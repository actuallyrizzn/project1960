import sqlite3
import requests
import json
import time
from collections import deque
import re
import logging
import argparse
import os
from dotenv import load_dotenv

# Load environment variables
load_dotenv()

VENICE_API_KEY = os.getenv("VENICE_API_KEY")
VENICE_API_URL = "https://api.venice.ai/api/v1/chat/completions"
MODEL_NAME = "qwen-2.5-qwq-32b"
PROCESSING_LIMIT = 100  # Changed to 3 for testing

# Set up logging
logging.basicConfig(level=logging.INFO, format='%(asctime)s - %(levelname)s - %(message)s')
logger = logging.getLogger(__name__)

# Validate required environment variables
if not VENICE_API_KEY:
    logger.error("VENICE_API_KEY environment variable is not set!")
    logger.error("Please create a .env file with your Venice API key:")
    logger.error("VENICE_API_KEY=your_api_key_here")
    exit(1)

def alter_database_table():
    """Alter the database to add the 'verified_1960', 'verified_crypto', and 'classification' columns if they don't exist."""
    conn = sqlite3.connect("doj_cases.db")
    cursor = conn.cursor()

    # Start a transaction
    cursor.execute("PRAGMA foreign_keys=off;")

    # Check if the columns 'verified_1960', 'verified_crypto', and 'classification' exist
    cursor.execute("PRAGMA table_info(cases);")
    existing_columns = [col[1] for col in cursor.fetchall()]

    # Add 'verified_1960' and 'verified_crypto' columns if they don't exist
    if 'verified_1960' not in existing_columns:
        cursor.execute("ALTER TABLE cases ADD COLUMN verified_1960 BOOLEAN DEFAULT FALSE;")
    if 'verified_crypto' not in existing_columns:
        cursor.execute("ALTER TABLE cases ADD COLUMN verified_crypto BOOLEAN DEFAULT FALSE;")
    if 'classification' not in existing_columns:
        cursor.execute("ALTER TABLE cases ADD COLUMN classification TEXT;")

    # Create the new table with the updated schema
    cursor.execute("""
        CREATE TABLE IF NOT EXISTS cases_temp AS
        SELECT id, title, date, body, url, teaser, number, component, topic, changed, created,
               mentions_1960, mentions_crypto,
               COALESCE(verified_1960, FALSE) as verified_1960,
               COALESCE(verified_crypto, FALSE) as verified_crypto,
               classification
        FROM cases;
    """)

    # Drop the old table
    cursor.execute("DROP TABLE IF EXISTS cases;")

    # Rename the new table to the original table name
    cursor.execute("ALTER TABLE cases_temp RENAME TO cases;")

    # Re-enable foreign key constraints
    cursor.execute("PRAGMA foreign_keys=on;")

    conn.commit()
    conn.close()

alter_database_table()

def get_sample_cases(limit=PROCESSING_LIMIT):
    """Retrieve UNPROCESSED sample cases from the database."""
    logger.debug(f"Fetching {limit} unprocessed sample cases from DB...")
    conn = sqlite3.connect("doj_cases.db")
    cursor = conn.cursor()
    cursor.execute("""
        SELECT id, title, body
        FROM cases
        WHERE mentions_1960 = 1
          AND (classification IS NULL OR classification = '')
        LIMIT ?
    """, (limit,))
    rows = cursor.fetchall()
    conn.close()
    logger.debug(f"Retrieved {len(rows)} unprocessed rows.")
    return rows

def store_classification(case_id, classification, verified_1960, verified_crypto):
    """Store the classification result into the database."""
    logger.info(f"Case {case_id} final classification: {classification}, Verified 1960: {verified_1960}")
    conn = sqlite3.connect("doj_cases.db")
    cursor = conn.cursor()
    cursor.execute("""
        UPDATE cases
        SET classification = ?, verified_1960 = ?, verified_crypto = ?
        WHERE id = ?
    """, (classification, verified_1960, verified_crypto, case_id))
    conn.commit()
    conn.close()

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

