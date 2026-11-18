<?php

declare(strict_types=1);

namespace App\Support\Olt;

use App\Support\Audit;
use App\Support\Db;
use App\Support\Secrets;
use RuntimeException;
use Throwable;

/**
 * Everything the portal can change on an OLT, and the live reads for one ONU. Each change is a fixed command built here
 * from checked numbers and a cleaned description: no text from the browser is sent to the OLT as a command. Before an
 * ONU is changed, the OLT is asked which serial number is at that position, so a stale list can never hit another ONU.
 */
final class OltManager
{
    /** @param  array<string, mixed>  $olt */
    public static function canRead(array $olt): bool
    {
        return (bool) $olt['enabled'] && (bool) $olt['telnet_enabled'];
    }

    /** @param  array<string, mixed>  $olt */
    public static function canChange(array $olt): bool
    {
        return self::canRead($olt) && !empty($olt['manage_enabled']);
    }

    /** @param  array<string, mixed>  $olt */
    private static function requireChange(array $olt): void
    {
        if (!self::canChange($olt)) {
            throw new RuntimeException('Changes are turned off for this OLT. Turn on "Allow changes from the portal" under Edit first.');
        }
    }

    /** @param  array<string, mixed>  $olt */
    private static function telnet(array $olt, float $budget = 90.0): OltTelnet
    {
        return new OltTelnet((string) $olt['host'], (int) $olt['telnet_port'], Secrets::reveal($olt['telnet_user'] ?? ''), Secrets::reveal($olt['telnet_password'] ?? ''), $budget);
    }

    /**
     * Sends one change and throws the OLT's own words if it refused.
     *
     * @param  array<int, string>  $sent
     */
    private static function send(OltTelnet $t, string $command, array &$sent, bool $confirm = false): string
    {
        $sent[] = $command;
        $output = $t->command($command, $confirm);
        $failure = HuaweiCli::failure($output);
        if ($failure !== null) {
            throw new RuntimeException('The OLT refused "' . $command . '": ' . $failure);
        }
        return $output;
    }

