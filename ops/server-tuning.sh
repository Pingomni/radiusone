#!/usr/bin/env bash
# Server settings for 5000+ clients (see the capacity test in tests/Load). Run as root:
#   sudo bash /opt/freeradius-web-laravel/ops/server-tuning.sh
# No MariaDB restart: every database setting is applied online and also saved for the next restart.
# PHP-FPM and nginx are reloaded gracefully; a config that fails its test is not loaded.
set -euo pipefail

backup=/var/backups/freeradius-web/tuning-$(date -u +%Y%m%dT%H%M%SZ)
mkdir -p "$backup"
cp -a /etc/php/8.3/fpm/pool.d/www.conf "$backup/www.conf"
cp -a /etc/nginx/sites-available/freeradius-web "$backup/freeradius-web.nginx"
echo "== previous config copied to $backup"

echo '== MariaDB: 3 GB buffer pool, 512 MB redo log, slow query log (>1 s)'
install -d -o mysql -g adm -m 2750 /var/log/mysql
cat > /etc/mysql/mariadb.conf.d/60-frweb-tuning.cnf <<'EOF'
# freeradius-web: sized for 5000+ clients on a 12 GB server (ops/server-tuning.sh).
[mariadbd]
innodb_buffer_pool_size = 3G
innodb_log_file_size = 512M
slow_query_log = 1
slow_query_log_file = /var/log/mysql/mariadb-slow.log
long_query_time = 1
EOF
mariadb -e "SET GLOBAL innodb_buffer_pool_size = 3221225472;
            SET GLOBAL innodb_log_file_size = 536870912;
            SET GLOBAL slow_query_log_file = '/var/log/mysql/mariadb-slow.log';
            SET GLOBAL long_query_time = 1;
            SET GLOBAL slow_query_log = 1;"

echo '== PHP-FPM: 20 workers (about 50 MB each), recycled after 500 requests'
sed -i -E 's/^pm\.max_children = .*/pm.max_children = 20/;
           s/^pm\.start_servers = .*/pm.start_servers = 4/;
           s/^pm\.min_spare_servers = .*/pm.min_spare_servers = 2/;
           s/^pm\.max_spare_servers = .*/pm.max_spare_servers = 6/;
           s/^;?pm\.max_requests = .*/pm.max_requests = 500/' /etc/php/8.3/fpm/pool.d/www.conf

echo '== Uploads: 10 MB per file, 24 MB per request (two ID documents of up to 8 MB on the client form)'
cat > /etc/php/8.3/fpm/conf.d/99-frweb.ini <<'EOF'
; freeradius-web (ops/server-tuning.sh): the app accepts ID documents up to 8 MB (DOCUMENT_MAX_UPLOAD_MB).
upload_max_filesize = 10M
post_max_size = 24M
EOF
sed -i 's/client_max_body_size 10m;/client_max_body_size 24m;/' /etc/nginx/sites-available/freeradius-web

php-fpm8.3 -t
nginx -t
systemctl reload php8.3-fpm
systemctl reload nginx

echo '== verify'
mariadb -N -e "SELECT CONCAT('buffer pool ', @@innodb_buffer_pool_size DIV 1048576, ' MB, redo log ', @@innodb_log_file_size DIV 1048576, ' MB, slow log ', IF(@@slow_query_log, 'on', 'off'), ' (', @@slow_query_log_file, ')')"
grep -E '^pm\.(max_children|start_servers|min_spare_servers|max_spare_servers|max_requests)' /etc/php/8.3/fpm/pool.d/www.conf
php-fpm8.3 -i 2>/dev/null | grep -E '^(upload_max_filesize|post_max_size) ' || true
grep -n 'client_max_body_size' /etc/nginx/sites-available/freeradius-web
printf 'login page: %s\n' "$(curl -s -o /dev/null -w '%{http_code}' -H 'Host: portal.example.com' -H 'X-Forwarded-Proto: https' http://127.0.0.1/login)"
echo 'Done.'
