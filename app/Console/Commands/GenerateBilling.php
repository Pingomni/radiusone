<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\BillingRepository;
use App\Support\JobRunner;
use Throwable;

final class GenerateBilling extends FrwebCommand
{
    protected $signature = 'frweb:generate-billing {--username= : Only this customer}';
    protected $description = 'Generate due recurring invoices and mark overdue ones';

    public function handle(): int
    {
        $username = (string) $this->option('username');
        try {
            return $this->jobResult(JobRunner::run('billing', static fn () => BillingRepository::generateRecurringInvoices($username !== '' ? $username : null)));
        } catch (Throwable $e) {
            return $this->failure('Billing job failed', $e);
        }
    }
}