    /**
     * One change at a time per OLT, so two people never get the same free ONU number.
     *
     * @template T
     *
     * @param  array<string, mixed>  $olt
     * @param  callable(OltTelnet): T  $work
     * @return T
     */
    private static function session(array $olt, callable $work): mixed
    {
        $lock = 'radiusone:olt-change:' . (int) $olt['id'] . ':' . substr(hash('sha256', (string) Db::value('SELECT DATABASE()')), 0, 20);
        if ((int) Db::value('SELECT GET_LOCK(?,15)', [$lock]) !== 1) {
            throw new RuntimeException('Someone else is changing this OLT right now. Try again in a moment.');
        }
        $t = self::telnet($olt);
        try {
            $t->open();
            $t->command('scroll');
            return $work($t);
        } finally {
            $t->close();
            Db::value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /**
     * Asks the OLT what is at this ONU's position, and stops unless it is the same serial number.
     *
     * @param  array<string, mixed>  $onu
     * @return array<string, string> the OLT's own details for the ONU
     */
    private static function verify(OltTelnet $t, array $onu): array
    {
        $output = $t->command(sprintf('display ont info %d %d %d %d', $onu['frame'], $onu['slot'], $onu['port'], $onu['ont_id']));
        $failure = HuaweiCli::failure($output);
        if ($failure !== null) {
            throw new RuntimeException('The OLT has no ONU at ' . Olts::position($onu) . ' any more (' . $failure . '). Use Read now, then try again.');
        }
        $fields = HuaweiCli::fields($output);
        if (HuaweiCli::serialFromCli($fields['SN'] ?? '') !== (string) $onu['sn']) {
            throw new RuntimeException('The OLT now has a different ONU at ' . Olts::position($onu) . '. Nothing was changed. Use Read now, then try again.');
        }
        return $fields;
    }

    /**
     * @param  array<string, mixed>  $olt
     * @param  array<string, mixed>  $onu
     * @param  array<int, string>  $commands
     * @param  array<string, mixed>  $more
     */
    private static function done(array $olt, array $onu, string $action, array $commands, array $more = []): void
    {
        Db::exec('UPDATE olts SET sync_requested_at=NOW() WHERE id=?', [$olt['id']]);
        Audit::log($action, 'onu', (string) $onu['sn'], null, ['olt' => (string) $olt['name'], 'position' => Olts::position($onu), 'commands' => $commands] + $more);
    }

    /**
     * @param  array<string, mixed>  $olt
     * @param  array<string, mixed>  $onu
     */
    public static function reboot(array $olt, array $onu): void
    {
        self::requireChange($olt);
        $sent = [];
        self::session($olt, static function (OltTelnet $t) use ($onu, &$sent): void {
            $now = self::verify($t, $onu);
            if (strtolower($now['Run state'] ?? '') !== 'online') {
                throw new RuntimeException('This ONU is offline, so it cannot be restarted from the OLT.');
            }
            $t->command('config');
            $t->command(sprintf('interface gpon %d/%d', $onu['frame'], $onu['slot']));
            self::send($t, sprintf('ont reset %d %d', $onu['port'], $onu['ont_id']), $sent, true);
        });
        self::done($olt, $onu, 'onu_reboot', $sent);
    }

    /**
     * Turns the ONU's service off or back on. Its settings stay on the OLT.
     *
     * @param  array<string, mixed>  $olt
     * @param  array<string, mixed>  $onu
     */
    public static function setActive(array $olt, array $onu, bool $active): void
    {
        self::requireChange($olt);
        $sent = [];
        self::session($olt, static function (OltTelnet $t) use ($onu, $active, &$sent): void {
            self::verify($t, $onu);
            $t->command('config');
            $t->command(sprintf('interface gpon %d/%d', $onu['frame'], $onu['slot']));
            self::send($t, sprintf('ont %s %d %d', $active ? 'activate' : 'deactivate', $onu['port'], $onu['ont_id']), $sent);
            // Believe the OLT, not the absence of an error.
            $flag = strtolower(HuaweiCli::fields($t->command(sprintf('display ont info %d %d', $onu['port'], $onu['ont_id'])))['Control flag'] ?? '');
            if ($flag !== '' && ($flag === 'active') !== $active) {
                throw new RuntimeException('The OLT accepted the command, but still shows this ONU as ' . $flag . '.');
            }
        });
        Db::exec('UPDATE olt_onus SET active=?' . ($active ? '' : ',status="offline"') . ' WHERE id=?', [$active ? 1 : 0, $onu['id']]);
        self::done($olt, $onu, $active ? 'onu_enable' : 'onu_disable', $sent);
    }

    /**
     * @param  array<string, mixed>  $olt
     * @param  array<string, mixed>  $onu
     * @return string the description as saved on the OLT
     */
    public static function describe(array $olt, array $onu, string $typed): string
    {
        self::requireChange($olt);
        $description = HuaweiCli::description($typed);
        if ($description === '') {
            throw new RuntimeException('Enter a description of letters and digits (up to 64 characters).');
        }
        $sent = [];
        self::session($olt, static function (OltTelnet $t) use ($onu, $description, &$sent): void {
            self::verify($t, $onu);
            $t->command('config');
            $t->command(sprintf('interface gpon %d/%d', $onu['frame'], $onu['slot']));
            self::send($t, sprintf('ont modify %d %d desc "%s"', $onu['port'], $onu['ont_id'], $description), $sent);
        });
        Db::exec('UPDATE olt_onus SET description=? WHERE id=?', [$description, $onu['id']]);
        self::done($olt, $onu, 'onu_describe', $sent, ['before' => (string) $onu['description']]);
        return $description;
    }

    /**
     * Removes the ONU and its service ports from the OLT. Its clients are unlinked, so a new ONU can be found for them.
     *
     * @param  array<string, mixed>  $olt
     * @param  array<string, mixed>  $onu
     */
    public static function delete(array $olt, array $onu): void
    {
        self::requireChange($olt);
        $sent = [];
        self::session($olt, static function (OltTelnet $t) use ($onu, &$sent): void {
            self::verify($t, $onu);
            $t->command('config');
            self::remove($t, $onu, $sent);
        });
        Db::exec('DELETE FROM customer_onus WHERE olt_id=? AND sn=?', [$olt['id'], $onu['sn']]);
        Db::exec('DELETE FROM olt_onus WHERE id=?', [$onu['id']]);
        self::done($olt, $onu, 'onu_delete', $sent, ['description' => (string) $onu['description']]);
    }

    /**
     * From config mode: the ONU's service ports first, as the OLT will not delete an ONU that still has one.
     *
     * @param  array<string, mixed>  $onu
     * @param  array<int, string>  $sent
     */
    private static function remove(OltTelnet $t, array $onu, array &$sent): void
    {
        $ports = HuaweiCli::servicePorts($t->command(sprintf('display service-port port %d/%d/%d ont %d', $onu['frame'], $onu['slot'], $onu['port'], $onu['ont_id'])));
        foreach ($ports as $p) {
            // Only what the OLT listed for exactly this ONU.
            if ([$p['frame'], $p['slot'], $p['port'], $p['ont_id']] === [(int) $onu['frame'], (int) $onu['slot'], (int) $onu['port'], (int) $onu['ont_id']]) {
                self::send($t, 'undo service-port ' . $p['index'], $sent);
            }
        }
        $t->command(sprintf('interface gpon %d/%d', $onu['frame'], $onu['slot']));
        self::send($t, sprintf('ont delete %d %d', $onu['port'], $onu['ont_id']), $sent);
        $t->command('quit');
    }

    /**
     * One change on a PON port: autofind on or off, every ONU restarted, or the port itself turned off or on.
     *
     * @param  array<string, mixed>  $olt
     * @return string what happened, for the operator
     */
    public static function port(array $olt, int $frame, int $slot, int $port, string $action): string
    {
        self::requireChange($olt);
        $where = "$frame/$slot/$port";
        [$command, $confirm, $message] = match ($action) {
            'autofind-on' => ["port $port ont-auto-find enable", false, "Autofind is on for $where: new ONUs plugged in there show under New ONUs."],
            'autofind-off' => ["port $port ont-auto-find disable", false, "Autofind is off for $where: new ONUs plugged in there are no longer listed."],
            'restart-onus' => ["ont reset $port all", true, "Every ONU on $where is restarting. They are back in a minute or two."],
            'off' => ["shutdown $port", false, "PON port $where is turned off. Every ONU on it is offline until you turn it on."],
            'on' => ["undo shutdown $port", false, "PON port $where is turned on. Its ONUs come back within a few minutes."],
            default => throw new RuntimeException('Unknown action.'),
        };
        $sent = [];
        $now = self::session($olt, static function (OltTelnet $t) use ($frame, $slot, $port, $command, $confirm, $where, &$sent): array {
            $t->command('config');
            $t->command("interface gpon $frame/$slot");
            // The port must be one the OLT itself lists on that card.
            $before = HuaweiCli::portBlocks($t->command("display port info $port"))[$where] ?? null;
            if ($before === null) {
                throw new RuntimeException("The OLT has no PON port $where.");
            }
            self::send($t, $command, $sent, $confirm);
            $after = HuaweiCli::portBlocks($t->command("display port info $port"));
            return array_key_exists($where, $after) ? $after[$where] : [];
        });
        // Believe the OLT, not the absence of an error.
        $autofind = isset($now['Autofind']) ? strtolower($now['Autofind']) === 'enable' : null;
        $admin = isset($now['Admin State']) ? strtolower($now['Admin State']) === 'on' : null;
        $wrong = match ($action) {
            'autofind-on' => $autofind === false, 'autofind-off' => $autofind === true, 'off' => $admin === true, 'on' => $admin === false, default => false,
        };
        if ($wrong) {
            throw new RuntimeException("The OLT accepted the command, but port $where still shows the old setting.");
        }
        $inventory = OltInventory::saved($olt);
        if (isset($inventory['pon'][$where])) {
            $saved = (array) $inventory['pon'][$where];
            $inventory['pon'][$where] = ['autofind' => $autofind ?? ($saved['autofind'] ?? null), 'admin' => $admin ?? ($saved['admin'] ?? null)] + $saved;
            Db::exec('UPDATE olts SET inventory=? WHERE id=?', [json_encode($inventory, JSON_INVALID_UTF8_SUBSTITUTE), $olt['id']]);
        }
        Db::exec('UPDATE olts SET sync_requested_at=NOW() WHERE id=?', [$olt['id']]);
        Audit::log('port_' . str_replace('-', '_', $action), 'olt', (string) $olt['id'], null, ['port' => $where, 'commands' => $sent]);
        return $message;
    }

    /**
     * Restarts one card. Everything on it is offline until it is back.
     *
     * @param  array<string, mixed>  $olt
     * @param  array<string, mixed>  $board  a row of olt_boards
     */
    public static function restartCard(array $olt, array $board): void
    {
        self::requireChange($olt);
        $where = (int) $board['frame'] . '/' . (int) $board['slot'];
        $sent = [];
        self::session($olt, static function (OltTelnet $t) use ($board, $where, &$sent): void {
            // The card in that slot must still be the one the portal showed.
            $name = HuaweiCli::fields($t->command('display board ' . $where))['Board Name'] ?? '';
            if ($name === '' || strcasecmp(trim($name), trim((string) $board['name'])) !== 0) {
                throw new RuntimeException('The OLT now has ' . ($name === '' ? 'no card' : 'card ' . trim($name)) . ' in slot ' . $where . ', not ' . $board['name'] . '. Nothing was restarted. Use Read now, then try again.');
            }
            $t->command('config');
            self::send($t, 'board reset ' . $where, $sent, true);
        });
        Db::exec('UPDATE olts SET sync_requested_at=NOW() WHERE id=?', [$olt['id']]);
        Audit::log('card_restart', 'olt', (string) $olt['id'], null, ['card' => $board['name'] . ' in ' . $where, 'commands' => $sent]);
    }

    /**
     * What the ONU says right now. Only reads, so it works with changes turned off.
     *
     * @param  array<string, mixed>  $olt
     * @param  array<string, mixed>  $onu
     * @return array<string, mixed>
     */
    public static function live(array $olt, array $onu): array
    {
        if (!self::canRead($olt)) {
            throw new RuntimeException('Live details need telnet turned on for this OLT, under Edit.');
        }
        [$f, $s, $p, $n] = [(int) $onu['frame'], (int) $onu['slot'], (int) $onu['port'], (int) $onu['ont_id']];
        $out = Olts::cli($olt, 60.0)->read([
            "display ont info $f $s $p $n", "display ont wan-info $f/$s $p $n", 'config', "interface gpon $f/$s",
            "display ont optical-info $p $n", "display ont port state $p $n eth-port all", "display ont version $p $n",
        ]);
        $failure = HuaweiCli::failure($out[0]);
        $info = HuaweiCli::fields($out[0]);
        if ($failure !== null || HuaweiCli::serialFromCli($info['SN'] ?? '') !== (string) $onu['sn']) {
            throw new RuntimeException('The OLT no longer has this ONU at ' . Olts::position($onu) . '. Use Read now on the OLT\'s page.');
        }
        $optical = HuaweiCli::fields($out[4]);
        $version = HuaweiCli::fields($out[6]);
        $v = static fn (array $from, string $key) => HuaweiCli::value($from[$key] ?? '');
        return [
            'state' => [
                'run' => strtolower($v($info, 'Run state')), 'active' => strtolower($v($info, 'Control flag')) === 'active',
                'config' => $v($info, 'Config state'), 'match' => $v($info, 'Match state'),
                'online' => $v($info, 'ONT online duration'), 'uptime' => $v($info, 'ONT system up duration'),
                'cpu' => $v($info, 'CPU occupation'), 'memory' => $v($info, 'Memory occupation'), 'temperature' => $v($info, 'Temperature'),
                'downCause' => $v($info, 'Last down cause'), 'ip' => $v($info, 'ONT IP 1 address/mask'),
                'lineProfile' => $v($info, 'Line profile name'), 'serviceProfile' => $v($info, 'Service profile name'),
            ],
            'optical' => [
                'rx' => $v($optical, 'Rx optical power(dBm)'), 'tx' => $v($optical, 'Tx optical power(dBm)'), 'oltRx' => $v($optical, 'OLT Rx ONT optical power(dBm)'),
                'temperature' => $v($optical, 'Temperature(C)'), 'voltage' => $v($optical, 'Voltage(V)'), 'bias' => $v($optical, 'Laser bias current(mA)'),
            ],
            'software' => ['model' => $v($version, 'Equipment-ID'), 'version' => $v($version, 'Main Software Version'), 'mac' => $v($version, 'Ont MAC')],
            'wans' => HuaweiCli::wans($out[1]),
            'lan' => HuaweiCli::lanPorts($out[5]),
        ];
    }

    /**
     * For the page that adds ONUs: what is plugged in but not added, and what the OLT has to choose from.
     *
     * @param  array<string, mixed>  $olt
     * @return array<string, mixed>
     */
    public static function options(array $olt): array
    {
        if (!self::canRead($olt)) {
            throw new RuntimeException('Finding new ONUs needs telnet turned on for this OLT, under Edit.');
        }
        $saved = json_decode((string) ($olt['provision_defaults'] ?? ''), true);
        $commands = [
            'display ont autofind all', 'display ont-lineprofile gpon all', 'display ont-srvprofile gpon all', 'display vlan all',
            'display traffic table ip from-index 0', 'display ont wan-profile all', 'display ont policy-route-profile all',
        ];
        // Only the first time: what the ONUs already on the OLT use.
        if (!is_array($saved)) {
            $commands[] = 'display service-port all';
        }
        $out = Olts::cli($olt, 120.0)->read($commands);
        $options = [
            'found' => HuaweiCli::autofind($out[0]),
            'lineProfiles' => HuaweiCli::profiles($out[1]), 'serviceProfiles' => HuaweiCli::profiles($out[2]),
            'vlans' => HuaweiCli::vlans($out[3]), 'trafficTables' => HuaweiCli::trafficTables($out[4]),
            'wanProfiles' => HuaweiCli::profiles($out[5]), 'routeProfiles' => HuaweiCli::profiles($out[6]),
        ];
        if (!$options['lineProfiles'] || !$options['serviceProfiles'] || !$options['vlans'] || !$options['trafficTables']) {
            throw new RuntimeException('The OLT did not list its profiles, VLANs and traffic tables. Check that the telnet user may read them.');
        }
        if (is_array($saved)) {
            $defaults = $saved;
        } else {
            $most = static function (array $rows, string $by) {
                usort($rows, static fn (array $a, array $b) => $b[$by] <=> $a[$by]);
                return $rows && $rows[0][$by] > 0 ? (string) $rows[0]['id'] : '';
            };
            $common = static function (array $ports, string $key): string {
                $counts = array_count_values(array_map('strval', array_filter(array_column($ports, $key), static fn ($v) => $v !== null)));
                arsort($counts);
                return (string) (array_key_first($counts) ?? '');
            };
            $ports = HuaweiCli::servicePorts($out[7]);
            $defaults = [
                'mode' => 'router', 'line_profile' => $most($options['lineProfiles'], 'bound'), 'service_profile' => $most($options['serviceProfiles'], 'bound'),
                'vlan' => $most($options['vlans'], 'ports'), 'gem' => $common($ports, 'gem') ?: '1',
                // The OLT lists the download table under RX and the upload table under TX.
                'traffic_up' => $common($ports, 'tx'), 'traffic_down' => $common($ports, 'rx'),
                'wan_profile' => $most($options['wanProfiles'], 'bound'), 'route_profile' => $most($options['routeProfiles'], 'bound'),
            ];
        }
        return $options + ['defaults' => array_map('strval', $defaults)];
    }

    /**
     * Adds a plugged-in ONU to the OLT with internet service. If any step after the first fails, the ONU is taken off again.
     *
     * @param  array<string, mixed>  $olt
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> the new ONU, as a row of olt_onus
     */
    public static function authorize(array $olt, array $input): array
    {
        self::requireChange($olt);
        $int = static function (string $key, string $label, int $min, int $max, bool $optional = false) use ($input): ?int {
            $raw = trim((string) ($input[$key] ?? ''));
            if ($raw === '' && $optional) {
                return null;
            }
            if (!preg_match('/^\d{1,6}$/D', $raw) || (int) $raw < $min || (int) $raw > $max) {
                throw new RuntimeException('Choose ' . $label . '.');
            }
            return (int) $raw;
        };
        $sn = HuaweiMa5800::normalizeSerial((string) ($input['sn'] ?? ''));
        $hex = HuaweiCli::serialForCli($sn);
        if ($hex === null) {
            throw new RuntimeException('Choose the ONU to add.');
        }
        $mode = ($input['mode'] ?? '') === 'bridge' ? 'bridge' : 'router';
        $line = $int('line_profile', 'a line profile', 0, 8191);
        $service = $int('service_profile', 'a service profile', 0, 8191);
        $vlan = $int('vlan', 'a VLAN', 1, 4093);
        $gem = $int('gem', 'a GEM port from 0 to 1023', 0, 1023);
        $up = $int('traffic_up', 'the upload traffic table', 0, 65535);
        $down = $int('traffic_down', 'the download traffic table', 0, 65535);
        $wan = $int('wan_profile', 'a WAN profile', 0, 8191, true);
        $route = $int('route_profile', 'a route policy profile', 0, 8191, true);
        $description = HuaweiCli::description((string) ($input['description'] ?? ''));
        if ($description === '') {
            throw new RuntimeException('Enter a description of letters and digits (up to 64 characters).');
        }

        $sent = [];
        $onu = self::session($olt, static function (OltTelnet $t) use ($sn, $hex, $mode, $line, $service, $vlan, $gem, $up, $down, $wan, $route, $description, &$sent): array {
            // Where the ONU is comes from the OLT, not from the form.
            $found = array_values(array_filter(HuaweiCli::autofind($t->command('display ont autofind all')), static fn (array $o) => $o['sn'] === $sn));
            if (count($found) !== 1) {
                throw new RuntimeException($found ? 'The OLT sees ' . $sn . ' on more than one port. Check the fibre first.' : 'The OLT no longer lists ' . $sn . ' as a new ONU. It may have been added or unplugged.');
            }
            $at = $found[0];
            $lan = $mode === 'router' ? HuaweiCli::ethPorts($t->command('display ont-srvprofile gpon profile-id ' . $service)) : 1;
            $t->command('config');
            $t->command(sprintf('interface gpon %d/%d', $at['frame'], $at['slot']));
            $added = self::send($t, sprintf('ont add %d sn-auth "%s" omci ont-lineprofile-id %d ont-srvprofile-id %d desc "%s"', $at['port'], $hex, $line, $service, $description), $sent);
            if (!preg_match_all('/PortID\s*:\s*\d+\s*,\s*ONTID\s*:\s*(\d+)/i', $added, $match)) {
                throw new RuntimeException('The OLT did not say which number it gave the ONU. Check the OLT before trying again: ' . mb_substr(trim($added), -200));
            }
            $onu = ['frame' => $at['frame'], 'slot' => $at['slot'], 'port' => $at['port'], 'ont_id' => (int) end($match[1]), 'sn' => $sn, 'onu_model' => $at['model'], 'description' => $description];
            [$p, $n] = [$at['port'], $onu['ont_id']];
            // The number is only used once the OLT confirms that this serial number is the one at it.
            if (HuaweiCli::serialFromCli(HuaweiCli::fields($t->command("display ont info $p $n"))['SN'] ?? '') !== $sn) {
                throw new RuntimeException('The OLT added the ONU, but does not show ' . $sn . ' at ' . Olts::position($onu) . '. Nothing more was sent: check the OLT by hand.');
            }
            try {
                if ($mode === 'router') {
                    self::send($t, "ont ipconfig $p $n ip-index 1 dhcp vlan $vlan priority 0", $sent);
                    self::send($t, "ont internet-config $p $n ip-index 1", $sent);
                    if ($wan !== null) {
                        self::send($t, "ont wan-config $p $n ip-index 1 profile-id $wan", $sent);
                    }
                    if ($route !== null) {
                        self::send($t, "ont policy-route-config $p $n profile-id $route", $sent);
                    }
                    for ($port = 1; $port <= min(8, (int) $lan); $port++) {
                        self::send($t, "ont port route $p $n eth $port enable", $sent);
                    }
                } else {
                    self::send($t, "ont port native-vlan $p $n eth 1 vlan $vlan priority 0", $sent);
                }
                $t->command('quit');
                self::send($t, sprintf('service-port vlan %d gpon %d/%d/%d ont %d gemport %d multi-service user-vlan %d tag-transform translate inbound traffic-table index %d outbound traffic-table index %d', $vlan, $at['frame'], $at['slot'], $p, $n, $gem, $vlan, $up, $down), $sent);
            } catch (Throwable $e) {
                $why = $e instanceof RuntimeException ? $e->getMessage() : 'an internal error';
                try {
                    // From wherever the failure left the command line.
                    $t->command('quit');
                    $t->command('config');
                    self::remove($t, $onu, $sent);
                } catch (Throwable) {
                    throw new RuntimeException($why . ' The ONU was added as ' . Olts::position($onu) . ' and could NOT be taken off again: remove it on the OLT by hand.');
                }
                throw new RuntimeException($why . ' The ONU was taken off the OLT again, so nothing is left half done.');
            }
            return $onu;
        });

        $defaults = ['mode' => $mode, 'line_profile' => $line, 'service_profile' => $service, 'vlan' => $vlan, 'gem' => $gem, 'traffic_up' => $up, 'traffic_down' => $down, 'wan_profile' => $wan ?? '', 'route_profile' => $route ?? ''];
        Db::exec('UPDATE olts SET provision_defaults=? WHERE id=?', [json_encode($defaults), $olt['id']]);
        // Shown at once; the next read, within a minute, fills in the rest.
        Db::exec('INSERT INTO olt_onus (olt_id,frame,slot,port,ont_id,sn,description,onu_model,status,active,seen_at) VALUES (?,?,?,?,?,?,?,?,"unknown",1,NOW())
            ON DUPLICATE KEY UPDATE sn=VALUES(sn),description=VALUES(description),onu_model=VALUES(onu_model),status="unknown",active=1,seen_at=NOW()', [$olt['id'], $onu['frame'], $onu['slot'], $onu['port'], $onu['ont_id'], $sn, $description, $onu['onu_model']]);
        $row = Db::row('SELECT * FROM olt_onus WHERE olt_id=? AND frame=? AND slot=? AND port=? AND ont_id=?', [$olt['id'], $onu['frame'], $onu['slot'], $onu['port'], $onu['ont_id']]);
        self::done($olt, $row, 'onu_add', $sent, ['mode' => $mode, 'vlan' => $vlan]);
        return $row;
    }
}
