"""
Data storage operations for enrichment data.
"""
import json
import logging
from typing import Any, Dict, List, Optional
from utils.database import DatabaseManager
from utils.logging_config import get_logger

logger = get_logger(__name__)

def store_extracted_data(case_id: str, table_name: str, normalized_data: Any, url: str) -> bool:
    """
    Store extracted data in the appropriate table after validating the data type.
    
    Args:
        case_id: The case ID
        table_name: The table to store data in
        normalized_data: The normalized data to store
        url: The press release URL
        
    Returns:
        True if successful, False otherwise
    """
    # Handle None data
    if normalized_data is None:
        logger.warning(f"No data to store for {table_name}. Case ID: {case_id}")
        log_enrichment_activity(case_id, table_name, 'error', 'No data received from AI')
        return False
    
    # Handle data type conversion for list-based tables
    if table_name != 'case_metadata':
        if isinstance(normalized_data, dict):
            logger.info(f"Converting single dict to list for {table_name}. Case ID: {case_id}")
            normalized_data = [normalized_data]
        elif not isinstance(normalized_data, list):
            error_msg = f"Invalid data type for {table_name}. Expected list or dict, but got {type(normalized_data).__name__}."
            logger.error(f"{error_msg} Case ID: {case_id}, Data: {repr(normalized_data)}")
            log_enrichment_activity(case_id, table_name, 'error', error_msg)
            return False
    else:
        # case_metadata expects a dict
        if not isinstance(normalized_data, dict):
            error_msg = f"Invalid data type for {table_name}. Expected dict, but got {type(normalized_data).__name__}."
            logger.error(f"{error_msg} Case ID: {case_id}, Data: {repr(normalized_data)}")
            log_enrichment_activity(case_id, table_name, 'error', error_msg)
            return False
        
    try:
        db_manager = DatabaseManager()
        
        if table_name == 'case_metadata':
            return _store_case_metadata(db_manager, case_id, normalized_data, url)
        elif table_name == 'participants':
            return _store_participants(db_manager, case_id, normalized_data)
        elif table_name == 'case_agencies':
            return _store_case_agencies(db_manager, case_id, normalized_data)
        elif table_name == 'charges':
            return _store_charges(db_manager, case_id, normalized_data)
        elif table_name == 'financial_actions':
            return _store_financial_actions(db_manager, case_id, normalized_data)
        elif table_name == 'victims':
            return _store_victims(db_manager, case_id, normalized_data)
        elif table_name == 'quotes':
            return _store_quotes(db_manager, case_id, normalized_data)
        elif table_name == 'themes':
            return _store_themes(db_manager, case_id, normalized_data)
        else:
            logger.error(f"Unknown table name: {table_name}")
            log_enrichment_activity(case_id, table_name, 'error', f'Unknown table: {table_name}')
            return False
            
    except Exception as e:
        logger.error(f"Failed to store data for case {case_id} in table {table_name}: {e}")
        log_enrichment_activity(case_id, table_name, 'error', str(e))
        return False

def _store_case_metadata(db_manager: DatabaseManager, case_id: str, data_obj: Dict[str, Any], url: str) -> bool:
    """Store case metadata."""
    try:
        if not isinstance(data_obj, dict):
            logger.error(f"case_metadata expects a dict, got {type(data_obj)}: {repr(data_obj)}")
            log_enrichment_activity(case_id, 'case_metadata', 'error', f'Expected dict, got {type(data_obj)}')
            return False
            
        data_obj['press_release_url'] = url
        columns = ['case_id', 'district_office', 'usa_name', 'event_type', 'judge_name', 'judge_title', 'case_number', 'max_penalty_text', 'sentence_summary', 'money_amounts', 'crypto_assets', 'statutes_json', 'timeline_json', 'press_release_url', 'extras_json']
        values = [case_id]
        
        for col in columns[1:]:
            value = data_obj.get(col)
            # Handle fields that should be comma-separated strings - convert lists if needed
            if col in ['money_amounts', 'crypto_assets'] and isinstance(value, list):
                value = ', '.join(str(item) for item in value)
            # Handle JSON fields
            elif col in ['statutes_json', 'timeline_json', 'extras_json'] and value is not None:
                if isinstance(value, (dict, list)):
                    value = json.dumps(value)
            values.append(value)
            
        query = f"INSERT OR REPLACE INTO case_metadata ({', '.join(columns)}) VALUES ({', '.join(['?'] * len(columns))})"
        db_manager.execute_query(query, tuple(values))
        
        logger.info(f"Successfully stored case metadata for case {case_id}")
        log_enrichment_activity(case_id, 'case_metadata', 'success', 'Data stored successfully')
        return True
        
    except Exception as e:
        logger.error(f"Failed to store case metadata for case {case_id}: {e}")
        log_enrichment_activity(case_id, 'case_metadata', 'error', str(e))
        return False

