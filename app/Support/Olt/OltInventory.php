<?php

declare(strict_types=1);

namespace App\Support\Olt;

use App\Support\Db;
use App\Support\Secrets;
use RuntimeException;

/**
 * What only the OLT's command line tells: each PON port's optics and settings, each uplink's VLANs, the OLT's VLANs,
 * profiles and time servers, and dated copies of its configuration. Only reads from the OLT.
 */
final class OltInventory
{
    /** Copies kept per OLT. A copy is added only when the configuration changed. */
    public const KEEP_BACKUPS = 30;
    public const BACKUP_EVERY_SECONDS = 86400;

    /**
     * Reads the inventory and saves it on the OLT's row.
     *
     * @param  array<string, mixed>  $olt
     * @return array<string, mixed>
     */
    public static function read(array $olt): array
    {
        $ports = ['pon' => [], 'uplink' => []];
        foreach (Db::select('SELECT name,kind FROM olt_ports WHERE olt_id=? ORDER BY if_index', [$olt['id']]) as $p) {
            if (preg_match('~(\d+)\s*/\s*(\d+)\s*/\s*(\d+)~', (string) $p['name'], $m) && isset($ports[$p['kind']])) {
                $ports[$p['kind']]["$m[1]/$m[2]"][] = (int) $m[3];
            }
        }
        $commands = ['display version', 'display vlan all', 'display ont-lineprofile gpon all', 'display ont-srvprofile gpon all', 'display dba-profile all',
            'display traffic table ip from-index 0', 'display ntp-service sessions', 'display autosave configuration',
            'display current-configuration section vlan-config', 'display interface vlanif'];
        $global = count($commands);
        $want = [];
        foreach ($ports['uplink'] as $card => $list) {
            $want[] = ['board', $card, 0];
            $commands[] = 'display board ' . $card;
            foreach ($list as $port) {
                $want[] = ['vlan', $card, $port];
                $commands[] = "display port vlan $card/$port";
            }
        }
        if ($ports['pon']) {
            $want[] = ['skip', '', 0];
            $commands[] = 'config';
        }
        foreach ($ports['pon'] as $card => $list) {
            array_push($want, ['skip', '', 0], ['state', $card, 0]);
            array_push($commands, 'interface gpon ' . $card, 'display port state all');
            foreach ($list as $port) {
                $want[] = ['info', $card, $port];
                $commands[] = 'display port info ' . $port;
            }
            $want[] = ['skip', '', 0];
            $commands[] = 'quit';
        }
        $out = Olts::cli($olt, 200.0)->read($commands);

        $version = HuaweiCli::fields($out[0]);
        if (!isset($version['VERSION']) && !HuaweiCli::vlans($out[1])) {
            throw new RuntimeException('The OLT did not answer the inventory commands. Check that the telnet user may run "display" commands.');
        }
        $inventory = [
            'version' => ['software' => HuaweiCli::value($version['VERSION'] ?? ''), 'patch' => HuaweiCli::value($version['PATCH'] ?? ''), 'product' => HuaweiCli::value($version['PRODUCT'] ?? '')],
            'vlans' => HuaweiCli::vlans($out[1]),
            'lineProfiles' => HuaweiCli::profiles($out[2]), 'serviceProfiles' => HuaweiCli::profiles($out[3]),
            'dbaProfiles' => HuaweiCli::dbaProfiles($out[4]), 'trafficTables' => HuaweiCli::trafficTables($out[5]),
            'ntp' => HuaweiCli::ntp($out[6]), 'autosave' => HuaweiCli::autosave($out[7]),
            // Each VLAN's name and description, and the VLANs the OLT's own management address is on.
            'vlanInfo' => HuaweiCli::vlanConfig($out[8])['vlans'], 'ownVlans' => HuaweiCli::ownVlans($out[9]),
            'uplinks' => [], 'pon' => [],
        ];
        foreach ($want as $i => [$what, $card, $port]) {
            $text = $out[$global + $i];
            if ($what === 'board') {
                foreach (HuaweiCli::uplinkPorts($text) as $u) {
                    $inventory['uplinks']["$card/{$u['port']}"] = $u + ['vlans' => []];
                }
            } elseif ($what === 'vlan' && isset($inventory['uplinks']["$card/$port"])) {
                $inventory['uplinks']["$card/$port"]['vlans'] = HuaweiCli::portVlans($text)['vlans'];
            } elseif ($what === 'state') {
                foreach (HuaweiCli::portBlocks($text) as $key => $f) {
                    $number = static fn (string $name) => is_numeric($f[$name] ?? null) ? (float) $f[$name] : null;
                    $inventory['pon'][$key] = ($inventory['pon'][$key] ?? []) + [
                        'state' => strtolower($f['Port state'] ?? ''), 'module' => strtolower($f['Optical Module status'] ?? ''), 'laser' => $f['Laser state'] ?? '',
                        'tx' => $number('TX power(dBm)'), 'temperature' => $number('Temperature(C)'), 'bias' => $number('TX Bias current(mA)'), 'voltage' => $number('Supply Voltage(V)'),
                        'wavelength' => $number('Wave length(nm)'), 'rogue' => $f['Illegal rogue ONT'] ?? '',
                    ];
                }
            } elseif ($what === 'info') {
                $f = HuaweiCli::portBlocks($text)["$card/$port"] ?? null;
                if ($f !== null) {
                    $inventory['pon']["$card/$port"] = ($inventory['pon']["$card/$port"] ?? []) + [
                        'autofind' => isset($f['Autofind']) ? strtolower($f['Autofind']) === 'enable' : null,
                        'admin' => isset($f['Admin State']) ? strtolower($f['Admin State']) === 'on' : null,
                        'min_km' => is_numeric($f['Min distance(km)'] ?? null) ? (int) $f['Min distance(km)'] : null,
                        'max_km' => is_numeric($f['Max distance(km)'] ?? null) ? (int) $f['Max distance(km)'] : null,
                    ];
                }
            }
        }
        $previous = self::saved($olt);
        if (isset($previous['pon'])) {
            self::logRogue((int) $olt['id'], self::rogueValues($previous), self::rogueValues($inventory));
        }
        Db::exec('UPDATE olts SET inventory=?,last_inventory_at=NOW(),last_inventory_error=NULL WHERE id=?', [json_encode($inventory, JSON_INVALID_UTF8_SUBSTITUTE), $olt['id']]);
        return $inventory;
    }

