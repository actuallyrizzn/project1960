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

Queries are **party-first** (optional real docket number). Press IDs like `CAS25-…` are skipped. Designed for **slow drip**: small `--limit`, `--wait` between cases, cron — not a one-shot burn of all verified seeds.

**Live DB note:** prod `cases` has no SQLite PRIMARY KEY on `id` (legacy). CL tables that touch `case_id` omit FKs to `cases` so inserts work.

**Rate limits:** default `--wait=2`; raise to 5–10s on token 429s. CLI backs off 30s on `RateLimitException` and continues.

## Document ingest CLI (CL-I2)

```bash
set -a && . ~/.ssh/courtlistener-api.pass && set +a
php bin/ingest-docs.php --limit=5 --wait=5 --dry-run --verbose
php bin/ingest-docs.php --limit=10 --wait=5 --verbose
```

Pulls DocketEntries + RecapDocuments for rows in `case_courtlistener_links`, upserts into `courtlistener_documents` (idempotent by `cl_document_id`).

## Multihost

On multihost SRC_DIR (`/root/repos/project1960.rizzn.net`):

1. `git clone https://github.com/actuallyrizzn/courtlistener-sdk.git /root/repos/courtlistener-sdk` (once)
2. Ensure path `../courtlistener-sdk/php` resolves from the project1960 clone
3. `composer install --no-dev`
4. Export `COURTLISTENER_API_TOKEN` for CLI workers (vault inject — not site env as SoT)
