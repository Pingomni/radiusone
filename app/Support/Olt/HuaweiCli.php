<?php

declare(strict_types=1);

namespace App\Support\Olt;

/** Reads what a Huawei MA5800 prints on its command line. Nothing here talks to the OLT. */
final class HuaweiCli
{
    /** The OLT's own words when it did not do what it was asked, or null when it did. */
    public static function failure(string $output): ?string
    {
        if (preg_match('/^\s*(Failure\s*:.*|% ?(Unknown command|Parameter error|Incomplete command|Ambiguous command|Too many parameters)[^\n]*|Error\s*:.*)$/mi', $output, $match)) {
            return mb_substr(trim((string) preg_replace('/\s+/', ' ', $match[1])), 0, 300);
        }
        return null;
    }

    /** HWTC1234ABCD, as the portal keeps it, from the 16 hex digits the command line uses. */
    public static function serialFromCli(string $text): string
    {
        if (preg_match('/\b([0-9A-Fa-f]{16})\b/', $text, $match)) {
            $vendor = (string) hex2bin(substr($match[1], 0, 8));
            return preg_match('/^[A-Za-z0-9]{4}$/D', $vendor) ? strtoupper($vendor . substr($match[1], 8)) : strtoupper($match[1]);
        }
        return HuaweiMa5800::normalizeSerial($text);
    }

    /** The 16 hex digits the command line wants, or null if this is not a serial number. */
    public static function serialForCli(string $sn): ?string
    {
        $sn = HuaweiMa5800::normalizeSerial($sn);
        if (preg_match('/^[0-9A-F]{16}$/D', $sn)) {
            return $sn;
        }
        return preg_match('/^[A-Z0-9]{4}[0-9A-F]{8}$/D', $sn) ? strtoupper(bin2hex(substr($sn, 0, 4))) . substr($sn, 4) : null;
    }

