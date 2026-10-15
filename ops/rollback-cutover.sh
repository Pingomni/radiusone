#!/usr/bin/env bash
# Puts portal.example.com and its cron jobs back on the plain-PHP app (/opt/freeradius-web).
set -euo pipefail
backup=/var/backups/freeradius-web/cutover-20261015T195741Z
install -m 600 -o root -g root "$backup/nginx/freeradius-web" /etc/nginx/sites-available/freeradius-web
nginx -t
systemctl reload nginx
rm -f /etc/cron.d/freeradius-web-scheduler
for f in "$backup"/cron.d/*; do install -m 644 -o root -g root "$f" "/etc/cron.d/$(basename "$f")"; done
ls -l /etc/cron.d/ | grep freeradius-web
echo 'Rolled back to /opt/freeradius-web.'
