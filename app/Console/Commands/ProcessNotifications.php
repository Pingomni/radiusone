<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\JobRunner;
use App\Support\NotificationService;
use Throwable;

final class ProcessNotifications extends FrwebCommand
{
    protected $signature = 'frweb:process-notifications';
    // There is deliberately no --force: it could not bypass a durable due time or an uncertain outcome.
    protected $description = 'Send due service-expiry notifications';

    public function handle(): int
    {
        try {
            $result = JobRunner::run('notifications', static fn () => NotificationService::processExpiryNotifications());
            $code = $this->jobResult($result);
            return $code === self::SUCCESS && ($result['summary']['failed'] ?? 0) > 0 ? self::FAILURE : $code;
        } catch (Throwable $e) {
            return $this->failure('Notification job failed', $e);
        }
    }
}