    /**
     * PON ports where the OLT reports a rogue ONU ("Illegal rogue ONT" other than "Inexistent"): one ONU sending light
     * out of turn, which knocks every ONU on that port offline.
     *
     * @param  array<string, mixed>  $inventory
     * @return array<string, string> F/S/P => what the OLT reported
     */
    public static function rogueValues(array $inventory): array
    {
        $found = [];
        foreach (($inventory['pon'] ?? []) as $key => $pon) {
            $value = trim((string) ($pon['rogue'] ?? ''));
            if ($value !== '' && $value !== '-' && strtolower($value) !== 'inexistent') {
                $found[(string) $key] = $value;
            }
        }
        return $found;
    }

    /**
     * Rogue ONUs as they appear and clear, in the OLT's changelog. Nothing on the first reading, which has nothing to
     * compare with.
     *
     * @param  array<string, string>  $before
     * @param  array<string, string>  $after
     */
    private static function logRogue(int $oltId, array $before, array $after): void
    {
        foreach (array_diff_key($after, $before) as $port => $value) {
            OltChangelog::add($oltId, 'rogue_found', null, $port, ['state' => $value], 'olt', '');
        }
        foreach (array_diff_key($before, $after) as $port => $value) {
            OltChangelog::add($oltId, 'rogue_gone', null, $port, [], 'olt', '');
        }
    }

    /**
     * @param  array<string, mixed>  $olt
     * @return array<string, mixed>
     */
    public static function saved(array $olt): array
    {
        $inventory = json_decode((string) ($olt['inventory'] ?? ''), true);
        return is_array($inventory) ? $inventory : [];
    }

    /**
     * Reads the OLT's whole configuration and keeps a copy if it differs from the last one.
     *
     * @param  array<string, mixed>  $olt
     * @return bool whether a new copy was kept
     */
    public static function backup(array $olt): bool
    {
        $raw = Olts::cli($olt, 300.0)->read(['display current-configuration'])[0];
        // A real configuration has sections and ends with "return".
        $config = HuaweiCli::configText($raw);
        if (!preg_match('/^\[[^\]\n]+\]\s*$/m', $config) || !preg_match('/^return\s*$/m', $config)) {
            throw new RuntimeException('The OLT did not print its configuration' . (($why = HuaweiCli::failure($raw)) ? ' (' . $why . ')' : '') . '. Check that the telnet user may run "display current-configuration".');
        }
        $hash = hash('sha256', $config);
        $last = Db::row('SELECT id,sha256 FROM olt_config_backups WHERE olt_id=? ORDER BY taken_at DESC,id DESC LIMIT 1', [$olt['id']]);
        $changed = !$last || $last['sha256'] !== $hash;
        if ($changed && $last) {
            // What is different from the copy before, for the changelog. Its secrets are masked there.
            $previous = self::backupText((int) $olt['id'], (int) $last['id']);
            if ($previous !== null) {
                $diff = OltChangelog::configDiff($previous, $config);
                if ($diff['addedCount'] + $diff['removedCount'] > 0) {
                    OltChangelog::add((int) $olt['id'], 'config_changed', null, null, $diff, 'olt', '');
                }
            }
        }
        if ($changed) {
            Db::exec('INSERT INTO olt_config_backups (olt_id,taken_at,checked_at,bytes,sha256,body) VALUES (?,NOW(),NOW(),?,?,?)', [$olt['id'], strlen($config), $hash, Secrets::encrypt((string) gzencode($config, 6))]);
            $old = Db::select('SELECT id FROM olt_config_backups WHERE olt_id=? ORDER BY taken_at DESC,id DESC LIMIT 1000 OFFSET ' . self::KEEP_BACKUPS, [$olt['id']]);
            foreach ($old as $row) {
                Db::exec('DELETE FROM olt_config_backups WHERE id=?', [$row['id']]);
            }
        } else {
            Db::exec('UPDATE olt_config_backups SET checked_at=NOW() WHERE id=?', [$last['id']]);
        }
        Db::exec('UPDATE olts SET last_backup_at=NOW(),last_backup_error=NULL WHERE id=?', [$olt['id']]);
        return $changed;
    }

    /** The configuration text of one copy, or null when it cannot be opened (the server's key changed). */
    public static function backupText(int $oltId, int $backupId): ?string
    {
        $body = Db::value('SELECT body FROM olt_config_backups WHERE id=? AND olt_id=?', [$backupId, $oltId]);
        if ($body === null) {
            return null;
        }
        $plain = @gzdecode(Secrets::reveal((string) $body));
        return $plain === false ? null : $plain;
    }
}
