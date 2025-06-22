#!/usr/bin/env python3
"""
Modularized 1960 verification script for DOJ cases.
Uses the new modular architecture.
"""
import argparse
import logging
from utils.logging_config import setup_logging
from utils.config import Config
from orchestrators.verification_orchestrator import VerificationOrchestrator

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
    
    args = parser.parse_args()
    
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
    
    return 0

if __name__ == "__main__":
    exit(main()) 