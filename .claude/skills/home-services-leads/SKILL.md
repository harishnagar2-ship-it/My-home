---
name: home-services-leads
description: Find US home-service businesses (plumbers, electricians, roofers, cleaners, landscapers, etc.) that have no website and export their name, phone, email, address and listing URL to CSV. Uses Google Places (API key) or OpenStreetMap (free). Use when the user asks for local-business leads, "businesses without a website", contractor lists, or web-design agency prospecting.
---

# Home-services leads (no website)

`scripts/find_leads.py` searches business listings city by city and category by
category, keeps only operating US businesses that list a phone number and have
**no website**, de-duplicates them, and appends to a CSV.

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

## Outreach notes

These are business listings published for customers to call, so calling the
business is fine. Do not use auto-dialers or bulk SMS on these numbers (many are
mobiles, and the TCPA requires consent for that). Cold email in the US must
follow CAN-SPAM: real sender identity, honest subject, and an opt-out.
