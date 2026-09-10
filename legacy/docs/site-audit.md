# Project1960 ‒ Comprehensive Site Audit (2025-07-16)

> This report captures the current state of the codebase with an emphasis on the underlying database design.  It is intended to serve as a technical reference for the upcoming planning cycle.

## 1  Executive Summary

Project1960 is a Python-based pipeline that ingests DOJ press releases, classifies them with AI, enriches confirmed cases with structured facts, and surfaces the data through a Flask dashboard & JSON API.  The build is mature and already in production use, but several architectural gaps were identified—especially around the evolution of the relational schema—that we should address before scaling further.

Key findings:

* The application flow is **well-defined** and split into logical stages (scraper → verification → enrichment → presentation).
* Database access is **hand-rolled with `sqlite3`**, no ORM/migration layer. Multiple scripts duplicate or mutate schema definitions.
* **SQLite** is convenient for a single-user workflow, but will become a bottleneck for concurrency, large data volumes, and cloud deployment.
* Documentation in `docs/database-schema.md` is strong but has already diverged from the live schema in several areas (column names & PK fields).

## 2  End-to-End Data Pipeline

| Stage | Code entry-point | Key tables | Notes |
|-------|-----------------|------------|-------|
| 1. Scraping | `scraper.py` (`fetch_all`) | `cases`, `scraper_state` | Filters by keyword and inserts raw press releases. |
| 2. 1960 Verification | `modules/verification/*` → `orchestrators/verification_orchestrator.py` | `cases` (adds `classification`, `verified_1960`, `verified_crypto`) | Adds columns on the fly if missing. |
| 3. Enrichment | `modules/enrichment/*` → `orchestrators/enrichment_orchestrator.py` | Eight enrichment tables + `enrichment_activity_log` | AI extraction → `store_extracted_data`. |
| 4. Presentation | `app.py` & Jinja templates | Read-only | Serves dashboard, API, and enrichment detail pages. |

## 3  Database Architecture (Live Schema)

Below is the authoritative schema as built at runtime (obtained by static analysis of `modules/enrichment/schemas.py`, `scraper.py` and `1960-verify.py`).  Primary keys are **bold**, foreign keys are _italic_.

### 3.1 Core Table

```
cases
─────
**id**               TEXT
 title              TEXT
 date               TEXT
 body               TEXT
 url                TEXT
 teaser             TEXT
 number             TEXT
 component          TEXT
 topic              TEXT
 changed            TEXT
 created            TEXT
 mentions_1960      BOOLEAN
 mentions_crypto    BOOLEAN
 verified_1960      BOOLEAN DEFAULT 0   -- added by 1960-verify
 verified_crypto    BOOLEAN DEFAULT 0   -- added by 1960-verify
 classification     TEXT                -- added by 1960-verify
```

### 3.2 Enrichment Tables

All enrichment tables use `case_id` ➞ _cases.id_ foreign keys and an auto-increment surrogate key.

| Table | Surrogate PK | Notable Columns |
|-------|--------------|-----------------|
| `case_metadata` | **(none – uses `case_id` as PK via `PRIMARY KEY` constraint)** | district_office, judge_name, statute/timeline JSON, etc. |
| `participants` | **id** | name, role, organization, age, nationality |
| `case_agencies` | **id** | agency_name, abbreviation, role, agents_mentioned |
| `charges` | **id** | charge_description, statute, severity, defendant |
| `financial_actions` | **id** | action_type, amount, currency, asset_type |
| `victims` | **id** | victim_type, number_affected, loss_amount |
| `quotes` | **id** | quote_text, speaker_name, context |
| `themes` | **id** | theme_name, related_statutes, stakeholders |

### 3.3 Supporting Tables

```
scraper_state
─────────────
 key   TEXT PRIMARY KEY
 value TEXT

enrichment_activity_log
──────────────────────
 log_id     INTEGER PRIMARY KEY AUTOINCREMENT
 timestamp  TEXT
 case_id    TEXT  -- FK → cases.id (not enforced)
 table_name TEXT
 status     TEXT  -- success | error | skipped
 notes      TEXT
```

### 3.4 ER Diagram (High-Level)

