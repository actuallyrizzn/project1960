"""
Enrichment process orchestration for Project1960.
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
    """Orchestrates the enrichment process for Project1960."""
    
    def __init__(self):
        """Initialize the enrichment orchestrator."""
        self.db_manager = DatabaseManager()
        self.api_client = VeniceAPIClient()
        
    def get_all_schemas(self) -> Dict[str, str]:
        """Get a copy of all schema definitions."""
        return get_all_schemas()

    def setup_enrichment_tables(self) -> None:
        """Set up all enrichment tables in the database."""
        logger.info("Setting up enrichment tables in the database...")
        schemas = get_all_schemas()
        self.db_manager.create_tables(schemas)
        
    def get_cases_for_enrichment(self, table_name: str, limit: int = 100, verified_1960_only: bool = False) -> List[tuple]:
        """
        Get cases that need enrichment for a specific table.
        Optionally filter for 1960-verified cases only.
        """
        if not self.db_manager.table_exists('enrichment_activity_log'):
            log_schema = self.get_all_schemas()['enrichment_activity_log']
            self.db_manager.execute_query(log_schema)

        base_query = """
            SELECT c.id, c.title, c.body, c.url
            FROM cases c
            LEFT JOIN (
                SELECT case_id, table_name, status, timestamp,
                       ROW_NUMBER() OVER (PARTITION BY case_id, table_name ORDER BY timestamp DESC) as rn
                FROM enrichment_activity_log
                WHERE table_name = ?
            ) latest_status ON c.id = latest_status.case_id AND latest_status.rn = 1
            WHERE (latest_status.status IS NULL OR latest_status.status != 'success')
        """
        params = [table_name]
        if verified_1960_only:
            base_query += " AND c.classification = 'yes' "
        base_query += " ORDER BY c.created DESC LIMIT ?"
        params.append(limit)
        try:
            results = self.db_manager.execute_query(base_query, tuple(params))
            logger.info(f"Found {len(results)} cases to process for '{table_name}'.")
            return results
        except Exception as e:
            logger.error(f"Failed to get cases for enrichment: {e}")
            return []
    
    def get_case_by_id(self, case_number: str) -> List[tuple]:
        """Get a single case by its case number."""
        query = "SELECT id, title, body, url FROM cases WHERE number = ?"
        try:
            result = self.db_manager.execute_query(query, (case_number,))
            if result:
                logger.info(f"Found case {case_number} for targeted enrichment.")
                return result
            else:
                logger.warning(f"Case with number {case_number} not found.")
                return []
        except Exception as e:
            logger.error(f"Failed to get case by number {case_number}: {e}")
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
    
    def _has_1960_verified_cases_to_enrich(self, table_name: str) -> bool:
        """
        Return True if there are any 1960-verified cases that do not have a row in the enrichment table.
        """
        # Assume enrichment tables have a 'case_id' column
        query = f'''
            SELECT 1 FROM cases c
            LEFT JOIN {table_name} t ON c.id = t.case_id
            WHERE c.classification = 'yes' AND t.case_id IS NULL
            LIMIT 1
        '''
        try:
            result = self.db_manager.execute_query(query)
            return bool(result)
        except Exception as e:
            logger.error(f"Failed to check for 1960-verified cases to enrich in {table_name}: {e}")
            return False

    def run_enrichment(self, table_name: str, limit: int = 100, dry_run: bool = False, case_number: Optional[str] = None, verified_1960_only: bool = False) -> Dict[str, Any]:
        """
        Run enrichment for a specific table.
        Optionally filter for 1960-verified cases only.
        """
        logger.info(f"Starting enrichment process for table: '{table_name}'")
        if dry_run:
            logger.info("DRY RUN MODE: No actual API calls or database changes will be made")
        if not dry_run:
            self.setup_enrichment_tables()
        if case_number:
            cases = self.get_case_by_id(case_number)
        else:
            cases = self.get_cases_for_enrichment(table_name, limit, verified_1960_only=verified_1960_only)
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
        Run enrichment for all tables sequentially, prioritizing 1960-verified cases. If none remain, process all cases.
        """
        all_tables = list(get_all_schemas().keys())
        if 'enrichment_activity_log' in all_tables:
            all_tables.remove('enrichment_activity_log')
        logger.info(f"Starting enrichment for all tables: {all_tables}")
        # Check if any 1960-verified cases remain to enrich for any table (by direct join)
        any_1960_left = False
        for table_name in all_tables:
            if self._has_1960_verified_cases_to_enrich(table_name):
                any_1960_left = True
                break
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
            result = self.run_enrichment(
                table_name,
                limit=limit,
                dry_run=dry_run,
                verified_1960_only=any_1960_left
            )
            overall_results['table_results'][table_name] = result
            overall_results['total_tables'] += 1
            overall_results['total_cases'] += result['total_cases']
            overall_results['total_successful'] += result['successful']
            overall_results['total_failed'] += result['failed']
        total_processed = overall_results['total_successful'] + overall_results['total_failed']
        overall_results['overall_success_rate'] = (overall_results['total_successful'] / total_processed * 100) if total_processed > 0 else 0
        logger.info("--- Completed enrichment for all tables ---")
        return overall_results 