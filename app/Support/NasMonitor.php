<?php
declare(strict_types=1);
namespace App\Support;
use RuntimeException;

/**
 * Read-only SNMP health polling for NAS devices. OIDs are listed in oidReference() so operators
 * can check them against the router's MIB support and ACLs.
 */
final class NasMonitor
{
    // MIB-2 / IF-MIB / HOST-RESOURCES-MIB (both vendors)
    public const SYS_DESCR = '1.3.6.1.2.1.1.1.0';
    public const SYS_UPTIME = '1.3.6.1.2.1.1.3.0';
    public const SYS_NAME = '1.3.6.1.2.1.1.5.0';
    public const IF_TYPE = '1.3.6.1.2.1.2.2.1.3';
    public const IF_ADMIN = '1.3.6.1.2.1.2.2.1.7';
    public const IF_OPER = '1.3.6.1.2.1.2.2.1.8';
    public const IF_IN_ERRORS = '1.3.6.1.2.1.2.2.1.14';
    public const IF_OUT_ERRORS = '1.3.6.1.2.1.2.2.1.20';
    public const IF_NAME = '1.3.6.1.2.1.31.1.1.1.1';
    public const IF_HC_IN = '1.3.6.1.2.1.31.1.1.1.6';
    public const IF_HC_OUT = '1.3.6.1.2.1.31.1.1.1.10';
    public const IF_HIGH_SPEED = '1.3.6.1.2.1.31.1.1.1.15';
    public const IF_ALIAS = '1.3.6.1.2.1.31.1.1.1.18';
    public const HR_PROCESSOR_LOAD = '1.3.6.1.2.1.25.3.3.1.2';
    public const HR_STORAGE_UNITS = '1.3.6.1.2.1.25.2.3.1.4';
    public const HR_STORAGE_SIZE = '1.3.6.1.2.1.25.2.3.1.5';
    public const HR_STORAGE_USED = '1.3.6.1.2.1.25.2.3.1.6';

    // MIKROTIK-MIB (enterprise 14988)
    public const MT_TEMPERATURE = '1.3.6.1.4.1.14988.1.1.3.10.0';
    public const MT_CPU_TEMPERATURE = '1.3.6.1.4.1.14988.1.1.3.11.0';
    public const MT_VOLTAGE = '1.3.6.1.4.1.14988.1.1.3.8.0';
    public const MT_PSU1_STATE = '1.3.6.1.4.1.14988.1.1.3.15.0';
    public const MT_PSU2_STATE = '1.3.6.1.4.1.14988.1.1.3.16.0';
    public const MT_FAN1_SPEED = '1.3.6.1.4.1.14988.1.1.3.17.0';
    public const MT_FAN2_SPEED = '1.3.6.1.4.1.14988.1.1.3.18.0';
    public const MT_GAUGE_TABLE = '1.3.6.1.4.1.14988.1.1.3.100.1';
    public const MT_VERSION = '1.3.6.1.4.1.14988.1.1.4.4.0';
    public const MT_DHCP_LEASES = '1.3.6.1.4.1.14988.1.1.6.1.0';
    public const MT_SERIAL = '1.3.6.1.4.1.14988.1.1.7.3.0';
    public const MT_FIRMWARE = '1.3.6.1.4.1.14988.1.1.7.4.0';
    public const MT_BOARD = '1.3.6.1.4.1.14988.1.1.7.8.0';

    // CISCO-PROCESS-MIB, CISCO-ENHANCED-MEMPOOL-MIB, CISCO-MEMORY-POOL-MIB, CISCO-ENVMON-MIB, CISCO-PPPOE-MIB
    public const CISCO_CPU_5SEC = '1.3.6.1.4.1.9.9.109.1.1.1.1.6';
    public const CISCO_CPU_1MIN = '1.3.6.1.4.1.9.9.109.1.1.1.1.7';
    public const CISCO_CPU_5MIN = '1.3.6.1.4.1.9.9.109.1.1.1.1.8';
    public const CISCO_MEMPOOL_NAME = '1.3.6.1.4.1.9.9.221.1.1.1.1.3';
    public const CISCO_MEMPOOL_HC_USED = '1.3.6.1.4.1.9.9.221.1.1.1.1.18';
    public const CISCO_MEMPOOL_HC_FREE = '1.3.6.1.4.1.9.9.221.1.1.1.1.20';
    public const CISCO_MEMORY_POOL_USED = '1.3.6.1.4.1.9.9.48.1.1.1.5.1';
    public const CISCO_MEMORY_POOL_FREE = '1.3.6.1.4.1.9.9.48.1.1.1.6.1';
    public const CISCO_TEMP_DESCR = '1.3.6.1.4.1.9.9.13.1.3.1.2';
    public const CISCO_TEMP_VALUE = '1.3.6.1.4.1.9.9.13.1.3.1.3';
    public const CISCO_TEMP_STATE = '1.3.6.1.4.1.9.9.13.1.3.1.6';
    public const CISCO_FAN_DESCR = '1.3.6.1.4.1.9.9.13.1.4.1.2';
    public const CISCO_FAN_STATE = '1.3.6.1.4.1.9.9.13.1.4.1.3';
    public const CISCO_PSU_DESCR = '1.3.6.1.4.1.9.9.13.1.5.1.2';
    public const CISCO_PSU_STATE = '1.3.6.1.4.1.9.9.13.1.5.1.3';
    public const CISCO_PPPOE_SESSIONS = '1.3.6.1.4.1.9.9.194.1.1.1.0';

