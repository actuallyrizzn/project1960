# Project 1960 scraper (PHP)

CLI: `php bin/scrape.php`

Fetches DOJ press releases from `https://www.justice.gov/api/v1/press_releases.json` (pagesize 50), filters for 18 U.S.C. § 1960 / crypto keywords, and `INSERT OR IGNORE`s into SQLite (`DATABASE_PATH` or `db/doj_cases.db`).

## Flags

| Flag | Meaning |
|------|---------|
| `--max-pages=N` | Stop after N pages |
| `--limit=N` | Alias for `--max-pages` |
| `--page-start=N` | Start at page N (overrides `scraper_state.last_page`) |
| `--wait=SECONDS` | Delay between pages (default 2) |
| `--dry-run` | Fetch only; no INSERT |
| `--verbose` | Extra log lines |
| `--help` | Help text |

## State

Table `scraper_state` key `last_page` resumes the crawl. `--page-start` sets the next page without requiring a prior run.

## Cron (multihost)

Ada owns multihost crontab. Example only:

```cron
15 */6 * * * cd /var/www/project1960.rizzn.net && \
  DATABASE_PATH=/var/www/project1960.rizzn.net/../db/doj_cases.db \
  php bin/scrape.php --max-pages=2 --wait=2 >> /var/log/project1960-scrape.log 2>&1
```

Do not point a live scrape at production until cutover (see SC5 / C* slices).

## Keyword filters

`KeywordFilters` uses regex + case-insensitive substrings (no rapidfuzz). See class docblock.
