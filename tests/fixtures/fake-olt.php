<?php

declare(strict_types=1);

/**
 * A pretend Huawei MA5800 for the tests: answers SNMP reads over UDP and runs a telnet command line over TCP, on the
 * loopback address only. The command line keeps its ONUs and service ports in memory, so the tests can add, change and
 * remove them the way the portal does on a real OLT. Every SNMP request type and every telnet line it receives is written to a log file.
 * Usage: php fake-olt.php <config.json>
 */

use App\Support\Snmp;

require __DIR__ . '/../../vendor/autoload.php';

$config = json_decode((string) file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR);
$log = static function (string $line) use ($config): void {
    file_put_contents($config['log'], $line . "\n", FILE_APPEND);
};

$tree = [];
foreach ($config['oids'] as $oid => [$tag, $value]) {
    $tree[$oid] = [$tag, $tag === 4 ? base64_decode($value, true) : $value];
}
uksort($tree, [Snmp::class, 'compare']);
$oids = array_keys($tree);

$udp = stream_socket_server('udp://127.0.0.1:' . $config['snmp_port'], $errno, $error, STREAM_SERVER_BIND);
$tcp = stream_socket_server('tcp://127.0.0.1:' . $config['telnet_port'], $errno, $error);
if (!$udp || !$tcp) {
    fwrite(STDERR, $error);
    exit(1);
}
file_put_contents($config['ready'], 'ready');

$next = static function (string $oid) use ($oids): ?string {
    foreach ($oids as $candidate) {
        if (Snmp::compare($candidate, $oid) > 0) {
            return $candidate;
        }
    }
    return null;
};

$answer = static function ($socket) use ($config, $tree, $next, $log): void {
    $packet = stream_socket_recvfrom($socket, 65535, 0, $peer);
    $request = Snmp::decodeRequest((string) $packet);
    if ($request === null || $request['community'] !== $config['community']) {
        return; // A real OLT stays silent for a wrong community.
    }
    $log('snmp ' . dechex($request['type']));
    $binds = [];
    if ($request['type'] === 0xA0) {
        foreach ($request['oids'] as $oid) {
            $binds[] = isset($tree[$oid]) ? [$oid, $tree[$oid][0], $tree[$oid][1]] : [$oid, 0x80, ''];
        }
    } elseif ($request['type'] === 0xA5 || $request['type'] === 0xA1) {
        $cursor = $request['oids'][0];
        for ($i = 0; $i < max(1, (int) $request['max_repetitions']); $i++) {
            $cursor = $next($cursor);
            if ($cursor === null) {
                $binds[] = [$request['oids'][0], 0x82, ''];
                break;
            }
            $binds[] = [$cursor, $tree[$cursor][0], $tree[$cursor][1]];
        }
    } else {
        return; // Anything else, such as a SET, is logged above and never answered.
    }
    stream_socket_sendto($socket, Snmp::encodeResponse($request['community'], (int) $request['version'], (int) $request['id'], $binds), 0, $peer);
};

$client = null;
$buffer = '';
$state = 'closed';
$say = static function (string $text) use (&$client): void {
    @fwrite($client, str_replace("\n", "\r\n", $text));
};
$mode = '';
$prompt = static function () use (&$state, &$mode, $say): void {
    $say("\n" . ($state === 'enable' ? 'MA5800-X7' . ($mode === '' ? '' : ($mode === 'config' ? '(config)' : '(config-if-gpon-' . substr($mode, 3) . ')')) . '#' : 'MA5800-X7>'));
};
$table = $config['mac_table'];

// What the command line knows: ONUs already added, ONUs plugged in but not added, and service ports. Tests can replace each.
$onts = [];
foreach ($config['onts'] ?? [[0, 1, 0, 3, '485754431234ABCD', 'Kati 2 hyrja A', 'online'], [0, 2, 5, 12, '5A54454700C171A7', 'byname', 'offline']] as [$f, $s, $p, $n, $sn, $desc, $run]) {
    $onts["$f/$s/$p/$n"] = ['sn' => $sn, 'desc' => $desc, 'run' => $run, 'active' => true, 'line' => 6, 'srv' => 2];
}
$autofind = $config['autofind'] ?? [[0, 1, 0, '48575443AAAA0001', 'EG8145V5']];
$servicePorts = [];
foreach ($config['service_ports'] ?? [[2100, 100, '0/1/0/3'], [2101, 100, '0/2/5/12']] as [$index, $vlan, $ont]) {
    $servicePorts[$index] = ['vlan' => $vlan, 'ont' => $ont, 'gem' => 1, 'in' => 8, 'out' => 9];
}
$lanOff = [];
$ntp = ['192.0.2.123'];
$vlans = [1 => [8, 0], 100 => [1, 16]];
$portVlans = [];
$failing = $config['fail'] ?? [];
$pending = null;
$profiles = static fn (array $rows) => "  ------------------------------------------------------------------------------\n  Profile-ID  Profile-name                                Binding times\n  ------------------------------------------------------------------------------\n"
    . implode('', array_map(static fn (array $r) => sprintf("  %-11d %-43s %-13d\n", ...$r), $rows)) . "  ------------------------------------------------------------------------------\n  Total: " . count($rows) . "\n";
