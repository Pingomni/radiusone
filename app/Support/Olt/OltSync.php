<?php

declare(strict_types=1);

namespace App\Support\Olt;

use App\Support\Db;
use RuntimeException;
use Throwable;

/** Copies what each OLT reports into the portal, then links what it can. Only reads from the OLT. */
final class OltSync
{
    public const SNMP_EVERY_SECONDS = 300;
    public const MAC_EVERY_SECONDS = 900;

    /** @return array<string, int> */
    public static function due(): array
    {
        $summary = ['olts' => 0, 'failed' => 0, 'linked' => 0];
        $olts = Db::select('SELECT * FROM olts WHERE enabled=1 AND (sync_requested_at IS NOT NULL OR last_sync_at IS NULL OR last_sync_at<=NOW()-INTERVAL ? SECOND
            OR (telnet_enabled=1 AND (last_mac_sync_at IS NULL OR last_mac_sync_at<=NOW()-INTERVAL ? SECOND))) ORDER BY id', [self::SNMP_EVERY_SECONDS - 30, self::MAC_EVERY_SECONDS - 30]);
        foreach ($olts as $olt) {
            $summary['olts']++;
            if (!self::run($olt)) {
                $summary['failed']++;
            }
        }
        if ($olts) {
            $summary['linked'] = OltMapper::linkAutomatically();
        }
        return $summary;
    }

    /**
     * One OLT. A failure is saved for the page, and what was read before stays.
     *
     * @param  array<string, mixed>  $olt
     */
    public static function run(array $olt): bool
    {
        $requested = $olt['sync_requested_at'] !== null;
        Db::exec('UPDATE olts SET sync_requested_at=NULL WHERE id=?', [$olt['id']]);
        $ok = true;
        $snmpDue = $requested || $olt['last_sync_at'] === null || strtotime((string) $olt['last_sync_at']) <= time() - self::SNMP_EVERY_SECONDS + 30;
        if ($snmpDue) {
            try {
                self::saveOnus($olt, HuaweiMa5800::readOnus(Olts::snmp($olt)));
                OltMonitor::record((int) $olt['id']);
                Db::exec('UPDATE olts SET last_sync_at=NOW(),last_sync_ok=1,last_error=NULL WHERE id=?', [$olt['id']]);
            } catch (Throwable $e) {
                $ok = false;
                Db::exec('UPDATE olts SET last_sync_at=NOW(),last_sync_ok=0,last_error=? WHERE id=?', [self::message($e), $olt['id']]);
            }
        }
        $macDue = $olt['telnet_enabled'] && ($requested || $olt['last_mac_sync_at'] === null || strtotime((string) $olt['last_mac_sync_at']) <= time() - self::MAC_EVERY_SECONDS + 30);
        if ($macDue) {
            try {
                self::saveMacs($olt, HuaweiMa5800::parseMacTable(Olts::cli($olt)->macTable()));
                Db::exec('UPDATE olts SET last_mac_sync_at=NOW(),last_mac_ok=1,last_mac_error=NULL WHERE id=?', [$olt['id']]);
            } catch (Throwable $e) {
                $ok = false;
                Db::exec('UPDATE olts SET last_mac_sync_at=NOW(),last_mac_ok=0,last_mac_error=? WHERE id=?', [self::message($e), $olt['id']]);
            }
        }
        return $ok;
    }

    private static function message(Throwable $e): string
    {
        return $e instanceof RuntimeException ? mb_substr(mb_scrub($e->getMessage(), 'UTF-8'), 0, 500) : 'The sync stopped with an internal error. See the server log.';
    }

    /**
     * @param  array<string, mixed>  $olt
     * @param  list<array<string, mixed>>  $onus
     */
    public static function saveOnus(array $olt, array $onus): void
    {
        // An empty answer is far more likely a blocked view on the OLT than an OLT with no ONUs, so nothing is removed.
        if (!$onus && Db::value('SELECT COUNT(*) FROM olt_onus WHERE olt_id=?', [$olt['id']])) {
            throw new RuntimeException('The OLT answered, but listed no ONUs. Check that its SNMP view includes the Huawei GPON tables. The last list was kept.');
        }
        if (!$onus) {
            throw new RuntimeException('The OLT answered, but listed no ONUs. If it has ONUs, check that its SNMP view includes the Huawei GPON tables.');
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $now = (string) Db::value('SELECT NOW()');
            foreach ($onus as $onu) {
                $columns = array_keys($onu);
                $update = implode(',', array_map(static fn ($c) => "$c=VALUES($c)", array_diff($columns, ['frame', 'slot', 'port', 'ont_id'])));
                Db::exec('INSERT INTO olt_onus (olt_id,seen_at,last_online_at,' . implode(',', $columns) . ') VALUES (?,?,?,' . implode(',', array_fill(0, count($columns), '?')) . ') ON DUPLICATE KEY UPDATE seen_at=VALUES(seen_at),last_online_at=COALESCE(VALUES(last_online_at),last_online_at),' . $update, [$olt['id'], $now, ($onu['status'] ?? '') === 'online' ? $now : null, ...array_values($onu)]);
            }
            Db::exec('DELETE FROM olt_onus WHERE olt_id=? AND seen_at<?', [$olt['id'], $now]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $olt
     * @param  list<array{mac:string,vlan:int,frame:int,slot:int,port:int,ont_id:int}>  $macs
     */
    public static function saveMacs(array $olt, array $macs): void
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::exec('DELETE FROM olt_macs WHERE olt_id=?', [$olt['id']]);
            foreach ($macs as $m) {
                Db::exec('INSERT INTO olt_macs (olt_id,mac,vlan,frame,slot,port,ont_id,seen_at) VALUES (?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE frame=VALUES(frame),slot=VALUES(slot),port=VALUES(port),ont_id=VALUES(ont_id)', [$olt['id'], $m['mac'], $m['vlan'], $m['frame'], $m['slot'], $m['port'], $m['ont_id']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
