#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

"${ROOT}/scripts/docker-daemon.sh"

DOCKER=(docker)
if ! docker info >/dev/null 2>&1; then
  DOCKER=(sudo docker)
fi

"${ROOT}/scripts/prepare-fixture.sh"

cd "${ROOT}/dev"
"${DOCKER[@]}" compose up -d db

for _ in $(seq 1 30); do
  if "${DOCKER[@]}" compose exec -T db mariadb-admin ping -h 127.0.0.1 -uwhmcs -pwhmcs --silent >/dev/null 2>&1; then
    break
  fi
  sleep 2
done

# Start the web stack when licensed WHMCS files are present.
if [[ -f "${ROOT}/dev/whmcs/configuration.php" ]] && grep -q 'db_' "${ROOT}/dev/whmcs/configuration.php" 2>/dev/null; then
  "${DOCKER[@]}" compose up -d whmcs mailhog
fi

echo "Development services are running."