def _store_participants(db_manager: DatabaseManager, case_id: str, data: List[Dict[str, Any]]) -> bool:
    """Store participants data."""
    # Clear existing data for this case
    db_manager.execute_query("DELETE FROM participants WHERE case_id = ?", (case_id,))
    
    stored_count = 0
    for item in data:
        if not isinstance(item, dict):
            logger.warning(f"Skipping non-dict item in participants list: {item}")
            continue
            
        columns = ['case_id', 'name', 'role', 'title', 'organization', 'location', 'age', 'nationality', 'status']
        values = [case_id] + [item.get(col) for col in columns[1:]]
        
        query = f"INSERT INTO participants ({','.join(columns)}) VALUES ({','.join(['?'] * len(columns))})"
        db_manager.execute_query(query, tuple(values))
        stored_count += 1
        
    logger.info(f"Successfully stored {stored_count} participants for case {case_id}.")
    log_enrichment_activity(case_id, 'participants', 'success', f'Stored {stored_count} participants')
    return True

def _store_case_agencies(db_manager: DatabaseManager, case_id: str, data: List[Dict[str, Any]]) -> bool:
    """Store case agencies data."""
    # Clear existing data for this case
    db_manager.execute_query("DELETE FROM case_agencies WHERE case_id = ?", (case_id,))
    
    stored_count = 0
    skipped = 0
    
    for agency in data:
        if not isinstance(agency, dict):
            logger.warning(f"Skipping non-dict item in case_agencies: {repr(agency)} (type: {type(agency)})")
            skipped += 1
            continue
            
        columns = ['case_id', 'agency_name', 'abbreviation', 'role', 'office_location', 'agents_mentioned', 'contribution']
        values = [case_id]
        
        for col in columns[1:]:
            value = agency.get(col)
            # Handle agents_mentioned field - convert list to comma-separated string if needed
            if col == 'agents_mentioned' and isinstance(value, list):
                value = ', '.join(str(item) for item in value)
            values.append(value)
            
        query = f"INSERT INTO case_agencies ({', '.join(columns)}) VALUES ({', '.join(['?'] * len(columns))})"
        db_manager.execute_query(query, tuple(values))
        stored_count += 1
        
    logger.info(f"Successfully stored {stored_count} agencies for case {case_id}. Skipped {skipped} non-dict items.")
    log_enrichment_activity(case_id, 'case_agencies', 'success', f'Stored {stored_count} agencies')
    return True

def _store_charges(db_manager: DatabaseManager, case_id: str, data: List[Dict[str, Any]]) -> bool:
    """Store charges data."""
    # Clear existing data for this case
    db_manager.execute_query("DELETE FROM charges WHERE case_id = ?", (case_id,))
    
    stored_count = 0
    skipped = 0
    
    for charge in data:
        if not isinstance(charge, dict):
            logger.warning(f"Skipping non-dict item in charges: {repr(charge)} (type: {type(charge)})")
            skipped += 1
            continue
            
        columns = ['case_id', 'charge_description', 'statute', 'severity', 'max_penalty', 'fine_amount', 'defendant', 'status']
        values = [case_id]
        
        for col in columns[1:]:
            values.append(charge.get(col))
            
        query = f"INSERT INTO charges ({', '.join(columns)}) VALUES ({', '.join(['?'] * len(columns))})"
        db_manager.execute_query(query, tuple(values))
        stored_count += 1
        
    logger.info(f"Successfully stored {stored_count} charges for case {case_id}. Skipped {skipped} non-dict items.")
    log_enrichment_activity(case_id, 'charges', 'success', f'Stored {stored_count} charges')
    return True

def _store_financial_actions(db_manager: DatabaseManager, case_id: str, data: List[Dict[str, Any]]) -> bool:
    """Store financial actions data."""
    # Clear existing data for this case
    db_manager.execute_query("DELETE FROM financial_actions WHERE case_id = ?", (case_id,))
    
    stored_count = 0
    skipped = 0
    
    for action in data:
        if not isinstance(action, dict):
            logger.warning(f"Skipping non-dict item in financial_actions: {repr(action)} (type: {type(action)})")
            skipped += 1
            continue
            
        columns = ['case_id', 'action_type', 'amount', 'currency', 'description', 'asset_type', 'defendant', 'status']
        values = [case_id]
        
        for col in columns[1:]:
            values.append(action.get(col))
            
        query = f"INSERT INTO financial_actions ({', '.join(columns)}) VALUES ({', '.join(['?'] * len(columns))})"
        db_manager.execute_query(query, tuple(values))
        stored_count += 1
        
    logger.info(f"Successfully stored {stored_count} financial actions for case {case_id}. Skipped {skipped} non-dict items.")
    log_enrichment_activity(case_id, 'financial_actions', 'success', f'Stored {stored_count} financial actions')
    return True

