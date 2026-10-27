<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\JobRunner;
use App\Support\UsageCaps;
use Throwable;

final class CollectUsage extends RadiusOneCommand
{
    protected $signature = 'radiusone:collect-usage';

    /** The name before RadiusOne 2.3, still accepted (cron lines, scripts). */
    protected $aliases = ['frweb:collect-usage'];
    protected $description = 'Record daily download/upload from RADIUS sessions and apply data caps';

    public function handle(): int
    {
        try {
            return $this->jobResult(JobRunner::run('usage', static fn () => UsageCaps::collect() + UsageCaps::caps()));
        } catch (Throwable $e) {
            return $this->failure('Usage job failed', $e);
        }
    }
}
