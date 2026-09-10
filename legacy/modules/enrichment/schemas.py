"""
Database schema definitions for enrichment tables.
"""
from typing import Dict

# A mapping of table names to their SQL CREATE TABLE statements.
# This centralized dictionary is the single source of truth for the database structure.
SCHEMA_DEFINITIONS = {
    'case_metadata': """
    CREATE TABLE IF NOT EXISTS case_metadata (
      case_id            TEXT PRIMARY KEY,
      district_office    TEXT,
      usa_name           TEXT,
      event_type         TEXT,
      judge_name         TEXT,
      judge_title        TEXT,
      case_number        TEXT,
      max_penalty_text   TEXT,
      sentence_summary   TEXT,
      money_amounts      TEXT,
      crypto_assets      TEXT,
      statutes_json      TEXT,
      timeline_json      TEXT,
      press_release_url  TEXT,
      extras_json        JSON,
      FOREIGN KEY(case_id) REFERENCES cases(id)
    );
    """,
    'participants': """
    CREATE TABLE IF NOT EXISTS participants (
      id                 INTEGER PRIMARY KEY AUTOINCREMENT,
      case_id            TEXT NOT NULL,
      name               TEXT,
      role               TEXT,
      title              TEXT,
      organization       TEXT,
      location           TEXT,
      age                INTEGER,
      nationality        TEXT,
      status             TEXT,
      FOREIGN KEY(case_id) REFERENCES cases(id)
    );
    """,
    'case_agencies': """
    CREATE TABLE IF NOT EXISTS case_agencies (
      id                 INTEGER PRIMARY KEY AUTOINCREMENT,
      case_id            TEXT NOT NULL,
      agency_name        TEXT,
      abbreviation       TEXT,
      role               TEXT,
      office_location    TEXT,
      agents_mentioned   TEXT,
      contribution       TEXT,
      FOREIGN KEY(case_id) REFERENCES cases(id)
    );
    """,
    'charges': """
    CREATE TABLE IF NOT EXISTS charges (
      id                 INTEGER PRIMARY KEY AUTOINCREMENT,
      case_id            TEXT NOT NULL,
      charge_description TEXT,
      statute            TEXT,
      severity           TEXT,
      max_penalty        TEXT,
      fine_amount        TEXT,
      defendant          TEXT,
      status             TEXT,
      FOREIGN KEY(case_id) REFERENCES cases(id)
    );
    """,
    'financial_actions': """
    CREATE TABLE IF NOT EXISTS financial_actions (
      id                 INTEGER PRIMARY KEY AUTOINCREMENT,
      case_id            TEXT NOT NULL,
      action_type        TEXT,
      amount             TEXT,
      currency           TEXT,
      description        TEXT,
      asset_type         TEXT,
      defendant          TEXT,
      status             TEXT,
      FOREIGN KEY(case_id) REFERENCES cases(id)
    );
    """,
    'victims': """
    CREATE TABLE IF NOT EXISTS victims (
      id                 INTEGER PRIMARY KEY AUTOINCREMENT,
      case_id            TEXT NOT NULL,
      victim_type        TEXT,
      description        TEXT,
      number_affected    INTEGER,
      loss_amount        TEXT,
      geographic_scope   TEXT,
      vulnerability_factors TEXT,
      impact_description TEXT,
      FOREIGN KEY(case_id) REFERENCES cases(id)
    );
    """,
    'quotes': """
    CREATE TABLE IF NOT EXISTS quotes (
      id                 INTEGER PRIMARY KEY AUTOINCREMENT,
      case_id            TEXT NOT NULL,
      quote_text         TEXT,
      speaker_name       TEXT,
      speaker_title      TEXT,
      speaker_organization TEXT,
      quote_type         TEXT,
      context            TEXT,
      significance       TEXT,
      FOREIGN KEY(case_id) REFERENCES cases(id)
    );
    """,
    'themes': """
    CREATE TABLE IF NOT EXISTS themes (
      id                 INTEGER PRIMARY KEY AUTOINCREMENT,
      case_id            TEXT NOT NULL,
      theme_name         TEXT,
      description        TEXT,
      significance       TEXT,
      related_statutes   TEXT,
      geographic_scope   TEXT,
      temporal_aspects   TEXT,
      stakeholders       TEXT,
      FOREIGN KEY(case_id) REFERENCES cases(id)
    );
    """,
    'enrichment_activity_log': """
    CREATE TABLE IF NOT EXISTS enrichment_activity_log (
      log_id             INTEGER PRIMARY KEY AUTOINCREMENT,
      timestamp          TEXT NOT NULL,
      case_id            TEXT NOT NULL,
      table_name         TEXT NOT NULL,
      status             TEXT NOT NULL,
      notes              TEXT
    );
    """
}

def get_schema(table_name: str) -> str:
    """Get the CREATE TABLE statement for a specific table."""
    if table_name not in SCHEMA_DEFINITIONS:
        raise ValueError(f"Schema not found for table: {table_name}")
    return SCHEMA_DEFINITIONS[table_name]

def get_all_schemas() -> Dict[str, str]:
    """Get a copy of all schema definitions."""
    return SCHEMA_DEFINITIONS.copy() 