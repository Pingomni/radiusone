<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/** NAS records (the FreeRADIUS client list and nas_metadata): save, delete and deployment revisions. */
final class NasRepository
{
    public static function saveNas(array $input, ?int $id = null): int
    {
        return self::withNasLock($id ?? 0, static fn () => self::saveNasLocked($input, $id));
    }

    /** Serialize NAS configuration changes with worker observation and application. */
    private static function withNasLock(int $id, callable $work): mixed
    {
        $lock = 'frweb:network:' . substr(hash('sha256', (string) Db::value('SELECT DATABASE()')), 0, 24) . ':' . $id;
        if ((int) Db::value('SELECT GET_LOCK(?,0)', [$lock]) !== 1) {
            throw new RuntimeException('A network operation is active on this NAS; retry the change shortly.');
        }
        try {
            return $work();
        } finally {
            Db::value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    private static function saveNasLocked(array $input, ?int $id = null): int
    {
        $vendor = trim((string) ($input['vendor'] ?? ''));
        if ($vendor === '') {
            $vendor = trim((string) ($input['type'] ?? ''));
        }
        $isMikrotik = strtolower($vendor) === 'mikrotik';
        // FreeRADIUS matches requests on this address, and ping, CoA and the API connect to it.
        $input['nasname'] = trim((string) ($input['nasname'] ?? ''));
        if (filter_var($input['nasname'], FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException('Enter the NAS IP address as an IPv4 or IPv6 address.');
        }
        if (!$id && trim((string) ($input['secret'] ?? '')) === '') {
            throw new RuntimeException('Enter the RADIUS shared secret.');
        }
        $coaEnabled = RadiusRepository::boolValue($input['coa_enabled'] ?? null);
        $apiEnabled = $isMikrotik ? RadiusRepository::boolValue($input['api_enabled'] ?? null) : 0;
        SchemaCompatibility::require('policy');
        $pdo = Db::pdo();
        $pdo->beginTransaction();

        try {
            // Only what FreeRADIUS reads (the client list) needs a RADIUS update; CoA and API
            // settings are used by the portal alone and apply at once.
            $radiusChanged = true;
            if ($id) {
                $old = Db::row('SELECT n.nasname, n.shortname, n.type, n.secret, COALESCE(m.enabled, 1) AS enabled FROM nas n LEFT JOIN nas_metadata m ON m.nas_id=n.id WHERE n.id=? FOR UPDATE', [$id]);
                $radiusChanged = !$old || (string) $old['nasname'] !== (string) $input['nasname'] || (string) $old['shortname'] !== (string) $input['shortname']
                    || (string) $old['type'] !== (string) $input['type'] || (($input['secret'] ?? '') !== '' && !hash_equals((string) $old['secret'], (string) $input['secret']))
                    || (int) $old['enabled'] !== RadiusRepository::boolValue($input['enabled'] ?? null);
                Db::exec(
                    'UPDATE nas SET nasname=?, shortname=?, type=?, ports=?, secret=IF(? = "", secret, ?), server=?, community=?, description=? WHERE id=?',
                    [
                        $input['nasname'], $input['shortname'], $input['type'], (int) ($input['ports'] ?: 0),
                        $input['secret'] ?? '', $input['secret'] ?? '', $input['server'], $input['community'], $input['description'], $id,
                    ]
                );
            } else {
                Db::exec(
                    'INSERT INTO nas (nasname, shortname, type, ports, secret, server, community, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$input['nasname'], $input['shortname'], $input['type'], (int) ($input['ports'] ?: 0), $input['secret'], $input['server'], $input['community'], $input['description']]
                );
                $id = (int) $pdo->lastInsertId();
            }

            Db::exec(
                'INSERT INTO nas_metadata (nas_id, vendor, enabled, coa_enabled, coa_port, api_host, api_user, api_password, updated_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, IF(? = "", NULL, ?), NOW(), NOW())
                 ON DUPLICATE KEY UPDATE vendor=VALUES(vendor), enabled=VALUES(enabled), coa_enabled=VALUES(coa_enabled), coa_port=VALUES(coa_port), api_host=VALUES(api_host), api_user=VALUES(api_user), api_password=COALESCE(VALUES(api_password), api_password), config_revision=config_revision+?, pending_delete=0, updated_at=NOW()',
                [
                    $id, $input['vendor'], RadiusRepository::boolValue($input['enabled'] ?? null), $coaEnabled,
                    (int) ($input['coa_port'] ?: 3799), $input['api_host'], $input['api_user'],
                    Secrets::encrypt((string) ($input['api_password'] ?? '')), Secrets::encrypt((string) ($input['api_password'] ?? '')),
                    $radiusChanged ? 1 : 0,
                ]
            );

            if (self::hasApiSwitch()) {
                Db::exec('UPDATE nas_metadata SET api_enabled=? WHERE nas_id=?', [$apiEnabled, $id]);
            }

            $pdo->commit();
            SubscriberPolicy::releaseLocks();
            return (int) $id;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                try {
                    $pdo->rollBack();
                    SubscriberPolicy::releaseLocks();
                } catch (\Throwable) { /* Preserve the original failure. */
                }
            }
            throw $e;
        }
    }

    public static function deleteNas(int $id): void
    {
        self::withNasLock($id ?? 0, static fn () => self::deleteNasLocked($id));
    }

    private static function deleteNasLocked(int $id): void
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            if (!Db::row('SELECT id FROM nas WHERE id=? FOR UPDATE', [$id])) {
                throw new RuntimeException('NAS not found.');
            }
            Db::exec('INSERT INTO nas_metadata (nas_id,vendor,enabled,coa_enabled,created_at,updated_at,pending_delete) VALUES (?,"generic",0,0,NOW(),NOW(),1) ON DUPLICATE KEY UPDATE enabled=0,pending_delete=1,config_revision=config_revision+1,updated_at=NOW()', [$id]);
            $pdo->commit();
            SubscriberPolicy::releaseLocks();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
                SubscriberPolicy::releaseLocks();
            }
            throw $e;
        }
    }

    /** Records operator-supplied deployment evidence, never activates/restarts RADIUS. */
    public static function markNasApplied(int $id, int $revision, string $evidence): void
    {
        self::withNasLock($id ?? 0, static fn () => self::markNasAppliedLocked($id, $revision, $evidence));
    }

    private static function markNasAppliedLocked(int $id, int $revision, string $evidence): void
    {
        if (trim($evidence) === '' || strlen($evidence) > 500) {
            throw new RuntimeException('Deployment evidence is required.');
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $row = Db::row('SELECT * FROM nas_metadata WHERE nas_id=? FOR UPDATE', [$id]);
            if (!$row || (int) $row['config_revision'] !== $revision) {
                throw new RuntimeException('NAS revision changed; verify deployment again.');
            }
            if ((int) $row['applied_revision'] !== $revision || $row['applied_evidence'] !== $evidence) {
                Db::exec('UPDATE nas_metadata SET applied_revision=?,applied_at=NOW(),applied_evidence=? WHERE nas_id=?', [$revision, $evidence, $id]);
            }
            $pdo->commit();
            SubscriberPolicy::releaseLocks();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
                SubscriberPolicy::releaseLocks();
            }
            throw $e;
        }
    }

    /**
     * FreeRADIUS reads the NAS list (client_query) only when it starts and refuses to start with a broken
     * one. So while it is running, every NAS saved before it started is in effect: record those revisions,
     * including removals. Runs every minute (radiusone:mark-nas-applied --auto).
     *
     * @param  int|null  $radiusStartedAt  epoch FreeRADIUS became active; read from systemd when null
     * @return array<int, int> nas id => revision recorded
     */
    public static function recordLoadedNasRevisions(?int $radiusStartedAt = null): array
    {
        if ($radiusStartedAt === null) {
            $radius = ServerMonitor::services()['freeradius'] ?? null;
            if (!$radius || $radius['state'] !== 'active' || !$radius['since']) {
                return [];
            }
            $radiusStartedAt = (int) $radius['since'];
        }
        $recorded = [];
        $rows = Db::select('SELECT nas_id, config_revision FROM nas_metadata WHERE (applied_revision IS NULL OR applied_revision <> config_revision) AND updated_at < FROM_UNIXTIME(?)', [$radiusStartedAt]);
        foreach ($rows as $row) {
            $revision = (int) $row['config_revision'];
            try {
                self::markNasApplied((int) $row['nas_id'], $revision, 'FreeRADIUS started ' . date('Y-m-d H:i', $radiusStartedAt) . ', after revision ' . $revision . ' was saved (recorded automatically).');
                $recorded[(int) $row['nas_id']] = $revision;
            } catch (RuntimeException) {
                // Saved again meanwhile: the next restart picks it up.
            }
        }
        return $recorded;
    }

    /** Whether migration 028 (nas_metadata.api_enabled) is applied. */
    public static function hasApiSwitch(): bool
    {
        static $has = null;
        return $has ??= (bool) Db::value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nas_metadata' AND COLUMN_NAME = 'api_enabled'");
    }

    /** SQL for the API switch; before migration 028 a saved API login counts as switched on (the old behaviour). */
    public static function apiEnabledSql(string $alias): string
    {
        return self::hasApiSwitch()
            ? "COALESCE($alias.api_enabled, 0)"
            : "(COALESCE($alias.api_user, '') <> '' AND COALESCE($alias.api_password, '') <> '')";
    }
}
