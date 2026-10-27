<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\SchemaCompatibility;
use Throwable;

final class CheckSchema extends RadiusOneCommand
{
    protected $signature = 'radiusone:check-schema';

    /** The name before RadiusOne 2.3, still accepted (cron lines, scripts). */
    protected $aliases = ['frweb:check-schema'];
    protected $description = 'Report schema problems per feature (exit 1 when any)';

    public function handle(): int
    {
        try {
            $report = [];
            foreach (['billing', 'policy', 'notifications'] as $feature) {
                $report[$feature] = SchemaCompatibility::problems($feature);
            }
            $this->json($report, true);
            return array_filter($report) ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failure('Schema check unavailable', $e, false, 2);
        }
    }
}
