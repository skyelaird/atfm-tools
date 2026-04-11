#!/usr/bin/env bash
# Upload public/ and vendor/ to an IONOS Web Hosting Ultimate account over SSH.
#
# Prereqs:
#   - SSH key added to your IONOS hosting package
#   - .env populated with IONOS_SSH_HOST, IONOS_SSH_USER, IONOS_SSH_PATH
#   - `composer install --no-dev --optimize-autoloader` already run locally
#
# Usage: ./scripts/deploy-ionos.sh

set -euo pipefail

cd "$(dirname "$0")/.."

if [[ -f .env ]]; then
    # shellcheck disable=SC1091
    set -a; source .env; set +a
fi

: "${IONOS_SSH_HOST:?IONOS_SSH_HOST must be set in .env}"
: "${IONOS_SSH_USER:?IONOS_SSH_USER must be set in .env}"
: "${IONOS_SSH_PATH:?IONOS_SSH_PATH must be set in .env}"

echo "==> composer install (no-dev)"
composer install --no-dev --optimize-autoloader --quiet

echo "==> rsync public/ and vendor/ to ${IONOS_SSH_USER}@${IONOS_SSH_HOST}:${IONOS_SSH_PATH}"
rsync -az --delete \
    --exclude '.env' \
    --exclude '.git*' \
    public/ vendor/ src/ composer.json \
    "${IONOS_SSH_USER}@${IONOS_SSH_HOST}:${IONOS_SSH_PATH}/"

echo "==> done"
