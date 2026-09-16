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

**Rate limits (this is why the drip was all 429s):** Free authenticated CourtListener caps are about **5/min, 50/hour, 125/day** (rolling). Check live with `GET /api/rest/v4/api-usage/` (own throttle). On 2026-09-16 the token showed **125/125 day used** after our match+ingest+manual batch work — every subsequent cron tick was guaranteed to 429.

Sustainable drip (installed on multihost):

```cron
# 1 seed / hour, paced for free-auth daily budget (~125/day shared with ingest)
0 * * * * cd /root/repos/project1960.rizzn.net && set -a && . /root/.ssh/courtlistener-api.pass && set +a && DATABASE_PATH=/var/www/project1960.rizzn.net/db/doj_cases.db php bin/match.php --limit=1 --wait=60 >> /var/log/project1960-cl-match.log 2>&1
# Ingest 1 linked docket every 6h (1 API call: docket-entries/?docket= with nested docs) — skip when day quota empty
20 */6 * * * cd /root/repos/project1960.rizzn.net && set -a && . /root/.ssh/courtlistener-api.pass && set +a && DATABASE_PATH=/var/www/project1960.rizzn.net/db/doj_cases.db php bin/ingest-docs.php --limit=1 --wait=30 >> /var/log/project1960-cl-ingest.log 2>&1
# Download free PDF bytes (no CL API quota) after ingest has rows
40 */6 * * * cd /root/repos/project1960.rizzn.net && DATABASE_PATH=/var/www/project1960.rizzn.net/db/doj_cases.db CL_DOCS_PATH=/var/www/project1960.rizzn.net/storage/cl-docs php bin/download-docs.php --limit=5 --wait=2 >> /var/log/project1960-cl-download.log 2>&1
```

`match.php` / `ingest-docs.php` now: check api-usage before starting, **abort the batch on first 429**, flock against overlap, and search with **type=d first** (not d+r back-to-back).

**Ingest API paths (fixed):** `docket-entries/?docket={id}` (official RelatedFilter). Do **not** call `recap-documents/?docket=` (400 `unknown_params: docket`) — harvest nested `recap_documents` from entries, or use `recap-documents/?docket_entry__docket=`. Nested SDK `dockets/{id}/…` paths **404** on prod. Queue prefers **linked dockets with zero local docs**, strong matches before weak.

**Entry descriptions without PDFs:** when an entry has no RECAP file rows, ingest still stores the docket-entry description (synthetic `cl_document_id = -entry_id`, `has_plaintext`, text copy for extract). Case pages badge these as **desc**. When a RECAP row exists but its label is thinner than the entry text, the longer entry description is kept.

**Public docket links:** CourtListener 404s on bare `/docket/{id}/` — pages must use `/docket/{id}/{slug}/` (`CourtListenerUrl::docket`).

**What that means in practice**

| Stage | Cadence | Batch | On Enrichment activity feed |
|-------|---------|-------|------------------------------|
| `bin/match.php` | every **hour** | **1** seed | `cl_match` (or `skipped` when quota empty) |
| `bin/ingest-docs.php` | every **6 hours** | **1** docket (zero-doc first) | `cl_ingest` |
| `bin/download-docs.php` | every **6 hours** (+20m) | **5** free URLs | `cl_download` |
| OCR / extract | **not on cron yet** | — | when those CLIs run |

Do **not** raise `--limit` into the tens on free-auth without raising the CourtListener membership / commercial cap.

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
