#!/usr/bin/env python3
"""
Modularized enrichment script for DOJ cases.
Uses the new modular architecture.
"""
import argparse
import logging
import os
import sys
import fcntl
from utils.logging_config import setup_logging
from utils.config import Config
from orchestrators.enrichment_orchestrator import EnrichmentOrchestrator

def acquire_lock(lock_file_path):
    """Acquire a lock file to prevent multiple instances."""
    try:
        lock_file = open(lock_file_path, 'w')
        fcntl.flock(lock_file.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
        return lock_file
    except (IOError, OSError):
        print(f"Another instance is already running. Lock file: {lock_file_path}")
        sys.exit(1)

def release_lock(lock_file):
    """Release the lock file."""
    if lock_file:
        try:
            fcntl.flock(lock_file.fileno(), fcntl.LOCK_UN)
            lock_file.close()
            os.unlink(lock_file.name)
        except:
            pass

def main():
    """Main function for the enrichment script."""
    # Setup logging
    setup_logging()
    logger = logging.getLogger(__name__)
    
    # Parse command line arguments
    parser = argparse.ArgumentParser(description='Enrich DOJ cases with structured data')
    parser.add_argument('--table', choices=[
        'case_metadata', 'participants', 'case_agencies', 'charges', 
        'financial_actions', 'victims', 'quotes', 'themes'
    ], required=True, help='Table to enrich')
    parser.add_argument('--limit', type=int, default=100, help='Maximum number of cases to process')
    parser.add_argument('--all', action='store_true', help='Enrich all tables')
    parser.add_argument('--no-lock', action='store_true', help='Skip lock file (for testing)')
    
    args = parser.parse_args()
    
    # Lock file handling
    lock_file = None
    lock_file_path = 'enrichment.lock'
    
    if not args.no_lock:
        try:
            lock_file = acquire_lock(lock_file_path)
            logger.info(f"Acquired lock file: {lock_file_path}")
        except Exception as e:
            logger.error(f"Failed to acquire lock: {e}")
            return 1
    
    try:
        # Create orchestrator
        orchestrator = EnrichmentOrchestrator()
        
        if args.all:
            # Run enrichment for all tables
            logger.info("Running enrichment for all tables")
            result = orchestrator.run_all_enrichment(limit=args.limit)
            
            # Print summary
            print(f"\n=== ENRICHMENT SUMMARY ===")
            print(f"Total tables processed: {result['total_tables']}")
            print(f"Total cases processed: {result['total_cases']}")
            print(f"Successful: {result['total_successful']}")
            print(f"Failed: {result['total_failed']}")
            print(f"Overall success rate: {result['overall_success_rate']:.1f}%")
            
            print(f"\n=== TABLE RESULTS ===")
            for table_name, table_result in result['table_results'].items():
                print(f"{table_name}: {table_result['successful']}/{table_result['total_cases']} successful ({table_result['success_rate']:.1f}%)")
                
        else:
            # Run enrichment for specific table
            logger.info(f"Running enrichment for table: {args.table}")
            result = orchestrator.run_enrichment(args.table, limit=args.limit)
            
            # Print summary
            print(f"\n=== ENRICHMENT SUMMARY ===")
            print(f"Table: {result['table_name']}")
            print(f"Total cases: {result['total_cases']}")
            print(f"Successful: {result['successful']}")
            print(f"Failed: {result['failed']}")
            print(f"Success rate: {result['success_rate']:.1f}%")
        
        logger.info("Enrichment process completed successfully")
        
    except Exception as e:
        logger.error(f"Enrichment process failed: {e}")
        return 1
    finally:
        # Always release the lock
        if lock_file:
            release_lock(lock_file)
            logger.info("Released lock file")
    
    return 0

if __name__ == "__main__":
    exit(main()) 