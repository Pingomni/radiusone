<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Support\Format;
use DateTimeImmutable;

/**
 * Daily usage and data caps.
 *
 * RADIUS keeps one running counter per session. Every few minutes collect() reads the open and recently
 * closed sessions, adds how much each counter grew since it was last seen to that day's row in usage_daily,
 * and remembers the new counters in usage_session_marks. A counter that went down (a NAS restart reusing
 * the session) counts from zero again. The first run fills usage_daily from the existing history, each
 * closed session on the day it last reported, like the client page used to.
 *
 * caps() then checks every customer on a plan with a data cap (service_metadata.total_quota, GB per cycle):
 * the cycle ends on the customer's expiry date and lasts the plan's billing period. At 80% and 100% the
 * customer gets the usage_warning / usage_reached message once per cycle; plans set to throttle also move
 * the customer to cap_service_group until the next cycle (the subscription is marked for republishing and
 * RadiusRepository::radiusServiceGroup() picks the slow plan).
 */
final class UsageCaps
{
    public const WARN_AT = 0.8;
    private const CURSOR = 'usage_collected_until';
    /** Closed sessions are read again for this long after the last run, so none is missed between runs. */
    private const OVERLAP = 900;
    private const BACKFILL_DAYS = 400;
    private const GB = 1073741824;

