<?php
declare(strict_types=1);
namespace App\Support;

/**
 * Whether each background job is actually running, from what it leaves behind (job_runs rows,
 * monitoring samples, the network outbox). Built-but-paused features are then shown as such instead
 * of looking active. States: running, idle, not_running, stuck, never.
 */
final class JobHealth
{
    /** job => [label, expected interval in seconds, what it does] */
    private const JOBS = [
        'expiry' => ['Expiry and policy publication', 60, 'Expires services and publishes access policies to RADIUS.'],
        'billing' => ['Recurring billing', 86400, 'Creates recurring invoices once a day.'],
        'customer-notifications' => ['Customer messages', 60, 'Sends queued customer email and SMS.'],
        'notifications' => ['Expiry reminders (legacy)', 300, 'Older expiry-reminder sender.'],
    ];

    /** @return array<string, array{label:string,state:string,ago:?int,detail:string,does:string}> */
    public static function all(?int $now = null): array
    {
        $now ??= time();
        $result = [];
        $last = [];
        try {
            // Newest first through idx_job_monitor (job_name, started_at): stops at the latest run
            // instead of grouping the whole history (a minute job adds ~525k rows a year).
            foreach (array_keys(self::JOBS) as $job) {
                $at = Db::value('SELECT UNIX_TIMESTAMP(started_at) FROM job_runs WHERE job_name=? AND state IN ("succeeded","skipped","running") ORDER BY started_at DESC LIMIT 1', [$job]);
                if ($at !== false && $at !== null) {
                    $last[$job] = (int) $at;
                }
            }
        } catch (\Throwable) {
            // Older schema without job_runs: every job reads as never run.
        }
        foreach (self::JOBS as $job => [$label, $every, $does]) {
            $result[$job] = self::byAge($label, $does, isset($last[$job]) ? $now - $last[$job] : null, $every);
        }
        // The SNMP poller records the RADIUS server (device 0) on every run.
        $snmp = null;
        try {
            $at = (int) Db::value('SELECT MAX(sampled_at) FROM nas_snmp_samples WHERE nas_id=0');
            $snmp = $at > 0 ? $now - $at : null;
        } catch (\Throwable) {
        }
        $result['snmp'] = self::byAge('Monitoring poller', 'Records server and NAS samples for Monitoring and its graphs.', $snmp, 60);
        // The network worker leaves no job_runs; its outbox shows whether it is sending.
        try {
            $lag = NetworkOperations::lag();
            $waiting = (int) ($lag['outstanding'] ?? 0);
            $oldest = (int) ($lag['oldest_seconds'] ?? 0);
            $result['network'] = ['label' => 'Network worker', 'does' => 'Sends queued router changes (speed changes, disconnects) to the NAS.', 'ago' => null] + match (true) {
                $waiting === 0 => ['state' => 'idle', 'detail' => 'Nothing waiting.'],
                $oldest > 600 => ['state' => 'stuck', 'detail' => $waiting . ' change' . ($waiting === 1 ? '' : 's') . ' waiting, oldest ' . self::age($oldest) . '. Nothing is being sent.'],
                default => ['state' => 'running', 'detail' => $waiting . ' change' . ($waiting === 1 ? '' : 's') . ' in progress.'],
            };
        } catch (\Throwable) {
            $result['network'] = ['label' => 'Network worker', 'does' => 'Sends queued router changes to the NAS.', 'ago' => null, 'state' => 'never', 'detail' => 'Not set up (policy schema missing).'];
        }
        return $result;
    }

    public static function age(int $seconds): string
    {
        return $seconds < 90 ? $seconds . ' s' : ($seconds < 5400 ? round($seconds / 60) . ' min' : ($seconds < 172800 ? round($seconds / 3600, 1) . ' h' : round($seconds / 86400, 1) . ' days'));
    }

    private static function byAge(string $label, string $does, ?int $ago, int $every): array
    {
        // Three missed runs (at least 5 minutes) means it is not running.
        $late = max(300, 3 * $every);
        return ['label' => $label, 'does' => $does, 'ago' => $ago] + match (true) {
            $ago === null => ['state' => 'never', 'detail' => 'Never run.'],
            $ago > $late => ['state' => 'not_running', 'detail' => 'Not running; last run ' . self::age($ago) . ' ago.'],
            default => ['state' => 'running', 'detail' => 'Last run ' . self::age($ago) . ' ago.'],
        };
    }
}
