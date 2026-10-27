<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Db;
use App\Support\NetworkOperations;
use App\Support\SchemaCompatibility;

final class JobStatus extends RadiusOneCommand
{
    protected $signature = 'radiusone:job-status';

    /** The name before RadiusOne 2.3, still accepted (cron lines, scripts). */
    protected $aliases = ['frweb:job-status'];
    protected $description = 'Recent job runs, schema state, network lag and notification queue';

    public function handle(): int
    {
        $report = ['jobs' => Db::select('SELECT * FROM job_runs ORDER BY id DESC LIMIT 20')];
        foreach (['billing', 'policy', 'notifications'] as $feature) {
            $report['schema'][$feature] = SchemaCompatibility::problems($feature);
        }
        if (!$report['schema']['policy']) {
            $report['network'] = NetworkOperations::lag();
        }
        if (!$report['schema']['notifications']) {
            $report['customer_notifications'] = Db::select('SELECT state,COUNT(*) AS count FROM notification_deliveries GROUP BY state');
        }
        $this->json($report, true);
        return self::SUCCESS;
    }
}
