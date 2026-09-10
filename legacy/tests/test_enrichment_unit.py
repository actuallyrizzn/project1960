import pytest
import json
import sqlite3
import tempfile
import os
from unittest.mock import Mock, patch, MagicMock
import sys
import os
from orchestrators.enrichment_orchestrator import EnrichmentOrchestrator

# Add the current directory to the path so we can import our modules
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from enrich_cases import (
    get_extraction_prompt,
    store_extracted_data,
    setup_enrichment_tables,
    get_cases_to_enrich,
    call_venice_api
)
from utils.json_parser import clean_and_parse_json

@pytest.fixture
def temp_db():
    """Create a temporary database for testing."""
    with tempfile.NamedTemporaryFile(suffix='.db', delete=False) as f:
        db_path = f.name
    yield db_path
    if os.path.exists(db_path):
        os.unlink(db_path)

@pytest.fixture
def temp_db_with_cases(temp_db):
    """Create a temporary database with test cases."""
    with patch('enrich_cases.DATABASE_NAME', temp_db):
        conn = sqlite3.connect(temp_db)
        cursor = conn.cursor()
        cursor.execute("""
            CREATE TABLE cases (
                id TEXT PRIMARY KEY,
                title TEXT,
                body TEXT,
                url TEXT,
                verified_1960 INTEGER
            )
        """)
        test_cases = [
            ('case1', 'Test Case 1', 'Body 1', 'http://example1.com', 1),
            ('case2', 'Test Case 2', 'Body 2', 'http://example2.com', 1),
            ('case3', 'Test Case 3', 'Body 3', 'http://example3.com', 0),
        ]
        cursor.executemany(
            "INSERT INTO cases (id, title, body, url, verified_1960) VALUES (?, ?, ?, ?, ?)",
            test_cases
        )
        conn.commit()
        conn.close()
        yield temp_db

class TestExtractionPrompts:
    """Test the extraction prompt generation for all tables."""
    
    def test_case_metadata_prompt(self):
        """Test that case_metadata prompt is generated correctly."""
        title = "Test Case Title"
        body = "Test case body content"
        
        prompt = get_extraction_prompt('case_metadata', title, body)
        
        assert prompt is not None
        assert "legal data extraction expert" in prompt
        assert title in prompt
        assert body in prompt
        assert "district_office" in prompt
        assert "usa_name" in prompt
        assert "event_type" in prompt
    
    def test_participants_prompt(self):
        """Test that participants prompt is generated correctly."""
        title = "Test Case Title"
        body = "Test case body content"
        
        prompt = get_extraction_prompt('participants', title, body)
        
        assert prompt is not None
        assert "participants mentioned" in prompt
        assert "name" in prompt
        assert "role" in prompt
        assert "defendant" in prompt
        assert "prosecutor" in prompt
    
    def test_case_agencies_prompt(self):
        """Test that case_agencies prompt is generated correctly."""
        title = "Test Case Title"
        body = "Test case body content"
        
        prompt = get_extraction_prompt('case_agencies', title, body)
        
        assert prompt is not None
        assert "law enforcement agencies" in prompt
        assert "agency_name" in prompt
        assert "abbreviation" in prompt
        assert "FBI" in prompt
    
    def test_charges_prompt(self):
        """Test that charges prompt is generated correctly."""
        title = "Test Case Title"
        body = "Test case body content"
        
        prompt = get_extraction_prompt('charges', title, body)
        
        assert prompt is not None
        assert "criminal charges" in prompt
        assert "charge_description" in prompt
        assert "statute" in prompt
        assert "18 U.S.C. § 1960" in prompt
    
    def test_financial_actions_prompt(self):
        """Test that financial_actions prompt is generated correctly."""
        title = "Test Case Title"
        body = "Test case body content"
        
        prompt = get_extraction_prompt('financial_actions', title, body)
        
        assert prompt is not None
        assert "financial actions" in prompt
        assert "forfeiture" in prompt
        assert "fine" in prompt
        assert "amount" in prompt
    
    def test_victims_prompt(self):
        """Test that victims prompt is generated correctly."""
        title = "Test Case Title"
        body = "Test case body content"
        
        prompt = get_extraction_prompt('victims', title, body)
        
        assert prompt is not None
        assert "victims mentioned" in prompt
        assert "victim_type" in prompt
        assert "individual" in prompt
        assert "business" in prompt
    
    def test_quotes_prompt(self):
        """Test that quotes prompt is generated correctly."""
        title = "Test Case Title"
        body = "Test case body content"
        
        prompt = get_extraction_prompt('quotes', title, body)
        
        assert prompt is not None
        assert "significant quotes" in prompt
        assert "quote_text" in prompt
        assert "speaker_name" in prompt
        assert "U.S. Attorney" in prompt
    
    def test_themes_prompt(self):
        """Test that themes prompt is generated correctly."""
        title = "Test Case Title"
        body = "Test case body content"
        
        prompt = get_extraction_prompt('themes', title, body)
        
        assert prompt is not None
        assert "key themes" in prompt
        assert "theme_name" in prompt
        assert "Money Laundering" in prompt
        assert "Cryptocurrency" in prompt
    
    def test_invalid_table_prompt(self):
        """Test that invalid table raises ValueError."""
        with pytest.raises(ValueError):
            get_extraction_prompt('invalid_table', "title", "body")

