<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Routing and layer-2 tables of a NAS, read live over SNMP (read-only) when the operator opens them on
 * the NAS monitor page: IP addresses (IP-MIB ipAddrTable), ARP (ipNetToMediaTable), the MAC table
 * (Q-BRIDGE-MIB, else BRIDGE-MIB), BGP peers (BGP4-MIB) and OSPF neighbours (OSPF-MIB). Nothing is stored.
 */
final class NasTables
{
    public const TABLES = ['addresses' => 'IP addresses', 'arp' => 'ARP', 'mac' => 'MAC table', 'bgp' => 'BGP', 'ospf' => 'OSPF'];
    /** Rows read per table; a BRAS can have a large ARP or MAC table. */
    public const LIMIT = 2000;

    private const IF_NAME = '1.3.6.1.2.1.31.1.1.1.1';
    private const IF_DESCR = '1.3.6.1.2.1.2.2.1.2';
    private const BGP_STATES = [1 => ['idle', 'danger'], 2 => ['connect', 'warning'], 3 => ['active', 'warning'], 4 => ['open sent', 'warning'], 5 => ['open confirm', 'warning'], 6 => ['established', 'success']];
    private const OSPF_STATES = [1 => ['down', 'danger'], 2 => ['attempt', 'warning'], 3 => ['init', 'warning'], 4 => ['two-way', ''], 5 => ['exchange start', 'warning'], 6 => ['exchange', 'warning'], 7 => ['loading', 'warning'], 8 => ['full', 'success']];
    private const ARP_TYPES = [1 => 'other', 2 => 'invalid', 3 => 'dynamic', 4 => 'static'];
    private const FDB_STATUS = [1 => 'other', 2 => 'invalid', 3 => 'learned', 4 => 'self', 5 => 'management'];

    /**
     * @param object|null $client anything with Snmp's walk(); tests pass recorded answers
     * @return array{columns: list<string>, rows: list<list<string|array{text:string,tone:string}>>, truncated: bool, empty: string, note: string}
     */
    public static function read(array $nas, string $table, ?object $client = null): array
    {
        if (!isset(self::TABLES[$table])) {
            throw new RuntimeException('Unknown table.');
        }
        $settings = NasMonitor::settings((int) $nas['id']);
        if ($client === null && !NasMonitor::configured($settings)) {
            throw new RuntimeException('SNMP is not set up for this NAS. Set it up on the NAS SNMP tab to read its tables.');
        }
        $snmp = $client ?? NasMonitor::client($nas, $settings, 15.0);
        return match ($table) {
            'addresses' => self::addresses($snmp),
            'arp' => self::arp($snmp),
            'mac' => self::mac($snmp),
            'bgp' => self::bgp($snmp),
            'ospf' => self::ospf($snmp),
        };
    }

    private static function addresses(object $snmp): array
    {
        $names = self::interfaces($snmp);
        $rows = [];
        foreach (self::columns($snmp, '1.3.6.1.2.1.4.20.1', [1, 2, 3]) as $c) {
            if (!isset($c[1])) {
                continue;
            }
            $rows[] = [(string) $c[1] . '/' . self::prefix((string) ($c[3] ?? '')), $names[(int) ($c[2] ?? 0)] ?? ('#' . ($c[2] ?? '?'))];
        }
        usort($rows, static fn ($a, $b) => [$a[1], ip2long(explode('/', $a[0])[0])] <=> [$b[1], ip2long(explode('/', $b[0])[0])]);
        return self::result(['Address', 'Interface'], $rows, 'The device reports no IPv4 addresses over SNMP (IP-MIB).');
    }

    private static function arp(object $snmp): array
    {
        $names = self::interfaces($snmp);
        $rows = [];
        $truncated = false;
        $invalid = 0;
        foreach (self::columns($snmp, '1.3.6.1.2.1.4.22.1', [2, 4], $truncated) as $index => $c) {
            $parts = explode('.', $index);
            $type = (int) ($c[4] ?? 1);
            if ($type === 2 || count($parts) < 5) {
                $invalid++;
                continue;
            }
            $rows[] = [implode('.', array_slice($parts, -4)), self::macAddress((string) ($c[2] ?? '')), $names[(int) $parts[0]] ?? ('#' . $parts[0]), self::ARP_TYPES[$type] ?? (string) $type];
        }
        usort($rows, static fn ($a, $b) => ip2long($a[0]) <=> ip2long($b[0]));
        return self::result(['IP address', 'MAC address', 'Interface', 'Type'], $rows, 'The ARP table is empty, or the device does not publish it over SNMP (IP-MIB).', $truncated,
            $invalid ? $invalid . ' invalid entr' . ($invalid === 1 ? 'y' : 'ies') . ' (failed lookups) not shown.' : '');
    }

