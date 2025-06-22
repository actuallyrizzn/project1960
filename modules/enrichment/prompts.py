"""
AI prompt templates for data extraction from DOJ cases.
"""
from typing import Dict, Any

def get_extraction_prompt(table_name: str, title: str, body: str) -> str:
    """Get the appropriate extraction prompt for a given table."""
    if table_name == 'case_metadata':
        return _get_case_metadata_prompt(title, body)
    elif table_name == 'participants':
        return _get_participants_prompt(title, body)
    elif table_name == 'case_agencies':
        return _get_case_agencies_prompt(title, body)
    elif table_name == 'charges':
        return _get_charges_prompt(title, body)
    elif table_name == 'financial_actions':
        return _get_financial_actions_prompt(title, body)
    elif table_name == 'victims':
        return _get_victims_prompt(title, body)
    elif table_name == 'quotes':
        return _get_quotes_prompt(title, body)
    elif table_name == 'themes':
        return _get_themes_prompt(title, body)
    else:
        raise ValueError(f"Unknown table name: {table_name}")

def _get_case_metadata_prompt(title: str, body: str) -> str:
    """Get prompt for case metadata extraction."""
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

def _get_participants_prompt(title: str, body: str) -> str:
    """Get prompt for participants extraction."""
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

def _get_case_agencies_prompt(title: str, body: str) -> str:
    """Get prompt for case agencies extraction."""
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
    * `agents_mentioned`: Names of specific agents mentioned as a comma-separated string (e.g., "John Smith, Jane Doe")
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

def _get_charges_prompt(title: str, body: str) -> str:
    """Get prompt for charges extraction."""
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

def _get_financial_actions_prompt(title: str, body: str) -> str:
    """Get prompt for financial actions extraction."""
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

def _get_victims_prompt(title: str, body: str) -> str:
    """Get prompt for victims extraction."""
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

def _get_quotes_prompt(title: str, body: str) -> str:
    """Get prompt for quotes extraction."""
    return f"""
You are a legal data extraction expert. Your task is to analyze the following U.S. Department of Justice press release and extract all significant quotes mentioned. Return ONLY a JSON array of quote objects with no additional text.

**Instructions:**
1. Read the entire press release text provided below.
2. Extract all significant quotes mentioned in the press release.
3. For each quote, create a JSON object with these fields:
    * `quote_text`: The exact quote text
    * `speaker_name`: Name of the person who said the quote
    * `speaker_title`: Title of the speaker if mentioned
    * `speaker_organization`: Organization the speaker represents
    * `quote_type`: Type of quote (e.g., "statement", "testimony", "comment")
    * `context`: Context in which the quote was made
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

def _get_themes_prompt(title: str, body: str) -> str:
    """Get prompt for themes extraction."""
    return f"""
You are a legal data extraction expert. Your task is to analyze the following U.S. Department of Justice press release and extract key themes and topics. Return ONLY a JSON array of theme objects with no additional text.

**Instructions:**
1. Read the entire press release text provided below.
2. Extract key themes and topics mentioned in the press release.
3. For each theme, create a JSON object with these fields:
    * `theme_name`: Name of the theme (e.g., "Money Laundering", "Cryptocurrency", "International Cooperation")
    * `description`: Description of how this theme appears in the case
    * `significance`: Why this theme is important to the case
    * `related_statutes`: Any statutes related to this theme as a comma-separated string (e.g., "18 U.S.C. § 1960, 21 U.S.C. § 846")
    * `geographic_scope`: Geographic scope of this theme (e.g., "International", "National", "Local")
    * `temporal_aspects`: Time-related aspects of this theme (e.g., "Historical trend", "Recent development")
    * `stakeholders`: Key stakeholders involved in this theme as a comma-separated string (e.g., "Law enforcement, Financial institutions, Victims")
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
    "geographic_scope": "value or null",
    "temporal_aspects": "value or null",
    "stakeholders": "value or null"
  }}
]
""" 