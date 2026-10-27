#!/usr/bin/env bash
# Installs brume/laravel (this checkout) into a brand-new Laravel app and checks that the admin
# answers over HTTP.
#
#   bin/smoke-install.sh <12|13> <precompiled|vite> [--no-upgrade]
#
# By default, if brume:install stops on package.json conflicts it is re-run with --upgrade-deps
# (and a WARN is printed). --no-upgrade fails instead: the plain documented quick start.
#
# vite mode installs @brume-ui/brume-* from npmjs.com. Set KEEP=1 to keep the scratch app.
set -euo pipefail

usage() {
  echo "usage: $0 <12|13> <precompiled|vite> [--no-upgrade]" >&2
  exit 2
}

[[ $# -eq 2 || $# -eq 3 ]] || usage
LARAVEL="$1"
MODE="$2"
NO_UPGRADE=0
if [[ $# -eq 3 ]]; then
  [[ "$3" == --no-upgrade ]] || usage
  NO_UPGRADE=1
fi
[[ "$LARAVEL" == 12 || "$LARAVEL" == 13 ]] || usage
[[ "$MODE" == precompiled || "$MODE" == vite ]] || usage

for tool in composer php curl; do
  command -v "$tool" >/dev/null || { echo "FAIL: $tool is not installed." >&2; exit 1; }
done
if [[ "$MODE" == vite ]]; then
  command -v pnpm >/dev/null || { echo "FAIL: pnpm is not installed." >&2; exit 1; }
fi

PACKAGE="$(cd "$(dirname "$0")/.." && pwd)"
PORT=8101
BASE="http://127.0.0.1:${PORT}"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/brume-smoke-${LARAVEL}-${MODE}-XXXXXX")"
APP="$WORK/app"
JAR="$WORK/cookies.txt"
SERVER_PID=""
UPGRADE_RETRY=0

LABEL="Laravel ${LARAVEL} / ${MODE}"
[[ $NO_UPGRADE == 1 ]] && LABEL+=" (no --upgrade-deps)"

cleanup() {
  local status=$?
  if [[ -n "$SERVER_PID" ]]; then
    kill "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
  # artisan serve starts php -S as a child; make sure nothing is left on the port.
  pkill -f "127.0.0.1:${PORT}" 2>/dev/null || true
  if [[ $status -eq 0 ]]; then
    [[ $UPGRADE_RETRY == 1 ]] && LABEL+=" (WARN: needed --upgrade-deps)"
    echo "PASS: ${LABEL}"
  else
    echo "FAIL: ${LABEL} (exit ${status}; scratch app kept at ${APP}, server log ${WORK}/server.log)"
    exit $status
  fi
  [[ "${KEEP:-0}" == 1 ]] || rm -rf "$WORK"
}
trap cleanup EXIT

step() { printf '\n==> %s\n' "$*"; }
fail() { echo "FAIL: $*" >&2; exit 1; }

if curl -s -o /dev/null "$BASE"; then
  fail "port ${PORT} is already in use; stop that server first."
fi

step "composer create-project laravel/laravel:^${LARAVEL}.0"
composer create-project "laravel/laravel:^${LARAVEL}.0" "$APP" --no-interaction --prefer-dist --quiet
cd "$APP"
echo "laravel/framework $(php artisan --version --no-ansi | awk '{print $3}')"

step "require brume/laravel from ${PACKAGE}"
composer config repositories.brume "{\"type\": \"path\", \"url\": \"${PACKAGE}\", \"options\": {\"symlink\": false}}"
composer require "brume/laravel:@dev" --no-interaction --quiet

step "configure .env (sqlite, own session cookie)"
php -r '
$env = file_get_contents(".env");
foreach (["DB_CONNECTION" => "sqlite", "SESSION_COOKIE" => "brume_smoke_session", "APP_URL" => "'"$BASE"'"] as $key => $value) {
    $env = preg_match("/^#?\s*{$key}=.*$/m", $env)
        ? preg_replace("/^#?\s*{$key}=.*$/m", "{$key}={$value}", $env, 1)
        : rtrim($env)."\n{$key}={$value}\n";
}
file_put_contents(".env", $env);
'
touch database/database.sqlite

step "php artisan brume:install"
install_args=(--username=admin --password=admin1234 --no-interaction)
[[ "$MODE" == vite ]] && install_args+=(--frontend)
if ! php artisan brume:install "${install_args[@]}" | tee "$WORK/install.log"; then
  # A fresh skeleton can pin older vite / laravel-vite-plugin than Brume needs. The installer then
  # stops with a conflict table and suggests --upgrade-deps; follow that advice like a user would.
  [[ $NO_UPGRADE == 0 && "$MODE" == vite ]] && grep -q -- '--upgrade-deps' "$WORK/install.log" || fail "brume:install failed"
  echo "WARN: brume:install --frontend stopped on package.json conflicts; retrying with --upgrade-deps." >&2
  echo "WARN: the plain quick start does not work on this skeleton (see the table above)." >&2
  UPGRADE_RETRY=1
  step "php artisan brume:install --upgrade-deps (as the installer suggested)"
  php artisan brume:install "${install_args[@]}" --upgrade-deps
fi
grep -q "^BRUME_FRONTEND=${MODE}$" .env || fail ".env does not contain BRUME_FRONTEND=${MODE}"

if [[ "$MODE" == vite ]]; then
  step "pnpm install && pnpm brume:build"
  pnpm install
  pnpm list vite laravel-vite-plugin @vitejs/plugin-react --depth 0 | grep -E "^(vite|laravel-vite-plugin|@vitejs)" || true
  pnpm brume:build
fi

step "php artisan serve --port=${PORT}"
php artisan serve --host=127.0.0.1 --port="$PORT" --no-reload >"$WORK/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 60); do
  curl -s -o /dev/null "$BASE/up" && break
  sleep 0.5
done

status_of() { curl -s -o "$WORK/body" -w '%{http_code}' -b "$JAR" -c "$JAR" "$@"; }

step "GET /admin/login"
code=$(status_of "$BASE/admin/login")
[[ "$code" == 200 ]] || fail "/admin/login returned ${code}"

# The page's first module script must be served too (precompiled: public/vendor/brume, vite: public/build-brume).
script=$(php -r 'preg_match("/<script type=\"module\" src=\"([^\"]+)\"/", file_get_contents($argv[1]), $m) || exit(1); echo html_entity_decode($m[1]);' "$WORK/body") \
  || fail "/admin/login has no module script tag"
code=$(curl -s -o /dev/null -w '%{http_code}' "$script")
[[ "$code" == 200 ]] || fail "frontend entry ${script} returned ${code}"
echo "frontend entry ${script#"$BASE"} 200"

step "POST /admin/login"
xsrf=$(awk '$6 == "XSRF-TOKEN" { print $7 }' "$JAR" | php -r 'echo urldecode(trim(stream_get_contents(STDIN)));')
[[ -n "$xsrf" ]] || fail "no XSRF-TOKEN cookie after GET /admin/login"
code=$(status_of -X POST -H "X-XSRF-TOKEN: ${xsrf}" -H 'Accept: text/html' \
  --data-urlencode username=admin --data-urlencode password=admin1234 "$BASE/admin/login")
[[ "$code" == 302 ]] || fail "login returned ${code}, expected a 302 redirect"

step "GET /admin (signed in)"
code=$(status_of "$BASE/admin")
[[ "$code" == 200 ]] || fail "/admin returned ${code}"
version=$(php -r 'preg_match("/data-page=\"([^\"]+)\"/", file_get_contents($argv[1]), $m) || exit(1); $page = json_decode(html_entity_decode($m[1]), true, flags: JSON_THROW_ON_ERROR); echo $page["version"] ?? "";' "$WORK/body") \
  || fail "/admin has no Inertia data-page attribute (still on the login page?)"

step "GET /admin with X-Inertia (partial visit)"
code=$(status_of -H 'X-Inertia: true' -H "X-Inertia-Version: ${version}" -H 'X-Requested-With: XMLHttpRequest' "$BASE/admin")
[[ "$code" == 200 ]] || fail "X-Inertia /admin returned ${code}"
php -r '
$page = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
if (($page["component"] ?? null) !== "brume/page") { fwrite(STDERR, "unexpected component\n"); exit(1); }
' "$WORK/body" || { head -c 600 "$WORK/body" >&2; echo >&2; fail "X-Inertia response is not clean Inertia JSON"; }
echo "X-Inertia /admin: JSON ok"
