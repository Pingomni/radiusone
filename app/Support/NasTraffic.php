<?php
declare(strict_types=1);
namespace App\Support;
use RuntimeException;

/** SNMP interface traffic history: recorded by bin/poll-snmp.php, read by the NAS monitor graph. */
final class NasTraffic
{
    public const RETENTION_SECONDS = 7 * 86400 + 3600;
    /** A newest sample older than this means the scheduled poller (or the device) has stopped. */
    public const STALE_SECONDS = 180;
    /** A gap longer than this between two samples is not turned into a rate. */
    private const MAX_RATE_GAP = 600;
    /** Range => [length in seconds, bucket in seconds, label]. About 60-120 points each. */
    public const RANGES = [
        '1h' => [3600, 60, 'Last hour'],
        '6h' => [21600, 300, '6 hours'],
        '24h' => [86400, 900, '24 hours'],
        '7d' => [604800, 5400, '7 days'],
    ];

    public static function available(): bool
    {
        return !SchemaCompatibility::problems('snmp_history');
    }

    public static function healthAvailable(): bool
    {
        return !SchemaCompatibility::problems('snmp_health');
    }

    public static function temperatureAvailable(): bool
    {
        return !SchemaCompatibility::problems('snmp_temperature');
    }

    /** Fixed categorical order has 8 hues; sensors beyond that are listed, never given a cycled color. */
    public const MAX_TEMPERATURE_SENSORS = 8;

