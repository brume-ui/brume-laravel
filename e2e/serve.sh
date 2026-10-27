#!/usr/bin/env bash
# Starts the workbench for the e2e suite on port 8100 with a fresh, seeded database.
# Playwright runs this as its webServer command (see playwright.config.ts).
set -euo pipefail

cd "$(dirname "$0")/.."

testbench=(php vendor/bin/testbench)

"${testbench[@]}" vendor:publish --tag=brume-assets --force --no-interaction >/dev/null
# create-sqlite-db + migrate:fresh with the workbench seeder (testbench.yaml workbench.build).
"${testbench[@]}" workbench:build --no-interaction

# `testbench serve` starts the PHP built-in server as a child process, which does not inherit
# `php -d` flags; an extra ini scan dir does reach it. The leading ':' keeps the default dir.
export PHP_INI_SCAN_DIR=":$(pwd)/e2e"

exec "${testbench[@]}" serve --host=127.0.0.1 --port=8100 --no-reload
