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
| Network operations | `/operations`, `/operations/{id}/retry` | todo |
| Reports / Logs | `/reports`, `/logs` | todo |
| NAS | `/nas*`, `/nas/{id}/access`, `/nas/access/save`, `/nas/{id}/snmp` | todo |
| Monitoring | `/monitoring*`, `/nas/{id}/monitor*` | todo |
| Clients | `/clients*`, `/documents/*` | todo |
| Billing | `/billing*` | todo |

## Workers (`bin/*` → artisan)

| Legacy script | Artisan command | Status |
| --- | --- | --- |
| generate-billing.php | `frweb:generate-billing` | todo |
| sync-expired-services.php | `frweb:sync-expired` | todo |
| process-notifications.php | `frweb:process-notifications` | todo |
| process-customer-notifications.php | `frweb:process-customer-notifications` | todo |
| process-network-operations.php | `frweb:process-network-operations` | todo |
| poll-snmp.php | `frweb:poll-snmp` | todo |
| backup.php / restore.php | `frweb:backup` / `frweb:restore` | todo |
| migrate.php / check-schema.php | `frweb:migrate` / `frweb:check-schema` | todo |
| encrypt-secrets.php | `frweb:encrypt-secrets` | todo |
| bootstrap-operator.php | `frweb:bootstrap-operator` | todo |
| job-status.php, mark-nas-applied.php, document-inventory.php, seed.php | matching `frweb:*` | todo |

## Cutover checklist

- [ ] Every row above **done**, `tests/run.sh` green, `npm run build` clean
- [ ] Side-by-side check on 127.0.0.1:8081 against the live database
- [ ] Cron/systemd timers switched from `bin/*` to `php artisan schedule:run`
- [ ] nginx `portal.example.com` root → `/opt/freeradius-web-laravel/public`
