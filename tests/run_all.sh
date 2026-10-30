#!/usr/bin/env bash
# Complete local regression matrix for a SIBIL source checkout.
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

php build/assemble.php
php -l sibil.php
bash -n bin/sibil

tests=(
  "php tests/test_db_tx_pdo_compat.php"
  "php tests/test_payment_iran_controls.php"
  "php tests/test_payment_tetrapay.php"
  "php tests/test_tetrapay_preflight_fast.php"
  "php tests/test_mirza_payment_selector.php"
  "php tests/test_metered_product_callbacks.php"
  "php tests/test_hourly_unlimited.php"
  "php tests/test_v318_unlimited.php"
  "php tests/test_rbac_force_join.php"
  "bash tests/test_cli_safety.sh"
)

for command in "${tests[@]}"; do
  printf '\n%s\n' "══════════ $command ══════════"
  bash -c "$command"
done

git diff --check
printf '\n%s\n' 'All SIBIL local regressions passed.'
