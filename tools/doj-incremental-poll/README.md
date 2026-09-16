# DOJ incremental poll prototype

Stand-alone proof for Tasks **#4000** — **not** production `bin/scrape.php` yet.

## Problem

Prod `scraper_state.last_page` sits at the end of the default (oldest-first) archive, so cron fetches empty pages and never sees new releases.

## Approach

1. Query `https://www.justice.gov/api/v1/press_releases.json` with `sort_by=date&sort_order=DESC` (newest first).
2. Watermark = `MAX(CAST(date AS INTEGER))` from `cases` (or `--since=`).
3. Walk `page=0,1,…` until an item’s `date` is **strictly older** than the watermark, then **stop**.
4. Count keyword hits that would store (does not INSERT in `--dry-run`).

## Run

```bash
# Against local/prod DB path (read-only for watermark + known ids)
DATABASE_PATH=/path/to/doj_cases.db php tools/doj-incremental-poll/prototype.php --dry-run --verbose --max-pages=5

# Explicit watermark
php tools/doj-incremental-poll/prototype.php --since=1789387200 --dry-run --verbose
```

Expect `stopped=watermark` after a small number of pages when the DB tip is recent.
