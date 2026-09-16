# Charge-level §1960 focus (multi-defendant / multi-docket PRs)

## Problem

A DOJ press release is often an **enterprise wrap-up**: many defendants, mixed charges, sometimes **two+ federal dockets** in one article (e.g. Thai sex trafficking: *Morris* `17-cr-107` + *Intarathong* `16-cr-257`). One co-defendant may have an **unlicensed money transmitting** count while the lead case is sex trafficking.

Legacy seed gate `cases.verified_1960` is **press-level**. Matching then enriches the lead docket — ambient co-defendant paper — instead of the §1960-shaped count.

## Model (after schema upgrade)

| Grain | Table | Role |
|-------|--------|------|
| Press release | `cases` | Ingest unit; `verified_1960` = historical / coarse flag |
| Stated dockets | `case_docket_refs` | Press-cited numbers (`17-cr-107`, `16-cr-257`) before CL |
| Defendant charge | `charges` | One row per charge; **`is_1960` / `verified_1960`** + optional `participant_id`, `cl_docket_id` |
| CL link focus | `case_courtlistener_links` | `relevance`: `primary_1960` \| `related` \| `ambient` \| `unspecified`; optional `focus_charge_id` |

### `charges` focus columns

- `is_1960` — charge looks like §1960 / UMT (keyword or curator)
- `verified_1960` — charge-level yes/no (NULL unset)
- `participant_id` — FK-ish to `participants`
- `cl_docket_id` — which linked docket this count belongs on
- `source` — `press` \| `enrichment` \| `cl_extract` \| `manual`

### Helper

`Project1960\CaseChargeStore` — upsert charges, list 1960-focus rows, set link relevance, upsert docket refs.

## Thai example (target shape)

Press `40d638a8-…` stays one `cases` row. Then:

1. `case_docket_refs`: `17-cr-107` (Morris et al.), `16-cr-257` (Intarathong et al.)
2. `charges`: Bhunna Win / “Unlicensed money transmitting business” / `is_1960=1` (and sex-trafficking counts as `is_1960=0`)
3. CL link to `7508872` marked `relevance=ambient` (or `related`) until Win’s count is tied to the correct docket; only a `primary_1960` link should drive §1960-priority ingest

## Pipeline follow-ups (not this migration)

- Press charge parser → populate `charges` + `case_docket_refs`
- Verifier writes **charge-level** `verified_1960`, not only case-level
- Matcher / ingest prefer `relevance=primary_1960` and `charges.is_1960=1`
