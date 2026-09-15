# Project brief — StreamBox: TV pairing and broadcast system

Paste everything below this line into your AI developer as the first message.

---

## Your role

You are the developer on this project. I am the product owner. I am a developer
too, so speak plainly and technically — do not oversimplify and do not flatter.

You will build a complete system in stages. **Follow the four hard rules below
before you write a single line of code.**

---

## HARD RULE 1 — Plan before you build

Do not write code in your first reply.

Your first reply must contain only:

1. **Your understanding of the system** in your own words — if you have
   misunderstood something, I need to catch it now, not after you've built it
2. **A stage-by-stage plan**, each stage independently testable
3. **The exact tools and accounts you need from me**, with the free option named
   for each
4. **Every open question** you have
5. **Anything you think is a bad idea**, and what you would do instead

Then stop and wait for me to approve. Do not start building until I say go.

If at any later point you discover the plan was wrong, stop and say so rather
than working around it quietly.

---

## HARD RULE 2 — Nothing goes online until it works locally

You may not deploy, upload, or publish anything until:

1. It runs on a local server (`php -S localhost:8000` is fine)
2. Every acceptance test in this brief passes
3. You have shown me the actual test output — not a description of it, the output
4. I have said deploy

When you claim something works, show the evidence. "Should work" and "I've
implemented X" are not evidence. A passing test, a curl response, or a
screenshot is.

If a test fails, tell me it failed. Do not quietly fix and move on without
saying what broke — I need to know what is fragile.

---

## HARD RULE 3 — Free of cost, entirely

Every tool, host, service and library must have a genuinely free tier that is
enough for this project. No trials that expire, no "free for 30 days", no
credit card required where avoidable.

If something genuinely cannot be done for free, **say so directly** and give me:
- what the limit actually is
- the cheapest real option
- what we lose if we stay free

Do not silently design around a paid service and tell me later.

---

## HARD RULE 4 — Ask, do not assume

If a requirement is unclear, ask. A wrong assumption costs more than a question.

Never invent credentials, URLs, API keys or account names. If you need one,
ask me for it.

---

## What already exists

There is a working prototype in this repository under `device-pairing/`:

| Path | What it is |
|---|---|
| `server/config.php` | SQLite storage, code generation, rate limiting, CORS allowlist |
| `server/api.php` | Endpoints: `new`, `poll`, `verify`, `sync`, `send`, `devices` |
| `server/activate.php` | The pairing form |
| `server/broadcast.php` | Broadcast portal |
| `device/index.html` | Landing page with APK download button |
| `device/app.html` | The TV client |
| `device/demo.html` | Serverless demo for static hosting |
| `device/config.js` | Deployment config |
| `android/` | Android TV WebView wrapper |

**Read all of it before planning.** Do not rewrite what already works. Extend it.

The pairing flow implements the OAuth 2.0 Device Authorization Grant
(RFC 8628) — the same pattern Prime Video and Netflix use. Keep the error
strings (`authorization_pending`, `slow_down`, `expired_token`,
`access_denied`) so it stays swappable with a real OAuth server later.

---

## What you are building

A system where a TV app is paired to a user account by typing a short code on a
website, after which an administrator can broadcast content to every paired
screen.

### Part A — API and website (PHP host)

Extend the existing PHP. Must have:

- Real user accounts: register, log in, log out, hashed passwords
  (`password_hash` / `password_verify`, never MD5 or SHA1)
- Pairing bound to the logged-in user — currently it pairs with no account at all
- A "My devices" page: list this user's paired screens, rename one, revoke one
- Broadcast portal behind a real admin login, not the current shared key
- MySQL instead of SQLite (free PHP hosts all give you one)

### Part B — TV client (static host)

- Keep `app.html` as the client. It pairs, then displays broadcasts
- Must survive a reboot without re-pairing (already does via `localStorage`)
- Must work with a TV remote: arrow keys and Enter only, no mouse, no touch
- Visible focus outlines — a TV user cannot see where the cursor is otherwise

### Part C — Android TV app

- Keep the WebView wrapper in `android/`
- Build the APK via GitHub Actions, not on my machine
- Serve the APK from the static host's download button

### Part D — Broadcast with media upload ← THE NEW PART

The portal currently accepts a media URL. It must also accept an uploaded file.

**Be honest with me about the limits here.** Free PHP hosting caps uploads
around 10MB and gives a few hundred MB of storage. A full movie cannot live
there. Do not pretend otherwise. Design three tiers:

