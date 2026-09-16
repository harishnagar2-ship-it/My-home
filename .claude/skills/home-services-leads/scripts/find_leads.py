#!/usr/bin/env python3
"""
Find US home-service businesses that have NO website, and export
name / phone / email / address / listing URL to CSV.

Data sources
  google  Google Places API (New). Best coverage. Needs GOOGLE_MAPS_API_KEY.
  osm     OpenStreetMap Overpass API. Free, no key, patchier coverage.

Examples
  export GOOGLE_MAPS_API_KEY=...            # from console.cloud.google.com
  python find_leads.py --limit 100
  python find_leads.py --cities "Austin, TX" "Dallas, TX" --categories plumber roofing
  python find_leads.py --backend osm --limit 200 --out osm_leads.csv

Only Python 3.8+ and `requests` are required (pip install requests).
"""
import argparse
import csv
import os
import re
import sys
import time
from pathlib import Path

try:
    import requests
except ImportError:  # pragma: no cover
    sys.exit("pip install requests")

# --------------------------------------------------------------------------
# Defaults
# --------------------------------------------------------------------------

DEFAULT_CATEGORIES = [
    "plumber", "electrician", "hvac contractor", "roofing contractor",
    "painter", "handyman", "landscaping", "lawn care", "house cleaning",
    "pest control", "locksmith", "garage door repair", "appliance repair",
    "pool service", "tree service", "junk removal", "pressure washing",
    "carpet cleaning", "fence contractor", "flooring contractor",
    "gutter cleaning", "window cleaning", "drywall contractor",
    "concrete contractor", "moving company",
]

DEFAULT_CITIES = [
    "New York, NY", "Los Angeles, CA", "Chicago, IL", "Houston, TX",
    "Phoenix, AZ", "Philadelphia, PA", "San Antonio, TX", "San Diego, CA",
    "Dallas, TX", "Austin, TX", "Jacksonville, FL", "Fort Worth, TX",
    "Columbus, OH", "Charlotte, NC", "Indianapolis, IN", "San Francisco, CA",
    "Seattle, WA", "Denver, CO", "Nashville, TN", "Oklahoma City, OK",
    "El Paso, TX", "Washington, DC", "Las Vegas, NV", "Boston, MA",
    "Portland, OR", "Louisville, KY", "Memphis, TN", "Detroit, MI",
    "Baltimore, MD", "Milwaukee, WI", "Albuquerque, NM", "Tucson, AZ",
    "Fresno, CA", "Sacramento, CA", "Kansas City, MO", "Mesa, AZ",
    "Atlanta, GA", "Omaha, NE", "Colorado Springs, CO", "Raleigh, NC",
    "Miami, FL", "Virginia Beach, VA", "Minneapolis, MN", "Tampa, FL",
    "Tulsa, OK", "Arlington, TX", "New Orleans, LA", "Cleveland, OH",
    "Orlando, FL", "Pittsburgh, PA", "St. Louis, MO", "Cincinnati, OH",
    "Salt Lake City, UT", "Boise, ID", "Richmond, VA", "Birmingham, AL",
]

COLUMNS = [
    "business_name", "category", "phone", "email", "website", "address",
    "city", "state", "zip", "source", "source_url", "source_id",
]

