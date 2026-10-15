<?php

use Illuminate\Support\Facades\Schedule;

// Replaces the www-data entries in /etc/cron.d/freeradius-web-* at cutover (one cron line runs
// `php artisan schedule:run` every minute). Each job keeps its own lock in job_runs (JobRunner), so
// overlapping runs exit 75 instead of running twice.
// Not scheduled here: frweb:backup runs from root's cron with FRWEB_OPERATOR_ENV_FILE=backup.env,
// and frweb:process-network-operations stays manual until device acceptance.

$log = static fn (string $name): string => '/var/log/freeradius-web/' . $name . '.log';

Schedule::command('frweb:process-customer-notifications')->everyMinute()->runInBackground()->appendOutputTo($log('customer-notifications'));
Schedule::command('frweb:poll-snmp')->everyMinute()->runInBackground()->appendOutputTo($log('snmp-poll'));
Schedule::command('frweb:generate-billing')->dailyAt('02:15')->appendOutputTo($log('billing'));

// Paused in the legacy cron (migration 018 rollout; notification delivery); enable explicitly.
Schedule::command('frweb:sync-expired')->everyMinute()->runInBackground()->appendOutputTo($log('expiry'))
    ->when(static fn () => config('app.schedule_expiry_sync'));
Schedule::command('frweb:process-notifications')->everyFiveMinutes()->runInBackground()->appendOutputTo($log('notifications'))
    ->when(static fn () => config('app.schedule_expiry_notifications'));
