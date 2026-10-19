#!/usr/bin/env bash
# Applies migration 028 (nas_metadata.api_enabled: the NAS → API on/off switch). Additive: one column,
# switched on only for NAS rows that already have an API login. Run as root:
#   sudo bash /opt/freeradius-web-laravel/ops/apply-028.sh
set -euo pipefail
cd /opt/freeradius-web-laravel

echo '== backup'
FRWEB_OPERATOR_ENV_FILE=/etc/freeradius-web/backup.env php artisan frweb:backup --no-upload

echo '== migrate (frweb_migration identity)'
FRWEB_OPERATOR_ENV_FILE=/etc/freeradius-web/migration.env php artisan frweb:migrate

echo '== verify'
mariadb radius -t -e "SELECT n.shortname, m.vendor, m.coa_enabled, m.coa_port, m.api_enabled, m.api_user FROM nas n JOIN nas_metadata m ON m.nas_id=n.id ORDER BY n.shortname"
echo 'Done.'