    /** Dynamic per-subscriber interfaces (ifType ppp) and loopbacks are counted, not listed. */
    private const HIDDEN_IF_TYPES = [23, 24];
    private const MAX_INTERFACES = 48;

    /** @return list<array{0:string,1:string,2:string,3:string}> [group, metric, OID, notes] */
    public static function oidReference(string $vendor): array
    {
        $common = [
            ['System', 'Description / OS version', self::SYS_DESCR, 'sysDescr'],
            ['System', 'Uptime', self::SYS_UPTIME, 'sysUpTime, hundredths of a second'],
            ['System', 'Name', self::SYS_NAME, 'sysName'],
            ['Interfaces', 'Name', self::IF_NAME, 'ifName (walk)'],
            ['Interfaces', 'Description', self::IF_ALIAS, 'ifAlias (walk)'],
            ['Interfaces', 'Type', self::IF_TYPE, 'ifType; 23 = ppp subscriber interface'],
            ['Interfaces', 'Admin / oper status', self::IF_ADMIN . ' / ' . self::IF_OPER, '1 = up, 2 = down'],
            ['Interfaces', 'Traffic in / out', self::IF_HC_IN . ' / ' . self::IF_HC_OUT, 'ifHCIn/OutOctets, 64-bit; rate from two polls'],
            ['Interfaces', 'Speed', self::IF_HIGH_SPEED, 'ifHighSpeed, Mbit/s'],
            ['Interfaces', 'Errors in / out', self::IF_IN_ERRORS . ' / ' . self::IF_OUT_ERRORS, 'ifIn/OutErrors'],
        ];
        $mikrotik = [
            ['CPU', 'Load per core', self::HR_PROCESSOR_LOAD, 'hrProcessorLoad (walk, averaged)'],
            ['Memory', 'RAM', self::HR_STORAGE_SIZE . '.65536 / ' . self::HR_STORAGE_USED . '.65536', 'hrStorage index 65536 x allocation units (' . self::HR_STORAGE_UNITS . '.65536)'],
            ['Memory', 'System disk', self::HR_STORAGE_SIZE . '.131072 / ' . self::HR_STORAGE_USED . '.131072', 'hrStorage index 131072'],
            ['Health', 'Health gauges (RouterOS 7)', self::MT_GAUGE_TABLE, 'mtxrGaugeTable: name .2, value .3, unit .4'],
            ['Health', 'Board temperature', self::MT_TEMPERATURE, 'mtxrHlTemperature, tenths of °C (RouterOS 6)'],
            ['Health', 'CPU temperature', self::MT_CPU_TEMPERATURE, 'mtxrHlProcessorTemperature, tenths of °C'],
            ['Health', 'Voltage', self::MT_VOLTAGE, 'mtxrHlVoltage, tenths of V'],
            ['Health', 'PSU 1 / PSU 2', self::MT_PSU1_STATE . ' / ' . self::MT_PSU2_STATE, 'mtxrHlPowerSupplyState, 1 = ok'],
            ['Health', 'Fan 1 / Fan 2', self::MT_FAN1_SPEED . ' / ' . self::MT_FAN2_SPEED, 'mtxrHlFanSpeed, RPM'],
            ['Subscribers', 'PPP sessions', self::IF_TYPE, 'Count of ifType = 23 (one dynamic interface per PPPoE session)'],
            ['Subscribers', 'DHCP leases', self::MT_DHCP_LEASES, 'mtxrDHCPLeaseCount'],
            ['Platform', 'RouterOS version', self::MT_VERSION, 'mtxrLicVersion'],
            ['Platform', 'Board / serial / firmware', self::MT_BOARD . ' / ' . self::MT_SERIAL . ' / ' . self::MT_FIRMWARE, 'mtxrBoardName, mtxrSerialNumber, mtxrFirmwareVersion'],
        ];
        $cisco = [
            ['CPU', 'Load 5 s / 1 min / 5 min', self::CISCO_CPU_5SEC . ' / ' . self::CISCO_CPU_1MIN . ' / ' . self::CISCO_CPU_5MIN, 'cpmCPUTotal*Rev (walk, first CPU)'],
            ['Memory', 'Processor pool', self::CISCO_MEMPOOL_HC_USED . ' / ' . self::CISCO_MEMPOOL_HC_FREE, 'cempMemPoolHCUsed/HCFree; pool chosen by name ' . self::CISCO_MEMPOOL_NAME],
            ['Memory', 'Processor pool (fallback)', self::CISCO_MEMORY_POOL_USED . ' / ' . self::CISCO_MEMORY_POOL_FREE, 'ciscoMemoryPoolUsed/Free, older images'],
            ['Health', 'Temperatures', self::CISCO_TEMP_DESCR . ' / ' . self::CISCO_TEMP_VALUE . ' / ' . self::CISCO_TEMP_STATE, 'ciscoEnvMonTemperatureStatus: descr, °C, state'],
            ['Health', 'Fans', self::CISCO_FAN_DESCR . ' / ' . self::CISCO_FAN_STATE, 'ciscoEnvMonFanState, 1 = normal'],
            ['Health', 'Power supplies', self::CISCO_PSU_DESCR . ' / ' . self::CISCO_PSU_STATE, 'ciscoEnvMonSupplyState, 1 = normal'],
            ['Subscribers', 'PPPoE sessions', self::CISCO_PPPOE_SESSIONS, 'cPppoeSystemCurrSessions'],
            ['Subscribers', 'PPP (Virtual-Access) interfaces', self::IF_TYPE, 'Count of ifType = 23'],
        ];
        return array_merge($common, match ($vendor) { 'mikrotik' => $mikrotik, 'cisco' => $cisco, default => [] });
    }