def extract_json_from_content(content):
    """
    Extract JSON from AI response content using multiple strategies.
    Returns the parsed JSON object or None if extraction fails.
    """
    if not content or not isinstance(content, str):
        logger.debug("Content is None or not a string")
        return None
    
    logger.debug(f"Attempting to extract JSON from content: {repr(content[:200])}...")
    
    # Strategy 1: Look for complete JSON objects with balanced braces
    json_patterns = [
        # Standard JSON object
        r'\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}',
        # JSON with nested objects (more complex)
        r'\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}(?=\s*$|\s*[^,])',
        # Simple key-value pairs
        r'\{[^}]*"answer"[^}]*\}',
    ]
    
    for i, pattern in enumerate(json_patterns, 1):
        logger.debug(f"Strategy 1.{i}: Trying pattern {pattern}")
        matches = re.findall(pattern, content, re.DOTALL | re.IGNORECASE)
        logger.debug(f"Found {len(matches)} matches with pattern {i}")
        
        for j, match in enumerate(matches):
            logger.debug(f"  Match {j+1}: {repr(match)}")
            try:
                # Clean the JSON string
                cleaned = clean_json_string(match)
                logger.debug(f"  Cleaned: {repr(cleaned)}")
                
                if cleaned:
                    parsed = json.loads(cleaned)
                    logger.debug(f"  Parsed successfully: {parsed}")
                    
                    if isinstance(parsed, dict) and "answer" in parsed:
                        logger.debug(f"  ✅ Valid JSON with 'answer' field found: {parsed}")
                        return parsed
                    else:
                        logger.debug(f"  ❌ JSON parsed but missing 'answer' field: {parsed}")
                else:
                    logger.debug(f"  ❌ JSON cleaning failed for: {repr(match)}")
            except (json.JSONDecodeError, TypeError) as e:
                logger.debug(f"  ❌ JSON decode error: {e}")
                continue
    
    # Strategy 2: Look for answer patterns in the text
    logger.debug("Strategy 2: Looking for answer patterns in text")
    answer_patterns = [
        r'"answer"\s*:\s*"([^"]+)"',
        r"'answer'\s*:\s*'([^']+)'",
        r'answer\s*:\s*"([^"]+)"',
        r'answer\s*:\s*\'([^\']+)\'',
    ]
    
    for i, pattern in enumerate(answer_patterns, 1):
        logger.debug(f"  Trying answer pattern {i}: {pattern}")
        match = re.search(pattern, content, re.IGNORECASE)
        if match:
            answer = match.group(1).lower().strip()
            logger.debug(f"  Found answer: {repr(answer)}")
            if answer in ["yes", "no", "unknown"]:
                logger.debug(f"  ✅ Valid answer extracted: {answer}")
                return {"answer": answer}
            else:
                logger.debug(f"  ❌ Invalid answer value: {repr(answer)}")
    
    # Strategy 3: Look for direct yes/no/unknown responses
    logger.debug("Strategy 3: Looking for direct yes/no/unknown responses")
    direct_patterns = [
        r'\b(yes|no|unknown)\b',
        r'{\s*"answer"\s*:\s*"(yes|no|unknown)"\s*}',
    ]
    
    for i, pattern in enumerate(direct_patterns, 1):
        logger.debug(f"  Trying direct pattern {i}: {pattern}")
        match = re.search(pattern, content, re.IGNORECASE)
        if match:
            answer = match.group(1).lower().strip()
            logger.debug(f"  ✅ Direct answer found: {answer}")
            return {"answer": answer}
    
    logger.debug("❌ All extraction strategies failed")
    return None

def safe_extract_response_data(response_data):
    """
    Safely extract content from API response with multiple fallback strategies.
    """
    if not response_data or not isinstance(response_data, dict):
        logger.debug("Response data is None or not a dict")
        return None
    
    logger.debug(f"Extracting content from response data with keys: {list(response_data.keys())}")
    
    # Strategy 1: Standard OpenAI-style response
    try:
        if "choices" in response_data and response_data["choices"]:
            choice = response_data["choices"][0]
            if "message" in choice and "content" in choice["message"]:
                content = choice["message"]["content"]
                logger.debug(f"✅ Strategy 1 (OpenAI format) succeeded: {repr(content[:100])}...")
                return content
    except (KeyError, IndexError, TypeError) as e:
        logger.debug(f"❌ Strategy 1 (OpenAI format) failed: {e}")
    
    # Strategy 2: Direct content field
    try:
        if "content" in response_data:
            content = response_data["content"]
            logger.debug(f"✅ Strategy 2 (direct content) succeeded: {repr(content[:100])}...")
            return content
    except (KeyError, TypeError) as e:
        logger.debug(f"❌ Strategy 2 (direct content) failed: {e}")
    
    # Strategy 3: Look for any text field
    text_fields = ["text", "response", "result", "output", "message"]
    for field in text_fields:
        try:
            if field in response_data:
                content = response_data[field]
                if isinstance(content, str):
                    logger.debug(f"✅ Strategy 3 (text field '{field}') succeeded: {repr(content[:100])}...")
                    return content
                elif isinstance(content, dict) and "content" in content:
                    content = content["content"]
                    logger.debug(f"✅ Strategy 3 (nested content in '{field}') succeeded: {repr(content[:100])}...")
                    return content
        except (KeyError, TypeError) as e:
            logger.debug(f"❌ Strategy 3 (text field '{field}') failed: {e}")
    
    # Strategy 4: Convert entire response to string and look for patterns
    try:
        response_str = str(response_data)
        logger.debug(f"Strategy 4: Converting response to string: {repr(response_str[:200])}...")
        # Look for any JSON-like content in the response
        json_match = re.search(r'\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}', response_str)
        if json_match:
            content = json_match.group(0)
            logger.debug(f"✅ Strategy 4 (string conversion) succeeded: {repr(content)}")
            return content
    except Exception as e:
        logger.debug(f"❌ Strategy 4 (string conversion) failed: {e}")
    
    logger.debug("❌ All content extraction strategies failed")
    return None

