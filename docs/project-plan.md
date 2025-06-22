# Field hunting in the press releases

| Category                       | Concrete Data Points to Extract                                                                                                                                                                                                              | Why It’s Valuable / How You’ll Use It                                                                       |
| ------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------- |
| **Core Legal**                 | • **Event Type** (indictment, plea, conviction, sentencing, deferred-prosecution)  <br>• **Statute Mix** (wire fraud, money-laundering counts, BSA counts, § 2320 sanctions, etc.)  <br>• **Maximum Penalty** quoted                         | Drives timeline analytics (“how far along is each case?”) and severity scoring.                             |
| **Court & Docket**             | • **Case Number / docket URL**  <br>• **Judge** (name & title)  <br>• **Sentencing Date** (future calendared)  <br>• **District / Division**                                                                                                 | Primary PACER keys; judge clustering reveals hotspots (“Judge LaPlante handles X% of crypto § 1960 cases”). |
| **Prosecution Team**           | • **U.S. Attorney Office** (abbrev + full)  <br>• **Lead AUSA(s)**  <br>• **DOJ Units** (e.g., NCET, MLARS, OCDETF)                                                                                                                          | Lets you map who’s driving policy; spot prosecutor specializations.                                         |
| **Law-Enforcement Actors**     | • **Investigating Agencies** (FBI, IRS-CI, USPIS, HSI…)  <br>• **Named Special Agents / SAC quotes**                                                                                                                                         | Build co-occurrence network to surface the go-to crypto agents.                                             |
| **Defendant Block**            | • **Full Name(s)** + aliases  <br>• **Age / DOB**  <br>• **Residence (city, state, country)**  <br>• **Citizenship** (esp. foreign nationals)  <br>• **Entity Names & Shell Companies**  <br>• **Role** (principal, money-mule, facilitator) | Enables cross-PR identity resolution and foreign-national stats.                                            |
| **Conspiracy / Cohort**        | • **Named Co-Conspirators** (even if separate indictment)  <br>• **Affiliated Churches / NGOs / DAOs**                                                                                                                                       | Clues for expanding corpus & building relationship graphs.                                                  |
| **Victims & Loss**             | • **Victim Type** (romance-scam, BEC victim, healthcare insurer, township)  <br>• **Victim Geography**  <br>• **Loss \$**                                                                                                                    | Good for impact narratives & heatmaps of victim origins.                                                    |
| **Crypto Details**             | • **Coins Mentioned** (BTC, ETH, Monero, USDT, mixers)  <br>• **Exchange / Platform Names** (KuCoin, LocalBitcoins)  <br>• **On-Chain Services** (kiosks, ATMs, Sinbad blender)                                                              | Correlate with blockchain-analytics flags and sanction lists.                                               |
| **Financial Flow**             | • **Fiat Inflow Method** (cash deposits, wire, ACH, church “donations”)  <br>• **Structuring Instructions** (“keep deposits < \$9,500”)  <br>• **Fees Charged** (%, absolute \$)                                                             | Helps model typical tradecraft patterns & compliance gaps.                                                  |
| **Regulatory Breaches**        | • **Missing AML / KYC Controls**  <br>• **SAR / CTR Failures**  <br>• **FinCEN Registration Omission**                                                                                                                                       | Useful for policy commentary and comparative risk scoring.                                                  |
| **Asset Recovery / Penalties** | • **Forfeiture Amounts** (cash, BTC, real estate)  <br>• **Criminal Fines**  <br>• **Restitution Ordered**  <br>• **Corporate-wide Remediation** (KuCoin exiting US)                                                                         | Feeds restitution trackers; totals show enforcement ROI.                                                    |
| **International Cooperation**  | • **Foreign Agencies Cited** (Netherlands FIOD, Australian AFP)  <br>• **Sanctions References** (OFAC Blender/Sinbad)                                                                                                                        | Highlights multilateral reach; good for press outreach.                                                     |
| **Quote Bank**                 | • **Pull-quotes** from U.S. Attorneys & SACs                                                                                                                                                                                                 | Great for press decks and narrative pieces; can sentiment-score rhetoric.                                   |
| **Timeline Anchors**           | • **Offense Date Range**  <br>• **Indictment Date**  <br>• **Plea Date**  <br>• **Sentencing Date (scheduled or past)**                                                                                                                      | Lets you calculate investigation length & plea-to-sentence gaps.                                            |
| **Ancillary Charges / Themes** | • **Tax Evasion Counts**  <br>• **Romance-Scam / Elder-Fraud references**  <br>• **Darknet / Ransomware** mentions                                                                                                                           | Tagging themes lets you build sub-dashboards (e.g., “Romance-Scam § 1960 cases in 2024”).                   |
| **Comms Metadata**             | • **Press-Release URL**  <br>• **PR Number / slug**                                                                                                                                                                                          | Ensures reproducibility & easy re-scrape on updates.                                                        |

---

Below is a **“kitchen-sink” schema** that can swallow every data point on the checklist while staying SQLite-simple and fully additive to your existing `cases` table.
Use `CREATE TABLE IF NOT EXISTS …` in your enrichment scripts; no migration risk.

---

## 0. Naming Conventions

* All dates = `TEXT` (`YYYY-MM-DD`) for SQLite friendliness.
* All money values = raw `TEXT` (parse later).
* `case_id` always FK-link back to `cases.id`.
* A final `extras_json` in each table can hold edge fields you discover later.

