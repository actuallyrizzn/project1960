#!/usr/bin/env python3
import sqlite3
import subprocess
import sys

def test_enrichment():
    # First, let's check if the test case exists and has content
    conn = sqlite3.connect('doj_cases.db')
    c = conn.cursor()
    
    c.execute('SELECT id, title, body FROM cases WHERE id = "test-case-1"')
    result = c.fetchone()
    
    if not result:
        print("Test case not found!")
        return
    
    case_id, title, body = result
    print(f"Test case found: {case_id}")
    print(f"Title: {title}")
    print(f"Body preview: {body[:200]}...")
    conn.close()
    
    # Now run enrichment specifically on this case
    print("\nRunning enrichment on test case...")
    try:
        result = subprocess.run([
            sys.executable, 'enrich_cases.py', 
            '--table', 'case_metadata', 
            '--limit', '1', 
            '--verbose'
        ], capture_output=True, text=True, timeout=120)
        
        print("STDOUT:")
        print(result.stdout)
        print("\nSTDERR:")
        print(result.stderr)
        print(f"\nReturn code: {result.returncode}")
        
    except subprocess.TimeoutExpired:
        print("Enrichment timed out after 2 minutes")
    except Exception as e:
        print(f"Error running enrichment: {e}")

if __name__ == "__main__":
    test_enrichment() 