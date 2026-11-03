<?php

declare(strict_types=1);

namespace App\Support\Olt;

use App\Support\Snmp;
use RuntimeException;

/**
 * What a Huawei MA5800 says about its GPON ONUs, read over SNMP and from "display mac-address". Only reads.
 * Rows are indexed <ifIndex of the PON port>.<ONT ID>. Values the OLT does not have stay null, and the page shows "-".
 */
final class HuaweiMa5800
{
    private const BASE = '1.3.6.1.4.1.2011.6.128.1.1.2';
    private const IF_DESCR = '1.3.6.1.2.1.2.2.1.2';
    /** The OLT's value for "no reading", for example the optical power of an offline ONU. */
    private const NO_READING = 2147483647;
    /** Enough for a full X7: 7 boards × 16 ports × 128 ONUs, and then some. */
    private const WALK_LIMIT = 200000;

    private const COLUMNS = [
        'sn' => '43.1.3',
        'line_profile' => '43.1.7',
        'service_profile' => '43.1.8',
        'description' => '43.1.9',
        'onu_model' => '45.1.4',
        'status' => '46.1.15',
        'distance_m' => '46.1.20',
        'mac_count' => '46.1.21',
        'last_up_at' => '46.1.22',
        'last_down_at' => '46.1.23',
        'down_cause' => '46.1.24',
        'temperature_c' => '51.1.1',
        'rx_dbm' => '51.1.4',
        'olt_rx_dbm' => '51.1.6',
    ];

    private const DOWN_CAUSES = [
        1 => 'Fibre: loss of signal (LOS)', 2 => 'Fibre: LOSi/LOBi', 3 => 'Fibre: LOFI', 4 => 'SFI', 5 => 'LOAI', 6 => 'LOAMI',
        7 => 'Deactivate failed', 8 => 'Deactivated', 9 => 'ONU reset', 10 => 'ONU re-registered', 11 => 'Pop-up failed',
        13 => 'Power off (dying gasp)', 15 => 'LOKI', 18 => 'Deactivated by ring protection', 30 => 'Optical module shut down',
        31 => 'Reset by command', 32 => 'Reset by its button', 33 => 'Reset by its software', 34 => 'Deactivated: broadcast attack',
        35 => 'Operator check failed', 37 => 'Rogue ONU detected',
    ];

    /** @return list<array<string, mixed>> every ONU the OLT has configured */
    public static function readOnus(Snmp $snmp): array
    {
        $ports = self::ports($snmp->walk(self::IF_DESCR, 5000));
        $values = [];
        foreach (self::COLUMNS as $name => $column) {
            $root = self::BASE . '.' . $column;
            foreach ($snmp->walk($root, self::WALK_LIMIT) as $oid => $value) {
                $values[substr((string) $oid, strlen($root) + 1)][$name] = $value;
            }
        }
        $onus = [];
        foreach ($values as $index => $row) {
            $onu = self::onu((string) $index, $row, $ports);
            if ($onu !== null) {
                $onus[] = $onu;
            }
        }
        return $onus;
    }