class TestJSONParsing:
    """Test the JSON parsing and cleaning functionality."""
    
    def test_clean_simple_json(self):
        """Test parsing of clean JSON."""
        json_data = {"name": "John Doe", "age": 30}
        raw_text = json.dumps(json_data)
        
        result = clean_and_parse_json(raw_text)
        
        assert result == json_data
    
    def test_json_with_thinking_tags(self):
        """Test parsing JSON that has thinking tags around it."""
        json_data = {"name": "John Doe", "age": 30}
        raw_text = f"<think>I need to extract information about the person</think>\n{json.dumps(json_data)}"
        
        result = clean_and_parse_json(raw_text)
        
        assert result == json_data
    
    def test_json_with_markdown_blocks(self):
        """Test parsing JSON wrapped in markdown code blocks."""
        json_data = {"name": "John Doe", "age": 30}
        raw_text = f"```json\n{json.dumps(json_data)}\n```"
        
        result = clean_and_parse_json(raw_text)
        
        assert result == json_data
    
    def test_json_with_explanation_text(self):
        """Test parsing JSON with explanatory text before it."""
        json_data = {"name": "John Doe", "age": 30}
        raw_text = f"Here is the extracted information:\n{json.dumps(json_data)}"
        
        result = clean_and_parse_json(raw_text)
        
        assert result == json_data
    
    def test_multiple_json_objects(self):
        """Test parsing when multiple JSON objects are present."""
        json_data = {"name": "John Doe", "age": 30}
        raw_text = f'{{"wrong": "data"}}\n{json.dumps(json_data)}'
        
        result = clean_and_parse_json(raw_text)
        
        assert result == json_data
    
    def test_invalid_json(self):
        """Test handling of invalid JSON."""
        raw_text = "This is not valid JSON at all"
        
        result = clean_and_parse_json(raw_text)
        
        assert result is None
    
    def test_empty_string(self):
        """Test handling of empty string."""
        result = clean_and_parse_json("")
        assert result is None
    
    def test_none_input(self):
        """Test handling of None input."""
        result = clean_and_parse_json(None)
        assert result is None

