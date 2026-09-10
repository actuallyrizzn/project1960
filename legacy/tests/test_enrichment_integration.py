import pytest
import json
import sqlite3
import tempfile
import os
from unittest.mock import Mock, patch, MagicMock
import sys
import os

# Add the current directory to the path so we can import our modules
sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from enrich_cases import (
    main,
    get_extraction_prompt,
    clean_and_parse_json,
    store_extracted_data,
    setup_enrichment_tables,
    get_cases_to_enrich,
    call_venice_api
)

class TestFullEnrichmentWorkflow:
    """Test the complete enrichment workflow from end to end."""
    
    @pytest.fixture
    def temp_db_with_cases(self):
        """Create a temporary database with test cases."""
        with tempfile.NamedTemporaryFile(suffix='.db', delete=False) as f:
            db_path = f.name
        
        # Create the main cases table
        conn = sqlite3.connect(db_path)
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
        
        # Insert test cases with realistic DOJ press release content
        test_cases = [
            ('case1', 
             'Oregon Man Charged with Operating Illegal Money Transmitting Business',
             '''WASHINGTON – An Oregon man was charged today with operating an unlicensed money transmitting business in violation of federal law.

According to court documents, John Doe, 35, of Portland, Oregon, operated a cryptocurrency exchange business without obtaining the required licenses from the Financial Crimes Enforcement Network (FinCEN) and state authorities. The business allegedly processed over $2.5 million in transactions between 2020 and 2023.

"Operating an unlicensed money transmitting business is a serious crime that undermines our financial system," said U.S. Attorney Jane Smith of the District of Oregon. "We will continue to work with our law enforcement partners to investigate and prosecute those who violate these laws."

The FBI and IRS-Criminal Investigation conducted the investigation. Assistant U.S. Attorney John Johnson is prosecuting the case.

If convicted, Doe faces a maximum penalty of 20 years in federal prison.''',
             'http://example1.com', 1),
            ('case2', 
             'California Resident Sentenced to 72 Months in Prison for Cryptocurrency Fraud',
             '''LOS ANGELES – A California resident was sentenced today to 72 months in federal prison for operating a cryptocurrency investment fraud scheme that defrauded victims of more than $800,000.

Jane Smith, 42, of Los Angeles, California, was sentenced by U.S. District Judge Michael Brown. Smith pleaded guilty in March 2023 to one count of wire fraud and one count of operating an unlicensed money transmitting business.

"Today's sentence sends a clear message that cryptocurrency fraud will not be tolerated," said U.S. Attorney David Wilson of the Central District of California. "We will continue to aggressively pursue those who use digital assets to defraud innocent victims."

The case was investigated by the FBI's Los Angeles Field Office and prosecuted by Assistant U.S. Attorney Sarah Johnson.''',
             'http://example2.com', 1),
        ]
        cursor.executemany(
            "INSERT INTO cases (id, title, body, url, verified_1960) VALUES (?, ?, ?, ?, ?)",
            test_cases
        )
        conn.commit()
        conn.close()
        
        yield db_path
        
        # Cleanup - ensure all connections are closed first
        try:
            # Force close any remaining connections
            import gc
            gc.collect()
            
            if os.path.exists(db_path):
                os.unlink(db_path)
        except PermissionError:
            # On Windows, sometimes files are still locked
            # This is acceptable for tests
            pass
    
    @patch('enrich_cases.call_venice_api')
    def test_full_case_metadata_enrichment(self, mock_api_call, temp_db_with_cases):
        """Test the complete case_metadata enrichment workflow."""
        with patch('enrich_cases.DATABASE_NAME', temp_db_with_cases):
            # Setup enrichment tables
            setup_enrichment_tables()
            
            # Mock API response for case_metadata
            mock_response = {
                'choices': [{
                    'message': {
                        'content': json.dumps({
                            'district_office': 'District of Oregon',
                            'usa_name': 'Jane Smith',
                            'event_type': 'indictment',
                            'judge_name': None,
                            'judge_title': None,
                            'case_number': None,
                            'max_penalty_text': '20 years in federal prison',
                            'sentence_summary': None,
                            'money_amounts': '$2.5 million',
                            'crypto_assets': 'cryptocurrency',
                            'statutes_json': ['18 U.S.C. § 1960'],
                            'timeline_json': None
                        })
                    }
                }]
            }
            mock_api_call.return_value = mock_response
            
            # Run enrichment for case_metadata
            from enrich_cases import main
            import sys
            from io import StringIO
            
            # Capture stdout to check output
            captured_output = StringIO()
            sys.stdout = captured_output
            
            # Mock command line arguments
            with patch('sys.argv', ['enrich_cases.py', '--table', 'case_metadata', '--limit', '1']):
                main()
            
            # Restore stdout
            sys.stdout = sys.__stdout__
            
            # Verify API was called
            mock_api_call.assert_called_once()
            
            # Debug: Check what's in the database
            conn = sqlite3.connect(temp_db_with_cases)
            cursor = conn.cursor()
            
            # Check if table exists
            cursor.execute("SELECT name FROM sqlite_master WHERE type='table' AND name='case_metadata'")
            table_exists = cursor.fetchone()
            
            # Check all rows in case_metadata
            cursor.execute("SELECT * FROM case_metadata")
            all_rows = cursor.fetchall()
            
            # Check specific row
            cursor.execute("SELECT * FROM case_metadata WHERE case_id = ?", ('case1',))
            row = cursor.fetchone()
            
            conn.close()
            
            assert row is not None
            assert row[1] == 'District of Oregon'  # district_office
            assert row[2] == 'Jane Smith'  # usa_name
            assert row[3] == 'indictment'  # event_type
            assert row[7] == '20 years in federal prison'  # max_penalty_text
    
    @patch('enrich_cases.call_venice_api')
    def test_full_participants_enrichment(self, mock_api_call, temp_db_with_cases):
        """Test the complete participants enrichment workflow."""
        with patch('enrich_cases.DATABASE_NAME', temp_db_with_cases):
            setup_enrichment_tables()
            mock_response = {
                'choices': [{
                    'message': {
                        'content': json.dumps([
                            {
                                'name': 'John Doe',
                                'role': 'defendant',
                                'title': 'Mr.',
                                'organization': None,
                                'location': 'Portland, Oregon',
                                'age': 35,
                                'nationality': 'US',
                                'status': 'charged'
                            },
                            {
                                'name': 'Jane Smith',
                                'role': 'prosecutor',
                                'title': 'U.S. Attorney',
                                'organization': 'U.S. Attorney\'s Office',
                                'location': 'Oregon',
                                'age': None,
                                'nationality': None,
                                'status': None
                            }
                        ])
                    }
                }]
            }
            mock_api_call.return_value = mock_response
            from enrich_cases import main
            import sys
            from io import StringIO
            with patch('enrich_cases.clean_and_parse_json', return_value=[
                {
                    'name': 'John Doe',
                    'role': 'defendant',
                    'title': 'Mr.',
                    'organization': None,
                    'location': 'Portland, Oregon',
                    'age': 35,
                    'nationality': 'US',
                    'status': 'charged'
                },
                {
                    'name': 'Jane Smith',
                    'role': 'prosecutor',
                    'title': 'U.S. Attorney',
                    'organization': 'U.S. Attorney\'s Office',
                    'location': 'Oregon',
                    'age': None,
                    'nationality': None,
                    'status': None
                }
            ]):
                captured_output = StringIO()
                sys.stdout = captured_output
                with patch('sys.argv', ['enrich_cases.py', '--table', 'participants', '--limit', '1']):
                    main()
                sys.stdout = sys.__stdout__
            mock_api_call.assert_called_once()
            conn = sqlite3.connect(temp_db_with_cases)
            cursor = conn.cursor()
            cursor.execute("SELECT * FROM participants WHERE case_id = ? ORDER BY participant_id", ('case1',))
            rows = cursor.fetchall()
            conn.close()
            assert len(rows) == 2
            assert rows[0][2] == 'John Doe'
            assert rows[0][3] == 'defendant'
            assert rows[1][2] == 'Jane Smith'
            assert rows[1][3] == 'prosecutor'
    
    @patch('enrich_cases.call_venice_api')
    def test_full_charges_enrichment(self, mock_api_call, temp_db_with_cases):
        """Test the complete charges enrichment workflow."""
        with patch('enrich_cases.DATABASE_NAME', temp_db_with_cases):
            setup_enrichment_tables()
            mock_response = {
                'choices': [{
                    'message': {
                        'content': json.dumps([
                            {
                                'charge_description': 'Operating an Unlicensed Money Transmitting Business',
                                'statute': '18 U.S.C. § 1960',
                                'severity': 'felony',
                                'max_penalty': '20 years in federal prison',
                                'fine_amount': None,
                                'defendant': 'John Doe',
                                'status': 'charged'
                            }
                        ])
                    }
                }]
            }
            mock_api_call.return_value = mock_response
            from enrich_cases import main
            import sys
            from io import StringIO
            with patch('enrich_cases.clean_and_parse_json', return_value=[
                {
                    'charge_description': 'Operating an Unlicensed Money Transmitting Business',
                    'statute': '18 U.S.C. § 1960',
                    'severity': 'felony',
                    'max_penalty': '20 years in federal prison',
                    'fine_amount': None,
                    'defendant': 'John Doe',
                    'status': 'charged'
                }
            ]):
                captured_output = StringIO()
                sys.stdout = captured_output
                with patch('sys.argv', ['enrich_cases.py', '--table', 'charges', '--limit', '1']):
                    main()
                sys.stdout = sys.__stdout__
            mock_api_call.assert_called_once()
            conn = sqlite3.connect(temp_db_with_cases)
            cursor = conn.cursor()
            cursor.execute("SELECT * FROM charges WHERE case_id = ? ORDER BY charge_id", ('case1',))
            rows = cursor.fetchall()
            conn.close()
            assert len(rows) == 1
            assert rows[0][2] == 'Operating an Unlicensed Money Transmitting Business'
            assert rows[0][3] == '18 U.S.C. § 1960'
            assert rows[0][4] == 'felony'
            assert rows[0][5] == '20 years in federal prison'
    
    @patch('enrich_cases.call_venice_api')
    def test_api_failure_handling(self, mock_api_call, temp_db_with_cases):
        """Test that the system handles API failures gracefully."""
        with patch('enrich_cases.DATABASE_NAME', temp_db_with_cases):
            # Setup enrichment tables
            setup_enrichment_tables()
            
            # Mock API failure
            mock_api_call.side_effect = Exception("API Error")
            
            # Run enrichment
            from enrich_cases import main
            import sys
            from io import StringIO
            
            # Capture stdout to check output
            captured_output = StringIO()
            sys.stdout = captured_output
            
            # Mock command line arguments
            with patch('sys.argv', ['enrich_cases.py', '--table', 'case_metadata', '--limit', '1']):
                with pytest.raises(Exception):
                    main()
            
            # Restore stdout
            sys.stdout = sys.__stdout__
            
            # Verify API was called
            mock_api_call.assert_called_once()
            
            # Verify no data was stored (since API failed)
            conn = sqlite3.connect(temp_db_with_cases)
            cursor = conn.cursor()
            cursor.execute("SELECT COUNT(*) FROM case_metadata")
            count = cursor.fetchone()[0]
            
            assert count == 0  # No data should be stored
            
            conn.close()
    
    @patch('enrich_cases.call_venice_api')
    def test_json_parsing_failure_handling(self, mock_api_call, temp_db_with_cases):
        """Test that the system handles JSON parsing failures gracefully."""
        with patch('enrich_cases.DATABASE_NAME', temp_db_with_cases):
            # Setup enrichment tables
            setup_enrichment_tables()
            
            # Mock API response with invalid JSON
            mock_response = {
                'choices': [{
                    'message': {
                        'content': 'Invalid JSON response'
                    }
                }]
            }
            mock_api_call.return_value = mock_response
            
            # Run enrichment
            from enrich_cases import main
            import sys
            from io import StringIO
            
            # Capture stdout to check output
            captured_output = StringIO()
            sys.stdout = captured_output
            
            # Mock command line arguments
            with patch('sys.argv', ['enrich_cases.py', '--table', 'case_metadata', '--limit', '1']):
                main()
            
            # Restore stdout
            sys.stdout = sys.__stdout__
            
            # Verify API was called
            mock_api_call.assert_called_once()
            
            # Verify no data was stored (since JSON parsing failed)
            conn = sqlite3.connect(temp_db_with_cases)
            cursor = conn.cursor()
            cursor.execute("SELECT COUNT(*) FROM case_metadata")
            count = cursor.fetchone()[0]
            
            assert count == 0  # No data should be stored
            
            conn.close()
    
    def test_dry_run_mode(self, temp_db_with_cases):
        """Test that dry run mode works correctly."""
        with patch('enrich_cases.DATABASE_NAME', temp_db_with_cases):
            # Setup enrichment tables
            setup_enrichment_tables()
            
            # Run enrichment in dry run mode
            from enrich_cases import main
            import sys
            from io import StringIO
            
            # Capture stdout to check output
            captured_output = StringIO()
            sys.stdout = captured_output
            
            # Mock command line arguments for dry run
            with patch('sys.argv', ['enrich_cases.py', '--table', 'case_metadata', '--limit', '1', '--dry-run']):
                main()
            
            # Restore stdout
            sys.stdout = sys.__stdout__
            
            output = captured_output.getvalue()
            
            # Verify dry run message appears
            assert "DRY RUN" in output
            
            # Verify no data was stored (since it's dry run)
            conn = sqlite3.connect(temp_db_with_cases)
            cursor = conn.cursor()
            cursor.execute("SELECT COUNT(*) FROM case_metadata")
            count = cursor.fetchone()[0]
            
            assert count == 0  # No data should be stored in dry run
            
            conn.close()

