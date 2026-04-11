#!/usr/bin/env bash
#
# Update atfm-tools on WHC shared hosting.
#
# This script is meant to be run ON THE SERVER after SSH-ing in. It does NOT
# push from your local machine — git is already installed on the WHC box, so
# the update flow is simply "pull from origin and reinstall deps".
#
# First-time setup (run ONCE, manually, the first time):
#
#   ssh ogzqox66@173.209.32.98 -p 27
#   cd ~
#   git clone https://github.com/skyelaird/atfm-tools.git
#   cd atfm-tools
#   composer install --no-dev --optimize-autoloader
#   cp .env.example .env
#   nano .env   # fill in DB credentials
#   chmod 600 .env
#   php bin/migrate.php
#   php bin/seed.php       # optional: adds demo FIRs and a sample flow measure
#
# Then in WHC cPanel → Domains → Create a subdomain
#   Subdomain:     atfm
#   Domain:        momentaryshutter.com
#   Document Root: /home/ogzqox66/atfm-tools/public
#
# Also in WHC cPanel → Software → Select PHP Version
#   Pick PHP 8.2 or higher and click "Set as current".
#
# After that, every update is just:
#
#   ssh ogzqox66@173.209.32.98 -p 27
#   ./atfm-tools/scripts/deploy-whc.sh
#

set -euo pipefail

cd "$(dirname "$0")/.."
REPO_ROOT="$(pwd)"
echo "==> updating atfm-tools in $REPO_ROOT"

echo "==> git pull"
git pull --ff-only

echo "==> composer install (prod)"
composer install --no-dev --optimize-autoloader --no-interaction

if [[ -f .env ]]; then
    chmod 600 .env
fi

echo "==> migrate (idempotent)"
php bin/migrate.php

echo "==> done."
echo "Test: curl -sf https://atfm.momentaryshutter.com/api/health && echo OK"