class TestDatabaseOperations:
    """Test database setup and operations."""
    
    def test_setup_enrichment_tables(self, temp_db):
        """Test that enrichment tables are created correctly."""
        with patch('enrich_cases.DATABASE_NAME', temp_db):
            setup_enrichment_tables()
            
            # Verify tables exist
            conn = sqlite3.connect(temp_db)
            cursor = conn.cursor()
            
            # Get all tables
            cursor.execute("SELECT name FROM sqlite_master WHERE type='table'")
            tables = [row[0] for row in cursor.fetchall()]
            
            expected_tables = [
                'case_metadata', 'participants', 'case_agencies', 
                'charges', 'financial_actions', 'victims', 'quotes', 'themes'
            ]
            
            for table in expected_tables:
                assert table in tables
            
            conn.close()
    
    def test_store_case_metadata(self, temp_db):
        """Test storing case metadata."""
        with patch('enrich_cases.DATABASE_NAME', temp_db):
            setup_enrichment_tables()
            
            test_data = {
                'district_office': 'Southern District of New York',
                'usa_name': 'John Doe',
                'event_type': 'indictment',
                'judge_name': 'Jane Smith',
                'judge_title': 'U.S. District Judge',
                'case_number': '1:23-cr-456',
                'max_penalty_text': 'up to 20 years in prison',
                'sentence_summary': 'sentenced to 10 years',
                'money_amounts': '$2.5 million',
                'crypto_assets': 'BTC, ETH',
                'statutes_json': ['18 U.S.C. § 1960'],
                'timeline_json': {'indictment_date': '2023-01-15'},
                'extras_json': {'additional_info': 'test'}
            }
            
            store_extracted_data('test_case_id', 'case_metadata', test_data, 'http://example.com')
            
            # Verify data was stored
            conn = sqlite3.connect(temp_db)
            cursor = conn.cursor()
            cursor.execute("SELECT * FROM case_metadata WHERE case_id = ?", ('test_case_id',))
            row = cursor.fetchone()
            
            assert row is not None
            assert row[1] == 'Southern District of New York'  # district_office
            assert row[2] == 'John Doe'  # usa_name
            assert row[3] == 'indictment'  # event_type
            
            conn.close()
    
    def test_store_participants(self, temp_db):
        """Test storing participants data."""
        with patch('enrich_cases.DATABASE_NAME', temp_db):
            setup_enrichment_tables()
            
            test_data = [
                {
                    'name': 'John Doe',
                    'role': 'defendant',
                    'title': 'Mr.',
                    'organization': None,
                    'location': 'New York',
                    'age': 35,
                    'nationality': 'US',
                    'status': 'indicted'
                },
                {
                    'name': 'Jane Smith',
                    'role': 'prosecutor',
                    'title': 'Assistant U.S. Attorney',
                    'organization': 'U.S. Attorney\'s Office',
                    'location': 'New York',
                    'age': None,
                    'nationality': None,
                    'status': None
                }
            ]
            
            store_extracted_data('test_case_id', 'participants', test_data, 'http://example.com')
            
            # Verify data was stored
            conn = sqlite3.connect(temp_db)
            cursor = conn.cursor()
            cursor.execute("SELECT * FROM participants WHERE case_id = ?", ('test_case_id',))
            rows = cursor.fetchall()
            
            assert len(rows) == 2
            assert rows[0][2] == 'John Doe'  # name
            assert rows[0][3] == 'defendant'  # role
            assert rows[1][2] == 'Jane Smith'  # name
            assert rows[1][3] == 'prosecutor'  # role
            
            conn.close()
    
    def test_store_charges(self, temp_db):
        """Test storing charges data."""
        with patch('enrich_cases.DATABASE_NAME', temp_db):
            setup_enrichment_tables()
            
            test_data = [
                {
                    'charge_description': 'Operating an Unlicensed Money Transmitting Business',
                    'statute': '18 U.S.C. § 1960',
                    'severity': 'felony',
                    'max_penalty': '20 years in prison',
                    'fine_amount': '$250,000',
                    'defendant': 'John Doe',
                    'status': 'indicted'
                }
            ]
            
            store_extracted_data('test_case_id', 'charges', test_data, 'http://example.com')
            
            # Verify data was stored
            conn = sqlite3.connect(temp_db)
            cursor = conn.cursor()
            cursor.execute("SELECT * FROM charges WHERE case_id = ?", ('test_case_id',))
            rows = cursor.fetchall()
            
            assert len(rows) == 1
            assert rows[0][2] == 'Operating an Unlicensed Money Transmitting Business'  # charge_description
            assert rows[0][3] == '18 U.S.C. § 1960'  # statute
            assert rows[0][4] == 'felony'  # severity
            
            conn.close()
    
    def test_store_empty_data(self, temp_db):
        """Test handling of empty data."""
        with patch('enrich_cases.DATABASE_NAME', temp_db):
            setup_enrichment_tables()
            
            # Should not raise an exception
            store_extracted_data('test_case_id', 'case_metadata', None, 'http://example.com')
            store_extracted_data('test_case_id', 'participants', [], 'http://example.com')

