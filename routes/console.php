<?php

use Illuminate\Support\Facades\Schedule;

// Replaces the www-data entries in /etc/cron.d/freeradius-web-* at cutover (one cron line runs
// `php artisan schedule:run` every minute). Each job keeps its own lock in job_runs (JobRunner), so
// overlapping runs exit 75 instead of running twice.
// Not scheduled here: frweb:backup runs from root's cron with FRWEB_OPERATOR_ENV_FILE=backup.env.

$log = static fn (string $name): string => '/var/log/freeradius-web/' . $name . '.log';

Schedule::command('frweb:process-customer-notifications')->everyMinute()->runInBackground()->appendOutputTo($log('customer-notifications'));
Schedule::command('frweb:poll-snmp')->everyMinute()->runInBackground()->appendOutputTo($log('snmp-poll'));
// Off until Settings → Notifications → Admin alerts is turned on (the command then checks every minute).
// FreeRADIUS loads the NAS list when it starts: NAS saved before that are in effect.
Schedule::command('frweb:mark-nas-applied --auto')->everyMinute()->runInBackground()->appendOutputTo($log('nas-applied'));
Schedule::command('frweb:admin-alerts')->everyMinute()->runInBackground()->appendOutputTo($log('admin-alerts'));
Schedule::command('frweb:generate-billing')->dailyAt('03:15')->appendOutputTo($log('billing'));
// Needs DELETE on job_runs (docs/operations/job-runs-retention-grants.sql).
Schedule::command('frweb:prune-job-runs')->dailyAt('03:30')->appendOutputTo($log('maintenance'));
// Deletes only when a period is chosen under Settings → Data retention (default: keep forever).
// Needs DELETE on radacct/radpostauth (docs/operations/radius-retention-grants.sql).
Schedule::command('frweb:prune-radius')->dailyAt('03:45')->appendOutputTo($log('maintenance'));

// Paused in the legacy cron (migration 018 rollout; notification delivery); enable explicitly.
Schedule::command('frweb:sync-expired')->everyMinute()->runInBackground()->appendOutputTo($log('expiry'))
    ->when(static fn () => config('app.schedule_expiry_sync'));
// Sends policy changes to the network: customers without an access profile who are online are
// disconnected (CoA or RouterOS API) so they reconnect with the new policy. Needs NETWORK_WORKER_ENABLED=true.
Schedule::command('frweb:process-network-operations')->everyMinute()->runInBackground()->appendOutputTo($log('network'))
    ->when(static fn () => filter_var(env('NETWORK_WORKER_ENABLED', false), FILTER_VALIDATE_BOOL));
Schedule::command('frweb:process-notifications')->everyFiveMinutes()->runInBackground()->appendOutputTo($log('notifications'))
    ->when(static fn () => config('app.schedule_expiry_notifications'));
