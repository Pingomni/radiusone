#!/usr/bin/env bash
# Side-by-side smoke check against the live database: signs in as the first enabled superadmin with a
# throw-away session (minted as www-data, destroyed on exit) and GETs each path on the local Laravel
# listener. Read-only: only GET requests are sent.
# Usage: tests/smoke.sh [/path ...]   (no paths = every main page)
set -euo pipefail
cd "$(dirname "$0")/.."
base=${SMOKE_BASE:-http://127.0.0.1:8081}

mint() {
  sudo -n -u www-data php -- "$@" <<'PHP'
<?php
chdir('/opt/freeradius-web-laravel');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$session = app('session')->driver();
if ($argv[1] === 'destroy') {
    $session->getHandler()->destroy($argv[2]);
    exit;
}
$user = App\Support\Db::row('SELECT id, session_version FROM portal_users WHERE role="superadmin" AND enabled=1 ORDER BY id LIMIT 1');
if (!$user) {
    fwrite(STDERR, "No enabled superadmin.\n");
    exit(1);
}
$session->start();
$session->put(['user_id' => (int) $user['id'], 'session_version' => (int) $user['session_version'], 'reauth_at' => time()]);
$session->save();
$name = config('session.cookie');
$value = app('encrypter')->encrypt(Illuminate\Cookie\CookieValuePrefix::create($name, app('encrypter')->getKey()) . $session->getId(), false);
echo $session->getId(), "\t", $name, '=', rawurlencode($value), "\n";
PHP
}

if [[ $# -eq 0 ]]; then
  set -- /dashboard /clients /clients/create /billing /billing/invoices/create /services /services/ip-pools /nas /nas/health \
    /monitoring /monitoring/server /operations /reports /logs /settings /settings/users /profile
fi

IFS=$'\t' read -r sid cookie < <(mint create)
trap 'mint destroy "$sid"' EXIT

failed=0
for path in "$@"; do
  body=$(mktemp)
  read -r code redirect < <(curl -s -o "$body" -w '%{http_code} %{redirect_url}\n' -H 'X-Forwarded-Proto: https' -H "Cookie: $cookie" "$base$path")
  component=$(grep -o '&quot;component&quot;:&quot;[^&]*' "$body" | head -1 | sed 's/.*&quot;//' || true)
  [[ -z "$component" ]] && component=$(grep -o '"component":"[^"]*' "$body" | head -1 | sed 's/.*"//' || true)
  status=ok
  if [[ "$code" -ge 400 || "$redirect" == */login || "$component" == Error ]]; then status=FAIL; failed=1; fi
  printf '%-4s %s %-60s %s%s\n' "$status" "$code" "$path" "${component:-$(head -c 60 "$body" | tr -d '\n')}" "${redirect:+ -> $redirect}"
  rm -f "$body"
done
exit $failed