class TestAPICalls:
    """Test the Venice API calling functionality."""
    
    @patch('enrich_cases.requests.post')
    def test_successful_api_call(self, mock_post):
        """Test successful API call."""
        mock_response = Mock()
        mock_response.json.return_value = {
            'choices': [{'message': {'content': '{"test": "data"}'}}]
        }
        mock_response.raise_for_status.return_value = None
        mock_post.return_value = mock_response
        
        with patch('enrich_cases.VENICE_API_KEY', 'test_key'):
            result = call_venice_api("test prompt")
            
            assert result is not None
            assert result['choices'][0]['message']['content'] == '{"test": "data"}'
    
    @patch('enrich_cases.requests.post')
    def test_api_call_failure(self, mock_post):
        """Test API call failure."""
        mock_post.side_effect = Exception("API Error")
        
        with patch('enrich_cases.VENICE_API_KEY', 'test_key'):
            result = call_venice_api("test prompt")
            
            assert result is None
    
    @patch('enrich_cases.requests.post')
    def test_api_timeout(self, mock_post):
        """Test API call timeout."""
        from requests.exceptions import Timeout
        mock_post.side_effect = Timeout("Request timed out")
        
        with patch('enrich_cases.VENICE_API_KEY', 'test_key'):
            result = call_venice_api("test prompt")
            
            assert result is None

class TestCaseQueries:
    """Test the case querying functionality."""
    
    def test_get_cases_to_enrich_with_existing_table(self, temp_db_with_cases):
        """Test getting cases when enrichment table exists."""
        with patch('enrich_cases.DATABASE_NAME', temp_db_with_cases):
            setup_enrichment_tables()
            
            cases = get_cases_to_enrich('case_metadata', 5)
            
            assert len(cases) == 2  # Only verified cases
            assert cases[0][0] in ['case1', 'case2']  # case_id
            assert cases[0][1].startswith('Test Case')  # title
    
    def test_get_cases_to_enrich_without_table(self, temp_db_with_cases):
        """Test getting cases when enrichment table doesn't exist."""
        with patch('enrich_cases.DATABASE_NAME', temp_db_with_cases):
            cases = get_cases_to_enrich('case_metadata', 5)
            
            assert len(cases) == 2  # Should use fallback query
            assert cases[0][0] in ['case1', 'case2']  # case_id
    
    def test_get_cases_to_enrich_limit(self, temp_db_with_cases):
        """Test that limit is respected."""
        with patch('enrich_cases.DATABASE_NAME', temp_db_with_cases):
            setup_enrichment_tables()
            
            cases = get_cases_to_enrich('case_metadata', 1)
            
            assert len(cases) == 1

