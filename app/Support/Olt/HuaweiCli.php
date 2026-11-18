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

    /** The OLT prints a dash for "nothing". */
    public static function value(string $raw): string
    {
        $raw = trim($raw);
        return $raw === '-' ? '' : mb_substr(mb_scrub($raw, 'UTF-8'), 0, 120);
    }
}
