<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\JobRunner;
use App\Support\RadiusRepository;
use Throwable;

final class SyncExpired extends RadiusOneCommand
{
    protected $signature = 'radiusone:sync-expired';

    /** The name before RadiusOne 2.3, still accepted (cron lines, scripts). */
    protected $aliases = ['frweb:sync-expired'];
    protected $description = 'Move expired customers to their expired service group';

    public function handle(): int
    {
        try {
            return $this->jobResult(JobRunner::run('expiry', static fn () => ['reconciled' => RadiusRepository::syncExpiredServiceGroups()]));
        } catch (Throwable $e) {
            return $this->failure('Expiry job failed', $e);
        }
    }
}
