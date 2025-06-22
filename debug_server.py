#!/usr/bin/env python3
"""
Debug script to check server database state
"""

import sqlite3
import os

def check_database():
    print("=== DATABASE DEBUG REPORT ===")
    
    # Check if database exists
    if not os.path.exists('doj_cases.db'):
        print("❌ doj_cases.db not found!")
        return
    
    print(f"✅ Database found: {os.path.getsize('doj_cases.db')} bytes")
    
    conn = sqlite3.connect('doj_cases.db')
    cursor = conn.cursor()
    
    # Check tables
    cursor.execute("SELECT name FROM sqlite_master WHERE type='table'")
    tables = cursor.fetchall()
    print(f"\n📋 Tables in database: {[table[0] for table in tables]}")
    
    # Check cases table
    cursor.execute("SELECT COUNT(*) FROM cases")
    total_cases = cursor.fetchone()[0]
    print(f"\n📊 Total cases: {total_cases}")
    
    # Check verified cases
    cursor.execute("SELECT COUNT(*) FROM cases WHERE verified_1960 = 1")
    verified_cases = cursor.fetchone()[0]
    print(f"✅ Verified cases (1960): {verified_cases}")
    
    # Check unverified cases
    cursor.execute("SELECT COUNT(*) FROM cases WHERE verified_1960 = 0")
    unverified_cases = cursor.fetchone()[0]
    print(f"❓ Unverified cases: {unverified_cases}")
    
    # Check null cases
    cursor.execute("SELECT COUNT(*) FROM cases WHERE verified_1960 IS NULL")
    null_cases = cursor.fetchone()[0]
    print(f"🔍 Null cases: {null_cases}")
    
    # Check enrichment tables
    enrichment_tables = ['case_metadata', 'participants', 'case_agencies', 'charges', 
                        'financial_actions', 'victims', 'quotes', 'themes']
    
    print(f"\n🔍 Enrichment table status:")
    for table in enrichment_tables:
        try:
            cursor.execute(f"SELECT COUNT(*) FROM {table}")
            count = cursor.fetchone()[0]
            print(f"  {table:20} {count:4d} records")
        except sqlite3.OperationalError:
            print(f"  {table:20} ❌ Table does not exist")
    
    # Test the exact query from enrich_cases.py
    print(f"\n🔍 Testing enrich_cases.py query logic:")
    
    # Test case_metadata query
    try:
        query = """
        SELECT c.id, c.title, c.body, c.url
        FROM cases c
        LEFT JOIN case_metadata et ON c.id = et.case_id
        WHERE c.verified_1960 = 1 AND et.case_id IS NULL
        LIMIT 5
        """
        cursor.execute(query)
        results = cursor.fetchall()
        print(f"  case_metadata query returns: {len(results)} cases")
        
        if results:
            print(f"  Sample case ID: {results[0][0][:8]}...")
        else:
            print(f"  ⚠️  No cases found for case_metadata enrichment")
            
    except Exception as e:
        print(f"  ❌ Query failed: {e}")
    
    # Check sample verified cases
    cursor.execute("SELECT id, title FROM cases WHERE verified_1960 = 1 LIMIT 3")
    sample_cases = cursor.fetchall()
    print(f"\n📝 Sample verified cases:")
    for case_id, title in sample_cases:
        print(f"  {case_id[:8]}... - {title[:50]}...")
    
    conn.close()

if __name__ == "__main__":
    check_database() 