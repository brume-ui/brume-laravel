#!/usr/bin/env bash
# A second workbench server for e2e/tests/precompiled.spec.ts: the same database (e2e/serve.sh
# creates and seeds it; this script never migrates), but the frontend is the committed dist/ as
# `brume:install` without --frontend serves it (published to public/vendor/brume). Port:
# $E2E_PRECOMPILED_PORT (default 8102). Playwright runs this as its second webServer command.
set -euo pipefail

cd "$(dirname "$0")/.."

testbench=(php vendor/bin/testbench)

# Exactly what brume:install does in precompiled mode: copy dist/ to public/vendor/brume.
"${testbench[@]}" vendor:publish --tag=brume-assets --force --no-interaction >/dev/null

export PHP_INI_SCAN_DIR=":$(pwd)/e2e"
export WORKBENCH_FRONTEND=precompiled

exec "${testbench[@]}" serve --host=127.0.0.1 --port="${E2E_PRECOMPILED_PORT:-8102}" --no-reload
