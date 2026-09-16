# CourtListener SDK wiring (CL-I1)

Project 1960 does **not** ship a custom CourtListener HTTP client.

## Dependency

- Package: `courtlistener/sdk-php` from https://github.com/actuallyrizzn/courtlistener-sdk (`php/`)
- Composer path repo: `../courtlistener-sdk/php` (symlink) — clone the SDK as a **sibling** of `project1960` on any host that runs `composer install`
- Factory: `Project1960\CourtListener\ClientFactory::make()` → `CourtListener\CourtListenerClient`

## Token

```bash
set -a && . ~/.ssh/courtlistener-api.pass && set +a
# COURTLISTENER_API_TOKEN=…
```

Never commit the token. Tasks Doc #1310.

## Smoke

```bash
set -a && . ~/.ssh/courtlistener-api.pass && set +a
php -r 'require "vendor/autoload.php";
$c = Project1960\CourtListener\ClientFactory::make();
$r = $c->dockets->listDockets(["page_size" => 1]);
echo "ok keys=" . implode(",", array_keys($r)) . "\n";'
```

## Match CLI (CL-M1 / CL-M2)

```bash
set -a && . ~/.ssh/courtlistener-api.pass && set +a
# Dry-run first (no DB writes for links/reviews)
php bin/match.php --limit=5 --wait=5 --dry-run --verbose
# Persist best matches + flag ambiguous into cl_match_reviews
php bin/match.php --limit=50 --wait=5 --verbose
```

Uses `CourtListener\CourtListenerClient` Search (`type=d` then `type=r`) via `SdkSearchGateway` — no hand-rolled HTTP.

Queries are **docket-first** when `case_number` looks like a federal docket (`YY-cr-NNNN`, PACER, `19 Cr. 838`, `E.D.N.Y. Docket No. …`). Normalized query + optional `court=` from `district_office`. Press IDs like `CAS25-…` fall back to party/title. Designed for **slow drip**: small `--limit`, `--wait` between cases, cron — not a one-shot burn of all verified seeds.

**Accept rule:** docket **core** + matching **court** → confidence ≥ `0.70` (`DOCKET_COURT_ACCEPT`) even if the press defendant is not the CL caption party. Duplicate CL ids for the same PACER docket are collapsed before the ambiguity gap check.

**No-docket lane:** when there is no courtish docket number, search up to three defendants (quoted firm names for Inc/LLC/etc.), prefer `United States v.` criminal dockets in the matching district, apply year proximity vs press date, and auto-accept party+court hits at ≥ `0.68` (`PARTY_COURT_ACCEPT` / `auto_party_court`).

**Weak accepts:** scores in `[0.50, 0.65)` with a clear top hit (gap ≥ `0.12`) still **link** the docket (`match_method=weak_accept`) and flag `cl_match_reviews.reason=weak_accept` so RECAP metadata/docs can be pulled for fact-pattern review. OCR and Venice people-extract **defer** these dockets to the end of their queues (`LinkQueuePriority`) so inference prefers strong auto-links first. Ambiguous (gap too small) and scores below `0.50` stay review-only with no link.

**Live DB note:** prod `cases` has no SQLite PRIMARY KEY on `id` (legacy). CL tables that touch `case_id` omit FKs to `cases` so inserts work.

**Rate limits:** default `--wait=2`; raise to 5–10s on token 429s. CLI backs off 30s on `RateLimitException` and continues.

**Seed selection:** `loadSeeds` skips cases already in `case_courtlistener_links` or `cl_match_reviews`, and **prefers known-good court docket numbers** over press-id-only verified rows so cron starts the machine on high-confidence seeds. Prototype notes: Tasks Doc #1358 / `tools/cl-match-prototype/`.

## Slow-drip cron (multihost)

Token lives in `/root/.ssh/courtlistener-api.pass` (vault copy — not `sites/*.env`). Prod DB:

`DATABASE_PATH=/var/www/project1960.rizzn.net/db/doj_cases.db`

Example (Ada/Otto host crontab — custom lines, not `devops__add_cron`):

```cron
# CourtListener match: 3 verified seeds / 30 min, 15s between searches (back off on 429)
*/30 * * * * cd /root/repos/project1960.rizzn.net && set -a && . /root/.ssh/courtlistener-api.pass && set +a && DATABASE_PATH=/var/www/project1960.rizzn.net/db/doj_cases.db php bin/match.php --limit=3 --wait=15 >> /var/log/project1960-cl-match.log 2>&1
# Document metadata for newly linked dockets
15 */2 * * * cd /root/repos/project1960.rizzn.net && set -a && . /root/.ssh/courtlistener-api.pass && set +a && DATABASE_PATH=/var/www/project1960.rizzn.net/db/doj_cases.db php bin/ingest-docs.php --limit=10 --wait=5 >> /var/log/project1960-cl-ingest.log 2>&1
```

**What that means in practice**

| Stage | Cadence | Batch | Gap | On Enrichment activity feed |
|-------|---------|-------|-----|------------------------------|
| `bin/match.php` | every **30 min** | **3** verified cases not yet linked/reviewed | **15s** between cases (+30s on 429) | `cl_match` success / weak_accept / skipped / **error** (incl. rate limit) |
| `bin/ingest-docs.php` | every **2 hours** at `:15` | up to **10** linked dockets | **5s** (+30s on 429) | `cl_ingest` |
| download / OCR / extract | **not on cron yet** | — | — | only when those CLIs are run by hand |

Seed picker skips anything already in `case_courtlistener_links` **or** `cl_match_reviews`, and prefers cases with real federal docket numbers. ~300 verified cases remain matchable after the 19 linked ones.

Do **not** raise `--limit` into the hundreds or drop `--wait` without Mark go.

**Activity log:** the Enrichment page reads `enrichment_activity_log`. Legacy Venice enrichment wrote here; as of the pipeline logging change, CourtListener **match / ingest / download / OCR / extract** also append rows (`table_name` = stage such as `cl_match`). Statuses: `success`, `skipped`, `error`, `weak_accept`.

## Document ingest CLI (CL-I2)

```bash
set -a && . ~/.ssh/courtlistener-api.pass && set +a
php bin/ingest-docs.php --limit=5 --wait=5 --dry-run --verbose
php bin/ingest-docs.php --limit=10 --wait=5 --verbose
```

## Document download CLI (CL-I3)

```bash
php bin/download-docs.php --limit=10 --wait=2 --dry-run --verbose
php bin/download-docs.php --limit=10 --wait=2 --verbose
```

Writes free HTTPS CourtListener/IA files under `storage/cl-docs/` (or `CL_DOCS_PATH`). Records `local_path` + `content_sha256`. PACER-only / relative paths → `download_status=skipped_pacer` (CL-I4).


## Multihost

On multihost SRC_DIR (`/root/repos/project1960.rizzn.net`):

1. `git clone https://github.com/actuallyrizzn/courtlistener-sdk.git /root/repos/courtlistener-sdk` (once)
2. Ensure path `../courtlistener-sdk/php` resolves from the project1960 clone
3. `composer install --no-dev`
4. Export `COURTLISTENER_API_TOKEN` for CLI workers (vault inject — not site env as SoT)