    private static function mac(object $snmp): array
    {
        $truncated = false;
        // Q-BRIDGE (per VLAN): index = FDB id . 6 MAC bytes. BRIDGE-MIB: index = 6 MAC bytes.
        $entries = self::columns($snmp, '1.3.6.1.2.1.17.7.1.2.2.1', [2, 3], $truncated);
        $vlans = true;
        if (!$entries) {
            $entries = self::columns($snmp, '1.3.6.1.2.1.17.4.3.1', [2, 3], $truncated);
            $vlans = false;
        }
        $ports = $entries ? array_map('intval', self::column($snmp, '1.3.6.1.2.1.17.1.4.1.2')) : [];
        $names = $entries ? self::interfaces($snmp) : [];
        $rows = [];
        foreach ($entries as $index => $c) {
            $parts = array_map('intval', explode('.', $index));
            if (count($parts) < 6) {
                continue;
            }
            $mac = implode(':', array_map(static fn ($b) => sprintf('%02X', $b), array_slice($parts, -6)));
            $port = (int) ($c[2] ?? 0);
            $ifIndex = $ports[$port] ?? 0;
            $rows[] = [$mac, $vlans && count($parts) > 6 ? (string) $parts[0] : '-', $port === 0 ? 'this device' : ($names[$ifIndex] ?? 'port ' . $port), self::FDB_STATUS[(int) ($c[3] ?? 1)] ?? (string) ($c[3] ?? '')];
        }
        usort($rows, static fn ($a, $b) => [$a[2], $a[0]] <=> [$b[2], $b[0]]);
        return self::result(['MAC address', 'VLAN', 'Port', 'Status'], $rows, 'The MAC table is empty, or the device does not publish it over SNMP (BRIDGE-MIB).', $truncated);
    }

    private static function bgp(object $snmp): array
    {
        $rows = [];
        foreach (self::columns($snmp, '1.3.6.1.2.1.15.3.1', [2, 3, 5, 9, 10, 11, 16]) as $peer => $c) {
            $admin = (int) ($c[3] ?? 2);
            [$text, $tone] = $admin === 1 ? ['disabled', ''] : (self::BGP_STATES[(int) ($c[2] ?? 0)] ?? ['unknown', 'warning']);
            $rows[] = [$peer, isset($c[9]) ? 'AS' . $c[9] : '-', ['text' => $text, 'tone' => $tone],
                // FsmEstablishedTime: time since the peer last came up or went down; 0 when it never came up.
                !isset($c[16]) ? '-' : ((int) $c[16] === 0 && $text !== 'established' ? 'never up' : self::duration((int) $c[16])),
                isset($c[10]) || isset($c[11]) ? ($c[10] ?? '-') . ' / ' . ($c[11] ?? '-') : '-', (string) ($c[5] ?? '-')];
        }
        return self::result(['Peer', 'Remote AS', 'State', 'In this state for', 'Updates in / out', 'Local address'], $rows, 'No BGP peers reported. Either BGP has no peers on this device, or it does not publish BGP over SNMP (BGP4-MIB).');
    }

    private static function ospf(object $snmp): array
    {
        $rows = [];
        foreach (self::columns($snmp, '1.3.6.1.2.1.14.10.1', [1, 3, 5, 6, 7]) as $c) {
            [$text, $tone] = self::OSPF_STATES[(int) ($c[6] ?? 0)] ?? ['unknown', 'warning'];
            $rows[] = [(string) ($c[1] ?? '-'), (string) ($c[3] ?? '-'), ['text' => $text, 'tone' => $tone], (string) ($c[5] ?? '-'), (string) ($c[7] ?? '-')];
        }
        return self::result(['Neighbour', 'Router ID', 'State', 'Priority', 'State changes'], $rows, 'No OSPF neighbours reported. Either OSPF has no neighbours on this device, or it does not publish OSPF over SNMP (OSPF-MIB).');
    }

    /**
     * Walks the given columns of a table; rows keyed by the index part of the OID.
     * @param list<int> $columns
     * @return array<string, array<int, mixed>>
     */
    private static function columns(object $snmp, string $entry, array $columns, bool &$truncated = false): array
    {
        $rows = [];
        foreach ($columns as $column) {
            $values = self::column($snmp, $entry . '.' . $column);
            $truncated = $truncated || count($values) >= self::LIMIT;
            foreach ($values as $index => $value) {
                $rows[$index][$column] = $value;
            }
        }
        return $rows;
    }

    /** @return array<string, mixed> index => value */
    private static function column(object $snmp, string $oid): array
    {
        $out = [];
        foreach ($snmp->walk($oid, self::LIMIT) as $full => $value) {
            $out[substr($full, strlen($oid) + 1)] = $value;
        }
        return $out;
    }

    /** @return array<int, string> ifIndex => name (ifName, else ifDescr) */
    private static function interfaces(object $snmp): array
    {
        $names = array_map('strval', self::column($snmp, self::IF_NAME));
        return $names ?: array_map('strval', self::column($snmp, self::IF_DESCR));
    }

    private static function macAddress(string $raw): string
    {
        return strlen($raw) === 6 ? implode(':', array_map(static fn ($b) => sprintf('%02X', ord($b)), str_split($raw))) : ($raw === '' ? 'incomplete' : bin2hex($raw));
    }

    private static function prefix(string $mask): string
    {
        $long = ip2long($mask);
        return $long === false ? '?' : (string) substr_count(decbin($long), '1');
    }

    private static function duration(int $seconds): string
    {
        return match (true) {
            $seconds < 120 => $seconds . ' s',
            $seconds < 7200 => round($seconds / 60) . ' min',
            $seconds < 172800 => round($seconds / 3600, 1) . ' h',
            default => round($seconds / 86400) . ' days',
        };
    }

    private static function result(array $columns, array $rows, string $empty, bool $truncated = false, string $note = ''): array
    {
        $rows = array_slice($rows, 0, self::LIMIT);
        // A column the device does not report at all ("-" in every row) is left out.
        foreach (array_reverse(array_keys($columns)) as $i) {
            if ($rows && !array_filter($rows, static fn ($r) => $r[$i] !== '-')) {
                array_splice($columns, $i, 1);
                $rows = array_map(static function ($r) use ($i) { array_splice($r, $i, 1); return $r; }, $rows);
            }
        }
        return ['columns' => $columns, 'rows' => $rows, 'truncated' => $truncated, 'empty' => $empty, 'note' => $note];
    }
}