    /**
     * A description the OLT accepts: plain letters, digits and simple punctuation, up to 64 characters.
     * Letters with marks lose them (ë becomes e), and a question mark or a quote is dropped. A colon is dropped too,
     * so a description echoed back by the OLT can never read like one of its own answers ("Failure: ...").
     */
    public static function description(string $typed): string
    {
        $typed = (string) preg_replace('/\s+/u', ' ', $typed);
        $plain = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $typed);
        $plain = (string) preg_replace('/[^A-Za-z0-9 _.,@#()+\/-]/', '', $plain === false ? $typed : $plain);
        return trim(substr((string) preg_replace('/\s+/', ' ', $plain), 0, 64));
    }

    /**
     * A PPPoE username or password the command line takes as one word: no spaces, quotes, question marks (the OLT's
     * help key) or backslashes, so it can never be read as more of the command.
     */
    public static function isCredential(string $value): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9._@+=!#$%&*-]{1,64}$/D', $value);
    }

    /**
     * "Name : value" lines. The first value for a name wins.
     *
     * @return array<string, string>
     */
    public static function fields(string $output): array
    {
        $fields = [];
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (preg_match('/^\s*([A-Za-z][^:|]{1,45}?)\s*:\s*(.*?)\s*$/', $line, $match) && !isset($fields[$match[1]])) {
                $fields[$match[1]] = $match[2];
            }
        }
        return $fields;
    }

    /**
     * ONUs that are plugged in but not yet added.
     *
     * @return list<array{frame:int,slot:int,port:int,sn:string,model:string,software:string,found:string}>
     */
    public static function autofind(string $output): array
    {
        $found = [];
        foreach (preg_split('/^\s*Number\s*:/m', $output) ?: [] as $block) {
            $f = self::fields($block);
            if (!isset($f['F/S/P'], $f['Ont SN']) || !preg_match('~^(\d+)\s*/\s*(\d+)\s*/\s*(\d+)~', $f['F/S/P'], $at)) {
                continue;
            }
            $sn = self::serialFromCli($f['Ont SN']);
            if ($sn === '') {
                continue;
            }
            $found[] = [
                'frame' => (int) $at[1], 'slot' => (int) $at[2], 'port' => (int) $at[3], 'sn' => $sn,
                'model' => self::value($f['Ont EquipmentID'] ?? ''), 'software' => self::value($f['Ont SoftwareVersion'] ?? ''),
                'found' => substr($f['Ont autofind time'] ?? '', 0, 19),
            ];
        }
        return $found;
    }

    /**
     * A profile list: line, service, WAN and route policy profiles all print the same table.
     *
     * @return list<array{id:int,name:string,bound:int}>
     */
    public static function profiles(string $output): array
    {
        preg_match_all('/^\s*(\d+)\s+(\S(?:.*?\S)?)\s+(\d+)\s*$/m', $output, $rows, PREG_SET_ORDER);
        return array_map(static fn (array $r) => ['id' => (int) $r[1], 'name' => $r[2], 'bound' => (int) $r[3]], $rows);
    }

    /**
     * @return list<array{id:int,ports:int,type:string,standard:int}> VLANs, with how many service ports and uplink ports use each
     */
    public static function vlans(string $output): array
    {
        preg_match_all('/^\s*(\d+)\s+(smart|standard|mux|super)\s+\S+\s+(\d+)\s+(\d+)/mi', $output, $rows, PREG_SET_ORDER);
        return array_map(static fn (array $r) => ['id' => (int) $r[1], 'ports' => (int) $r[4], 'type' => strtolower($r[2]), 'standard' => (int) $r[3]], $rows);
    }

    /**
     * The OLT's VLANs as its "vlan-config" section has them: type, name and description of each, and which uplink
     * ports carry which VLANs. A VLAN's default name (VLAN_0010) is not in the configuration, so it has none here.
     *
     * @return array{vlans:array<int, array{type:string,name:string,desc:string}>,ports:array<string, list<int>>}
     */
    public static function vlanConfig(string $output): array
    {
        $range = static function (string $from, string $to): array {
            $to = $to === '' ? $from : $to;
            return (int) $to - (int) $from > 4093 ? [] : range((int) $from, max((int) $from, (int) $to));
        };
        $vlans = [];
        $ports = [];
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (preg_match('/^\s*vlan (\d+)(?: to (\d+))? (smart|standard|mux|super)\s*$/i', $line, $m)) {
                foreach ($range($m[1], $m[2]) as $id) {
                    $vlans[$id] = ['type' => strtolower($m[3]), 'name' => '', 'desc' => ''] + ($vlans[$id] ?? []);
                }
            } elseif (preg_match('/^\s*vlan name (\d+) (\S+)\s*$/i', $line, $m)) {
                $vlans[(int) $m[1]] = ['name' => self::value(trim($m[2], '"'))] + ($vlans[(int) $m[1]] ?? ['type' => '', 'desc' => '']);
            } elseif (preg_match('/^\s*vlan desc (\d+) description (.+?)\s*$/i', $line, $m)) {
                $vlans[(int) $m[1]] = ['desc' => self::value(trim($m[2], '"'))] + ($vlans[(int) $m[1]] ?? ['type' => '', 'name' => '']);
            } elseif (preg_match('~^\s*port vlan (\d+)(?: to (\d+))? (\d+/\d+) ([\d,\-]+)\s*$~i', $line, $m)) {
                foreach (explode(',', $m[4]) as $part) {
                    [$a, $b] = array_pad(explode('-', $part), 2, '');
                    foreach ($range($a, $b) as $port) {
                        $ports["$m[3]/$port"] = array_merge($ports["$m[3]/$port"] ?? [], $range($m[1], $m[2]));
                    }
                }
            }
        }
        foreach ($ports as $key => $list) {
            $list = array_values(array_unique($list));
            sort($list);
            $ports[$key] = $list;
        }
        ksort($vlans);
        $vlans = array_map(static fn (array $v) => ['type' => $v['type'], 'name' => $v['name'], 'desc' => $v['desc']], $vlans);
        return ['vlans' => $vlans, 'ports' => $ports];
    }

    /** @return list<int> the VLANs the OLT has an address of its own on, from "display interface vlanif" */
    public static function ownVlans(string $output): array
    {
        preg_match_all('/^\s*Vlanif(\d+)\b/mi', $output, $m);
        return array_values(array_unique(array_map('intval', $m[1])));
    }

    /** A VLAN name the OLT accepts, or '' when what was typed is not one: up to 15 letters, digits, "_", "-" or ".". */
    public static function vlanName(string $typed): string
    {
        $typed = trim($typed);
        return preg_match('/^[A-Za-z0-9_.-]{1,15}$/D', $typed) ? $typed : '';
    }

    /** A VLAN description the OLT accepts: plain letters, digits, spaces and simple punctuation, up to 32 characters. */
    public static function vlanDescription(string $typed): string
    {
        $plain = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) preg_replace('/\s+/u', ' ', $typed));
        $plain = (string) preg_replace('/[^A-Za-z0-9 _.,@#()+\/-]/', '', $plain === false ? $typed : $plain);
        return trim(substr((string) preg_replace('/\s+/', ' ', $plain), 0, 32));
    }

    /**
     * "Name    value" lines, as the port commands print them, in blocks that each start with an F/S/P line.
     *
     * @return array<string, array<string, string>> "0/1/15" => its lines
     */
    public static function portBlocks(string $output): array
    {
        $blocks = [];
        $at = null;
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (!preg_match('/^\s{2}(\S.*?)\s{2,}(\S.*?)\s*$/', $line, $m)) {
                continue;
            }
            if ($m[1] === 'F/S/P' && preg_match('~^(\d+)\s*/\s*(\d+)\s*/\s*(\d+)$~', $m[2], $p)) {
                $at = "$p[1]/$p[2]/$p[3]";
                $blocks[$at] = [];
            } elseif ($at !== null && !isset($blocks[$at][$m[1]])) {
                $blocks[$at][$m[1]] = self::value($m[2]);
            }
        }
        return $blocks;
    }

    /**
     * The port table of an uplink card, from "display board F/S".
     *
     * @return list<array{port:int,type:string,optic:string,native:int,speed:string,duplex:string,active:bool,online:bool}>
     */
    public static function uplinkPorts(string $output): array
    {
        preg_match_all('/^\s+(\d+)\s+(\d*GE|FE|XGE|\d+G)\s+(\S+)\s+(\d+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(online|offline)\s*$/mi', $output, $rows, PREG_SET_ORDER);
        return array_map(static fn (array $r) => [
            'port' => (int) $r[1], 'type' => strtoupper($r[2]), 'optic' => strtolower($r[3]), 'native' => (int) $r[4], 'speed' => self::value($r[6]), 'duplex' => self::value($r[7]),
            'active' => strtolower($r[9]) === 'active', 'online' => strtolower($r[10]) === 'online',
        ], $rows);
    }

    /**
     * @return array{vlans:list<int>,native:?int} the VLANs an uplink port carries, from "display port vlan F/S/P"
     */
    public static function portVlans(string $output): array
    {
        $vlans = [];
        $native = null;
        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (preg_match('/^\s*Native VLAN\s*:\s*(\d+)/i', $line, $m)) {
                $native = (int) $m[1];
            } elseif (preg_match('/^\s*\d+(\s+\d+)*\s*$/', $line)) {
                $vlans = array_merge($vlans, array_map('intval', preg_split('/\s+/', trim($line)) ?: []));
            }
        }
        return ['vlans' => array_values(array_unique($vlans)), 'native' => $native];
    }

    /**
     * @return list<array{server:string,status:string,reach:int}> time servers, from "display ntp-service sessions"
     */
    public static function ntp(string $output): array
    {
        $servers = [];
        foreach (array_slice(preg_split('/^\s*clock source\s*:/mi', $output) ?: [], 1) as $block) {
            $f = self::fields('clock source :' . $block);
            if (filter_var(trim($f['clock source'] ?? ''), FILTER_VALIDATE_IP)) {
                $servers[] = ['server' => trim($f['clock source']), 'status' => self::value($f['clock status'] ?? ''), 'reach' => (int) ($f['reach'] ?? 0)];
            }
        }
        return $servers;
    }

    /**
     * @return list<array{id:int,type:int,fix:int,assure:int,max:int,bound:int}> speeds in kbit/s
     */
    public static function dbaProfiles(string $output): array
    {
        preg_match_all('/^\s*(\d+)\s+(\d)\s+(?:Yes|No)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*$/mi', $output, $rows, PREG_SET_ORDER);
        return array_map(static fn (array $r) => ['id' => (int) $r[1], 'type' => (int) $r[2], 'fix' => (int) $r[3], 'assure' => (int) $r[4], 'max' => (int) $r[5], 'bound' => (int) $r[6]], $rows);
    }

    /**
     * Whether the OLT saves a changed configuration by itself, and how many minutes after the change.
     *
     * @return array{on:?bool,minutes:?int}
     */
    public static function autosave(string $output): array
    {
        if (!preg_match('/autosave modified configuration switch\s*:\s*(on|off)(?:\s*\n\s*Autosave interval\s*:\s*(\d+))?/i', $output, $m)) {
            return ['on' => null, 'minutes' => null];
        }
        return ['on' => strtolower($m[1]) === 'on', 'minutes' => isset($m[2]) ? (int) $m[2] : null];
    }

    /**
     * @return list<array{id:int,cir:string,pir:string}> speeds in kbit/s, or "off" for no limit
     */
    public static function trafficTables(string $output): array
    {
        preg_match_all('/^\s*(\d+)\s+(\d+|off)\s+(\d+|off)\s+(\d+|off)\s+(\d+|off)\s+\d+\s/mi', $output, $rows, PREG_SET_ORDER);
        return array_map(static fn (array $r) => ['id' => (int) $r[1], 'cir' => strtolower($r[2]), 'pir' => strtolower($r[4])], $rows);
    }

    /**
     * @return list<array{index:int,vlan:int,frame:int,slot:int,port:int,ont_id:int,gem:int,rx:?int,tx:?int,up:bool}>
     */
    public static function servicePorts(string $output): array
    {
        preg_match_all('~^\s*(\d+)\s+(\d+)\s+\S+\s+gpon\s+(\d+)\s*/\s*(\d+)\s*/\s*(\d+)\s+(\d+)\s+(\d+)\s+\S+\s+\S+\s+(\S+)\s+(\S+)\s+(up|down)~mi', $output, $rows, PREG_SET_ORDER);
        $number = static fn (string $v) => ctype_digit($v) ? (int) $v : null;
        return array_map(static fn (array $r) => [
            'index' => (int) $r[1], 'vlan' => (int) $r[2], 'frame' => (int) $r[3], 'slot' => (int) $r[4], 'port' => (int) $r[5],
            'ont_id' => (int) $r[6], 'gem' => (int) $r[7], 'rx' => $number($r[8]), 'tx' => $number($r[9]), 'up' => strtolower($r[10]) === 'up',
        ], $rows);
    }

    /** How many LAN ports a service profile has, or null when it does not say a number. */
    public static function ethPorts(string $profile): ?int
    {
        return preg_match('/^\s*ETH\s+(\d+)\s/m', $profile, $match) ? (int) $match[1] : null;
    }

    /**
     * @return list<array{port:int,type:string,speed:string,duplex:string,up:bool}>
     */
    public static function lanPorts(string $output): array
    {
        preg_match_all('/^\s*\d+\s+(\d+)\s+(\S+)\s+(\S+)\s+(\S+)\s+(up|down)\b/mi', $output, $rows, PREG_SET_ORDER);
        return array_map(static fn (array $r) => ['port' => (int) $r[1], 'type' => $r[2], 'speed' => self::value($r[3]), 'duplex' => self::value($r[4]), 'up' => strtolower($r[5]) === 'up'], $rows);
    }

    /**
     * The ONU's own internet connections, as "display ont wan-info" lists them.
     *
     * @return list<array<string, string>>
     */
    public static function wans(string $output): array
    {
        $wans = [];
        foreach (array_slice(preg_split('/^\s*Index\s*:/m', $output) ?: [], 1) as $block) {
            $f = self::fields('Index :' . $block);
            $wans[] = [
                'name' => self::value($f['Name'] ?? ''), 'service' => self::value($f['Service type'] ?? ''), 'type' => self::value($f['Connection type'] ?? ''),
                'status' => self::value($f['IPv4 Connection status'] ?? ''), 'access' => self::value($f['IPv4 access type'] ?? ''),
                'ip' => self::value($f['IPv4 address'] ?? ''), 'mask' => self::value($f['Subnet mask'] ?? ''), 'gateway' => self::value($f['Default gateway'] ?? ''),
                'dns' => trim(self::value($f['Primary DNS'] ?? '') . ' ' . self::value($f['Secondary DNS'] ?? '')),
                'vlan' => self::value($f['Manage VLAN'] ?? ''), 'mac' => self::value($f['MAC address'] ?? ''),
            ];
        }
        return $wans;
    }

    /**
     * An ONU's LAN ports as set on the OLT, from "display ont port attribute".
     *
     * @return list<array{port:int,on:bool,native:int,speed:string}>
     */
    public static function lanSettings(string $output): array
    {
        preg_match_all('/^\s*\d+\s+(\d+)\s+ETH\s+\S+\s+(\S+)\s+\S+\s+(on|off)\s+\S+\s+(\d+)\s+\d+\s*$/mi', $output, $rows, PREG_SET_ORDER);
        return array_map(static fn (array $r) => ['port' => (int) $r[1], 'on' => strtolower($r[3]) === 'on', 'native' => (int) $r[4], 'speed' => strtolower($r[2])], $rows);
    }

    /**
     * What an ONU is sending and receiving right now, in kbit/s, from "display ont traffic".
     *
     * @return array{up:?int,down:?int}
     */
    public static function ontTraffic(string $output): array
    {
        $f = self::fields($output);
        $number = static fn (string $key) => is_numeric(trim($f[$key] ?? '')) ? (int) trim($f[$key]) : null;
        return ['up' => $number('Up traffic (kbps)'), 'down' => $number('Down traffic (kbps)')];
    }

    /** A configuration as the OLT prints it, without the command's echo before it and the prompt after it. */
    public static function configText(string $output): string
    {
        $lines = explode("\n", trim(str_replace("\r", '', $output)));
        while ($lines && !str_starts_with($lines[0], '[')) {
            array_shift($lines);
        }
        if ($lines && preg_match('/[>#]\s*$/', (string) end($lines))) {
            array_pop($lines);
        }
        return $lines ? trim(implode("\n", $lines)) . "\n" : '';
    }

    /**
     * What one ONU has set over OMCI, read from its part of the running configuration: its internet connections
     * (by ip-index), its route policy, and each LAN port's routing and VLANs. Lines the OLT wrapped are joined first.
     *
     * @return array{mode:string,wans:array<int,array{mode:string,vlan:int,priority:int,user:string,internet:bool,profile:?int}>,routePolicy:?int,ports:array<int,array{route:bool,native:?int,tagged:list<int>,transparent:bool}>}
     */
    public static function onuConfig(string $config, int $port, int $ont): array
    {
        $lines = [];
        foreach (explode("\n", str_replace("\r", '', $config)) as $line) {
            if ($lines && $line !== '' && !preg_match('/^[\s#\[<]|^return\b/', $line)) {
                $lines[array_key_last($lines)] .= ' ' . trim($line);
            } else {
                $lines[] = trim($line);
            }
        }
        $at = "$port $ont";
        $ipconfig = $internet = $profiles = $route = $native = $tagged = $transparent = [];
        $routePolicy = null;
        foreach ($lines as $line) {
            $line = (string) preg_replace('/\s+/', ' ', $line);
            if (preg_match("/^ont ipconfig $at ip-index (\\d+) (dhcp|pppoe|static)\\b.*?\\bvlan (\\d+)(?: priority (\\d+))?(?:.*\\busername (\\S+))?/", $line, $m)) {
                $ipconfig[(int) $m[1]] = ['mode' => $m[2], 'vlan' => (int) $m[3], 'priority' => (int) ($m[4] ?? 0), 'user' => trim($m[5] ?? '', '"')];
            } elseif (preg_match("/^ont internet-config $at ip-index (\\d+)$/", $line, $m)) {
                $internet[(int) $m[1]] = true;
            } elseif (preg_match("/^ont wan-config $at ip-index (\\d+) profile-id (\\d+)$/", $line, $m)) {
                $profiles[(int) $m[1]] = (int) $m[2];
            } elseif (preg_match("/^ont policy-route-config $at profile-id (\\d+)$/", $line, $m)) {
                $routePolicy = (int) $m[1];
            } elseif (preg_match("/^ont port route $at eth (\\d+) (enable|disable)$/", $line, $m)) {
                $route[(int) $m[1]] = $m[2] === 'enable';
            } elseif (preg_match("/^ont port native-vlan $at eth (\\d+) vlan (\\d+)/", $line, $m)) {
                $native[(int) $m[1]] = (int) $m[2];
            } elseif (preg_match("/^ont port vlan $at eth (\\d+) transparent$/", $line, $m)) {
                $transparent[(int) $m[1]] = true;
            } elseif (preg_match("/^ont port vlan $at eth (\\d+) (?:translation )?(\\d+)(?: user-vlan \\d+)?$/", $line, $m)) {
                $tagged[(int) $m[1]][] = (int) $m[2];
            }
        }
        // A connection counts only with its ipconfig line: the rest of it hangs on that.
        $wans = [];
        foreach ($ipconfig as $index => $wan) {
            $wans[$index] = $wan + ['internet' => isset($internet[$index]), 'profile' => $profiles[$index] ?? null];
        }
        ksort($wans);
        $ports = [];
        foreach (array_unique([...array_keys($route), ...array_keys($native), ...array_keys($tagged), ...array_keys($transparent)]) as $eth) {
            $ports[$eth] = ['route' => $route[$eth] ?? false, 'native' => $native[$eth] ?? null, 'tagged' => $tagged[$eth] ?? [], 'transparent' => $transparent[$eth] ?? false];
        }
        ksort($ports);
        $mode = $wans ? 'routing' : ($native || $tagged || $transparent ? 'bridging' : 'none');
        return ['mode' => $mode, 'wans' => $wans, 'routePolicy' => $routePolicy, 'ports' => $ports];
    }

    /** The OLT prints a dash for "nothing". */
    public static function value(string $raw): string
    {
        $raw = trim($raw);
        return $raw === '-' ? '' : mb_substr(mb_scrub($raw, 'UTF-8'), 0, 120);
    }
}
