import sqlite3
import requests
import json
import time
import re
import logging
import argparse
import os
from dotenv import load_dotenv

# --- Configuration ---
load_dotenv()
VENICE_API_KEY = os.getenv("VENICE_API_KEY")
VENICE_API_URL = "https://api.venice.ai/api/v1/chat/completions"
MODEL_NAME = "qwen-2.5-qwq-32b"
DATABASE_NAME = os.getenv("DATABASE_NAME", "doj_cases.db")
TABLE_CHOICES = [
    'case_metadata', 'participants', 'case_agencies', 'charges', 
    'financial_actions', 'victims', 'quotes', 'themes'
]

# --- Logging Setup ---
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

# --- Database Schema ---
SCHEMA = {
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
      participant_id     INTEGER PRIMARY KEY AUTOINCREMENT,
      case_id            TEXT,
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
      agency_id          INTEGER PRIMARY KEY AUTOINCREMENT,
      case_id            TEXT,
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
      charge_id          INTEGER PRIMARY KEY AUTOINCREMENT,
      case_id            TEXT,
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
      fin_id             INTEGER PRIMARY KEY AUTOINCREMENT,
      case_id            TEXT,
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
      victim_id          INTEGER PRIMARY KEY AUTOINCREMENT,
      case_id            TEXT,
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
      quote_id           INTEGER PRIMARY KEY AUTOINCREMENT,
      case_id            TEXT,
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
      theme_id           INTEGER PRIMARY KEY AUTOINCREMENT,
      case_id            TEXT,
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
      log_id INTEGER PRIMARY KEY AUTOINCREMENT,
      timestamp TEXT,
      case_id TEXT,
      table_name TEXT,
      status TEXT,
      notes TEXT
    );
    """
}

def setup_enrichment_tables():
    """Creates all necessary tables for data enrichment if they don't exist."""
    logger.info("Setting up enrichment tables in the database...")
    conn = None
    try:
        conn = sqlite3.connect(DATABASE_NAME)
        cursor = conn.cursor()
        for table, query in SCHEMA.items():
            logger.debug(f"Creating table '{table}'...")
            cursor.execute(query)
        conn.commit()
        logger.info("All enrichment tables created successfully or already exist.")
    except sqlite3.Error as e:
        logger.error(f"Database error during table setup: {e}")
        exit(1)
    finally:
        if conn:
            conn.close()

def get_cases_to_enrich(table_name, limit):
    """Fetches verified cases that have not yet been enriched for the given table."""
    conn = sqlite3.connect(DATABASE_NAME)
    cursor = conn.cursor()
    
    # First, ensure the target table exists
    try:
        cursor.execute(SCHEMA[table_name])
        conn.commit()
        logger.debug(f"Ensured table '{table_name}' exists")
    except sqlite3.Error as e:
        logger.warning(f"Could not create table '{table_name}': {e}")
    
    # Now try the enrichment query
    query = f"""
        SELECT c.id, c.title, c.body, c.url
        FROM cases c
        LEFT JOIN {table_name} et ON c.id = et.case_id
        WHERE c.verified_1960 = 1 AND et.case_id IS NULL
        LIMIT ?
    """
    logger.debug(f"Fetching up to {limit} cases for enrichment in '{table_name}'...")
    try:
        cursor.execute(query, (limit,))
        cases = cursor.fetchall()
        logger.info(f"Found {len(cases)} cases to process for '{table_name}'.")
        return cases
    except sqlite3.OperationalError as e:
        # If the table doesn't exist, fall back to a simpler query
        logger.warning(f"Table '{table_name}' doesn't exist, using fallback query: {e}")
        fallback_query = """
            SELECT c.id, c.title, c.body, c.url
            FROM cases c
            WHERE c.verified_1960 = 1
            LIMIT ?
        """
        cursor.execute(fallback_query, (limit,))
        cases = cursor.fetchall()
        logger.info(f"Found {len(cases)} cases to process for '{table_name}' (fallback query).")
        return cases
    except sqlite3.Error as e:
        logger.error(f"Failed to fetch cases for enrichment: {e}")
        return []
    finally:
        conn.close()

