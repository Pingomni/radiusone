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
     * @param  ?string  $shown  the command as it may be kept and shown, when it carries a password
     */
    private static function send(OltTelnet $t, string $command, array &$sent, bool $confirm = false, ?string $shown = null): string
    {
        $shown ??= $command;
        $sent[] = $shown;
        $output = $t->command($command, $confirm);
        $failure = HuaweiCli::failure($output);
        if ($failure !== null) {
            throw new RuntimeException('The OLT refused "' . $shown . '": ' . $failure);
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
    private static function session(array $olt, callable $work, float $budget = 90.0): mixed
    {
        $lock = 'radiusone:olt-change:' . (int) $olt['id'] . ':' . substr(hash('sha256', (string) Db::value('SELECT DATABASE()')), 0, 20);
        if ((int) Db::value('SELECT GET_LOCK(?,15)', [$lock]) !== 1) {
            throw new RuntimeException('Someone else is changing this OLT right now. Try again in a moment.');
        }
        $t = self::telnet($olt, $budget);
        try {
            $t->open();
            $t->command('scroll');
            // For this login only: the OLT stops asking "press Enter for more options" after every command.
            $t->command('undo smart');
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
        OltChangelog::add((int) $olt['id'], $action, (string) $onu['sn'], Olts::position($onu), ['commands' => $commands, 'description' => (string) ($onu['description'] ?? '')] + $more);
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
        self::done($olt, $onu, 'onu_describe', $sent, ['before' => (string) $onu['description'], 'after' => $description]);
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
        OltChangelog::add((int) $olt['id'], 'port_' . str_replace('-', '_', $action), null, $where, ['commands' => $sent]);
        return $message;
    }

    /**
     * Saves part of the inventory at once after a change, so the page shows it before the next read.
     *
     * @param  array<string, mixed>  $olt
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     * @param  array<int, string>  $sent
     * @param  array<string, mixed>  $more
     */
    private static function settled(array $olt, callable $change, string $action, array $sent, array $more): void
    {
        Db::exec('UPDATE olts SET inventory=?,sync_requested_at=NOW() WHERE id=?', [json_encode($change(OltInventory::saved($olt)), JSON_INVALID_UTF8_SUBSTITUTE), $olt['id']]);
        Audit::log($action, 'olt', (string) $olt['id'], null, $more + ['commands' => $sent]);
        OltChangelog::add((int) $olt['id'], $action, null, isset($more['port']) && is_string($more['port']) ? $more['port'] : null, $more + ['commands' => $sent]);
    }

    /**
     * The VLANs the OLT has an address of its own on. Taking one away could cut the portal off from the OLT.
     *
     * @return list<int>
     */
    private static function ownVlans(OltTelnet $t): array
    {
        preg_match_all('/^\s*Vlanif(\d+)\b/mi', $t->command('display interface vlanif'), $m);
        return array_map('intval', $m[1]);
    }

    /**
     * Adds or removes one of the OLT's time servers.
     *
     * @param  array<string, mixed>  $olt
     */
    public static function ntp(array $olt, bool $add, string $server): string
    {
        self::requireChange($olt);
        if (!filter_var($server, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new RuntimeException('Enter the time server\'s IPv4 address, for example 162.159.200.123.');
        }
        $sent = [];
        $servers = self::session($olt, static function (OltTelnet $t) use ($add, $server, &$sent): array {
            $t->command('config');
            self::send($t, ($add ? '' : 'undo ') . 'ntp-service unicast-server ' . $server, $sent);
            $now = HuaweiCli::ntp($t->command('display ntp-service sessions'));
            if (in_array($server, array_column($now, 'server'), true) !== $add) {
                throw new RuntimeException('The OLT accepted the command, but ' . ($add ? 'does not list' : 'still lists') . ' ' . $server . ' as a time server.');
            }
            return $now;
        });
        self::settled($olt, static fn (array $i) => ['ntp' => $servers] + $i, $add ? 'ntp_add' : 'ntp_remove', $sent, ['server' => $server]);
        return $add ? 'Time server ' . $server . ' was added. It takes a few minutes before the OLT is in sync with it.' : 'Time server ' . $server . ' was removed.';
    }

    /**
     * Creates or deletes a VLAN on the OLT. A VLAN in use is never deleted.
     *
     * @param  array<string, mixed>  $olt
     */
    public static function vlan(array $olt, bool $add, int $vlan): string
    {
        self::requireChange($olt);
        if ($vlan < 2 || $vlan > 4093) {
            throw new RuntimeException('Enter a VLAN from 2 to 4093.');
        }
        $sent = [];
        $vlans = self::session($olt, static function (OltTelnet $t) use ($add, $vlan, &$sent): array {
            $before = array_column(HuaweiCli::vlans($t->command('display vlan all')), null, 'id');
            if ($add && isset($before[$vlan])) {
                throw new RuntimeException('The OLT already has VLAN ' . $vlan . '.');
            }
            if (!$add) {
                if (!isset($before[$vlan])) {
                    throw new RuntimeException('The OLT has no VLAN ' . $vlan . '.');
                }
                if ($before[$vlan]['ports'] > 0) {
                    throw new RuntimeException('VLAN ' . $vlan . ' is used by ' . $before[$vlan]['ports'] . ' ONUs, so it was not deleted. Move or remove those ONUs first.');
                }
                if (in_array($vlan, self::ownVlans($t), true)) {
                    throw new RuntimeException('The OLT has its own address on VLAN ' . $vlan . ', so deleting it could cut the OLT off. Do this on the OLT itself.');
                }
                if ($before[$vlan]['standard'] > 0) {
                    throw new RuntimeException('VLAN ' . $vlan . ' is still on ' . $before[$vlan]['standard'] . ' uplink port(s). Take it off them first.');
                }
            }
            $t->command('config');
            self::send($t, $add ? "vlan $vlan smart" : "undo vlan $vlan", $sent);
            $now = HuaweiCli::vlans($t->command('display vlan all'));
            if (in_array($vlan, array_column($now, 'id'), true) !== $add) {
                throw new RuntimeException('The OLT accepted the command, but ' . ($add ? 'does not list' : 'still lists') . ' VLAN ' . $vlan . '.');
            }
            return $now;
        });
        self::settled($olt, static function (array $i) use ($vlans, $vlan): array {
            unset($i['vlanInfo'][$vlan]);
            return ['vlans' => $vlans] + $i;
        }, $add ? 'vlan_add' : 'vlan_remove', $sent, ['vlan' => $vlan]);
        return $add ? 'VLAN ' . $vlan . ' was created. Add it to an uplink port before using it for ONUs.' : 'VLAN ' . $vlan . ' was deleted.';
    }

    /**
     * What the OLT has now for its VLANs, read in the same login after a change: the list, each one's name and
     * description, and the VLANs on each uplink port.
     *
     * @return array{vlans:list<array{id:int,ports:int,type:string,standard:int}>,info:array<int, array{type:string,name:string,desc:string}>,ports:array<string, list<int>>}
     */
    private static function vlanState(OltTelnet $t): array
    {
        $config = HuaweiCli::vlanConfig($t->command('display current-configuration section vlan-config'));
        return ['vlans' => HuaweiCli::vlans($t->command('display vlan all')), 'info' => $config['vlans'], 'ports' => $config['ports']];
    }

    /**
     * The page's copy of the OLT's VLANs, brought up to date from vlanState().
     *
     * @param  array<string, mixed>  $inventory
     * @param  array{vlans:list<array<string, mixed>>,info:array<int, array<string, string>>,ports:array<string, list<int>>}  $state
     * @return array<string, mixed>
     */
    private static function withVlans(array $inventory, array $state): array
    {
        $inventory['vlans'] = $state['vlans'];
        $inventory['vlanInfo'] = $state['info'];
        foreach ((array) ($inventory['uplinks'] ?? []) as $key => $uplink) {
            // The configuration does not list a port's native VLAN, which "display port vlan" does.
            $native = isset($uplink['native']) ? [(int) $uplink['native']] : [];
            $list = array_values(array_unique([...$native, ...($state['ports'][$key] ?? [])]));
            sort($list);
            $inventory['uplinks'][$key]['vlans'] = $list;
        }
        return $inventory;
    }

    /**
     * Creates a VLAN, or a range of them, names and describes it, and puts it on the uplink ports chosen, in one go.
     * If a step fails, what was done is taken off again.
     *
     * @param  array<string, mixed>  $olt
     * @param  list<string>  $uplinks  "frame/slot/port" of the OLT's uplink ports
     */
    public static function vlanCreate(array $olt, int $from, int $to, string $type, string $name, string $desc, array $uplinks): string
    {
        self::requireChange($olt);
        $to = $to === 0 ? $from : $to;
        if ($from < 2 || $from > 4093 || $to < $from || $to > 4093) {
            throw new RuntimeException('Enter a VLAN from 2 to 4093' . ($to !== $from ? ', and the last one of the range no lower than the first' : '') . '.');
        }
        if ($to - $from >= 64) {
            throw new RuntimeException('Create up to 64 VLANs at once.');
        }
        if (!in_array($type, ['smart', 'standard'], true)) {
            throw new RuntimeException('Choose the VLAN type.');
        }
        $cleanName = HuaweiCli::vlanName($name);
        if (trim($name) !== '' && $cleanName === '') {
            throw new RuntimeException('A VLAN name is up to 15 letters, digits, "_", "-" or ".", without spaces.');
        }
        $cleanDesc = HuaweiCli::vlanDescription($desc);
        if (trim($desc) !== '' && $cleanDesc === '') {
            throw new RuntimeException('Enter a description of letters and digits (up to 32 characters).');
        }
        if ($to !== $from && ($cleanName !== '' || $cleanDesc !== '')) {
            throw new RuntimeException('A name and a description are for one VLAN. Create the range without them, then edit each VLAN.');
        }
        $list = $to === $from ? (string) $from : "$from to $to";
        $sent = [];
        $state = self::session($olt, static function (OltTelnet $t) use ($from, $to, $list, $type, $cleanName, $cleanDesc, $uplinks, &$sent): array {
            $have = array_column(HuaweiCli::vlans($t->command('display vlan all')), 'id');
            $taken = array_values(array_intersect($have, range($from, $to)));
            if ($taken) {
                throw new RuntimeException('The OLT already has VLAN ' . implode(', ', array_slice($taken, 0, 10)) . (count($taken) > 10 ? ' and more' : '') . '. Nothing was created.');
            }
            $t->command('config');
            self::send($t, "vlan $list $type", $sent);
            $done = [];
            try {
                if ($cleanName !== '') {
                    self::send($t, "vlan name $from $cleanName", $sent);
                }
                if ($cleanDesc !== '') {
                    self::send($t, "vlan desc $from description " . (str_contains($cleanDesc, ' ') ? '"' . $cleanDesc . '"' : $cleanDesc), $sent);
                }
                foreach ($uplinks as $key) {
                    [$f, $s, $p] = explode('/', $key);
                    self::send($t, "port vlan $list $f/$s $p", $sent);
                    $done[] = "$f/$s $p";
                }
            } catch (Throwable $e) {
                $why = $e instanceof RuntimeException ? $e->getMessage() : 'an internal error';
                try {
                    foreach ($done as $port) {
                        self::send($t, "undo port vlan $list $port", $sent);
                    }
                    self::send($t, "undo vlan $list", $sent);
                } catch (Throwable) {
                    throw new RuntimeException($why . ' VLAN ' . $list . ' was created and could NOT be taken off again: check it on the OLT.');
                }
                throw new RuntimeException($why . ' VLAN ' . $list . ' was taken off the OLT again, so nothing is left half done.');
            }
            $state = self::vlanState($t);
            $missing = array_diff(range($from, $to), array_column($state['vlans'], 'id'));
            if ($missing) {
                throw new RuntimeException('The OLT accepted the commands, but does not list VLAN ' . implode(', ', array_slice($missing, 0, 10)) . '.');
            }
            return $state;
        });
        self::settled($olt, static fn (array $i) => self::withVlans($i, $state), 'vlan_add', $sent, ['vlan' => $list, 'uplinks' => $uplinks]);
        $what = $to === $from ? "VLAN $from" : "VLANs $from to $to";
        return $what . ' ' . ($to === $from ? 'was' : 'were') . ' created' . ($uplinks ? ' and ' . ($to === $from ? 'is' : 'are') . ' carried on ' . implode(', ', $uplinks) . '.' : '. Add ' . ($to === $from ? 'it' : 'them') . ' to an uplink port before using ' . ($to === $from ? 'it' : 'them') . ' for ONUs.');
    }

    /**
     * Changes a VLAN's name and description, and the uplink ports that carry it. Only what differs is sent.
     *
     * @param  array<string, mixed>  $olt
     * @param  list<string>  $uplinks  the uplink ports that should carry it, as "frame/slot/port"
     * @param  bool  $mayRemove  whether this operator may take it off an uplink port
     */
    public static function vlanUpdate(array $olt, int $vlan, string $name, string $desc, array $uplinks, bool $mayRemove): string
    {
        self::requireChange($olt);
        if ($vlan < 1 || $vlan > 4093) {
            throw new RuntimeException('Choose a VLAN.');
        }
        $cleanName = HuaweiCli::vlanName($name);
        if (trim($name) !== '' && $cleanName === '') {
            throw new RuntimeException('A VLAN name is up to 15 letters, digits, "_", "-" or ".", without spaces.');
        }
        $cleanDesc = HuaweiCli::vlanDescription($desc);
        if (trim($desc) !== '' && $cleanDesc === '') {
            throw new RuntimeException('Enter a description of letters and digits (up to 32 characters).');
        }
        $sent = [];
        $state = self::session($olt, static function (OltTelnet $t) use ($vlan, $cleanName, $cleanDesc, $uplinks, $mayRemove, &$sent): array {
            $before = self::vlanState($t);
            if (!in_array($vlan, array_column($before['vlans'], 'id'), true)) {
                throw new RuntimeException('The OLT has no VLAN ' . $vlan . '.');
            }
            $on = array_keys(array_filter($before['ports'], static fn (array $list) => in_array($vlan, $list, true)));
            $add = array_values(array_diff($uplinks, $on));
            $remove = array_values(array_diff($on, $uplinks));
            if ($remove && !$mayRemove) {
                throw new RuntimeException('Taking VLAN ' . $vlan . ' off an uplink port needs the right to delete. Nothing was changed.');
            }
            if ($remove && in_array($vlan, HuaweiCli::ownVlans($t->command('display interface vlanif')), true)) {
                throw new RuntimeException('The OLT has its own address on VLAN ' . $vlan . ', so taking it off an uplink could cut the OLT off. Nothing was changed. Do this on the OLT itself.');
            }
            $info = $before['info'][$vlan] ?? ['type' => '', 'name' => '', 'desc' => ''];
            $t->command('config');
            if ($cleanName !== '' && $cleanName !== $info['name']) {
                self::send($t, "vlan name $vlan $cleanName", $sent);
            }
            if ($cleanDesc !== $info['desc']) {
                self::send($t, $cleanDesc === '' ? "undo vlan desc $vlan" : "vlan desc $vlan description " . (str_contains($cleanDesc, ' ') ? '"' . $cleanDesc . '"' : $cleanDesc), $sent);
            }
            foreach ($add as $key) {
                [$f, $s, $p] = explode('/', $key);
                self::send($t, "port vlan $vlan $f/$s $p", $sent);
            }
            foreach ($remove as $key) {
                [$f, $s, $p] = explode('/', $key);
                self::send($t, "undo port vlan $vlan $f/$s $p", $sent);
            }
            return self::vlanState($t);
        });
        if (!$sent) {
            return 'Nothing to change: VLAN ' . $vlan . ' already has these settings.';
        }
        self::settled($olt, static fn (array $i) => self::withVlans($i, $state), 'vlan_edit', $sent, ['vlan' => $vlan]);
        return 'VLAN ' . $vlan . ' was saved on the OLT.';
    }

    /**
     * Adds a VLAN to an uplink port, or takes it off.
     *
     * @param  array<string, mixed>  $olt
     */
    public static function uplinkVlan(array $olt, int $frame, int $slot, int $port, bool $add, int $vlan): string
    {
        self::requireChange($olt);
        if ($vlan < 1 || $vlan > 4093) {
            throw new RuntimeException('Choose a VLAN.');
        }
        $where = "$frame/$slot/$port";
        $sent = [];
        $vlans = self::session($olt, static function (OltTelnet $t) use ($add, $vlan, $frame, $slot, $port, $where, &$sent): array {
            if (!in_array($vlan, array_column(HuaweiCli::vlans($t->command('display vlan all')), 'id'), true)) {
                throw new RuntimeException('The OLT has no VLAN ' . $vlan . '. Create it first.');
            }
            $before = HuaweiCli::portVlans($t->command("display port vlan $where"));
            if ($before['native'] === null) {
                throw new RuntimeException("The OLT has no uplink port $where.");
            }
            if (in_array($vlan, $before['vlans'], true) === $add) {
                throw new RuntimeException("Port $where " . ($add ? 'already carries' : 'does not carry') . ' VLAN ' . $vlan . '.');
            }
            if (!$add && in_array($vlan, self::ownVlans($t), true)) {
                throw new RuntimeException('The OLT has its own address on VLAN ' . $vlan . ', so taking it off an uplink could cut the OLT off. Do this on the OLT itself.');
            }
            $t->command('config');
            self::send($t, ($add ? '' : 'undo ') . "port vlan $vlan $frame/$slot $port", $sent);
            $now = HuaweiCli::portVlans($t->command("display port vlan $where"))['vlans'];
            if (in_array($vlan, $now, true) !== $add) {
                throw new RuntimeException("The OLT accepted the command, but port $where " . ($add ? 'does not carry' : 'still carries') . ' VLAN ' . $vlan . '.');
            }
            return $now;
        });
        self::settled($olt, static function (array $i) use ($where, $vlans): array {
            if (isset($i['uplinks'][$where])) {
                $i['uplinks'][$where]['vlans'] = $vlans;
            }
            return $i;
        }, $add ? 'uplink_vlan_add' : 'uplink_vlan_remove', $sent, ['port' => $where, 'vlan' => $vlan]);
        return $add ? "Uplink port $where now carries VLAN $vlan." : "VLAN $vlan was taken off uplink port $where.";
    }

    /**
     * Sets or clears the description of an uplink port ("port desc"). Read back from the OLT before it is saved.
     *
     * @param  array<string, mixed>  $olt
     */
    public static function uplinkDescription(array $olt, int $ifIndex, int $frame, int $slot, int $port, string $typed): string
    {
        self::requireChange($olt);
        $clean = HuaweiCli::description($typed);
        if (trim($typed) !== '' && $clean === '') {
            throw new RuntimeException('Enter a description of letters and digits (up to 64 characters).');
        }
        $where = "$frame/$slot/$port";
        $sent = [];
        $now = self::session($olt, static function (OltTelnet $t) use ($where, $clean, &$sent): string {
            $read = static function () use ($t, $where): string {
                $out = $t->command("display port desc $where");
                if (HuaweiCli::failure($out) !== null || !preg_match('~^\s*\d+/\s*\d+/\s*\d+\s+\S+[ \t]*(.*)$~m', str_replace("\r", '', $out), $m)) {
                    throw new RuntimeException("The OLT did not show port $where.");
                }
                return trim($m[1]);
            };
            $before = $read();
            if ($before === $clean) {
                throw new RuntimeException("Port $where already has this description.");
            }
            $t->command('config');
            self::send($t, $clean === '' ? "undo port desc $where" : "port desc $where description " . (str_contains($clean, ' ') ? '"' . $clean . '"' : $clean), $sent);
            $after = $read();
            if ($after !== $clean) {
                throw new RuntimeException("The OLT accepted the command, but port $where now shows \"$after\".");
            }
            return $before;
        });
        Db::exec('UPDATE olt_ports SET alias=? WHERE olt_id=? AND if_index=?', [$clean, $olt['id'], $ifIndex]);
        Audit::log('port_desc', 'olt', (string) $olt['id'], null, ['port' => $where, 'before' => $now, 'after' => $clean, 'commands' => $sent]);
        OltChangelog::add((int) $olt['id'], 'port_desc', null, $where, ['port' => $where, 'before' => $now, 'after' => $clean, 'commands' => $sent]);
        return $clean === '' ? "The description of uplink port $where was removed." : "Uplink port $where is now described as \"$clean\".";
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
        OltChangelog::add((int) $olt['id'], 'card_restart', null, $where, ['card' => (string) $board['name'], 'commands' => $sent]);
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
            // Not "display ont traffic": the OLT takes over 4 seconds to measure it, and the page has the traffic graph.
            "display ont port attribute $p $n eth", 'quit', "display service-port port $f/$s/$p ont $n",
            // In the same login, so the Configure and Running config tabs need no login of their own.
            "display current-configuration ont $f/$s/$p $n",
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
            'lan' => (static function () use ($out): array {
                // The link from the ONU, and the switch and native VLAN as set on the OLT, side by side per port.
                $set = array_column(HuaweiCli::lanSettings($out[7]), null, 'port');
                $link = array_column(HuaweiCli::lanPorts($out[5]), null, 'port');
                $ports = [];
                foreach (array_unique([...array_keys($set), ...array_keys($link)]) as $port) {
                    $ports[] = ['port' => $port, 'type' => $link[$port]['type'] ?? 'ETH', 'speed' => $link[$port]['speed'] ?? '', 'duplex' => $link[$port]['duplex'] ?? '', 'up' => $link[$port]['up'] ?? false, 'on' => $set[$port]['on'] ?? null, 'native' => $set[$port]['native'] ?? null];
                }
                sort($ports);
                return $ports;
            })(),
            'traffic' => ['up' => null, 'down' => null],
            'servicePorts' => array_map(static fn (array $sp) => ['index' => $sp['index'], 'vlan' => $sp['vlan'], 'gem' => $sp['gem'], 'down' => $sp['rx'], 'up' => $sp['tx'], 'state' => $sp['up']], HuaweiCli::servicePorts($out[9])),
            'config' => ($config = HuaweiCli::configText($out[10])),
            'omci' => $config !== '' && str_contains($config, HuaweiCli::serialForCli((string) $onu['sn']) ?? "\0") ? HuaweiCli::onuConfig($config, $p, $n) : null,
        ];
    }

    /**
     * Everything the OLT says about the ONU, as the OLT prints it (SmartOLT's "Get status"). Only reads.
     *
     * @param  array<string, mixed>  $olt
     * @param  array<string, mixed>  $onu
     * @return list<array{title: string, text: string}>
     */
    public static function statusReport(array $olt, array $onu): array
    {
        if (!self::canRead($olt)) {
            throw new RuntimeException('This needs telnet turned on for this OLT, under Edit.');
        }
        [$f, $s, $p, $n] = [(int) $onu['frame'], (int) $onu['slot'], (int) $onu['port'], (int) $onu['ont_id']];
        $out = Olts::cli($olt, 90.0)->read([
            "display ont info $f $s $p $n", "display ont wan-info $f/$s $p $n", "display mac-address port $f/$s/$p ont $n", "display mac-address ont $f/$s/$p $n",
            'config', "interface gpon $f/$s", "display ont optical-info $p $n", "display ont register-info $p $n", "display ont port state $p $n eth-port all", 'quit',
        ]);
        if (HuaweiCli::failure($out[0]) !== null || HuaweiCli::serialFromCli(HuaweiCli::fields($out[0])['SN'] ?? '') !== (string) $onu['sn']) {
            throw new RuntimeException('The OLT no longer has this ONU at ' . Olts::position($onu) . '. Use Read now on the OLT\'s page.');
        }
        $sections = [
            ['Optical status', HuaweiCli::printed($out[6])], ['ONU details', HuaweiCli::printed($out[0], true)], ['History', HuaweiCli::printed($out[7])],
            ['ONU WAN interfaces', HuaweiCli::printed($out[1])], ['ONU LAN interfaces', HuaweiCli::printed($out[8])],
            ['MACs on the OLT from this ONU', HuaweiCli::printed($out[2])], ['Online MACs on this ONU', HuaweiCli::printed($out[3])],
        ];
        return array_map(static fn (array $x) => ['title' => $x[0], 'text' => $x[1]], $sections);
    }

    /**
     * The ONU's part of the OLT's configuration, as text. Only reads.
     *
     * @param  array<string, mixed>  $olt
     * @param  array<string, mixed>  $onu
     */
    public static function runningConfig(array $olt, array $onu): string
    {
        if (!self::canRead($olt)) {
            throw new RuntimeException('This needs telnet turned on for this OLT, under Edit.');
        }
        $out = Olts::cli($olt, 60.0)->read([sprintf('display current-configuration ont %d/%d/%d %d', $onu['frame'], $onu['slot'], $onu['port'], $onu['ont_id'])])[0];
        $config = HuaweiCli::configText($out);
        if ($config === '' || !str_contains($config, HuaweiCli::serialForCli((string) $onu['sn']) ?? "\0")) {
            throw new RuntimeException('The OLT no longer has this ONU at ' . Olts::position($onu) . (($why = HuaweiCli::failure($out)) ? ' (' . $why . ')' : '') . '. Use Read now on the OLT\'s page.');
        }
        return $config;
    }

    /**
     * Changes the speed ceiling of one of the ONU's service ports to two of the OLT's traffic tables.
     *
     * @param  array<string, mixed>  $olt
     * @param  array<string, mixed>  $onu
     */
    public static function servicePortSpeed(array $olt, array $onu, int $index, int $up, int $down): void
    {
        self::requireChange($olt);
        if ($up < 0 || $up > 65535 || $down < 0 || $down > 65535) {
            throw new RuntimeException('Choose the upload and the download traffic table.');
        }
        $sent = [];
        $list = sprintf('display service-port port %d/%d/%d ont %d', $onu['frame'], $onu['slot'], $onu['port'], $onu['ont_id']);
        self::session($olt, static function (OltTelnet $t) use ($onu, $index, $up, $down, $list, &$sent): void {
            self::verify($t, $onu);
            // Only a service port the OLT lists for exactly this ONU, and only traffic tables the OLT has.
            if (!in_array($index, array_column(HuaweiCli::servicePorts($t->command($list)), 'index'), true)) {
                throw new RuntimeException('Service port ' . $index . ' does not belong to this ONU. Nothing was changed.');
            }
            $tables = array_column(HuaweiCli::trafficTables($t->command('display traffic table ip from-index 0')), 'id');
            if (!in_array($up, $tables, true) || !in_array($down, $tables, true)) {
                throw new RuntimeException('The OLT has no such traffic table. Nothing was changed.');
            }
            $t->command('config');
            self::send($t, "service-port $index inbound traffic-table index $up outbound traffic-table index $down", $sent);
            $now = array_column(HuaweiCli::servicePorts($t->command($list)), null, 'index')[$index] ?? null;
            if ($now === null || $now['tx'] !== $up || $now['rx'] !== $down) {
                throw new RuntimeException('The OLT accepted the command, but service port ' . $index . ' does not show the new traffic tables.');
            }
        });
        self::done($olt, $onu, 'onu_speed', $sent, ['service_port' => $index]);
    }

    /**
     * Turns one of the ONU's LAN ports off or on, through OMCI.
     *
     * @param  array<string, mixed>  $olt
     * @param  array<string, mixed>  $onu
     */
    public static function lanPort(array $olt, array $onu, int $eth, bool $on): void
    {
        self::requireChange($olt);
        $sent = [];
        self::session($olt, static function (OltTelnet $t) use ($onu, $eth, $on, &$sent): void {
            self::verify($t, $onu);
            $t->command('config');
            $t->command(sprintf('interface gpon %d/%d', $onu['frame'], $onu['slot']));
            $list = sprintf('display ont port attribute %d %d eth', $onu['port'], $onu['ont_id']);
            if (!in_array($eth, array_column(HuaweiCli::lanSettings($t->command($list)), 'port'), true)) {
                throw new RuntimeException('This ONU has no LAN port ' . $eth . '.');
            }
            self::send($t, sprintf('ont port attribute %d %d eth %d operational-state %s', $onu['port'], $onu['ont_id'], $eth, $on ? 'on' : 'off'), $sent);
            $now = array_column(HuaweiCli::lanSettings($t->command($list)), null, 'port')[$eth] ?? null;
            if ($now !== null && $now['on'] !== $on) {
                throw new RuntimeException('The OLT accepted the command, but LAN port ' . $eth . ' still shows as ' . ($on ? 'off' : 'on') . '.');
            }
        });
        self::done($olt, $onu, $on ? 'onu_lan_on' : 'onu_lan_off', $sent, ['lan_port' => $eth]);
    }

    /** Commands that save, restart, remove or cut off: the terminal sends them only once the operator said yes to them. */
    public const TERMINAL_CAREFUL = '/^(save|reboot|reset|undo\b|delete\b|erase|load\b|upgrade|active\b|patch\b|terminal user|sysname|ont (delete|reset|deactivate|factory-setting-restore)\b|board (reset|delete)\b|.*\bshutdown\b)/i';

    /** A command as it may be kept in the audit log: what follows a password, cipher, key or community is masked. */
    public static function maskSecrets(string $command): string
    {
        return (string) preg_replace('/\b(password|cipher|key|authentication-key|(?:read-|write-)?community(?:\s+(?:read|write))?)\s+\S+/i', '$1 ******', $command);
    }

    /**
     * The OLT's terminal: any one command, in the mode the operator is in. The connection does not stay open between
     * commands, so the mode (config, interface gpon 0/1, ...) is entered again before each one, from $context.
     * A yes/no question from the OLT is answered no and shown, unless $yes; a careful command needs $confirmed.
     *
     * @param  array<string, mixed>  $olt
     * @param  list<string>  $context  the commands that led to the current mode
     * @return array{output:string,prompt:string,context:list<string>,asked:?string,careful:bool}
     */
    public static function terminal(array $olt, array $context, string $command, bool $confirmed = false, bool $yes = false): array
    {
        self::requireChange($olt);
        $command = trim((string) preg_replace('/[ \t]+/', ' ', $command));
        $line = static function (string $text, string $what): void {
            if ($text === '' || strlen($text) > 512 || preg_match('/[\x00-\x1f\x7f?]/', $text)) {
                throw new RuntimeException($what . ' must be one line of up to 512 characters, without "?" (the OLT reads it as help before Enter).');
            }
        };
        $line($command, 'A command');
        if (count($context) > 6) {
            throw new RuntimeException('The terminal went too deep into modes. Type "return" to start again at the top.');
        }
        foreach ($context as $step) {
            $line($step, 'A mode');
            if (preg_match(self::TERMINAL_CAREFUL, $step)) {
                throw new RuntimeException('That is not a mode. Type "return" to start again at the top.');
            }
        }
        if (!$context && preg_match('/^(quit|logout|exit)$/i', $command)) {
            throw new RuntimeException('At the top, "quit" would log out. The portal logs in and out by itself for each command.');
        }
        if (preg_match(self::TERMINAL_CAREFUL, $command) && !$confirmed) {
            return ['output' => '', 'prompt' => '', 'context' => $context, 'asked' => null, 'careful' => true];
        }
        [$output, $prompt] = self::session($olt, static function (OltTelnet $t) use ($context, $command, $yes): array {
            foreach ($context as $step) {
                $failure = HuaweiCli::failure($t->command($step));
                if ($failure !== null) {
                    throw new RuntimeException('The OLT would not go back into "' . $step . '": ' . $failure . ' Type "return" to start again at the top.');
                }
            }
            $text = str_replace("\r", '', $t->command($command, $yes, true));
            $lines = explode("\n", rtrim($text));
            $prompt = trim((string) array_pop($lines));
            // The OLT echoes the command first.
            if ($lines && str_contains($lines[0], $command)) {
                array_shift($lines);
            }
            return [rtrim(implode("\n", $lines)), $prompt];
        }, 150.0);
        $asked = !$yes && preg_match('/^.*\(y\/n\).*$/mi', $output, $m) ? trim($m[0]) : null;

        // Where the command left the operator: deeper, back up, or beside where they were.
        $depth = 0;
        if (preg_match('/\(([^()]*)\)[#>%]+\s*$/', $prompt, $m)) {
            $depth = str_starts_with($m[1], 'config-') ? 2 : 1;
        }
        if ($depth > count($context)) {
            $context[] = $command;
        } elseif ($depth < count($context)) {
            $context = array_slice($context, 0, $depth);
        } elseif ($depth > 0 && preg_match('/^interface\s/i', $command)) {
            $context[count($context) - 1] = $command;
        }
        Audit::log('olt_terminal', 'olt', (string) $olt['id'], null, ['olt' => (string) $olt['name'], 'mode' => array_map(self::maskSecrets(...), $context), 'commands' => [self::maskSecrets($command)], 'answered_yes' => $yes]);
        OltChangelog::terminal((int) $olt['id'], self::maskSecrets($command), array_map(self::maskSecrets(...), $context));
        return ['output' => mb_substr(mb_scrub($output, 'UTF-8'), 0, 400000), 'prompt' => $prompt, 'context' => array_values($context), 'asked' => $asked, 'careful' => false];
    }

    /**
     * What the OMCI settings can be chosen from (the OLT's VLANs, WAN and route policy profiles and traffic tables),
     * and what the ONU has set now, from its running configuration. Only reads.
     *
     * @param  array<string, mixed>  $olt
     * @param  array<string, mixed>  $onu
     * @return array<string, mixed>
     */
    public static function omciOptions(array $olt, array $onu): array
    {
        return self::omciChoices($olt) + ['state' => self::onuState($olt, $onu)];
    }

    /**
     * The OLT's VLANs, WAN and route policy profiles and traffic tables. Only reads.
     *
     * @param  array<string, mixed>  $olt
     * @return array{vlans:list<int>,wanProfiles:list<array{id:int,name:string,bound:int}>,routeProfiles:list<array{id:int,name:string,bound:int}>,trafficTables:list<array{id:int,cir:string,pir:string}>}
     */
    public static function omciChoices(array $olt): array
    {
        if (!self::canRead($olt)) {
            throw new RuntimeException('This needs telnet turned on for this OLT, under Edit.');
        }
        $out = Olts::cli($olt, 90.0)->read(['display vlan all', 'display ont wan-profile all', 'display ont policy-route-profile all', 'display traffic table ip from-index 0']);
        $vlans = array_column(HuaweiCli::vlans($out[0]), 'id');
        if (!$vlans) {
            throw new RuntimeException('The OLT did not list its VLANs. Check that the telnet user may read them.');
        }
        sort($vlans);
        return ['vlans' => $vlans, 'wanProfiles' => HuaweiCli::profiles($out[1]), 'routeProfiles' => HuaweiCli::profiles($out[2]), 'trafficTables' => HuaweiCli::trafficTables($out[3])];
    }

    /**
     * What the ONU has set over OMCI now, from its running configuration. Only reads.
     *
     * @param  array<string, mixed>  $olt
     * @param  array<string, mixed>  $onu
     * @return array<string, mixed>
     */
    public static function onuState(array $olt, array $onu): array
    {
        if (!self::canRead($olt)) {
            throw new RuntimeException('This needs telnet turned on for this OLT, under Edit.');
        }
        return self::stateFrom(Olts::cli($olt, 60.0)->read([sprintf('display current-configuration ont %d/%d/%d %d', $onu['frame'], $onu['slot'], $onu['port'], $onu['ont_id'])])[0], $onu);
    }

    /**
     * @param  array<string, mixed>  $onu
     * @return array<string, mixed> the ONU's OMCI settings, from "display current-configuration ont"
     */
    private static function stateFrom(string $output, array $onu): array
    {
        $config = HuaweiCli::configText($output);
        if ($config === '' || !str_contains($config, HuaweiCli::serialForCli((string) $onu['sn']) ?? "\0")) {
            throw new RuntimeException('The OLT no longer has this ONU at ' . Olts::position($onu) . '. Use Read now on the OLT\'s page.');
        }
        return HuaweiCli::onuConfig($config, (int) $onu['port'], (int) $onu['ont_id']);
    }

    /**
     * @param  array<string, mixed>  $onu
     * @return array<string, mixed>
     */
    private static function state(OltTelnet $t, array $onu): array
    {
        return self::stateFrom($t->command(sprintf('display current-configuration ont %d/%d/%d %d', $onu['frame'], $onu['slot'], $onu['port'], $onu['ont_id'])), $onu);
    }

    /** @param  list<int>  $wanted */
    private static function requireVlans(OltTelnet $t, array $wanted): void
    {
        $have = array_column(HuaweiCli::vlans($t->command('display vlan all')), 'id');
        foreach ($wanted as $vlan) {
            if (!in_array($vlan, $have, true)) {
                throw new RuntimeException('The OLT has no VLAN ' . $vlan . '. Add it on the OLT\'s page first. Nothing was changed.');
            }
        }
    }

    /**
     * Turns a routing ONU into a bridge: takes off exactly the internet connections, route policy and LAN routing its
     * running configuration shows, then puts each LAN port's untagged traffic on the VLAN.
     *
     * @param  array<string, mixed>  $olt
     * @param  array<string, mixed>  $onu
     */
    public static function bridge(array $olt, array $onu, int $vlan): string
    {
        self::requireChange($olt);
        if ($vlan < 1 || $vlan > 4094) {
            throw new RuntimeException('Choose a VLAN from 1 to 4094.');
        }
        [$p, $n] = [(int) $onu['port'], (int) $onu['ont_id']];
        $sent = [];
        [$removed, $lan] = self::session($olt, static function (OltTelnet $t) use ($onu, $vlan, $p, $n, &$sent): array {
            $info = self::verify($t, $onu);
            self::requireVlans($t, [$vlan]);
            $state = self::state($t, $onu);
            $lan = 0;
            if (preg_match('/^\d+$/', trim((string) ($info['Service profile ID'] ?? '')))) {
                $lan = (int) HuaweiCli::ethPorts($t->command('display ont-srvprofile gpon profile-id ' . (int) trim($info['Service profile ID'])));
            }
            $lan = min(8, max($lan, ...array_keys($state['ports']), ...[0]));
            if ($lan === 0) {
                throw new RuntimeException('The OLT does not say how many LAN ports this ONU has. Nothing was changed.');
            }
            $t->command('config');
            $t->command(sprintf('interface gpon %d/%d', $onu['frame'], $onu['slot']));
            $removed = 0;
            foreach ($state['ports'] as $eth => $port) {
                if ($port['route']) {
                    self::send($t, "ont port route $p $n eth $eth disable", $sent);
                }
            }
            if ($state['routePolicy'] !== null) {
                self::send($t, "undo ont policy-route-config $p $n", $sent);
            }
            foreach ($state['wans'] as $index => $wan) {
                if ($wan['profile'] !== null) {
                    self::send($t, "undo ont wan-config $p $n ip-index $index", $sent);
                }
                if ($wan['internet']) {
                    self::send($t, "undo ont internet-config $p $n ip-index $index", $sent);
                }
                self::send($t, "undo ont ipconfig $p $n ip-index $index", $sent);
                $removed++;
            }
            for ($eth = 1; $eth <= $lan; $eth++) {
                if (($state['ports'][$eth]['native'] ?? null) !== $vlan) {
                    self::send($t, "ont port native-vlan $p $n eth $eth vlan $vlan priority 0", $sent);
                }
            }
            return [$removed, $lan];
        });
        self::done($olt, $onu, 'onu_bridge', $sent, ['vlan' => $vlan]);
        return 'The ONU now bridges: ' . ($removed ? 'its internet connection was taken off, and ' : '') . 'LAN ports 1 to ' . $lan . ' put untagged traffic on VLAN ' . $vlan . '.';
    }

    /**
     * Sets one LAN port's mode: LAN (routed through the ONU's connection), Access (untagged on one VLAN), Trunk (tagged
     * VLANs, and untagged on one too for hybrid) or Transparent. Only what differs from the running configuration is sent.
     *
     * @param  array<string, mixed>  $olt
     * @param  array<string, mixed>  $onu
     * @param  list<int>  $tagged
     */
    public static function ethPort(array $olt, array $onu, int $eth, string $mode, ?int $native, array $tagged): string
    {
        self::requireChange($olt);
        if (!in_array($mode, ['lan', 'access', 'trunk', 'transparent'], true)) {
            throw new RuntimeException('Choose the port mode: LAN, Access, Trunk or Transparent.');
        }
        $tagged = array_values(array_unique(array_map('intval', $tagged)));
        sort($tagged);
        foreach ([...$tagged, ...($native === null ? [] : [$native])] as $vlan) {
            if ($vlan < 1 || $vlan > 4094) {
                throw new RuntimeException('VLANs go from 1 to 4094.');
            }
        }
        if ($mode === 'access' && $native === null) {
            throw new RuntimeException('Choose the VLAN for an access port.');
        }
        if ($mode === 'trunk' && (!$tagged || count($tagged) > 16)) {
            throw new RuntimeException('Choose from 1 to 16 tagged VLANs for a trunk port.');
        }
        if ($mode !== 'trunk') {
            $tagged = [];
        }
        if (!in_array($mode, ['access', 'trunk'], true)) {
            $native = null;
        }
        [$p, $n] = [(int) $onu['port'], (int) $onu['ont_id']];
        $sent = [];
        self::session($olt, static function (OltTelnet $t) use ($onu, $eth, $mode, $native, $tagged, $p, $n, &$sent): void {
            self::verify($t, $onu);
            self::requireVlans($t, [...$tagged, ...($native === null ? [] : [$native])]);
            $now = self::state($t, $onu)['ports'][$eth] ?? ['route' => false, 'native' => null, 'tagged' => [], 'transparent' => false];
            $t->command('config');
            $t->command(sprintf('interface gpon %d/%d', $onu['frame'], $onu['slot']));
            if (!in_array($eth, array_column(HuaweiCli::lanSettings($t->command("display ont port attribute $p $n eth")), 'port'), true)) {
                throw new RuntimeException('This ONU has no LAN port ' . $eth . '.');
            }
            // What goes first, then what comes.
            if ($now['transparent'] && $mode !== 'transparent') {
                self::send($t, "undo ont port vlan $p $n eth $eth transparent", $sent);
            }
            foreach (array_diff($now['tagged'], $tagged) as $vlan) {
                self::send($t, "undo ont port vlan $p $n eth $eth translation $vlan user-vlan $vlan", $sent);
            }
            if ($now['route'] && $mode !== 'lan') {
                self::send($t, "ont port route $p $n eth $eth disable", $sent);
            }
            if ($mode === 'lan' && !$now['route']) {
                self::send($t, "ont port route $p $n eth $eth enable", $sent);
            }
            if ($native !== null && $now['native'] !== $native) {
                self::send($t, "ont port native-vlan $p $n eth $eth vlan $native priority 0", $sent);
            }
            foreach (array_diff($tagged, $now['tagged']) as $vlan) {
                self::send($t, "ont port vlan $p $n eth $eth translation $vlan user-vlan $vlan", $sent);
            }
            if ($mode === 'transparent' && !$now['transparent']) {
                self::send($t, "ont port vlan $p $n eth $eth transparent", $sent);
            }
        });
        if (!$sent) {
            return 'LAN port ' . $eth . ' already has these settings. Nothing was sent.';
        }
        self::done($olt, $onu, 'onu_eth_port', $sent, ['lan_port' => $eth, 'mode' => $mode]);
        $words = ['lan' => 'routes through the ONU', 'access' => 'puts untagged traffic on VLAN ' . $native, 'trunk' => 'carries tagged VLANs ' . implode(', ', $tagged) . ($native !== null ? ' and untagged VLAN ' . $native : ''), 'transparent' => 'passes all traffic as it comes'];
        return 'LAN port ' . $eth . ' now ' . $words[$mode] . '.';
    }

    /**
     * Adds a speed to the OLT: a traffic table with the same committed and peak rate. Refuses a speed the OLT already has.
     *
     * @param  array<string, mixed>  $olt
     * @return array{id:int,kbps:int}
     */
    public static function addSpeed(array $olt, int $mbps): array
    {
        self::requireChange($olt);
        if ($mbps < 1 || $mbps > 10000) {
            throw new RuntimeException('Enter a speed from 1 to 10000 Mbit/s.');
        }
        $kbps = $mbps * 1024;
        $sent = [];
        $id = self::session($olt, static function (OltTelnet $t) use ($kbps, $mbps, &$sent): int {
            $list = 'display traffic table ip from-index 0';
            $tables = HuaweiCli::trafficTables($t->command($list));
            foreach ($tables as $table) {
                if ($table['pir'] === (string) $kbps) {
                    throw new RuntimeException('The OLT already has ' . $mbps . 'M as traffic table ' . $table['id'] . '.');
                }
            }
            $used = array_column($tables, 'id');
            // 0 to 9 are left to the OLT's own tables.
            for ($id = 10; in_array($id, $used, true); $id++);
            if ($id > 65535) {
                throw new RuntimeException('The OLT has no free traffic table number.');
            }
            $t->command('config');
            self::send($t, "traffic table ip index $id cir $kbps pir $kbps priority 0 priority-policy local-setting", $sent);
            $now = array_column(HuaweiCli::trafficTables($t->command($list)), null, 'id')[$id] ?? null;
            if ($now === null || $now['pir'] !== (string) $kbps) {
                throw new RuntimeException('The OLT accepted the command, but does not list traffic table ' . $id . ' at ' . $mbps . 'M.');
            }
            return $id;
        });
        // Shown at once on the ONU pages; the next inventory read replaces it with the OLT's own list.
        $inventory = OltInventory::saved($olt);
        $inventory['trafficTables'] = [...($inventory['trafficTables'] ?? []), ['id' => $id, 'cir' => (string) $kbps, 'pir' => (string) $kbps]];
        Db::exec('UPDATE olts SET inventory=? WHERE id=?', [json_encode($inventory), $olt['id']]);
        Audit::log('olt_traffic_table_add', 'olt', (string) $olt['id'], null, ['olt' => (string) $olt['name'], 'traffic_table' => $id, 'kbps' => $kbps, 'commands' => $sent]);
        OltChangelog::add((int) $olt['id'], 'olt_traffic_table_add', null, null, ['traffic_table' => $id, 'kbps' => $kbps, 'commands' => $sent]);
        return ['id' => $id, 'kbps' => $kbps];
    }

    /**
     * Sets the ONU's internet connection (its WAN) through OMCI: DHCP, PPPoE or a fixed address, on a VLAN of the OLT,
     * and routes its LAN ports through it. Every value is checked here; a PPPoE password never appears in what is kept.
     *
     * @param  array<string, mixed>  $olt
     * @param  array<string, mixed>  $onu
     * @param  array<string, mixed>  $input
     * @param  ?array{0:string,1:string}  $login  a PPPoE login looked up by the caller (the linked client's RADIUS login)
     * @return string what was set, in words
     */
    public static function wanConfig(array $olt, array $onu, array $input, ?array $login = null): string
    {
        self::requireChange($olt);
        $int = static function (string $key, string $label, int $min, int $max, ?int $default = null) use ($input): ?int {
            $raw = trim((string) ($input[$key] ?? ''));
            if ($raw === '' && func_num_args() > 4) {
                return $default;
            }
            if (!preg_match('/^\d{1,5}$/D', $raw) || (int) $raw < $min || (int) $raw > $max) {
                throw new RuntimeException('Choose ' . $label . '.');
            }
            return (int) $raw;
        };
        $mode = (string) ($input['mode'] ?? '');
        if (!in_array($mode, ['dhcp', 'pppoe', 'static'], true)) {
            throw new RuntimeException('Choose how the ONU gets its address: DHCP, PPPoE or a fixed address.');
        }
        $index = $int('ip_index', 'a connection number from 0 to 7', 0, 7, 1);
        $vlan = $int('vlan', 'a VLAN from 1 to 4094', 1, 4094);
        $priority = $int('priority', 'a priority from 0 to 7', 0, 7, 0);
        $wan = $int('wan_profile', 'a WAN profile', 0, 8191, null);
        $route = $int('route_profile', 'a route policy profile', 0, 8191, null);
        $routeLan = !empty($input['route_lan']);
        [$p, $n] = [(int) $onu['port'], (int) $onu['ont_id']];
        $base = "ont ipconfig $p $n ip-index $index";
        $tail = "vlan $vlan priority $priority";
        $shown = null;
        if ($mode === 'dhcp') {
            $command = "$base dhcp $tail";
            $words = 'DHCP';
        } elseif ($mode === 'pppoe') {
            [$user, $password] = $login ?? [(string) ($input['pppoe_user'] ?? ''), (string) ($input['pppoe_password'] ?? '')];
            if (!HuaweiCli::isCredential($user) || !HuaweiCli::isCredential($password)) {
                throw new RuntimeException('The PPPoE username and password may only hold letters, digits and . _ @ + = ! # $ % & * - (up to 64 each, no spaces or quotes).');
            }
            $command = "$base pppoe $tail user-account username $user password $password";
            $shown = "$base pppoe $tail user-account username $user password ******";
            $words = 'PPPoE as ' . $user;
        } else {
            $ip = static function (string $key, string $label, bool $optional = false) use ($input): ?string {
                $raw = trim((string) ($input[$key] ?? ''));
                if ($raw === '' && $optional) {
                    return null;
                }
                if (filter_var($raw, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    throw new RuntimeException('Enter ' . $label . ' as an IPv4 address, such as 10.20.0.2.');
                }
                return $raw;
            };
            $address = (string) $ip('ip', 'the address');
            $mask = (string) $ip('mask', 'the subnet mask');
            $bits = ip2long($mask);
            if ($bits === false || $bits === 0 || ((~$bits & 0xFFFFFFFF) & ((~$bits & 0xFFFFFFFF) + 1)) !== 0) {
                throw new RuntimeException('Enter a subnet mask such as 255.255.255.0.');
            }
            $gateway = (string) $ip('gateway', 'the gateway');
            if (((int) ip2long($address) & $bits) !== ((int) ip2long($gateway) & $bits)) {
                throw new RuntimeException('The gateway is not in the same network as the address.');
            }
            $dns = (string) $ip('dns1', 'the first DNS server');
            $dns2 = $ip('dns2', 'the second DNS server', true);
            $command = "$base static ip-address $address mask $mask gateway $gateway pri-dns $dns" . ($dns2 !== null ? " slave-dns $dns2" : '') . " $tail";
            $words = 'fixed address ' . $address;
        }

        $sent = [];
        $ports = self::session($olt, static function (OltTelnet $t) use ($onu, $p, $n, $index, $vlan, $wan, $route, $routeLan, $command, $shown, &$sent): int {
            $info = self::verify($t, $onu);
            if (!in_array($vlan, array_column(HuaweiCli::vlans($t->command('display vlan all')), 'id'), true)) {
                throw new RuntimeException('The OLT has no VLAN ' . $vlan . '. Add it on the OLT\'s page first. Nothing was changed.');
            }
            foreach ([[$wan, 'display ont wan-profile all', 'WAN profile'], [$route, 'display ont policy-route-profile all', 'route policy profile']] as [$id, $list, $label]) {
                if ($id !== null && !in_array($id, array_column(HuaweiCli::profiles($t->command($list)), 'id'), true)) {
                    throw new RuntimeException('The OLT has no ' . $label . ' ' . $id . '. Nothing was changed.');
                }
            }
            $lan = 0;
            if ($routeLan && preg_match('/^\d+$/', trim((string) ($info['Service profile ID'] ?? '')))) {
                $lan = min(8, (int) HuaweiCli::ethPorts($t->command('display ont-srvprofile gpon profile-id ' . (int) trim($info['Service profile ID']))));
            }
            $t->command('config');
            $t->command(sprintf('interface gpon %d/%d', $onu['frame'], $onu['slot']));
            self::send($t, $command, $sent, false, $shown);
            self::send($t, "ont internet-config $p $n ip-index $index", $sent);
            if ($wan !== null) {
                self::send($t, "ont wan-config $p $n ip-index $index profile-id $wan", $sent);
            }
            if ($route !== null) {
                self::send($t, "ont policy-route-config $p $n profile-id $route", $sent);
            }
            for ($eth = 1; $eth <= $lan; $eth++) {
                self::send($t, "ont port route $p $n eth $eth enable", $sent);
            }
            return $lan;
        });
        self::done($olt, $onu, 'onu_wan', $sent, ['mode' => $mode, 'vlan' => $vlan, 'ip_index' => $index]);
        return 'The ONU\'s internet connection ' . $index . ' is now set to ' . $words . ' on VLAN ' . $vlan . ($ports ? ', with LAN ports 1 to ' . $ports . ' routed through it' : '') . '. The ONU takes it within a minute: use Get status to see it connect.';
    }

    /**
     * Sets the VLAN a LAN port puts untagged traffic on (its native VLAN), through OMCI.
     *
     * @param  array<string, mixed>  $olt
     * @param  array<string, mixed>  $onu
     */
    public static function lanVlan(array $olt, array $onu, int $eth, int $vlan, int $priority): void
    {
        self::requireChange($olt);
        if ($vlan < 1 || $vlan > 4094 || $priority < 0 || $priority > 7) {
            throw new RuntimeException('Choose a VLAN from 1 to 4094 and a priority from 0 to 7.');
        }
        $sent = [];
        self::session($olt, static function (OltTelnet $t) use ($onu, $eth, $vlan, $priority, &$sent): void {
            self::verify($t, $onu);
            if (!in_array($vlan, array_column(HuaweiCli::vlans($t->command('display vlan all')), 'id'), true)) {
                throw new RuntimeException('The OLT has no VLAN ' . $vlan . '. Nothing was changed.');
            }
            $t->command('config');
            $t->command(sprintf('interface gpon %d/%d', $onu['frame'], $onu['slot']));
            $list = sprintf('display ont port attribute %d %d eth', $onu['port'], $onu['ont_id']);
            if (!in_array($eth, array_column(HuaweiCli::lanSettings($t->command($list)), 'port'), true)) {
                throw new RuntimeException('This ONU has no LAN port ' . $eth . '.');
            }
            self::send($t, sprintf('ont port native-vlan %d %d eth %d vlan %d priority %d', $onu['port'], $onu['ont_id'], $eth, $vlan, $priority), $sent);
            $now = array_column(HuaweiCli::lanSettings($t->command($list)), null, 'port')[$eth] ?? null;
            if ($now !== null && $now['native'] !== $vlan) {
                throw new RuntimeException('The OLT accepted the command, but LAN port ' . $eth . ' still shows native VLAN ' . $now['native'] . '.');
            }
        });
        self::done($olt, $onu, 'onu_lan_vlan', $sent, ['lan_port' => $eth, 'vlan' => $vlan]);
    }

    /**
     * Puts the ONU's own settings (Wi-Fi name and password, its admin page) back to how it left the factory.
     *
     * @param  array<string, mixed>  $olt
     * @param  array<string, mixed>  $onu
     */
    public static function factoryReset(array $olt, array $onu): void
    {
        self::requireChange($olt);
        $sent = [];
        self::session($olt, static function (OltTelnet $t) use ($onu, &$sent): void {
            $now = self::verify($t, $onu);
            if (strtolower($now['Run state'] ?? '') !== 'online') {
                throw new RuntimeException('This ONU is offline, so it cannot be reset from the OLT.');
            }
            $t->command('config');
            $t->command(sprintf('interface gpon %d/%d', $onu['frame'], $onu['slot']));
            self::send($t, sprintf('ont factory-setting-restore %d %d', $onu['port'], $onu['ont_id']), $sent, true);
        });
        self::done($olt, $onu, 'onu_factory_reset', $sent);
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
        $onu = self::session($olt, static function (OltTelnet $t) use ($olt, $sn, $hex, $mode, $line, $service, $vlan, $gem, $up, $down, $wan, $route, $description, &$sent): array {
            // Where the ONU is comes from the OLT, not from the form.
            $found = array_values(array_filter(HuaweiCli::autofind($t->command('display ont autofind all')), static fn (array $o) => $o['sn'] === $sn));
            if (count($found) !== 1) {
                throw new RuntimeException($found ? 'The OLT sees ' . $sn . ' on more than one port. Check the fibre first.' : 'The OLT no longer lists ' . $sn . ' as a new ONU. It may have been added or unplugged.');
            }
            $at = $found[0];
            $max = (int) ($olt['max_onus_per_pon'] ?? Olts::MAX_ONUS);
            $onPort = (int) Db::value('SELECT COUNT(*) FROM olt_onus WHERE olt_id=? AND frame=? AND slot=? AND port=?', [$olt['id'], $at['frame'], $at['slot'], $at['port']]);
            if ($onPort >= $max) {
                throw new RuntimeException(sprintf('Port %d/%d/%d already has %d ONUs, the most this OLT allows per PON port (%d). Raise it in the OLT settings to add more.', $at['frame'], $at['slot'], $at['port'], $onPort, $max));
            }
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
