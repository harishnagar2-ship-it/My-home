#!/usr/bin/env python3
"""
Score each lead's website on how badly it needs a redesign.

Reads a CSV produced by find_leads.py (run it with --include-with-website so
businesses that DO have a site are kept), fetches every website, looks for
signals of an old or broken site, and writes the same rows back with a
redesign score, a lead type and a plain-English list of problems.

Examples
  python find_leads.py --include-with-website --limit 300 --out all.csv
  python audit_sites.py all.csv --out scored.csv
  python audit_sites.py all.csv --min-score 40 --out hot.csv

Only Python 3.8+ and `requests` are required (pip install requests).
"""
import argparse
import csv
import datetime as dt
import re
import sys
import time
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

try:
    import requests
except ImportError:  # pragma: no cover
    sys.exit("pip install requests")

UA = ("Mozilla/5.0 (compatible; SiteAudit/1.0; "
      "web design prospecting; +https://example.com/bot)")

EXTRA_COLUMNS = ["lead_type", "redesign_score", "site_issues", "site_final_url",
                 "site_http_status", "site_load_ms", "site_checked_at"]

# signal -> (points, human-readable description)
SIGNALS = {
    "no_website":           (100, "no website at all"),
    "site_unreachable":     (40, "website does not load"),
    "site_error":           (35, "website returns an error page"),
    "parked_page":          (35, "parked or 'coming soon' placeholder"),
    "not_mobile_friendly":  (30, "not mobile friendly (no viewport tag)"),
    "flash_content":        (25, "uses Flash or legacy embeds"),
    "no_https":             (20, "no HTTPS"),
    "ssl_broken":           (20, "broken HTTPS certificate"),
    "old_generator":        (20, "built with obsolete software"),
    "legacy_html":          (15, "1990s-era HTML tags"),
    "table_layout":         (15, "table-based layout"),
    "stale_copyright":      (10, "copyright year out of date"),
    "thin_page":            (10, "almost no content"),
    "slow_site":            (8,  "slow to load"),
    "diy_builder":          (5,  "free drag-and-drop site builder"),
}

OLD_GENERATORS = re.compile(
    r"frontpage|dreamweaver|iweb|microsoft word|publisher|geocities|netobjects|"
    r"homestead|joomla!?\s*[12]\.|drupal\s*[67]|wordpress\s*[1-4]\.",
    re.I)

BUILDERS = re.compile(r"wix\.com|weebly|godaddy(sites|\.com/websites)|"
                      r"sitebuilder|homestead\.com|network\s*solutions", re.I)

PARKED = re.compile(
    r"this domain (is|may be) for sale|buy this domain|domain for sale|"
    r"under construction|coming soon|site (is )?not (yet )?(published|configured)|"
    r"default web site page|welcome to nginx|apache2 (ubuntu|debian) default page|"
    r"future home of", re.I)

LEGACY_HTML = re.compile(r"<font\b|<center\b|<marquee\b|<blink\b|"
                         r"\sbgcolor=|<frameset\b|<applet\b", re.I)

FLASH = re.compile(r"\.swf\b|application/x-shockwave-flash|<applet\b|"
                   r"classid=[\"']clsid:D27CDB6E", re.I)

VIEWPORT = re.compile(r"<meta[^>]+name=[\"']?viewport", re.I)
GENERATOR = re.compile(r"<meta[^>]+name=[\"']?generator[\"']?[^>]*content=[\"']([^\"']+)", re.I)
COPYRIGHT = re.compile(r"(?:©|&copy;|copyright)[^.\n]{0,40}?(19|20)(\d{2})", re.I)
MODERN_CSS = re.compile(r"display\s*:\s*(flex|grid)|grid-template|"
                        r"tailwind|bootstrap|@media[^{]*\(max-width", re.I)


def analyze(html, final_url, status, elapsed_ms, now_year=None):
    """Pure scoring function. Returns (score, [signal keys])."""
    now_year = now_year or dt.date.today().year
    issues = []

    if status is None:
        return SIGNALS["site_unreachable"][0], ["site_unreachable"]
    if status >= 400:
        issues.append("site_error")

    text = html or ""
    head = text[:200000]

    if final_url.startswith("http://"):
        issues.append("no_https")
    if PARKED.search(head[:8000]):
        issues.append("parked_page")
    if not VIEWPORT.search(head):
        issues.append("not_mobile_friendly")
    if FLASH.search(head):
        issues.append("flash_content")
    if LEGACY_HTML.search(head):
        issues.append("legacy_html")

    g = GENERATOR.search(head)
    if g and OLD_GENERATORS.search(g.group(1)):
        issues.append("old_generator")
    if BUILDERS.search(head):
        issues.append("diy_builder")

    # Table layout: tables used for structure, with no modern CSS anywhere.
    if re.search(r"<table\b", head, re.I) and not MODERN_CSS.search(head):
        if re.search(r"<table[^>]*(cellpadding|cellspacing|border\s*=|width\s*=)", head, re.I):
            issues.append("table_layout")

    years = [int(m.group(1) + m.group(2)) for m in COPYRIGHT.finditer(text)]
    if years and max(years) < now_year - 2:
        issues.append("stale_copyright")

    if len(re.sub(r"<[^>]+>", " ", text).split()) < 60:
        issues.append("thin_page")
    if elapsed_ms and elapsed_ms > 4000:
        issues.append("slow_site")

    score = min(100, sum(SIGNALS[i][0] for i in issues))
    return score, issues


