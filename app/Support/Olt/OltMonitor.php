<?php

declare(strict_types=1);

namespace App\Support\Olt;

use App\Support\Db;
use App\Support\SchemaCompatibility;

/** OLTs on the Monitoring page and their graph. Everything comes from the last read: rendering never contacts an OLT. */
final class OltMonitor
{
    /** Longer than this since the last read attempt means the job is not running. */
    private const STALE_SECONDS = 900;
    /** A PON whose ONUs were all offline for longer than this is treated as out of use, not as an outage. */
    private const OUTAGE_SECONDS = 86400;
    private const RETENTION_SECONDS = 691200;
    private const WEAK_DBM = -27;

    public const RANGES = [
        '6h' => [21600, 300, '6 hours'],
        '24h' => [86400, 900, '24 hours'],
        '7d' => [604800, 5400, '7 days'],
    ];

    public static function available(): bool
    {
        return !SchemaCompatibility::problems('olt');
    }

    public static function record(int $oltId): void
    {
        $c = Db::row('SELECT COUNT(*) AS onus,COALESCE(SUM(status="online"),0) AS online,COALESCE(SUM(status="offline"),0) AS offline,COALESCE(SUM(rx_dbm<? OR olt_rx_dbm<?),0) AS weak FROM olt_onus WHERE olt_id=?', [self::WEAK_DBM, self::WEAK_DBM, $oltId]);
        Db::exec('INSERT IGNORE INTO olt_samples (olt_id,sampled_at,onus,online,offline,weak) VALUES (?,?,?,?,?,?)', [$oltId, time(), $c['onus'] ?? 0, $c['online'] ?? 0, $c['offline'] ?? 0, $c['weak'] ?? 0]);
        Db::exec('DELETE FROM olt_samples WHERE sampled_at<? LIMIT 5000', [time() - self::RETENTION_SECONDS]);
    }

    /** Reads every OLT within a minute, through the usual job. */
    public static function requestAll(): void
    {
        if (self::available()) {
            Db::exec('UPDATE olts SET sync_requested_at=NOW() WHERE enabled=1');
        }
    }

