#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd /workspace

chmod +x "${ROOT}/scripts/"*.sh

echo "==> Ensuring Docker daemon is available"
"${ROOT}/scripts/docker-daemon.sh"

DOCKER=(docker)
if ! docker info >/dev/null 2>&1; then
  DOCKER=(sudo docker)
fi

echo "==> Preparing development fixture"
"${ROOT}/scripts/prepare-fixture.sh"

echo "==> Installing dev harness Composer dependencies"
if ! command -v composer >/dev/null 2>&1; then
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/tmp --filename=composer
  COMPOSER=(php /tmp/composer)
else
  COMPOSER=(composer)
fi

(
  cd "${ROOT}/dev"
  "${COMPOSER[@]}" install --no-interaction --no-progress --prefer-dist
)

echo "==> PHP syntax check"
"${ROOT}/scripts/lint-php.sh"

echo "==> Core encryption + integrity verification"
php "${ROOT}/scripts/verify-core.php"

echo "==> Building WHMCS development stack images"
(
  cd "${ROOT}/dev"
  "${DOCKER[@]}" compose build db whmcs mailhog
)

echo "==> Starting MariaDB for integration tests"
(
  cd "${ROOT}/dev"
  "${DOCKER[@]}" compose up -d db
)

echo "==> Waiting for MariaDB"
for _ in $(seq 1 60); do
  if "${DOCKER[@]}" compose -f "${ROOT}/dev/docker-compose.yml" exec -T db \
      mariadb-admin ping -h 127.0.0.1 -uwhmcs -pwhmcs --silent >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

if ! "${DOCKER[@]}" compose -f "${ROOT}/dev/docker-compose.yml" exec -T db \
    mariadb-admin ping -h 127.0.0.1 -uwhmcs -pwhmcs --silent >/dev/null 2>&1; then
  echo "MariaDB did not become ready in time." >&2
  exit 1
fi

echo "==> End-to-end backup verification"
php "${ROOT}/scripts/e2e-backup.php"

echo "Install completed successfully."
