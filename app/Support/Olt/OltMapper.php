<?php

declare(strict_types=1);

namespace App\Support\Olt;

use App\Support\Accounting;
use App\Support\Audit;
use App\Support\Db;
use RuntimeException;

/**
 * Which client is on which ONU. Automatic links are made only when the evidence points to exactly one ONU, and never
 * replace an existing link: when the evidence disagrees with a link, the mapping page shows it for a person to decide.
 */
final class OltMapper
{
    public const SOURCES = ['manual' => 'Linked by hand', 'mac' => 'Found from the session MAC', 'description' => 'ONU description matches the username'];

    /** 12 lowercase hex digits, or null when a Caller ID is not a MAC. */
    public static function mac(?string $raw): ?string
    {
        $hex = strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string) $raw) ?? '');
        return strlen($hex) === 12 && preg_match('/^[0-9a-f:.\- ]+$/i', (string) $raw) ? $hex : null;
    }

    /**
     * What points each online client to an ONU: the session's MAC found behind an ONU.
     *
     * @return array<int, array{mac:string,onus:list<array<string, mixed>>}> customer id => evidence
     */
    public static function macEvidence(): array
    {
        $sessions = Db::select('SELECT c.id AS customer_id,r.callingstationid FROM radacct r JOIN customer_profiles c ON c.username=r.username WHERE c.account_status<>"archived" AND ' . Accounting::freshSql('r'));
        if (!$sessions) {
            return [];
        }
        $onusByMac = [];
        foreach (Db::select('SELECT DISTINCT m.mac,o.* FROM olt_macs m JOIN olt_onus o ON o.olt_id=m.olt_id AND o.frame=m.frame AND o.slot=m.slot AND o.port=m.port AND o.ont_id=m.ont_id') as $row) {
            $onusByMac[$row['mac']][(int) $row['id']] = $row;
        }
        $evidence = [];
        foreach ($sessions as $session) {
            $mac = self::mac($session['callingstationid']);
            if ($mac !== null && isset($onusByMac[$mac])) {
                $evidence[(int) $session['customer_id']] = ['mac' => $mac, 'onus' => array_values($onusByMac[$mac])];
            }
        }
        return $evidence;
    }

    /** @return int how many clients were linked */
    public static function linkAutomatically(): int
    {
        $linked = 0;
        foreach (self::macEvidence() as $customerId => $evidence) {
            if (count($evidence['onus']) === 1) {
                $linked += self::autoLink($customerId, $evidence['onus'][0], 'mac', self::formatMac($evidence['mac']));
            }
        }
        // Exact matches only, and only when the description belongs to one ONU: a shared or default text proves nothing.
        foreach (Db::select('SELECT c.id,c.username,MIN(o.id) AS onu_id FROM customer_profiles c JOIN olt_onus o ON o.description=c.username LEFT JOIN customer_onus l ON l.customer_id=c.id
            WHERE l.customer_id IS NULL AND c.account_status<>"archived" GROUP BY c.id,c.username HAVING COUNT(*)=1') as $row) {
            $onu = Db::row('SELECT * FROM olt_onus WHERE id=?', [$row['onu_id']]);
            if ($onu !== null) {
                $linked += self::autoLink((int) $row['id'], $onu, 'description', $onu['description']);
            }
        }
        return $linked;
    }

    /**
     * @param  array<string, mixed>  $onu
     */
    private static function autoLink(int $customerId, array $onu, string $source, string $evidence): int
    {
        $added = Db::exec('INSERT IGNORE INTO customer_onus (customer_id,olt_id,sn,source,evidence,linked_by,linked_at) VALUES (?,?,?,?,?,"system",NOW())', [$customerId, $onu['olt_id'], $onu['sn'], $source, mb_substr($evidence, 0, 190)]);
        if ($added === 1) {
            Audit::log('link_onu', 'client', (string) Db::value('SELECT username FROM customer_profiles WHERE id=?', [$customerId]), null, ['sn' => $onu['sn'], 'source' => $source, 'evidence' => $evidence]);
        }
        return $added;
    }

    /**
     * Links by hand, replacing any link the client had.
     *
     * @return array<string, mixed>
     */
    public static function linkBySerial(int $customerId, string $username, string $typed, string $actor): array
    {
        $sn = HuaweiMa5800::normalizeSerial($typed);
        if ($sn === '') {
            throw new RuntimeException('Enter the ONU serial number.');
        }
        $onus = Db::select('SELECT * FROM olt_onus WHERE sn=?', [$sn]);
        if (!$onus) {
            throw new RuntimeException('No ONU with serial number ' . $sn . ' was found. The list updates every 5 minutes.');
        }
        if (count($onus) > 1) {
            throw new RuntimeException('Serial number ' . $sn . ' is on more than one OLT. Check the OLTs first.');
        }
        $before = Db::row('SELECT olt_id,sn,source FROM customer_onus WHERE customer_id=?', [$customerId]);
        Db::exec('INSERT INTO customer_onus (customer_id,olt_id,sn,source,evidence,linked_by,linked_at) VALUES (?,?,?,"manual","",?,NOW())
            ON DUPLICATE KEY UPDATE olt_id=VALUES(olt_id),sn=VALUES(sn),source="manual",evidence="",linked_by=VALUES(linked_by),linked_at=NOW()', [$customerId, $onus[0]['olt_id'], $sn, $actor]);
        Audit::log('link_onu', 'client', $username, $before, ['sn' => $sn, 'source' => 'manual']);
        return $onus[0];
    }

    public static function unlink(int $customerId, string $username): void
    {
        $before = Db::row('SELECT olt_id,sn,source FROM customer_onus WHERE customer_id=?', [$customerId]);
        if ($before) {
            // Also stops the next sync linking it straight back from the same evidence.
            Db::exec('UPDATE customer_onus SET source="none",sn="",evidence="",linked_by=?,linked_at=NOW() WHERE customer_id=?', [$username, $customerId]);
            Audit::log('unlink_onu', 'client', $username, $before);
        }
    }

    public static function formatMac(string $hex): string
    {
        return strtoupper(implode(':', str_split($hex, 2)));
    }

    /**
     * The ONU linked to a client, for the client's page.
     *
     * @return array{link: array<string, mixed>, onu: array<string, mixed>|null}|null
     */
    public static function forCustomer(int $customerId): ?array
    {
        $link = Db::row('SELECT l.*,t.name AS olt_name FROM customer_onus l JOIN olts t ON t.id=l.olt_id WHERE l.customer_id=? AND l.source<>"none"', [$customerId]);
        if (!$link) {
            return null;
        }
        return ['link' => $link, 'onu' => Db::row('SELECT * FROM olt_onus WHERE olt_id=? AND sn=?', [$link['olt_id'], $link['sn']])];
    }
}
