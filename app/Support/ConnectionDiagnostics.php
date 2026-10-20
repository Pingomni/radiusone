<?php

declare(strict_types=1);

namespace App\Support;

final class ConnectionDiagnostics
{
    public static function forCustomer(string $username): array
    {
        SchemaCompatibility::require('policy');
        $client = Db::row('SELECT c.*,s.id AS subscription_id,s.policy_revision,s.provisioning_state,s.applied_revision,s.last_error,s.access_profile_id,s.dhcp_client_id,s.circuit_id,s.suspension_source FROM customer_profiles c LEFT JOIN subscriptions s ON s.customer_id=c.id WHERE c.username=?', [$username]);
        if (!$client) {
            throw new \RuntimeException('Customer not found.');
        }
        $profile = $client['access_profile_id'] ? Db::row('SELECT * FROM nas_access_profiles WHERE id=?', [$client['access_profile_id']]) : null;
        $policy = Db::row('SELECT adapter,allowed,reason,revision,valid_until FROM access_policies WHERE username=?', [$username]);
        $snapshot = $client['subscription_id'] ? Db::value('SELECT policy_json FROM policy_revisions WHERE subscription_id=? AND revision=?', [$client['subscription_id'], $client['policy_revision']]) : null;
        $effective = $snapshot ? json_decode($snapshot, true, 512, JSON_THROW_ON_ERROR) : null;
        $identity = '(username=? OR username IN (SELECT username FROM customer_identity_history WHERE customer_id=?))';
        $session = Db::row('SELECT * FROM radacct WHERE ' . $identity . ' ORDER BY radacctid DESC LIMIT 1', [$username, $client['id']]);
        if ($session && $profile) {
            $session += ['expected_interval' => $profile['expected_interval'], 'grace_seconds' => $profile['grace_seconds']];
        }
        // radpostauth has no decision reason in legacy data. Never invent one or read pass.
        $auth = Db::row('SELECT reply,authdate FROM radpostauth WHERE ' . $identity . ' ORDER BY authdate DESC LIMIT 1', [$username, $client['id']]);
        $decision = Db::row('SELECT decision,reason,source_ip,adapter,occurred_at FROM access_auth_events WHERE ' . $identity . ' ORDER BY id DESC LIMIT 1', [$username, $client['id']]);
        $operations = Db::select('SELECT id,action,policy_revision,state,attempts,result_code,created_at,updated_at FROM network_operations WHERE subscription_id=? ORDER BY id DESC LIMIT 30', [$client['subscription_id']]);
        return compact('client', 'profile', 'policy', 'effective', 'session', 'auth', 'decision', 'operations');
    }
}
