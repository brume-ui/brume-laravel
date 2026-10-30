#!/usr/bin/env bash
set -uo pipefail
mkdir -p .ci-test-results
composer test -- --log-junit .ci-test-results/junit.xml 2>&1 | tee .ci-test-results/full.log
result=${PIPESTATUS[0]}
if [ "$result" -ne 0 ]; then
    php scripts/ci-test-diagnostics.php .ci-test-results/junit.xml || true
fi
exit "$result"