# OSM tags that mark a home-service trade, keyed by the label we export.
OSM_CATEGORY_TAGS = {
    "plumber": ['["craft"="plumber"]'],
    "electrician": ['["craft"="electrician"]'],
    "hvac contractor": ['["craft"="hvac"]'],
    "roofing contractor": ['["craft"="roofer"]'],
    "painter": ['["craft"="painter"]'],
    "handyman": ['["craft"="handyman"]'],
    "landscaping": ['["craft"="gardener"]', '["craft"="landscape_gardener"]'],
    "tree service": ['["craft"="tree_surgeon"]'],
    "house cleaning": ['["craft"="cleaning"]'],
    "window cleaning": ['["craft"="window_cleaner"]'],
    "chimney sweep": ['["craft"="chimney_sweeper"]'],
    "locksmith": ['["shop"="locksmith"]', '["craft"="locksmith"]'],
    "carpenter": ['["craft"="carpenter"]', '["craft"="joiner"]'],
    "flooring contractor": ['["craft"="floorer"]'],
    "tiler": ['["craft"="tiler"]'],
    "plasterer": ['["craft"="plasterer"]'],
    "stonemason": ['["craft"="stonemason"]'],
    "glazier": ['["craft"="glaziery"]'],
    "window contractor": ['["craft"="window_construction"]'],
    "metal fabricator": ['["craft"="metal_construction"]'],
    "insulation contractor": ['["craft"="insulation"]'],
    "scaffolder": ['["craft"="scaffolder"]'],
    "well drilling": ['["craft"="well_drilling"]'],
    "appliance repair": ['["craft"="electronics_repair"]', '["shop"="appliance"]'],
    "pest control": ['["office"="pest_control"]', '["craft"="pest_control"]'],
    "moving company": ['["office"="moving_company"]'],
    "builder": ['["craft"="builder"]', '["office"="construction_company"]'],
}

# One sweep that catches every mapped trade, including tags not listed above.
OSM_ALL_TRADES = ['["craft"]', '["shop"="locksmith"]', '["office"="pest_control"]',
                  '["office"="moving_company"]', '["office"="construction_company"]']

US_AREA_ID = 3600148838  # OSM relation 148838 = United States
OVERPASS_URLS = [
    "https://overpass-api.de/api/interpreter",
    "https://overpass.kumi.systems/api/interpreter",
    "https://overpass.private.coffee/api/interpreter",
]


# --------------------------------------------------------------------------
# Helpers
# --------------------------------------------------------------------------

def has_website(*values):
    return any((v or "").strip() for v in values)


def norm_phone(p):
    p = (p or "").strip()
    return re.sub(r"\s+", " ", p)


def split_us_address(formatted):
    """'123 Main St, Austin, TX 78701, USA' -> (city, state, zip)."""
    parts = [x.strip() for x in (formatted or "").split(",")]
    parts = [x for x in parts if x and x.upper() not in ("USA", "UNITED STATES")]
    city = state = zipc = ""
    if len(parts) >= 2:
        m = re.match(r"^([A-Z]{2})\s*(\d{5}(?:-\d{4})?)?$", parts[-1])
        if m:
            state, zipc = m.group(1), m.group(2) or ""
            city = parts[-2]
    return city, state, zipc


# --------------------------------------------------------------------------
# Google Places (New)
# --------------------------------------------------------------------------

GOOGLE_URL = "https://places.googleapis.com/v1/places:searchText"
GOOGLE_FIELDS = ",".join([
    "places.id", "places.displayName", "places.formattedAddress",
    "places.nationalPhoneNumber", "places.internationalPhoneNumber",
    "places.websiteUri", "places.googleMapsUri", "places.businessStatus",
    "places.addressComponents", "nextPageToken",
])


def google_post(api_key, body):
    r = requests.post(
        GOOGLE_URL, json=body, timeout=30,
        headers={"X-Goog-Api-Key": api_key, "X-Goog-FieldMask": GOOGLE_FIELDS,
                 "Content-Type": "application/json"},
    )
    if r.status_code != 200:
        raise RuntimeError(f"Google Places {r.status_code}: {r.text[:300]}")
    return r.json()


def google_components(place):
    out = {}
    for c in place.get("addressComponents", []) or []:
        for t in c.get("types", []):
            out.setdefault(t, c.get("shortText") or c.get("longText") or "")
    return out


def google_search(api_key, category, city, max_pages, post=google_post):
    """Yield lead dicts for one (category, city) query."""
    token = None
    for _ in range(max_pages):
        body = {"textQuery": f"{category} in {city}", "regionCode": "US",
                "languageCode": "en", "pageSize": 20}
        if token:
            body["pageToken"] = token
        data = post(api_key, body)
        for p in data.get("places", []):
            yield google_to_lead(p, category)
        token = data.get("nextPageToken")
        if not token:
            break
        time.sleep(2)  # Google requires a short delay before a page token is valid


