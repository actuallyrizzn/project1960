#!/usr/bin/env python3
"""
Modularized enrichment script for DOJ cases.
Uses the new modular architecture.
"""
import argparse
import logging
from utils.logging_config import setup_logging
from utils.config import Config
from orchestrators.enrichment_orchestrator import EnrichmentOrchestrator

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
    
    args = parser.parse_args()
    
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
    
    return 0

if __name__ == "__main__":
    exit(main()) 