    /** @return array{sessions:int, days:int, backfilled:bool} */
    public static function collect(?int $now = null): array
    {
        $now ??= time();
        $cursor = Db::value('SELECT setting_value FROM settings WHERE setting_key=?', [self::CURSOR]);
        $cursor = $cursor === false || $cursor === null ? null : $cursor;
        $since = date('Y-m-d H:i:s', ($cursor !== null ? (int) $cursor : $now) - self::OVERLAP);
        $backfilled = false;
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            if ($cursor === null) {
                // First run: closed sessions from before the overlap window count once, on their last day.
                Db::exec(
                    'INSERT INTO usage_daily (username, day, down, up)
                     SELECT username, DATE(COALESCE(acctupdatetime, acctstoptime, acctstarttime)), SUM(COALESCE(acctoutputoctets, 0)), SUM(COALESCE(acctinputoctets, 0))
                     FROM radacct WHERE acctstoptime IS NOT NULL AND acctstoptime < ? AND acctstoptime >= DATE_SUB(?, INTERVAL ' . self::BACKFILL_DAYS . ' DAY)
                     GROUP BY username, DATE(COALESCE(acctupdatetime, acctstoptime, acctstarttime))
                     ON DUPLICATE KEY UPDATE down = down + VALUES(down), up = up + VALUES(up)',
                    [$since, $since]
                );
                $backfilled = true;
            }
            $sessions = Db::select(
                'SELECT radacctid, username, COALESCE(acctoutputoctets, 0) AS down, COALESCE(acctinputoctets, 0) AS up,
                        DATE(COALESCE(acctupdatetime, acctstoptime, acctstarttime)) AS day
                 FROM radacct WHERE acctstoptime IS NULL OR acctstoptime >= ?',
                [$since]
            );
            $marks = [];
            foreach (array_chunk(array_column($sessions, 'radacctid'), 1000) as $ids) {
                foreach (Db::select('SELECT radacctid, down, up FROM usage_session_marks WHERE radacctid IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $m) {
                    $marks[(int) $m['radacctid']] = $m;
                }
            }
            $days = [];
            $seenRows = [];
            $seen = date('Y-m-d H:i:s', $now);
            foreach ($sessions as $s) {
                $id = (int) $s['radacctid'];
                $down = (int) $s['down'];
                $up = (int) $s['up'];
                $before = $marks[$id] ?? null;
                $grewDown = $before === null || $down < (int) $before['down'] ? $down : $down - (int) $before['down'];
                $grewUp = $before === null || $up < (int) $before['up'] ? $up : $up - (int) $before['up'];
                if (($grewDown > 0 || $grewUp > 0) && $s['day'] !== null) {
                    $key = $s['username'] . "\0" . $s['day'];
                    $days[$key] = [$s['username'], $s['day'], ($days[$key][2] ?? 0) + $grewDown, ($days[$key][3] ?? 0) + $grewUp];
                }
                $seenRows[] = [$id, $down, $up, $seen];
            }
            foreach (array_chunk($seenRows, 500) as $chunk) {
                Db::exec('INSERT INTO usage_session_marks (radacctid, down, up, seen_at) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?)')) . ' ON DUPLICATE KEY UPDATE down = VALUES(down), up = VALUES(up), seen_at = VALUES(seen_at)', array_merge(...$chunk));
            }
            foreach (array_chunk(array_values($days), 500) as $chunk) {
                Db::exec('INSERT INTO usage_daily (username, day, down, up) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?)')) . ' ON DUPLICATE KEY UPDATE down = down + VALUES(down), up = up + VALUES(up)', array_merge(...$chunk));
            }
            // A session not seen for two days has closed long ago; its mark is no longer needed.
            Db::exec('DELETE FROM usage_session_marks WHERE seen_at < ?', [date('Y-m-d H:i:s', $now - 2 * 86400)]);
            Db::exec('INSERT INTO settings (setting_key, setting_value, is_secret, updated_by, updated_at) VALUES (?, ?, 0, NULL, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()', [self::CURSOR, (string) $now]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['sessions' => count($sessions), 'days' => count($days), 'backfilled' => $backfilled];
    }

    /** Cap in bytes from the plan's total_quota (GB); 0 = no cap. */
    public static function capBytes(?string $quota): int
    {
        $gb = trim((string) $quota);
        return is_numeric($gb) && (float) $gb > 0 ? (int) round((float) $gb * self::GB) : 0;
    }

    /**
     * The customer's current cycle: it ends on the expiry date (or the end of this month without one) and
     * lasts the plan's billing period; prepaid months ahead step back to the cycle that holds today.
     *
     * @return array{0:string, 1:string} [first day, last day]
     */
    public static function cycle(?string $expiry, string $period, string $today): array
    {
        $months = BillingCalendar::months(in_array($period, ['monthly', '2_months', '3_months', '6_months', 'yearly'], true) ? $period : 'monthly');
        $end = $expiry ? BillingCalendar::date($expiry) : (new DateTimeImmutable($today))->modify('last day of this month');
        $start = self::back($end, $months)->modify('+1 day');
        for ($i = 0; $i < 240 && $start->format('Y-m-d') > $today; $i++) {
            $end = $start->modify('-1 day');
            $start = self::back($end, $months)->modify('+1 day');
        }
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    /** The same day $months months earlier, clamped to that month's length (Mar 31 → Feb 28). */
    private static function back(DateTimeImmutable $day, int $months): DateTimeImmutable
    {
        $first = $day->modify('first day of this month')->modify("-$months months");
        return $first->setDate((int) $first->format('Y'), (int) $first->format('m'), min((int) $day->format('d'), (int) $first->format('t')));
    }

    /** @return array{checked:int, warned:int, reached:int, capped:int, released:int} */
    public static function caps(?string $today = null): array
    {
        $today ??= Entitlement::today();
        $result = ['checked' => 0, 'warned' => 0, 'reached' => 0, 'capped' => 0, 'released' => 0];
        $customers = Db::select(
            'SELECT c.id, c.username, c.expiry_date, s.total_quota, s.cap_action, s.cap_service_group, s.billing_period
             FROM customer_profiles c JOIN service_metadata s ON s.group_name = c.service_group
             WHERE c.account_status <> "archived" AND s.total_quota IS NOT NULL AND s.total_quota <> ""'
        );
        $kept = [];
        foreach ($customers as $c) {
            $cap = self::capBytes($c['total_quota']);
            if ($cap === 0) {
                continue;
            }
            $kept[] = (int) $c['id'];
            $result['checked']++;
            [$start, $end] = self::cycle($c['expiry_date'] ?: null, (string) $c['billing_period'], $today);
            $used = (int) Db::value(
                'SELECT COALESCE(SUM(d.down + d.up), 0) FROM usage_daily d
                 WHERE d.day BETWEEN ? AND ? AND d.username IN (SELECT username FROM customer_profiles WHERE id = ? UNION SELECT username FROM customer_identity_history WHERE customer_id = ?)',
                [$start, $end, $c['id'], $c['id']]
            );
            $throttle = $c['cap_action'] === 'throttle' && (string) $c['cap_service_group'] !== '';
            $pdo = Db::pdo();
            $pdo->beginTransaction();
            try {
                $row = Db::row('SELECT * FROM usage_cycles WHERE customer_id = ? FOR UPDATE', [$c['id']]);
                $fresh = !$row || $row['cycle_start'] !== $start;
                $warned = !$fresh && (int) $row['warned'] === 1;
                $reached = !$fresh && (int) $row['reached'] === 1;
                $wasCapped = $row && (int) $row['capped'] === 1;
                $extra = self::tokens($used, $cap, $end);
                if (!$warned && $used >= $cap * self::WARN_AT) {
                    $warned = true;
                    CustomerNotifications::usage('usage_warning', (int) $c['id'], $start, $extra);
                    $result['warned']++;
                }
                if (!$reached && $used >= $cap) {
                    $reached = true;
                    CustomerNotifications::usage('usage_reached', (int) $c['id'], $start, $extra);
                    $result['reached']++;
                }
                $capped = $throttle && $used >= $cap;
                Db::exec(
                    'INSERT INTO usage_cycles (customer_id, cycle_start, cycle_end, cap_bytes, used_bytes, warned, reached, capped, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE cycle_start = VALUES(cycle_start), cycle_end = VALUES(cycle_end), cap_bytes = VALUES(cap_bytes), used_bytes = VALUES(used_bytes), warned = VALUES(warned), reached = VALUES(reached), capped = VALUES(capped), updated_at = NOW()',
                    [$c['id'], $start, $end, $cap, $used, (int) $warned, (int) $reached, (int) $capped]
                );
                if ($capped !== $wasCapped) {
                    // The expiry job republishes the subscription within a minute: slow plan on, or back to normal.
                    Db::exec('UPDATE subscriptions SET dirty = 1 WHERE customer_id = ?', [$c['id']]);
                    Audit::log($capped ? 'usage_capped' : 'usage_released', 'client', (string) $c['username'], null, ['used' => $used, 'cap' => $cap, 'cycle' => $start . '/' . $end]);
                    $result[$capped ? 'capped' : 'released']++;
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
        // Customers no longer on a capped plan: forget their cycle, and lift a slow-down they still have.
        $stale = Db::select('SELECT customer_id, capped FROM usage_cycles' . ($kept ? ' WHERE customer_id NOT IN (' . implode(',', array_fill(0, count($kept), '?')) . ')' : ''), $kept);
        foreach ($stale as $s) {
            if ((int) $s['capped'] === 1) {
                Db::exec('UPDATE subscriptions SET dirty = 1 WHERE customer_id = ?', [$s['customer_id']]);
                $result['released']++;
            }
            Db::exec('DELETE FROM usage_cycles WHERE customer_id = ?', [$s['customer_id']]);
        }
        return $result;
    }

    /**
     * Whether the customer is slowed down right now (read by RadiusRepository when it picks the plan).
     *
     * @param  array<string, mixed>  $client
     */
    public static function cappedGroup(array $client, string $selectedGroup): ?string
    {
        if (empty($client['id'])) {
            return null;
        }
        $row = Db::row(
            'SELECT s.cap_service_group FROM usage_cycles u JOIN service_metadata s ON s.group_name = ?
             JOIN service_metadata slow ON slow.group_name = s.cap_service_group AND slow.enabled = 1
             WHERE u.customer_id = ? AND u.capped = 1 AND u.cycle_end >= ? AND s.cap_action = "throttle"',
            [$selectedGroup, $client['id'], Entitlement::today()]
        );
        return $row ? (string) $row['cap_service_group'] : null;
    }

    /**
     * The cycle shown on the client page, or null when the plan has no cap.
     *
     * @return array{start: string, end: string, cap: int, used: int, capped: bool, action: string, slowPlan: string|null}|null
     */
    public static function summary(int $customerId): ?array
    {
        $row = Db::row('SELECT u.*, s.cap_action, slow.name AS slow_name FROM usage_cycles u JOIN customer_profiles c ON c.id = u.customer_id JOIN service_metadata s ON s.group_name = c.service_group LEFT JOIN service_metadata slow ON slow.group_name = s.cap_service_group WHERE u.customer_id = ?', [$customerId]);
        if (!$row) {
            return null;
        }
        return [
            'start' => (string) $row['cycle_start'],
            'end' => (string) $row['cycle_end'],
            'cap' => (int) $row['cap_bytes'],
            'used' => (int) $row['used_bytes'],
            'capped' => (bool) $row['capped'],
            'action' => (string) $row['cap_action'],
            'slowPlan' => $row['slow_name'] !== null ? (string) $row['slow_name'] : null,
        ];
    }

    /**
     * Message placeholders: {used} {cap} {percent} {resets}.
     *
     * @return array{used: string, cap: string, percent: string, resets: string}
     */
    public static function tokens(int $used, int $cap, string $end): array
    {
        return [
            'used' => Format::bytes($used),
            'cap' => Format::bytes($cap),
            'percent' => (string) min(999, (int) floor($used * 100 / max(1, $cap))),
            'resets' => (new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d'),
        ];
    }
}
