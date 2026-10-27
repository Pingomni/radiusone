#!/usr/bin/env bash
# RadiusOne installer: sets up a complete, running system on a fresh Ubuntu 24.04 server.
#
#   a release (radiusone-X.Y.Z.tar.gz, libraries and pages included):
#     mkdir /opt/radiusone && tar -xzf radiusone-X.Y.Z.tar.gz -C /opt/radiusone --strip-components=1
#     cd /opt/radiusone && sudo bash install.sh [options]
#   or the source: git clone <repo> /opt/radiusone && cd /opt/radiusone && sudo bash install.sh [options]
#
# Installs and configures nginx (HTTPS), PHP 8.3-FPM, MariaDB, FreeRADIUS (SQL backend) and, from the
# source only, Composer and Node 22 (to build the web assets), creates the database with one least-privilege user per role, generates
# every key and password, schedules the jobs and backups, and creates the first portal administrator.
# Its password is shown once, at the end.
#
# Options:
#   --domain NAME        Name or IP the portal is reached on (default: this server's first IP)
#   --email ADDRESS      With a real --domain: get a Let's Encrypt certificate for it (port 80 must be
#                        reachable from the internet). Without it, a self-signed certificate is used.
#   --admin-user NAME    First administrator's username (default: admin)
#   --admin-email ADDR   First administrator's email (default: admin@<domain>)
#   --timezone ZONE      Set the server time zone first, e.g. Europe/Tirane (default: leave as is)
#   --force              Run on a server that already has a RadiusOne or FreeRADIUS database not made by
#                        this script (it is not overwritten, but its configuration files are)
#
# Running it again is safe: existing keys, passwords, data and administrators are kept.
set -Eeuo pipefail

APP=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
ETC=/etc/radiusone
DATA=/var/lib/radiusone
BACKUPS=/var/backups/radiusone
LOGS=/var/log/radiusone
DB=radius
PHP=8.3
NODE_VERSION=22.20.0
RADDB=/etc/freeradius/3.0
MARKER=$ETC/.installed-by-install-sh
# A release tarball carries the libraries and the built pages; a git checkout builds them here.
BUILT=0
[[ -f "$APP/vendor/autoload.php" && -f "$APP/public/build/manifest.json" && ! -d "$APP/.git" ]] && BUILT=1

DOMAIN=''
EMAIL=''
ADMIN_USER=admin
ADMIN_EMAIL=''
TIMEZONE=''
FORCE=0

step() { printf '\n\033[1;34m==> %s\033[0m\n' "$*"; }
note() { printf '    %s\n' "$*"; }
fail() { printf '\n\033[1;31mInstall failed: %s\033[0m\n' "$*" >&2; exit 1; }
trap 'fail "line $LINENO: $BASH_COMMAND"' ERR

usage() { sed -n '2,23p' "$0" | sed 's/^# \{0,1\}//'; exit "${1:-0}"; }
while [[ $# -gt 0 ]]; do
  case "$1" in
    --domain) DOMAIN=${2:?}; shift 2 ;;
    --email) EMAIL=${2:?}; shift 2 ;;
    --admin-user) ADMIN_USER=${2:?}; shift 2 ;;
    --admin-email) ADMIN_EMAIL=${2:?}; shift 2 ;;
    --timezone) TIMEZONE=${2:?}; shift 2 ;;
    --force) FORCE=1; shift ;;
    -h|--help) usage 0 ;;
    *) echo "Unknown option: $1" >&2; usage 2 ;;
  esac
done

# --- checks ------------------------------------------------------------------------------------------
step "Checking the server (RadiusOne v$(cat "$APP/VERSION" 2>/dev/null || echo dev))"
[[ $EUID -eq 0 ]] || fail 'run as root: sudo bash install.sh'
. /etc/os-release
[[ "${ID:-}" == ubuntu && "${VERSION_ID:-}" == 24.04 ]] || fail "Ubuntu 24.04 is required (this is ${PRETTY_NAME:-unknown})."
[[ -f "$APP/artisan" && -d "$APP/database/sql" ]] || fail "run install.sh from the project folder ($APP is not one)."
[[ "$ADMIN_USER" =~ ^[A-Za-z0-9_.-]{3,80}$ ]] || fail '--admin-user: 3 to 80 letters, digits, dot, dash or underscore.'
if [[ -z "$DOMAIN" ]]; then
  DOMAIN=$(hostname -I | awk '{print $1}')
  [[ -n "$DOMAIN" ]] || fail 'could not find this server'"'"'s IP address; pass --domain.'
