<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * How long RADIUS session (radacct) and login (radpostauth) records are kept, chosen under
 * Settings → Data retention. "Keep forever" (0) is the default: nothing is deleted until an operator
 * picks a period. frweb:prune-radius applies the periods nightly in small batches.
 */
final class Retention
{
    /** days => label; 0 keeps everything */
    public const SESSIONS = [0 => 'Keep forever', 90 => '3 months', 180 => '6 months', 365 => '1 year', 730 => '2 years', 1095 => '3 years', 1825 => '5 years'];
    public const LOGINS = [0 => 'Keep forever', 30 => '30 days', 90 => '3 months', 180 => '6 months', 365 => '1 year', 730 => '2 years'];

    private const BATCH = 5000;

    /** @return array{sessions:int, logins:int} */
    public static function settings(): array
    {
        return [
            'sessions' => self::days('retention_sessions_days', self::SESSIONS),
            'logins' => self::days('retention_logins_days', self::LOGINS),
        ];
    }

    public static function save(int $sessions, int $logins): void
    {
        if (!isset(self::SESSIONS[$sessions]) || !isset(self::LOGINS[$logins])) {
            throw new RuntimeException('Choose one of the listed retention periods.');
        }
        Settings::save('retention_sessions_days', (string) $sessions);
        Settings::save('retention_logins_days', (string) $logins);
    }

    /**
     * Deletes records older than the chosen periods: closed sessions by stop time, sessions that stopped
     * reporting before the cut-off (never closed, so not live), and login records by time. Stops after
     * $maxSeconds and continues the next night.
     * @return array{sessions:int, logins:int, finished:bool, at:string}
     */
    public static function prune(int $maxSeconds = 600): array
    {
        $deadline = microtime(true) + $maxSeconds;
        $periods = self::settings();
        $result = ['sessions' => 0, 'logins' => 0, 'finished' => true, 'at' => date('Y-m-d H:i:s')];
        if ($periods['sessions'] > 0) {
            $cutoff = self::cutoff($periods['sessions']);
            $result['sessions'] += self::batches('DELETE FROM radacct WHERE acctstoptime < ? ORDER BY acctstoptime LIMIT ' . self::BATCH, $cutoff, $deadline, $result['finished']);
            $result['sessions'] += self::batches('DELETE FROM radacct WHERE acctstoptime IS NULL AND COALESCE(acctupdatetime, acctstarttime) < ? LIMIT ' . self::BATCH, $cutoff, $deadline, $result['finished']);
        }
        if ($periods['logins'] > 0) {
            $result['logins'] += self::batches('DELETE FROM radpostauth WHERE authdate < ? ORDER BY authdate LIMIT ' . self::BATCH, self::cutoff($periods['logins']), $deadline, $result['finished']);
        }
        Settings::save('retention_last_run', json_encode($result, JSON_THROW_ON_ERROR));
        if ($result['sessions'] > 0 || $result['logins'] > 0) {
            Audit::log('prune', 'radius_records', 'retention', null, $result + ['periods_days' => $periods]);
        }
        return $result;
    }

    /** Size, oldest record and the last cleanup, for the settings page. Row counts are table estimates. */
    public static function stats(): array
    {
        $estimate = static fn (string $table): int => (int) Db::value('SELECT table_rows FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$table]);
        $last = json_decode(Settings::value('retention_last_run', ''), true);
        return [
            'sessions' => ['rows' => $estimate('radacct'), 'oldest' => (string) (Db::value('SELECT MIN(acctstarttime) FROM radacct WHERE acctstarttime >= "2000-01-01"') ?: '')],
            'logins' => ['rows' => $estimate('radpostauth'), 'oldest' => (string) (Db::value('SELECT MIN(authdate) FROM radpostauth') ?: '')],
            'lastRun' => is_array($last) ? $last : null,
        ];
    }

    private static function days(string $key, array $allowed): int
    {
        $days = (int) Settings::value($key, '0');
        return isset($allowed[$days]) ? $days : 0;
    }

    private static function cutoff(int $days): string
    {
        return date('Y-m-d H:i:s', time() - $days * 86400);
    }

    private static function batches(string $sql, string $cutoff, float $deadline, bool &$finished): int
    {
        $deleted = 0;
        do {
            if (microtime(true) >= $deadline) {
                $finished = false;
                break;
            }
            $batch = Db::exec($sql, [$cutoff]);
            $deleted += $batch;
        } while ($batch === self::BATCH);
        return $deleted;
    }
}