    public static function available(): bool
    {
        return !SchemaCompatibility::problems('snmp');
    }

    public static function settings(int $nasId): ?array
    {
        return Db::row('SELECT * FROM nas_snmp WHERE nas_id=?', [$nasId]);
    }

    public static function v3Available(): bool
    {
        return !SchemaCompatibility::problems('snmp_v3');
    }

    /** Enabled and holding the credentials its version needs. */
    public static function configured(?array $settings): bool
    {
        if (!$settings || empty($settings['enabled'])) { return false; }
        return ($settings['version'] ?? '') === '3' ? !empty($settings['v3_user']) : !empty($settings['community']);
    }

    public static function usm(array $settings): array
    {
        return [
            'user' => (string) ($settings['v3_user'] ?? ''),
            'auth' => (string) ($settings['v3_auth_protocol'] ?? 'none'),
            'auth_pass' => Secrets::reveal($settings['v3_auth_password'] ?? ''),
            'priv' => (string) ($settings['v3_priv_protocol'] ?? 'none'),
            'priv_pass' => Secrets::reveal($settings['v3_priv_password'] ?? ''),
            'context' => (string) ($settings['v3_context'] ?? ''),
        ];
    }

    public static function client(array $nas, array $settings, float $budget = 6.0): Snmp
    {
        return new Snmp(
            (string) (($settings['host'] ?? '') ?: $nas['nasname']),
            Secrets::reveal($settings['community'] ?? ''),
            (string) ($settings['version'] ?? '2c'),
            (int) ($settings['port'] ?? 161),
            max(0.3, (int) ($settings['timeout_ms'] ?? 1500) / 1000),
            1,
            $budget,
            ($settings['version'] ?? '') === '3' ? self::usm($settings) : [],
        );
    }