$portRows = static function (array $ports) use (&$onts): string {
    if (!$ports) {
        return "\n  Failure: No service virtual port can be operated\n";
    }
    $text = "\n  Switch-Oriented Flow List\n  -----------------------------------------------------------------------------\n   INDEX VLAN VLAN     PORT F/ S/ P VPI  VCI   FLOW  FLOW       RX   TX   STATE\n         ID   ATTR     TYPE                    TYPE  PARA\n  -----------------------------------------------------------------------------\n";
    foreach ($ports as $index => $sp) {
        [$f, $s, $p, $n] = explode('/', $sp['ont']);
        $text .= sprintf("   %5d %4d common   gpon %d/%-2d/%-3d%-4d %-5d vlan  %-10d %-4d %-4d %-5s\n", $index, $sp['vlan'], $f, $s, $p, $n, $sp['gem'], $sp['vlan'], $sp['out'], $sp['in'], ($onts[$sp['ont']]['run'] ?? '') === 'online' ? 'up' : 'down');
    }
    return $text . "  -----------------------------------------------------------------------------\n   Total : " . count($ports) . "  (Up/Down :    0/0)\n";
};
$ontInfo = static function (string $key) use (&$onts): string {
    if (!isset($onts[$key])) {
        return "\n  Failure: The ONT does not exist\n";
    }
    [$f, $s, $p, $n] = explode('/', $key);
    $o = $onts[$key];
    $on = $o['run'] === 'online' && $o['active'];
    return "\n  -----------------------------------------------------------------------------\n  F/S/P                   : $f/$s/$p\n  ONT-ID                  : $n\n"
        . '  Control flag            : ' . ($o['active'] ? 'active' : 'deactivated') . "\n  Run state               : " . ($on ? 'online' : 'offline') . "\n"
        . "  Config state            : normal\n  Match state             : match\n  Memory occupation       : " . ($on ? '50%' : '-') . "\n  CPU occupation          : " . ($on ? '2%' : '-') . "\n  Temperature             : " . ($on ? '60(C)' : '-') . "\n"
        . '  SN                      : ' . $o['sn'] . ' (' . hex2bin(substr($o['sn'], 0, 8)) . '-' . substr($o['sn'], 8) . ")\n  ONT IP 1 address/mask   : 10.200.0.254/24\n"
        . '  Description             : ' . $o['desc'] . "\n  Last down cause         : -\n  ONT online duration     : " . ($on ? '0 day(s), 19 hour(s), 4 minute(s), 53 second(s)' : '-') . "\n"
        . "  -----------------------------------------------------------------------------\n  Line profile ID      : {$o['line']}\n  Line profile name    : SmartOLT_G\n  <T-CONT   0>          DBA Profile-ID:2\n   <Gem Index 1>\n"
        . "  Service profile ID   : {$o['srv']}\n  Service profile name : HG8346M\n  -----------------------------------------------------------------------------\n";
};
// A command the command line knows beyond the first reads. Returns false for one it does not.
$cli = static function (string $text) use (&$mode, &$state, &$onts, &$autofind, &$servicePorts, &$pending, &$ntp, &$vlans, &$portVlans, &$lanOff, $failing, $say, $profiles, $portRows, $ontInfo, $config): bool {
    foreach ($failing as $prefix) {
        if (str_starts_with($text, $prefix)) {
            $say("\n  Failure: Made to fail by the test\n");
            return true;
        }
    }
    $interface = str_starts_with($mode, 'if:') ? substr($mode, 3) : null;
    $ok = static fn () => true;
    if ($text === 'config' && $mode === '') {
        $mode = 'config';
        return true;
    }
    if ($mode === 'config' && preg_match('~^interface gpon (\d+/\d+)$~', $text, $m)) {
        $mode = 'if:' . $m[1];
        return true;
    }
    if ($text === 'quit' && $mode !== '') {
        $mode = $interface !== null ? 'config' : '';
        return true;
    }
    if ($text === 'display ont autofind all') {
        if (!$autofind) {
            $say("\n  Failure: The automatically found ONTs do not exist\n");
            return true;
        }
        foreach ($autofind as $i => [$f, $s, $p, $sn, $model]) {
            $say("\n   ----------------------------------------------------------------------------\n   Number              : " . ($i + 1) . "\n   F/S/P               : $f/$s/$p\n   Ont SN              : $sn (" . hex2bin(substr($sn, 0, 8)) . '-' . substr($sn, 8) . ")\n   Password            : 0x00000000000000000000\n   Loid                : \n   VendorID            : HWTC\n   Ont Version         : 3FE.A\n   Ont SoftwareVersion : V5R019C00S050\n   Ont EquipmentID     : $model\n   Ont autofind time   : 2026-11-18 10:00:00+01:00");
        }
        $say("\n   ----------------------------------------------------------------------------\n   The number of GPON autofind ONT is " . count($autofind) . "\n");
        return true;
    }
    if ($text === 'display version') {
        $say("\n  VERSION : MA5800V100R018C00\n  PATCH   : SPH612\n  PRODUCT : MA5800-X7\n\n  Uptime is 0 day(s), 11 hour(s), 17 minute(s), 11 second(s)\n");
        return true;
    }
    if ($text === 'display dba-profile all') {
        $say("\n  ----------------------------------------------------------------------------\n  Profile-ID   type   Bandwidth      Fix        Assure        Max        Bind\n                     compensation    (kbps)     (kbps)        (kbps)     times\n  ----------------------------------------------------------------------------\n           0      3           No          0       8192         20480         1\n          10      4           No          0          0       1048000         2\n  ----------------------------------------------------------------------------\n");
        return true;
    }
    if ($text === 'display ntp-service sessions') {
        foreach ($ntp as $server) {
            $say("\n clock source: $server\n clock stratum: 16\n clock status: configured, insane, valid, unsynced\n reference clock ID: 0.0.0.0\n reach: 0\n current poll: 1024");
        }
        $say("\n");
        return true;
    }
    if ($mode === 'config' && preg_match('/^(undo )?ntp-service unicast-server (\S+)$/', $text, $m)) {
        $ntp = $m[1] === '' ? array_values(array_unique([...$ntp, $m[2]])) : array_values(array_diff($ntp, [$m[2]]));
        return true;
    }
    if ($text === 'display interface vlanif') {
        $say("\nVlanif100 current state : UP (ifindex: 8388618)\nLine protocol current state : UP \nInternet Address is 10.200.0.2/24\n");
        return true;
    }
    if ($mode === 'config' && preg_match('/^(?:vlan (\d+) smart|undo vlan (\d+))$/', $text, $m)) {
        if ($m[1] !== '') {
            $vlans[(int) $m[1]] = [0, 0];
        } else {
            unset($vlans[(int) $m[2]]);
        }
        ksort($vlans);
        return true;
    }
    if ($mode === 'config' && preg_match('~^(undo )?port vlan (\d+) (\d+/\d+) (\d+)$~', $text, $m)) {
        $key = "$m[3]/$m[4]";
        $have = $portVlans[$key] ?? [1, 100, 200];
        $portVlans[$key] = $m[1] === '' ? array_values(array_unique([...$have, (int) $m[2]])) : array_values(array_diff($have, [(int) $m[2]]));
        foreach ($vlans as $id => $counts) {
            $vlans[$id][0] = count(array_filter($portVlans, static fn (array $list) => in_array($id, $list, true)));
        }
        return true;
    }
    if ($text === 'display ntp-service sessions-unused') {
        $say("\n clock source: 192.0.2.123\n clock stratum: 16\n clock status: configured, insane, valid, unsynced\n reference clock ID: 0.0.0.0\n reach: 0\n current poll: 1024\n");
        return true;
    }
    if ($text === 'display autosave configuration') {
        $say("\n  System autosave interval switch: on\n  Autosave interval: 1442 minutes\n  Autosave type: data and configuration file\n  System autosave modified configuration switch: on\n  Autosave interval: 60 minutes\n  Autosave type: data and configuration file\n");
        return true;
    }
    if (preg_match('~^display board (\d+/\d+)$~', $text)) {
        $say("\n  ---------------------------------------\n  Board Name          : H905MPLB   \n  Board Status        : Active normal\n  ---------------------------------------\n  Port  Port Optic   Native  MDI    Speed      Duplex    Flow-  Active   Link    \n        Type Status  VLAN           (Mbps)               Ctrl   State            \n  ------------------------------------------------------------------------------\n     0  GE   normal       1  normal 1000       full      off    active   online \n     1  10GE absence      1  -      10000      full      off    active   offline\n  ------------------------------------------------------------------------------\n");
        return true;
    }
    if (preg_match('~^display port vlan (\d+/\d+/\d+)$~', $text, $m)) {
        $list = $portVlans[$m[1]] ?? [1, 100, 200];
        $say("\n  ---------------------------------------\n   " . implode('    ', $list) . "\n  ---------------------------------------\n  Total: " . count($list) . "\n  Native VLAN: 1\n");
        return true;
    }
    if ($text === 'display current-configuration') {
        $say("\n{ <cr>|include-default<K>|section<K>||<K> }:");
        $state = 'config-question';
        return true;
    }
    if ($interface !== null && $text === 'display port state all') {
        foreach ([0, 5] as $p) {
            $say("\n  ----------------------------------------------------------------------------\n  F/S/P                        $interface/$p\n  Optical Module status        Online\n  Port state                   " . ($p ? 'Offline' : 'Online') . "\n  Laser state                  Normal\n  Temperature(C)               31\n  TX Bias current(mA)          37\n  Supply Voltage(V)            3.19\n  TX power(dBm)                7.12\n  Illegal rogue ONT            Inexistent\n  Wave length(nm)              1490");
        }
        $say("\n  ----------------------------------------------------------------------------\n");
        return true;
    }
    if ($interface !== null && preg_match('/^(port (\d+) ont-auto-find (enable|disable)|(undo )?shutdown (\d+))$/', $text, $m)) {
        // Port settings changed by a test, read back by "display port info".
        $settings = is_file($config['log'] . '.ports') ? (array) json_decode((string) file_get_contents($config['log'] . '.ports'), true) : [];
        if ($m[2] !== '') {
            $settings["$interface/$m[2]"]['autofind'] = $m[3] === 'enable';
        } else {
            $settings["$interface/$m[5]"]['admin'] = ($m[4] ?? '') !== '';
        }
        file_put_contents($config['log'] . '.ports', json_encode($settings));
        return true;
    }
    if ($interface !== null && preg_match('/^ont reset (\d+) all$/', $text)) {
        $state = 'question';
        $pending = static function () use ($say): void {
            $say("\n  Number of ONTs that can be reset: 2, success: 2\n");
        };
        $say("\n Are you sure to reset the ONT(s)? (y/n)[n]:");
        return true;
    }
    if ($mode === 'config' && preg_match('~^board reset \d+/\d+$~', $text)) {
        $state = 'question';
        $pending = static function () use ($say): void {
            $say("\n  Board reset, please wait...\n");
        };
        $say("\n Are you sure to reset board? (y/n)[n]:");
        return true;
    }
    if ($interface !== null && preg_match('/^display port info (\d+)$/', $text, $m)) {
        $settings = is_file($config['log'] . '.ports') ? (array) json_decode((string) file_get_contents($config['log'] . '.ports'), true) : [];
        $set = $settings["$interface/$m[1]"] ?? [];
        if ((int) $m[1] > 15) {
            $say("\n  Failure: The port does not exist\n");
            return true;
        }
        $say("\n  -----------------------------------------------------------\n  F/S/P                                      $interface/$m[1]\n  Min distance(km)                           0\n  Max distance(km)                           20\n  Autofind                                   " . (($set['autofind'] ?? $m[1] === '0') ? 'Enable' : 'Disable') . "\n  Admin State                                " . (($set['admin'] ?? true) ? 'On' : 'Off') . "\n  -----------------------------------------------------------\n");
        return true;
    }
    if ($interface !== null && preg_match('/^display port info-unused (\d+)$/', $text, $m)) {
        $say("\n  -----------------------------------------------------------\n  F/S/P                                      $interface/$m[1]\n  Min distance(km)                           0\n  Max distance(km)                           20\n  Autofind                                   " . ($m[1] === '0' ? 'Enable' : 'Disable') . "\n  Admin State                                On\n  -----------------------------------------------------------\n");
        return true;
    }
    if ($text === 'display ont-lineprofile gpon all') {
        $say("\n" . $profiles([[0, 'line-profile_default_0', 0], [6, 'SmartOLT_G', 16]]));
        return true;
    }
    if ($text === 'display ont-srvprofile gpon all') {
        $say("\n" . $profiles([[0, 'srv-profile_default_0', 0], [1, 'HG8010H', 1], [2, 'HG8346M', 11]]));
        return true;
    }
    if ($text === 'display ont wan-profile all') {
        $say("\n" . $profiles([[0, 'smartolt', 15]]));
        return true;
    }
    if ($text === 'display ont policy-route-profile all') {
        $say("\n" . $profiles([[0, 'smartolt', 4], [1, 'smartolt_eth1-4_wifi1_wan1', 11]]));
        return true;
    }
    if ($text === 'display vlan all') {
        $say("\n  -----------------------------------------------------------------------\n  VLAN   Type      Attribute  STND-Port NUM   SERV-Port NUM  VLAN-Con NUM\n  -----------------------------------------------------------------------\n");
        foreach ($vlans as $id => [$standard, $service]) {
            $say(sprintf("  %4d   smart     common     %12d    %12d             -\n", $id, $standard, $service));
        }
        $say("  -----------------------------------------------------------------------\n  Total: " . count($vlans) . "\n");
        return true;
    }
    if ($text === 'display traffic table ip from-index 0') {
        $say("\n  ---------------------------------------------------------------------------\n   TID CIR      CBS        PIR      PBS        Pri Copy-policy     Pri-Policy\n       (kbps)   (bytes)    (kbps)   (bytes)\n  ---------------------------------------------------------------------------\n     6 off      off        off      off          0 -                  tag-pri\n     8 1048064  13100800   1048064  13100800     0 -                local-pri\n     9 1048064  13100800   1048064  13100800     0 -                local-pri\n  ---------------------------------------------------------------------------\n  Total Num : 3\n");
        return true;
    }
    if ($text === 'display service-port all') {
        $say($portRows($servicePorts));
        return true;
    }
    if (preg_match('~^display service-port port (\d+/\d+/\d+) ont (\d+)$~', $text, $m)) {
        $say($portRows(array_filter($servicePorts, static fn (array $sp) => $sp['ont'] === $m[1] . '/' . $m[2])));
        return true;
    }
    if (preg_match('/^display ont-srvprofile gpon profile-id (\d+)$/', $text, $m)) {
        $say("\n  -----------------------------------------------------------------------------\n  Profile-ID  : $m[1]\n  Port-type     Port-number     Max-adaptive-number\n  -----------------------------------------------------------------------------\n  POTS          0               -\n  ETH           " . ($m[1] === '1' ? 1 : 4) . "               -\n  CATV          adaptive        1\n  Binding times : 1\n");
        return true;
    }
    if (preg_match('~^display current-configuration ont (\d+/\d+)/(\d+) (\d+)$~', $text, $m)) {
        $k = "$m[1]/$m[2]/$m[3]";
        if (!isset($onts[$k])) {
            $say("\n  Failure: The ONT does not exist\n");
            return true;
        }
        $say("\n[MA5800V100R018: 8300]\n#\n[gpon]\n  <gpon-$m[1]>\n interface gpon $m[1]\n ont add $m[2] $m[3] sn-auth \"{$onts[$k]['sn']}\" omci ont-lineprofile-id 6 \nont-srvprofile-id 2 desc \"{$onts[$k]['desc']}\" \n ont ipconfig $m[2] $m[3] ip-index 1 dhcp vlan 100 priority 0\n#\nreturn\n");
        return true;
    }
    if ($mode === 'config' && preg_match('/^service-port (\d+) inbound traffic-table index (\d+) outbound traffic-table index (\d+)$/', $text, $m)) {
        if (!isset($servicePorts[(int) $m[1]])) {
            $say("\n  Failure: Service virtual port does not exist\n");
            return true;
        }
        $servicePorts[(int) $m[1]]['in'] = (int) $m[2];
        $servicePorts[(int) $m[1]]['out'] = (int) $m[3];
        return true;
    }
    if ($interface !== null && preg_match('/^display ont port attribute (\d+) (\d+) eth$/', $text, $m)) {
        $say("\n  ----------------------------------------------------------------------------\n  ONT ONT     ONT       Auto-neg Speed  Duplex Port   Flow    Native Priority \n      port    port-type          (Mbps)        switch control VLAN            \n  ----------------------------------------------------------------------------\n");
        foreach ([1, 4] as $eth) {
            $say(sprintf("  %-3d %-7d ETH       enable   auto   auto   %-6s off     1      0       \n", $m[2], $eth, ($lanOff["$interface/$m[1]/$m[2]/$eth"] ?? false) ? 'off' : 'on'));
        }
        $say("  ----------------------------------------------------------------------------\n");
        return true;
    }
    if ($interface !== null && preg_match('/^ont port attribute (\d+) (\d+) eth (\d+) operational-state (on|off)$/', $text, $m)) {
        $lanOff["$interface/$m[1]/$m[2]/$m[3]"] = $m[4] === 'off';
        return true;
    }
    if ($interface !== null && preg_match('/^display ont traffic (\d+) (\d+)$/', $text)) {
        $say("\n ----------------------------------------------------------------\n  Traffic Information\n ----------------------------------------------------------------\n Up traffic (kbps)          :  83\n Down traffic (kbps)        :  6300\n ----------------------------------------------------------------\n");
        return true;
    }
    if ($interface !== null && preg_match('/^ont factory-setting-restore (\d+) (\d+)$/', $text)) {
        $state = 'question';
        $pending = static function () use ($say): void {
            $say("\n  The command has been sent to the ONT\n");
        };
        $say("\n Are you sure to restore the factory settings of the ONT? (y/n)[n]:");
        return true;
    }
    if (preg_match('/^display ont info (\d+) (\d+) (\d+) (\d+)$/', $text, $m)) {
        $say($ontInfo("$m[1]/$m[2]/$m[3]/$m[4]"));
        return true;
    }
    if (preg_match('~^display ont wan-info (\d+/\d+) (\d+) (\d+)$~', $text, $m)) {
        $say("\n  ---------------------------------------------------------------------\n  F/S/P                      : $m[1]/$m[2]\n  ONT ID                     : $m[3]\n  ---------------------------------------------------------------------\n  Index                      : 1\n  Name                       : OLT_C_1_INTERNET_R_VID_100\n  Service type               : Internet\n  Connection type            : IP routed\n  IPv4 Connection status     : Connected\n  IPv4 access type           : DHCP\n  IPv4 address               : 10.200.0.254\n  Subnet mask                : 255.255.255.0\n  Default gateway            : 10.200.0.1\n  Primary DNS                : 10.200.0.1\n  Secondary DNS              : 1.1.1.1\n  Manage VLAN                : 100\n  MAC address                : 789A-1806-A44A\n  ---------------------------------------------------------------------\n");
        return true;
    }
    if ($interface === null) {
        if (preg_match('~^service-port vlan (\d+) gpon (\d+/\d+/\d+) ont (\d+) gemport (\d+) multi-service user-vlan \d+ tag-transform translate inbound traffic-table index (\d+) outbound traffic-table index (\d+)$~', $text, $m) && $mode === 'config') {
            if (!isset($onts["$m[2]/$m[3]"])) {
                $say("\n  Failure: The ONT does not exist\n");
                return true;
            }
            $servicePorts[$servicePorts ? max(array_keys($servicePorts)) + 1 : 0] = ['vlan' => (int) $m[1], 'ont' => "$m[2]/$m[3]", 'gem' => (int) $m[4], 'in' => (int) $m[5], 'out' => (int) $m[6]];
            return true;
        }
        if (preg_match('/^undo service-port (\d+)$/', $text, $m) && $mode === 'config') {
            if (!isset($servicePorts[(int) $m[1]])) {
                $say("\n  Failure: Service virtual port does not exist\n");
                return true;
            }
            unset($servicePorts[(int) $m[1]]);
            return true;
        }
        return false;
    }
    // Inside "interface gpon F/S": the commands take the port and the ONU number.
    $key = static fn (string $p, string $n) => "$interface/$p/$n";
    $need = static function (string $k) use (&$onts, $say): bool {
        if (!isset($onts[$k])) {
            $say("\n  Failure: The ONT does not exist\n");
            return false;
        }
        return true;
    };
    if (preg_match('/^display ont info (\d+) (\d+)$/', $text, $m)) {
        $say($ontInfo($key($m[1], $m[2])));
        return true;
    }
    if (preg_match('/^display ont optical-info (\d+) (\d+)$/', $text, $m)) {
        $on = ($onts[$key($m[1], $m[2])]['run'] ?? '') === 'online';
        $say($on ? "\n  -----------------------------------------------------------------------------\n  ONU NNI port ID                        : 0\n  Rx optical power(dBm)                  : -18.18\n  Rx power current alarm threshold(dBm)  : [-29.0,-7.0]\n  Tx optical power(dBm)                  : 2.46\n  Laser bias current(mA)                 : 8\n  Temperature(C)                         : 43\n  Voltage(V)                             : 3.300\n  OLT Rx ONT optical power(dBm)          : -22.45\n" : "\n  Failure: The ONT is not online\n");
        return true;
    }
    if (preg_match('/^display ont port state (\d+) (\d+) eth-port all$/', $text, $m)) {
        $say("\n  --------------------------------------------------------------------------\n  ONT-ID   ONT      ONT       Speed(Mbps)   Duplex   LinkState  RingStatus\n           port-ID  Port-type    \n  --------------------------------------------------------------------------\n       $m[2]         1         GE -             -        down       noloop    \n       $m[2]         4         GE 100           full     up         noloop    \n");
        return true;
    }
    if (preg_match('/^display ont version (\d+) (\d+)$/', $text, $m)) {
        $say("\n  -------------------------------------------------------------------------- \n  Vendor-ID                : HWTC \n  Equipment-ID             : EG8041X6-10 \n  Main Software Version    : V5R024C10S112 \n  Ont MAC                  : 789A-1806-A449 \n");
        return true;
    }
    if (preg_match('/^ont add (\d+) sn-auth "([0-9A-F]{16})" omci ont-lineprofile-id (\d+) ont-srvprofile-id (\d+) desc "([^"]*)"$/', $text, $m)) {
        $at = null;
        foreach ($autofind as $i => $found) {
            if ($found[3] === $m[2] && "$found[0]/$found[1]" === $interface && (string) $found[2] === $m[1]) {
                $at = $i;
            }
        }
        if ($at === null) {
            $say("\n  Failure: SN already exists\n");
            return true;
        }
        $id = 0;
        while (isset($onts[$key($m[1], (string) $id)])) {
            $id++;
        }
        $onts[$key($m[1], (string) $id)] = ['sn' => $m[2], 'desc' => $m[5], 'run' => 'online', 'active' => true, 'line' => (int) $m[3], 'srv' => (int) $m[4]];
        unset($autofind[$at]);
        $autofind = array_values($autofind);
        $say("\n  Number of ONTs that can be added: 1, success: 1\n  PortID :$m[1], ONTID :$id\n");
        return true;
    }
    if (preg_match('/^ont (ipconfig|internet-config|wan-config|policy-route-config) (\d+) (\d+) (ip-index 1 dhcp vlan \d+ priority 0|ip-index 1|ip-index 1 profile-id \d+|profile-id \d+)$/', $text, $m)
        || preg_match('/^ont port (route|native-vlan) (\d+) (\d+) eth \d+ (enable|vlan \d+ priority 0)$/', $text, $m)) {
        return $need($key($m[2], $m[3])) || $ok();
    }
    if (preg_match('/^ont (reset|deactivate|activate|delete) (\d+) (\d+)$/', $text, $m)) {
        $k = $key($m[2], $m[3]);
        if (!$need($k)) {
            return true;
        }
        if ($m[1] === 'reset') {
            // Asks first, like the OLT.
            $state = 'question';
            $pending = static function () use ($say): void {
                $say("\n  Number of ONTs that can be reset: 1, success: 1\n");
            };
            $say("\n Are you sure to reset the ONT(s)? (y/n)[n]:");
            return true;
        }
        if ($m[1] === 'delete') {
            if (array_filter($servicePorts, static fn (array $sp) => $sp['ont'] === $k)) {
                $say("\n  Failure: This configured object has some service virtual ports\n");
                return true;
            }
            unset($onts[$k]);
            $say("\n  Number of ONTs that can be deleted: 1, success: 1\n");
            return true;
        }
        $onts[$k]['active'] = $m[1] === 'activate';
        $say("\n  Number of ONTs that can be " . $m[1] . "d: 1, success: 1\n");
        return true;
    }
    if (preg_match('/^ont modify (\d+) (\d+) desc "([^"]*)"$/', $text, $m)) {
        if ($need($key($m[1], $m[2]))) {
            $onts[$key($m[1], $m[2])]['desc'] = $m[3];
        }
        return true;
    }
    return false;
};
$line = static function (string $text) use (&$client, &$state, &$table, &$pending, $cli, &$config, $say, $prompt, $log): void {
    switch ($state) {
        case 'config-question':
            $state = 'enable';
            $say("\n[Active: H905MPLB; Standby: H905MPLB]\n[MA5800V100R018: 8300]\n#\n[global-config]\n  <global-config>\n sysname " . ($config['sysname'] ?? 'MA5800-X7') . "\n snmp-agent community read cipher \"secret-in-the-config\"\n#\n[gpon]\n  <gpon-0/1>\n interface gpon 0/1\n port 0 ont-auto-find enable\n#\nreturn\n");
            $prompt();
            return;
        case 'question':
            $log('telnet-cmd ' . $text);
            $state = 'enable';
            if ($text === 'y' && $pending) {
                $pending();
            }
            $pending = null;
            $prompt();
            return;
        case 'user':
            $log('telnet-user');
            $state = $text === $config['user'] ? 'password' : 'bad-user';
            $say("\n>>User password:");
            return;
        case 'bad-user':
        case 'password':
            $log('telnet-password');
            if ($state === 'bad-user' || $text !== $config['password']) {
                $say("\n  Username or password invalid.\n\n>>User name:");
                $state = 'user';
                return;
            }
            $state = 'login';
            $say("\n  Number of failed logins before this one: 0\n");
            $prompt();
            return;
        case 'scroll-question':
            $state = 'enable';
            $say("\n  Command:\n          scroll\n");
            $prompt();
            return;
        case 'table-question':
            $parts = explode('@MORE@', $table);
            $say("\n  It will take some time, please wait...\n" . array_shift($parts));
            if ($parts) {
                $table = implode('@MORE@', $parts);
                $state = 'more';
                $say("\n---- More ( Press 'Q' to break ) ----");
            } else {
                $state = 'enable';
                $prompt();
            }
            return;
        case 'logout':
            $log('telnet-cmd ' . $text);
            $state = 'closed';
            fclose($client);
            $client = null;
            return;
    }
    $log('telnet-cmd ' . $text);
    if ($state === 'enable' && is_file($config['log'] . '.sysname')) {
        // A test changes the configuration by leaving a new name here.
        $config['sysname'] = trim((string) file_get_contents($config['log'] . '.sysname'));
    }
    if ($state === 'enable' && $cli($text)) {
        if ($state === 'enable') {
            $prompt();
        }
        return;
    }
    match ($text) {
        'enable' => (static function () use (&$state, $prompt) {
            $state = 'enable';
            $prompt();
        })(),
        'scroll' => (static function () use (&$state, $say) {
            $state = 'scroll-question';
            $say("\n{ <cr>|number<U><10,512> }:");
        })(),
        'display mac-address all' => (static function () use (&$state, $say) {
            $state = 'table-question';
            $say("\n{ <cr>||<K> }:");
        })(),
        'quit' => (static function () use (&$state, $say) {
            $state = 'logout';
            $say("\n  Are you sure to log out? (y/n)[n]:");
        })(),
        default => $say("\n  % Unknown command, the error locates at '^'\n"),
    };
    if (!in_array($text, ['enable', 'scroll', 'display mac-address all', 'quit'], true)) {
        $prompt();
    }
};

