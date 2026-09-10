"""
Verification process orchestration for Project1960.
"""
import logging
from typing import List, Optional, Dict, Any
from utils.database import DatabaseManager
from utils.logging_config import get_logger
from modules.verification.classifier import classify_case, store_classification

logger = get_logger(__name__)

class VerificationOrchestrator:
    """Orchestrates the verification process for Project1960."""
    
    def __init__(self):
        """Initialize the verification orchestrator."""
        self.db_manager = DatabaseManager()
    
    def get_sample_cases(self, limit: int = 100) -> List[tuple]:
        """
        Get sample cases for verification.
        
        Args:
            limit: Maximum number of cases to process
            
        Returns:
            List of (case_id, title, body) tuples
        """
        query = """
            SELECT id, title, body
            FROM cases
            WHERE mentions_1960 = 1
              AND (classification IS NULL OR classification = '' OR classification = 'unknown')
            ORDER BY
              CASE
                WHEN classification IS NULL THEN 0
                WHEN classification = '' THEN 1
                WHEN classification = 'unknown' THEN 2
                ELSE 3
              END
            LIMIT ?
        """
        
        try:
            results = self.db_manager.execute_query(query, (limit,))
            logger.info(f"Found {len(results)} cases to process")
            return results
        except Exception as e:
            logger.error(f"Failed to get sample cases: {e}")
            return []
    
    def verify_case(self, case_id: str, title: str, body: str, dry_run: bool = False) -> Optional[str]:
        """
        Verify a single case.
        
        Args:
            case_id: The case ID
            title: The case title
            body: The case body
            dry_run: If True, simulate the verification without making API calls
            
        Returns:
            Classification result: 'yes', 'no', or 'unknown'
        """
        try:
            # Classify the case
            classification = classify_case(case_id, title, body, dry_run=dry_run)
            
            if classification:
                # Store the classification
                store_classification(case_id, classification, dry_run=dry_run)
                logger.info(f"Classification result for case {case_id}: {classification}")
            
            return classification
            
        except Exception as e:
            logger.error(f"Error verifying case {case_id}: {e}")
            return None
    
    def run_verification(self, limit: int = 100, dry_run: bool = False) -> Dict[str, Any]:
        """
        Run verification process.
        
        Args:
            limit: Maximum number of cases to process
            dry_run: If True, simulate the verification without making API calls
            
        Returns:
            Dictionary with results summary
        """
        if dry_run:
            logger.info("Starting DRY RUN mode - no actual API calls will be made")
        else:
            logger.info("Starting REAL processing mode - will make actual API calls")
        
        logger.info(f"Processing limit: {limit} cases")
        
        # Get cases to process
        cases = self.get_sample_cases(limit)
        
        if not cases:
            logger.info("No cases found for verification.")
            return {
                'total_cases': 0,
                'successful': 0,
                'failed': 0,
                'yes_count': 0,
                'no_count': 0,
                'unknown_count': 0,
                'success_rate': 0.0
            }
        
        # Process cases
        successful = 0
        failed = 0
        yes_count = 0
        no_count = 0
        unknown_count = 0
        
        for case_id, title, body in cases:
            try:
                classification = self.verify_case(case_id, title, body, dry_run=dry_run)
                
                if classification:
                    successful += 1
                    if classification == 'yes':
                        yes_count += 1
                    elif classification == 'no':
                        no_count += 1
                    else:
                        unknown_count += 1
                else:
                    failed += 1
                    
            except Exception as e:
                logger.error(f"Failed to process case {case_id}: {e}")
                failed += 1
        
        total = successful + failed
        success_rate = (successful / total * 100) if total > 0 else 0
        
        if dry_run:
            logger.info("DRY RUN completed - no actual changes were made")
        else:
            logger.info(f"Verification complete. Results: {successful} successful, {failed} failed ({success_rate:.1f}% success rate)")
            logger.info(f"Classifications: {yes_count} yes, {no_count} no, {unknown_count} unknown")
        
        return {
            'total_cases': total,
            'successful': successful,
            'failed': failed,
            'yes_count': yes_count,
            'no_count': no_count,
            'unknown_count': unknown_count,
            'success_rate': success_rate,
            'dry_run': dry_run
        }
    
    def get_verification_stats(self) -> Dict[str, Any]:
        """
        Get verification statistics.
        
        Returns:
            Dictionary with verification statistics
        """
        try:
            # Get total cases with mentions_1960 = 1
            total_1960_cases = self.db_manager.execute_query(
                "SELECT COUNT(*) FROM cases WHERE mentions_1960 = 1"
            )[0][0]
            
            # Get classified cases
            classified_cases = self.db_manager.execute_query(
                "SELECT COUNT(*) FROM cases WHERE mentions_1960 = 1 AND classification IS NOT NULL AND classification != '' AND classification != 'unknown'"
            )[0][0]
            
            # Get classification breakdown
            yes_cases = self.db_manager.execute_query(
                "SELECT COUNT(*) FROM cases WHERE mentions_1960 = 1 AND classification = 'yes'"
            )[0][0]
            
            no_cases = self.db_manager.execute_query(
                "SELECT COUNT(*) FROM cases WHERE mentions_1960 = 1 AND classification = 'no'"
            )[0][0]
            
            unknown_cases = self.db_manager.execute_query(
                "SELECT COUNT(*) FROM cases WHERE mentions_1960 = 1 AND (classification IS NULL OR classification = '' OR classification = 'unknown')"
            )[0][0]
            
            classification_rate = (classified_cases / total_1960_cases * 100) if total_1960_cases > 0 else 0
            
            return {
                'total_1960_cases': total_1960_cases,
                'classified_cases': classified_cases,
                'unclassified_cases': unknown_cases,
                'classification_rate': classification_rate,
                'yes_cases': yes_cases,
                'no_cases': no_cases,
                'unknown_cases': unknown_cases
            }
            
        except Exception as e:
            logger.error(f"Failed to get verification stats: {e}")
            return {} 