def get_extraction_prompt(table_name, title, body):
    if table_name == 'case_metadata':
        return f"""
You are a legal data extraction expert. Your task is to analyze the following U.S. Department of Justice press release and extract specific metadata points. Return ONLY a single, clean JSON object with no additional text, explanations, or thinking content.

**Instructions:**
1. Read the entire press release text provided below.
2. Extract the information for the following fields:
    * `district_office`: The full name of the U.S. Attorney's Office (e.g., "Southern District of New York").
    * `usa_name`: The name of the U.S. Attorney mentioned (e.g., "Joon H. Kim").
    * `event_type`: The primary legal event described. Choose one from: indictment, plea, conviction, sentencing, deferred-prosecution, other.
    * `judge_name`: The full name of the judge mentioned, if any.
    * `judge_title`: The title of the judge (e.g., "U.S. District Judge").
    * `case_number`: The docket or case number, if provided.
    * `max_penalty_text`: A direct quote of the maximum potential sentence mentioned (e.g., "up to 20 years in prison").
    * `sentence_summary`: A summary of the actual sentence imposed, if described.
    * `money_amounts`: A comma-separated list of any significant monetary values mentioned (e.g., "$2.5 million", "€800,000").
    * `crypto_assets`: A comma-separated list of any specific cryptocurrency assets mentioned (e.g., "BTC, ETH, Monero").
    * `statutes_json`: A JSON array of the U.S. Code statutes mentioned (e.g., '["18 U.S.C. § 1960", "21 U.S.C. § 846"]').
    * `timeline_json`: A JSON object of key dates, like {{"indictment_date": "YYYY-MM-DD", "plea_date": "YYYY-MM-DD"}}.
3. If a field's value cannot be found in the text, use `null` for that field in the JSON output.
4. The `press_release_url` will be added later; you do not need to extract it.
5. Return ONLY the JSON object. Do not include any explanations, thinking text, or content outside the JSON.

**Press Release Title:**
{title}
**Press Release Body:**
{body}

{{
  "district_office": "value or null",
  "usa_name": "value or null",
  "event_type": "value or null",
  "judge_name": "value or null",
  "judge_title": "value or null",
  "case_number": "value or null",
  "max_penalty_text": "value or null",
  "sentence_summary": "value or null",
  "money_amounts": "value or null",
  "crypto_assets": "value or null",
  "statutes_json": ["array of statutes or null"],
  "timeline_json": {{"key": "value or null"}}
}}
"""
    elif table_name == 'participants':
        return f"""
You are a legal data extraction expert. Your task is to analyze the following U.S. Department of Justice press release and extract information about all participants mentioned. Return ONLY a JSON array of participant objects with no additional text.

**Instructions:**
1. Read the entire press release text provided below.
2. Extract information about all participants mentioned (defendants, prosecutors, attorneys, etc.).
3. For each participant, create a JSON object with these fields:
    * `name`: Full name of the participant
    * `role`: Their role in the case (e.g., "defendant", "prosecutor", "defense attorney", "judge", "witness")
    * `title`: Professional title if mentioned (e.g., "U.S. Attorney", "Assistant U.S. Attorney")
    * `organization`: Organization they represent (e.g., "U.S. Attorney's Office", "FBI")
    * `location`: Geographic location if mentioned (e.g., "New York", "California")
    * `age`: Age if mentioned
    * `nationality`: Nationality if mentioned
    * `status`: Current status if mentioned (e.g., "sentenced", "pleaded guilty", "indicted")
4. If a field's value cannot be found, use `null` for that field.
5. Return ONLY the JSON array. Do not include any explanations or text outside of the JSON array.

**Press Release Title:**
{title}
**Press Release Body:**
{body}

[
  {{
    "name": "value or null",
    "role": "value or null",
    "title": "value or null",
    "organization": "value or null",
    "location": "value or null",
    "age": "value or null",
    "nationality": "value or null",
    "status": "value or null"
  }}
]
"""
    elif table_name == 'case_agencies':
        return f"""
You are a legal data extraction expert. Your task is to analyze the following U.S. Department of Justice press release and extract information about all law enforcement agencies involved. Return ONLY a JSON array of agency objects with no additional text.

**Instructions:**
1. Read the entire press release text provided below.
2. Extract information about all law enforcement agencies mentioned.
3. For each agency, create a JSON object with these fields:
    * `agency_name`: Full name of the agency (e.g., "Federal Bureau of Investigation", "Drug Enforcement Administration")
    * `abbreviation`: Common abbreviation if used (e.g., "FBI", "DEA")
    * `role`: Their role in the case (e.g., "investigation", "arrest", "prosecution")
    * `office_location`: Specific office location if mentioned (e.g., "New York Field Office")
    * `agents_mentioned`: Names of specific agents mentioned
    * `contribution`: Brief description of their contribution to the case
4. If a field's value cannot be found, use `null` for that field.
5. Return ONLY the JSON array. Do not include any explanations or text outside of the JSON array.

**Press Release Title:**
{title}
**Press Release Body:**
{body}

[
  {{
    "agency_name": "value or null",
    "abbreviation": "value or null",
    "role": "value or null",
    "office_location": "value or null",
    "agents_mentioned": "value or null",
    "contribution": "value or null"
  }}
]
"""
    elif table_name == 'charges':
        return f"""
You are a legal data extraction expert. Your task is to analyze the following U.S. Department of Justice press release and extract information about all criminal charges mentioned. Return ONLY a JSON array of charge objects with no additional text.

**Instructions:**
1. Read the entire press release text provided below.
2. Extract information about all criminal charges mentioned.
3. For each charge, create a JSON object with these fields:
    * `charge_description`: Description of the charge (e.g., "Operating an Unlicensed Money Transmitting Business")
    * `statute`: The specific statute violated (e.g., "18 U.S.C. § 1960")
    * `severity`: Severity level if mentioned (e.g., "felony", "misdemeanor")
    * `max_penalty`: Maximum penalty for this charge (e.g., "20 years in prison")
    * `fine_amount`: Fine amount if mentioned
    * `defendant`: Name of the defendant charged with this offense
    * `status`: Status of this charge (e.g., "indicted", "pleaded guilty", "convicted")
4. If a field's value cannot be found, use `null` for that field.
5. Return ONLY the JSON array. Do not include any explanations or text outside of the JSON array.

**Press Release Title:**
{title}
**Press Release Body:**
{body}

[
  {{
    "charge_description": "value or null",
    "statute": "value or null",
    "severity": "value or null",
    "max_penalty": "value or null",
    "fine_amount": "value or null",
    "defendant": "value or null",
    "status": "value or null"
  }}
]
"""
    elif table_name == 'financial_actions':
        return f"""
You are a legal data extraction expert. Your task is to analyze the following U.S. Department of Justice press release and extract information about all financial actions, seizures, forfeitures, and monetary penalties mentioned. Return ONLY a JSON array of financial action objects with no additional text.

**Instructions:**
1. Read the entire press release text provided below.
2. Extract information about all financial actions mentioned (seizures, forfeitures, fines, restitution, etc.).
3. For each financial action, create a JSON object with these fields:
    * `action_type`: Type of financial action (e.g., "forfeiture", "fine", "restitution", "seizure")
    * `amount`: Monetary amount (e.g., "$2.5 million", "€800,000")
    * `currency`: Currency if specified (e.g., "USD", "EUR")
    * `description`: Description of what was seized/forfeited/fined
    * `asset_type`: Type of asset (e.g., "cash", "cryptocurrency", "property", "vehicles")
    * `defendant`: Name of the defendant associated with this action
    * `status`: Status of the action (e.g., "ordered", "completed", "pending")
4. If a field's value cannot be found, use `null` for that field.
5. Return ONLY the JSON array. Do not include any explanations or text outside of the JSON array.

**Press Release Title:**
{title}
**Press Release Body:**
{body}

[
  {{
    "action_type": "value or null",
    "amount": "value or null",
    "currency": "value or null",
    "description": "value or null",
    "asset_type": "value or null",
    "defendant": "value or null",
    "status": "value or null"
  }}
]
"""
    elif table_name == 'victims':
        return f"""
You are a legal data extraction expert. Your task is to analyze the following U.S. Department of Justice press release and extract information about any victims mentioned. Return ONLY a JSON array of victim objects with no additional text.

**Instructions:**
1. Read the entire press release text provided below.
2. Extract information about any victims mentioned in the case.
3. For each victim, create a JSON object with these fields:
    * `victim_type`: Type of victim (e.g., "individual", "business", "government", "financial institution")
    * `description`: Description of the victim (e.g., "elderly investors", "small businesses")
    * `number_affected`: Number of victims if specified
    * `loss_amount`: Total loss amount if mentioned
    * `geographic_scope`: Geographic scope of victimization if mentioned
    * `vulnerability_factors`: Any vulnerability factors mentioned (e.g., "elderly", "immigrants")
    * `impact_description`: Description of the impact on victims
4. If a field's value cannot be found, use `null` for that field.
5. Return ONLY the JSON array. Do not include any explanations or text outside of the JSON array.

**Press Release Title:**
{title}
**Press Release Body:**
{body}

[
  {{
    "victim_type": "value or null",
    "description": "value or null",
    "number_affected": "value or null",
    "loss_amount": "value or null",
    "geographic_scope": "value or null",
    "vulnerability_factors": "value or null",
    "impact_description": "value or null"
  }}
]
"""
    elif table_name == 'quotes':
        return f"""
You are a legal data extraction expert. Your task is to analyze the following U.S. Department of Justice press release and extract all significant quotes mentioned. Return ONLY a JSON array of quote objects with no additional text.

**Instructions:**
1. Read the entire press release text provided below.
2. Extract all significant quotes mentioned in the press release.
3. For each quote, create a JSON object with these fields:
    * `quote_text`: The exact quote text
    * `speaker_name`: Name of the person who said the quote
    * `speaker_title`: Title/position of the speaker (e.g., "U.S. Attorney", "FBI Special Agent")
    * `speaker_organization`: Organization the speaker represents
    * `quote_type`: Type of quote (e.g., "statement", "comment", "announcement", "reaction")
    * `context`: Brief context of when/why the quote was made
    * `significance`: Why this quote is significant to the case
4. If a field's value cannot be found, use `null` for that field.
5. Return ONLY the JSON array. Do not include any explanations or text outside of the JSON array.

**Press Release Title:**
{title}
**Press Release Body:**
{body}

[
  {{
    "quote_text": "value or null",
    "speaker_name": "value or null",
    "speaker_title": "value or null",
    "speaker_organization": "value or null",
    "quote_type": "value or null",
    "context": "value or null",
    "significance": "value or null"
  }}
]
"""
    elif table_name == 'themes':
        return f"""
You are a legal data extraction expert. Your task is to analyze the following U.S. Department of Justice press release and extract key themes and topics. Return ONLY a JSON array of theme objects with no additional text.

**Instructions:**
1. Read the entire press release text provided below.
2. Extract key themes and topics mentioned in the press release.
3. For each theme, create a JSON object with these fields:
    * `theme_name`: Name of the theme (e.g., "Money Laundering", "Cryptocurrency", "International Cooperation")
    * `description`: Description of how this theme appears in the case
    * `significance`: Why this theme is important to the case
    * `related_statutes`: Any statutes related to this theme
    * `impact`: Impact of this theme on the case outcome
    * `trends`: Any trends or patterns related to this theme
4. If a field's value cannot be found, use `null` for that field.
5. Return ONLY the JSON array. Do not include any explanations or text outside of the JSON array.

**Press Release Title:**
{title}
**Press Release Body:**
{body}

[
  {{
    "theme_name": "value or null",
    "description": "value or null",
    "significance": "value or null",
    "related_statutes": "value or null",
    "impact": "value or null",
    "trends": "value or null"
  }}
]
"""
    else:
        raise ValueError(f"Unknown table name: {table_name}")

