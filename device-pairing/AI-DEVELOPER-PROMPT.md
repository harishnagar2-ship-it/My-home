# Project brief — StreamBox: TV pairing and broadcast system

Paste everything below this line into your AI developer as the first message.

---

## Your role

You are the developer on this project. I am the product owner. I am a developer
too, so speak plainly and technically — do not oversimplify and do not flatter.

You will build a complete system in stages. **Read all six hard rules below
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

## HARD RULE 5 — Ask before every single tool, every single time

You must get my approval before adding **anything** external to this project.
Not once at the start — **every time**, for every item.

This counts as a tool and needs approval:

- Any library, framework or package (npm, Composer, pip — all of it)
- Any script or stylesheet loaded from a CDN
- Any hosting account, database, or online service
- Any API, however small or free
- Any GitHub app, Action from the marketplace, or integration
- Any analytics, error tracker, or monitoring service
- Any font, icon set, or asset loaded from someone else's server

"It's only a small library" and "it's free anyway" are not exemptions.

**When you ask, use exactly this format:**

```
TOOL REQUEST
What:        <name and version>
For:         <the specific thing in this project it does>
Free tier:   <what the free plan actually allows>
Account:     <does this need me to sign up? what data does it want?>
Alternative: <what we would do instead if you say no>
If refused:  <what we lose, honestly>
```

Then stop and wait. Do not install it, do not write code that imports it, and
do not "prepare for it" while waiting for my answer.

Default to **not** adding things. Plain PHP and plain JavaScript are preferred
over a dependency. The existing prototype uses no frameworks at all — keep it
that way unless you can argue otherwise and I agree.

---

## HARD RULE 6 — New accounts only. Nothing shared with anything else

This project is isolated. It does not touch anything I already have.

**You must not:**

- Use, read, or connect to any account, service or integration that is already
  connected to my environment
- Reuse an API key, token, database, or credential from any other project
- Assume an existing connector, MCP server, or linked service is available to you
- Store this project's data in a database that holds anything else
- Push to a repository that contains another project

**You must:**

- Ask me to create a **new, dedicated account** for each service this project
  needs, used by nothing else
- Keep this project's credentials in its own config file, gitignored, separate
  from everything
- Name accounts and databases so it is obvious they belong to this project
- Tell me plainly if something would be easier by reusing an existing account —
  and then not do it unless I say yes

If you find yourself with access to a service I did not explicitly set up for
this project, stop and tell me. Do not use it.

---

## Working with the existing website

I already have a live website. **This project is added to it. It does not
replace it, and it does not get built as a separate site.**

Before you touch anything on it, ask me for:

- The URL, and what it currently runs on (PHP version, framework or plain PHP)
- How I upload files to it — hPanel File Manager, FTP, git, something else
- Whether it already has user accounts and login, or whether we are adding them
- Its existing database, and whether this project gets its own **separate**
  database on the same host (it should — see Hard Rule 6)
- Which folder this project lives in, so nothing collides with what is there

Then propose where each piece goes and wait for me to confirm before uploading
anything.

**Do not modify any existing file on that site without showing me the change
first and getting a yes.** If the project needs a link in the site's navigation
or a new page in its header, tell me what to add and let me decide whether you
do it or I do.

If the existing site uses a framework or structure your plan does not fit, say
so at the planning stage — not halfway through.

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

## The pairing flow — this exact order

The code is checked **first**, before any login. Someone walking up to the
website has been told by their TV to type a code, so that is the first thing
the page asks for. Do not put a login wall in front of it.

```
  1. Visitor opens /activate           ← no login needed to reach this
         │
  2. Types the code, submits
         │
  3. Server checks the code ────────────────────────────────┐
         │                                                   │
         ├─ not found  → "Wrong code, check the TV"          │  all of these
         ├─ expired    → "Code expired, get a new one"       │  are rate
         ├─ used       → "Already used"                      │  limited
         │                                                   │
         └─ valid ──────────────────────────────────────────┘
                 │
                 │  hold the code in the session
                 ▼
  4. Logged in already? ── yes ──┐
         │                        │
         no                       │
         │                        │
         ▼                        │
  5. Show login / register        │
     (code still held)            │
         │                        │
         └────────────────────────┤
                                  ▼
  6. RE-CHECK the code, then pair it to this account
         │
         ├─ expired meanwhile → "Code expired while you were signing in.
         │                       Get a new code from your TV."
         │
         └─ still valid → bind device to user, mark paired
                 │
                 ▼
  7. "Connected — your device has been paired"
         │
         ▼
  8. TV updates within one poll interval
```

