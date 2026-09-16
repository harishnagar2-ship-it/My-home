---
name: home-services-leads
description: Find US home-service businesses (plumbers, electricians, roofers, cleaners, landscapers, etc.) that have no website or an outdated one, and export their name, phone, email, address and listing URL to CSV with a redesign-opportunity score. Uses Google Places (API key) or OpenStreetMap (free). Use when the user asks for local-business leads, "businesses without a website", businesses with old or ugly websites, contractor lists, or web-design agency prospecting.
---

# Home-services leads (no website, or an outdated one)

Two scripts, meant to be run in sequence:

1. `scripts/find_leads.py` searches business listings city by city and category
   by category, keeps operating US businesses that list a phone number, de-duplicates
   them, and appends to a CSV. By default it keeps only businesses with **no website**;
   `--include-with-website` keeps the rest too.
2. `scripts/audit_sites.py` visits each website in that CSV, scores how badly it
   needs a redesign, and sorts the strongest prospects to the top.

## Setup

```bash
pip install requests
```

**Google Places (recommended).** Create a key at console.cloud.google.com,
enable "Places API (New)", then:

```bash
export GOOGLE_MAPS_API_KEY=your_key
```

Each query is one billable Text Search request returning up to 20 businesses.
Google's monthly free tier covers roughly 1,000 of these requests, which is
enough for tens of thousands of listings. Keep `--limit` set and it stops early.

**OpenStreetMap (no key).** `--backend osm` queries the Overpass API for the
whole US per category. It is free but only lists businesses volunteers have
mapped with a phone number, so expect far fewer results.

## Run

```bash
python .claude/skills/home-services-leads/scripts/find_leads.py --limit 100
python .claude/skills/home-services-leads/scripts/find_leads.py --cities "Austin, TX" "Dallas, TX" --categories plumber roofing\ contractor --limit 50
python .claude/skills/home-services-leads/scripts/find_leads.py --backend osm --limit 200
python .claude/skills/home-services-leads/scripts/find_leads.py --cities-file my_cities.txt --out leads.csv
```

Defaults: 25 home-service categories, 56 largest US cities, 3 pages per query,
100 leads, output `home_services_leads.csv`. Re-running appends only new
businesses (deduplicated on the listing id), so you can grow the file over time.

## Output columns

`business_name, category, phone, email, website, address, city, state, zip,
source, source_url, source_id`

- `website` is empty for every row by design (that is the filter). Pass
  `--include-with-website` to keep the others too.
- `email` is empty for Google results; Google never exposes emails. It is
  filled for the minority of OpenStreetMap listings that carry one. Businesses
  with no website rarely have a public email, so treat phone as the primary
  contact.
- `source_url` is the Google Maps or OpenStreetMap listing to verify the lead.

## Score the websites

To also target businesses whose site is old or broken, collect everyone, then audit:

```bash
python .claude/skills/home-services-leads/scripts/find_leads.py --include-with-website --limit 300 --out all.csv
python .claude/skills/home-services-leads/scripts/audit_sites.py all.csv --out scored.csv
```

`audit_sites.py` adds `lead_type`, `redesign_score` (0-100), `site_issues`,
`site_final_url`, `site_http_status`, `site_load_ms` and `site_checked_at`, drops
anything below `--min-score` (default 30) and sorts by score. `--workers` (default 8)
and `--timeout` (default 12s) control the fetching.

`lead_type` is one of `no_website` (scores 100, the strongest pitch),
`outdated_website` (30+), `dated_website` (1-29) or `modern_website` (0, skip it).

Scoring signals, highest weight first:

| Points | Signal |
|-------|--------|
| 100 | no website at all |
| 40 | website does not load |
| 35 | error page, or a parked / "coming soon" placeholder |
| 30 | not mobile friendly (no viewport tag) — the most common real finding |
| 25 | Flash or other legacy embeds |
| 20 | no HTTPS, broken certificate, or built with obsolete software (FrontPage, old Joomla/Drupal) |
| 15 | 1990s-era HTML tags, or a table-based layout |
| 10 | copyright year 3+ years stale, or almost no content |
| 8 | slow to load (over 4 seconds) |
| 5 | free drag-and-drop builder |

Points add up and cap at 100. `site_issues` spells out each hit in plain English,
which doubles as your talking points on the call.

## Outreach notes

These are business listings published for customers to call, so calling the
business is fine. Do not use auto-dialers or bulk SMS on these numbers (many are
mobiles, and the TCPA requires consent for that). Cold email in the US must
follow CAN-SPAM: real sender identity, honest subject, and an opt-out.
