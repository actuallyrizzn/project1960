# Project 1960 scraper (PHP)

CLI: `php bin/scrape.php`

Fetches DOJ press releases from `https://www.justice.gov/api/v1/press_releases.json`, filters for 18 U.S.C. § 1960 / crypto keywords, and `INSERT OR IGNORE`s into SQLite (`DATABASE_PATH` or `db/doj_cases.db`).

## Incremental mode (default)

Newest-first via `sort_by=date&sort_order=DESC`. Watermark = `MAX(cases.date)` (or `--since=UNIX`). Walks `page=0…` and **stops** when an item’s date is older than the watermark — does **not** re-download the archive.

Prototype proof: `tools/doj-incremental-poll/` (Tasks #4000).

| Flag | Meaning |
|------|---------|
| `--incremental` | Newest-first watermark poll (**default**) |
| `--since=UNIX` | Override watermark |
| `--legacy-pages` | Old oldest-first `scraper_state.last_page` crawl |
| `--max-pages=N` | Stop after N pages (incremental default cap 50) |
| `--limit=N` | Alias for `--max-pages` |
| `--page-start=N` | Legacy: start at page N (implies legacy mode) |
| `--wait=SECONDS` | Delay between pages (default 2) |
| `--dry-run` | Fetch / count only; no INSERT |
| `--verbose` | Extra log lines |
| `--help` | Help text |

## Legacy page cursor

Table `scraper_state` key `last_page` — only used with `--legacy-pages` / `--page-start`. Prod tip at ~5446 was returning **empty** pages; that is why incremental mode exists.

## Cron (multihost)

Ada owns multihost crontab. Example:

```cron
17 3,15 * * * DATABASE_PATH=/var/www/project1960.rizzn.net/db/doj_cases.db \
  /usr/bin/php /root/repos/project1960.rizzn.net/bin/scrape.php --max-pages=5 --wait=2 \
  >> /var/log/project1960-scrape.log 2>&1
```

No flag needed for incremental (default). Cap `--max-pages` so a bad watermark cannot walk forever.

## Keyword filters

`KeywordFilters` uses regex + case-insensitive substrings (no rapidfuzz). See class docblock.
