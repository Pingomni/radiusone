#!/usr/bin/env bash
# Switches the nightly root backup to the new frweb:backup (database + documents, encrypted remote
# copies from Settings → Backups) and runs it once now so the Backups page shows a result. Run as root:
#   sudo bash /opt/freeradius-web-laravel/ops/apply-backups.sh
set -euo pipefail
cron=/etc/cron.d/freeradius-web
cp -a "$cron" "/var/backups/freeradius-web/cron-freeradius-web-$(date -u +%Y%m%dT%H%M%SZ)"

# The documents archive is now part of frweb:backup, so the separate tar line goes.
# LOG_CHANNEL=stderr: a root process must not create Laravel log files that www-data then cannot write.
cat > "$cron" <<'EOF'
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
# Database and customer documents; encrypted copies go to the destinations in Settings → Backups.
# Needs the read-only backup identity; the result is in /var/backups/freeradius-web/status.json.
15 2 * * * root cd /opt/freeradius-web-laravel && FRWEB_OPERATOR_ENV_FILE=/etc/freeradius-web/backup.env LOG_CHANNEL=stderr /usr/bin/php artisan frweb:backup >/var/log/freeradius-web-backup.log 2>&1
EOF
chmod 644 "$cron"
echo "== $cron"
grep -v '^#' "$cron"

echo '== running the backup once now'
cd /opt/freeradius-web-laravel
FRWEB_OPERATOR_ENV_FILE=/etc/freeradius-web/backup.env LOG_CHANNEL=stderr php artisan frweb:backup || true
ls -l /var/backups/freeradius-web/status.json
