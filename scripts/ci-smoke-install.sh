#!/usr/bin/env bash
set -uo pipefail

if [[ $# -ne 2 || ! "$1" =~ ^(12|13)$ || ! "$2" =~ ^(precompiled|vite)$ ]]; then
  echo "usage: $0 <12|13> <precompiled|vite>" >&2
  exit 2
fi

laravel="$1"
mode="$2"
mkdir -p .ci-smoke-results
log=".ci-smoke-results/laravel-${laravel}-${mode}.log"

bin/smoke-install.sh "$laravel" "$mode" 2>&1 | tee "$log"
result=${PIPESTATUS[0]}

if [[ $result -ne 0 ]]; then
  step=$(awk '/^==> / { last = substr($0, 5, 200) } END { print last }' "$log")
  [[ -n "$step" ]] || step="before the first smoke step"
  context=$(tail -n 8 "$log" | cut -c 1-180 | tr '\n' '|' | cut -c 1-1200)
  # GitHub workflow-command data must escape these characters before it becomes an annotation.
  context=${context//'%'/'%25'}
  context=${context//$'\r'/'%0D'}
  step=${step//'%'/'%25'}
  step=${step//$'\r'/'%0D'}
  step=${step//$'\n'/'%0A'}
  echo "::error title=Install smoke failed (Laravel ${laravel} / ${mode})::exit=${result}; last step=${step}; recent output=${context}; log artifact=${log}"
fi

exit "$result"
