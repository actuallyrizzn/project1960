# Project 1960

Data journalism on **18 U.S.C. § 1960** / DOJ press releases (Operation Chokepoint 2.0 / crypto-adjacent money-transmission cases).

**Live site:** https://project1960.rizzn.net  
**Board:** [DSC Tasks #65](https://tasks.decisionsciencecorp.com/admin/project.php?id=65) · slice map [Doc #1308](https://tasks.decisionsciencecorp.com/admin/doc.php?id=1308) · ops [Doc #1307](https://tasks.decisionsciencecorp.com/admin/doc.php?id=1307)

## Layout

| Path | Role |
|------|------|
| `public/` | Multihost PHP docroot (`index.php`, `includes/`, `assets/`, directory entrypoints) |
| `bin/` | CLI: `scrape.php`, `match.php`, `ingest-docs.php`, `download-docs.php`, `ocr-docs.php`, `extract-people.php`, `recap-fetch.php`, `patterns-export.php` |
| `legacy/` | Previous Python Flask app + Venice enrich/verify (kept until soak) |
| `docs/` | `courtlistener.md`, `ocr.md`, `scraper.md` |
| `env.example` | Venice, DB path, CourtListener token hints |

## PHP app (primary)

```bash
composer install
# sibling checkout: ../courtlistener-sdk (path repo in composer.json)
cp env.example .env   # set DATABASE_PATH / COURTLISTENER_API_TOKEN / VENICE_API_KEY as needed

./vendor/bin/phpunit
php -S 127.0.0.1:8080 -t public   # local only; prod is multihost

# Slow-drip CL pipeline (prefer --limit / --wait — do not burn the API)
php bin/match.php --limit=5 --wait=10
php bin/ingest-docs.php --limit=5 --wait=5
php bin/download-docs.php --limit=5 --wait=2
php bin/ocr-docs.php --limit=5 --metrics   # needs tesseract+poppler (see docs/ocr.md)
php bin/extract-people.php --limit=3 --wait=2
```

Design smoke (Playwright, ephemeral `php -S`):

```bash
python3 tools/design-smoke/verify.py
```

## CourtListener (Phase 2)

Uses existing SDK: [actuallyrizzn/courtlistener-sdk](https://github.com/actuallyrizzn/courtlistener-sdk) (PHP path dependency). Do not reinvent the HTTP client. Matcher + ingest + OCR + Venice people extract + patterns UI ship in this repo.

## Legacy Python (enrich backlog)

Venice verify/enrich for press-release tables can still run from `legacy/` on NewDev writing the same SQLite. See `legacy/README.md`.

## License

Creative Commons Attribution-ShareAlike 4.0 International — see [LICENSE](LICENSE).
