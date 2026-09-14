# Deploying the two halves

This project splits into two pieces that live on **different servers**.

| Folder | Goes to | Needs |
|---|---|---|
| `server/` | Your PHP host — Hostinger, cPanel, DigitalOcean, any shared host | PHP 8+ |
| `device/` | Netlify, GitHub Pages, Vercel, Cloudflare Pages | Nothing — it's static |

## Why the split

**Netlify, GitHub Pages and Vercel cannot run PHP.** They serve static files only.

That is fine, because the device page is plain HTML and JavaScript with no PHP
in it at all. It calls your PHP API over the network instead. So:

```
   Netlify                        Your PHP host
   ┌──────────────┐               ┌──────────────────┐
   │ device/      │  ── fetch ──> │ server/api.php   │
   │ index.html   │  <── JSON ──  │ server/config.php│
   │ config.js    │               │ pairing DB       │
   └──────────────┘               └──────────────────┘
          (the TV)                        │
                                          │
                                 ┌────────▼─────────┐
                                 │ activate.php     │  ← on your website
                                 └──────────────────┘
```

`activate.php` stays on the PHP host with the API, because it is part of your
existing site and needs the user's login session.

---

## Step 1 — Upload `server/` to your PHP host

Put the three files wherever you want them reachable, for example
`https://yoursite.com/tv/`.

```
/tv/config.php
/tv/api.php
/tv/activate.php
```

Make sure the folder is **writable** so SQLite can create `pairing.sqlite`.
On cPanel that is usually permission `755` on the folder.

**Better for production:** switch to MySQL. In `config.php`, replace the SQLite
line with:

```php
$pdo = new PDO('mysql:host=localhost;dbname=YOURDB;charset=utf8mb4', 'USER', 'PASS');
```

The two `CREATE TABLE` statements work on MySQL with one change — swap
`TEXT PRIMARY KEY` for `VARCHAR(64) PRIMARY KEY`, because MySQL cannot index
an unbounded TEXT column.

---

## Step 2 — Allow your device page's address

Open `server/config.php` and add the exact address of your Netlify site to
`ALLOWED_ORIGINS`:

```php
const ALLOWED_ORIGINS = [
    'https://your-device-page.netlify.app',
    'https://yourusername.github.io',
];
```

Scheme and host only. **No trailing slash**, no path.

If you skip this, the browser silently blocks every request and the TV page
just sits there. It is the single most common thing that breaks this setup.

---

## Step 3 — Upload `device/` to Netlify

Drag the `device/` folder onto https://app.netlify.com/drop — that is the whole
deployment. Or connect a GitHub repo and set the publish directory to `device`.

Then edit `device/config.js`:

```js
window.API_BASE     = 'https://yoursite.com/tv';
window.ACTIVATE_URL = 'yoursite.com/tv/activate.php';
```

`API_BASE` must point at the folder containing `api.php`, with no trailing slash.

---

## Step 4 — Put the form on your real website

You do not have to use `activate.php` as a whole page. To drop the form into an
existing page of your site, copy the `<form>`, the `<script>` block, and set the
fetch URL to wherever `api.php` sits:

```js
fetch('/tv/api.php?action=verify', { ... })
```

Add your login check at the top:

```php
<?php
session_start();
if (empty($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}
?>
```

---

## Step 5 — Bind the pairing to a real user account

Right now a code pairs without an account, which is correct for a prototype and
wrong for production.

In `server/api.php`, find the `verify` case and the comment marking the spot:

```php
// Real system: bind $_SESSION['user_id'] to this row here.
db()->prepare("UPDATE device_codes
               SET status = 'paired', user_id = ?, paired_at = ?
               WHERE device_code = ?")
    ->execute([$_SESSION['user_id'], time(), $row['device_code']]);
```

Add a `user_id TEXT` column to the table. Then `poll` can return which account
the TV is signed in as, and you can build a "my devices" page that revokes them.

---

## Checklist before you demo

- [ ] `API_BASE` in `config.js` points at the folder holding `api.php`
- [ ] Netlify address is in `ALLOWED_ORIGINS` in `config.php`
- [ ] Both sites are **HTTPS** — a https page cannot call a http API
- [ ] The server folder is writable, or you switched to MySQL
- [ ] Open the browser console on the TV page; CORS failures show up there

## If the TV page says "Cannot reach the server"

In order of how often it is the cause:

1. The Netlify address is not in `ALLOWED_ORIGINS` — check for a trailing slash
2. `API_BASE` has a trailing slash, or points at `api.php` instead of its folder
3. Mixed content — the page is https and `API_BASE` is http
4. The PHP folder is not writable so SQLite cannot create its file
