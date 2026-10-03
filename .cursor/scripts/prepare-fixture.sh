#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
FIXTURE="${ROOT}/dev/fixture-whmcs"
WHMCS_DIR="${ROOT}/dev/whmcs"
MODULE_SRC="${ROOT}/../modules/addons/snapshot_pro"

mkdir -p "${FIXTURE}/modules/addons"
mkdir -p "${WHMCS_DIR}/modules/addons"

ln -sfn "${MODULE_SRC}" "${FIXTURE}/modules/addons/snapshot_pro"
ln -sfn "${MODULE_SRC}" "${WHMCS_DIR}/modules/addons/snapshot_pro"

cat > "${FIXTURE}/index.php" <<'PHP'
<?php
echo 'WHMCS Snapshot Pro development fixture';
PHP

cat > "${FIXTURE}/configuration.php" <<'PHP'
<?php
// Minimal configuration stub for filesystem backup tests.
$license = 'dev-fixture';
$db_host = '127.0.0.1';
$db_username = 'whmcs';
$db_password = 'whmcs';
$db_name = 'whmcs';
PHP

if [[ ! -f "${WHMCS_DIR}/index.php" ]]; then
  cat > "${WHMCS_DIR}/index.php" <<'PHP'
<?php
http_response_code(503);
echo 'Place licensed WHMCS files in .cursor/dev/whmcs/ for full admin UI development.';
PHP
fi

echo "Development fixture prepared at ${FIXTURE}"
