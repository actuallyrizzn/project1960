import requests
from bs4 import BeautifulSoup
import sqlite3
import time
import re
import os
from dotenv import load_dotenv
from rapidfuzz import process, fuzz
from datetime import datetime

# Load environment variables
load_dotenv()

# Configuration
BASE_URL = "https://www.justice.gov/news"
DATABASE_NAME = "doj_cases.db"

DOJ_API_URL = "https://www.justice.gov/api/v1/press_releases.json"

# Local detection terms
LAW_VARIATIONS = ["18 USC 1960", "§ 1960", "unlicensed money transmitting"]
CRYPTO_TERMS = ["Bitcoin", "Ethereum", "crypto", "cryptocurrency"]

LAW_REGEX = r"(18[\s\.U.S.C]*1960|\§\s*1960|unlicensed money transmitting)"

##################################
# Database Setup
##################################
def setup_database():
    print("Setting up the database...")
    conn = sqlite3.connect(DATABASE_NAME)
    cursor = conn.cursor()
    cursor.execute('''
        CREATE TABLE IF NOT EXISTS cases (
            id TEXT PRIMARY KEY,
            title TEXT,
            date TEXT,
            body TEXT,
            url TEXT,
            teaser TEXT,
            number TEXT,
            component TEXT,
            topic TEXT,
            changed TEXT,
            created TEXT,
            mentions_1960 BOOLEAN,
            mentions_crypto BOOLEAN
        )
    ''')
    conn.commit()
    conn.close()
    print("Database setup complete.")

def get_most_recent_date():
    """Get the most recent date from the database to determine where to start scraping."""
    conn = sqlite3.connect(DATABASE_NAME)
    cursor = conn.cursor()
    
    # Get the most recent date from existing records
    cursor.execute('''
        SELECT MAX(date) FROM cases 
        WHERE date IS NOT NULL AND date != ''
    ''')
    
    result = cursor.fetchone()
    most_recent_date = result[0] if result and result[0] else None
    
    conn.close()
    
    if most_recent_date:
        print(f"Most recent date in database: {most_recent_date}")
        return most_recent_date
    else:
        print("No existing records found. Will scrape from the beginning.")
        return None

##################################
# Local Matching
##################################
def check_1960(text):
    """Returns True if text matches 18 USC 1960 or variations."""
    if re.search(LAW_REGEX, text, re.IGNORECASE):
        return True
    match = process.extractOne(text, LAW_VARIATIONS, scorer=fuzz.partial_ratio)
    if match and match[1] > 80:
        return True
    return False

def check_crypto(text):
    """Returns True if text mentions crypto-related terms."""
    match = process.extractOne(text, CRYPTO_TERMS, scorer=fuzz.partial_ratio)
    if match and match[1] > 80:
        return True
    return False

##################################
# Insert a Single Case
##################################
def store_case(item):
    """Inserts a single DOJ record into SQLite (ignore if ID exists).
    Returns True if the case was stored, False otherwise."""

    conn = sqlite3.connect(DATABASE_NAME)
    cursor = conn.cursor()

    case_id = item.get("uuid")
    title = item.get("title", "")
    date_ = item.get("date", "")
    body = item.get("body", "")
    url = item.get("url", "")
    teaser = item.get("teaser", "")
    number = item.get("number", "")

    # Convert component list -> string
    component = ""
    if isinstance(item.get("component"), list):
        component = ", ".join(
            c.get("name", "") for c in item["component"] if isinstance(c, dict)
        )
    else:
        component = str(item.get("component", ""))

    # Convert topic list -> string
    topic = ""
    if isinstance(item.get("topic"), list):
        topic = ", ".join(
            str(t) for t in item["topic"] if not isinstance(t, dict)
        )
    else:
        topic = str(item.get("topic", ""))

    changed = item.get("changed", "")
    created = item.get("created", "")

    # Local checks
    mentions_1960 = check_1960(body)
    mentions_crypto = check_crypto(body)

    # Insert only if it matches at least one
    if mentions_1960 or mentions_crypto:
        cursor.execute('''
            INSERT OR IGNORE INTO cases
            (id, title, date, body, url, teaser, number, component, topic,
             changed, created, mentions_1960, mentions_crypto)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ''', (
            case_id, title, date_, body, url, teaser, number,
            component, topic, changed, created,
            mentions_1960, mentions_crypto
        ))
        
        # Check if a row was actually inserted (not ignored due to duplicate)
        if cursor.rowcount > 0:
            print(f"Stored NEW match: {title[:60]}... | 1960={mentions_1960} crypto={mentions_crypto}")
            stored = True
        else:
            print(f"Skipped existing match: {title[:60]}... | 1960={mentions_1960} crypto={mentions_crypto}")
            stored = False
    else:
        stored = False

    conn.commit()
    conn.close()
    return stored

