#!/usr/bin/env python3
"""
Modularized 1960 verification script for Project1960.
Uses the new modular architecture.
"""
import argparse
import logging
import os
import sys
import time
from utils.logging_config import setup_logging
from utils.config import Config
from orchestrators.verification_orchestrator import VerificationOrchestrator

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
    """Main function for the verification script."""
    # Setup logging
    setup_logging()
    logger = logging.getLogger(__name__)
    
    # Parse command line arguments
    parser = argparse.ArgumentParser(description='Verify 1960 cases in DOJ database')
    parser.add_argument('--limit', type=int, default=100, help='Maximum number of cases to process')
    parser.add_argument('--dry-run', action='store_true', help='Run in dry-run mode (no API calls)')
    parser.add_argument('--stats', action='store_true', help='Show verification statistics only')
    parser.add_argument('--no-lock', action='store_true', help='Skip lock file (for testing)')
    
    args = parser.parse_args()
    
    # Lock file handling
    lock_file_path = 'verification.lock'
    
    if not args.no_lock:
        try:
            lock_file_path = acquire_lock(lock_file_path)
            logger.info(f"Acquired lock file: {lock_file_path}")
        except Exception as e:
            logger.error(f"Failed to acquire lock: {e}")
            return 1
    
    try:
        # Create orchestrator
        orchestrator = VerificationOrchestrator()
        
        if args.stats:
            # Show statistics only
            stats = orchestrator.get_verification_stats()
            
            print(f"\n=== VERIFICATION STATISTICS ===")
            print(f"Total 1960 cases: {stats.get('total_1960_cases', 0)}")
            print(f"Classified cases: {stats.get('classified_cases', 0)}")
            print(f"Unclassified cases: {stats.get('unclassified_cases', 0)}")
            print(f"Classification rate: {stats.get('classification_rate', 0):.1f}%")
            print(f"\nClassification breakdown:")
            print(f"  Yes: {stats.get('yes_cases', 0)}")
            print(f"  No: {stats.get('no_cases', 0)}")
            print(f"  Unknown: {stats.get('unknown_cases', 0)}")
            
        else:
            # Run verification process
            result = orchestrator.run_verification(limit=args.limit, dry_run=args.dry_run)
            
            # Print summary
            print(f"\n=== VERIFICATION SUMMARY ===")
            print(f"Total cases processed: {result['total_cases']}")
            print(f"Successful: {result['successful']}")
            print(f"Failed: {result['failed']}")
            print(f"Success rate: {result['success_rate']:.1f}%")
            print(f"\nClassification results:")
            print(f"  Yes: {result['yes_count']}")
            print(f"  No: {result['no_count']}")
            print(f"  Unknown: {result['unknown_count']}")
            
            if result['dry_run']:
                print(f"\nNote: This was a dry run - no actual changes were made")
        
        logger.info("Verification process completed successfully")
        
    except Exception as e:
        logger.error(f"Verification process failed: {e}")
        return 1
    finally:
        # Always release the lock
        if not args.no_lock:
            release_lock(lock_file_path)
            logger.info("Released lock file")
    
    return 0

if __name__ == "__main__":
    exit(main()) 