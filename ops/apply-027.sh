#!/usr/bin/env bash
# Applies migration 027 (radacct index for monitoring) and the job_runs DELETE grant.
# Both are additive: one index on radacct, one grant; no row changes. Run as root:
#   sudo bash /opt/freeradius-web-laravel/ops/apply-027.sh
set -euo pipefail
cd /opt/freeradius-web-laravel

echo '== backup'
FRWEB_OPERATOR_ENV_FILE=/etc/freeradius-web/backup.env php artisan frweb:backup

echo '== migrate (frweb_migration identity)'
FRWEB_OPERATOR_ENV_FILE=/etc/freeradius-web/migration.env php artisan frweb:migrate
echo '-- second run (expect nothing applied)'
FRWEB_OPERATOR_ENV_FILE=/etc/freeradius-web/migration.env php artisan frweb:migrate

echo '== grant (MariaDB administrator)'
mariadb < docs/operations/job-runs-retention-grants.sql

echo '== verify (portal identity)'
sudo -u www-data php artisan frweb:check-schema
mariadb radius -N -e "SHOW INDEX FROM radacct WHERE Key_name='idx_radacct_nas_stop'" | wc -l | xargs -I{} echo 'idx_radacct_nas_stop columns: {}'
sudo -u www-data php artisan frweb:prune-job-runs
echo 'Done.'
