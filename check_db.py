#!/usr/bin/env python3
import sqlite3

def check_database():
    conn = sqlite3.connect('doj_cases.db')
    c = conn.cursor()
    
    # Check case_metadata table
    c.execute('SELECT COUNT(*) FROM case_metadata')
    metadata_count = c.fetchone()[0]
    print(f"Total enriched cases in case_metadata: {metadata_count}")
    
    # Check test case
    c.execute('SELECT id, title FROM cases WHERE id = "test-case-1"')
    test_case = c.fetchone()
    print(f"Test case exists: {test_case is not None}")
    if test_case:
        print(f"Test case: {test_case}")
    
    # Check recent activity log
    c.execute('SELECT * FROM enrichment_activity_log ORDER BY timestamp DESC LIMIT 3')
    recent_activity = c.fetchall()
    print(f"\nRecent enrichment activity:")
    for activity in recent_activity:
        print(f"  {activity[1]}: {activity[3]} - {activity[4]} - {activity[5]}")
    
    conn.close()

if __name__ == "__main__":
    check_database() 