<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

final class NetworkOperations
{
    public static function requestDisconnect(string $username, int $nasId, int $accountingId): int
    {
        SchemaCompatibility::require('policy');
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $sub = Db::row('SELECT s.* FROM subscriptions s JOIN customer_profiles c ON c.id=s.customer_id WHERE c.username=? FOR UPDATE', [$username]);
            if (!$sub || !$sub['policy_revision']) {
                throw new RuntimeException('Publish a validated subscription policy first.');
            }
            $profile = Db::row('SELECT * FROM nas_access_profiles WHERE id=?', [$sub['access_profile_id']]);
            if ($reason = AccessPolicy::capability($profile, 'disconnect')) {
                throw new RuntimeException($reason);
            }
            if ((int) $profile['nas_id'] !== $nasId) {
                throw new RuntimeException('Session NAS differs from the subscription profile.');
            }
            $session = self::sessions($sub, (int) $accountingId);
            if (count($session) !== 1) {
                throw new RuntimeException('Select one identified active session.');
            }
            $key = $sub['id'] . ':disconnect:' . $accountingId;
            Db::exec('INSERT IGNORE INTO network_operations (subscription_id,policy_revision,action,dedupe_key,session_json,available_at,created_at,updated_at) VALUES (?, ?,"disconnect",?,?,NOW(),NOW(),NOW())', [$sub['id'], $sub['policy_revision'], $key, json_encode($session, JSON_THROW_ON_ERROR)]);
            $id = (int) Db::value('SELECT id FROM network_operations WHERE dedupe_key=?', [$key]);
            $pdo->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
    private static function sessions(array $sub, ?int $accountingId = null): array
    {
        $rows = Db::select('SELECT r.radacctid,r.username,r.acctsessionid,r.nasipaddress,r.acctstarttime,r.framedipaddress,r.callingstationid FROM radacct r JOIN customer_profiles c ON c.id=? WHERE (r.username=c.username OR r.username IN (SELECT username FROM customer_identity_history WHERE customer_id=c.id)) AND r.acctstoptime IS NULL AND (? IS NULL OR r.radacctid=?) ORDER BY r.radacctid LIMIT 33', [$sub['customer_id'], $accountingId, $accountingId]);
        if (count($rows) > 32) {
            throw new RuntimeException('Too many active sessions for one operation.');
        }
        foreach ($rows as $row) {
            if (empty($row['acctsessionid']) || empty($row['acctstarttime']) || empty($row['nasipaddress'])) {
                throw new RuntimeException('Incomplete accounting session identity.');
            }
        }
        return $rows;
    }
    public static function run(NetworkDriver $driver, int $limit = 50): array
    {
        SchemaCompatibility::require('policy');
        $limit = max(1, min(200, $limit));
        $counts = [];
        $ids = Db::select('SELECT id FROM network_operations WHERE (state IN ("pending","running","ack","timeout","uncertain") OR (state="blocked" AND result_code="no_observed_session_next_authentication")) AND available_at<=NOW() ORDER BY available_at,id LIMIT ' . $limit);
        $deadline = microtime(true) + 45;
        foreach ($ids as $row) {
            if (microtime(true) >= $deadline) {
                break;
            }$state = self::process((int) $row['id'], $driver);
            $counts[$state] = ($counts[$state] ?? 0) + 1;
        }
        return ['processed' => array_sum($counts), 'states' => $counts, 'lag' => self::lag()];
    }
    public static function lag(): array
    {
        return Db::row('SELECT COUNT(*) AS outstanding,COALESCE(MAX(TIMESTAMPDIFF(SECOND,created_at,NOW())),0) AS oldest_seconds,SUM(state IN ("failed","nak","blocked")) AS needs_attention FROM network_operations WHERE state NOT IN ("verified","superseded")');
    }
    public static function process(int $id, NetworkDriver $driver): string
    {
        $op = Db::row('SELECT * FROM network_operations WHERE id=?', [$id]);
        if (!$op || (in_array($op['state'], ['verified', 'superseded', 'blocked', 'nak', 'failed'], true) && ($op['result_code'] ?? '') !== 'no_observed_session_next_authentication')) {
            return $op['state'] ?? 'missing';
        }
        $sub = Db::row('SELECT * FROM subscriptions WHERE id=?', [$op['subscription_id']]);
        $profile = $sub['access_profile_id'] ? Db::row('SELECT * FROM nas_access_profiles WHERE id=?', [$sub['access_profile_id']]) : null;
        // DB-scoped per-NAS mutex is held across I/O. Dead workers release the lock automatically.
        $lock = 'frweb:network:' . substr(hash('sha256', (string) Db::value('SELECT DATABASE()')), 0, 24) . ':' . ($profile['nas_id'] ?? 0);
        if ((int) Db::value('SELECT GET_LOCK(?,0)', [$lock]) !== 1) {
            return 'busy';
        }
        try {
            // Serialize publication/status changes with this operation without holding a DB transaction over I/O.
            $subLock = 'frweb:subscription:' . substr(hash('sha256', (string) Db::value('SELECT DATABASE()')), 0, 20) . ':' . $sub['id'];
            if ((int) Db::value('SELECT GET_LOCK(?,0)', [$subLock]) !== 1) {
                return 'busy';
            }
            try {
                return self::locked($id, $driver);
            } finally {
                Db::value('SELECT RELEASE_LOCK(?)', [$subLock]);
            }
        } finally {
            Db::value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
    private static function locked(int $id, NetworkDriver $driver): string
    {
        $op = Db::row('SELECT * FROM network_operations WHERE id=?', [$id]);
        if (!in_array($op['state'], ['pending', 'running', 'ack', 'timeout', 'uncertain'], true) && ($op['result_code'] ?? '') !== 'no_observed_session_next_authentication') {
            return $op['state'];
        }
        $sub = Db::row('SELECT * FROM subscriptions WHERE id=?', [$op['subscription_id']]);
        if ((int) $sub['policy_revision'] !== (int) $op['policy_revision']) {
            return self::finish($op, 'superseded', 'newer_policy_revision');
        }
        if (!$sub['access_profile_id']) {
            return self::reconnect($op, $sub);
        }
        $profile = Db::row('SELECT * FROM nas_access_profiles WHERE id=?', [$sub['access_profile_id']]);
        if (AccessPolicy::capability($profile, $op['action'])) {
            return self::finish($op, 'blocked', 'capability_unverified');
        }
        $policy = json_decode(Db::value('SELECT policy_json FROM policy_revisions WHERE subscription_id=? AND revision=?', [$sub['id'], $op['policy_revision']]), true, 512, JSON_THROW_ON_ERROR);
        $policy['revision'] = (int) $op['policy_revision'];
        $policy['operation_id'] = (int) $op['id'];
        if ((int) $policy['profile_revision'] !== (int) $profile['revision']) {
            return self::finish($op, 'blocked', 'profile_revision_changed');
        }
        $nas = Db::row('SELECT enabled,config_revision,applied_revision,pending_delete FROM nas_metadata WHERE nas_id=?', [$profile['nas_id']]);
        if (!$nas || !$nas['enabled'] || $nas['pending_delete'] || $nas['applied_revision'] === null || (int) $nas['config_revision'] !== (int) $nas['applied_revision']) {
            return self::finish($op, 'blocked', 'nas_configuration_pending');
        }
        $deadline = microtime(true) + 40;
        try {
            $sessions = $op['session_json'] ? json_decode($op['session_json'], true, 512, JSON_THROW_ON_ERROR) : self::sessions($sub);
            if (!$sessions) {
                return self::finish($op, 'blocked', 'no_observed_session_next_authentication');
            }
            foreach ($sessions as $session) {
                if ($session['nasipaddress'] !== $profile['source_ip']) {
                    return self::finish($op, 'blocked', 'session_nas_mismatch');
                }
            }
            Db::exec('UPDATE network_operations SET state="running",session_json=?,claimed_at=NOW(),available_at=DATE_ADD(NOW(),INTERVAL 60 SECOND),updated_at=NOW() WHERE id=?', [json_encode($sessions, JSON_THROW_ON_ERROR), $op['id']]);
            $allVerified = true;
            $observations = [];
            foreach ($sessions as $session) {
                if (microtime(true) >= $deadline) {
                    return self::finish($op, 'uncertain', 'observation_deadline');
                }
                $observation = $driver->observe($profile, $session, $policy, $op['action']);
                $observations[] = $observation;
                if (!self::verified($observation, $policy, $op)) {
                    $allVerified = false;
                }
            }
            if ($allVerified) {
                return self::finish($op, 'verified', 'network_observation_verified');
            }
            // ACK only reconciles. An uncertain send can retry only after a fresh exact NAS identity match.
            if ($op['state'] === 'ack') {
                return self::finish($op, 'ack', 'awaiting_network_observation');
            }
            foreach ($observations as $o) {
                if (empty($o['identity_match']) || empty($o['fresh'])) {
                    return self::finish($op, 'uncertain', 'identity_not_reverified');
                }
            }
            if ((int) $op['attempts'] >= 5) {
                return self::finish($op, 'failed', 'retry_limit_manual_review');
            }
            // A concurrent entitlement change must not be enforced with an obsolete snapshot.
            if ((int) Db::value('SELECT policy_revision FROM subscriptions WHERE id=?', [$sub['id']]) !== (int) $op['policy_revision']) {
                return self::finish($op, 'superseded', 'newer_policy_revision');
            }
            Db::exec('UPDATE network_operations SET attempts=attempts+1 WHERE id=?', [$op['id']]);
            $outcome = 'ack';
            foreach ($sessions as $i => $session) {
                if (self::verified($observations[$i], $policy, $op)) {
                    continue;
                }
                if (microtime(true) >= $deadline) {
                    return self::finish($op, 'uncertain', 'application_deadline');
                }
                $result = $driver->apply($profile, $session, $policy, $op['action']);
                $state = $result['status'] ?? 'uncertain';
                if (!in_array($state, ['ack', 'nak', 'timeout', 'uncertain'], true)) {
                    $state = 'uncertain';
                }
                if ($state !== 'ack') {
                    $outcome = $state;
                    break;
                }
            }
            return self::finish($op, $outcome, 'transport_' . $outcome);
        } catch (\Throwable) {
            return self::finish($op, 'uncertain', 'worker_or_transport_interrupted');
        }
    }
    /**
     * Without an access profile there is no router adapter: RADIUS already holds the new policy, so an online
     * customer is disconnected (CoA or RouterOS API, as the Disconnect button does) and gets it on reconnect;
     * an offline one gets it at the next login. A refused disconnect is retried with back-off, five times.
     */
    private static function reconnect(array $op, array $sub): string
    {
        if (Db::value('SELECT auth_method FROM customer_profiles WHERE id=?', [$sub['customer_id']]) === 'dhcp') {
            return self::finish($op, 'verified', 'next_dhcp_lease');
        }
        $sessions = self::sessions($sub);
        if (!$sessions) {
            return self::finish($op, 'verified', 'no_session_next_login');
        }
        Db::exec('UPDATE network_operations SET state="running",session_json=?,claimed_at=NOW(),attempts=attempts+1,updated_at=NOW() WHERE id=?', [json_encode($sessions, JSON_THROW_ON_ERROR), $op['id']]);
        $failed = null;
        $sent = 0;
        foreach ($sessions as $session) {
            try {
                SessionDisconnect::disconnectUser((string) $session['username'], null, (int) $session['radacctid']);
                $sent++;
            } catch (\Throwable $e) {
                // A session whose accounting went quiet is not online any more: the new policy applies at its next login.
                if (str_contains($e->getMessage(), 'stale') || str_contains($e->getMessage(), 'No identified active session')) {
                    continue;
                }
                $failed = $e->getMessage();
            }
        }
        if ($failed === null) {
            return self::finish($op, 'verified', $sent ? 'disconnected_for_new_policy' : 'no_session_next_login');
        }
        $op['attempts'] = (int) $op['attempts'] + 1;
        return self::finish($op, $op['attempts'] >= 5 ? 'failed' : 'uncertain', $op['attempts'] >= 5 ? 'disconnect_failed_manual_review' : 'disconnect_retry: ' . mb_substr($failed, 0, 60));
    }

    private static function verified(array $o, array $policy, array $op): bool
    {
        if (empty($o['fresh']) || empty($o['evidence'])) {
            return false;
        }
        if ($op['action'] === 'disconnect') {
            return !empty($o['exact_session_absent']);
        }
        if ($op['action'] === 'suspend') {
            return !empty($o['forwarding_blocked']) && !empty($o['identity_match']) && (int) ($o['policy_revision'] ?? 0) === (int) $op['policy_revision'];
        }
        return !empty($o['identity_match']) && !empty($o['policy_observed']) && (int) ($o['policy_revision'] ?? 0) === (int) $op['policy_revision'];
    }
    private static function finish(array $op, string $state, string $code): string
    {
        $pdo = Db::pdo();
        $own = !$pdo->inTransaction();
        if ($own) {
            $pdo->beginTransaction();
        }
        try {
            $delay = min(900, 30 * (2 ** min(5, (int) $op['attempts'])));
            Db::exec('UPDATE network_operations SET state=?,result_code=?,available_at=DATE_ADD(NOW(),INTERVAL ? SECOND),updated_at=NOW() WHERE id=?', [$state, $code, $delay, $op['id']]);
            if ($op['action'] !== 'disconnect') {
                Db::exec('UPDATE subscriptions SET provisioning_state=?,last_error=?,applied_revision=IF(?="verified",?,applied_revision) WHERE id=? AND policy_revision=?', [$state, $code, $state, $op['policy_revision'], $op['subscription_id'], $op['policy_revision']]);
            }
            if ($state === 'verified' && in_array($op['action'], ['apply', 'restore'], true)) {
                CustomerNotifications::activated((int) $op['subscription_id']);
            }
            if ($own) {
                $pdo->commit();
            }
            return $state;
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) {
                $pdo->rollBack();
            } throw $e;
        }
    }
}
