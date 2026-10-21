#!/usr/bin/env bash
# Runs the test suite against a private, throw-away MariaDB (no networking, data in /tmp).
set -euo pipefail
cd "$(dirname "$0")/.."
dir=$(mktemp -d /tmp/frweb-laravel-test.XXXXXXXX)
pid=''
cleanup() {
  if [[ -n "$pid" ]]; then kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; fi
  rm -rf "$dir"
}
trap cleanup EXIT
mariadb-install-db --no-defaults --datadir="$dir/data" --auth-root-authentication-method=normal --skip-test-db >"$dir/install.log" 2>&1
mariadbd --no-defaults --datadir="$dir/data" --socket="$dir/db.sock" --pid-file="$dir/db.pid" --skip-networking --log-error="$dir/server.log" &
pid=$!
for ((attempt=0; attempt<100; attempt++)); do
  if [[ -S "$dir/db.sock" ]] && mariadb --no-defaults --socket="$dir/db.sock" -u root -e 'CREATE DATABASE IF NOT EXISTS frweb_test' >/dev/null 2>&1; then break; fi
  sleep .1
done
[[ -S "$dir/db.sock" ]] || { cat "$dir/server.log"; exit 1; }
export DB_SOCKET="$dir/db.sock"
export FRWEB_TEST_DIR="$dir"
vendor/bin/phpunit "$@"