def call_venice_api(prompt):
    headers = {"Authorization": f"Bearer {VENICE_API_KEY}", "Content-Type": "application/json"}
    payload = {"model": MODEL_NAME, "messages": [{"role": "user", "content": prompt}], "max_tokens": 2000, "temperature": 0.1}
    try:
        response = requests.post(VENICE_API_URL, headers=headers, json=payload, timeout=120)
        response.raise_for_status()
        return response.json()
    except Exception as e:
        logger.error(f"API call failed: {e}")
        return None

def clean_and_parse_json(raw_text):
    if raw_text is None or (isinstance(raw_text, str) and raw_text.strip() == ''):
        logger.debug("Input to clean_and_parse_json is None or empty string.")
        return None
    
    logger.debug(f"Raw text length: {len(raw_text) if isinstance(raw_text, str) else 'not a string'}")
    logger.debug(f"Raw text preview: {raw_text[:500] if isinstance(raw_text, str) else 'not a string'}...")
    
    # Strategy 1: Remove think tags and other common AI artifacts
    cleaned_text = raw_text
    if isinstance(cleaned_text, str):
        # Remove think tags more aggressively
        cleaned_text = re.sub(r'<think>.*?</think>', '', cleaned_text, flags=re.DOTALL | re.IGNORECASE)
        cleaned_text = re.sub(r'<thinking>.*?</thinking>', '', cleaned_text, flags=re.DOTALL | re.IGNORECASE)
        cleaned_text = re.sub(r'<reasoning>.*?</reasoning>', '', cleaned_text, flags=re.DOTALL | re.IGNORECASE)
        # Remove markdown code blocks
        cleaned_text = re.sub(r'```.*?```', '', cleaned_text, flags=re.DOTALL)
        # Remove any remaining XML-like tags
        cleaned_text = re.sub(r'<[^>]+>', '', cleaned_text)
        logger.debug(f"After cleaning, text length: {len(cleaned_text)}")
        logger.debug(f"Cleaned text preview: {cleaned_text[:500]}...")
    
    # Strategy 2: Look for complete JSON objects with balanced braces (prioritize objects over arrays)
    if isinstance(cleaned_text, str):
        # Use the same pattern as 1960-verify.py for complete JSON objects
        json_patterns = [
            # Standard JSON object with balanced braces
            r'\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}',
            # JSON object that ends at line end or followed by non-comma
            r'\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}(?=\s*$|\s*[^,])',
        ]
        
        for i, pattern in enumerate(json_patterns, 1):
            logger.debug(f"Strategy 2.{i}: Trying pattern for complete JSON objects")
            matches = re.findall(pattern, cleaned_text, re.DOTALL | re.IGNORECASE)
            logger.debug(f"Found {len(matches)} matches with pattern {i}")
            
            for j, match in enumerate(matches):
                logger.debug(f"  Match {j+1}: {repr(match)}")
                try:
                    # Clean the JSON string
                    cleaned = clean_json_string(match)
                    logger.debug(f"  Cleaned: {repr(cleaned)}")
                    
                    if cleaned:
                        parsed = json.loads(cleaned)
                        logger.debug(f"  Parsed successfully: {type(parsed)}")
                        
                        # Validate that this is a complete object with expected fields
                        if isinstance(parsed, dict):
                            # Check if this looks like a complete metadata object
                            expected_fields = ['district_office', 'usa_name', 'event_type', 'judge_name', 'judge_title', 'case_number', 'max_penalty_text', 'sentence_summary', 'money_amounts', 'crypto_assets', 'statutes_json', 'timeline_json']
                            found_fields = [field for field in expected_fields if field in parsed]
                            if len(found_fields) >= 3:  # At least 3 expected fields present
                                logger.debug(f"  ✅ Valid complete JSON object found with {len(found_fields)} expected fields")
                                return parsed
                            else:
                                logger.debug(f"  ❌ JSON object missing expected fields. Found: {found_fields}")
                        else:
                            logger.debug(f"  ❌ Parsed result is not a dict: {type(parsed)}")
                    else:
                        logger.debug(f"  ❌ JSON cleaning failed for: {repr(match)}")
                except (json.JSONDecodeError, TypeError) as e:
                    logger.debug(f"  ❌ JSON decode error: {e}")
                    continue
    
    # Strategy 3: Look for JSON arrays (for tables that expect arrays)
    if isinstance(cleaned_text, str):
        # Look for complete JSON arrays
        array_pattern = r'\[\s*(?:[^[\]]*|\[[^[\]]*\])*\s*\]'
        array_matches = list(re.finditer(array_pattern, cleaned_text, re.DOTALL))
        if array_matches:
            for match in reversed(array_matches):
                json_str = match.group(0)
                try:
                    cleaned = clean_json_string(json_str)
                    if cleaned:
                        parsed = json.loads(cleaned)
                        logger.debug(f"Successfully parsed JSON array with {len(parsed) if isinstance(parsed, list) else 'unknown'} items")
                        return parsed
                except json.JSONDecodeError as e:
                    logger.debug(f"Failed to parse array JSON: {e}")
                    continue
    
    # Strategy 4: Try to extract JSON after common markers
    if isinstance(cleaned_text, str):
        json_markers = [
            r'JSON Output:\s*(\{.*?\})',
            r'JSON Output:\s*(\[.*?\])',
            r'```json\s*(\{.*?\})\s*```',
            r'```json\s*(\[.*?\])\s*```',
            r'```\s*(\{.*?\})\s*```',
            r'```\s*(\[.*?\])\s*```',
            r'Output:\s*(\{.*?\})',
            r'Output:\s*(\[.*?\])',
            r'Result:\s*(\{.*?\})',
            r'Result:\s*(\[.*?\])'
        ]
        for pattern in json_markers:
            match = re.search(pattern, cleaned_text, re.DOTALL)
            if match:
                json_str = match.group(1)
                try:
                    cleaned = clean_json_string(json_str)
                    if cleaned:
                        parsed = json.loads(cleaned)
                        logger.debug(f"Successfully parsed JSON from marker pattern: {type(parsed)}")
                        return parsed
                except json.JSONDecodeError as e:
                    logger.debug(f"Failed to parse JSON from marker: {e}")
                    continue
    
    # Strategy 5: Try to parse the entire cleaned text as JSON
    if isinstance(cleaned_text, str):
        try:
            cleaned = clean_json_string(cleaned_text.strip())
            if cleaned:
                parsed = json.loads(cleaned)
                logger.debug(f"Successfully parsed entire cleaned text as JSON: {type(parsed)}")
                return parsed
        except json.JSONDecodeError as e:
            logger.debug(f"Failed to parse entire text as JSON: {e}")
    
    logger.warning("All JSON extraction strategies failed.")
    logger.debug(f"Final cleaned text that couldn't be parsed: {cleaned_text[:1000] if isinstance(cleaned_text, str) else 'not a string'}...")
    return None