    /**
     * Records the RADIUS server (device 0, from /proc) and polls every NAS with SNMP set up.
     * $only limits the run to one device (0 = the server), for the "Poll now" button.
     * @return array{polled:int,failed:int,rows:int,purged:int,errors:array<string,string>,server:?string}
     */
    public static function recordAll(?callable $clientFactory = null, ?int $only = null): array
    {
        SchemaCompatibility::require('snmp_history');
        $lock = 'frweb:snmp-poll:' . substr(hash('sha256', (string) Db::value('SELECT DATABASE()')), 0, 40);
        if ((int) Db::value('SELECT GET_LOCK(?,0)', [$lock]) !== 1) {
            throw new RuntimeException('Another SNMP poller is still running.');
        }
        try {
            $summary = ['polled' => 0, 'failed' => 0, 'rows' => 0, 'health_rows' => 0, 'purged' => 0, 'errors' => [], 'server' => null];
            $health = self::healthAvailable();
            $temperature = self::temperatureAvailable();
            $summary['temperature_rows'] = 0;
            if ($only === null || $only === ServerMonitor::ID) {
                $server = ServerMonitor::snapshot(self::previous(ServerMonitor::ID));
                $summary['rows'] += self::record(ServerMonitor::ID, $server);
                if ($health) { $summary['health_rows'] += self::recordHealth(ServerMonitor::ID, $server); }
                if ($temperature) { $summary['temperature_rows'] += self::recordTemperatures(ServerMonitor::ID, $server); }
                $summary['server'] = 'recorded';
            }
            $rows = $only === ServerMonitor::ID ? [] : Db::select('SELECT n.*, m.vendor, s.* FROM nas n JOIN nas_snmp s ON s.nas_id=n.id LEFT JOIN nas_metadata m ON m.nas_id=n.id WHERE s.enabled=1 AND COALESCE(m.pending_delete,0)=0' . ($only !== null ? ' AND n.id=' . (int) $only : '') . ' ORDER BY n.id');
            foreach ($rows as $row) {
                if (!NasMonitor::configured($row)) { continue; }
                $nasId = (int) $row['nas_id'];
                $snapshot = NasMonitor::poll($row, $row, self::previous($nasId), $clientFactory ? $clientFactory($row) : null);
                if (!$snapshot['ok']) {
                    $summary['failed']++;
                    $summary['errors'][(string) ($row['shortname'] ?: $row['nasname'])] = (string) $snapshot['error'];
                    continue;
                }
                $summary['polled']++;
                $summary['rows'] += self::record($nasId, $snapshot);
                if ($health) { $summary['health_rows'] += self::recordHealth($nasId, $snapshot); }
                if ($temperature) { $summary['temperature_rows'] += self::recordTemperatures($nasId, $snapshot); }
            }
            $summary['purged'] = Db::exec('DELETE FROM nas_snmp_samples WHERE sampled_at < ? LIMIT 50000', [time() - self::RETENTION_SECONDS]);
            if ($health) { $summary['purged'] += Db::exec('DELETE FROM nas_snmp_health_samples WHERE sampled_at < ? LIMIT 50000', [time() - self::RETENTION_SECONDS]); }
            if ($temperature) { $summary['purged'] += Db::exec('DELETE FROM nas_snmp_temperature_samples WHERE sampled_at < ? LIMIT 50000', [time() - self::RETENTION_SECONDS]); }
            return $summary;
        } finally {
            Db::value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** Counters from the newest stored sample, so the next poll can derive rates. */
    public static function previous(int $nasId): ?array
    {
        $rows = Db::select(
            'SELECT if_index, in_octets, out_octets, sampled_at FROM nas_snmp_samples
             WHERE nas_id=? AND sampled_at=(SELECT MAX(sampled_at) FROM nas_snmp_samples WHERE nas_id=?)',
            [$nasId, $nasId]
        );
        if (!$rows || time() - (int) $rows[0]['sampled_at'] > self::MAX_RATE_GAP) { return null; }
        $counters = [];
        foreach ($rows as $row) {
            $counters[(int) $row['if_index']] = ['in' => $row['in_octets'] !== null ? (float) $row['in_octets'] : null, 'out' => $row['out_octets'] !== null ? (float) $row['out_octets'] : null];
        }
        return ['polled_at' => (float) $rows[0]['sampled_at'], 'counters' => $counters];
    }

    public static function record(int $nasId, array $snapshot): int
    {
        $at = (int) round($snapshot['polled_at']);
        $count = 0;
        $counter = fn ($v) => is_numeric($v) && $v >= 0 ? number_format((float) $v, 0, '', '') : null;
        $rate = fn ($v) => is_numeric($v) && $v >= 0 ? (int) round((float) $v) : null;
        foreach ($snapshot['interfaces'] as $if) {
            $count += Db::exec(
                'INSERT INTO nas_snmp_samples (nas_id, if_index, if_name, sampled_at, in_octets, out_octets, in_bps, out_bps)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE if_name=VALUES(if_name), in_octets=VALUES(in_octets), out_octets=VALUES(out_octets), in_bps=VALUES(in_bps), out_bps=VALUES(out_bps)',
                [$nasId, (int) $if['index'], mb_substr((string) $if['name'], 0, 120), $at, $counter($if['in']), $counter($if['out']), $rate($if['in_bps']), $rate($if['out_bps'])]
            ) > 0 ? 1 : 0;
        }
        return $count;
    }

    /** Stores CPU and memory when the device reported either. */
    public static function recordHealth(int $nasId, array $snapshot): int
    {
        $memory = $snapshot['memory'] ?? null;
        if ($snapshot['cpu'] === null && !$memory) { return 0; }
        return Db::exec(
            'INSERT INTO nas_snmp_health_samples (nas_id, sampled_at, cpu_pct, mem_used, mem_total) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE cpu_pct=VALUES(cpu_pct), mem_used=VALUES(mem_used), mem_total=VALUES(mem_total)',
            [$nasId, (int) round($snapshot['polled_at']), $snapshot['cpu'] === null ? null : max(0, min(100, round((float) $snapshot['cpu'], 2))),
                $memory ? number_format((float) $memory['used'], 0, '', '') : null, $memory ? number_format((float) $memory['total'], 0, '', '') : null]
        ) > 0 ? 1 : 0;
    }

    /** Stores every °C sensor the poll reported (MikroTik health gauges/scalars, Cisco envmon). */
    public static function recordTemperatures(int $nasId, array $snapshot): int
    {
        $count = 0;
        $seen = [];
        foreach ($snapshot['sensors'] ?? [] as $sensor) {
            if (($sensor['unit'] ?? '') !== '°C' || !is_numeric($sensor['value'])) { continue; }
            $name = mb_substr(trim((string) $sensor['label']) ?: 'Temperature', 0, 80);
            if (isset($seen[$name])) { continue; } // Duplicate labels: keep the first reading.
            $seen[$name] = true;
            $count += Db::exec(
                'INSERT INTO nas_snmp_temperature_samples (nas_id, sensor, sampled_at, celsius) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE celsius=VALUES(celsius)',
                [$nasId, $name, (int) round($snapshot['polled_at']), max(-99.9, min(999.9, round((float) $sensor['value'], 1)))]
            ) > 0 ? 1 : 0;
        }
        return $count;
    }

    /**
     * Temperature per sensor (°C), averaged per bucket, with gaps as null. Sensors are sorted by
     * name so each keeps its color slot; past MAX_TEMPERATURE_SENSORS they are reported, not drawn.
     * @return array{start:int,end:int,bucket:int,sensors:list<string>,hidden:list<string>,points:list<array>,peaks:array<string,float>,latest:array<string,?float>,samples:int}
     */
    public static function temperatureSeries(int $nasId, string $range, ?int $now = null): array
    {
        [$length, $bucket] = self::RANGES[$range] ?? self::RANGES['1h'];
        $now ??= time();
        $end = intdiv($now, $bucket) * $bucket + $bucket;
        $start = $end - $length;
        $all = array_column(Db::select('SELECT DISTINCT sensor FROM nas_snmp_temperature_samples WHERE nas_id=? AND sampled_at >= ? AND sampled_at < ? ORDER BY sensor', [$nasId, $start, $end]), 'sensor');
        $sensors = array_slice($all, 0, self::MAX_TEMPERATURE_SENSORS);
        $hidden = array_slice($all, self::MAX_TEMPERATURE_SENSORS);
        $rows = $sensors ? Db::select(
            'SELECT sensor, FLOOR(sampled_at / ?) * ? AS bucket, AVG(celsius) AS celsius, MAX(celsius) AS peak, COUNT(*) AS samples
             FROM nas_snmp_temperature_samples WHERE nas_id=? AND sampled_at >= ? AND sampled_at < ? AND sensor IN (' . implode(',', array_fill(0, count($sensors), '?')) . ')
             GROUP BY sensor, FLOOR(sampled_at / ?) ORDER BY bucket',
            array_merge([$bucket, $bucket, $nasId, $start, $end], $sensors, [$bucket])
        ) : [];
        $values = [];
        $peaks = [];
        $samples = 0;
        foreach ($rows as $row) {
            $values[(int) $row['bucket']][$row['sensor']] = (float) $row['celsius'];
            $peaks[$row['sensor']] = max($peaks[$row['sensor']] ?? -999.0, (float) $row['peak']);
            $samples += (int) $row['samples'];
        }
        $points = [];
        $latest = array_fill_keys($sensors, null);
        for ($t = $start; $t < $end; $t += $bucket) {
            $point = ['t' => $t];
            foreach ($sensors as $i => $sensor) {
                $point['s' . $i] = $values[$t][$sensor] ?? null;
                if ($point['s' . $i] !== null) { $latest[$sensor] = $point['s' . $i]; }
            }
            $points[] = $point;
        }
        return ['start' => $start, 'end' => $end, 'bucket' => $bucket, 'sensors' => $sensors, 'hidden' => $hidden, 'points' => $points, 'peaks' => $peaks, 'latest' => $latest, 'samples' => $samples];
    }

    /** Axis that need not start at zero: 4 equal nice steps covering min..max. */
    public static function niceRange(float $min, float $max): array
    {
        if ($max - $min < 4) { $mid = ($min + $max) / 2; $min = $mid - 2; $max = $mid + 2; }
        $raw = ($max - $min) / 4;
        $magnitude = 10 ** floor(log10($raw));
        foreach ([1, 2, 2.5, 5, 10, 20] as $factor) {
            $step = $factor * $magnitude;
            $low = floor($min / $step) * $step;
            if ($low + 4 * $step >= $max) { return [$low, $low + 4 * $step, $step]; }
        }
        return [$low, $low + 4 * $step, $step];
    }

    /**
     * CPU and memory use in percent, averaged per bucket, with gaps as null.
     * @return array{start:int,end:int,bucket:int,points:list<array{t:int,cpu:?float,mem:?float}>,peak_cpu:?float,peak_mem:?float,mem_used:?float,mem_total:?float,samples:int}
     */
    public static function healthSeries(int $nasId, string $range, ?int $now = null): array
    {
        [$length, $bucket] = self::RANGES[$range] ?? self::RANGES['1h'];
        $now ??= time();
        $end = intdiv($now, $bucket) * $bucket + $bucket;
        $start = $end - $length;
        $rows = Db::select(
            'SELECT FLOOR(sampled_at / ?) * ? AS bucket, AVG(cpu_pct) AS cpu, AVG(mem_used / NULLIF(mem_total, 0) * 100) AS mem,
                    MAX(cpu_pct) AS peak_cpu, MAX(mem_used / NULLIF(mem_total, 0) * 100) AS peak_mem, COUNT(*) AS samples
             FROM nas_snmp_health_samples WHERE nas_id=? AND sampled_at >= ? AND sampled_at < ?
             GROUP BY FLOOR(sampled_at / ?) ORDER BY bucket',
            [$bucket, $bucket, $nasId, $start, $end, $bucket]
        );
        $byBucket = array_column($rows, null, 'bucket');
        $points = [];
        $peakCpu = $peakMem = null;
        $samples = 0;
        for ($t = $start; $t < $end; $t += $bucket) {
            $row = $byBucket[$t] ?? null;
            $points[] = ['t' => $t, 'cpu' => $row && $row['cpu'] !== null ? (float) $row['cpu'] : null, 'mem' => $row && $row['mem'] !== null ? (float) $row['mem'] : null];
            if ($row) {
                $samples += (int) $row['samples'];
                if ($row['peak_cpu'] !== null) { $peakCpu = max($peakCpu ?? 0, (float) $row['peak_cpu']); }
                if ($row['peak_mem'] !== null) { $peakMem = max($peakMem ?? 0, (float) $row['peak_mem']); }
            }
        }
        $latest = Db::row('SELECT mem_used, mem_total FROM nas_snmp_health_samples WHERE nas_id=? AND sampled_at >= ? AND mem_total > 0 ORDER BY sampled_at DESC LIMIT 1', [$nasId, $start]);
        return ['start' => $start, 'end' => $end, 'bucket' => $bucket, 'points' => $points, 'peak_cpu' => $peakCpu, 'peak_mem' => $peakMem,
            'mem_used' => $latest ? (float) $latest['mem_used'] : null, 'mem_total' => $latest ? (float) $latest['mem_total'] : null, 'samples' => $samples];
    }

    /**
     * Interfaces with samples in the window, busiest first.
     * @return list<array{if_index:int,if_name:string,avg_bps:float}>
     */
    public static function interfaces(int $nasId, int $since): array
    {
        return array_map(fn ($r) => ['if_index' => (int) $r['if_index'], 'if_name' => (string) $r['if_name'], 'avg_bps' => (float) $r['avg_bps']], Db::select(
            'SELECT if_index, MAX(if_name) AS if_name, COALESCE(AVG(COALESCE(in_bps,0) + COALESCE(out_bps,0)), 0) AS avg_bps
             FROM nas_snmp_samples WHERE nas_id=? AND sampled_at >= ? GROUP BY if_index ORDER BY avg_bps DESC, if_index',
            [$nasId, $since]
        ));
    }

    /**
     * Averages per bucket over the range ending now. Buckets without data are null so the
     * graph shows gaps instead of drawing across outages.
     * @return array{start:int,end:int,bucket:int,points:list<array{t:int,in:?float,out:?float}>,peak_in:?float,peak_out:?float,samples:int}
     */
    public static function series(int $nasId, int $ifIndex, string $range, ?int $now = null): array
    {
        [$length, $bucket] = self::RANGES[$range] ?? self::RANGES['1h'];
        $now ??= time();
        $end = intdiv($now, $bucket) * $bucket + $bucket;
        $start = $end - $length;
        $rows = Db::select(
            'SELECT FLOOR(sampled_at / ?) * ? AS bucket, AVG(in_bps) AS in_bps, AVG(out_bps) AS out_bps, MAX(in_bps) AS peak_in, MAX(out_bps) AS peak_out, COUNT(*) AS samples
             FROM nas_snmp_samples WHERE nas_id=? AND if_index=? AND sampled_at >= ? AND sampled_at < ?
             GROUP BY FLOOR(sampled_at / ?) ORDER BY bucket',
            [$bucket, $bucket, $nasId, $ifIndex, $start, $end, $bucket]
        );
        $byBucket = array_column($rows, null, 'bucket');
        $points = [];
        $peakIn = $peakOut = null;
        $samples = 0;
        for ($t = $start; $t < $end; $t += $bucket) {
            $row = $byBucket[$t] ?? null;
            $points[] = ['t' => $t, 'in' => $row && $row['in_bps'] !== null ? (float) $row['in_bps'] : null, 'out' => $row && $row['out_bps'] !== null ? (float) $row['out_bps'] : null];
            if ($row) {
                $samples += (int) $row['samples'];
                if ($row['peak_in'] !== null) { $peakIn = max($peakIn ?? 0, (float) $row['peak_in']); }
                if ($row['peak_out'] !== null) { $peakOut = max($peakOut ?? 0, (float) $row['peak_out']); }
            }
        }
        return ['start' => $start, 'end' => $end, 'bucket' => $bucket, 'points' => $points, 'peak_in' => $peakIn, 'peak_out' => $peakOut, 'samples' => $samples];
    }

    /** Axis maximum and step: 4 equal steps of 1, 2, 2.5 or 5 x 10^n. */
    public static function niceScale(float $max): array
    {
        if ($max <= 0) { return [4000.0, 1000.0]; }
        $raw = $max / 4;
        $magnitude = 10 ** floor(log10($raw));
        foreach ([1, 2, 2.5, 5, 10] as $factor) {
            if ($factor * $magnitude >= $raw) { $step = $factor * $magnitude; break; }
        }
        return [$step * 4, $step];
    }
}