    /**
     * The cards for the Monitoring page, and what needs attention as [text, link].
     *
     * @return array{cards:list<array<string, mixed>>,problems:list<array{string,string}>}
     */
    public static function overview(callable $age): array
    {
        if (!self::available()) {
            return ['cards' => [], 'problems' => []];
        }
        $cards = [];
        $problems = [];
        $outages = self::outages();
        $rows = Db::select('SELECT t.id,t.name,t.host,t.model,t.enabled,t.telnet_enabled,t.created_at,t.last_error,t.last_mac_error,t.last_sync_ok,UNIX_TIMESTAMP(t.last_sync_at) AS tried,
            COUNT(o.id) AS onus,COALESCE(SUM(o.status="online"),0) AS online,COALESCE(SUM(o.status="offline"),0) AS offline,COALESCE(SUM(o.rx_dbm<? OR o.olt_rx_dbm<?),0) AS weak,UNIX_TIMESTAMP(MAX(o.seen_at)) AS good
            FROM olts t LEFT JOIN olt_onus o ON o.olt_id=t.id GROUP BY t.id ORDER BY t.name', [self::WEAK_DBM, self::WEAK_DBM]);
        foreach ($rows as $o) {
            $id = (int) $o['id'];
            $href = '/olts/' . $id;
            $name = 'OLT ' . $o['name'];
            $enabled = (bool) $o['enabled'];
            $tried = $o['tried'] === null ? null : time() - (int) $o['tried'];
            $good = $o['good'] === null ? null : time() - (int) $o['good'];
            $failed = $enabled && $o['last_sync_ok'] !== null && !(bool) $o['last_sync_ok'];
            $stale = $enabled && $tried !== null && $tried > self::STALE_SECONDS;
            $never = $enabled && $tried === null && strtotime((string) $o['created_at']) < time() - self::STALE_SECONDS;
            if ($failed) {
                $problems[] = [$name . ': the read failed, so its list is ' . ($good === null ? 'empty' : $age($good) . ' old') . '. ' . mb_substr((string) $o['last_error'], 0, 120), $href];
            }
            if ($stale || $never) {
                $problems[] = [$name . ': ' . ($stale ? 'last read ' . $age($tried) . ' ago' : 'never read') . '. Check /etc/cron.d/radiusone.', $href];
            }
            if ($enabled && $o['telnet_enabled'] && (string) $o['last_mac_error'] !== '') {
                $problems[] = [$name . ': the telnet read failed, so clients are not being found from their MAC. ' . mb_substr((string) $o['last_mac_error'], 0, 120), $href];
            }
            // An old list can't show a new outage or prove an old one has ended.
            $down = $enabled && !$failed && !$stale ? ($outages[$id] ?? []) : [];
            foreach (array_slice($down, 0, 5) as $port => $count) {
                $problems[] = [$name . ': PON ' . $port . ' has all ' . $count . ' ONUs offline', $href . '?status=offline'];
            }
            if (count($down) > 5) {
                $problems[] = [$name . ': and ' . (count($down) - 5) . ' more PON ports with all ONUs offline', $href . '?status=offline'];
            }
            $danger = $failed || $stale || $never || $down !== [];
            $offline = (int) $o['offline'];
            $weak = (int) $o['weak'];
            $cards[] = [
                'id' => $id,
                'name' => (string) $o['name'],
                'host' => (string) $o['host'],
                'model' => Olts::MODELS[$o['model']] ?? (string) $o['model'],
                'enabled' => $enabled,
                'danger' => $danger,
                'items' => [
                    ['label' => 'ONUs online', 'value' => $o['onus'] ? $o['online'] . ' of ' . $o['onus'] : '-', 'tone' => ''],
                    ['label' => 'Offline', 'value' => (string) $offline, 'tone' => $down !== [] ? 'danger' : ($offline ? 'warning' : '')],
                    ['label' => 'Weak signal', 'value' => (string) $weak, 'tone' => $weak ? 'warning' : ''],
                    ['label' => 'List age', 'value' => $good === null ? ($enabled ? 'Not read yet' : '-') : $age($good), 'tone' => $failed || $stale ? 'danger' : ''],
                ],
            ];
        }
        return ['cards' => $cards, 'problems' => $problems];
    }

    /** @return array<int, array<string, int>> OLT id => PON port => ONUs on it, for ports that went fully offline recently */
    private static function outages(): array
    {
        $outages = [];
        foreach (Db::select('SELECT olt_id,frame,slot,port,COUNT(*) AS n FROM olt_onus GROUP BY olt_id,frame,slot,port
            HAVING n>=2 AND SUM(status="offline")=n AND MAX(last_online_at)>=NOW()-INTERVAL ? SECOND ORDER BY olt_id,frame,slot,port', [self::OUTAGE_SECONDS]) as $row) {
            $outages[(int) $row['olt_id']][$row['frame'] . '/' . $row['slot'] . '/' . $row['port']] = (int) $row['n'];
        }
        return $outages;
    }

    /** @return array{start:int,end:int,bucket:int,samples:int,points:list<array<string, float|int|null>>,latest:array<string, mixed>|null} */
    public static function series(int $oltId, string $range): array
    {
        [$length, $bucket] = self::RANGES[$range];
        $end = intdiv(time(), $bucket) * $bucket + $bucket;
        $start = $end - $length;
        $rows = Db::select('SELECT FLOOR(sampled_at/?)*? AS bucket,AVG(online) AS online,AVG(offline) AS offline,AVG(weak) AS weak,COUNT(*) AS samples FROM olt_samples WHERE olt_id=? AND sampled_at>=? AND sampled_at<? GROUP BY FLOOR(sampled_at/?) ORDER BY bucket', [$bucket, $bucket, $oltId, $start, $end, $bucket]);
        $byBucket = array_column($rows, null, 'bucket');
        $points = [];
        for ($t = $start; $t < $end; $t += $bucket) {
            $row = $byBucket[$t] ?? null;
            // s0, s1 and s2 are the chart's line colours.
            $points[] = ['t' => $t, 's0' => $row ? (float) $row['online'] : null, 's1' => $row ? (float) $row['offline'] : null, 's2' => $row ? (float) $row['weak'] : null];
        }
        return [
            'start' => $start, 'end' => $end, 'bucket' => $bucket, 'samples' => (int) array_sum(array_column($rows, 'samples')), 'points' => $points,
            'latest' => Db::row('SELECT online,offline,weak FROM olt_samples WHERE olt_id=? ORDER BY sampled_at DESC LIMIT 1', [$oltId]),
        ];
    }
}
