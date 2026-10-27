<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * The RADIUS server itself, monitored by default without SNMP: CPU, memory, disk, load, uptime,
 * temperatures and interface traffic from /proc and /sys, plus the services the portal depends on.
 * History is stored in the SNMP sample tables under the reserved device ID 0.
 */
final class ServerMonitor
{
    public const ID = 0;
    private const SERVICES = ['freeradius' => 'FreeRADIUS', 'mariadb' => 'MariaDB', 'nginx' => 'Nginx', 'cron' => 'Cron (scheduled jobs)'];

    /** A NAS-shaped row so the history graphs and their endpoints can serve the server too. */
    public static function pseudoNas(): array
    {
        $host = (string) (gethostname() ?: 'localhost');
        return ['id' => self::ID, 'nasname' => $host, 'shortname' => 'RADIUS server', 'vendor' => 'server', 'type' => 'server', 'description' => '', 'secret' => ''];
    }

    /** Snapshot in the shape NasMonitor::poll() returns, so NasTraffic can record it unchanged. */
    public static function snapshot(?array $previous = null, float $cpuWindow = 1.0): array
    {
        $memory = self::memory();
        $snapshot = [
            'ok' => true, 'error' => null, 'vendor' => 'server', 'polled_at' => microtime(true), 'latency_ms' => 0,
            'system' => ['name' => (string) gethostname(), 'description' => php_uname('s') . ' ' . php_uname('r'), 'uptime' => self::uptime()],
            'cpu' => self::cpuPercent($cpuWindow), 'cpu_detail' => [], 'memory' => $memory, 'storage' => self::disk(),
            'sensors' => self::temperatures(), 'subscribers' => [], 'interfaces' => [], 'hidden_interfaces' => 0, 'counters' => [], 'warnings' => [],
        ];
        [$snapshot['interfaces'], $snapshot['counters'], $snapshot['polled_at']] = self::interfaces($previous);
        return $snapshot;
    }

    /** Busy share of all CPUs over a short window, from two /proc/stat readings. */
    public static function cpuPercent(float $window = 1.0): ?float
    {
        $first = self::cpuTimes();
        if ($first === null) {
            return null;
        }
        usleep((int) (max(0.1, $window) * 1e6));
        $second = self::cpuTimes();
        if ($second === null || $second[1] <= $first[1]) {
            return null;
        }
        return round(100 * (1 - ($second[0] - $first[0]) / ($second[1] - $first[1])), 1);
    }

    /** @return array{0:float,1:float}|null idle and total jiffies */
    private static function cpuTimes(): ?array
    {
        $line = strtok((string) @file_get_contents('/proc/stat'), "\n");
        if (!$line || !str_starts_with($line, 'cpu ')) {
            return null;
        }
        $values = array_map('floatval', preg_split('/\s+/', trim(substr($line, 4))));
        // idle + iowait count as idle; guest time is already included in user time.
        return [($values[3] ?? 0) + ($values[4] ?? 0), array_sum(array_slice($values, 0, 8))];
    }

    /** @return array{used:float,total:float}|null */
    public static function memory(): ?array
    {
        $info = [];
        foreach (explode("\n", (string) @file_get_contents('/proc/meminfo')) as $line) {
            if (preg_match('/^(\w+):\s+(\d+) kB/', $line, $m)) {
                $info[$m[1]] = (float) $m[2] * 1024;
            }
        }
        if (empty($info['MemTotal']) || !isset($info['MemAvailable'])) {
            return null;
        }
        return ['used' => $info['MemTotal'] - $info['MemAvailable'], 'total' => $info['MemTotal']];
    }

    /** Root filesystem. @return array{used:float,total:float}|null */
    public static function disk(): ?array
    {
        $total = @disk_total_space('/');
        $free = @disk_free_space('/');
        return $total ? ['used' => (float) $total - (float) $free, 'total' => (float) $total] : null;
    }

    public static function uptime(): ?int
    {
        $raw = @file_get_contents('/proc/uptime');
        return $raw === false ? null : (int) floatval($raw);
    }

    /** @return array{0:float,1:float,2:float}|null 1, 5 and 15-minute load averages */
    public static function load(): ?array
    {
        $raw = @file_get_contents('/proc/loadavg');
        if ($raw === false) {
            return null;
        }
        $parts = explode(' ', $raw);
        return [(float) $parts[0], (float) $parts[1], (float) $parts[2]];
    }

    public static function cpuCount(): int
    {
        return max(1, (int) preg_match_all('/^processor\s*:/m', (string) @file_get_contents('/proc/cpuinfo')));
    }

    /** hwmon temperature inputs (°C). Virtual machines usually expose none. */
    public static function temperatures(): array
    {
        $sensors = [];
        foreach (glob('/sys/class/hwmon/hwmon*/temp*_input') ?: [] as $input) {
            $raw = @file_get_contents($input);
            if ($raw === false || !is_numeric(trim($raw))) {
                continue;
            }
            $dir = dirname($input);
            $label = trim((string) @file_get_contents(str_replace('_input', '_label', $input)));
            $chip = trim((string) @file_get_contents($dir . '/name'));
            $name = trim(($chip !== '' ? $chip : basename($dir)) . ' ' . ($label !== '' ? $label : basename($input, '_input')));
            $value = round((float) trim($raw) / 1000, 1);
            if ($value <= -50 || $value >= 150) {
                continue;
            } // Disconnected probes report nonsense.
            $sensors[] = ['label' => $name, 'value' => $value, 'unit' => '°C', 'state' => $value >= 85 ? 'critical' : ($value >= 70 ? 'warning' : 'ok')];
        }
        return $sensors;
    }