---

## 1. Case-Level Metadata

```sql
CREATE TABLE IF NOT EXISTS case_metadata (
  case_id            TEXT PRIMARY KEY,      -- FK → cases.id
  district_office    TEXT,
  usa_name           TEXT,                  -- U.S. Attorney
  event_type         TEXT,                  -- indictment | plea | conviction | sentencing | deferred
  judge_name         TEXT,
  judge_title        TEXT,                  -- “U.S. District Judge”
  case_number        TEXT,
  max_penalty_text   TEXT,                  -- “up to five years”
  sentence_summary   TEXT,                  -- actual sentence text
  money_amounts      TEXT,                  -- raw e.g. “$297 million penalties”
  crypto_assets      TEXT,                  -- “BTC,ETH”
  statutes_json      TEXT,                  -- ‘["18 USC 1960","18 USC 1956"]’
  timeline_json      TEXT,                  -- key→date pairs
  press_release_url  TEXT,
  extras_json        JSON
);
```

---

## 2. Participants & Roles

```sql
CREATE TABLE IF NOT EXISTS participants (
  participant_id     INTEGER PRIMARY KEY AUTOINCREMENT,
  case_id            TEXT,                 -- FK
  name               TEXT,
  role               TEXT,                 -- defendant | AUSA | agent | co-conspirator | judge
  agency             TEXT,                 -- “FBI”, “IRS-CI” (null for defendants)
  age                INTEGER,
  city               TEXT,
  state_country      TEXT,
  aliases            TEXT,                 -- comma list or JSON array
  entity_name        TEXT,                 -- shell company / church / mixer name
  extras_json        JSON
);
```

---

## 3. Investigating & Cooperating Agencies

*(one row per agency per case, keeps querying simple)*

```sql
CREATE TABLE IF NOT EXISTS case_agencies (
  case_id            TEXT,
  agency             TEXT,                 -- “FBI”, “HSI Pretoria”, “Netherlands FIOD”
  role               TEXT,                 -- investigating | assisting | international_partner
  PRIMARY KEY (case_id, agency, role)
);
```

---

## 4. Charges & Statutes (Granular)

```sql
CREATE TABLE IF NOT EXISTS charges (
  charge_id          INTEGER PRIMARY KEY AUTOINCREMENT,
  case_id            TEXT,
  statute            TEXT,                 -- “18 USC 1960”
  description        TEXT,                 -- “unlicensed money transmitting business”
  count_number       INTEGER,              -- if PR lists “four counts”
  extras_json        JSON
);
```

---

## 5. Financials & Assets

```sql
CREATE TABLE IF NOT EXISTS financial_actions (
  fin_id             INTEGER PRIMARY KEY AUTOINCREMENT,
  case_id            TEXT,
  action_type        TEXT,                 -- forfeiture | fine | restitution | fee | laundering_volume
  amount_text        TEXT,                 -- “$1.5 million”, “1.93 BTC”
  currency           TEXT,                 -- USD, BTC, ETH…
  asset_type         TEXT,                 -- fiat | crypto | real_estate
  extras_json        JSON
);
```

---

## 6. Victims & Loss Context

```sql
CREATE TABLE IF NOT EXISTS victims (
  victim_id          INTEGER PRIMARY KEY AUTOINCREMENT,
  case_id            TEXT,
  victim_type        TEXT,                 -- romance_scam | BEC | elder_fraud | township | insurer
  industry           TEXT,                 -- healthcare, municipal, etc.
  geography          TEXT,                 -- city / state / country
  loss_amount_text   TEXT,
  extras_json        JSON
);
```

---

## 7. Pull-Quote Bank

```sql
CREATE TABLE IF NOT EXISTS quotes (
  quote_id           INTEGER PRIMARY KEY AUTOINCREMENT,
  case_id            TEXT,
  speaker_name       TEXT,
  speaker_title      TEXT,
  quote_text         TEXT,
  sentiment          TEXT,                 -- optional: positive ¦ deterrent ¦ punitive
  extras_json        JSON
);
```

---

## 8. Thematic Tags

```sql
CREATE TABLE IF NOT EXISTS themes (
  case_id            TEXT,
  theme              TEXT,                 -- romance_scam | darknet | ransomware | elder_fraud | DPRK
  PRIMARY KEY (case_id, theme)
);
```

---

## 9. Relational Cheat-Sheet

```
cases (original)
   │ 1
   │
   ├── case_metadata        (1‒to‒1)
   ├── participants         (1‒to‒many)
   ├── case_agencies        (1‒to‒many)
   ├── charges              (1‒to‒many)
   ├── financial_actions    (1‒to‒many)
   ├── victims              (1‒to‒many)
   ├── quotes               (1‒to‒many)
   └── themes               (1‒to‒many)
```

---

### How to Populate

1. **Ensure Tables Exist** – your enrichment script calls `CREATE TABLE IF NOT EXISTS …` once.
2. **Phase-2 LLM extraction** outputs a big JSON blob containing every field name above.
3. Your Python upsert code:

   * Inserts/updates `case_metadata`.
   * Loops through arrays to populate `participants`, `case_agencies`, `charges`, etc.
4. Leave `extras_json` hooks for edge cases so you never lose info even if a field wasn’t planned.

---

**This schema captures every “last drop” while staying flat, query-friendly, and fully additive.**