```
cases (1) ─┬─ case_metadata (1)
           ├─ participants (*n*)
           ├─ case_agencies (*n*)
           ├─ charges (*n*)
           ├─ financial_actions (*n*)
           ├─ victims (*n*)
           ├─ quotes (*n*)
           └─ themes (*n*)
```

## 4  Gap Analysis

| Area | Observation | Impact | Recommendation |
|------|-------------|--------|----------------|
| **Schema duplication** | `cases` DDL lives in `scraper.py`, `migrate_schemas.py`, **and** `1960-verify.py`.  Columns risk drifting. | Medium | Centralize DDL in one module (e.g. `schemas/base.py`) and import everywhere. |
| **Migration strategy** | Manual `ALTER TABLE` logic sprinkled in scripts.  No version history. | High | Introduce Alembic (if switching to SQLAlchemy) or `sqlite-utils` migrations. |
| **SQLite limitations** | Single-writer lock; no row-level security; limited JSON functions. | Medium-High (future scale) | Pilot PostgreSQL; map JSON fields to `jsonb`; add read replicas. |
| **Cascade behaviour** | Foreign keys declared but `ON DELETE CASCADE` absent.  Orchestrator manually deletes child rows before insert. | Low-Medium | Add `ON DELETE CASCADE` & rely on DB engine. |
| **Type precision** | Monetary amounts stored as TEXT; numeric sorting impossible. | Medium | Convert to DECIMAL or INTEGER cents; unify currency handling. |
| **Index coverage** | Only implicit PK indexes exist.  Frequent filter columns (`date`, `mentions_1960`, `classification`) lack indexes unless created manually. | Medium | Add composite/partial indexes; benchmark query plans. |
| **Logging granularity** | Activity log records table-level success but not per-column diff. | Low | Consider `json_patch` records to enable rollbacks & provenance. |
| **Data validation** | Most inserts accept raw strings; minimal sanitization. | Medium | Enforce NOT NULL & CHECK constraints; validate enums (status, severity). |

## 5  Strengths

1. **Clear Stage Separation** – Each processing step is encapsulated in its own module & orchestrator.
2. **Modular Schema Definitions** – Enrichment table DDL lives in a single source (`modules/enrichment/schemas.py`).
3. **Ease of Bootstrapping** – Minimal external dependencies; a new dev can spin up with `sqlite3` instantly.
4. **Comprehensive Docs** – Existing `docs/database-schema.md` provides deep reference material.

## 6  Opportunities for Next Phase

1. **Adopt an ORM & Migration Tool**
   • SQLAlchemy + Alembic will eliminate duplicated DDL and facilitate versioned migrations.
2. **Move to PostgreSQL (or Cloud-native DB)**
   • Unlock concurrency, JSONB indexing, full-text search, and Role-Based Access Control.
3. **Normalize Financial Fields**
   • Store monetary amounts as `NUMERIC(20, 2)`; create an `assets` lookup table for currency/crypto types.
4. **Implement Cascading Deletes & FK Enforcement**
   • Simplifies orchestrator logic and protects referential integrity.
5. **Add Indexes & Materialized Views**
   • Speed up dashboard queries (especially enrichment joins) and enable analytics.
6. **Automated Backups & CI health-checks**
   • Nightly dumps & schema diff tests to catch drift early.
7. **Unit-test Coverage for DB Layer**
   • Use an in-memory SQLite or pytest-PostgreSQL fixture to assert migrations/inserts.

## 7  Suggested Roadmap (6 Months)

| Month | Milestone |
|-------|-----------|
| 1 | Decide on target DB (SQLite + migrations vs PostgreSQL). |
| 2 | Introduce Alembic; port existing schema into version `0001_initial`. |
| 3 | Refactor code to use SQLAlchemy ORM; remove manual `sqlite3` calls. |
| 4 | Enable PostgreSQL in staging; run dual-write & migration scripts. |
| 5 | Decommission SQLite in production; add backup & monitoring. |
| 6 | Optimize indexes; implement analytic views; update documentation & onboarding. |

---

*Prepared by*: Engineering AI Assistant   |   *Date*: 2025-07-16