def classify_case(case_id, title, body):
    """Classify the case by sending it to Venice for analysis."""
    logger.info(f"Classifying case {case_id}")
    system_prompt = (
        "You are a legal AI assistant specialized in U.S. financial crime law, specifically 18 U.S.C. § 1960 "
        "(Unlicensed Money Transmitting Business). The user will provide a U.S. Department of Justice press release. "
        "Your task is to determine if it describes a clear violation of 18 U.S.C. § 1960.\n\n"
        "18 USC 1960 applies when:\n"
        "✅ A business transmits money for others without a required state or federal license.\n"
        "✅ A person or entity moves funds without registering with FinCEN.\n"
        "✅ The money transmission activity is linked to illicit activities.\n\n"
        "It does NOT apply to:\n"
        "❌ General fraud, tax evasion, or theft (unless money transmission is involved).\n"
        "❌ Bank fraud, securities fraud, or insurance fraud (without an unlicensed money transmission aspect).\n"
        "❌ Cases where money movement is internal corporate transfers.\n\n"
        "Confidence Threshold for Answering:\n"
        "- If >=85% certain it's a violation, return { \"answer\": \"yes\" }.\n"
        "- If >=85% certain it's NOT, return { \"answer\": \"no\" }.\n"
        "- Otherwise, return { \"answer\": \"unknown\" }.\n\n"
        "IMPORTANT: Valid JSON only.\n"
    )

    user_prompt = (
        f"Press release title: {title}\n\n"
        f"{body}\n\n"
        "Does this press release from the DoJ in any way concern an Unlicensed Money Transmitter case (18 USC 1960)? "
        "Please ONLY respond in {\"answer\":\"yes\"}, {\"answer\":\"no\"}, or {\"answer\":\"unknown\"}."
    )

    payload = {
        "model": MODEL_NAME,
        "messages": [
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": user_prompt}
        ],
        "max_completion_tokens": 1000
    }

    headers = {
        "Authorization": f"Bearer {VENICE_API_KEY}",
        "Content-Type": "application/json"
    }

    retries = 3
    for attempt in range(retries):
        try:
            logger.debug(f"Sending request to Venice API for case {case_id} (attempt {attempt+1})...")
            response = requests.post(VENICE_API_URL, headers=headers, json=payload, timeout=120)
            logger.debug(f"Received response with status code: {response.status_code} for case {case_id}")
            break
        except (requests.exceptions.ConnectionError, requests.exceptions.ReadTimeout) as e:
            logger.error(f"Connection/ReadTimeout error on attempt {attempt+1} for case {case_id}: {e}")
            if attempt < retries - 1:
                time.sleep(2)  # Wait a bit before retrying
            else:
                logger.error(f"All retry attempts failed for case {case_id}. Re-queueing case.")
                return "rate-limit"

    if response.status_code == 429:
        logger.warning(f"Case {case_id} hit rate limit. Response: {response.text}")
        return "rate-limit"
    elif response.status_code != 200:
        logger.error(f"Case {case_id} received unexpected status code {response.status_code}. Response: {response.text}")
        return "unknown"

    try:
        # Parse the main response JSON
        data = response.json()
        logger.debug(f"Raw API JSON response for case {case_id}: {data}")
        
        # Safely extract content from the response
        content = safe_extract_response_data(data)
        if not content:
            logger.warning(f"Case {case_id}: Could not extract content from API response")
            return "unknown"
        
        logger.debug(f"Extracted content for case {case_id}: {content}")

        # Use robust JSON extraction
        parsed_result = extract_json_from_content(content)
        if not parsed_result:
            logger.warning(f"Case {case_id}: Could not extract valid JSON from content")
            return "unknown"

        logger.debug(f"Parsed JSON for case {case_id}: {parsed_result}")
        answer = parsed_result.get("answer", "unknown").lower()
        
        # Validate the answer
        if answer not in ["yes", "no", "unknown"]:
            logger.warning(f"Case {case_id}: Answer '{answer}' is not recognized, defaulting to 'unknown'.")
            answer = "unknown"
        
        return answer

    except json.JSONDecodeError as e:
        logger.error(f"JSON decode error for case {case_id}: {str(e)}. Response text was: {response.text}")
        return "unknown"
    except Exception as e:
        logger.error(f"Unexpected error processing case {case_id}: {str(e)}")
        return "unknown"

