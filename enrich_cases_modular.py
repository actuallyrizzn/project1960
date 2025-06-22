#!/usr/bin/env python3
"""
Modularized enrichment script for DOJ cases.
Uses the new modular architecture.
"""
import argparse
import logging
import os
import sys
import time
from utils.logging_config import setup_logging
from utils.config import Config
from orchestrators.enrichment_orchestrator import EnrichmentOrchestrator

def acquire_lock(lock_file_path):
    """Acquire a lock file to prevent multiple instances (cross-platform)."""
    try:
        # Try to create the lock file
        with open(lock_file_path, 'w') as f:
            # Write the current process ID
            f.write(str(os.getpid()))
        
        # On Unix systems, try to use fcntl if available
        try:
            import fcntl
            with open(lock_file_path, 'r+') as f:
                fcntl.flock(f.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
                return lock_file_path
        except (ImportError, IOError, OSError):
            # On Windows or if fcntl fails, use simple file existence check
            # Check if another process is using the lock file
            time.sleep(0.1)  # Small delay to avoid race conditions
            with open(lock_file_path, 'r') as f:
                pid = f.read().strip()
                if pid and pid != str(os.getpid()):
                    # Check if the process is still running
                    try:
                        os.kill(int(pid), 0)  # Signal 0 just checks if process exists
                        print(f"Another instance (PID {pid}) is already running. Lock file: {lock_file_path}")
                        sys.exit(1)
                    except (OSError, ValueError):
                        # Process doesn't exist, remove stale lock file
                        pass
            return lock_file_path
            
    except Exception as e:
        print(f"Failed to acquire lock: {e}")
        sys.exit(1)

def release_lock(lock_file_path):
    """Release the lock file."""
    try:
        if os.path.exists(lock_file_path):
            os.unlink(lock_file_path)
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
    ], required=False, help='Table to enrich. Required if --all is not specified.')
    parser.add_argument('--limit', type=int, default=100, help='Maximum number of cases to process per table')
    parser.add_argument('--all', action='store_true', help='Enrich all tables sequentially.')
    parser.add_argument('--no-lock', action='store_true', help='Skip lock file (for testing)')
    parser.add_argument('--dry-run', action='store_true', help='Run in dry-run mode (no API calls)')
    
    args = parser.parse_args()

    # Validate arguments
    if args.all and args.table:
        parser.error("Argument --table cannot be used with --all.")
    if not args.all and not args.table:
        parser.error("Argument --table is required when --all is not specified.")
    
    # Lock file handling
    lock_file_path = 'enrichment.lock'
    
    if not args.no_lock:
        try:
            lock_file_path = acquire_lock(lock_file_path)
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
            result = orchestrator.run_all_enrichment(limit=args.limit, dry_run=args.dry_run)
            
            # Print summary
            print(f"\n=== ENRICHMENT SUMMARY ===")
            if result.get('dry_run'):
                print("DRY RUN MODE - No actual changes made")
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
            result = orchestrator.run_enrichment(args.table, limit=args.limit, dry_run=args.dry_run)
            
            # Print summary
            print(f"\n=== ENRICHMENT SUMMARY ===")
            if result.get('dry_run'):
                print("DRY RUN MODE - No actual changes made")
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
        if not args.no_lock:
            release_lock(lock_file_path)
            logger.info("Released lock file")
    
    return 0

if __name__ == "__main__":
    exit(main()) 