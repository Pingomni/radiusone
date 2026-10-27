<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\DemoSeeder;
use Throwable;

final class Seed extends RadiusOneCommand
{
    protected $signature = 'radiusone:seed {--demo : Required; inserts demo records and keeps existing ones}';

    /** The name before RadiusOne 2.3, still accepted (cron lines, scripts). */
    protected $aliases = ['frweb:seed'];
    protected $description = 'Insert-only demo data for a test install';

    public function handle(): int
    {
        if (!$this->option('demo')) {
            $this->getOutput()->getErrorStyle()->writeln('Explicit --demo is required; existing records are retained.');
            return self::FAILURE;
        }
        try {
            DemoSeeder::run();
            $this->line('Insert-only demo seed complete.');
            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failure('Seed failed', $e, true);
        }
    }
}
