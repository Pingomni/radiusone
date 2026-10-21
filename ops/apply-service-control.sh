#!/usr/bin/env bash
# Enables the Restart buttons on Monitoring → RADIUS server, the time zone switch on Settings → Regional,
# Back up now on Settings → Backups and Software update on Settings → Overview: installs the root helpers
# and a sudoers rule that lets www-data restart exactly FreeRADIUS, MariaDB, Nginx and PHP-FPM, schedule a
# time zone change, start a backup, and check for or start an update (nothing else). Run as root:
#   sudo bash <app>/ops/apply-service-control.sh
# Also installs frweb-root-artisan, which the helpers and the nightly backup use to run artisan as root (not
# in the sudoers rule), and points older root cron lines in /etc/cron.d (the nightly backup) at it.
# Undo: rm /etc/sudoers.d/freeradius-web-services /usr/local/sbin/frweb-service-restart /usr/local/sbin/frweb-set-timezone /usr/local/sbin/frweb-backup-now /usr/local/sbin/frweb-update /usr/local/sbin/frweb-root-artisan
set -euo pipefail
app=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
helper=/usr/local/sbin/frweb-service-restart
timezone=/usr/local/sbin/frweb-set-timezone
backup=/usr/local/sbin/frweb-backup-now
update=/usr/local/sbin/frweb-update
rule=/etc/sudoers.d/freeradius-web-services

# The helpers name the app folder; write in the one this script runs from.
for name in frweb-service-restart frweb-set-timezone frweb-backup-now frweb-update frweb-root-artisan; do
  tmp=$(mktemp)
  sed "s#/opt/freeradius-web-laravel#$app#g" "$app/ops/$name" > "$tmp"
  install -o root -g root -m 0755 "$tmp" "/usr/local/sbin/$name"
  rm -f "$tmp"
done

# Servers installed before frweb-root-artisan run root cron jobs in the app folder (the nightly backup) with
# plain php artisan, which loads the cache files www-data can write in bootstrap/cache. Any /etc/cron.d file
# may hold them (install.sh writes radiusone; hand-built servers use other names).
for cron in /etc/cron.d/*; do
  [[ -f $cron ]] && grep -qF " root cd $app " "$cron" || continue
  sed -i -e "\# root cd $app #s#/usr/bin/php artisan #/usr/local/sbin/frweb-root-artisan #" \
         -e "\# root cd $app #s#\([ ;&]\)php artisan #\1/usr/local/sbin/frweb-root-artisan #" "$cron"
done

fpm=$(basename "$(ls /lib/systemd/system/php*-fpm.service | sort -V | tail -n 1)" .service)
tmp=$(mktemp)
cat > "$tmp" <<EOF
# Restart buttons in the RadiusOne portal (ops/apply-service-control.sh). One line per allowed unit.
www-data ALL=(root) NOPASSWD: $helper freeradius, $helper mariadb, $helper nginx, $helper $fpm
www-data ALL=(root) NOPASSWD: $timezone schedule *
www-data ALL=(root) NOPASSWD: $backup
www-data ALL=(root) NOPASSWD: $update check, $update schedule *
EOF
visudo -cf "$tmp" >/dev/null
install -o root -g root -m 0440 "$tmp" "$rule"
rm -f "$tmp"

echo "== $rule"
cat "$rule"
echo '== www-data may now run:'
sudo -l -U www-data | sed -n '/may run/,$p'
