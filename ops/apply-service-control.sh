#!/usr/bin/env bash
# Enables the Restart buttons on Monitoring → RADIUS server, the time zone switch on Settings → Regional
# and Back up now on Settings → Backups: installs the root helpers and a sudoers rule that lets www-data restart exactly FreeRADIUS, MariaDB,
# Nginx and PHP-FPM, schedule a time zone change and start a backup (nothing else). Run as root:
#   sudo bash /opt/freeradius-web-laravel/ops/apply-service-control.sh
# Undo: rm /etc/sudoers.d/freeradius-web-services /usr/local/sbin/frweb-service-restart /usr/local/sbin/frweb-set-timezone /usr/local/sbin/frweb-backup-now
set -euo pipefail
helper=/usr/local/sbin/frweb-service-restart
timezone=/usr/local/sbin/frweb-set-timezone
backup=/usr/local/sbin/frweb-backup-now
rule=/etc/sudoers.d/freeradius-web-services

install -o root -g root -m 0755 /opt/freeradius-web-laravel/ops/frweb-service-restart "$helper"
install -o root -g root -m 0755 /opt/freeradius-web-laravel/ops/frweb-set-timezone "$timezone"
install -o root -g root -m 0755 /opt/freeradius-web-laravel/ops/frweb-backup-now "$backup"

fpm=$(basename "$(ls /lib/systemd/system/php*-fpm.service | sort -V | tail -n 1)" .service)
tmp=$(mktemp)
cat > "$tmp" <<EOF
# Restart buttons in the RadiusWeb portal (ops/apply-service-control.sh). One line per allowed unit.
www-data ALL=(root) NOPASSWD: $helper freeradius, $helper mariadb, $helper nginx, $helper $fpm
www-data ALL=(root) NOPASSWD: $timezone schedule *
www-data ALL=(root) NOPASSWD: $backup
EOF
visudo -cf "$tmp" >/dev/null
install -o root -g root -m 0440 "$tmp" "$rule"
rm -f "$tmp"

echo "== $rule"
cat "$rule"
echo '== www-data may now run:'
sudo -l -U www-data | sed -n '/may run/,$p'
