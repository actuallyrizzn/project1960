# CHANGELOG

*(Last updated 2025-06-21, Central Time)*

> **Scope** – This log captures every material addition, improvement, or hot-fix that emerged in our development sessions. It is grouped chronologically, then sub-grouped by functional area (Data Ingest, Pipeline Logic, Infra/UI, and Meta/Process).

---

## **2025-01 | Genesis**

| Area               | Item                                                                                                                                                                                                                         |
| ------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Meta / Process** | • Defined mission: track & analyze 18 U.S.C. § 1960 prosecutions (esp. crypto) as an open dataset.<br>• Spun up **GitHub org `1960-project`** (private).<br>• Settled on MIT license for code, CC-BY-SA 4.0 for data export. |
| **Infra/UI**       | • Picked **Python 3.12 + SQLite** as baseline; Flask for ad-hoc explorers; Tailwind for quick UI polish.                                                                                                                     |

---

## **2025-02 | First Complete Pass**

| Area               | Item                                                                                                                                                                                                                               |
| ------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Data Ingest**    | • Wrote **DOJ press-release scraper** (requests + BeautifulSoup) covering 2009-present.<br>• Normalized JSON → `press_releases` SQLite table.<br>• Implemented caching layer (200 ms/doc hot pull).                                |
| **Pipeline Logic** | • Added **DeepSeek-large classifier** (prompted as "financial-crime paralegal").<br>• First numbers: **3 653 PRs → 935 § 1960 hits → 2 860 crypto-adjacent** (overlap pending).                                                    |
| **Hot Fixes**      | • Solved `sqlite3.ProgrammingError: execute one statement at a time` by swapping multi-line `ALTER` into batched `cursor.executescript()`.<br>• Added `strip_json()` helper to extract LLM JSON from verbose output (regex-based). |

---

## **2025-03 | Reliability & Automation**

| Area               | Item                                                                                                                               |
| ------------------ | ---------------------------------------------------------------------------------------------------------------------------------- |
| **Pipeline Logic** | • Wrapped ingest + classify in **queue runner** (idempotent nightly cron).<br>• Differential crawl: only new PR URLs hit DeepSeek. |
| **Meta / Process** | • Formalized **branch naming** (`feat/*`, `fix/*`, `data/*`), Monday async stand-ups.                                              |

---

## **2025-04 | Classification Quality Push**

| Area               | Item                                                                                                                                                                                |
| ------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Pipeline Logic** | • Introduced **false-positive review notebook**; produced first prompt-tuning delta (-9 % FP, +3 % precision).<br>• Early PACER test: RECAP scraper prototyped for docket metadata. |
| **Infra/UI**       | • Built **Streamlit KPI dashboard** (counts, district heatmap).<br>• Decision: fold Streamlit into Sanctum later, leave proto for now.                                              |

---

## **2025-05 | Schema v1 + Front-end Spike**

| Area            | Item                                                                                                                                                                |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Data Ingest** | • Created `defendants`, `charges`, `investigators`, `ausas` tables (one-to-many from `cases`).<br>• Added minimal `exchanges` lookup table.                         |
| **Infra/UI**    | • **Flask "Data Explorer"** MVP: faceted search, table view, CSV export.<br>• Deployed behind existing **LEMP** stack via Nginx sub-domain proxy (no PHP conflict). |
| **Hot Fixes**   | • Patched duplicate-row edge case when DOJ republishes PRs with minor edits.<br>• Added retry logic w/ exponential back-off for DOJ 502/503 bursts.                 |

---

## **2025-06 | Developer Ergonomics & External Collab**

| Area               | Item                                                                                                                                                                                 |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Meta / Process** | • On-boarded **Zoe Hu** (see "quick primer" memo).<br>• Drafted **Roadmap Q3** (Schema-v2, PACER enrichment, LLM Q&A, Sanctum agent wrapper).                                       |
| **Infra/UI**       | • Dropped Flask explorer into **sub-directory multi-host** LEMP config; documented one-liner symlink deploy.<br>• Experimental React/Streamlit hybrid branch started for UI refresh. |
| **Pipeline Logic** | • Added nightly **delta-hash check** (MD5 of raw press-release body) to prevent unneeded re-classification.                                                                          |

---

## **2025-06-21 | Public Repository & Modern UI Overhaul**

| Area               | Item                                                                                                                                                                                                                                                         |
| ------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Data Ingest**    | • **Idempotent scraper**: Added logic to check most recent date in database and stop scraping when reaching older/equal dates.<br>• **Robust error handling**: Enhanced JSON extraction with multiple strategies and detailed logging.<br>• **Performance**: Scraper now returns boolean to track new inserts, making it safe for repeated runs. |
| **Pipeline Logic** | • **AI verification overhaul**: Switched to Venice AI API with `qwen-2.5-qwq-32b` model.<br>• **Robust JSON parsing**: Added cleaning functions, multiple extraction strategies, and comprehensive error handling.<br>• **CLI interface**: Added `--help`, `--dry-run`, `--limit`, `--verbose` arguments for safer testing.<br>• **Token optimization**: Increased token limit from 500 to 1000 to prevent truncated responses and false negatives.<br>• **Timeout handling**: Extended API timeout to 120 seconds to handle longer processing times. |
| **Infra/UI**       | • **Modern Flask/Bootstrap UI**: Complete overhaul with Bootstrap 5, responsive design, and modern UX.<br>• **Dashboard**: Added statistics overview with case counts and filtering options.<br>• **Case explorer**: Implemented pagination, filtering by classification status, 1960 mentions, and crypto mentions.<br>• **Detail views**: Individual case pages with full content and metadata display.<br>• **Search functionality**: Text search across titles and content with real-time filtering. |
| **Security**       | • **Environment variables**: Moved all sensitive data (API keys, configuration) to `.env` files.<br>• **Public repo preparation**: Added comprehensive `.gitignore`, `env.example` template, and security documentation.<br>• **Dependency management**: Cleaned up `requirements.txt` with pinned versions and removed built-in packages. |
| **Documentation**  | • **Comprehensive README**: Added setup instructions, usage examples, project structure, and security notes.<br>• **License**: Applied CC BY-SA 4.0 license across the repository.<br>• **Changelog**: Created detailed development history tracking all major changes and improvements. |
| **Hot Fixes**      | • **PowerShell compatibility**: Fixed command syntax issues for Windows PowerShell environment.<br>• **Encoding issues**: Resolved Unicode problems with environment file creation.<br>• **Git integration**: Successfully synced to GitHub repository with proper branch tracking. |

---

## **Coming Up** (Not Yet Shipped, but spec'd)

1. **Schema-v2** – fully relational (many-to-many) for exchanges/OTC desks and cross-district agent mapping.
2. **Automated PACER Enrichment** – docket meta pull + sentence outcomes.
3. **LLM RAG Q&A Layer** – "Ask the dataset" natural-language endpoint.
4. **Sanctum Agent Integration** – turn crawler/classifier into a self-healing scheduled agent.
5. **Public Portal v1** – polished UI, downloadable slices, and an API key system.
6. **GitHub Actions** – automated testing and deployment workflows.
7. **API Endpoints** – RESTful API for programmatic access to case data.
8. **Advanced Analytics** – trend analysis, geographic mapping, and statistical reporting.

---

## **Usage**

Drop the table chunks straight into `CHANGELOG.md`, or cherry-pick items for release notes / investor updates. If you spot an omission, add a line under the correct month and re-commit under `docs/changelog-patch-<date>.md`.

*End of log – 2025-06-21* 