$until = time() + 300;
while (time() < $until) {
    $read = [$udp, $tcp];
    if ($client) {
        $read[] = $client;
    }
    $none = null;
    if (!stream_select($read, $none, $none, 1)) {
        continue;
    }
    foreach ($read as $socket) {
        if ($socket === $udp) {
            $answer($udp);
        } elseif ($socket === $tcp) {
            $client = stream_socket_accept($tcp, 1);
            stream_set_blocking($client, false);
            $state = 'user';
            $mode = '';
            $buffer = '';
            $table = $config['mac_table'];
            // Asks for an option, as telnet servers do, split so a reply has to cope with it.
            fwrite($client, chr(255) . chr(251) . chr(1) . chr(255) . chr(253) . chr(31));
            fwrite($client, "\e[0mWarning: authorised use only\r\n>>User name:");
        } else {
            $data = fread($client, 4096);
            if ($data === '' || $data === false) {
                if (feof($client)) {
                    fclose($client);
                    $client = null;
                }
                continue;
            }
            // Drop the client's replies to the options.
            $data = (string) preg_replace('/\xff[\xfb-\xfe]./s', '', $data);
            if ($state === 'more' && str_contains($data, ' ')) {
                $data = str_replace(' ', '', $data);
                $parts = explode('@MORE@', $table);
                // Like the OLT: the cursor moves back over the "More" line, and the next page starts on that line.
                $say("\e[37D" . str_repeat(' ', 37) . "\e[37D" . array_shift($parts));
                if ($parts) {
                    $table = implode('@MORE@', $parts);
                    $say("\n---- More ( Press 'Q' to break ) ----");
                } else {
                    $state = 'enable';
                    $prompt();
                }
            }
            $buffer .= $data;
            while ($client && ($at = strpos($buffer, "\n")) !== false) {
                $text = rtrim(substr($buffer, 0, $at), "\r");
                $buffer = substr($buffer, $at + 1);
                $line($text);
            }
        }
    }
}
