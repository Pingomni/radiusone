<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Db;
use Throwable;

final class PruneJobRuns extends FrwebCommand
{
    protected $signature = 'frweb:prune-job-runs {--days=30 : Keep this many days of history}';
    // Job health only needs the latest run; a minute job otherwise adds ~525k rows a year.
    protected $description = 'Delete finished job_runs history older than --days';

    public function handle(): int
    {
        $days = max(7, (int) $this->option('days'));
        try {
            $deleted = 0;
            do {
                // Oldest rows have the lowest ids, so each batch is found at the start of the primary key.
                $batch = Db::exec('DELETE FROM job_runs WHERE started_at < NOW() - INTERVAL ? DAY AND state <> "running" ORDER BY id LIMIT 10000', [$days]);
                $deleted += $batch;
            } while ($batch === 10000);
            $this->json(['deleted' => $deleted, 'kept_days' => $days]);
            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failure('Pruning job history failed', $e);
        }
    }
}