def get_last_processed_page():
    """Get the last page number that was successfully processed."""
    conn = sqlite3.connect(DATABASE_NAME)
    cursor = conn.cursor()
    
    # Check if we have a last_page table
    cursor.execute("""
        SELECT name FROM sqlite_master 
        WHERE type='table' AND name='scraper_state'
    """)
    
    if not cursor.fetchone():
        # Create the table if it doesn't exist
        cursor.execute('''
            CREATE TABLE scraper_state (
                key TEXT PRIMARY KEY,
                value TEXT
            )
        ''')
        conn.commit()
        conn.close()
        return 0
    
    cursor.execute('SELECT value FROM scraper_state WHERE key = "last_page"')
    result = cursor.fetchone()
    last_page = int(result[0]) if result and result[0] else 0
    
    conn.close()
    return last_page

def save_last_processed_page(page):
    """Save the last page number that was successfully processed."""
    conn = sqlite3.connect(DATABASE_NAME)
    cursor = conn.cursor()
    
    cursor.execute('''
        INSERT OR REPLACE INTO scraper_state (key, value) 
        VALUES (?, ?)
    ''', ('last_page', str(page)))
    
    conn.commit()
    conn.close()

##################################
# Full Crawl with Indefinite Pagination
##################################
def fetch_all(wait_sec=2):
    """
    Fetch results from the DOJ API (pagesize=50), page by page.
    Start from the last processed page to avoid re-processing.
    Stop only when we hit an empty 'results' or a request error.
    Each page is locally filtered for 1960/crypto mentions before storing.
    """
    # Start from the last processed page + 1
    start_page = get_last_processed_page() + 1
    page = start_page
    total_fetched = 0
    total_stored = 0
    oldest_date = None
    newest_date = None

    print(f"Starting scrape from page {start_page} (last processed: {start_page - 1})...")

    while True:
        params = {"pagesize": 50, "page": page}
        
        # Add timeout and retry logic
        max_retries = 3
        retry_count = 0
        
        while retry_count < max_retries:
            try:
                print(f"Fetching page {page} (attempt {retry_count + 1}/{max_retries})...")
                response = requests.get(DOJ_API_URL, params=params, timeout=30)
                break  # Success, exit retry loop
            except requests.exceptions.Timeout:
                retry_count += 1
                if retry_count < max_retries:
                    print(f"Timeout on page {page}, retrying in 5 seconds... (attempt {retry_count + 1}/{max_retries})")
                    time.sleep(5)
                else:
                    print(f"Failed to fetch page {page} after {max_retries} attempts due to timeout")
                    print(f"Stopping crawl due to persistent timeout.")
                    return
            except requests.exceptions.RequestException as e:
                retry_count += 1
                if retry_count < max_retries:
                    print(f"Request error on page {page}: {e}, retrying in 5 seconds... (attempt {retry_count + 1}/{max_retries})")
                    time.sleep(5)
                else:
                    print(f"Failed to fetch page {page} after {max_retries} attempts: {e}")
                    print(f"Stopping crawl due to persistent request error.")
                    return
        
        if response.status_code != 200:
            print(f"Error fetching page {page}: {response.status_code}")
            print(f"Response content: {response.text[:200]}...")
            print(f"Stopping crawl due to API error.")
            break

        data = response.json()
        results = data.get("results", [])
        if not results:
            print(f"No more results at page {page}. Ending crawl.")
            break

        total_fetched += len(results)
        print(f"Fetched {len(results)} results on page {page} (total fetched: {total_fetched})")

        # Track oldest/newest dates
        for item in results:
            item_date = item.get("date", None)
            if item_date:
                if not oldest_date or item_date < oldest_date:
                    oldest_date = item_date
                if not newest_date or item_date > newest_date:
                    newest_date = item_date

        # Store cases if they match our criteria
        page_stored = 0
        for item in results:
            if store_case(item):
                page_stored += 1
                total_stored += 1
        print(f"Stored {page_stored} new matches from page {page}")
        
        # Save this page as successfully processed
        save_last_processed_page(page)

        page += 1
        # Polite wait to avoid hitting the API too fast
        time.sleep(wait_sec)

    print(f"\nCrawl complete!")
    print(f"Total items fetched: {total_fetched}")
    print(f"Total new matches stored: {total_stored}")
    print(f"Oldest date seen: {oldest_date}")
    print(f"Newest date seen: {newest_date}")
    print(f"Last page processed: {page - 1}")
    if total_stored == 0:
        print("No new matching content found.")
    else:
        print("Check your doj_cases.db for the new stored matches!")

def main():
    setup_database()
    try:
        fetch_all(wait_sec=2)
        print("\nCrawl complete. Check your doj_cases.db for stored matches!")
    except Exception as e:
        print(f"\nCRITICAL ERROR: Scraper failed with exception: {e}")
        import traceback
        traceback.print_exc()
        print(f"\nScraper stopped due to unhandled exception.")

if __name__ == "__main__":
    main()
