# Device Pairing Prototype

A working "enter the code on your phone to activate the TV" flow, in plain PHP.
No TV needed — one browser tab pretends to be the TV, the other is your website.

This implements the same pattern Prime Video, Netflix and YouTube use:
the **OAuth 2.0 Device Authorization Grant** ([RFC 8628](https://www.ietf.org/rfc/rfc8628.html)).

## Run it locally

Two servers, because the two halves deploy to two different hosts.

```bash
# terminal 1 — the PHP API and your website
cd device-pairing/server && php -S localhost:8000

# terminal 2 — the TV page (static)
cd device-pairing/device && php -S localhost:8001
```

Then open **two tabs**:

| Tab | URL | What it is |
|---|---|---|
| 1 | http://localhost:8001/index.html | The TV. Shows the code. |
| 2 | http://localhost:8000/activate.php | Your website. Type the code here. |

To put these on real servers, see **DEPLOY.md**.

Type the code from tab 1 into tab 2 and press Connect.
Tab 2 says *"Your device has been paired"*. Within 5 seconds tab 1 switches
to a connected screen and starts playing video.

Type a wrong code and tab 2 says *"Wrong code"* instead.

No database setup needed — it creates `pairing.sqlite` on first run.

## Files

| File | Purpose |
|---|---|
| `server/config.php` | Database, schema, code generation, rate limiting, CORS allowlist |
| `server/api.php` | The three endpoints: `new`, `poll`, `verify` |
| `server/activate.php` | The pairing form — drop this into your existing site |
| `device/index.html` | The TV screen — static, deploys anywhere |
| `device/config.js` | The one file you edit when deploying: where the API lives |

## The API

```
POST api.php?action=new
  { "device_name": "Living Room TV" }
→ { "device_code": "...64 hex chars...", "user_code": "HMJB-HSCW",
    "expires_in": 3600, "interval": 5 }

POST api.php?action=poll
  { "device_code": "..." }
→ 400 { "error": "authorization_pending" }    still waiting
→ 400 { "error": "expired_token" }            code died
→ 400 { "error": "access_denied" }            refused
→ 200 { "status": "paired", "access_token": "...", "device_name": "..." }

POST api.php?action=verify
  { "user_code": "HMJB-HSCW" }
→ 200 { "ok": true, "device_name": "Living Room TV" }
→ 404 { "ok": false, "error": "not_found" }
→ 410 { "ok": false, "error": "expired" }
→ 409 { "ok": false, "error": "already_used" }
→ 429 { "ok": false, "error": "too_many_attempts" }
```

The error strings match RFC 8628, so this stays swappable with a real
OAuth device-flow server later.

## Design notes

**Two codes, not one.** `device_code` is a 64-character secret only the TV
ever sees. `user_code` is 8 characters a human can read across a room.
That asymmetry is the security design — the short code is useless without
also holding the long one.

**The alphabet is consonants only** (`BCDFGHJKLMNPQRSTVWXZ`). No `0`/`O`,
no `1`/`I`/`l`, and no accidental words. 20⁸ ≈ 2.5 × 10¹⁰ combinations.

**Input is normalised.** `bdwp hqpk`, `BDWPHQPK` and `bdwp-hqpk` all match.
Users should not lose because of a dash.

**Brute force is capped** at 10 wrong tries per IP per 5 minutes. Guessing
an 8-letter code is the real attack on this design, not anything clever.

**Codes are single use** and expire after one hour.

## Moving it to your live site

1. **Swap the database.** In `config.php`, replace the SQLite DSN with your
   MySQL one. Nothing else changes.
2. **Require login.** Add your session check at the top of `activate.php` —
   there is a commented example in the file.
3. **Bind the real user.** In `api.php`, the `verify` branch has a comment
   marking where to store `$_SESSION['user_id']` on the row. Right now it
   pairs without an account, which is fine for a prototype and wrong for production.
4. **HTTPS only.** The `user_code` travels over the wire.

## Possible extensions

- Replace polling with WebSocket or SSE so the TV activates instantly
- Add refresh tokens and expiry on `access_token`
- Show the device name on the website and ask "Is this your TV?" before pairing
- Let a user see and revoke their paired devices
