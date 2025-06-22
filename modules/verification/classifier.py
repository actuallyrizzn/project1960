"""
1960 verification classifier for DOJ cases.
"""
import logging
from typing import Optional, Dict, Any
from utils.api_client import VeniceAPIClient
from utils.json_parser import extract_json_from_content
from utils.logging_config import get_logger

logger = get_logger(__name__)

def classify_case(case_id: str, title: str, body: str, dry_run: bool = False) -> Optional[str]:
    """
    Classify a case as to whether it involves 18 U.S.C. § 1960.
    
    Args:
        case_id: The case ID
        title: The case title
        body: The case body text
        dry_run: If True, simulate the classification without making API calls
        
    Returns:
        Classification result: 'yes', 'no', or 'unknown'
    """
    if dry_run:
        logger.info(f"[DRY RUN] Would classify case {case_id}")
        # Simulate API response for dry run
        return 'yes'
    
    try:
        # Create API client
        api_client = VeniceAPIClient()
        
        # Get classification prompt
        prompt = _get_classification_prompt(title, body)
        
        # Make API call
        logger.debug(f"Sending classification request for case {case_id}")
        response_data = api_client.call_api(prompt)
        
        if not response_data:
            logger.error(f"API call failed for case {case_id}")
            return 'unknown'
        
        # Extract content from response
        content = api_client.extract_content(response_data)
        if not content:
            logger.error(f"Failed to extract content from API response for case {case_id}")
            return 'unknown'
        
        # Parse JSON from content
        parsed_data = extract_json_from_content(content)
        if not parsed_data:
            logger.error(f"Failed to parse JSON from content for case {case_id}")
            return 'unknown'
        
        # Extract answer
        answer = parsed_data.get('answer', '').lower().strip()
        if answer in ['yes', 'no', 'unknown']:
            logger.info(f"Classification result for case {case_id}: {answer}")
            return answer
        else:
            logger.warning(f"Invalid answer value for case {case_id}: {repr(answer)}")
            return 'unknown'
            
    except Exception as e:
        logger.error(f"Error classifying case {case_id}: {e}")
        return 'unknown'

def _get_classification_prompt(title: str, body: str) -> str:
    """Get the classification prompt for 1960 verification."""
    return f"""
You are a legal expert specializing in U.S. federal criminal law. Your task is to analyze the following U.S. Department of Justice press release and determine whether it involves violations of 18 U.S.C. § 1960 (Operating Unlicensed Money Transmitting Businesses).

**About 18 U.S.C. § 1960:**
This statute makes it a federal crime to operate an unlicensed money transmitting business. It applies to:
- Operating a money transmitting business without proper state or federal licenses
- Transmitting funds without required registration
- Operating money services businesses (MSBs) without proper authorization
- Cryptocurrency exchanges or services that act as money transmitters without licenses

**Instructions:**
1. Read the entire press release carefully.
2. Look for any mention of:
   - Money transmitting businesses
   - Unlicensed financial services
   - Cryptocurrency exchanges or services
   - Money services businesses (MSBs)
   - Wire transfers or money transfers
   - Financial services without proper licensing
   - References to 18 U.S.C. § 1960 specifically
3. Determine if the case involves violations of this statute.
4. Return ONLY a JSON object with an "answer" field containing one of:
   - "yes" - if the case clearly involves 18 U.S.C. § 1960 violations
   - "no" - if the case does not involve 18 U.S.C. § 1960 violations
   - "unknown" - if you cannot determine with certainty

**Press Release Title:**
{title}

**Press Release Body:**
{body}

{{
  "answer": "yes|no|unknown"
}}
"""

def store_classification(case_id: str, classification: str, dry_run: bool = False) -> bool:
    """
    Store the classification result in the database.
    
    Args:
        case_id: The case ID
        classification: The classification result
        dry_run: If True, simulate the storage without making database changes
        
    Returns:
        True if successful, False otherwise
    """
    if dry_run:
        logger.info(f"[DRY RUN] Would store classification: {classification}")
        return True
    
    try:
        from utils.database import DatabaseManager
        
        db_manager = DatabaseManager()
        
        # Update the cases table with the classification
        query = "UPDATE cases SET classification = ? WHERE id = ?"
        db_manager.execute_query(query, (classification, case_id))
        
        logger.info(f"Successfully stored classification '{classification}' for case {case_id}")
        return True
        
    except Exception as e:
        logger.error(f"Failed to store classification for case {case_id}: {e}")
        return False 