class TestEnrichmentOrchestrator1960Logic:
    @pytest.fixture
    def temp_db_with_enrichment_tables(self):
        with tempfile.NamedTemporaryFile(suffix='.db', delete=False) as f:
            db_path = f.name
        conn = sqlite3.connect(db_path)
        cursor = conn.cursor()
        # Create cases table
        cursor.execute('''
            CREATE TABLE cases (
                id TEXT PRIMARY KEY,
                title TEXT,
                body TEXT,
                url TEXT,
                classification TEXT
            )
        ''')
        # Create a sample enrichment table
        cursor.execute('''
            CREATE TABLE participants (
                participant_id INTEGER PRIMARY KEY AUTOINCREMENT,
                case_id TEXT,
                name TEXT
            )
        ''')
        conn.commit()
        yield db_path
        try:
            if os.path.exists(db_path):
                os.unlink(db_path)
        except PermissionError:
            pass

    def test_has_1960_verified_cases_to_enrich_true(self, temp_db_with_enrichment_tables):
        orchestrator = EnrichmentOrchestrator()
        orchestrator.db_manager.db_path = temp_db_with_enrichment_tables
        # Insert a 1960-verified case with no enrichment row
        conn = sqlite3.connect(temp_db_with_enrichment_tables)
        cursor = conn.cursor()
        cursor.execute("INSERT INTO cases (id, title, body, url, classification) VALUES (?, ?, ?, ?, ?)",
                       ("case1", "Title", "Body", "url", "yes"))
        conn.commit()
        conn.close()
        assert orchestrator._has_1960_verified_cases_to_enrich('participants') is True

    def test_has_1960_verified_cases_to_enrich_false(self, temp_db_with_enrichment_tables):
        orchestrator = EnrichmentOrchestrator()
        orchestrator.db_manager.db_path = temp_db_with_enrichment_tables
        # Insert a 1960-verified case with enrichment row
        conn = sqlite3.connect(temp_db_with_enrichment_tables)
        cursor = conn.cursor()
        cursor.execute("INSERT INTO cases (id, title, body, url, classification) VALUES (?, ?, ?, ?, ?)",
                       ("case1", "Title", "Body", "url", "yes"))
        cursor.execute("INSERT INTO participants (case_id, name) VALUES (?, ?)", ("case1", "John Doe"))
        conn.commit()
        conn.close()
        assert orchestrator._has_1960_verified_cases_to_enrich('participants') is False

    def test_run_all_enrichment_prioritizes_1960(self, temp_db_with_enrichment_tables):
        orchestrator = EnrichmentOrchestrator()
        orchestrator.db_manager.db_path = temp_db_with_enrichment_tables
        # Insert a 1960-verified case and a non-1960 case
        conn = sqlite3.connect(temp_db_with_enrichment_tables)
        cursor = conn.cursor()
        cursor.execute("INSERT INTO cases (id, title, body, url, classification) VALUES (?, ?, ?, ?, ?)",
                       ("case1", "Title", "Body", "url", "yes"))
        cursor.execute("INSERT INTO cases (id, title, body, url, classification) VALUES (?, ?, ?, ?, ?)",
                       ("case2", "Title2", "Body2", "url2", "no"))
        conn.commit()
        conn.close()
        # Patch run_enrichment to record the verified_1960_only flag
        called_flags = []
        def fake_run_enrichment(table_name, limit, dry_run, verified_1960_only, case_number=None):
            called_flags.append(verified_1960_only)
            return {'table_name': table_name, 'total_cases': 0, 'successful': 0, 'failed': 0, 'success_rate': 0.0, 'dry_run': dry_run}
        with patch.object(orchestrator, 'run_enrichment', side_effect=fake_run_enrichment):
            with patch('orchestrators.enrichment_orchestrator.get_all_schemas', return_value={'participants': '', 'enrichment_activity_log': ''}):
                orchestrator.run_all_enrichment()
        # Should prioritize 1960-verified cases (verified_1960_only True)
        assert any(called_flags)

    def test_run_all_enrichment_fallback_to_all(self, temp_db_with_enrichment_tables):
        orchestrator = EnrichmentOrchestrator()
        orchestrator.db_manager.db_path = temp_db_with_enrichment_tables
        # Insert only a non-1960-verified case
        conn = sqlite3.connect(temp_db_with_enrichment_tables)
        cursor = conn.cursor()
        cursor.execute("INSERT INTO cases (id, title, body, url, classification) VALUES (?, ?, ?, ?, ?)",
                       ("case2", "Title2", "Body2", "url2", "no"))
        conn.commit()
        conn.close()
        called_flags = []
        def fake_run_enrichment(table_name, limit, dry_run, verified_1960_only, case_number=None):
            called_flags.append(verified_1960_only)
            return {'table_name': table_name, 'total_cases': 0, 'successful': 0, 'failed': 0, 'success_rate': 0.0, 'dry_run': dry_run}
        with patch.object(orchestrator, 'run_enrichment', side_effect=fake_run_enrichment):
            with patch('orchestrators.enrichment_orchestrator.get_all_schemas', return_value={'participants': '', 'enrichment_activity_log': ''}):
                orchestrator.run_all_enrichment()
        # Should fallback to all cases (verified_1960_only False)
        assert not any(called_flags)

if __name__ == "__main__":
    pytest.main([__file__, "-v"]) 