    /**
     * @param  array<string, mixed>  $descriptions
     * @return array<int, array{int,int,int}> ifIndex => frame, slot, port
     */
    private static function ports(array $descriptions): array
    {
        $ports = [];
        foreach ($descriptions as $oid => $text) {
            if (is_string($text) && preg_match('~pon[^0-9]*(\d+)\s*/\s*(\d+)\s*/\s*(\d+)~i', $text, $match)) {
                $ports[(int) substr((string) $oid, strrpos((string) $oid, '.') + 1)] = [(int) $match[1], (int) $match[2], (int) $match[3]];
            }
        }
        return $ports;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, array{int,int,int}>  $ports
     * @return array<string, mixed>|null
     */
    private static function onu(string $index, array $row, array $ports): ?array
    {
        if (!preg_match('/^(\d+)\.(\d+)$/D', $index, $match) || !isset($row['sn'])) {
            return null;
        }
        $sn = self::serial((string) $row['sn']);
        $position = $ports[(int) $match[1]] ?? self::positionFromIndex((int) $match[1]);
        if ($sn === '' || $position === null) {
            return null;
        }
        return [
            'frame' => $position[0], 'slot' => $position[1], 'port' => $position[2], 'ont_id' => (int) $match[2],
            'sn' => $sn,
            'description' => self::text($row['description'] ?? '', 190),
            'onu_model' => self::text($row['onu_model'] ?? '', 64),
            'line_profile' => self::text($row['line_profile'] ?? '', 64),
            'service_profile' => self::text($row['service_profile'] ?? '', 64),
            'status' => match ((int) ($row['status'] ?? 0)) {
                1 => 'online', 2 => 'offline', default => 'unknown'
            },
            'down_cause' => self::DOWN_CAUSES[(int) ($row['down_cause'] ?? 0)] ?? '',
            'last_up_at' => self::dateTime($row['last_up_at'] ?? null),
            'last_down_at' => self::dateTime($row['last_down_at'] ?? null),
            'distance_m' => self::number($row['distance_m'] ?? null, 0, 100000),
            'mac_count' => self::number($row['mac_count'] ?? null, 0, 100000),
            'temperature_c' => self::number($row['temperature_c'] ?? null, -60, 150),
            'rx_dbm' => self::power($row['rx_dbm'] ?? null, 0),
            'olt_rx_dbm' => self::power($row['olt_rx_dbm'] ?? null, 10000),
        ];
    }

    /**
     * The usual MA5800 numbering, for a PON port whose name did not say where it is.
     *
     * @return array{int,int,int}|null
     */
    private static function positionFromIndex(int $ifIndex): ?array
    {
        $offset = $ifIndex - 4194304000;
        return $offset >= 0 && $offset % 256 === 0 ? [0, (int) ($offset / 8192), (int) (($offset % 8192) / 256)] : null;
    }

    /** Eight bytes on the wire: four letters of vendor, then four bytes shown as hex. HWTC1234ABCD. */
    public static function serial(string $raw): string
    {
        if (strlen($raw) === 8 && preg_match('/^[A-Za-z0-9]{4}/', $raw)) {
            return strtoupper(substr($raw, 0, 4) . bin2hex(substr($raw, 4)));
        }
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $raw) ?? '');
    }

    public static function normalizeSerial(string $typed): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $typed) ?? '');
    }

    private static function text(mixed $raw, int $length): string
    {
        $text = is_scalar($raw) ? trim((string) $raw, " \t\r\n\0") : '';
        $text = (string) preg_replace('/[\x00-\x1f\x7f]/', '', mb_scrub($text, 'UTF-8'));
        return mb_substr($text, 0, $length);
    }

    private static function number(mixed $raw, int $min, int $max): ?int
    {
        if (!is_numeric($raw) || (int) $raw === self::NO_READING || (int) $raw < $min || (int) $raw > $max) {
            return null;
        }
        return (int) $raw;
    }

    /** Hundredths of a dBm. The OLT adds 10000 to the power it receives from the ONU. */
    private static function power(mixed $raw, int $offset): ?float
    {
        if (!is_numeric($raw) || (int) $raw === self::NO_READING) {
            return null;
        }
        $dbm = ((int) $raw - $offset) / 100;
        return $dbm >= -60 && $dbm <= 20 ? round($dbm, 2) : null;
    }

    /** An SNMP DateAndTime (RFC 2579): the OLT's own clock, so it is kept as it was read. */
    private static function dateTime(mixed $raw): ?string
    {
        if (!is_string($raw) || !in_array(strlen($raw), [8, 11], true)) {
            return null;
        }
        $b = array_values(unpack('C*', $raw) ?: []);
        $year = $b[0] * 256 + $b[1];
        if ($year < 2000 || $year > 2100 || !checkdate($b[2], $b[3], $year) || $b[4] > 23 || $b[5] > 59 || $b[6] > 60) {
            return null;
        }
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $b[2], $b[3], $b[4], $b[5], $b[6]);
    }

    /**
     * The MACs behind ONUs from "display mac-address all". On a GPON row the VPI column is the ONT ID and VCI the GEM
     * port. Other rows, such as MACs learned on the uplink, are not behind an ONU and are left out.
     *
     * @return list<array{mac:string,vlan:int,frame:int,slot:int,port:int,ont_id:int}>
     *
     * @throws RuntimeException when the output is not a MAC table, so an old list is never wiped by an error message
     */
    public static function parseMacTable(string $output): array
    {
        // The OLT prints the next page straight after its "More" line, having moved the cursor back over it with
        // terminal codes, so the first row of each page shares that line.
        $output = (string) preg_replace('/\x1b\[[0-9;?]*[A-Za-z]/', '', str_replace("\r", '', $output));
        $output = (string) preg_replace('/-+\s*more\s*\([^)]*\)\s*-*/i', "\n", $output);
        if (!preg_match('~F\s*/\s*S\s*/\s*P~i', $output) && !preg_match('/(there is not any|no) .*mac/i', $output)) {
            throw new RuntimeException('The OLT did not answer with a MAC table: ' . mb_substr(trim($output), -200));
        }
        $rows = [];
        foreach (explode("\n", $output) as $line) {
            if (preg_match('~^\s*\S+\s+\S+\s+(\S*pon)\s+([0-9a-f]{4})-([0-9a-f]{4})-([0-9a-f]{4})\s+\S+\s+(\d+)\s*/\s*(\d+)\s*/\s*(\d+)\s+(\d+)\s+\S+\s+(\d+)\s*$~i', $line, $m)) {
                $rows[] = ['mac' => strtolower($m[2] . $m[3] . $m[4]), 'vlan' => (int) $m[9], 'frame' => (int) $m[5], 'slot' => (int) $m[6], 'port' => (int) $m[7], 'ont_id' => (int) $m[8]];
            }
        }
        // Rows there, but none understood: a different firmware's layout. An empty list would look like "no MACs".
        if (!$rows && preg_match('/^.*\\S*pon\\s+[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}.*$/mi', $output, $line)) {
            throw new RuntimeException('This OLT prints its MAC table in a layout the portal does not understand: ' . mb_substr(trim($line[0]), 0, 120));
        }

        return $rows;
    }
}