def clean_json_string(json_str):
    """
    Clean and normalize a potentially dirty JSON string.
    Handles common issues like trailing commas, unquoted keys, etc.
    """
    if not json_str or not isinstance(json_str, str):
        logger.debug("JSON string is None or not a string")
        return None
    
    logger.debug(f"Cleaning JSON string: {repr(json_str)}")
    
    # Remove leading/trailing whitespace
    original = json_str
    json_str = json_str.strip()
    if json_str != original:
        logger.debug(f"  Stripped whitespace: {repr(json_str)}")
    
    # Common fixes for dirty JSON
    fixes = [
        # Remove trailing commas before closing braces/brackets
        (r',(\s*[}\]])', r'\1'),
        # Fix unquoted keys (basic cases)
        (r'(\s*)(\w+)(\s*):', r'\1"\2"\3:'),
        # Fix single quotes to double quotes
        (r"'([^']*)'", r'"\1"'),
        # Remove any non-printable characters
        (r'[\x00-\x1f\x7f-\x9f]', ''),
        # Fix common escape issues
        (r'\\"', '"'),
        (r'\\n', ' '),
        (r'\\t', ' '),
    ]
    
    for i, (pattern, replacement) in enumerate(fixes, 1):
        before = json_str
        json_str = re.sub(pattern, replacement, json_str)
        if json_str != before:
            logger.debug(f"  Fix {i}: {pattern} -> {replacement}")
            logger.debug(f"    Before: {repr(before)}")
            logger.debug(f"    After:  {repr(json_str)}")
    
    logger.debug(f"Final cleaned JSON: {repr(json_str)}")
    return json_str

