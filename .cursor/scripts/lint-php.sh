#!/usr/bin/env bash
set -euo pipefail

ROOT="/workspace/modules/addons/snapshot_pro"
FAIL=0

while IFS= read -r -d '' file; do
  if ! php -l "$file" >/dev/null; then
    php -l "$file"
    FAIL=1
  fi
done < <(find "$ROOT" -name '*.php' -print0)

if [[ "$FAIL" -ne 0 ]]; then
  exit 1
fi

echo "PHP syntax check passed for all module files."
