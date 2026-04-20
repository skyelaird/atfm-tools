<?php

declare(strict_types=1);

namespace Atfm\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Server-side session record for VATSIM-Connect-authenticated users.
 *
 * Cookie: `atfm_session=<token>` (HttpOnly, Secure, SameSite=Lax).
 * Server looks up this token in auth_sessions → gets the VATSIM CID +
 * cached profile (name, rating, division, etc.).
 *
 * Session lifetime: 30 days (refreshed on use).
 */
final class AuthSession extends Model
{
    protected $table = 'auth_sessions';

    protected $fillable = [
        'token',
        'vatsim_cid',
        'user_data',
        'expires_at',
        'last_seen_at',
    ];

    protected $casts = [
        'vatsim_cid'  => 'int',
        'user_data'   => 'array',
        'expires_at'  => 'datetime',
        'last_seen_at'=> 'datetime',
    ];

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