    /**
     * Interface counters from /proc/net/dev (loopback excluded); In = received, Out = sent.
     *
     * @return array{0:list<array>,1:array<int,array{in:float,out:float}>,2:float}
     */
    public static function interfaces(?array $previous): array
    {
        $now = microtime(true);
        $elapsed = isset($previous['polled_at']) ? $now - (float) $previous['polled_at'] : 0;
        $rows = [];
        $counters = [];
        foreach (array_slice(explode("\n", (string) @file_get_contents('/proc/net/dev')), 2) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $data] = array_map('trim', explode(':', $line, 2));
            if ($name === 'lo') {
                continue;
            }
            $fields = preg_split('/\s+/', $data);
            $index = (int) trim((string) @file_get_contents('/sys/class/net/' . basename($name) . '/ifindex'));
            if ($index <= 0 || count($fields) < 9) {
                continue;
            }
            $in = (float) $fields[0];
            $out = (float) $fields[8];
            $row = ['index' => $index, 'name' => $name, 'alias' => '', 'admin' => 1, 'oper' => trim((string) @file_get_contents('/sys/class/net/' . basename($name) . '/operstate')) === 'down' ? 2 : 1,
                'speed' => null, 'in' => $in, 'out' => $out, 'in_errors' => (int) $fields[2], 'out_errors' => (int) $fields[10], 'in_bps' => null, 'out_bps' => null];
            $old = $previous['counters'][$index] ?? null;
            if ($old && $elapsed >= 1) {
                foreach (['in', 'out'] as $direction) {
                    $delta = $row[$direction] - (float) ($old[$direction] ?? -1);
                    $row[$direction . '_bps'] = isset($old[$direction]) && $delta >= 0 ? $delta * 8 / $elapsed : null;
                }
            }
            $rows[] = $row;
            $counters[$index] = ['in' => $in, 'out' => $out];
        }
        return [$rows, $counters, $now];
    }

    /** @return array<string, array{label:string,state:string,since:?int}> systemd state per service and when it last became active */
    public static function services(): array
    {
        // Several browsers refresh Monitoring at once; five systemctl calls each are not needed.
        return MicroCache::remember('server-services', 10, static fn () => self::checkServices());
    }

    private static function checkServices(): array
    {
        $units = self::SERVICES;
        $fpm = glob('/lib/systemd/system/php*-fpm.service') ?: [];
        rsort($fpm, SORT_NATURAL);
        if ($fpm) {
            $units = array_slice($units, 0, 3, true) + [basename($fpm[0], '.service') => 'PHP-FPM'] + array_slice($units, 3, null, true);
        }
        // One systemctl call for all units; blocks come back in argument order.
        $blocks = [];
        try {
            $output = BoundedProcess::run(['systemctl', 'show', '--timestamp=unix', '-p', 'ActiveState,ActiveEnterTimestamp', ...array_keys($units)], '', 3, 4096)['output'];
            $blocks = preg_split('/\n\s*\n/', trim($output)) ?: [];
        } catch (RuntimeException) {
        }
        $result = [];
        foreach (array_keys($units) as $i => $unit) {
            preg_match('/^ActiveState=(\S+)/m', $blocks[$i] ?? '', $state);
            preg_match('/^ActiveEnterTimestamp=@(\d+)/m', $blocks[$i] ?? '', $since);
            $result[$unit] = ['label' => $units[$unit], 'state' => $state[1] ?? 'unknown', 'since' => isset($since[1]) ? (int) $since[1] : null];
        }
        return $result;
    }

    /** @return array<string, string> unit => label of the services the portal may restart (ops/radiusone-postinstall) */
    public static function restartable(): array
    {
        return array_diff_key(array_map(static fn (array $s) => $s['label'], self::services()), ['cron' => true]);
    }

    /**
     * Asks the root helper to restart one service. It checks the configuration first and starts the
     * restart two seconds later, so this request still completes when it restarts the web stack itself.
     */
    public static function restart(string $unit): void
    {
        if (!array_key_exists($unit, self::restartable())) {
            throw new RuntimeException('This service cannot be restarted from the portal.');
        }
        if (!is_executable(Paths::helper('service-restart'))) {
            throw new RuntimeException('Service restarts are not enabled on this server. Run ops/radiusone-postinstall as root first.');
        }
        $result = BoundedProcess::run(['sudo', '-n', Paths::helper('service-restart'), $unit], '', 30, 8192);
        if ($result['exit'] !== 0) {
            $detail = trim($result['error']) ?: trim($result['output']);
            if (str_contains($detail, 'password is required') || str_contains($detail, 'not allowed')) {
                $detail = 'www-data is not allowed to run the restart helper. Run ops/radiusone-postinstall as root.';
            }
            throw new RuntimeException($detail !== '' ? $detail : 'The restart could not be started.');
        }
    }

    /** RADIUS outcomes in the last minutes, from the redacted post-auth log. */
    public static function authStats(int $minutes = 15): array
    {
        $row = Db::row('SELECT COALESCE(SUM(reply LIKE "%Accept%"),0) AS accepts, COALESCE(SUM(reply LIKE "%Reject%"),0) AS rejects FROM radpostauth WHERE authdate >= NOW() - INTERVAL ? MINUTE', [$minutes]);
        return ['accepts' => (int) ($row['accepts'] ?? 0), 'rejects' => (int) ($row['rejects'] ?? 0)];
    }
}
