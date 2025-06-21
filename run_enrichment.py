#!/usr/bin/env python3
"""
Progressive Enrichment Runner for DOJ Cases

This script runs the enrichment process for all tables in the optimal order.
It's designed to be run via cron to continuously enrich new data as it arrives.

Usage:
    python run_enrichment.py [--dry-run] [--limit-per-table LIMIT] [--verbose]

The script processes tables in this order:
1. case_metadata (foundation data)
2. participants (people involved)
3. case_agencies (investigating agencies)
4. charges (legal statutes)
5. financial_actions (money flows)
6. victims (impact assessment)
7. quotes (narrative elements)
8. themes (categorization)
"""

import subprocess
import sys
import time
import logging
import argparse
import os
from datetime import datetime

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s',
    handlers=[
        logging.FileHandler('enrichment.log'),
        logging.StreamHandler(sys.stdout)
    ]
)
logger = logging.getLogger(__name__)

# Define the enrichment order and batch sizes
ENRICHMENT_ORDER = [
    ('case_metadata', 20),      # Foundation data - larger batches
    ('participants', 15),       # People involved
    ('case_agencies', 15),      # Investigating agencies
    ('charges', 15),            # Legal statutes
    ('financial_actions', 15),  # Money flows
    ('victims', 10),           # Impact assessment
    ('quotes', 10),            # Narrative elements
    ('themes', 10)             # Categorization
]

def run_enrichment_pass(table_name, limit, dry_run=False, verbose=False):
    """Run enrichment for a specific table."""
    logger.info(f"Starting enrichment pass for table: {table_name}")
    
    cmd = ['python', 'enrich_cases.py', '--table', table_name, '--limit', str(limit)]
    
    if dry_run:
        cmd.append('--dry-run')
    if verbose:
        cmd.append('--verbose')
    
    logger.info(f"Running command: {' '.join(cmd)}")
    
    try:
        start_time = time.time()
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=3600)  # 1 hour timeout
        end_time = time.time()
        
        if result.returncode == 0:
            logger.info(f"✅ Successfully completed {table_name} enrichment in {end_time - start_time:.1f}s")
            if result.stdout:
                logger.debug(f"Output: {result.stdout}")
            return True
        else:
            logger.error(f"❌ Failed to enrich {table_name}")
            logger.error(f"Error output: {result.stderr}")
            return False
            
    except subprocess.TimeoutExpired:
        logger.error(f"⏰ Timeout while enriching {table_name}")
        return False
    except Exception as e:
        logger.error(f"💥 Unexpected error enriching {table_name}: {e}")
        return False

def get_database_stats():
    """Get current database statistics."""
    try:
        import sqlite3
        conn = sqlite3.connect('doj_cases.db')
        cursor = conn.cursor()
        
        # Get total cases and verified cases
        cursor.execute("SELECT COUNT(*) FROM cases")
        total_cases = cursor.fetchone()[0]
        
        cursor.execute("SELECT COUNT(*) FROM cases WHERE verified_1960 = 1")
        verified_cases = cursor.fetchone()[0]
        
        # Get enrichment progress for each table
        enrichment_stats = {}
        for table_name, _ in ENRICHMENT_ORDER:
            try:
                cursor.execute(f"SELECT COUNT(*) FROM {table_name}")
                enriched_count = cursor.fetchone()[0]
                enrichment_stats[table_name] = enriched_count
            except sqlite3.OperationalError:
                enrichment_stats[table_name] = 0
        
        conn.close()
        
        return {
            'total_cases': total_cases,
            'verified_cases': verified_cases,
            'enrichment_stats': enrichment_stats
        }
    except Exception as e:
        logger.error(f"Failed to get database stats: {e}")
        return None

def print_progress_report(stats):
    """Print a progress report."""
    if not stats:
        return
    
    logger.info("=" * 60)
    logger.info("📊 ENRICHMENT PROGRESS REPORT")
    logger.info("=" * 60)
    logger.info(f"Total cases in database: {stats['total_cases']}")
    logger.info(f"Verified cases (1960): {stats['verified_cases']}")
    logger.info(f"Enrichment progress:")
    
    for table_name, enriched_count in stats['enrichment_stats'].items():
        if stats['verified_cases'] > 0:
            percentage = (enriched_count / stats['verified_cases']) * 100
            logger.info(f"  {table_name:20} {enriched_count:4d}/{stats['verified_cases']:4d} ({percentage:5.1f}%)")
        else:
            logger.info(f"  {table_name:20} {enriched_count:4d}/0 (N/A)")

def main():
    parser = argparse.ArgumentParser(description="Run progressive enrichment for all DOJ case tables")
    parser.add_argument(
        '--dry-run',
        action='store_true',
        help="Run in test mode without making actual changes"
    )
    parser.add_argument(
        '--limit-per-table',
        type=int,
        default=None,
        help="Override the default limit for each table"
    )
    parser.add_argument(
        '--verbose',
        action='store_true',
        help="Enable verbose logging"
    )
    parser.add_argument(
        '--tables',
        nargs='+',
        choices=[table for table, _ in ENRICHMENT_ORDER],
        help="Only process specific tables"
    )
    
    args = parser.parse_args()
    
    if args.verbose:
        logger.setLevel(logging.DEBUG)
    
    # Print startup information
    logger.info("🚀 Starting progressive enrichment process")
    logger.info(f"Dry run mode: {args.dry_run}")
    logger.info(f"Tables to process: {args.tables if args.tables else 'ALL'}")
    
    # Get initial stats
    initial_stats = get_database_stats()
    if initial_stats:
        print_progress_report(initial_stats)
    
    # Determine which tables to process
    tables_to_process = ENRICHMENT_ORDER
    if args.tables:
        tables_to_process = [(table, limit) for table, limit in ENRICHMENT_ORDER if table in args.tables]
    
    # Run enrichment passes
    successful_passes = 0
    total_passes = len(tables_to_process)
    
    for i, (table_name, default_limit) in enumerate(tables_to_process, 1):
        logger.info(f"\n{'='*50}")
        logger.info(f"Pass {i}/{total_passes}: {table_name}")
        logger.info(f"{'='*50}")
        
        # Use override limit if provided, otherwise use default
        limit = args.limit_per_table if args.limit_per_table else default_limit
        
        success = run_enrichment_pass(table_name, limit, args.dry_run, args.verbose)
        
        if success:
            successful_passes += 1
        
        # Brief pause between passes to be respectful to the API
        if i < total_passes:
            logger.info("Pausing 5 seconds before next pass...")
            time.sleep(5)
    
    # Final progress report
    logger.info(f"\n{'='*60}")
    logger.info("🎯 ENRICHMENT PROCESS COMPLETE")
    logger.info(f"{'='*60}")
    logger.info(f"Successful passes: {successful_passes}/{total_passes}")
    
    final_stats = get_database_stats()
    if final_stats:
        print_progress_report(final_stats)
    
    # Exit with appropriate code
    if successful_passes == total_passes:
        logger.info("✅ All enrichment passes completed successfully!")
        sys.exit(0)
    else:
        logger.warning(f"⚠️  {total_passes - successful_passes} passes failed")
        sys.exit(1)

if __name__ == "__main__":
    main() 