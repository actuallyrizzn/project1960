#!/usr/bin/env python3
import sqlite3
import argparse
import pandas as pd
from orchestrators.enrichment_orchestrator import EnrichmentOrchestrator

def check_database(query=None, rebuild=False):
    if rebuild:
        print("Rebuilding all enrichment tables...")
        try:
            orchestrator = EnrichmentOrchestrator()
            db_manager = orchestrator.db_manager
            
            # Get all enrichment table names
            all_schemas = orchestrator.get_all_schemas()
            table_names = list(all_schemas.keys())
            
            # Drop each table
            for table_name in table_names:
                print(f"Dropping table: {table_name}")
                db_manager.execute_query(f"DROP TABLE IF EXISTS {table_name};")
            
            # Recreate tables
            print("Re-running schema setup...")
            orchestrator.setup_enrichment_tables()
            print("Database rebuild complete.")
        except Exception as e:
            print(f"Error during rebuild: {e}")
        return

    conn = sqlite3.connect('doj_cases.db')
    
    if query:
        print(f"Executing query: {query}")
        try:
            # Use pandas to execute query and display results in a nice table
            df = pd.read_sql_query(query, conn)
            print(df.to_string())
        except Exception as e:
            print(f"Error executing query: {e}")
        finally:
            conn.close()
        return

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
    parser = argparse.ArgumentParser(description='Check the status of the DOJ cases database.')
    parser.add_argument('--query', type=str, help='Execute a raw SQL query against the database.')
    parser.add_argument('--rebuild', action='store_true', help='Drop and rebuild all enrichment tables.')
    args = parser.parse_args()

    if args.rebuild and args.query:
        parser.error("Cannot use --rebuild and --query at the same time.")
    
    check_database(query=args.query, rebuild=args.rebuild) 