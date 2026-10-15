<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\DemoSeeder;
use Throwable;

final class Seed extends FrwebCommand
{
    protected $signature = 'frweb:seed {--demo : Required; inserts demo records and keeps existing ones}';
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