def fetch(url, timeout):
    """Return (final_url, status, html, elapsed_ms). status None = unreachable."""
    if not re.match(r"^https?://", url, re.I):
        url = "https://" + url
    started = time.time()
    try:
        r = requests.get(url, timeout=timeout, allow_redirects=True,
                         headers={"User-Agent": UA})
        return r.url, r.status_code, r.text, int((time.time() - started) * 1000)
    except requests.exceptions.SSLError:
        http_url = re.sub(r"^https://", "http://", url, flags=re.I)
        try:
            r = requests.get(http_url, timeout=timeout, allow_redirects=True,
                             headers={"User-Agent": UA})
            return r.url, r.status_code, r.text, int((time.time() - started) * 1000)
        except requests.RequestException:
            return url, None, "", int((time.time() - started) * 1000)
    except requests.RequestException:
        return url, None, "", int((time.time() - started) * 1000)


def audit_row(row, timeout, fetcher=fetch):
    site = (row.get("website") or "").strip()
    checked = dt.datetime.now().isoformat(timespec="seconds")

    if not site:
        row.update(lead_type="no_website", redesign_score=100,
                   site_issues=SIGNALS["no_website"][1], site_final_url="",
                   site_http_status="", site_load_ms="", site_checked_at=checked)
        return row

    final_url, status, html, ms = fetcher(site, timeout)
    score, issues = analyze(html, final_url, status, ms)
    if score >= 30:
        lead_type = "outdated_website"
    elif score > 0:
        lead_type = "dated_website"
    else:
        lead_type = "modern_website"

    row.update(lead_type=lead_type, redesign_score=score,
               site_issues="; ".join(SIGNALS[i][1] for i in issues),
               site_final_url=final_url,
               site_http_status="" if status is None else status,
               site_load_ms=ms, site_checked_at=checked)
    return row


def run(args, fetcher=fetch):
    src = Path(args.csv)
    with src.open(newline="", encoding="utf-8") as f:
        rows = list(csv.DictReader(f))
    if not rows:
        sys.exit(f"{src} has no rows")

    print(f"Auditing {len(rows)} leads from {src}")
    with ThreadPoolExecutor(max_workers=args.workers) as pool:
        done = list(pool.map(lambda r: audit_row(r, args.timeout, fetcher), rows))

    for r in done:
        print(f"  {r['redesign_score']:>3}  {r['lead_type']:<17} "
              f"{r.get('business_name', '')[:34]:<34} {r['site_issues'][:60]}")

    keep = [r for r in done if int(r["redesign_score"]) >= args.min_score]
    keep.sort(key=lambda r: -int(r["redesign_score"]))

    cols = list(rows[0].keys()) + [c for c in EXTRA_COLUMNS if c not in rows[0]]
    out = Path(args.out)
    with out.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=cols, extrasaction="ignore")
        w.writeheader()
        w.writerows(keep)

    buckets = {}
    for r in keep:
        buckets[r["lead_type"]] = buckets.get(r["lead_type"], 0) + 1
    print(f"\n{len(keep)} of {len(done)} leads scored >= {args.min_score} -> {out}")
    for k, v in sorted(buckets.items(), key=lambda kv: -kv[1]):
        print(f"  {v:>4}  {k}")
    return keep


def parse_args(argv=None):
    p = argparse.ArgumentParser(description=__doc__,
                                formatter_class=argparse.RawDescriptionHelpFormatter)
    p.add_argument("csv", help="CSV from find_leads.py (needs a 'website' column)")
    p.add_argument("--out", default="leads_scored.csv")
    p.add_argument("--min-score", type=int, default=30,
                   help="drop leads scoring below this (default 30)")
    p.add_argument("--workers", type=int, default=8)
    p.add_argument("--timeout", type=float, default=12.0)
    return p.parse_args(argv)


if __name__ == "__main__":
    run(parse_args())