def google_to_lead(p, category):
    comps = google_components(p)
    city, state, zipc = split_us_address(p.get("formattedAddress"))
    return {
        "business_name": (p.get("displayName") or {}).get("text", ""),
        "category": category,
        "phone": norm_phone(p.get("nationalPhoneNumber") or p.get("internationalPhoneNumber")),
        "email": "",  # Google never exposes emails
        "website": p.get("websiteUri", "") or "",
        "address": p.get("formattedAddress", "") or "",
        "city": comps.get("locality") or city,
        "state": comps.get("administrative_area_level_1") or state,
        "zip": comps.get("postal_code") or zipc,
        "source": "google_places",
        "source_url": p.get("googleMapsUri", "") or "",
        "source_id": p.get("id", "") or "",
        "_status": p.get("businessStatus", "OPERATIONAL"),
        "_country": comps.get("country", "US"),
    }


# --------------------------------------------------------------------------
# OpenStreetMap Overpass
# --------------------------------------------------------------------------

def overpass_area(name):
    """Build the Overpass area statement. Default is the whole US."""
    if not name or name.strip().lower() in ("us", "usa", "united states"):
        return f"area(id:{US_AREA_ID})->.searchArea;"
    safe = name.replace('"', '')
    return (f'area["name"="{safe}"]["boundary"="administrative"]'
            f'["admin_level"~"^(4|6|8)$"]->.searchArea;')


def overpass_query(tag_filters, area_stmt, timeout=300):
    """Businesses matching any tag filter that publish a phone number."""
    body = "".join(
        f'nwr{tf}["phone"](area.searchArea);nwr{tf}["contact:phone"](area.searchArea);'
        for tf in tag_filters
    )
    return f"[out:json][timeout:{timeout}];{area_stmt}({body});out tags center;"


def overpass_get(query):
    last = None
    for url in OVERPASS_URLS:
        try:
            r = requests.post(url, data={"data": query}, timeout=420)
            if r.status_code == 200:
                return r.json()
            last = f"{url} -> {r.status_code}"
        except requests.RequestException as e:
            last = f"{url} -> {e}"
    raise RuntimeError(f"Overpass unreachable: {last}")


def osm_search(category, tag_filters, area_stmt, get=overpass_get, timeout=300):
    data = get(overpass_query(tag_filters, area_stmt, timeout))
    for el in data.get("elements", []):
        t = el.get("tags", {})
        yield osm_to_lead(el, t, category)


def osm_to_lead(el, t, category=None):
    category = category or t.get("craft") or t.get("shop") or t.get("office") or ""
    street = " ".join(x for x in [t.get("addr:housenumber", ""), t.get("addr:street", "")] if x)
    city, state, zipc = t.get("addr:city", ""), t.get("addr:state", ""), t.get("addr:postcode", "")
    address = ", ".join(x for x in [street, city, f"{state} {zipc}".strip()] if x)
    return {
        "business_name": t.get("name", ""),
        "category": category,
        "phone": norm_phone(t.get("phone") or t.get("contact:phone")),
        "email": t.get("email") or t.get("contact:email") or "",
        "website": t.get("website") or t.get("contact:website") or t.get("url") or "",
        "address": address,
        "city": city, "state": state, "zip": zipc,
        "source": "openstreetmap",
        "source_url": f"https://www.openstreetmap.org/{el.get('type')}/{el.get('id')}",
        "source_id": f"{el.get('type')}/{el.get('id')}",
        "_status": "OPERATIONAL",
        "_country": t.get("addr:country", "US"),
    }


# --------------------------------------------------------------------------
# Filtering / output
# --------------------------------------------------------------------------

def qualifies(lead, include_with_website=False):
    if not lead["business_name"] or not lead["phone"]:
        return False
    if lead["_status"] not in ("OPERATIONAL", "", None):
        return False
    if lead["_country"] not in ("US", "USA", "United States", ""):
        return False
    if not include_with_website and has_website(lead["website"]):
        return False
    return True


