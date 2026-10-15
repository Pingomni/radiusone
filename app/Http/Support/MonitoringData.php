<?php

declare(strict_types=1);

namespace App\Http\Support;

use App\Support\Accounting;
use App\Support\Auth;
use App\Support\Db;
use App\Support\JobHealth;
use App\Support\MicroCache;
use App\Support\NasMonitor;
use App\Support\NasTraffic;
use App\Support\RadiusRepository;
use App\Support\ServerMonitor;

/**
 * Data for the Monitoring pages and their live panels (legacy app/Http/monitoring.php). Everything is
 * returned as plain arrays: the React pages render it and the live endpoints send it as JSON.
 */
final class MonitoringData
{
    public static function age(int $seconds): string
    {
        return $seconds < 90 ? max(0, $seconds) . ' s' : ($seconds < 5400 ? round($seconds / 60) . ' min' : round($seconds / 3600, 1) . ' h');
    }

    /** Colour marks problems only; a normal value stays plain text. */
    public static function tone(?float $value, float $warn, float $danger): string
    {
        return $value === null ? '' : ($value >= $danger ? 'danger' : ($value >= $warn ? 'warning' : ''));
    }

    public static function pct(?float $value): string
    {
        return $value === null ? '-' : self::trim($value) . ' %';
    }

