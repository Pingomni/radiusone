# Laravel migration tracker

Side-by-side port of `/opt/freeradius-web` (plain PHP) to Laravel 13 + Inertia + React + TypeScript.
The legacy app keeps serving production until every row below is **done**; then nginx switches.

Decisions: same database schema (no data migration), same URLs, English only, domain classes in
`app/Support` reused unchanged (only `App`, `Auth`, `Db`, `Security` adapted to Laravel's
connection/session), `bin/*` workers become artisan commands run by the scheduler.

Status: **done** = controller + page + HTTP feature test; **todo** = not started.

## Routes

| Area | Routes | Status |
| --- | --- | --- |
| Auth | `/login`, `/two-factor`, `/two-factor/setup`, `/logout`, `/reauth` | done |
| Dashboard | `/`, `/dashboard`, `/fragments/dashboard-*` | done |
| Branding assets | `/uploads/branding/{file}` | done |
| Profile | `/profile`, `/profile/save`, `/profile/language`, `/profile/password`, `/profile/2fa/*` | done |
| Portal users | `/settings/users*`, `/portal-users*` | done |
| Settings | `/settings`, `/settings/branding/*`, `/settings/notifications/*` | done |
| Services | `/services*`, `/services/ip-pools*` | done |
| Network operations | `/operations`, `/operations/{id}/retry` | done |
| Reports / Logs | `/reports`, `/logs` | done |
| NAS | `/nas*`, `/nas/{id}/access`, `/nas/access/save`, `/nas/{id}/snmp` | done |
| Monitoring | `/monitoring*`, `/nas/{id}/monitor*` | done |
| Clients | `/clients*`, `/documents/*` | done |
| Billing | `/billing*` | done |

## Workers (`bin/*` → artisan)

All legacy scripts are artisan commands (`app/Console/Commands`, `php artisan list frweb`) with the same
exit codes (75 = another run holds the job lock). The schedule is in `routes/console.php`.

| Legacy script | Artisan command | Status |
| --- | --- | --- |
| generate-billing.php | `frweb:generate-billing` (scheduled 02:15 UTC) | done |
| sync-expired-services.php | `frweb:sync-expired` (scheduled only with `FRWEB_SCHEDULE_EXPIRY_SYNC=true`; paused in cron) | done |
| process-notifications.php | `frweb:process-notifications` (scheduled only with `FRWEB_SCHEDULE_EXPIRY_NOTIFICATIONS=true`; paused in cron) | done |
| process-customer-notifications.php | `frweb:process-customer-notifications` (every minute) | done |
| process-network-operations.php | `frweb:process-network-operations` (manual; `NETWORK_WORKER_ENABLED`) | done |
| poll-snmp.php | `frweb:poll-snmp` (every minute) | done |
| backup.php / restore.php | `frweb:backup` / `frweb:restore` (root cron, `FRWEB_OPERATOR_ENV_FILE=/etc/freeradius-web/backup.env`) | done |
| migrate.php / check-schema.php | `frweb:migrate` (`FRWEB_OPERATOR_ENV_FILE=/etc/freeradius-web/migration.env`) / `frweb:check-schema` | done |
| encrypt-secrets.php | `frweb:encrypt-secrets` | done |
| bootstrap-operator.php | `frweb:bootstrap-operator` | done |
| job-status.php, mark-nas-applied.php, document-inventory.php, seed.php | `frweb:job-status`, `frweb:mark-nas-applied`, `frweb:document-inventory`, `frweb:seed` | done |

## Cutover checklist

- [x] Every row above **done**, `tests/run.sh` green, `npm run build` clean
- [ ] Side-by-side check on 127.0.0.1:8081 against the live database
- [ ] Cron switched from `bin/*` to the scheduler, in one step so no job runs twice:
  - remove the www-data lines in `/etc/cron.d/freeradius-web-{customer-notifications,lifecycle,notifications,snmp}`
  - add `* * * * * www-data cd /opt/freeradius-web-laravel && /usr/bin/php artisan schedule:run >>/var/log/freeradius-web/scheduler.log 2>&1`
  - change root's backup line in `/etc/cron.d/freeradius-web` to `php artisan frweb:backup` (same env file)
- [ ] nginx `portal.example.com` root → `/opt/freeradius-web-laravel/public`