### The step everyone gets wrong

**Step 6 must re-check the code.** A naive build validates at step 3, carries a
"valid" flag through login, and pairs blindly afterwards. But logging in takes
time, and a person may stop to register an account, or reset a password. In that
window the code can expire, or be used by someone else.

Re-read the code from the database after login and validate it again. Trust the
database, never a flag in the session.

### Other details for this flow

- The pending code lives in the session, not in a URL and not in a hidden form
  field — neither survives a login redirect safely
- **Regenerate the session id on login** (session fixation), and carry the
  pending code across that regeneration deliberately. Doing one without the
  other either loses the code or leaves the hole open
- Registering a new account works the same as logging in — same pending code,
  same completion
- If the visitor abandons login and comes back later, the code is either still
  valid (continue) or expired (ask for a new one). Never pair a stale code
- Rate limiting applies at **step 3**, before any login. This is the one place
  an unauthenticated stranger can probe, so it is the one that needs the cap
- Show the device name at step 7 — "Living Room TV is now connected" — so the
  person can tell they paired the screen they meant to

### One security note to think about, not to panic over

Checking the code before login means an unauthenticated visitor can find out
whether a code exists. That is unavoidable in this design and real services
accept it — the defence is the rate limit plus a short code lifetime, not
hiding the check behind a login.

Be aware of the underlying attack in any device-flow system: someone who guesses
a live code and completes pairing with **their own** account ends up controlling
what that TV shows. Short expiry and a hard rate limit are what make guessing
impractical. Tell me if you see a cheap way to harden it further.

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

## Acceptance tests — all 28 must pass before anything is deployed

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

**Accounts and the code-first flow**
9. Register, log out, log back in
10. Logged OUT, correct code → asked to log in, then paired after login
11. Logged IN, correct code → paired immediately, no login step
12. Logged OUT, wrong code → error at the code step, no login prompt,
    and the attempt still counts against the rate limit
13. Code expires DURING login → refused with "expired while signing in",
    not silently paired
14. Register a new account mid-flow → pairs to the new account
15. Abandon login, come back with the session alive → code still works if
    it has not expired
16. Pairing binds the device to the account that logged in
17. User A cannot see or revoke user B's devices
18. Revoked device stops receiving broadcasts

**Broadcast**
19. Message broadcast reaches a paired screen
20. Video by URL plays
21. Uploaded clip plays
22. Stop broadcast returns screens to idle
23. `<img src=x onerror=alert(1)>` as a broadcast title renders as text,
    does not execute

**Upload security**
24. A `.php` file renamed to `.mp4` is rejected
25. A file over the size limit is rejected server-side
26. A logged-out user posting directly to the upload endpoint is rejected

**Cross-origin**
27. Request from the allowed origin succeeds
28. Request from an unlisted origin has no CORS header

Tests 13, 23, 24 and 26 are the ones that matter most. Test 13 in particular —
the expired-during-login case — is the one a rushed build silently gets wrong,
and it is the difference between a correct implementation and one that only
looks correct. If any of those four fail, the project is not ready regardless
of how good everything else looks.

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

1. Install the app on an Android TV
2. See a code on the TV
3. Go to the website and type the code — without logging in first
4. Be asked to log in or register, and do so
5. See "Connected", and the TV pair within seconds
6. Have an admin broadcast a message, an uploaded clip, and a video URL
7. See all three appear on the TV
8. Revoke the device from their account and see it stop receiving

All 28 acceptance tests pass. Nothing costs money. Every account this project
uses was created for this project and is used by nothing else. Every tool in
the build was one I approved by name.

---

## Start now

Reply with your plan only. **No code.**

Your first reply must contain:

1. Your understanding of the system in your own words
2. A stage-by-stage plan
3. **Every tool you will need, each as a separate TOOL REQUEST block** in the
   format given in Hard Rule 5 — I will approve or refuse them one by one
4. The questions you need answered about my existing website
5. Everything you think is a bad idea, and what you would do instead

Then stop. Do not install anything, do not create any account, and do not write
any code until I have answered.
