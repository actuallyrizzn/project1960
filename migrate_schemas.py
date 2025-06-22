#!/usr/bin/env python3
"""
Database schema migration script for enrichment tables.
Safely adds missing columns without destroying existing data.
"""
import sqlite3
import logging
from utils.logging_config import setup_logging

def migrate_schemas():
    """Migrate existing enrichment tables to the new schema."""
    setup_logging()
    logger = logging.getLogger(__name__)
    
    conn = sqlite3.connect('doj_cases.db')
    cursor = conn.cursor()
    
    # Define the migrations needed
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
    
    logger.info("Starting schema migration...")
    
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