def normalize_data_for_table(data, table_name):
    """Normalize data to expected format for each table."""
    if data is None:
        logger.warning(f"Data is None for table {table_name}")
        return None
    
    logger.debug(f"Normalizing data for {table_name}: type={type(data)}, value={repr(data)[:200]}...")
    
    # case_metadata expects a single object
    if table_name == 'case_metadata':
        if isinstance(data, list) and len(data) > 0:
            logger.warning(f"case_metadata expects a single object, got a list. Using first item.")
            first_item = data[0]
            if not isinstance(first_item, dict):
                logger.error(f"case_metadata first list item is not a dict: {type(first_item)}")
                return None
            return first_item
        elif isinstance(data, dict):
            return data
        else:
            logger.error(f"case_metadata expects a dict, got {type(data)}: {repr(data)}")
            return None
    
    # All other tables expect lists
    if isinstance(data, list):
        return data
    elif isinstance(data, dict):
        logger.warning(f"{table_name} expects a list, got a dict. Wrapping in list.")
        return [data]
    else:
        logger.error(f"{table_name} expects a list, got {type(data)}: {repr(data)}")
        return None

def log_enrichment_activity(case_id, table_name, status, notes, max_retries=3):
    from datetime import datetime, UTC
    for attempt in range(max_retries):
        try:
            conn = sqlite3.connect(DATABASE_NAME, timeout=60.0)
            cursor = conn.cursor()
            timestamp = datetime.now(UTC).isoformat()
            cursor.execute(
                "INSERT INTO enrichment_activity_log (timestamp, case_id, table_name, status, notes) VALUES (?, ?, ?, ?, ?)",
                (timestamp, case_id, table_name, status, notes)
            )
            conn.commit()
            conn.close()
            return True
        except Exception as e:
            logger.warning(f"Failed to log activity (attempt {attempt+1}/{max_retries}): {e}")
            if attempt < max_retries - 1:
                time.sleep(2 ** attempt)  # Exponential backoff
    logger.warning(f"Failed to log activity for case {case_id}, table {table_name} after {max_retries} attempts. Continuing without logging.")
    return False

