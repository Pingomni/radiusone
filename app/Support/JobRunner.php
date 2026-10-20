<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use RuntimeException;

final class JobRunner
{
    public static function run(string $job, Closure $work): array
    {
        SchemaCompatibility::require($job === 'customer-notifications' ? 'notifications' : ($job === 'expiry' ? 'policy' : 'billing'));
        if (!in_array($job, ['billing', 'expiry', 'notifications', 'customer-notifications'], true)) {
            throw new RuntimeException('Unknown lifecycle job.');
        }
        $lock = (in_array($job, ['notifications', 'customer-notifications'], true) ? 'frweb:' . $job . ':' : 'frweb:lifecycle:') . substr(hash('sha256', (string) Db::value('SELECT DATABASE()')), 0, 40);
        $acquired = Db::value('SELECT GET_LOCK(?,0)', [$lock]);
        if ($acquired === null || $acquired === false) {
            throw new RuntimeException('Lifecycle lock unavailable.');
        }
        if ((int) $acquired !== 1) {
            Db::exec('INSERT INTO job_runs (job_name,state,started_at,finished_at) VALUES (?,"skipped",NOW(),NOW())', [$job]);
            return ['state' => 'skipped', 'reason' => 'Another lifecycle job holds the lock.'];
        }
        $id = null;
        try {
            // No previous holder is alive once this session owns the shared lock.
            Db::exec('UPDATE job_runs SET state="failed",finished_at=NOW(),error_class="InterruptedWorker" WHERE state="running" AND ' . (in_array($job, ['notifications', 'customer-notifications'], true) ? 'job_name="' . $job . '"' : 'job_name IN ("billing","expiry")'));
            Db::exec('INSERT INTO job_runs (job_name,state,started_at) VALUES (?,"running",NOW())', [$job]);
            $id = (int) Db::pdo()->lastInsertId();
            $summary = $work();
            Db::exec('UPDATE job_runs SET state="succeeded",finished_at=NOW(),summary_json=? WHERE id=?', [json_encode($summary, JSON_THROW_ON_ERROR), $id]);
            return ['state' => 'succeeded', 'run_id' => $id, 'summary' => $summary];
        } catch (\Throwable $e) {
            if ($id !== null) {
                Db::exec('UPDATE job_runs SET state="failed",finished_at=NOW(),error_class=? WHERE id=?', [get_class($e), $id]);
            }throw $e;
        } finally {
            Db::value('SELECT RELEASE_LOCK(?)',[$lock]);
        }
    }
}
