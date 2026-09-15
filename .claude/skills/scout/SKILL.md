---
name: scout
description: Install and run Scout (github.com/kiryano/Scout), a Python CLI that scrapes public profiles from Instagram, TikTok, LinkedIn, GitHub, YouTube, Twitch, Pinterest and Linktree, enriches them with verified emails, scores leads, and exports CSV. Use when the user mentions Scout, lead generation, appointment-setter prospecting, scraping social profiles, bulk-scraping a username list, or finding contact emails for a list of handles.
---

# Scout

Scout is a third-party Python 3.10+ tool (MIT, by kiryano). It is an interactive
Rich-based CLI, but its scrapers are importable Python functions, which is the
easier path when Claude drives it.

## Install or update

```bash
bash .claude/skills/scout/scripts/install.sh
```

This clones Scout into `$SCOUT_HOME` (default `~/.scout`), creates a virtualenv
at `$SCOUT_HOME/.venv`, installs `requirements.txt`, and copies `.env.example`
to `.env`. Re-running it pulls the latest release. Scout checks GitHub on
startup and exits with "Update Required" if a newer release exists, so re-run
the installer whenever you see that message.

## Configure

Edit `$SCOUT_HOME/.env` (loaded automatically on startup; never commit it):

| Variable | Purpose |
|----------|---------|
| `LINKEDIN_COOKIE` | `li_at` cookie from a logged-in browser. Required only for LinkedIn. |
| `HUNTER_API_KEY` | Optional Hunter.io key for extra email coverage. |
| `SCOUT_PROXY` | Single proxy, e.g. `http://user:pass@host:port`. |
| `SCOUT_PROXY_FILE` | File with one proxy per line, rotated. |
| `SCOUT_FREE_PROXY` | `true` to use free anonymous proxies (unreliable). |
| `SCOUT_DELAY_MIN` / `SCOUT_DELAY_MAX` | Seconds between requests (defaults 1.0 / 2.5). |

All platforms except LinkedIn work with no configuration.

## Run interactively

```bash
cd "${SCOUT_HOME:-$HOME/.scout}" && .venv/bin/python scout.py            # menu
cd "${SCOUT_HOME:-$HOME/.scout}" && .venv/bin/python scout.py --verbose  # debug logging
```

Menu: 1 Instagram, 2 TikTok, 3 LinkedIn, 4 GitHub, 5 YouTube, 6 Twitch,
7 Linktree, 8 Pinterest, 9 bulk scrape from file, 10 view exports,
11 settings, 0 quit. Exports are written to the current directory as
`<platform>_export_<timestamp>.csv`.

Bulk scrape (option 9) reads a `.txt` file with one handle per line, or a
`.csv` with a `username` or `handle` column (falls back to the first column).
Leading `@` is stripped.

## Run programmatically

Prefer this when the user wants a specific handle or list processed without
walking the menu. Run from `$SCOUT_HOME` so `app` imports and `.env` loads:

```bash
cd "${SCOUT_HOME:-$HOME/.scout}" && .venv/bin/python - <<'PY'
import os
from pathlib import Path
for line in Path(".env").read_text().splitlines():      # same loader scout.py uses
    if line.strip() and not line.startswith("#") and "=" in line:
        k, _, v = line.partition("="); os.environ.setdefault(k.strip(), v.strip())

from app.scrapers import (scrape_instagram, scrape_tiktok, scrape_linkedin,
                          scrape_github, scrape_youtube, scrape_twitch,
                          scrape_linktree, scrape_linkbio, scrape_pinterest)
from app.scrapers.enrichment import enrich_lead

profile = scrape_github("torvalds")          # dict or None
if profile:
    lead = enrich_lead(profile, hunter_api_key=os.environ.get("HUNTER_API_KEY") or None)
    print(lead)
PY
```

Every `scrape_*` function takes a username/handle string and returns a dict
(or `None` when the profile is missing or blocked). `enrich_lead` adds
`email`, confidence score, company domain and verification details. For many
leads use `LeadEnricher().enrich_bulk(leads, max_workers=3)`.

## Limitations to warn the user about

- Instagram and TikTok may return nothing or CAPTCHAs depending on region/IP; retries help.
- LinkedIn cookies expire and LinkedIn needs the `li_at` cookie.
- GitHub is limited to 60 unauthenticated requests/hour.
- SMTP verification is blocked by some mail servers, so scores below 100 are guesses.
- Free proxies are unreliable; Twitch falls back to a direct connection.

## Responsible use

Scout only reads publicly visible profile data, but scraping can still breach
a platform's terms of service and outreach must comply with local law
(CAN-SPAM, GDPR, PECR, etc.). Keep the default request delays, do not bulk-scrape
at high volume without the user confirming they accept the ToS risk, and never
store or commit scraped CSVs or `.env` files in this repository.
