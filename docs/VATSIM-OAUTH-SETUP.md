# VATSIM Connect OAuth — setup

## Register the application

Go to https://my.vatsim.net/ and look for **OAuth** or **Developer
applications** (exact menu name has changed a few times; it may require
requesting access via the VATSIM Tech team if self-serve isn't
available for your rating/division).

When submitting the application, provide:

| Field | Value |
|---|---|
| Application name | `atfm-tools` |
| Application description | Rate-based tactical CTOT allocator for VATSIM Canadian airports (CYHZ/CYOW/CYUL/CYVR/CYWG/CYYC/CYYZ). Uses VATSIM Connect to identify controllers and pilots so they can set TOBT, view CTOT, and acknowledge slots in the Web portal. |
| Organization | (VATCAN or personal) |
| Redirect URI | `https://atfm.momentaryshutter.com/oauth/vatsim/callback` |
| Scopes requested | `full_name`, `vatsim_details` |
| Website | `https://atfm.momentaryshutter.com/` |

VATSIM responds with a **Client ID** and **Client Secret**. Keep the
secret private — never commit it.

## Install the credentials on the server

Edit `/home/<whc-user>/atfm.momentaryshutter.com/.env` (create if needed)
and add:

```
VATSIM_OAUTH_CLIENT_ID=<client_id>
VATSIM_OAUTH_CLIENT_SECRET=<client_secret>
VATSIM_OAUTH_REDIRECT_URI=https://atfm.momentaryshutter.com/oauth/vatsim/callback
APP_URL=https://atfm.momentaryshutter.com
```

No deploy / restart needed — the `.env` is re-read per request by
`Bootstrap::boot()`.

## Verify the flow

1. Navigate to `https://atfm.momentaryshutter.com/oauth/vatsim/login`
2. VATSIM's login page should appear; approve the requested scopes.
3. You should be redirected back to `/` with a valid `atfm_session`
   cookie. Test with `GET /api/v1/auth/me` — should return
   `{authenticated: true, cid: ..., name: "...", ...}`.

If the callback errors with `state mismatch`, your browser is blocking
cross-site cookies on the first request — try a normal (not incognito)
session first.

## Troubleshooting

- `503 VATSIM OAuth not configured` — `.env` not loaded or env vars
  missing. Check file permissions; `php -r "echo getenv('VATSIM_OAUTH_CLIENT_ID');"`
  from the app root should print the ID.
- `502 token exchange failed` — client_id/secret mismatch, or VATSIM
  has revoked the app. Regenerate secret in VATSIM dashboard.
- `502 user fetch failed` — token issued but user-info call failed;
  rare, usually transient.