def store_extracted_data(case_id, table_name, data, url):
    if not data:
        logger.warning(f"No data provided for case {case_id}, skipping storage.")
        return
    
    # Normalize data to expected format
    normalized_data = normalize_data_for_table(data, table_name)
    if normalized_data is None:
        logger.error(f"Failed to normalize data for table {table_name}")
        return
    
    logger.debug(f"Storing {len(normalized_data) if isinstance(normalized_data, list) else 1} items for table {table_name}")
    
    conn = None
    try:
        conn = sqlite3.connect(DATABASE_NAME, timeout=30.0)
        cursor = conn.cursor()
        
        if table_name == 'case_metadata':
            data_obj = normalized_data
            if not isinstance(data_obj, dict):
                logger.error(f"case_metadata expects a dict, got {type(data_obj)}: {repr(data_obj)}")
                if not log_enrichment_activity(case_id, table_name, 'error', f'Expected dict, got {type(data_obj)}'):
                    return
                return
            data_obj['press_release_url'] = url
            columns = ['case_id', 'district_office', 'usa_name', 'event_type', 'judge_name', 'judge_title', 'case_number', 'max_penalty_text', 'sentence_summary', 'money_amounts', 'crypto_assets', 'statutes_json', 'timeline_json', 'press_release_url', 'extras_json']
            values = [case_id]
            for col in columns[1:]:
                value = data_obj.get(col)
                if col in ['statutes_json', 'timeline_json', 'extras_json'] and value is not None:
                    values.append(json.dumps(value))
                else:
                    values.append(value)
            query = f"INSERT OR REPLACE INTO {table_name} ({', '.join(columns)}) VALUES ({', '.join(['?'] * len(columns))})"
            cursor.execute(query, tuple(values))
            logger.info(f"Successfully stored metadata for case {case_id}.")
            if not log_enrichment_activity(case_id, table_name, 'success', 'Stored metadata'):
                return
            
        elif table_name == 'participants':
            for participant in normalized_data:
                columns = ['case_id', 'name', 'role', 'title', 'organization', 'location', 'age', 'nationality', 'status']
                values = [case_id]
                for col in columns[1:]:
                    values.append(participant.get(col))
                query = f"INSERT OR REPLACE INTO {table_name} ({', '.join(columns)}) VALUES ({', '.join(['?'] * len(columns))})"
                cursor.execute(query, tuple(values))
            logger.info(f"Successfully stored {len(normalized_data)} participants for case {case_id}.")
            if not log_enrichment_activity(case_id, table_name, 'success', f"Stored {len(normalized_data)} participants"):
                return
                
        elif table_name == 'case_agencies':
            skipped = 0
            for agency in normalized_data:
                if not isinstance(agency, dict):
                    logger.warning(f"Skipping non-dict item in case_agencies: {repr(agency)} (type: {type(agency)})")
                    skipped += 1
                    if not log_enrichment_activity(case_id, table_name, 'skipped', f"Non-dict item: {repr(agency)}"):
                        return
                    continue
                columns = ['case_id', 'agency_name', 'abbreviation', 'role', 'office_location', 'agents_mentioned', 'contribution']
                values = [case_id]
                for col in columns[1:]:
                    values.append(agency.get(col))
                query = f"INSERT OR REPLACE INTO {table_name} ({', '.join(columns)}) VALUES ({', '.join(['?'] * len(columns))})"
                cursor.execute(query, tuple(values))
                if not log_enrichment_activity(case_id, table_name, 'success', f"Stored agency: {agency.get('agency_name')}"):
                    return
            logger.info(f"Successfully stored {len(normalized_data) - skipped} agencies for case {case_id}. Skipped {skipped} non-dict items.")
                
        elif table_name == 'charges':
            for charge in normalized_data:
                columns = ['case_id', 'charge_description', 'statute', 'severity', 'max_penalty', 'fine_amount', 'defendant', 'status']
                values = [case_id]
                for col in columns[1:]:
                    values.append(charge.get(col))
                query = f"INSERT OR REPLACE INTO {table_name} ({', '.join(columns)}) VALUES ({', '.join(['?'] * len(columns))})"
                cursor.execute(query, tuple(values))
                if not log_enrichment_activity(case_id, table_name, 'success', f"Stored charge: {charge.get('statute')}"):
                    return
            logger.info(f"Successfully stored {len(normalized_data)} charges for case {case_id}.")
                
        elif table_name == 'financial_actions':
            for action in normalized_data:
                columns = ['case_id', 'action_type', 'amount', 'currency', 'description', 'asset_type', 'defendant', 'status']
                values = [case_id]
                for col in columns[1:]:
                    values.append(action.get(col))
                query = f"INSERT OR REPLACE INTO {table_name} ({', '.join(columns)}) VALUES ({', '.join(['?'] * len(columns))})"
                cursor.execute(query, tuple(values))
                if not log_enrichment_activity(case_id, table_name, 'success', f"Stored action: {action.get('action_type')}"):
                    return
            logger.info(f"Successfully stored {len(normalized_data)} financial actions for case {case_id}.")
                
        elif table_name == 'victims':
            for victim in normalized_data:
                columns = ['case_id', 'victim_type', 'description', 'number_affected', 'loss_amount', 'geographic_scope', 'vulnerability_factors', 'impact_description']
                values = [case_id]
                for col in columns[1:]:
                    values.append(victim.get(col))
                query = f"INSERT OR REPLACE INTO {table_name} ({', '.join(columns)}) VALUES ({', '.join(['?'] * len(columns))})"
                cursor.execute(query, tuple(values))
                if not log_enrichment_activity(case_id, table_name, 'success', f"Stored victim: {victim.get('victim_type')}"):
                    return
            logger.info(f"Successfully stored {len(normalized_data)} victims for case {case_id}.")
                
        elif table_name == 'quotes':
            for quote in normalized_data:
                columns = ['case_id', 'quote_text', 'speaker_name', 'speaker_title', 'speaker_organization', 'quote_type', 'context', 'significance']
                values = [case_id]
                for col in columns[1:]:
                    values.append(quote.get(col))
                query = f"INSERT OR REPLACE INTO {table_name} ({', '.join(columns)}) VALUES ({', '.join(['?'] * len(columns))})"
                cursor.execute(query, tuple(values))
                if not log_enrichment_activity(case_id, table_name, 'success', f"Stored quote: {quote.get('speaker_name')}"):
                    return
            logger.info(f"Successfully stored {len(normalized_data)} quotes for case {case_id}.")
                
        elif table_name == 'themes':
            for theme in normalized_data:
                columns = ['case_id', 'theme_name', 'description', 'significance', 'related_statutes', 'geographic_scope', 'temporal_aspects', 'stakeholders']
                values = [case_id]
                for col in columns[1:]:
                    values.append(theme.get(col))
                query = f"INSERT OR REPLACE INTO {table_name} ({', '.join(columns)}) VALUES ({', '.join(['?'] * len(columns))})"
                cursor.execute(query, tuple(values))
                if not log_enrichment_activity(case_id, table_name, 'success', f"Stored theme: {theme.get('theme_name')}"):
                    return
            logger.info(f"Successfully stored {len(normalized_data)} themes for case {case_id}.")
                
        else:
            logger.error(f"Storage logic for table '{table_name}' is not yet implemented.")
            return
            
        conn.commit()
        
    except sqlite3.Error as e:
        logger.error(f"Failed to store data for case {case_id} in table {table_name}: {e}")
        logger.debug(f"Data: {data}")
        log_enrichment_activity(case_id, table_name, 'error', f"Failed to store data: {e}")
    finally:
        if conn:
            conn.close()