    public static function save(int $nasId, array $input): void
    {
        SchemaCompatibility::require('snmp');
        $host = trim((string) ($input['snmp_host'] ?? ''));
        if ($host !== '' && !filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^(?=.{1,190}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i', $host)) {
            throw new RuntimeException('Management host must be an IP address or hostname.');
        }
        $port = (int) ($input['snmp_port'] ?? 161);
        if ($port < 1 || $port > 65535) { throw new RuntimeException('SNMP port must be between 1 and 65535.'); }
        $version = (string) ($input['snmp_version'] ?? '2c');
        if (!in_array($version, ['1', '2c', '3'], true)) { throw new RuntimeException('Choose SNMP v3, v2c or v1.'); }
        if ($version === '3') { SchemaCompatibility::require('snmp_v3'); }
        $timeout = max(300, min(5000, (int) ($input['snmp_timeout_ms'] ?? 1500)));
        $secret = static function (string $name, string $label) use ($input): string {
            $value = (string) ($input[$name] ?? '');
            if (strlen($value) > Secrets::MAX_LENGTH || preg_match('/[\x00-\x1F]/', $value)) { throw new RuntimeException('Invalid ' . $label . ' (at most ' . Secrets::MAX_LENGTH . ' characters).'); }
            return $value;
        };
        $community = $secret('snmp_community', 'SNMP community');
        $enabled = !empty($input['snmp_enabled']) ? 1 : 0;
        $existing = self::settings($nasId) ?? [];

        $v3 = null;
        if (self::v3Available()) {
            $v3 = [
                'v3_user' => trim((string) ($input['snmp_v3_user'] ?? '')),
                'v3_auth_protocol' => strtolower((string) (($input['snmp_v3_auth_protocol'] ?? '') ?: 'none')),
                'v3_auth_password' => $secret('snmp_v3_auth_password', 'authentication password'),
                'v3_priv_protocol' => strtolower((string) (($input['snmp_v3_priv_protocol'] ?? '') ?: 'none')),
                'v3_priv_password' => $secret('snmp_v3_priv_password', 'privacy password'),
                'v3_context' => trim((string) ($input['snmp_v3_context'] ?? '')),
            ];
            if (strlen($v3['v3_context']) > 64) { throw new RuntimeException('SNMPv3 context is too long.'); }
            if ($version === '3') {
                // Blank passwords keep the saved ones; validate the combination that will be used.
                $effective = $v3;
                foreach (['v3_auth_password', 'v3_priv_password'] as $field) {
                    if ($effective[$field] === '') { $effective[$field] = Secrets::reveal($existing[$field] ?? ''); }
                }
                if ($enabled || $effective['v3_user'] !== '') { Snmp::validateUsm(self::usm($effective)); }
            }
        }
        if ($enabled && $version !== '3' && $community === '' && empty($existing['community'])) { throw new RuntimeException('Enter the SNMP read-only community to enable monitoring.'); }

        $columns = ['enabled' => $enabled, 'host' => $host !== '' ? $host : null, 'port' => $port, 'version' => $version, 'timeout_ms' => $timeout];
        $secrets = ['community' => $community];
        if ($v3 !== null) {
            $columns += ['v3_user' => $v3['v3_user'] !== '' ? $v3['v3_user'] : null, 'v3_auth_protocol' => $v3['v3_auth_protocol'], 'v3_priv_protocol' => $v3['v3_priv_protocol'], 'v3_context' => $v3['v3_context']];
            $secrets += ['v3_auth_password' => $v3['v3_auth_password'], 'v3_priv_password' => $v3['v3_priv_password']];
        }
        $secrets = array_map(fn (string $value) => Secrets::encrypt($value), $secrets); // Blank stays blank: keeps the saved one.
        $names = array_merge(array_keys($columns), array_keys($secrets));
        $placeholders = array_merge(array_fill(0, count($columns), '?'), array_fill(0, count($secrets), 'NULLIF(?, "")'));
        $updates = array_merge(
            array_map(fn ($c) => $c . '=VALUES(' . $c . ')', array_keys($columns)),
            array_map(fn ($c) => $c . '=COALESCE(VALUES(' . $c . '), ' . $c . ')', array_keys($secrets)) // Blank keeps the saved secret.
        );
        Db::exec(
            'INSERT INTO nas_snmp (nas_id, ' . implode(', ', $names) . ', created_at, updated_at) VALUES (?, ' . implode(', ', $placeholders) . ', NOW(), NOW())
             ON DUPLICATE KEY UPDATE ' . implode(', ', $updates) . ', updated_at=NOW()',
            array_merge([$nasId], array_values($columns), array_values($secrets))
        );
        // Turning authentication or privacy off clears the password it no longer needs.
        if ($v3 !== null) {
            Db::exec('UPDATE nas_snmp SET v3_auth_password=IF(v3_auth_protocol="none", NULL, v3_auth_password), v3_priv_password=IF(v3_priv_protocol="none", NULL, v3_priv_password) WHERE nas_id=?', [$nasId]);
        }
    }

    /**
     * Quick reachability check for many NAS devices in parallel (sysUpTime and sysName only).
     * @param list<array> $rows NAS rows with id and nasname
     * @return array<int, array{state:string,label:string,class:string,uptime:?int,name:string,latency_ms:?int,error:?string}>
     */
    public static function probeMany(array $rows): array
    {
        $result = [];
        $targets = [];
        $settings = self::available() ? array_column(Db::select('SELECT * FROM nas_snmp'), null, 'nas_id') : [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $s = $settings[$id] ?? null;
            $result[$id] = ['state' => 'off', 'label' => 'not configured', 'class' => '', 'uptime' => null, 'name' => '', 'latency_ms' => null, 'error' => null];
            if (!self::configured($s)) { continue; }
            $targets[$id] = ['host' => ($s['host'] ?: $row['nasname']), 'port' => (int) $s['port'], 'community' => Secrets::reveal($s['community']), 'version' => (string) $s['version'],
                'usm' => $s['version'] === '3' ? self::usm($s) : []];
        }
        // Longest configured timeout, capped so the health page stays quick.
        $timeout = 0.3;
        foreach (array_keys($targets) as $id) { $timeout = max($timeout, (int) ($settings[$id]['timeout_ms'] ?? 1500) / 1000); }
        foreach ($targets ? Snmp::probe($targets, [self::SYS_UPTIME, self::SYS_NAME], min($timeout, 1.5)) : [] as $id => $probe) {
            $uptime = $probe['values'][self::SYS_UPTIME] ?? null;
            $result[$id] = $probe['ok'] && is_numeric($uptime)
                ? ['state' => 'up', 'label' => 'responding · ' . $probe['latency_ms'] . ' ms', 'class' => 'success', 'uptime' => (int) floor($uptime / 100),
                    'name' => self::text($probe['values'][self::SYS_NAME] ?? null), 'latency_ms' => $probe['latency_ms'], 'error' => null]
                : ['state' => 'down', 'label' => 'no response', 'class' => 'danger', 'uptime' => null, 'name' => '', 'latency_ms' => null, 'error' => $probe['error']];
        }
        return $result;
    }

    /**
     * One poll. $previous is the last snapshot's counters so interface rates can be derived.
     * @return array<string, mixed>
     */
    public static function poll(array $nas, array $settings, ?array $previous = null, ?Snmp $client = null): array
    {
        $vendor = strtolower((string) (($nas['vendor'] ?? '') ?: ($nas['type'] ?? '')));
        $snapshot = ['ok' => false, 'error' => null, 'vendor' => $vendor, 'polled_at' => microtime(true), 'latency_ms' => null,
            'system' => [], 'cpu' => null, 'cpu_detail' => [], 'memory' => null, 'storage' => null, 'sensors' => [], 'subscribers' => [],
            'interfaces' => [], 'hidden_interfaces' => 0, 'counters' => [], 'warnings' => []];
        try {
            $client ??= self::client($nas, $settings);
            $started = microtime(true);
            $system = $client->get([self::SYS_DESCR, self::SYS_UPTIME, self::SYS_NAME]);
            $snapshot['latency_ms'] = (int) round((microtime(true) - $started) * 1000);
            if ($system[self::SYS_UPTIME] === null && $system[self::SYS_DESCR] === null) {
                throw new RuntimeException('Device answered but returned no system MIB; check the SNMP view.');
            }
            $snapshot['ok'] = true;
            $snapshot['system'] = [
                'name' => self::text($system[self::SYS_NAME]),
                'description' => self::text($system[self::SYS_DESCR]),
                'uptime' => is_numeric($system[self::SYS_UPTIME]) ? (int) floor($system[self::SYS_UPTIME] / 100) : null,
            ];
        } catch (RuntimeException $e) {
            $snapshot['error'] = $e->getMessage();
            return $snapshot;
        }

        // Each section is independent: one unsupported MIB must not hide the rest.
        $sections = match ($vendor) {
            'mikrotik' => ['mikrotikHealth', 'mikrotikResources'],
            'cisco' => ['ciscoHealth', 'ciscoResources'],
            default => [],
        };
        $sections[] = 'interfaces';
        foreach ($sections as $section) {
            try {
                self::$section($client, $snapshot, $previous);
            } catch (RuntimeException $e) {
                $snapshot['warnings'][] = $section . ': ' . $e->getMessage();
            }
        }
        return $snapshot;
    }

    private static function mikrotikResources(Snmp $client, array &$snapshot): void
    {
        $loads = array_filter($client->walk(self::HR_PROCESSOR_LOAD, 256), 'is_numeric');
        if ($loads) {
            $snapshot['cpu'] = round(array_sum($loads) / count($loads), 1);
            $snapshot['cpu_detail'][] = [count($loads) . ' core' . (count($loads) === 1 ? '' : 's') . ', busiest', max($loads) . ' %'];
        }
        $values = $client->get([
            self::HR_STORAGE_UNITS . '.65536', self::HR_STORAGE_SIZE . '.65536', self::HR_STORAGE_USED . '.65536',
            self::HR_STORAGE_UNITS . '.131072', self::HR_STORAGE_SIZE . '.131072', self::HR_STORAGE_USED . '.131072',
            self::MT_VERSION, self::MT_BOARD, self::MT_SERIAL, self::MT_FIRMWARE, self::MT_DHCP_LEASES,
        ]);
        $snapshot['memory'] = self::storage($values, '65536');
        $snapshot['storage'] = self::storage($values, '131072');
        foreach ([['RouterOS', self::MT_VERSION], ['Board', self::MT_BOARD], ['Serial', self::MT_SERIAL], ['Firmware', self::MT_FIRMWARE]] as [$label, $oid]) {
            if (($text = self::text($values[$oid])) !== '') { $snapshot['system']['platform'][$label] = $text; }
        }
        if (is_numeric($values[self::MT_DHCP_LEASES])) {
            $snapshot['subscribers'][] = ['DHCP leases', (int) $values[self::MT_DHCP_LEASES], self::MT_DHCP_LEASES];
        }
    }

    private static function mikrotikHealth(Snmp $client, array &$snapshot): void
    {
        // RouterOS 7 publishes health as a gauge table; RouterOS 6 uses fixed scalars.
        $gauges = $client->walk(self::MT_GAUGE_TABLE, 200);
        $rows = [];
        foreach ($gauges as $oid => $value) {
            $suffix = substr($oid, strlen(self::MT_GAUGE_TABLE) + 1);
            [$column, $index] = array_pad(explode('.', $suffix, 2), 2, '');
            $rows[$index][(int) $column] = $value;
        }
        foreach ($rows as $row) {
            if (!isset($row[2], $row[3]) || !is_numeric($row[3])) { continue; }
            $name = self::text($row[2]);
            [$value, $unit] = match ((int) ($row[4] ?? 0)) {
                1 => [(float) $row[3], '°C'],
                2 => [(float) $row[3], 'RPM'],
                3 => [$row[3] / 10, 'V'],
                4 => [$row[3] / 10, 'A'],
                5 => [$row[3] / 10, 'W'],
                6 => [(int) $row[3], 'status'],
                default => [(float) $row[3], ''],
            };
            $label = preg_replace_callback('/\b(cpu|psu|sfp|poe|fan)(\d*)\b/i', fn ($m) => strtoupper($m[1]) . ($m[2] !== '' ? ' ' . $m[2] : ''), ucfirst(str_replace('-', ' ', $name)));
            if ($unit === 'status') {
                $snapshot['sensors'][] = self::sensor($label, (int) $value === 0 ? 'ok' : 'failed', '', (int) $value === 0 ? 'ok' : 'critical');
                continue;
            }
            $snapshot['sensors'][] = self::sensor($label, $value, $unit, self::thresholdState($value, $unit));
        }
        if ($snapshot['sensors']) { return; }
        $values = $client->get([self::MT_TEMPERATURE, self::MT_CPU_TEMPERATURE, self::MT_VOLTAGE, self::MT_PSU1_STATE, self::MT_PSU2_STATE, self::MT_FAN1_SPEED, self::MT_FAN2_SPEED]);
        foreach ([['Board temperature', self::MT_TEMPERATURE, '°C'], ['CPU temperature', self::MT_CPU_TEMPERATURE, '°C'], ['Voltage', self::MT_VOLTAGE, 'V']] as [$label, $oid, $unit]) {
            if (is_numeric($values[$oid])) { $snapshot['sensors'][] = self::sensor($label, $values[$oid] / 10, $unit, self::thresholdState($values[$oid] / 10, $unit)); }
        }
        foreach ([['Fan 1', self::MT_FAN1_SPEED], ['Fan 2', self::MT_FAN2_SPEED]] as [$label, $oid]) {
            if (is_numeric($values[$oid])) { $snapshot['sensors'][] = self::sensor($label, (int) $values[$oid], 'RPM', (int) $values[$oid] > 0 ? 'ok' : 'warning'); }
        }
        foreach ([['PSU 1', self::MT_PSU1_STATE], ['PSU 2', self::MT_PSU2_STATE]] as [$label, $oid]) {
            if (is_numeric($values[$oid])) { $snapshot['sensors'][] = self::sensor($label, (int) $values[$oid] === 1 ? 'ok' : 'failed', '', (int) $values[$oid] === 1 ? 'ok' : 'critical'); }
        }
    }

    private static function ciscoResources(Snmp $client, array &$snapshot): void
    {
        $cpu = [];
        foreach ([['5 seconds', self::CISCO_CPU_5SEC], ['1 minute', self::CISCO_CPU_1MIN], ['5 minutes', self::CISCO_CPU_5MIN]] as [$label, $oid]) {
            $first = array_values(array_filter($client->walk($oid, 16), 'is_numeric'))[0] ?? null;
            if ($first !== null) { $cpu[$label] = (float) $first; $snapshot['cpu_detail'][] = [$label, $first . ' %']; }
        }
        $snapshot['cpu'] = $cpu['1 minute'] ?? $cpu['5 seconds'] ?? $cpu['5 minutes'] ?? null;

        $names = $client->walk(self::CISCO_MEMPOOL_NAME, 64);
        foreach ($names as $oid => $name) {
            if (stripos(self::text($name), 'processor') === false) { continue; }
            $index = substr($oid, strlen(self::CISCO_MEMPOOL_NAME) + 1);
            $values = $client->get([self::CISCO_MEMPOOL_HC_USED . '.' . $index, self::CISCO_MEMPOOL_HC_FREE . '.' . $index]);
            $used = $values[self::CISCO_MEMPOOL_HC_USED . '.' . $index];
            $free = $values[self::CISCO_MEMPOOL_HC_FREE . '.' . $index];
            if (is_numeric($used) && is_numeric($free)) { $snapshot['memory'] = ['used' => (float) $used, 'total' => (float) $used + (float) $free]; }
            break;
        }
        if ($snapshot['memory'] === null) {
            $values = $client->get([self::CISCO_MEMORY_POOL_USED, self::CISCO_MEMORY_POOL_FREE]);
            if (is_numeric($values[self::CISCO_MEMORY_POOL_USED]) && is_numeric($values[self::CISCO_MEMORY_POOL_FREE])) {
                $snapshot['memory'] = ['used' => (float) $values[self::CISCO_MEMORY_POOL_USED], 'total' => (float) $values[self::CISCO_MEMORY_POOL_USED] + (float) $values[self::CISCO_MEMORY_POOL_FREE]];
            }
        }
        $sessions = $client->get([self::CISCO_PPPOE_SESSIONS])[self::CISCO_PPPOE_SESSIONS];
        if (is_numeric($sessions)) { $snapshot['subscribers'][] = ['PPPoE sessions', (int) $sessions, self::CISCO_PPPOE_SESSIONS]; }
    }

    private static function ciscoHealth(Snmp $client, array &$snapshot): void
    {
        $state = fn ($value) => match ((int) $value) { 1 => 'ok', 2 => 'warning', 3, 4, 6 => 'critical', default => 'unknown' };
        $temps = self::table($client, [self::CISCO_TEMP_DESCR, self::CISCO_TEMP_VALUE, self::CISCO_TEMP_STATE]);
        foreach ($temps as $row) {
            if ((int) ($row[2] ?? 0) === 5 || !is_numeric($row[1] ?? null)) { continue; } // notPresent
            $snapshot['sensors'][] = self::sensor(self::text($row[0] ?? 'Temperature'), (float) $row[1], '°C', $state($row[2] ?? 0));
        }
        foreach ([['Fan', self::CISCO_FAN_DESCR, self::CISCO_FAN_STATE], ['Power supply', self::CISCO_PSU_DESCR, self::CISCO_PSU_STATE]] as [$fallback, $descr, $status]) {
            foreach (self::table($client, [$descr, $status]) as $row) {
                if ((int) ($row[1] ?? 0) === 5) { continue; }
                $label = $state($row[1] ?? 0);
                $snapshot['sensors'][] = self::sensor(self::text($row[0] ?? $fallback) ?: $fallback, $label === 'ok' ? 'normal' : $label, '', $label);
            }
        }
    }

    private static function interfaces(Snmp $client, array &$snapshot, ?array $previous): void
    {
        $types = $client->walk(self::IF_TYPE, 20000);
        $visible = [];
        $ppp = 0;
        foreach ($types as $oid => $type) {
            $index = substr($oid, strlen(self::IF_TYPE) + 1);
            if ((int) $type === 23) { $ppp++; }
            if (in_array((int) $type, self::HIDDEN_IF_TYPES, true)) { continue; }
            $visible[] = $index;
        }
        $snapshot['subscribers'][] = ['PPP interfaces', $ppp, self::IF_TYPE . ' = 23'];
        $snapshot['hidden_interfaces'] = max(0, count($visible) - self::MAX_INTERFACES);
        $visible = array_slice($visible, 0, self::MAX_INTERFACES);
        if (!$visible) { return; }
        $columns = ['name' => self::IF_NAME, 'alias' => self::IF_ALIAS, 'admin' => self::IF_ADMIN, 'oper' => self::IF_OPER, 'speed' => self::IF_HIGH_SPEED,
            'in' => self::IF_HC_IN, 'out' => self::IF_HC_OUT, 'in_errors' => self::IF_IN_ERRORS, 'out_errors' => self::IF_OUT_ERRORS];
        $oids = [];
        foreach ($visible as $index) { foreach ($columns as $base) { $oids[] = $base . '.' . $index; } }
        $values = $client->get($oids);
        $now = microtime(true);
        $prevAt = (float) ($previous['polled_at'] ?? 0);
        $elapsed = $prevAt > 0 ? $now - $prevAt : 0;
        foreach ($visible as $index) {
            $row = ['index' => (int) $index];
            foreach ($columns as $key => $base) { $row[$key] = $values[$base . '.' . $index] ?? null; }
            $row['name'] = self::text($row['name']) ?: 'if' . $index;
            $row['alias'] = self::text($row['alias']);
            $row['in_bps'] = $row['out_bps'] = null;
            $old = $previous['counters'][$index] ?? null;
            if ($old && $elapsed >= 1) {
                foreach (['in', 'out'] as $direction) {
                    $delta = is_numeric($row[$direction]) && is_numeric($old[$direction] ?? null) ? (float) $row[$direction] - (float) $old[$direction] : -1;
                    $row[$direction . '_bps'] = $delta >= 0 ? $delta * 8 / $elapsed : null; // Counter reset or wrap: skip one sample.
                }
            }
            $snapshot['counters'][$index] = ['in' => $row['in'], 'out' => $row['out']];
            $snapshot['interfaces'][] = $row;
        }
        $snapshot['polled_at'] = $now;
    }

    /** Walks parallel columns and joins them by row index. */
    private static function table(Snmp $client, array $columns): array
    {
        $rows = [];
        foreach ($columns as $position => $base) {
            foreach ($client->walk($base, 128) as $oid => $value) {
                $rows[substr($oid, strlen($base) + 1)][$position] = $value;
            }
        }
        return $rows;
    }

    private static function storage(array $values, string $index): ?array
    {
        $units = $values[self::HR_STORAGE_UNITS . '.' . $index] ?? null;
        $size = $values[self::HR_STORAGE_SIZE . '.' . $index] ?? null;
        $used = $values[self::HR_STORAGE_USED . '.' . $index] ?? null;
        if (!is_numeric($units) || !is_numeric($size) || !is_numeric($used) || (float) $size <= 0) { return null; }
        return ['used' => (float) $used * (float) $units, 'total' => (float) $size * (float) $units];
    }

    private static function sensor(string $label, mixed $value, string $unit, string $state): array
    {
        return ['label' => $label, 'value' => $value, 'unit' => $unit, 'state' => $state];
    }

    private static function thresholdState(float $value, string $unit): string
    {
        return match ($unit) {
            '°C' => $value >= 80 ? 'critical' : ($value >= 65 ? 'warning' : 'ok'),
            'RPM' => $value > 0 ? 'ok' : 'warning',
            default => 'ok',
        };
    }

    /** Device strings may be binary (MACs, serials); show those as hex. */
    private static function text(mixed $value): string
    {
        if ($value === null) { return ''; }
        $value = (string) $value;
        if ($value !== '' && (!mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0E-\x1F]/', $value))) {
            return implode(':', str_split(bin2hex($value), 2));
        }
        return trim($value);
    }
}
