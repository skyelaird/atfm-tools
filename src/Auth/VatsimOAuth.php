<?php

declare(strict_types=1);

namespace Atfm\Auth;

/**
 * VATSIM Connect OAuth 2.0 client.
 *
 * Minimal authorization-code flow implementation — no external OAuth
 * library. VATSIM Connect is a standard OAuth 2.0 provider:
 *
 *   Authorize: https://auth.vatsim.net/oauth/authorize
 *   Token:     https://auth.vatsim.net/oauth/token
 *   User info: https://auth.vatsim.net/api/user
 *
 * The application is registered at https://my.vatsim.net/oauth/manage
 * (or via VATSIM SSO admin); VATSIM issues client_id + client_secret
 * and the redirect URI is whatever we register. Secret lives in .env:
 *
 *   VATSIM_OAUTH_CLIENT_ID=...
 *   VATSIM_OAUTH_CLIENT_SECRET=...
 *   VATSIM_OAUTH_REDIRECT_URI=https://atfm.momentaryshutter.com/oauth/vatsim/callback
 *
 * The scopes we request:
 *   - full_name       : display name
 *   - vatsim_details  : CID, rating, division
 *   - email           : optional, not currently used
 */
final class VatsimOAuth
{
    private const AUTHORIZE_URL = 'https://auth.vatsim.net/oauth/authorize';
    private const TOKEN_URL     = 'https://auth.vatsim.net/oauth/token';
    private const USER_URL      = 'https://auth.vatsim.net/api/user';

    public static function clientId(): ?string
    {
        return $_ENV['VATSIM_OAUTH_CLIENT_ID'] ?? getenv('VATSIM_OAUTH_CLIENT_ID') ?: null;
    }

    public static function clientSecret(): ?string
    {
        return $_ENV['VATSIM_OAUTH_CLIENT_SECRET'] ?? getenv('VATSIM_OAUTH_CLIENT_SECRET') ?: null;
    }

    public static function redirectUri(): string
    {
        return $_ENV['VATSIM_OAUTH_REDIRECT_URI']
            ?? getenv('VATSIM_OAUTH_REDIRECT_URI')
            ?: 'https://atfm.momentaryshutter.com/oauth/vatsim/callback';
    }

    public static function isConfigured(): bool
    {
        return self::clientId() !== null && self::clientSecret() !== null;
    }

    /**
     * Build the authorization URL. The `state` is a CSRF nonce we set as
     * a short-lived cookie and check on callback.
     */
    public static function authorizeUrl(string $state, string $returnTo = '/'): string
    {
        $params = http_build_query([
            'client_id'     => self::clientId(),
            'redirect_uri'  => self::redirectUri(),
            'response_type' => 'code',
            'scope'         => 'full_name vatsim_details',
            'state'         => $state . '|' . base64_encode($returnTo),
        ]);
        return self::AUTHORIZE_URL . '?' . $params;
    }

    /**
     * Exchange the authorization code for an access token.
     * @return array{access_token:string, expires_in:int}|null
     */
    public static function exchangeCode(string $code): ?array
    {
        if (!self::isConfigured()) return null;
        $body = http_build_query([
            'grant_type'    => 'authorization_code',
            'client_id'     => self::clientId(),
            'client_secret' => self::clientSecret(),
            'redirect_uri'  => self::redirectUri(),
            'code'          => $code,
        ]);
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        $res = @file_get_contents(self::TOKEN_URL, false, $ctx);
        if ($res === false) return null;
        $data = json_decode($res, true);
        if (!is_array($data) || empty($data['access_token'])) return null;
        return $data;
    }

    /**
     * Fetch the user profile with a valid access token.
     * VATSIM response shape (abridged):
     *   { "data": {
     *       "cid": 1234567,
     *       "personal": { "name_first":"X", "name_last":"Y", "name_full":"X Y", "email":"..." },
     *       "vatsim":   { "rating":{"id":3,"short":"S2"}, "pilotrating":{...}, "division":{"id":"CA"}, ... }
     *   }}
     * @return array|null the data object
     */
    public static function fetchUser(string $accessToken): ?array
    {
        $ctx = stream_context_create(['http' => [
            'method'  => 'GET',
            'header'  => "Authorization: Bearer {$accessToken}\r\nAccept: application/json\r\n",
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);
        $res = @file_get_contents(self::USER_URL, false, $ctx);
        if ($res === false) return null;
        $payload = json_decode($res, true);
        return $payload['data'] ?? null;
    }
}