fi
[[ "$DOMAIN" =~ ^[A-Za-z0-9.:-]+$ ]] || fail "--domain: not a host name or IP address: $DOMAIN"
is_ip=0
[[ "$DOMAIN" =~ ^[0-9.]+$ || "$DOMAIN" == *:* ]] && is_ip=1
if [[ -z "$ADMIN_EMAIL" ]]; then
  [[ $is_ip == 0 && "$DOMAIN" == *.* ]] && ADMIN_EMAIL="admin@$DOMAIN" || ADMIN_EMAIL='admin@localhost.localdomain'
fi
[[ "$ADMIN_EMAIL" =~ ^[^[:space:]\"\\@]+@[^[:space:]\"\\@]+\.[^[:space:]\"\\@]+$ ]] || fail "--admin-email: not an email address: $ADMIN_EMAIL"
[[ -z "$EMAIL" || $is_ip == 0 ]] || fail '--email needs --domain with a real host name (Let'"'"'s Encrypt does not issue certificates for IPs).'
if [[ $FORCE == 0 && ! -f "$MARKER" ]]; then
  # RadiusOne before 2.3 lived in /opt/freeradius-web-laravel with /etc/freeradius-web: move it with
  # ops/radiusone-upgrade-2.3 instead.
  if [[ -d /opt/freeradius-web || -d /opt/freeradius-web-laravel || -d /etc/freeradius-web ]] || { command -v mariadb >/dev/null && mariadb -N -e "SHOW DATABASES LIKE '$DB'" 2>/dev/null | grep -q .; }; then
    fail "this server already has a '$DB' database or an older install that this script did not create. Use --force only if you are sure."
  fi
fi
note "Project: $APP"
note "Portal:  https://$DOMAIN"

# --- packages ----------------------------------------------------------------------------------------
step 'Installing packages (nginx, PHP 8.3, MariaDB, FreeRADIUS)'
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q --no-install-recommends \
  ca-certificates curl git unzip openssl sudo cron logrotate iputils-ping xz-utils \
  nginx mariadb-server mariadb-client \
  freeradius freeradius-mysql freeradius-utils \
  "php$PHP-fpm" "php$PHP-cli" "php$PHP-mysql" "php$PHP-mbstring" "php$PHP-xml" "php$PHP-curl" \
  "php$PHP-zip" "php$PHP-intl" "php$PHP-bcmath" rsync
[[ $BUILT == 1 ]] || apt-get install -y -q --no-install-recommends composer
[[ -n "$EMAIL" ]] && apt-get install -y -q --no-install-recommends certbot

if [[ -n "$TIMEZONE" ]]; then
  timedatectl list-timezones | grep -qxF "$TIMEZONE" || fail "unknown time zone: $TIMEZONE"
  timedatectl set-timezone "$TIMEZONE"
  echo "$TIMEZONE" > /etc/timezone
  systemctl restart mariadb
  note "Time zone: $TIMEZONE"
fi

if [[ $BUILT == 0 ]] && ! node -e 'const [a,b]=process.versions.node.split(".").map(Number);process.exit(a>22||(a===22&&b>=12)||(a===20&&b>=19)?0:1)' 2>/dev/null; then
  step "Installing Node.js $NODE_VERSION (only used to build the web pages)"
  tmp=$(mktemp -d)
  file="node-v$NODE_VERSION-linux-x64.tar.xz"
  curl -fsSLo "$tmp/$file" "https://nodejs.org/dist/v$NODE_VERSION/$file"
  curl -fsSLo "$tmp/SHASUMS256.txt" "https://nodejs.org/dist/v$NODE_VERSION/SHASUMS256.txt"
  (cd "$tmp" && grep " $file\$" SHASUMS256.txt | sha256sum -c --quiet) || fail 'Node.js download did not match its checksum.'
  rm -rf /usr/local/lib/nodejs && mkdir -p /usr/local/lib/nodejs
  tar -xJf "$tmp/$file" -C /usr/local/lib/nodejs --strip-components=1
  ln -sf /usr/local/lib/nodejs/bin/{node,npm,npx} /usr/local/bin/
  rm -rf "$tmp"
fi

# --- keys and passwords ------------------------------------------------------------------------------
step 'Generating keys and passwords'
install -d -o root -g root -m 0711 "$ETC"
# From here on the database is this script's own, so a re-run after a failure is allowed.
touch "$MARKER"
# Hex: safe in every config file and SQL statement it lands in, and no pipe for pipefail to trip on.
random() { local n=${1:-32}; openssl rand -hex $(( (n + 1) / 2 )) | cut -c "1-$n"; }
# Reuses a value already saved in an env file, so running the installer again changes nothing.
saved() { [[ -f "$1" ]] && sed -n "s/^$2=//p" "$1" | tail -n 1 || true; }
PORTAL_PASS=$(saved "$ETC/portal.env" DB_PASSWORD); PORTAL_PASS=${PORTAL_PASS:-$(random)}
SECRETS_KEY=$(saved "$ETC/portal.env" APP_KEY); SECRETS_KEY=${SECRETS_KEY:-$(random 48)}
MIGRATION_PASS=$(saved "$ETC/migration.env" DB_PASSWORD); MIGRATION_PASS=${MIGRATION_PASS:-$(random)}
BACKUP_PASS=$(saved "$ETC/backup.env" DB_PASSWORD); BACKUP_PASS=${BACKUP_PASS:-$(random)}
RADIUS_PASS=$(saved "$ETC/radius.env" DB_PASSWORD); RADIUS_PASS=${RADIUS_PASS:-$(random)}

identity() {  # $1 file, $2 user, $3 password, $4 group, $5 mode
  local tmp; tmp=$(mktemp)
  cat > "$tmp" <<EOF
# RadiusOne database identity ($2), written by install.sh. Keep this file private.
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$DB
DB_USERNAME=$2
DB_PASSWORD=$3
EOF
  install -o root -g "$4" -m "$5" "$tmp" "$1"; rm -f "$tmp"
}
identity "$ETC/portal.env" radiusone_portal "$PORTAL_PASS" www-data 0640
# The app reads its secrets key from the shared file under this name (App\Support\SharedEnv).
echo "APP_KEY=$SECRETS_KEY" >> "$ETC/portal.env"
identity "$ETC/migration.env" radiusone_migration "$MIGRATION_PASS" root 0600
identity "$ETC/backup.env" radiusone_backup "$BACKUP_PASS" root 0600
identity "$ETC/radius.env" radiusone_radius "$RADIUS_PASS" root 0600
note "Saved in $ETC (readable by root only; portal.env also by the web server)."

# --- database ----------------------------------------------------------------------------------------
step 'Creating the database'
systemctl enable --now mariadb >/dev/null
mariadb -e "CREATE DATABASE IF NOT EXISTS \`$DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
for pair in "radiusone_portal:$PORTAL_PASS" "radiusone_migration:$MIGRATION_PASS" "radiusone_backup:$BACKUP_PASS" "radiusone_radius:$RADIUS_PASS"; do
  user=${pair%%:*} pass=${pair#*:}
  mariadb -e "CREATE USER IF NOT EXISTS '$user'@'localhost' IDENTIFIED BY '$pass'; ALTER USER '$user'@'localhost' IDENTIFIED BY '$pass';"
done
if ! mariadb -N "$DB" -e "SHOW TABLES LIKE 'radcheck'" | grep -q .; then
  mariadb "$DB" < "$RADDB/mods-config/sql/main/mysql/schema.sql"
  note 'FreeRADIUS tables created.'
fi

# --- application -------------------------------------------------------------------------------------
step 'Setting up the application'
install -d -o www-data -g www-data -m 0750 "$DATA" "$DATA/documents" "$DATA/branding" "$DATA/security" "$BACKUPS"
install -d -o www-data -g adm -m 0750 "$LOGS"
if [[ ! -f "$APP/.env" ]]; then
  cat > "$APP/.env" <<EOF
APP_NAME=RadiusOne
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://$DOMAIN

# Secrets (database password, credentials key) are read from this protected file at boot.
RADIUSONE_SHARED_ENV=$ETC/portal.env

LOG_CHANNEL=daily
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=$DB
DB_USERNAME=radiusone_portal

SESSION_DRIVER=file
SESSION_LIFETIME=480
SESSION_COOKIE=radiusone_session
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
CACHE_STORE=file
QUEUE_CONNECTION=sync
FILESYSTEM_DISK=local
FORCE_HTTPS=true
TRUSTED_PROXIES=127.0.0.1,::1
LOGIN_MAX_ATTEMPTS=5
LOGIN_DECAY_MINUTES=15

DOCUMENT_STORAGE=$DATA/documents
BACKUP_PATH=$BACKUPS
BRANDING_STORAGE=$DATA/branding
SECURITY_STORAGE=$DATA/security
RADCLIENT_BIN=/usr/bin/radclient
RADCLIENT_TIMEOUT=5
NOTIFICATION_HTTP_ALLOWED_HOSTS=
WEB_SERVER_ENGINE=nginx

RADIUSONE_SCHEDULE_EXPIRY_SYNC=true
NETWORK_WORKER_ENABLED=true
EOF
fi
chown root:www-data "$APP/.env" && chmod 0640 "$APP/.env"

# Root runs these steps: log to the terminal, never into storage/logs, which must stay www-data's.
export LOG_CHANNEL=stderr COMPOSER_ALLOW_SUPERUSER=1
# Root never loads Laravel's cache files from bootstrap/cache (www-data's; Laravel runs them as PHP code):
# its artisan steps keep a private copy, as ops/radiusone-root-artisan does. The portal builds its own.
root_cache=$(mktemp -d /run/radiusone-install.XXXXXX)
trap 'rm -rf "$root_cache"' EXIT
for name in config services packages routes events; do
  export "APP_${name^^}_CACHE=$root_cache/$name.php"
done
cd "$APP"
[[ $BUILT == 1 ]] || composer install --no-dev --optimize-autoloader --no-interaction --no-progress -q
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force -q
if [[ $BUILT == 0 ]]; then
  npm ci --no-audit --no-fund --loglevel=error
  npm run build --silent >/dev/null
fi
chown -R www-data:www-data storage bootstrap/cache

step 'Creating the application tables'
RADIUSONE_OPERATOR_ENV_FILE="$ETC/migration.env" php artisan radiusone:migrate >/dev/null
mariadb < "$APP/ops/install/radiusone-grants.sql"
mariadb -e 'FLUSH PRIVILEGES'
sudo -u www-data LOG_CHANNEL=stderr php artisan radiusone:check-schema >/dev/null || fail 'the schema check failed (php artisan radiusone:check-schema).'

# --- FreeRADIUS --------------------------------------------------------------------------------------
step 'Connecting FreeRADIUS to the database'
for f in mods-config/sql/main/mysql/queries.conf sites-available/default; do
  # Copies of the originals; before 2.3 they were named .frweb-orig.
  [[ -f "$RADDB/$f.frweb-orig" && ! -e "$RADDB/$f.radiusone-orig" ]] && mv "$RADDB/$f.frweb-orig" "$RADDB/$f.radiusone-orig"
  [[ -f "$RADDB/$f.radiusone-orig" ]] || cp -a "$RADDB/$f" "$RADDB/$f.radiusone-orig"
done
cat > "$RADDB/mods-available/sql" <<EOF
# Written by RadiusOne install.sh: the SQL backend (users, groups, accounting and the NAS list).
sql {
	dialect = "mysql"
	driver = "rlm_sql_\${dialect}"
	mysql {
		warnings = auto
	}
	server = "localhost"
	port = 3306
	login = "radiusone_radius"
	password = "$RADIUS_PASS"
	radius_db = "$DB"
	acct_table1 = "radacct"
	acct_table2 = "radacct"
	postauth_table = "radpostauth"
	authcheck_table = "radcheck"
	groupcheck_table = "radgroupcheck"
	authreply_table = "radreply"
	groupreply_table = "radgroupreply"
	usergroup_table = "radusergroup"
	delete_stale_sessions = yes
	pool {
		start = \${thread[pool].start_servers}
		min = \${thread[pool].min_spare_servers}
		max = \${thread[pool].max_servers}
		spare = \${thread[pool].max_spare_servers}
		uses = 0
		retry_delay = 30
		lifetime = 0
		idle_timeout = 60
		max_retries = 5
	}
	# NAS devices come from the portal (Network → NAS); FreeRADIUS loads them when it starts.
	read_clients = yes
	client_table = "nas"
	group_attribute = "SQL-Group"
	\$INCLUDE \${modconfdir}/\${.:name}/main/\${dialect}/queries.conf
}
EOF
chown freerad:freerad "$RADDB/mods-available/sql" && chmod 0640 "$RADDB/mods-available/sql"
ln -sfn ../mods-available/sql "$RADDB/mods-enabled/sql"

queries="$RADDB/mods-config/sql/main/mysql/queries.conf"
cp -a "$queries.radiusone-orig" "$queries"
# Only NAS devices switched on in the portal, and not being removed, are RADIUS clients.
perl -0pi -e 's/\tSELECT id, nasname, shortname, type, secret, server \\\n\tFROM \$\{client_table\}"/\tSELECT n.id, n.nasname, n.shortname, n.type, n.secret, n.server \\\n\tFROM \${client_table} n \\\n\tLEFT JOIN nas_metadata m ON m.nas_id=n.id \\\n\tWHERE COALESCE(m.enabled, 1)=1 AND COALESCE(m.pending_delete, 0)=0"/' "$queries"
# The post-auth log never stores passwords.
perl -0pi -e "s/\t\t\t'%\{%\{User-Password\}:-%\{Chap-Password\}\}', \\\\\n/\t\t\t'', \\\\\n/" "$queries"
grep -q 'LEFT JOIN nas_metadata m ON m.nas_id=n.id' "$queries" || fail "could not update the NAS query in $queries (FreeRADIUS version changed?)."
grep -q 'User-Password}:-%{Chap-Password' "$queries" && fail "could not remove passwords from the post-auth log in $queries."

site="$RADDB/sites-available/default"
cp -a "$site.radiusone-orig" "$site"
# Simultaneous-Use (per-customer session limit) is checked against radacct.
[[ $(grep -cP '^#\tsql$' "$site") == 1 ]] || fail "could not enable the session check in $site (FreeRADIUS version changed?)."
sed -i -E 's/^#\tsql$/\tsql/' "$site"

freeradius -C >/dev/null 2>&1 || { freeradius -C 2>&1 | tail -n 15; fail 'the FreeRADIUS configuration check failed.'; }
systemctl enable freeradius >/dev/null
systemctl restart freeradius

# --- web server --------------------------------------------------------------------------------------
step 'Configuring HTTPS and the web server'
cert_dir=/etc/ssl/radiusone
if [[ ! -f "$cert_dir/cert.pem" ]]; then
  install -d -m 0755 "$cert_dir"
  [[ $is_ip == 1 ]] && san="IP:$DOMAIN" || san="DNS:$DOMAIN"
  openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=$DOMAIN" -addext "subjectAltName=$san" \
    -keyout "$cert_dir/key.pem" -out "$cert_dir/cert.pem" 2>/dev/null
  chmod 0600 "$cert_dir/key.pem"
fi
cert="$cert_dir/cert.pem" key="$cert_dir/key.pem"
[[ -f "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" ]] && cert="/etc/letsencrypt/live/$DOMAIN/fullchain.pem" key="/etc/letsencrypt/live/$DOMAIN/privkey.pem"

write_site() {
  cat > /etc/nginx/sites-available/radiusone <<EOF
# RadiusOne, written by install.sh.
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    location /.well-known/acme-challenge/ { root $APP/public; }
    location / { return 301 https://\$host\$request_uri; }
}

server {
    # Ubuntu 24.04 ships nginx 1.24: http2 is a listen flag there ("http2 on;" needs 1.25+).
    listen 443 ssl http2 default_server;
    listen [::]:443 ssl http2 default_server;
    server_name $DOMAIN _;
    ssl_certificate $cert;
    ssl_certificate_key $key;
    ssl_protocols TLSv1.2 TLSv1.3;

    if (-f $DATA/radiusone-maintenance) { return 503; }
    root $APP/public;
    index index.php;
    # Security headers come from the app (App\\Http\\Middleware\\SecurityHeaders).
    client_max_body_size 24m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location /build/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        add_header X-Content-Type-Options "nosniff";
        try_files \$uri =404;
    }

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php$PHP-fpm.sock;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF
  ln -sfn /etc/nginx/sites-available/radiusone /etc/nginx/sites-enabled/radiusone
  rm -f /etc/nginx/sites-enabled/default
  nginx -t 2>/dev/null || { nginx -t; fail 'the nginx configuration check failed.'; }
  systemctl reload nginx
}

# The app accepts ID documents up to 8 MB each, two per client form.
cat > "/etc/php/$PHP/fpm/conf.d/99-radiusone.ini" <<'EOF'
; RadiusOne (install.sh)
upload_max_filesize = 10M
post_max_size = 24M
EOF
systemctl enable "php$PHP-fpm" nginx >/dev/null
systemctl restart "php$PHP-fpm"
systemctl start nginx
write_site

if [[ -n "$EMAIL" && "$cert" == "$cert_dir/cert.pem" ]]; then
  if certbot certonly --webroot -w "$APP/public" -d "$DOMAIN" -m "$EMAIL" --agree-tos -n --deploy-hook 'systemctl reload nginx' >/dev/null 2>&1; then
    cert="/etc/letsencrypt/live/$DOMAIN/fullchain.pem" key="/etc/letsencrypt/live/$DOMAIN/privkey.pem"
    write_site
    note "Let's Encrypt certificate installed for $DOMAIN (renews automatically)."
  else
    note "Let's Encrypt failed (is $DOMAIN pointing here, with port 80 open?). Using the self-signed certificate;"
    note "run this installer again with the same options once DNS is right."
  fi
fi

# --- jobs, backups, server controls ------------------------------------------------------------------
step 'Scheduling jobs and backups'
cat > /etc/cron.d/radiusone <<EOF
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
# RadiusOne (install.sh). Every app job (billing, SNMP, alerts, expiry...) runs from the scheduler
# (routes/console.php); the nightly backup runs as root with the read-only backup identity, through
# radiusone-root-artisan (installed below), so root never loads the cache files www-data writes in bootstrap/cache.
* * * * * www-data cd $APP && /usr/bin/php artisan schedule:run >>$LOGS/scheduler.log 2>&1
15 2 * * * root cd $APP && RADIUSONE_OPERATOR_ENV_FILE=$ETC/backup.env LOG_CHANNEL=stderr /usr/local/sbin/radiusone-root-artisan radiusone:backup >$LOGS/backup.log 2>&1
EOF
chmod 0644 /etc/cron.d/radiusone
cat > /etc/logrotate.d/radiusone <<EOF
$LOGS/*.log {
    weekly
    rotate 8
    compress
    delaycompress
    missingok
    notifempty
    create 0640 www-data adm
    su www-data adm
}
EOF
systemctl enable --now cron >/dev/null
# Restart buttons, time zone switch, Back up now and Software update in the portal (root helpers, a narrow
# sudoers rule and radiusone-update.path).
bash "$APP/ops/radiusone-postinstall" >/dev/null

if command -v ufw >/dev/null && ufw status | grep -q '^Status: active'; then
  ufw allow 80/tcp >/dev/null; ufw allow 443/tcp >/dev/null
  ufw allow 1812/udp >/dev/null; ufw allow 1813/udp >/dev/null
  note 'Firewall (ufw): opened 80, 443 (web) and 1812, 1813/udp (RADIUS).'
fi

# --- first administrator -----------------------------------------------------------------------------
step 'Creating the first administrator'
ADMIN_PASS=''
existing=$(mariadb -N "$DB" -e 'SELECT COUNT(*) FROM portal_users')
if [[ "$existing" == 0 ]]; then
  ADMIN_PASS=$(random 24)
  printf '{"username":"%s","name":"Administrator","email":"%s","password":"%s"}' "$ADMIN_USER" "$ADMIN_EMAIL" "$ADMIN_PASS" \
    | sudo -u www-data LOG_CHANNEL=stderr php artisan radiusone:bootstrap-operator --create-first-superadmin >/dev/null
else
  note "The portal already has $existing user(s); none created."
fi

trap - ERR

# --- done --------------------------------------------------------------------------------------------
check() { systemctl is-active --quiet "$1" && printf '    %-14s running\n' "$1" || printf '    %-14s NOT RUNNING\n' "$1"; }
code=$(curl -sk -o /dev/null -w '%{http_code}' "https://127.0.0.1/login" -H "Host: $DOMAIN" || true)

printf '\n\033[1;32m==> RadiusOne %s is installed.\033[0m\n\n' "v$(cat "$APP/VERSION" 2>/dev/null || echo dev)"
check nginx; check "php$PHP-fpm"; check mariadb; check freeradius; check cron
printf '    %-14s %s\n' 'login page' "HTTP $code"
printf '\n    Portal:    https://%s\n' "$DOMAIN"
if [[ -n "$ADMIN_PASS" ]]; then
  printf '    Username:  %s\n' "$ADMIN_USER"
  printf '    Password:  \033[1m%s\033[0m\n' "$ADMIN_PASS"
  printf '\n    The password is shown only now: save it. At first sign-in you set up two-factor\n'
  printf '    authentication with an authenticator app, then you can change the password in Profile.\n'
fi
[[ "$cert" == "$cert_dir/cert.pem" ]] && printf '\n    HTTPS uses a self-signed certificate: the browser warns once; accept it to continue.\n'
printf '\n    Next: add your routers under NAS, then restart FreeRADIUS from Monitoring → RADIUS server.\n'
printf '    RADIUS listens on UDP 1812 (auth) and 1813 (accounting) on this server.\n\n'
