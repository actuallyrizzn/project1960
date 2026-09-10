#!/usr/bin/env python3
"""
Database schema migration script for enrichment tables.
Safely creates all required tables and adds missing columns without destroying existing data.
"""
import sqlite3
import logging
from utils.logging_config import setup_logging
from modules.enrichment.schemas import get_all_schemas

# Schema for the 'cases' table (from scraper.py)
CASES_TABLE_SCHEMA = '''
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
);
'''

def migrate_schemas():
    """Migrate existing enrichment tables to the new schema."""
    setup_logging()
    logger = logging.getLogger(__name__)
    
    conn = sqlite3.connect('doj_cases.db')
    cursor = conn.cursor()

    # 1. Create the 'cases' table if it does not exist
    logger.info("Ensuring 'cases' table exists...")
    cursor.execute(CASES_TABLE_SCHEMA)
    logger.info("'cases' table checked/created.")

    # 2. Create all enrichment tables and activity log if missing
    schemas = get_all_schemas()
    for table_name, create_stmt in schemas.items():
        logger.info(f"Ensuring '{table_name}' table exists...")
        cursor.execute(create_stmt)
        logger.info(f"'{table_name}' table checked/created.")

    # 3. Perform column migrations (legacy logic)
    migrations = [
        {
            'table': 'participants',
            'column': 'title',
            'type': 'TEXT',
            'description': 'Add missing title column to participants table'
        },
        {
            'table': 'charges', 
            'column': 'charge_description',
            'type': 'TEXT',
            'description': 'Add missing charge_description column to charges table'
        }
    ]
    
    logger.info("Starting column migration checks...")
    
    for migration in migrations:
        table = migration['table']
        column = migration['column']
        column_type = migration['type']
        description = migration['description']
        
        try:
            # Check if column already exists
            cursor.execute(f"PRAGMA table_info({table})")
            columns = [col[1] for col in cursor.fetchall()]
            
            if column not in columns:
                logger.info(f"Adding {column} column to {table} table...")
                cursor.execute(f"ALTER TABLE {table} ADD COLUMN {column} {column_type}")
                logger.info(f"✓ Successfully added {column} to {table}")
            else:
                logger.info(f"✓ Column {column} already exists in {table}")
                
        except Exception as e:
            logger.error(f"✗ Failed to add {column} to {table}: {e}")
            continue
    
    # Commit changes
    conn.commit()
    conn.close()
    
    logger.info("Schema migration completed.")

if __name__ == "__main__":
    migrate_schemas() 