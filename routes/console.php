<?php

use App\Support\License;
use App\Support\Paths;
use Illuminate\Support\Facades\Schedule;

// One cron line (/etc/cron.d/radiusone) runs `php artisan schedule:run` every minute. Each job keeps its own lock in job_runs (JobRunner), so
// overlapping runs exit 75 instead of running twice.
// Not scheduled here: radiusone:backup runs from root's cron with RADIUSONE_OPERATOR_ENV_FILE=backup.env.

// RadiusOne Pro jobs (License::FEATURES) run while a licence covers them, also once it has expired.
$log = static fn (string $name): string => Paths::logs($name . '.log');

Schedule::command('radiusone:process-customer-notifications')->everyMinute()->runInBackground()->appendOutputTo($log('customer-notifications'))
    ->when(static fn () => License::allows('notifications'));
Schedule::command('radiusone:poll-snmp')->everyMinute()->runInBackground()->appendOutputTo($log('snmp-poll'))
    ->when(static fn () => License::allows('monitoring'));
// Off until Settings → Notifications → Admin alerts is turned on (the command then checks every minute).
// FreeRADIUS loads the NAS list when it starts: NAS saved before that are in effect.
Schedule::command('radiusone:mark-nas-applied --auto')->everyMinute()->runInBackground()->appendOutputTo($log('nas-applied'));
Schedule::command('radiusone:admin-alerts')->everyMinute()->runInBackground()->appendOutputTo($log('admin-alerts'))
    ->when(static fn () => License::allows('notifications'));
// Daily usage per client from RADIUS counters, then data caps (messages; slow-down via the expiry job).
Schedule::command('radiusone:collect-usage')->everyFiveMinutes()->runInBackground()->appendOutputTo($log('usage'));
Schedule::command('radiusone:generate-billing')->dailyAt('03:15')->appendOutputTo($log('billing'))
    ->when(static fn () => License::allows('billing'));
// Needs DELETE on job_runs (ops/install/grants.sql).
Schedule::command('radiusone:prune-job-runs')->dailyAt('03:30')->appendOutputTo($log('maintenance'));
// Deletes only when a period is chosen under Settings → Data retention (default: keep forever).
// Needs DELETE on radacct/radpostauth (ops/install/grants.sql).
Schedule::command('radiusone:prune-radius')->dailyAt('03:45')->appendOutputTo($log('maintenance'));

// The licence's server, registered with the PingOmni account when due (report only: never changes a Pro feature).
Schedule::command('license:check-in')->everyFiveMinutes()->runInBackground()->appendOutputTo($log('license'));

// Paused in the legacy cron (migration 018 rollout; notification delivery); enable explicitly.
Schedule::command('radiusone:sync-expired')->everyMinute()->runInBackground()->appendOutputTo($log('expiry'))
    ->when(static fn () => config('app.schedule_expiry_sync'));
// Sends policy changes to the network: customers without an access profile who are online are
// disconnected (CoA or RouterOS API) so they reconnect with the new policy. Needs NETWORK_WORKER_ENABLED=true.
Schedule::command('radiusone:process-network-operations')->everyMinute()->runInBackground()->appendOutputTo($log('network'))
    ->when(static fn () => filter_var(env('NETWORK_WORKER_ENABLED', false), FILTER_VALIDATE_BOOL));
Schedule::command('radiusone:process-notifications')->everyFiveMinutes()->runInBackground()->appendOutputTo($log('notifications'))
    ->when(static fn () => config('app.schedule_expiry_notifications') && License::allows('notifications'));
