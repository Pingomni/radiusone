<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AdminAlerts as Alerts;
use App\Support\Db;
use RuntimeException;
use Throwable;

final class AdminAlerts extends RadiusOneCommand
{
    protected $signature = 'radiusone:admin-alerts';

    /** The name before RadiusOne 2.3, still accepted (cron lines, scripts). */
    protected $aliases = ['frweb:admin-alerts'];
    protected $description = 'Alert the admin recipients about service, NAS, resource and job problems, and send the daily report';

    public function handle(): int
    {
        $lock = 'frweb:admin-alerts';
        if ((int) Db::value('SELECT GET_LOCK(?,0)', [$lock]) !== 1) {
            $this->line('Another admin-alerts run is still working.');
            return self::BUSY;
        }
        try {
            $this->json(Alerts::run());
            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->failure('Admin alerts failed', $e, $e instanceof RuntimeException);
        } finally {
            Db::value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }
}