def _store_victims(db_manager: DatabaseManager, case_id: str, data: List[Dict[str, Any]]) -> bool:
    """Store victims data."""
    # Clear existing data for this case
    db_manager.execute_query("DELETE FROM victims WHERE case_id = ?", (case_id,))
    
    stored_count = 0
    skipped = 0
    
    for victim in data:
        if not isinstance(victim, dict):
            logger.warning(f"Skipping non-dict item in victims: {repr(victim)} (type: {type(victim)})")
            skipped += 1
            continue
            
        columns = ['case_id', 'victim_type', 'description', 'number_affected', 'loss_amount', 'geographic_scope', 'vulnerability_factors', 'impact_description']
        values = [case_id]
        
        for col in columns[1:]:
            values.append(victim.get(col))
            
        query = f"INSERT INTO victims ({', '.join(columns)}) VALUES ({', '.join(['?'] * len(columns))})"
        db_manager.execute_query(query, tuple(values))
        stored_count += 1
        
    logger.info(f"Successfully stored {stored_count} victims for case {case_id}. Skipped {skipped} non-dict items.")
    log_enrichment_activity(case_id, 'victims', 'success', f'Stored {stored_count} victims')
    return True

def _store_quotes(db_manager: DatabaseManager, case_id: str, data: List[Dict[str, Any]]) -> bool:
    """Store quotes data."""
    # Clear existing data for this case
    db_manager.execute_query("DELETE FROM quotes WHERE case_id = ?", (case_id,))
    
    stored_count = 0
    skipped = 0
    
    for quote in data:
        if not isinstance(quote, dict):
            logger.warning(f"Skipping non-dict item in quotes: {repr(quote)} (type: {type(quote)})")
            skipped += 1
            continue
            
        columns = ['case_id', 'quote_text', 'speaker_name', 'speaker_title', 'speaker_organization', 'quote_type', 'context', 'significance']
        values = [case_id]
        
        for col in columns[1:]:
            values.append(quote.get(col))
            
        query = f"INSERT INTO quotes ({', '.join(columns)}) VALUES ({', '.join(['?'] * len(columns))})"
        db_manager.execute_query(query, tuple(values))
        stored_count += 1
        
    logger.info(f"Successfully stored {stored_count} quotes for case {case_id}. Skipped {skipped} non-dict items.")
    log_enrichment_activity(case_id, 'quotes', 'success', f'Stored {stored_count} quotes')
    return True

def _store_themes(db_manager: DatabaseManager, case_id: str, data: List[Dict[str, Any]]) -> bool:
    """Store themes data."""
    # Clear existing data for this case
    db_manager.execute_query("DELETE FROM themes WHERE case_id = ?", (case_id,))
    
    stored_count = 0
    skipped = 0
    
    for theme in data:
        if not isinstance(theme, dict):
            logger.warning(f"Skipping non-dict item in themes: {repr(theme)} (type: {type(theme)})")
            skipped += 1
            continue
            
        columns = ['case_id', 'theme_name', 'description', 'significance', 'related_statutes', 'geographic_scope', 'temporal_aspects', 'stakeholders']
        values = [case_id]
        
        for col in columns[1:]:
            value = theme.get(col)
            # Handle fields that should be comma-separated strings - convert lists if needed
            if col in ['related_statutes', 'stakeholders'] and isinstance(value, list):
                value = ', '.join(str(item) for item in value)
            values.append(value)
            
        query = f"INSERT INTO themes ({', '.join(columns)}) VALUES ({', '.join(['?'] * len(columns))})"
        db_manager.execute_query(query, tuple(values))
        stored_count += 1
        
    logger.info(f"Successfully stored {stored_count} themes for case {case_id}. Skipped {skipped} non-dict items.")
    log_enrichment_activity(case_id, 'themes', 'success', f'Stored {stored_count} themes')
    return True

def log_enrichment_activity(case_id: str, table_name: str, status: str, notes: str) -> None:
    """Log enrichment activity to the database."""
    try:
        import datetime
        db_manager = DatabaseManager()
        
        timestamp = datetime.datetime.now().isoformat()
        query = "INSERT INTO enrichment_activity_log (timestamp, case_id, table_name, status, notes) VALUES (?, ?, ?, ?, ?)"
        db_manager.execute_query(query, (timestamp, case_id, table_name, status, notes))
        
    except Exception as e:
        logger.error(f"Failed to log enrichment activity: {e}") 