<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\BillingRepository;
use App\Support\JobRunner;
use Illuminate\Support\Facades\Log;
use Throwable;

final class GenerateBilling extends RadiusOneCommand
{
    protected $signature = 'radiusone:generate-billing {--username= : Only this customer}';

    /** The name before RadiusOne 2.3, still accepted (cron lines, scripts). */
    protected $aliases = ['frweb:generate-billing'];
    protected $description = 'Generate due recurring invoices and mark overdue ones';

    public function handle(): int
    {
        $username = (string) $this->option('username');
        try {
            $run = JobRunner::run('billing', static fn () => BillingRepository::generateRecurringInvoices($username !== '' ? $username : null));
            foreach ($run['summary']['review'] ?? [] as $item) {
                Log::warning('Billing skipped ' . $item['username'] . ' (needs review): ' . $item['reason']);
            }
            return $this->jobResult($run);
        } catch (Throwable $e) {
            return $this->failure('Billing job failed', $e);
        }
    }
}
