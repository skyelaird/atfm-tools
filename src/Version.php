<?php

declare(strict_types=1);

namespace Atfm;

/**
 * Single source of truth for the running atfm-tools version.
 *
 * Bumped as part of every tagged release commit. Referenced from the
 * /api/v1/status endpoint and any other place that needs to surface
 * the version (the dashboard banner reads it from /api/v1/status).
 *
 * Why a constant rather than `git describe`: shared hosting may not
 * have git in PATH for the web user, and PHP exec'ing a shell out per
 * request adds latency for no benefit. A constant means one file edit
 * per release, picked up automatically on the next request.
 */
final class Version
{
    public const STRING = '0.6.22';
}
