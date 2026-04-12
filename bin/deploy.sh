#!/usr/bin/env bash
#
# Auto-deploy script for WHC shared hosting.
#
# Pulls the latest commit from origin/main and runs any pending database
# migrations. Designed to be run from cron every minute. Idempotent — if
# the working tree is already up to date, exits silently with no output
# (so cron mail doesn't spam you).
#
# Cron line (install via `crontab -e` on WHC):
#
#   * * * * * cd ~/atfm.momentaryshutter.com && bash bin/deploy.sh >> logs/deploy.log 2>&1
#
# Failure modes that this script REFUSES to deploy on:
#   - Local uncommitted changes (would be overwritten by --ff-only)
#   - Non-fast-forward (someone force-pushed; needs human review)
#   - Pull error from upstream (network, auth, etc.)
#
# Anything caught here gets logged with a [deploy] prefix and a non-zero
# exit so cron mail surfaces it.

set -euo pipefail

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_DIR"

# Refuse to run on a dirty working tree.
if ! git diff --quiet || ! git diff --cached --quiet; then
    echo "[deploy] $(date -u +%FT%TZ) ABORT: working tree has local changes"
    git status --short
    exit 1
fi

# Capture HEAD before pulling so we can detect "did anything change?".
OLD_SHA="$(git rev-parse HEAD)"

# Fast-forward only — refuse to merge unrelated histories or deal with
# divergence. If this fails, a human needs to look.
if ! git pull --ff-only origin main >/tmp/atfm-deploy-pull.log 2>&1; then
    echo "[deploy] $(date -u +%FT%TZ) ABORT: git pull failed"
    cat /tmp/atfm-deploy-pull.log
    exit 1
fi

NEW_SHA="$(git rev-parse HEAD)"

# Nothing to do — exit silently so cron doesn't mail us 1440 noops/day.
if [ "$OLD_SHA" = "$NEW_SHA" ]; then
    exit 0
fi

# Something changed. Log what.
echo "[deploy] $(date -u +%FT%TZ) updated $OLD_SHA → $NEW_SHA"
git log --oneline "$OLD_SHA..$NEW_SHA" | sed 's/^/[deploy]   /'

# Run migrations (idempotent — bin/migrate.php is "create if not exists"
# guarded). If a migration adds a column, this is the line that applies it.
if php bin/migrate.php >/tmp/atfm-deploy-migrate.log 2>&1; then
    grep -E '^✓' /tmp/atfm-deploy-migrate.log | sed 's/^/[deploy]   /' || true
else
    echo "[deploy] $(date -u +%FT%TZ) MIGRATE FAILED — investigate immediately"
    cat /tmp/atfm-deploy-migrate.log
    exit 1
fi

echo "[deploy] $(date -u +%FT%TZ) done"
