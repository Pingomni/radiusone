<?php

declare(strict_types=1);

namespace App\Support\Olt;

use App\Support\Db;
use App\Support\SchemaCompatibility;
use RuntimeException;
use Throwable;

/** OLTs on the Monitoring page and their graph. Everything comes from the last read: rendering never contacts an OLT. */
final class OltMonitor
{
    /** Longer than this since the last read attempt means the job is not running. */
    private const STALE_SECONDS = 900;
    /** A PON whose ONUs were all offline for longer than this is treated as out of use, not as an outage. */
    private const OUTAGE_SECONDS = 86400;
    private const RETENTION_SECONDS = 691200;
    private const WEAK_DBM = -27;
    private const HOT_C = 75;
    private const BUSY_PCT = 90;

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

    /**
     * @param  array<string, mixed>  $olt
     * @param  array{boards:list<array<string, mixed>>,ports:list<array<string, mixed>>,fans?:list<array<string, mixed>>}  $read
     */
    public static function saveHardware(array $olt, array $read): void
    {
        $id = (int) $olt['id'];
        // An empty answer is far more likely a blocked SNMP view than an OLT with no hardware: the last read is kept.
        if (!$read['boards'] && !$read['ports']) {
            throw new RuntimeException('The OLT answered, but listed no cards or ports. Check that its SNMP view includes them.');
        }
        $now = time();
        $previous = array_column(Db::select('SELECT if_index,in_octets,out_octets,sampled_at FROM olt_ports WHERE olt_id=?', [$id]), null, 'if_index');
        $rate = static function (mixed $current, mixed $before, int $seconds): ?float {
            // A counter that went backwards was reset, so it says nothing about this interval.
            if (!is_numeric($current) || !is_numeric($before) || $seconds <= 0 || $current < $before) {
                return null;
            }

            return ((float) $current - (float) $before) * 8 / $seconds;
        };
        $uplinkIn = $uplinkOut = null;
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::exec('DELETE FROM olt_boards WHERE olt_id=?', [$id]);
            foreach ($read['boards'] as $b) {
                Db::exec('INSERT INTO olt_boards (olt_id,frame,slot,name,status_code,online_code,work_mode,power_input,serial,cpu_pct,mem_pct,temperature_c,seen_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())', [$id, $b['frame'], $b['slot'], $b['name'], $b['status_code'], $b['online_code'] ?? null, $b['work_mode'] ?? null, $b['power_input'] ?? null, $b['serial'], $b['cpu_pct'], $b['mem_pct'], $b['temperature_c']]);
            }
            Db::exec('DELETE FROM olt_fans WHERE olt_id=?', [$id]);
            foreach ($read['fans'] ?? [] as $f) {
                Db::exec('INSERT IGNORE INTO olt_fans (olt_id,label,state,ok,speed_pct) VALUES (?,?,?,?,?)', [$id, $f['label'], $f['state'], $f['ok'], $f['speed_pct']]);
            }
            Db::exec('DELETE FROM olt_ports WHERE olt_id=?', [$id]);
            foreach ($read['ports'] as $p) {
                $before = $previous[$p['if_index']] ?? null;
                $seconds = $before ? $now - (int) $before['sampled_at'] : 0;
                $in = $before ? $rate($p['in_octets'], $before['in_octets'], $seconds) : null;
                $out = $before ? $rate($p['out_octets'], $before['out_octets'], $seconds) : null;
                if ($p['kind'] === 'uplink') {
                    $uplinkIn = $in === null ? $uplinkIn : ($uplinkIn ?? 0) + $in;
                    $uplinkOut = $out === null ? $uplinkOut : ($uplinkOut ?? 0) + $out;
                }
                Db::exec('INSERT INTO olt_ports (olt_id,if_index,name,alias,kind,admin_up,oper_up,speed_mbps,in_octets,out_octets,in_bps,out_bps,in_errors,out_errors,sampled_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    [$id, $p['if_index'], $p['name'], $p['alias'], $p['kind'], $p['admin_up'], $p['oper_up'], $p['speed_mbps'], $p['in_octets'], $p['out_octets'], $in, $out, $p['in_errors'], $p['out_errors'], $now]);
            }
            $main = self::mainBoard($read['boards']);
            $temperatures = array_filter(array_column($read['boards'], 'temperature_c'), static fn ($v) => $v !== null);
            Db::exec('INSERT IGNORE INTO olt_hw_samples (olt_id,sampled_at,cpu_pct,mem_pct,temperature_c,in_bps,out_bps) VALUES (?,?,?,?,?,?,?)', [$id, $now, $main['cpu_pct'] ?? null, $main['mem_pct'] ?? null, $temperatures ? max($temperatures) : null, $uplinkIn, $uplinkOut]);
            Db::exec('DELETE FROM olt_hw_samples WHERE sampled_at<? LIMIT 5000', [$now - self::RETENTION_SECONDS]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * The control card: the active one when the OLT says which, else one named MP.
     *
     * @param  list<array<string, mixed>>  $boards
     * @return array<string, mixed>|null
     */
    public static function mainBoard(array $boards): ?array
    {
        $withCpu = array_values(array_filter($boards, static fn (array $b) => $b['cpu_pct'] !== null));
        $active = array_values(array_filter($withCpu, static fn (array $b) => (int) ($b['work_mode'] ?? 0) === 1));
        $named = array_values(array_filter($withCpu, static fn (array $b) => str_contains(strtoupper((string) $b['name']), 'MP')));

        return $active[0] ?? $named[0] ?? null;
    }

    /**
     * What a card's state means, from hwSlotOperStatus and hwMusaBoardOnlineState. "alert" is for states that need a
     * look. A card being found or set up, or turned off on purpose, is not normal but needs no alert.
     *
     * @return array{text:string,ok:bool,alert:bool}
     */
    public static function boardState(?int $oper, ?int $online): array
    {
        $names = [1 => 'Empty', 3 => 'Fault', 4 => 'Disabled', 5 => 'Auto-found, not confirmed', 6 => 'Being configured', 7 => 'Offline', 8 => 'Abnormal', 9 => 'Wrong version', 10 => 'Loading', 11 => 'Out of service', 12 => 'Stopping', 13 => 'Shut down', 14 => 'Shut down to save energy', 15 => 'Shut down: too hot', 16 => 'Shut down by hand', 17 => 'Wrong card type', 18 => 'Shut down: no AC power', 19 => 'Shut down: too much power', 20 => 'Isolated'];
        if ($online === 3) {
            return ['text' => 'Fault', 'ok' => false, 'alert' => true];
        }
        // The MIB defines 7 as offline, but a public MA5800 template treats 7 as a working card. A card that the second
        // table also says is online gets the benefit of the doubt, so this can't raise a false alarm on every card.
        if ($oper === 2 && $online !== 2 || $oper === null && $online === 1 || $oper === 7 && $online === 1) {
            return ['text' => 'Normal', 'ok' => true, 'alert' => false];
        }
        if ($oper === 2 || $oper === null && $online === 2) {
            return ['text' => 'Offline', 'ok' => false, 'alert' => true];
        }
        if ($oper === null) {
            return ['text' => 'Unknown', 'ok' => false, 'alert' => false];
        }

        return ['text' => $names[$oper] ?? 'Code ' . $oper, 'ok' => false, 'alert' => in_array($oper, [3, 7, 8, 9, 15, 17, 18, 19, 20], true)];
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
        $boardsByOlt = [];
        foreach (Db::select('SELECT * FROM olt_boards ORDER BY olt_id,frame,slot') as $b) {
            $boardsByOlt[(int) $b['olt_id']][] = $b;
        }
        $fansByOlt = [];
        foreach (Db::select('SELECT olt_id,label,state,ok FROM olt_fans') as $f) {
            $fansByOlt[(int) $f['olt_id']][] = $f;
        }
        $portsByOlt = [];
        foreach (Db::select('SELECT olt_id,name,kind,admin_up,oper_up,in_bps,out_bps FROM olt_ports') as $p) {
            $portsByOlt[(int) $p['olt_id']][] = $p;
        }
        $rows = Db::select('SELECT t.id,t.name,t.host,t.model,t.enabled,t.telnet_enabled,t.created_at,t.last_error,t.last_mac_error,t.last_hw_error,t.last_sync_ok,UNIX_TIMESTAMP(t.last_sync_at) AS tried,
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
            $boards = $boardsByOlt[$id] ?? [];
            $ports = $portsByOlt[$id] ?? [];
            $fans = $fansByOlt[$id] ?? [];
            $hwProblem = false;
            if ($enabled && !$stale) {
                if (!$failed && (string) $o['last_hw_error'] !== '') {
                    $problems[] = [$name . ': the card and port read failed. ' . mb_substr((string) $o['last_hw_error'], 0, 120), $href . '?tab=ports'];
                }
                foreach ($boards as $b) {
                    $label = $name . ': card ' . $b['frame'] . '/' . $b['slot'] . ' ' . $b['name'];
                    $state = self::boardState($b['status_code'] === null ? null : (int) $b['status_code'], $b['online_code'] === null ? null : (int) $b['online_code']);
                    if ($state['alert']) {
                        $problems[] = [$label . ': ' . $state['text'], $href . '?tab=cards'];
                        $hwProblem = true;
                    }
                    if ((int) $b['power_input'] === 2) {
                        $problems[] = [$label . ': power input fault', $href . '?tab=cards'];
                        $hwProblem = true;
                    }
                    if ($b['temperature_c'] !== null && (int) $b['temperature_c'] >= self::HOT_C) {
                        $problems[] = [$label . ' is at ' . $b['temperature_c'] . ' °C', $href . '?tab=cards'];
                        $hwProblem = true;
                    }
                    if ($b['cpu_pct'] !== null && (int) $b['cpu_pct'] >= self::BUSY_PCT) {
                        $problems[] = [$label . ' CPU is at ' . $b['cpu_pct'] . ' %', $href . '?tab=cards'];
                        $hwProblem = true;
                    }
                }
                foreach ($fans as $f) {
                    if ((int) $f['ok'] === 0) {
                        $problems[] = [$name . ': ' . $f['label'] . ': ' . $f['state'], $href . '?tab=cards'];
                        $hwProblem = true;
                    }
                }
                foreach ($ports as $p) {
                    if ($p['kind'] === 'uplink' && (int) $p['admin_up'] === 1 && $p['oper_up'] !== null && (int) $p['oper_up'] === 0) {
                        $problems[] = [$name . ': uplink ' . $p['name'] . ' is down', $href . '?tab=ports'];
                        $hwProblem = true;
                    }
                }
            }
            $danger = $failed || $stale || $never || $down !== [] || $hwProblem;
            $main = self::mainBoard($boards);
            $temperatures = array_filter(array_column($boards, 'temperature_c'), static fn ($v) => $v !== null);
            $uplinks = array_filter($ports, static fn ($p) => $p['kind'] === 'uplink');
            $pons = array_filter($ports, static fn ($p) => $p['kind'] === 'pon');
            $upCount = static fn (array $list) => count(array_filter($list, static fn ($p) => (int) $p['oper_up'] === 1)) . ' of ' . count($list);
            $normal = count(array_filter($boards, static fn ($b) => self::boardState($b['status_code'] === null ? null : (int) $b['status_code'], $b['online_code'] === null ? null : (int) $b['online_code'])['ok']));
            $fansOk = count(array_filter($fans, static fn ($f) => (int) $f['ok'] === 1));
            $readings = array_values(array_filter([
                $main ? ['label' => 'CPU', 'value' => $main['cpu_pct'] . ' %', 'tone' => (int) $main['cpu_pct'] >= self::BUSY_PCT ? 'danger' : ''] : null,
                $main && $main['mem_pct'] !== null ? ['label' => 'Memory', 'value' => $main['mem_pct'] . ' %', 'tone' => ''] : null,
                $temperatures ? ['label' => 'Hottest card', 'value' => max($temperatures) . ' °C', 'tone' => max($temperatures) >= self::HOT_C ? 'danger' : ''] : null,
                $boards ? ['label' => 'Cards normal', 'value' => $normal . ' of ' . count($boards), 'tone' => $normal < count($boards) ? 'warning' : ''] : null,
                $fans ? ['label' => 'Fans OK', 'value' => $fansOk . ' of ' . count($fans), 'tone' => $fansOk < count($fans) ? 'danger' : ''] : null,
                $uplinks ? ['label' => 'Uplinks up', 'value' => $upCount($uplinks), 'tone' => ''] : null,
                $pons ? ['label' => 'PON ports up', 'value' => $upCount($pons), 'tone' => ''] : null,
            ]));
            $offline = (int) $o['offline'];
            $weak = (int) $o['weak'];
            $cards[] = [
                'id' => $id,
                'name' => (string) $o['name'],
                'host' => (string) $o['host'],
                'model' => Olts::MODELS[$o['model']] ?? (string) $o['model'],
                'enabled' => $enabled,
                'danger' => $danger,
                'readings' => $readings,
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

    /**
     * History of the OLT's hardware for one graph.
     *
     * @param  array<string, string>  $columns  chart series key => column of olt_hw_samples
     * @return array{start:int,end:int,bucket:int,points:list<array<string, float|int|null>>}
     */
    public static function hardwareSeries(int $oltId, string $range, array $columns): array
    {
        [$length, $bucket] = self::RANGES[$range];
        $end = intdiv(time(), $bucket) * $bucket + $bucket;
        $start = $end - $length;
        $select = implode(',', array_map(static fn (string $key, string $column) => "AVG($column) AS $key", array_keys($columns), $columns));
        $rows = Db::select("SELECT FLOOR(sampled_at/?)*? AS bucket,$select FROM olt_hw_samples WHERE olt_id=? AND sampled_at>=? AND sampled_at<? GROUP BY FLOOR(sampled_at/?) ORDER BY bucket", [$bucket, $bucket, $oltId, $start, $end, $bucket]);
        $byBucket = array_column($rows, null, 'bucket');
        $points = [];
        for ($t = $start; $t < $end; $t += $bucket) {
            $point = ['t' => $t];
            foreach (array_keys($columns) as $key) {
                $value = $byBucket[$t][$key] ?? null;
                $point[$key] = $value === null ? null : (float) $value;
            }
            $points[] = $point;
        }

        return ['start' => $start, 'end' => $end, 'bucket' => $bucket, 'points' => $points];
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
