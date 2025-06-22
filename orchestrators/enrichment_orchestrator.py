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
        # Use a subquery to get the most recent status for each case
        query = """
            SELECT c.id, c.title, c.body, c.url
            FROM cases c
            LEFT JOIN (
                SELECT case_id, table_name, status, timestamp,
                       ROW_NUMBER() OVER (PARTITION BY case_id, table_name ORDER BY timestamp DESC) as rn
                FROM enrichment_activity_log
                WHERE table_name = ?
            ) latest_status ON c.id = latest_status.case_id AND latest_status.rn = 1
            WHERE latest_status.status IS NULL OR latest_status.status != 'success'
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
    
    def enrich_case(self, case_id: str, title: str, body: str, url: str, table_name: str, dry_run: bool = False) -> bool:
        """
        Enrich a single case for a specific table.
        
        Args:
            case_id: The case ID
            title: The case title
            body: The case body
            url: The case URL
            table_name: The table to enrich
            dry_run: If True, simulate the enrichment without making API calls
            
        Returns:
            True if successful, False otherwise
        """
        logger.info(f"Processing case {case_id}...")
        
        try:
            if dry_run:
                # Simulate enrichment in dry-run mode
                logger.info(f"DRY RUN: Would enrich case {case_id} for table {table_name}")
                logger.info(f"DRY RUN: Would extract data from title: {title[:100]}...")
                logger.info(f"DRY RUN: Would extract data from body: {body[:200]}...")
                
                # Create a mock response for dry-run
                mock_data = self._create_mock_data(table_name, title, body)
                if mock_data:
                    logger.info(f"DRY RUN: Would store mock data: {mock_data}")
                    if not dry_run:  # Only store if not dry-run
                        store_extracted_data(case_id, table_name, mock_data, url)
                    return True
                else:
                    logger.warning(f"DRY RUN: Failed to create mock data for case {case_id}")
                    return False
            
            # Get the extraction prompt for this table
            prompt = get_extraction_prompt(table_name, title, body)
            
            # Make API call with increased token limit to ensure complete JSON output
            response_data = self.api_client.call_api(prompt, max_tokens=4000, temperature=0.1)
            
            if not response_data:
                logger.warning(f"Failed to get API response for case {case_id}")
                if not dry_run:
                    store_extracted_data(case_id, table_name, None, url)
                return False
            
            # Extract content from the API response
            response = self.api_client.extract_content(response_data)
            if not response:
                logger.warning(f"Failed to extract content from API response for case {case_id}")
                if not dry_run:
                    store_extracted_data(case_id, table_name, None, url)
                return False
            
            # Parse the JSON response
            parsed_data = clean_and_parse_json(response)
            
            if not parsed_data:
                logger.warning(f"Failed to parse data for case {case_id}")
                if not dry_run:
                    store_extracted_data(case_id, table_name, None, url)
                return False
            
            # Store the parsed data
            if not dry_run:
                store_extracted_data(case_id, table_name, parsed_data, url)
            
            return True
            
        except Exception as e:
            logger.error(f"Error enriching case {case_id} for table {table_name}: {e}")
            if not dry_run:
                store_extracted_data(case_id, table_name, None, url)
            return False
    
    def _create_mock_data(self, table_name: str, title: str, body: str) -> Optional[Any]:
        """Create mock data for dry-run mode."""
        if table_name == 'case_metadata':
            return {
                'district_office': 'Mock District Office',
                'usa_name': 'Mock USA Name',
                'event_type': 'Mock Event Type',
                'judge_name': 'Mock Judge Name',
                'judge_title': 'Mock Judge Title',
                'case_number': 'Mock Case Number',
                'max_penalty_text': 'Mock Penalty Text',
                'sentence_summary': 'Mock Sentence Summary',
                'money_amounts': 'Mock Money Amounts',
                'crypto_assets': 'Mock Crypto Assets',
                'statutes_json': '{"statutes": ["mock statute"]}',
                'timeline_json': '{"timeline": ["mock timeline"]}',
                'extras_json': '{"extras": "mock extras"}'
            }
        elif table_name in ['participants', 'case_agencies', 'charges', 'financial_actions', 'victims', 'quotes', 'themes']:
            return [
                {
                    'name': 'Mock Name',
                    'role': 'Mock Role',
                    'title': 'Mock Title',
                    'organization': 'Mock Organization',
                    'location': 'Mock Location',
                    'age': 'Mock Age',
                    'nationality': 'Mock Nationality',
                    'status': 'Mock Status'
                }
            ]
        return None
    
    def run_enrichment(self, table_name: str, limit: int = 100, dry_run: bool = False) -> Dict[str, Any]:
        """
        Run enrichment for a specific table.
        
        Args:
            table_name: The table to enrich
            limit: Maximum number of cases to process
            dry_run: If True, simulate the enrichment without making API calls
            
        Returns:
            Dictionary with results summary
        """
        logger.info(f"Starting enrichment process for table: '{table_name}'")
        if dry_run:
            logger.info("DRY RUN MODE: No actual API calls or database changes will be made")
        
        # Setup tables if needed
        if not dry_run:
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
                'success_rate': 0.0,
                'dry_run': dry_run
            }
        
        # Process cases
        successful = 0
        failed = 0
        
        for case_id, title, body, url in cases:
            if self.enrich_case(case_id, title, body, url, table_name, dry_run=dry_run):
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
            'success_rate': success_rate,
            'dry_run': dry_run
        }
    
    def run_all_enrichment(self, limit: int = 100, dry_run: bool = False) -> Dict[str, Any]:
        """
        Run enrichment for all tables sequentially.
        
        Args:
            limit: Maximum number of cases to process per table
            dry_run: If True, simulate the enrichment without making API calls
            
        Returns:
            Dictionary with a comprehensive results summary for all tables.
        """
        all_tables = list(get_all_schemas().keys())
        # We don't enrich the activity log itself
        if 'enrichment_activity_log' in all_tables:
            all_tables.remove('enrichment_activity_log')
            
        logger.info(f"Starting enrichment for all tables: {all_tables}")

        overall_results = {
            'total_tables': 0,
            'total_cases': 0,
            'total_successful': 0,
            'total_failed': 0,
            'table_results': {},
            'dry_run': dry_run
        }

        for table_name in all_tables:
            logger.info(f"--- Processing table: {table_name} ---")
            result = self.run_enrichment(table_name, limit=limit, dry_run=dry_run)
            
            overall_results['table_results'][table_name] = result
            overall_results['total_tables'] += 1
            overall_results['total_cases'] += result['total_cases']
            overall_results['total_successful'] += result['successful']
            overall_results['total_failed'] += result['failed']

        total_processed = overall_results['total_successful'] + overall_results['total_failed']
        overall_results['overall_success_rate'] = (overall_results['total_successful'] / total_processed * 100) if total_processed > 0 else 0
        
        logger.info("--- Completed enrichment for all tables ---")
        return overall_results 