def classify_case_dry_run(case_id, title, body):
    """Simulate classification without making actual API calls."""
    logger.info(f"[DRY RUN] Would classify case {case_id}")
    logger.info(f"[DRY RUN] Title: {title[:100]}...")
    logger.info(f"[DRY RUN] Body preview: {body[:200]}...")
    
    # Simulate API response time
    time.sleep(0.1)
    
    # Return a simulated result
    import random
    simulated_results = ["yes", "no", "unknown"]
    result = random.choice(simulated_results)
    logger.info(f"[DRY RUN] Simulated classification result: {result}")
    return result

def main():
    # Set up command line argument parsing
    parser = argparse.ArgumentParser(
        description="Verify 18 USC 1960 violations in DOJ press releases using AI analysis",
        formatter_class=argparse.RawDescriptionHelpFormatter,
        epilog="""
Examples:
  python verify_1960.py                    # Process real cases
  python verify_1960.py --dry-run          # Test mode without API calls
  python verify_1960.py --limit 10         # Process only 10 cases
  python verify_1960.py --dry-run --limit 5 # Test with 5 cases
        """
    )
    
    parser.add_argument(
        '--dry-run', 
        action='store_true',
        help='Run in test mode without making actual API calls'
    )
    
    parser.add_argument(
        '--limit', 
        type=int, 
        default=PROCESSING_LIMIT,
        help=f'Maximum number of cases to process (default: {PROCESSING_LIMIT})'
    )
    
    parser.add_argument(
        '--verbose', 
        action='store_true',
        help='Enable verbose logging (DEBUG level)'
    )
    
    args = parser.parse_args()
    
    # Set logging level based on verbose flag
    if args.verbose:
        logging.getLogger().setLevel(logging.DEBUG)
        logger.debug("Verbose logging enabled")
    
    # Log the mode we're running in
    if args.dry_run:
        logger.info("Starting DRY RUN mode - no actual API calls will be made")
    else:
        logger.info("Starting REAL processing mode - will make actual API calls")
    
    logger.info(f"Processing limit: {args.limit} cases")
    
    # Get cases to process
    rows = get_sample_cases(limit=args.limit)
    if not rows:
        logger.info("No unprocessed cases found in database")
        return
    
    queue = deque(rows)
    logger.info(f"Found {len(queue)} cases to process")

    while queue:
        case_id, title, body = queue.popleft()
        logger.info(f"Processing case {case_id}")

        # Choose classification method based on dry-run flag
        if args.dry_run:
            classification = classify_case_dry_run(case_id, title, body)
        else:
            classification = classify_case(case_id, title, body)
        
        logger.info(f"Classification result for case {case_id}: {classification}")

        if classification == "rate-limit":
            logger.warning("Rate limit hit. Sleeping 2s then re-queueing case.")
            time.sleep(2)
            queue.append((case_id, title, body))
        else:
            # Only store results if not in dry-run mode
            if not args.dry_run:
                is_verified = classification.lower() == "yes"
                store_classification(
                    case_id=case_id,
                    classification=classification,
                    verified_1960=is_verified,
                    verified_crypto=False
                )
                logger.info(f"Stored classification for case {case_id}")
            else:
                logger.info(f"[DRY RUN] Would store classification: {classification}")

        # Shorter sleep for dry-run mode
        sleep_time = 0.5 if args.dry_run else 2
        time.sleep(sleep_time)

    if args.dry_run:
        logger.info("DRY RUN completed - no actual changes were made")
    else:
        logger.info("All cases processed or re-queued until done.")

if __name__ == "__main__":
    main()
