#!/usr/bin/env bash
# Enables the Restart buttons on Monitoring → RADIUS server and the time zone switch on Settings → Regional:
# installs both root helpers and a sudoers rule that lets www-data restart exactly FreeRADIUS, MariaDB,
# Nginx and PHP-FPM, and schedule a time zone change (nothing else). Run as root:
#   sudo bash /opt/freeradius-web-laravel/ops/apply-service-control.sh
# Undo: rm /etc/sudoers.d/freeradius-web-services /usr/local/sbin/frweb-service-restart /usr/local/sbin/frweb-set-timezone
set -euo pipefail
helper=/usr/local/sbin/frweb-service-restart
timezone=/usr/local/sbin/frweb-set-timezone
rule=/etc/sudoers.d/freeradius-web-services

install -o root -g root -m 0755 /opt/freeradius-web-laravel/ops/frweb-service-restart "$helper"
install -o root -g root -m 0755 /opt/freeradius-web-laravel/ops/frweb-set-timezone "$timezone"

fpm=$(basename "$(ls /lib/systemd/system/php*-fpm.service | sort -V | tail -n 1)" .service)
tmp=$(mktemp)
cat > "$tmp" <<EOF
# Restart buttons in the RadiusWeb portal (ops/apply-service-control.sh). One line per allowed unit.
www-data ALL=(root) NOPASSWD: $helper freeradius, $helper mariadb, $helper nginx, $helper $fpm
www-data ALL=(root) NOPASSWD: $timezone schedule *
EOF
visudo -cf "$tmp" >/dev/null
install -o root -g root -m 0440 "$tmp" "$rule"
rm -f "$tmp"

echo "== $rule"
cat "$rule"
echo '== www-data may now run:'
sudo -l -U www-data | sed -n '/may run/,$p'
