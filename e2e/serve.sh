#!/usr/bin/env bash
# Starts the workbench for the e2e suite on port 8100 (or $E2E_PORT) with a fresh, seeded database.
# Playwright runs this as its webServer command (see playwright.config.ts).
set -euo pipefail

cd "$(dirname "$0")/.."

testbench=(php vendor/bin/testbench)

# The workbench runs in vite (--frontend) mode: build workbench/resources/js/brume into the
# testbench skeleton's public/build-brume (composer build-frontend).
pnpm --dir frontend install --frozen-lockfile >/dev/null
node frontend/scripts/check-package-origins.mjs
pnpm --dir frontend build:workbench >/dev/null
# create-sqlite-db + migrate:fresh with the workbench seeder (testbench.yaml workbench.build).
"${testbench[@]}" workbench:build --no-interaction

# `testbench serve` starts the PHP built-in server as a child process, which does not inherit
# `php -d` flags; an extra ini scan dir does reach it. The leading ':' keeps the default dir.
export PHP_INI_SCAN_DIR=":$(pwd)/e2e"

exec "${testbench[@]}" serve --host=127.0.0.1 --port="${E2E_PORT:-8100}" --no-reload
