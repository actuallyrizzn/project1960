"""
Enrichment process orchestration for DOJ cases.
"""
import logging
from typing import List, Optional, Dict, Any
from utils.database import DatabaseManager
from utils.api_client import VeniceAPIClient
from utils.json_parser import clean_and_parse_json
from utils.logging_config import get_logger
from modules.enrichment.schemas import get_all_schemas
from modules.enrichment.prompts import get_extraction_prompt
from modules.enrichment.storage import store_extracted_data

logger = get_logger(__name__)

class EnrichmentOrchestrator:
    """Orchestrates the enrichment process for DOJ cases."""
    
    def __init__(self):
        """Initialize the enrichment orchestrator."""
        self.db_manager = DatabaseManager()
        self.api_client = VeniceAPIClient()
        
    def setup_enrichment_tables(self) -> None:
        """Set up all enrichment tables in the database."""
        logger.info("Setting up enrichment tables in the database...")
        schemas = get_all_schemas()
        self.db_manager.create_tables(schemas)
        
    def get_cases_for_enrichment(self, table_name: str, limit: int = 100) -> List[tuple]:
        """
        Get cases that need enrichment for a specific table.
        
        Args:
            table_name: The table to enrich
            limit: Maximum number of cases to process
            
        Returns:
            List of (case_id, title, body, url) tuples
        """
        # Check if enrichment activity log exists
        if not self.db_manager.table_exists('enrichment_activity_log'):
            # Create the log table if it doesn't exist
            log_schema = get_all_schemas()['enrichment_activity_log']
            self.db_manager.execute_query(log_schema)
        
        # Get cases that haven't been successfully enriched for this table
        query = """
            SELECT c.id, c.title, c.body, c.url
            FROM cases c
            LEFT JOIN enrichment_activity_log eal ON c.id = eal.case_id AND eal.table_name = ?
            WHERE eal.status IS NULL OR eal.status != 'success'
            ORDER BY c.created DESC
            LIMIT ?
        """
        
        try:
            results = self.db_manager.execute_query(query, (table_name, limit))
            logger.info(f"Found {len(results)} cases to process for '{table_name}'.")
            return results
        except Exception as e:
            logger.error(f"Failed to get cases for enrichment: {e}")
            return []
    
    def enrich_case(self, case_id: str, title: str, body: str, url: str, table_name: str) -> bool:
        """
        Enrich a single case for a specific table.
        
        Args:
            case_id: The case ID
            title: The case title
            body: The case body
            url: The case URL
            table_name: The table to enrich
            
        Returns:
            True if successful, False otherwise
        """
        logger.info(f"Processing case {case_id}...")
        
        try:
            # Get the extraction prompt
            prompt = get_extraction_prompt(table_name, title, body)
            
            # Make API call
            logger.debug(f"Sending extraction request for case {case_id}, table {table_name}")
            response_data = self.api_client.call_api(prompt)
            
            if not response_data:
                logger.error(f"API call failed for case {case_id}")
                store_extracted_data(case_id, table_name, None, url)
                return False
            
            # Extract content from response
            content = self.api_client.extract_content(response_data)
            if not content:
                logger.error(f"Failed to extract content from API response for case {case_id}")
                store_extracted_data(case_id, table_name, None, url)
                return False
            
            # Parse JSON from content
            parsed_data = clean_and_parse_json(content)
            if not parsed_data:
                logger.warning(f"Failed to parse data for case {case_id}.")
                store_extracted_data(case_id, table_name, None, url)
                return False
            
            # Store the extracted data
            success = store_extracted_data(case_id, table_name, parsed_data, url)
            return success
            
        except Exception as e:
            logger.error(f"Error enriching case {case_id} for table {table_name}: {e}")
            store_extracted_data(case_id, table_name, None, url)
            return False
    
    def run_enrichment(self, table_name: str, limit: int = 100) -> Dict[str, Any]:
        """
        Run enrichment for a specific table.
        
        Args:
            table_name: The table to enrich
            limit: Maximum number of cases to process
            
        Returns:
            Dictionary with results summary
        """
        logger.info(f"Starting enrichment process for table: '{table_name}'")
        
        # Setup tables if needed
        self.setup_enrichment_tables()
        
        # Get cases to process
        cases = self.get_cases_for_enrichment(table_name, limit)
        
        if not cases:
            logger.info("No cases found for enrichment.")
            return {
                'table_name': table_name,
                'total_cases': 0,
                'successful': 0,
                'failed': 0,
                'success_rate': 0.0
            }
        
        # Process cases
        successful = 0
        failed = 0
        
        for case_id, title, body, url in cases:
            if self.enrich_case(case_id, title, body, url, table_name):
                successful += 1
            else:
                failed += 1
        
        total = successful + failed
        success_rate = (successful / total * 100) if total > 0 else 0
        
        logger.info(f"Enrichment run complete.")
        logger.info(f"Results: {successful} successful, {failed} failed ({success_rate:.1f}% success rate)")
        
        return {
            'table_name': table_name,
            'total_cases': total,
            'successful': successful,
            'failed': failed,
            'success_rate': success_rate
        }
    
    def run_all_enrichment(self, limit: int = 100) -> Dict[str, Any]:
        """
        Run enrichment for all tables.
        
        Args:
            limit: Maximum number of cases to process per table
            
        Returns:
            Dictionary with results summary for all tables
        """
        logger.info("Starting enrichment process for all tables")
        
        # Setup tables
        self.setup_enrichment_tables()
        
        # Get all table names (excluding the log table)
        table_names = [name for name in get_all_schemas().keys() if name != 'enrichment_activity_log']
        
        results = {}
        total_successful = 0
        total_failed = 0
        total_cases = 0
        
        for table_name in table_names:
            logger.info(f"Processing table: {table_name}")
            result = self.run_enrichment(table_name, limit)
            results[table_name] = result
            
            total_successful += result['successful']
            total_failed += result['failed']
            total_cases += result['total_cases']
        
        overall_success_rate = (total_successful / total_cases * 100) if total_cases > 0 else 0
        
        overall_result = {
            'total_tables': len(table_names),
            'total_cases': total_cases,
            'total_successful': total_successful,
            'total_failed': total_failed,
            'overall_success_rate': overall_success_rate,
            'table_results': results
        }
        
        logger.info(f"All enrichment complete. Overall success rate: {overall_success_rate:.1f}%")
        
        return overall_result 