def load_existing_ids(path):
    ids = set()
    if path.exists():
        with path.open(newline="", encoding="utf-8") as f:
            for row in csv.DictReader(f):
                if row.get("source_id"):
                    ids.add(row["source_id"])
    return ids


def write_leads(path, leads):
    new_file = not path.exists()
    with path.open("a", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=COLUMNS, extrasaction="ignore")
        if new_file:
            w.writeheader()
        for lead in leads:
            w.writerow(lead)


def run(args, post=google_post, get=overpass_get):
    out = Path(args.out)
    seen = load_existing_ids(out)
    found = []
    limit = args.limit or 10 ** 9

    def take(lead):
        if lead["source_id"] in seen or not qualifies(lead, args.include_with_website):
            return
        seen.add(lead["source_id"])
        found.append(lead)
        print(f"  + {lead['business_name']}  {lead['phone']}  {lead['city']}, {lead['state']}")

    if args.backend == "google":
        key = os.environ.get("GOOGLE_MAPS_API_KEY", "").strip()
        if not key:
            sys.exit("Set GOOGLE_MAPS_API_KEY, or use --backend osm")
        for city in args.cities:
            for cat in args.categories:
                if len(found) >= limit:
                    break
                print(f"[google] {cat} in {city}")
                for lead in google_search(key, cat, city, args.max_pages, post=post):
                    take(lead)
                    if len(found) >= limit:
                        break
                time.sleep(args.delay)
            if len(found) >= limit:
                break
    else:
        area_stmt = overpass_area(args.osm_area)
        where = args.osm_area or "the whole US"
        if args.osm_all_trades:
            print(f"[osm] every mapped trade in {where} (one sweep, can take minutes)")
            for lead in osm_search(None, OSM_ALL_TRADES, area_stmt, get=get,
                                   timeout=args.osm_timeout):
                take(lead)
                if len(found) >= limit:
                    break
        else:
            for cat in args.categories:
                tags = OSM_CATEGORY_TAGS.get(cat)
                if not tags:
                    print(f"[osm] skipping '{cat}' (no OSM tag; try --osm-all-trades)")
                    continue
                if len(found) >= limit:
                    break
                print(f"[osm] {cat} in {where}")
                for lead in osm_search(cat, tags, area_stmt, get=get,
                                       timeout=args.osm_timeout):
                    take(lead)
                    if len(found) >= limit:
                        break
                time.sleep(args.delay)

    write_leads(out, found)
    print(f"\n{len(found)} new leads written to {out} ({len(seen)} total in file)")
    return found


def parse_args(argv=None):
    p = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("--backend", choices=["google", "osm"],
                   default="google" if os.environ.get("GOOGLE_MAPS_API_KEY") else "osm")
    p.add_argument("--categories", nargs="+", default=DEFAULT_CATEGORIES)
    p.add_argument("--cities", nargs="+", default=DEFAULT_CITIES, help='e.g. "Austin, TX"')
    p.add_argument("--cities-file", help="text file, one 'City, ST' per line")
    p.add_argument("--limit", type=int, default=100, help="stop after this many new leads (0 = no limit)")
    p.add_argument("--max-pages", type=int, default=3, help="Google pages per query (20 results each)")
    p.add_argument("--delay", type=float, default=1.0, help="seconds between queries")
    p.add_argument("--include-with-website", action="store_true",
                   help="keep businesses that DO have a website (default: drop them)")
    p.add_argument("--osm-area", default="", help='OSM backend: limit to a state/county/city, e.g. "Texas". Default: whole US')
    p.add_argument("--osm-all-trades", action="store_true", help="OSM backend: one sweep across every mapped trade")
    p.add_argument("--osm-timeout", type=int, default=300, help="OSM backend: Overpass server-side timeout in seconds")
    p.add_argument("--out", default="home_services_leads.csv")
    a = p.parse_args(argv)
    if a.cities_file:
        a.cities = [l.strip() for l in Path(a.cities_file).read_text().splitlines() if l.strip()]
    return a


if __name__ == "__main__":
    run(parse_args())