def main():
    parser = argparse.ArgumentParser(description="Enrich DOJ cases with structured data using an LLM.")
    parser.add_argument(
        '--table',
        required=False,
        choices=TABLE_CHOICES,
        help="The target table to enrich."
    )
    parser.add_argument(
        '--limit',
        type=int,
        default=10,
        help="Number of cases to process in this run."
    )
    parser.add_argument(
        '--dry-run',
        action='store_true',
        help="Simulate the process without making API calls or writing to DB."
    )
    parser.add_argument(
        '--verbose',
        '-v',
        action='store_true',
        help="Enable verbose logging."
    )
    parser.add_argument(
        '--setup-only',
        action='store_true',
        help="Only set up the database tables and exit."
    )
    args = parser.parse_args()
    if args.verbose:
        logger.setLevel(logging.DEBUG)
        logger.debug("Verbose logging enabled")
    if args.setup_only:
        setup_enrichment_tables()
        return
    if not args.table:
        parser.error("--table is required unless you are using --setup-only.")
    logger.info(f"Starting enrichment process for table: '{args.table}'")
    cases_to_process = get_cases_to_enrich(args.table, args.limit)
    if not cases_to_process:
        logger.info("No new cases to process. Exiting.")
        return
    for case in cases_to_process:
        case_id, title, body, url = case
        logger.info(f"Processing case {case_id}...")
        prompt = get_extraction_prompt(args.table, title, body)
        if not prompt:
            continue
        if args.dry_run:
            print(f"[DRY RUN] Would process case {case_id} for table '{args.table}'.")
            logger.info(f"[DRY RUN] Would process case {case_id} for table '{args.table}'.")
            logger.debug(f"[DRY RUN] Prompt for case {case_id}:\n{prompt[:500]}...")
            continue
        api_response = call_venice_api(prompt)
        if not api_response:
            logger.warning(f"Skipping case {case_id} due to API failure.")
            continue
        content = api_response.get('choices', [{}])[0].get('message', {}).get('content', '')
        if not content:
            logger.warning(f"No content in API response for case {case_id}.")
            continue
        extracted_data = clean_and_parse_json(content)
        if not extracted_data:
            logger.warning(f"Failed to parse data for case {case_id}. Check logs for details.")
            with open(f"failed_parse_{case_id}.txt", "w", encoding="utf-8") as f:
                f.write(content)
            log_enrichment_activity(case_id, args.table, 'error', 'Failed to parse data')
            continue
        store_extracted_data(case_id, args.table, extracted_data, url)
        time.sleep(1)
    logger.info("Enrichment run complete.")

if __name__ == "__main__":
    main()