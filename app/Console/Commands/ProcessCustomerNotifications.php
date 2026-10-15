<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\CustomerNotifications;
use App\Support\JobRunner;
use Throwable;

final class ProcessCustomerNotifications extends FrwebCommand
{
    protected $signature = 'frweb:process-customer-notifications';
    protected $description = 'Deliver queued customer notifications (SMS/email)';

    public function handle(): int
    {
        try {
            $result = JobRunner::run('customer-notifications', static fn () => CustomerNotifications::process());
            $code = $this->jobResult($result);
            return $code === self::SUCCESS && ($result['summary']['uncertain'] ?? 0) > 0 ? self::FAILURE : $code;
        } catch (Throwable $e) {
            // Class name only, as before: delivery errors can carry gateway credentials or recipients.
            return $this->failure('Customer notification job failed', $e);
        }
    }
}
