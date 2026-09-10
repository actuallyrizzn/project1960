# Project 1960 — legacy Python runbook

This directory is the **previous Flask / scraper / Venice enrich** codebase, relocated under `legacy/` (R0) so the repo root can become the PHP multihost tree.

**Do not treat NewDev `/root/justice` as the git layout.** Until cutover, NewDev may still be a **flat** checkout (no `legacy/` prefix). The GitHub repo (`actuallyrizzn/project1960`) is the source of truth: run Python from **`legacy/`** in this repo.

## Quick map

| Concern | Path / command |
|---------|----------------|
| Flask explorer | `python3 app.py` (from `legacy/`) |
| DOJ scraper | `python3 scraper.py` |
| Enrich (modular) | `python3 enrich_cases_modular.py …` |
| Verify §1960 (modular) | `python3 1960-verify_modular.py …` |
| Cron notes | [`CRON_SETUP.md`](CRON_SETUP.md) — **cwd must be `legacy/`** |
| Longer docs | [`docs/`](docs/) |
| Changelog | [`CHANGELOG.md`](CHANGELOG.md) |

## Setup (repo clone)

```bash
cd /path/to/project1960/legacy
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
cp ../env.example .env
# Edit .env: VENICE_API_KEY, DATABASE_NAME / paths
```

Env template lives at **repo root** `env.example` (shared with future PHP). CourtListener token (Phase 2) is vaulted at `~/.ssh/courtlistener-api.pass` — not required for legacy DOJ scrape/enrich.

## NewDev (`/root/justice`) until cutover

Live ops (2026-09): Flask on `:5000`, SQLite `doj_cases.db`, cron with **`python3`**.

- Deploying from git: either run from a clone’s `legacy/` directory, **or** keep syncing a flat tree on NewDev that mirrors these files without the `legacy/` prefix.
- Do **not** assume `cd /root/justice && git pull` alone matches this layout after R0 — check whether the NewDev tree was updated to nested `legacy/`.
- Database backups stay on the host (`doj_cases.db.bak.*`); never commit DB files.

## Common commands

All commands assume `cwd` = this directory (`legacy/`).

```bash
# Scrape DOJ press releases into SQLite
python3 scraper.py

# Enrich structured tables (modular)
python3 enrich_cases_modular.py --table case_metadata --limit 20

# Dry-run enrich
python3 enrich_cases_modular.py --table case_metadata --limit 5 --dry-run

# Verify §1960 classifications
python3 1960-verify_modular.py --limit 20

# Flask UI (default 0.0.0.0:5000 from env)
python3 app.py
```

Monolith scripts `enrich_cases.py` / `1960-verify.py` remain for reference; prefer the `*_modular.py` entrypoints.

## Imports

Package imports (`from utils…`, `from modules…`, `from orchestrators…`) resolve when the process **cwd** (or `PYTHONPATH`) is `legacy/`. Tests under `tests/` insert the parent of `tests/` onto `sys.path` (that parent is now `legacy/`).

Smoke:

```bash
cd legacy
PYTHONPATH=. python3 -c 'from modules.enrichment import schemas; print(len(schemas.get_all_schemas()))'
```

## PHP port / cutover

Public site target: **project1960.rizzn.net**. Slice map: DSC Tasks Doc #1308. After cutover, this tree is retired (C5). Enrich/verify host language is decided on E0 / CL-R3 — until then, keep running Venice jobs from this legacy tree (or NewDev flat mirror).

## License

Same as repo root: CC BY-SA 4.0 — see [`../LICENSE`](../LICENSE).
