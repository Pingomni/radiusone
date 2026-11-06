<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Contracts;
use App\Support\JobRunner;
use App\Support\PayPage;
use Throwable;

final class SyncPayments extends RadiusOneCommand
{
    protected $signature = 'radiusone:sync-payments';

    protected $description = 'Check open pay page payments with their service, and end contracts past their end date';

    public function handle(): int
    {
        try {
            $run = JobRunner::run('pay-sync', static fn () => ['paid' => PayPage::syncOpen(), 'ended' => Contracts::endExpired()]);
            return $this->jobResult($run);
        } catch (Throwable $e) {
            return $this->failure('Pay sync job failed', $e);
        }
    }
}