| Tier | For | Where it lives |
|---|---|---|
| 1. Paste a URL | Any size, including full films | Wherever it already is |
| 2. Upload a clip | Under ~10MB — promos, announcements | The PHP host |
| 3. Large video | Full movies | A free external video host |

Implement tiers 1 and 2. For tier 3, research the current free options
(Cloudinary's free tier, archive.org, and similar), tell me what each actually
allows in 2026, and recommend one. Do not assume old limits are still true —
check.

**Upload security is not optional.** Uncontrolled file upload is the single
most dangerous feature in this project:

- Validate the real MIME type with `finfo_file`, never trust the extension
  and never trust the `Content-Type` header the browser sends
- Whitelist extensions explicitly: `mp4`, `webm`, `jpg`, `png`. Nothing else
- Generate a random filename. Never reuse the uploaded name
- Store uploads in a directory where PHP cannot execute — drop a `.htaccess`
  with `php_flag engine off`, or keep the directory outside the web root
- Enforce the size limit in PHP as well as in `php.ini`. Client-side checks
  are a convenience, not a control
- Admin only. Check the session before touching `$_FILES`

If you cannot make upload safe on the chosen host, say so and we will use
tier 1 and 3 only. That is an acceptable outcome. A working exploit is not.

---

## Security requirements

These are not suggestions:

- Every SQL query uses prepared statements. No string concatenation, ever
- Every piece of user or admin text is escaped before it reaches HTML
- Passwords hashed with `password_hash`, default algorithm
- CSRF tokens on every state-changing form
- Rate limit pairing-code attempts — brute-forcing an 8-character code is the
  real attack on this design
- Codes are single use and expire
- HTTPS everywhere. An https page cannot call an http API
- No secrets in the repository. Config values come from a file that is gitignored
- Session fixation: regenerate the session id on login

---

## Acceptance tests — all must pass before anything is deployed

Run these and show me the output.

**Pairing**
1. TV requests a code; a code appears
2. Website with a wrong code → clear error, no pairing
3. Website with the correct code → paired, TV updates within one poll interval
4. Same code used twice → refused
5. Expired code → refused, TV requests a new one
6. 10 wrong codes from one IP → rate limited
7. Lowercase, no dash (`bdwphqpk`) → still works
8. TV reloads → still paired, no new code

**Accounts**
9. Register, log out, log back in
10. Pairing while logged in binds the device to that account
11. User A cannot see or revoke user B's devices
12. Revoked device stops receiving broadcasts

**Broadcast**
13. Message broadcast reaches a paired screen
14. Video by URL plays
15. Uploaded clip plays
16. Stop broadcast returns screens to idle
17. `<img src=x onerror=alert(1)>` as a broadcast title renders as text,
    does not execute

**Upload security**
18. A `.php` file renamed to `.mp4` is rejected
19. A file over the size limit is rejected server-side
20. A logged-out user posting directly to the upload endpoint is rejected

**Cross-origin**
21. Request from the allowed origin succeeds
22. Request from an unlisted origin has no CORS header

Test 17, 18 and 20 are the ones that matter most. If any of those fail, the
project is not ready regardless of how good everything else looks.

---

## Deployment — only after I approve

Two halves, two hosts:

- `server/` → a free PHP host with MySQL
- `device/` → Netlify, GitHub Pages or Cloudflare Pages

They connect with two settings: `API_BASE` in `config.js` points at the API,
and the static host's address goes in `ALLOWED_ORIGINS` in `config.php`.

Before deploying, tell me:
- which free host you chose and why
- its real limits: storage, bandwidth, upload size, whether it sleeps
- what breaks first if this got real traffic

---

## What I need from you at each stage

- What changed
- What you tested and the actual output
- What you could not do and why
- What you need from me next

Never report something as done if it is untested. I would rather hear "built
but not verified" than find out later.

---

## Definition of done

A person can:

1. Register on the website
2. Install the app on an Android TV
3. See a code on the TV
4. Type it on the website while logged in
5. See the TV pair within seconds
6. Have an admin broadcast a message, an uploaded clip, and a video URL
7. See all three appear on the TV
8. Revoke the device from their account and see it stop receiving

All 22 acceptance tests pass. Nothing costs money.

---

## Start now

Reply with your plan only. No code. Include the tools and accounts you need
from me, with the free option for each, and every question you have.
