#!/usr/bin/env bash
set -euo pipefail

repo=$(cd "$(dirname "$0")/.." && pwd)
fixture=$(mktemp -d "${TMPDIR:-/tmp}/brume-ci-smoke-XXXXXX")
trap 'rm -rf "$fixture"' EXIT
mkdir -p "$fixture/bin"

cat > "$fixture/bin/smoke-install.sh" <<'SH'
#!/usr/bin/env bash
echo '==> pnpm install && pnpm brume:build'
echo 'fixture stdout'
echo 'fixture stderr: install failed 50%' >&2
printf '%02000d\n' 0
exit 37
SH
chmod +x "$fixture/bin/smoke-install.sh"

result=0
(cd "$fixture" && bash "$repo/scripts/ci-smoke-install.sh" 12 vite) > "$fixture/output" 2>&1 || result=$?
[[ $result -eq 37 ]]
grep -Fq 'fixture stdout' "$fixture/.ci-smoke-results/laravel-12-vite.log"
grep -Fq 'fixture stderr: install failed 50%' "$fixture/.ci-smoke-results/laravel-12-vite.log"
grep -Fq '::error title=Install smoke failed (Laravel 12 / vite)::exit=37; last step=pnpm install && pnpm brume:build;' "$fixture/output"
grep -Fq 'recent output=' "$fixture/output"
grep -Fq 'log artifact=.ci-smoke-results/laravel-12-vite.log' "$fixture/output"
grep -Fq 'install failed 50%25' "$fixture/output"
annotation=$(grep '^::error title=' "$fixture/output")
[[ ${#annotation} -le 1700 ]]

cat > "$fixture/bin/smoke-install.sh" <<'SH'
#!/usr/bin/env bash
echo '==> successful smoke'
echo 'fixture success'
exit 0
SH
chmod +x "$fixture/bin/smoke-install.sh"

(cd "$fixture" && bash "$repo/scripts/ci-smoke-install.sh" 13 precompiled) > "$fixture/output" 2>&1
grep -Fq 'fixture success' "$fixture/.ci-smoke-results/laravel-13-precompiled.log"
if grep -Fq '::error' "$fixture/output"; then
  echo 'unexpected failure annotation on successful smoke' >&2
  exit 1
fi

echo 'PASS: smoke diagnostics preserve failure status, capture both streams, and annotate only failures'