    private static function trim(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1), '0'), '.');
    }

    private static function item(string $label, string $value, string $tone = ''): array
    {
        return compact('label', 'value', 'tone');
    }

    /** Gauge tile; a meter is drawn when a percentage and thresholds are given. */
    private static function gauge(string $label, string $value, string $note, string $tone = '', ?float $meter = null, float $low = 75, float $high = 90): array
    {
        return ['label' => $label, 'value' => $value, 'note' => $note, 'tone' => $tone, 'meter' => $meter === null ? null : ['value' => round($meter, 1), 'low' => $low, 'high' => $high]];
    }

    /* ---------------------------------------------------------------- Overview */

    /**
     * Latest recorded sample per device from the history tables (no device is contacted while rendering).
     *
     * @return array<int, array{at:int,cpu:?float,mem:?float,hottest:?float,if_name:?string,in_bps:?float,out_bps:?float}>
     */
    private static function latest(): array
    {
        $since = time() - 86400;
        $latest = [];
        $at = static function (int $id, int $time) use (&$latest): void {
            $latest[$id] ??= ['at' => 0, 'cpu' => null, 'mem' => null, 'hottest' => null, 'if_name' => null, 'in_bps' => null, 'out_bps' => null];
            $latest[$id]['at'] = max($latest[$id]['at'], $time);
        };
        if (NasTraffic::available()) {
            foreach (Db::select('SELECT s.nas_id, s.sampled_at, s.if_name, s.in_bps, s.out_bps FROM nas_snmp_samples s
                JOIN (SELECT nas_id, MAX(sampled_at) AS t FROM nas_snmp_samples WHERE sampled_at >= ? GROUP BY nas_id) x ON x.nas_id=s.nas_id AND x.t=s.sampled_at', [$since]) as $row) {
                $id = (int) $row['nas_id'];
                $at($id, (int) $row['sampled_at']);
                $total = (float) $row['in_bps'] + (float) $row['out_bps'];
                if ($row['in_bps'] !== null && ($latest[$id]['if_name'] === null || $total > (float) $latest[$id]['in_bps'] + (float) $latest[$id]['out_bps'])) {
                    $latest[$id] = array_merge($latest[$id], ['if_name' => (string) $row['if_name'], 'in_bps' => (float) $row['in_bps'], 'out_bps' => (float) $row['out_bps']]);
                }
            }
        }
        if (NasTraffic::healthAvailable()) {
            foreach (Db::select('SELECT h.nas_id, h.sampled_at, h.cpu_pct, h.mem_used / NULLIF(h.mem_total, 0) * 100 AS mem FROM nas_snmp_health_samples h
                JOIN (SELECT nas_id, MAX(sampled_at) AS t FROM nas_snmp_health_samples WHERE sampled_at >= ? GROUP BY nas_id) x ON x.nas_id=h.nas_id AND x.t=h.sampled_at', [$since]) as $row) {
                $id = (int) $row['nas_id'];
                $at($id, (int) $row['sampled_at']);
                $latest[$id]['cpu'] = $row['cpu_pct'] !== null ? (float) $row['cpu_pct'] : null;
                $latest[$id]['mem'] = $row['mem'] !== null ? (float) $row['mem'] : null;
            }
        }
        if (NasTraffic::temperatureAvailable()) {
            foreach (Db::select('SELECT t.nas_id, MAX(t.celsius) AS hottest FROM nas_snmp_temperature_samples t
                JOIN (SELECT nas_id, MAX(sampled_at) AS t FROM nas_snmp_temperature_samples WHERE sampled_at >= ? GROUP BY nas_id) x ON x.nas_id=t.nas_id AND x.t=t.sampled_at GROUP BY t.nas_id', [$since]) as $row) {
                $at((int) $row['nas_id'], 0);
                $latest[(int) $row['nas_id']]['hottest'] = (float) $row['hottest'];
            }
        }
        return $latest;
    }

    /** Why a NAS can or cannot disconnect sessions right now. */
    private static function disconnectLabel(array $nas): string
    {
        if (empty($nas['enabled'])) {
            return 'off: NAS disabled';
        }
        if (($nas['applied_revision'] ?? null) === null || (int) $nas['applied_revision'] !== (int) ($nas['config_revision'] ?? 0)) {
            return 'waiting for RADIUS update';
        }
        $vendor = strtolower((string) ($nas['vendor'] ?: $nas['type']));
        return match ($vendor) {
            'mikrotik' => RadiusRepository::mikrotikDisconnectApiStatus($nas)['ok'] ? 'ready' : 'API login missing',
            'cisco' => (int) $nas['coa_enabled'] === 1 && !empty($nas['secret']) ? 'ready' : 'CoA off',
            default => 'unsupported platform',
        };
    }

    /**
     * Latest accounting packet per NAS id (accounting may name a NAS by address or short name):
     * the newest open-session update, or the newest stop. Index lookups only (open sessions are few;
     * stops use idx_radacct_nas_stop), so the cost does not grow with the session history.
     * @return array<int, string>
     */
    private static function lastAccounting(array $nasRows): array
    {
        $open = [];
        foreach (Db::select('SELECT nasipaddress, MAX(COALESCE(acctupdatetime, acctstarttime)) AS at FROM radacct WHERE acctstoptime IS NULL GROUP BY nasipaddress') as $row) {
            $open[(string) $row['nasipaddress']] = (string) $row['at'];
        }
        $last = [];
        foreach ($nasRows as $nas) {
            $times = [];
            foreach (array_unique(array_filter([(string) $nas['nasname'], (string) $nas['shortname']])) as $name) {
                $times[] = $open[$name] ?? null;
                $times[] = Db::value('SELECT MAX(acctstoptime) FROM radacct WHERE nasipaddress=?', [$name]) ?: null;
            }
            $times = array_filter($times);
            if ($times) {
                $last[(int) $nas['id']] = max($times);
            }
        }
        return $last;
    }

    public static function overview(): array
    {
        $latest = self::latest();
        $fresh = [];
        foreach (Db::select('SELECT r.nasipaddress, COUNT(*) AS n FROM radacct r WHERE ' . Accounting::freshSql('r') . ' GROUP BY r.nasipaddress') as $row) {
            $fresh[(string) $row['nasipaddress']] = (int) $row['n'];
        }
        $alerts = 0;
        $responding = 0;
        $configured = 0;

        // The RADIUS server: services and live system figures, CPU from the latest minute sample.
        $sample = $latest[ServerMonitor::ID] ?? null;
        $cpu = $sample && time() - $sample['at'] <= 120 ? $sample['cpu'] : MicroCache::remember('server-cpu', 10, static fn () => ServerMonitor::cpuPercent(0.3));
        $memory = ServerMonitor::memory();
        $memPct = $memory ? $memory['used'] / $memory['total'] * 100 : null;
        $disk = ServerMonitor::disk();
        $diskPct = $disk ? $disk['used'] / $disk['total'] * 100 : null;
        $load = ServerMonitor::load();
        $services = ServerMonitor::services();
        $down = array_values(array_filter($services, static fn ($s) => $s['state'] !== 'active'));
        $auth = ServerMonitor::authStats(15);
        $tones = [self::tone($cpu, 75, 90), self::tone($memPct, 85, 95), self::tone($diskPct, 80, 90)];
        $alerts += count($down) + count(array_filter($tones, static fn ($t) => $t === 'danger'));
        $server = [
            'host' => (string) gethostname(),
            'running' => array_values(array_column(array_filter($services, static fn ($s) => $s['state'] === 'active'), 'label')),
            'down' => array_map(static fn ($s) => $s['label'] . ': ' . $s['state'], $down),
            'items' => [
                self::item('CPU', self::pct($cpu), $tones[0]),
                self::item('Memory', self::pct($memPct), $tones[1]),
                self::item('Disk /', self::pct($diskPct), $tones[2]),
                self::item('Load (1/5/15 min)', $load ? implode(' / ', array_map(static fn ($v) => number_format($v, 2), $load)) . ' · ' . ServerMonitor::cpuCount() . ' CPUs' : '-'),
                self::item('RADIUS last 15 min', $auth['accepts'] . ' accepted · ' . $auth['rejects'] . ' rejected'),
                self::item('Online now (all NAS)', (string) array_sum($fresh)),
            ],
        ];

        $nasRows = Db::select('SELECT n.id, n.nasname, n.shortname, n.type, n.secret, COALESCE(m.vendor, n.type) AS vendor, COALESCE(m.enabled, 1) AS enabled, m.pending_delete, m.config_revision, m.applied_revision,
                COALESCE(m.coa_enabled, 1) AS coa_enabled, m.api_host, m.api_user, (COALESCE(m.api_password, "") <> "") AS api_password_saved
            FROM nas n LEFT JOIN nas_metadata m ON m.nas_id=n.id WHERE COALESCE(m.pending_delete, 0)=0 ORDER BY n.shortname, n.id');
        $lastAccountingAt = self::lastAccounting($nasRows);
        $snmp = NasMonitor::available() ? array_column(Db::select('SELECT * FROM nas_snmp'), null, 'nas_id') : [];
        // Without SNMP, ping is the only reachability signal; all pings run in parallel (about 1 s).
        $pingHosts = array_values(array_map(static fn ($n) => (string) $n['nasname'], array_filter($nasRows, static fn ($n) => !NasMonitor::configured($snmp[(int) $n['id']] ?? null))));
        $pings = $pingHosts ? MicroCache::remember('pings:' . implode(',', $pingHosts), 15, static fn () => Nas::pingStatuses($pingHosts)) : [];
        $canManage = Auth::can('nas.manage');
        $cards = [];
        foreach ($nasRows as $nas) {
            $id = (int) $nas['id'];
            $sample = $latest[$id] ?? null;
            $isConfigured = NasMonitor::configured($snmp[$id] ?? null);
            $configured += $isConfigured ? 1 : 0;
            $age = $sample && $sample['at'] > 0 ? time() - $sample['at'] : null;
            $ping = $isConfigured ? null : ($pings[trim((string) $nas['nasname'])] ?? null);
            [$state, $stateTone] = match (true) {
                $ping !== null && !$ping['ok'] => ['ping: ' . $ping['label'], 'danger'],
                !$isConfigured => ['SNMP off', ''],
                $age === null => ['waiting for first poll', ''],
                $age > NasTraffic::STALE_SECONDS => ['no data for ' . self::age($age), 'danger'],
                default => ['polling', ''],
            };
            $responding += $isConfigured && $age !== null && $age <= NasTraffic::STALE_SECONDS ? 1 : 0;
            $lastAt = $lastAccountingAt[(int) $nas['id']] ?? null;
            $lastAccounting = $lastAt ? max(0, time() - (int) strtotime($lastAt)) : null;
            $nasTones = [self::tone($sample['cpu'] ?? null, 75, 90), self::tone($sample['mem'] ?? null, 85, 95), self::tone($sample['hottest'] ?? null, 65, 80)];
            $alerts += ($stateTone === 'danger' ? 1 : 0) + count(array_filter($nasTones, static fn ($t) => $t === 'danger'));
            $disconnect = self::disconnectLabel($nas);
            $items = $isConfigured
                ? [
                    self::item('CPU', self::pct($sample['cpu'] ?? null), $nasTones[0]),
                    self::item('Memory', self::pct($sample['mem'] ?? null), $nasTones[1]),
                    self::item('Temperature', isset($sample['hottest']) ? self::trim($sample['hottest']) . ' °C' : '-', $nasTones[2]),
                    self::item('Busiest interface' . (($sample['if_name'] ?? null) ? ' (' . $sample['if_name'] . ')' : ''), $sample && $sample['if_name'] !== null ? self::bpsOrDash($sample['in_bps']) . ' in · ' . self::bpsOrDash($sample['out_bps']) . ' out' : '-'),
                ]
                : [self::item('Ping', $ping['label'] ?? '-', ($ping['ok'] ?? true) ? '' : 'danger')];
            $items[] = self::item('Disconnect', $disconnect, $disconnect === 'ready' ? '' : 'warning');
            $items[] = self::item('Online now', (string) (($fresh[(string) $nas['nasname']] ?? 0) + ($nas['shortname'] !== $nas['nasname'] ? ($fresh[(string) $nas['shortname']] ?? 0) : 0)));
            $items[] = self::item('Last accounting', $lastAccounting === null ? 'never' : self::age($lastAccounting) . ' ago');
            if ($isConfigured) {
                $items[] = self::item('Last sample', $age === null ? '-' : self::age($age) . ' ago');
            }
            $cards[] = [
                'id' => $id,
                'name' => (string) ($nas['shortname'] ?: $nas['nasname']),
                'ip' => (string) $nas['nasname'],
                'vendor' => Nas::VENDORS[strtolower((string) $nas['vendor'])]['label'] ?? (string) $nas['vendor'],
                'state' => Present::tag($state, $stateTone),
                'danger' => $stateTone === 'danger',
                'radiusDisabled' => empty($nas['enabled']),
                'items' => $items,
                'setupSnmp' => !$isConfigured && $canManage,
            ];
        }

        $jobs = JobHealth::all();
        $alerts += count(array_filter($jobs, static fn ($j) => $j['state'] === 'stuck'));
        return [
            'metrics' => [
                Present::metric('NAS responding', $configured ? $responding . ' of ' . $configured : 'SNMP not set up', 'server', $configured && $responding < $configured ? 'danger' : ''),
                Present::metric('RADIUS services', $down ? count($down) . ' down' : 'All running', 'shield', $down ? 'danger' : ''),
                Present::metric('Alerts', $alerts, 'signal', $alerts ? 'danger' : ''),
                Present::metric('Online now', array_sum($fresh), 'user'),
            ],
            'server' => $server,
            'nas' => $cards,
            'jobs' => array_values(array_map(static function (array $job) {
                [$text, $tone] = match ($job['state']) {
                    'running' => ['Running', ''],
                    'idle' => ['Idle', ''],
                    'not_running' => ['Not running', 'warning'],
                    'stuck' => ['Not sending', 'danger'],
                    default => ['Never run', ''],
                };
                return ['label' => (string) $job['label'], 'does' => (string) $job['does'], 'detail' => (string) $job['detail'], 'state' => $text, 'tone' => $tone];
            }, $jobs)),
        ];
    }

    /* ---------------------------------------------------------------- RADIUS server */

    public static function serverStatus(): array
    {
        $cpu = MicroCache::remember('server-cpu', 10, static fn () => ServerMonitor::cpuPercent(0.3));
        $memory = ServerMonitor::memory();
        $disk = ServerMonitor::disk();
        $load = ServerMonitor::load();
        $pct = static fn (?array $m) => $m && $m['total'] > 0 ? $m['used'] / $m['total'] * 100 : null;
        $auth = ServerMonitor::authStats(15);
        $hour = ServerMonitor::authStats(60);
        $services = [];
        foreach (ServerMonitor::services() as $unit => $service) {
            $services[] = ['label' => (string) $service['label'], 'unit' => (string) $unit, 'state' => (string) $service['state'], 'running' => $service['state'] === 'active'];
        }
        $uptime = ServerMonitor::uptime();
        return [
            'gauges' => [
                self::gauge('CPU', self::pct($cpu), ServerMonitor::cpuCount() . ' CPUs' . ($load ? ' · load ' . number_format($load[0], 2) : ''), self::tone($cpu, 75, 90), $cpu, 75, 90),
                self::gauge('Memory', self::pct($pct($memory)), $memory ? Format::bytes((int) $memory['used']) . ' of ' . Format::bytes((int) $memory['total']) : 'Not available', self::tone($pct($memory), 85, 95), $pct($memory), 85, 95),
                self::gauge('Disk /', self::pct($pct($disk)), $disk ? Format::bytes((int) $disk['used']) . ' of ' . Format::bytes((int) $disk['total']) : 'Not available', self::tone($pct($disk), 80, 90), $pct($disk), 80, 90),
                self::gauge('RADIUS last 15 min', $auth['accepts'] . ' / ' . $auth['rejects'], 'accepted / rejected · last hour ' . $hour['accepts'] . ' / ' . $hour['rejects']),
                self::gauge('Online now', (string) (int) Db::value('SELECT COUNT(*) FROM radacct r WHERE ' . Accounting::freshSql('r')), 'All NAS, from RADIUS accounting'),
            ],
            'services' => $services,
            'system' => [
                ['Host', (string) gethostname()],
                ['Uptime', $uptime !== null ? Present::uptime($uptime) : '-'],
                ['Load (1/5/15 min)', $load ? implode(' / ', array_map(static fn ($v) => number_format($v, 2), $load)) : '-'],
                ['Kernel', php_uname('s') . ' ' . php_uname('r')],
            ],
            'temperatures' => array_map(static fn (array $s) => [
                'label' => (string) $s['label'],
                'value' => $s['value'] . ' °C',
                'tone' => $s['state'] === 'ok' ? null : ($s['state'] === 'critical' ? 'danger' : 'warning'),
            ], ServerMonitor::temperatures()),
        ];
    }

    /* ---------------------------------------------------------------- Live NAS snapshot */

    public static function nasSnapshot(array $nas): array
    {
        $nasId = (int) $nas['id'];
        if (!NasMonitor::available()) {
            return ['status' => 'unavailable', 'message' => 'Apply database migration 020_nas_snmp.sql to enable SNMP monitoring.'];
        }
        $settings = NasMonitor::settings($nasId);
        if (!NasMonitor::configured($settings)) {
            return ['status' => 'off', 'message' => 'SNMP polling is not enabled for this NAS.', 'setup' => Auth::can('nas.manage') ? '/nas/' . $nasId . '/edit?tab=snmp' : null];
        }
        // Interface rates need the previous counters; keep one sample per NAS in this operator's session.
        $key = 'nas_snmp_sample.' . $nasId;
        $previous = session($key);
        $snap = NasMonitor::poll($nas, $settings, is_array($previous) ? $previous : null);
        if ($snap['ok']) {
            session()->put($key, ['polled_at' => $snap['polled_at'], 'counters' => $snap['counters']]);
        }
        $fresh = (int) Db::value('SELECT COUNT(*) FROM radacct r WHERE ' . Accounting::freshSql('r') . ' AND (r.nasipaddress=? OR r.nasipaddress=?)', [$nas['nasname'], $nas['shortname']]);
        $target = (($settings['host'] ?? '') ?: $nas['nasname']) . ':' . $settings['port'] . ' (v' . $settings['version']
            . ($settings['version'] === '3' ? ', ' . ($settings['v3_priv_protocol'] !== 'none' ? 'authPriv' : ($settings['v3_auth_protocol'] !== 'none' ? 'authNoPriv' : 'noAuthNoPriv')) : '') . ')';
        if (!$snap['ok']) {
            return ['status' => 'down', 'target' => $target, 'error' => (string) $snap['error'], 'fresh' => $fresh];
        }

        $pct = static fn (?array $m) => $m && $m['total'] > 0 ? round($m['used'] / $m['total'] * 100, 1) : null;
        $toneOf = static fn (?float $v) => $v === null ? '' : ($v >= 90 ? 'danger' : ($v >= 75 ? 'warning' : 'success'));
        $percent = static fn (?float $v) => $v === null ? 'n/a' : self::trim($v) . ' %';
        $memory = $pct($snap['memory']);
        $gauges = [
            self::gauge('CPU load', $percent($snap['cpu']), $snap['cpu_detail'] ? implode(' · ', array_map(static fn ($d) => $d[0] . ' ' . $d[1], $snap['cpu_detail'])) : 'Not reported', $toneOf($snap['cpu']), $snap['cpu']),
            self::gauge('Memory', $percent($memory), $snap['memory'] ? Format::bytes((int) $snap['memory']['used']) . ' of ' . Format::bytes((int) $snap['memory']['total']) : 'Not reported', $toneOf($memory), $memory),
        ];
        if ($snap['storage']) {
            $storage = $pct($snap['storage']);
            $gauges[] = self::gauge('Storage', $percent($storage), Format::bytes((int) $snap['storage']['used']) . ' of ' . Format::bytes((int) $snap['storage']['total']), $toneOf($storage), $storage);
        }
        foreach ($snap['subscribers'] as [$label, $count]) {
            $gauges[] = self::gauge((string) $label, (string) (int) $count, 'From SNMP');
        }
        $gauges[] = self::gauge('Online now', (string) $fresh, 'From RADIUS accounting');

        $system = [
            ['Status', 'responding · ' . (int) $snap['latency_ms'] . ' ms'],
            ['sysName', (string) ($snap['system']['name'] ?: '-')],
            ['Uptime', $snap['system']['uptime'] !== null ? Present::uptime((int) $snap['system']['uptime']) : '-'],
        ];
        foreach ($snap['system']['platform'] ?? [] as $label => $value) {
            $system[] = [(string) $label, (string) $value];
        }
        $system[] = ['Polled', $target];

        return [
            'status' => 'ok',
            'gauges' => $gauges,
            'system' => $system,
            'description' => (string) $snap['system']['description'],
            'sensors' => array_map(static function (array $sensor) {
                $value = is_float($sensor['value']) || is_int($sensor['value']) ? self::trim((float) $sensor['value']) . ' ' . $sensor['unit'] : (string) $sensor['value'];
                return ['label' => (string) $sensor['label'], 'value' => trim($value), 'tone' => $sensor['state'] === 'ok' ? null : ($sensor['state'] === 'critical' ? 'danger' : ($sensor['state'] === 'warning' ? 'warning' : ''))];
            }, $snap['sensors']),
            'interfaces' => array_map(static function (array $if) {
                $speed = is_numeric($if['speed']) && $if['speed'] > 0 ? ($if['speed'] >= 1000 ? ($if['speed'] / 1000) . ' Gbit/s' : $if['speed'] . ' Mbit/s') : '-';
                return [
                    'name' => (string) $if['name'],
                    'alias' => (string) $if['alias'],
                    'status' => (int) $if['admin'] === 2 ? Present::tag('disabled') : ((int) $if['oper'] === 1 ? Present::tag('up', null) : Present::tag('down', 'danger')),
                    'speed' => $speed,
                    'in' => Nas::bps($if['in_bps']),
                    'out' => Nas::bps($if['out_bps']),
                    'totals' => (is_numeric($if['in']) ? Format::bytes((int) $if['in']) : '-') . ' / ' . (is_numeric($if['out']) ? Format::bytes((int) $if['out']) : '-'),
                    'errors' => (int) ($if['in_errors'] ?? 0) + (int) ($if['out_errors'] ?? 0),
                ];
            }, $snap['interfaces']),
            'hiddenInterfaces' => (int) $snap['hidden_interfaces'],
            'warnings' => array_values(array_map('strval', $snap['warnings'])),
        ];
    }

    /* ---------------------------------------------------------------- History charts */

    /** Range, interface choice and the three chart panels for a NAS (or the server) monitor page. */
    public static function history(array $nas, ?string $requestedRange, mixed $requestedIf): array
    {
        $nasId = (int) $nas['id'];
        if (!NasTraffic::available()) {
            return ['available' => false];
        }
        [$selected, $range, $interfaces] = self::selection($nasId, $requestedRange, $requestedIf);
        $hasHealth = (NasTraffic::healthAvailable() && (bool) Db::value('SELECT 1 FROM nas_snmp_health_samples WHERE nas_id=? LIMIT 1', [$nasId]))
            || (NasTraffic::temperatureAvailable() && (bool) Db::value('SELECT 1 FROM nas_snmp_temperature_samples WHERE nas_id=? LIMIT 1', [$nasId]));
        $query = static fn (array $q) => '?' . http_build_query(array_filter($q, static fn ($v) => $v !== null));
        return [
            'available' => true,
            'range' => $range,
            'ranges' => array_map(static fn ($r) => $r[2], NasTraffic::RANGES),
            'interfaces' => array_map(static fn ($if) => ['value' => (string) $if['if_index'], 'label' => (string) $if['if_name']], $interfaces),
            'selected' => $selected,
            'showFilters' => $interfaces !== [] || $hasHealth,
            'health' => NasTraffic::healthAvailable() ? ['url' => '/nas/' . $nasId . '/monitor/health' . $query(['range' => $range]), 'data' => self::healthChart($nas, $range)] : null,
            'temperature' => NasTraffic::temperatureAvailable() ? ['url' => '/nas/' . $nasId . '/monitor/temperature' . $query(['range' => $range]), 'data' => self::temperatureChart($nas, $range)] : null,
            'traffic' => ['url' => '/nas/' . $nasId . '/monitor/graph' . $query(['if' => $selected, 'range' => $range]), 'data' => self::trafficChart($nas, $selected, $range, $interfaces)],
        ];
    }

    public static function range(?string $requested): string
    {
        return isset(NasTraffic::RANGES[$requested ?? '']) ? (string) $requested : '1h';
    }

    /** @return array{0:?int,1:string,2:list<array>} selected interface, range key and interfaces with data */
    public static function selection(int $nasId, ?string $requestedRange, mixed $requestedIf): array
    {
        $range = self::range($requestedRange);
        $interfaces = NasTraffic::interfaces($nasId, time() - NasTraffic::RANGES[$range][0]);
        $requested = is_string($requestedIf) && ctype_digit($requestedIf) ? (int) $requestedIf : null;
        $selected = in_array($requested, array_column($interfaces, 'if_index'), true) ? $requested : ($interfaces[0]['if_index'] ?? null);
        return [$selected, $range, $interfaces];
    }

    /** The server is always recorded; a NAS only once SNMP polling is set up. */
    private static function historyRecorded(array $nas): bool
    {
        if ((int) $nas['id'] === ServerMonitor::ID) {
            return true;
        }
        return NasMonitor::available() && NasMonitor::configured(NasMonitor::settings((int) $nas['id']));
    }

    /** @return array{message: string}|array{chart: array} */
    public static function healthChart(array $nas, string $range): array
    {
        $series = NasTraffic::healthSeries((int) $nas['id'], $range);
        if ($series['samples'] === 0 && !Db::value('SELECT 1 FROM nas_snmp_health_samples WHERE nas_id=? LIMIT 1', [(int) $nas['id']])) {
            return ['message' => self::historyRecorded($nas)
                ? 'No CPU or memory history yet. It is recorded every minute by the SNMP poller; use Poll now to record a sample immediately.'
                : 'CPU and memory history is recorded once SNMP polling is set up for this NAS.'];
        }
        $latest = self::latestValues($series['points'], ['cpu', 'mem']);
        $pct = static fn (?float $v) => $v === null ? '-' : self::trim($v) . ' %';
        $memNote = $series['mem_total'] ? ' (' . Format::bytes((int) $series['mem_used']) . ' of ' . Format::bytes((int) $series['mem_total']) . ')' : '';
        return self::chart($series, $range, [
            'title' => 'CPU and memory',
            'note' => '% used, ' . self::bucketLabel($series['bucket']) . ' averages',
            'unit' => 'pct',
            'fixed_max' => 100.0,
            'empty' => 'No CPU or memory samples in this range yet.',
            'series' => [
                'cpu' => ['CPU', $pct($latest['cpu']) . ' latest · peak ' . $pct($series['peak_cpu'])],
                'mem' => ['Memory', $pct($latest['mem']) . ' latest' . $memNote . ' · peak ' . $pct($series['peak_mem'])],
            ],
            'summary' => 'CPU and memory use, ' . strtolower(NasTraffic::RANGES[$range][2]) . ': latest CPU ' . $pct($latest['cpu']) . ', memory ' . $pct($latest['mem'])
                . '; peak CPU ' . $pct($series['peak_cpu']) . ', memory ' . $pct($series['peak_mem']) . '.',
        ]);
    }

    public static function temperatureChart(array $nas, string $range): array
    {
        $series = NasTraffic::temperatureSeries((int) $nas['id'], $range);
        if (!$series['sensors']) {
            $any = (bool) Db::value('SELECT 1 FROM nas_snmp_temperature_samples WHERE nas_id=? LIMIT 1', [(int) $nas['id']]);
            return ['message' => match (true) {
                $any => 'No temperature samples in this range.',
                (int) $nas['id'] === ServerMonitor::ID => 'This server exposes no temperature sensors (usual for a virtual machine).',
                !self::historyRecorded($nas) => 'Temperature history is recorded once SNMP polling is set up for this NAS.',
                default => 'No temperature history yet. It is recorded by the SNMP poller when the device reports temperature sensors over SNMP.',
            }];
        }
        $deg = static fn (?float $v) => $v === null ? '-' : self::trim($v) . ' °C';
        $config = [];
        foreach ($series['sensors'] as $i => $sensor) {
            $config['s' . $i] = [$sensor, $deg($series['latest'][$sensor]) . ' latest · peak ' . $deg($series['peaks'][$sensor] ?? null)];
        }
        $hottest = $series['peaks'] ? array_keys($series['peaks'], max($series['peaks']))[0] : null;
        return self::chart($series, $range, [
            'title' => 'Temperature',
            'note' => '°C, ' . self::bucketLabel($series['bucket']) . ' averages',
            'unit' => 'celsius',
            'zero' => false,
            'empty' => 'No temperature samples in this range yet.',
            'series' => $config,
            'summary' => 'Temperature, ' . strtolower(NasTraffic::RANGES[$range][2]) . ', ' . count($series['sensors']) . ' sensor' . (count($series['sensors']) === 1 ? '' : 's')
                . ($hottest !== null ? '; hottest peak ' . $hottest . ' at ' . $deg($series['peaks'][$hottest]) : '') . '.',
            'footnote' => $series['hidden'] ? count($series['hidden']) . ' more sensor' . (count($series['hidden']) === 1 ? '' : 's') . ' not drawn (8 colors at most): ' . implode(', ', $series['hidden']) . '.' : null,
        ]);
    }

    public static function trafficChart(array $nas, ?int $ifIndex, string $range, array $interfaces): array
    {
        if ($ifIndex === null) {
            return ['message' => self::historyRecorded($nas)
                ? 'No traffic history yet. It is recorded every minute by the SNMP poller; rates appear after two polls.'
                : 'Traffic history is recorded once SNMP polling is set up for this NAS.'];
        }
        $name = '';
        foreach ($interfaces as $if) {
            if ($if['if_index'] === $ifIndex) {
                $name = (string) $if['if_name'];
            }
        }
        $series = NasTraffic::series((int) $nas['id'], $ifIndex, $range);
        $latest = self::latestValues($series['points'], ['in', 'out']);
        return self::chart($series, $range, [
            'title' => $name,
            'note' => 'bit/s, ' . self::bucketLabel($series['bucket']) . ' averages',
            'unit' => 'bps',
            'area' => 'in',
            'empty' => 'No samples for ' . $name . ' in this range yet.',
            'series' => [
                'in' => ['In', self::bpsOrDash($latest['in']) . ' latest · peak ' . self::bpsOrDash($series['peak_in'])],
                'out' => ['Out', self::bpsOrDash($latest['out']) . ' latest · peak ' . self::bpsOrDash($series['peak_out'])],
            ],
            'summary' => 'Traffic on ' . $name . ', ' . strtolower(NasTraffic::RANGES[$range][2]) . ': latest in ' . self::bpsOrDash($latest['in']) . ', out ' . self::bpsOrDash($latest['out'])
                . '; peak in ' . self::bpsOrDash($series['peak_in']) . ', out ' . self::bpsOrDash($series['peak_out']) . '.',
        ]);
    }

    /**
     * Chart data for resources/js/components/LineChart.tsx: axis bounds and labels are decided here so
     * the server and the legacy charts agree; the browser only draws the points.
     */
    private static function chart(array $series, string $range, array $config): array
    {
        $keys = array_keys($config['series']);
        $values = [];
        foreach ($keys as $key) {
            foreach ($series['points'] as $point) {
                if ($point[$key] !== null) {
                    $values[] = (float) $point[$key];
                }
            }
        }
        if (!$values) {
            return ['message' => $config['empty']];
        }
        if (($config['zero'] ?? true) === false) {
            [$min, $max, $step] = NasTraffic::niceRange(min($values), max($values));
        } else {
            $min = 0.0;
            [$max, $step] = isset($config['fixed_max']) ? [$config['fixed_max'], $config['fixed_max'] / 4] : NasTraffic::niceScale(max($values));
        }
        $label = match ($config['unit']) {
            'pct' => static fn (float $v) => self::trim($v) . '%',
            'celsius' => static fn (float $v) => self::trim($v) . '°',
            default => static fn (float $v) => self::bpsShort($v),
        };
        $span = max(1, $series['end'] - $series['start']);
        $format = $range === '7d' ? 'D H:i' : 'H:i';
        return ['chart' => [
            'title' => $config['title'],
            'note' => $config['note'],
            'unit' => $config['unit'],
            'area' => $config['area'] ?? null,
            'summary' => $config['summary'],
            'footnote' => $config['footnote'] ?? null,
            'start' => (int) $series['start'],
            'end' => (int) $series['end'],
            'bucket' => (int) $series['bucket'],
            'min' => (float) $min,
            'max' => (float) $max,
            'week' => $range === '7d',
            'yLabels' => array_map(static fn (int $i) => $label($min + $step * $i), [4, 3, 2, 1, 0]),
            'xLabels' => array_map(static fn (int $i) => date($format, (int) ($series['start'] + $span * $i / 5)), range(0, 5)),
            'tableTimes' => array_map(static fn ($p) => date($range === '7d' ? 'Y-m-d H:i' : 'H:i', (int) $p['t']), $series['points']),
            'series' => array_map(static fn ($key, $s) => ['key' => (string) $key, 'label' => (string) $s[0], 'detail' => (string) $s[1]], $keys, array_values($config['series'])),
            'points' => array_map(static fn ($p) => array_merge([(int) $p['t']], array_map(static fn ($key) => $p[$key] === null ? null : round((float) $p[$key], 2), $keys)), $series['points']),
        ]];
    }

    /** Newest non-null value per series. */
    private static function latestValues(array $points, array $keys): array
    {
        $latest = array_fill_keys($keys, null);
        foreach ($points as $point) {
            foreach ($keys as $key) {
                if ($point[$key] !== null) {
                    $latest[$key] = $point[$key];
                }
            }
        }
        return $latest;
    }

    /** Compact axis label: 0, 250k, 1.5M, 10G. */
    private static function bpsShort(float $bps): string
    {
        foreach ([[1e12, 'T'], [1e9, 'G'], [1e6, 'M'], [1e3, 'k']] as [$unit, $suffix]) {
            if ($bps >= $unit) {
                return self::trim($bps / $unit) . $suffix;
            }
        }
        return (string) (int) round($bps);
    }

    private static function bpsOrDash(?float $bps): string
    {
        return $bps === null ? '-' : Nas::bps($bps);
    }

    private static function bucketLabel(int $seconds): string
    {
        return $seconds < 3600 ? ($seconds / 60) . '-minute' : self::trim($seconds / 3600) . '-hour';
    }

    public static function pollMessage(array $summary): string
    {
        $parts = [];
        if ($summary['server'] !== null) {
            $parts[] = 'the RADIUS server';
        }
        if ($summary['polled']) {
            $parts[] = $summary['polled'] . ' NAS device' . ($summary['polled'] === 1 ? '' : 's');
        }
        $message = $parts ? 'Polled ' . implode(' and ', $parts) . '.' : 'Nothing to poll: no NAS has SNMP enabled.';
        if ($summary['failed']) {
            $errors = [];
            foreach ($summary['errors'] as $name => $error) {
                $errors[] = $name . ' (' . $error . ')';
            }
            $message .= ' No answer from ' . implode('; ', $errors) . '.';
        }
        return $message;
    }
}