class TestEnrichmentOrchestrator:
    """Test the enrichment orchestrator functionality."""
    
    @pytest.fixture
    def temp_db_with_cases(self):
        """Create a temporary database with test cases."""
        with tempfile.NamedTemporaryFile(suffix='.db', delete=False) as f:
            db_path = f.name
        
        # Create the main cases table
        conn = sqlite3.connect(db_path)
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
        
        # Insert a test case
        cursor.execute(
            "INSERT INTO cases (id, title, body, url, verified_1960) VALUES (?, ?, ?, ?, ?)",
            ('case1', 'Test Case', 'Test body', 'http://test.com', 1)
        )
        conn.commit()
        conn.close()
        
        yield db_path
        
        # Cleanup
        try:
            import gc
            gc.collect()
            
            if os.path.exists(db_path):
                os.unlink(db_path)
        except PermissionError:
            pass
    
    @patch('subprocess.run')
    def test_run_enrichment_orchestrator(self, mock_subprocess, temp_db_with_cases):
        """Test the enrichment orchestrator runs all passes correctly."""
        with patch('enrich_cases.DATABASE_NAME', temp_db_with_cases):
            # Mock subprocess.run to simulate successful enrichment runs
            mock_subprocess.return_value = Mock(returncode=0)
            
            # Import and run the orchestrator
            import run_enrichment
            import sys
            from io import StringIO
            
            # Capture stdout to check output
            captured_output = StringIO()
            sys.stdout = captured_output
            
            # Mock command line arguments to avoid pytest args
            with patch('sys.argv', ['run_enrichment.py']):
                # Run the orchestrator
                with pytest.raises(SystemExit):
                    run_enrichment.main()
            
            # Restore stdout
            sys.stdout = sys.__stdout__
            
            # Verify subprocess was called for each table
            expected_calls = [
                'case_metadata',
                'participants', 
                'case_agencies',
                'charges',
                'financial_actions',
                'victims',
                'quotes',
                'themes'
            ]
            
            assert mock_subprocess.call_count == len(expected_calls)
            
            # Verify each table was processed
            for call in mock_subprocess.call_args_list:
                args = call[0][0]
                assert 'enrich_cases.py' in args
                assert '--table' in args
                table_arg_index = args.index('--table') + 1
                assert args[table_arg_index] in expected_calls

if __name__ == "__main__":
    pytest.main([__file__, "-v"]) 