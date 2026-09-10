# Command Line Tools

Project1960 provides a comprehensive set of command-line tools for data collection, processing, and management. This guide covers all available scripts, their options, and usage examples.

## 🛠️ Available Tools

### Core Processing Scripts
- **`scraper.py`** - DOJ press release collection
- **`enrich_cases_modular.py`** - AI-powered data enrichment
- **`1960-verify_modular.py`** - Case classification and verification
- **`run_enrichment.py`** - Batch enrichment processing

### Management Scripts
- **`check_db.py`** - Database status and management
- **`migrate_schemas.py`** - Schema migration utility

## 📥 Data Collection

### DOJ Press Release Scraper

**Script**: `scraper.py`

Automatically scrapes Department of Justice press releases and stores them in the database.

```bash
python scraper.py
```

**Features:**
- **Idempotent Operation**: Safe to run multiple times without duplicates
- **Automatic Filtering**: Identifies cases mentioning 18 USC 1960 or cryptocurrency
- **Error Recovery**: Handles network issues and API failures gracefully
- **Progress Tracking**: Shows real-time progress and statistics

**What it does:**
1. Fetches press releases from DOJ API
2. Filters for relevant cases (1960 mentions, crypto mentions)
3. Stores data in the `cases` table
4. Skips already processed releases

**Output Example:**
```
Setting up the database...
Database setup complete.
Fetching press releases from DOJ API...
Processing 50 press releases...
Found 12 cases mentioning 1960 or crypto terms
Inserted 8 new cases into database
Scraping complete. Total cases: 3,653
```

## 🔍 Case Classification

### 1960 Verification Script

**Script**: `1960-verify_modular.py`

Uses AI to classify whether cases involve 18 USC 1960 violations.

```bash
# Basic usage
python 1960-verify_modular.py

# With options
python 1960-verify_modular.py --limit 10 --verbose --dry-run
```

**Command Line Options:**

| Option | Description | Default |
|--------|-------------|---------|
| `--limit N` | Process up to N cases | 100 |
| `--verbose` | Enable detailed logging | False |
| `--dry-run` | Simulate without making changes | False |
| `--help` | Show help message | - |

**Examples:**

```bash
# Process 10 cases with verbose output
python 1960-verify_modular.py --limit 10 --verbose

# Test run without making changes
python 1960-verify_modular.py --dry-run --limit 5

# Process all unclassified cases
python 1960-verify_modular.py --limit 1000
```

**Output Example:**
```
2025-01-27 10:30:15 - INFO - Starting 1960 verification process
2025-01-27 10:30:15 - INFO - Found 25 unprocessed cases
2025-01-27 10:30:16 - INFO - Processing case 12345: "DOJ Announces Money Transmitter Charges"
2025-01-27 10:30:18 - INFO - Classification result: yes
2025-01-27 10:30:18 - INFO - Updated case 12345 with classification: yes
...
2025-01-27 10:35:20 - INFO - Verification complete. Processed 10 cases.
```

## 🧠 Data Enrichment

### Modular Enrichment Script

**Script**: `enrich_cases_modular.py`

Extracts structured data from press releases using AI and stores it in relational tables.

```bash
# Basic usage (requires table specification)
python enrich_cases_modular.py --table case_metadata

# With options
python enrich_cases_modular.py --table participants --limit 20 --verbose
```

**Command Line Options:**

| Option | Description | Default | Required |
|--------|-------------|---------|----------|
| `--table TABLE` | Target enrichment table | - | **Yes** |
| `--limit N` | Process up to N cases | 100 | No |
| `--case_number ID` | Process specific case | - | No |
| `--all` | Process all unprocessed cases | False | No |
| `--verbose` | Enable detailed logging | False | No |
| `--setup-only` | Create tables without processing | False | No |
| `--help` | Show help message | - | No |

**Available Tables:**
- `case_metadata` - Core case details (district, judge, case numbers)
- `participants` - People and organizations involved
- `case_agencies` - Investigating and prosecuting agencies
- `charges` - Legal charges and statutes
- `financial_actions` - Monetary penalties and outcomes
- `victims` - Individuals or organizations harmed
- `quotes` - Notable statements from officials
- `themes` - Thematic tags and categories

**Examples:**

```bash
# Enrich case metadata for 10 cases
python enrich_cases_modular.py --table case_metadata --limit 10

# Process specific case
python enrich_cases_modular.py --table participants --case_number 12345

# Process all unprocessed cases for charges
python enrich_cases_modular.py --table charges --all

# Set up database tables only
python enrich_cases_modular.py --setup-only

# Verbose processing with progress tracking
python enrich_cases_modular.py --table financial_actions --limit 5 --verbose
```

**Output Example:**
```
2025-01-27 11:00:15 - INFO - Starting enrichment for table: case_metadata
2025-01-27 11:00:15 - INFO - Found 15 unprocessed cases for case_metadata
2025-01-27 11:00:16 - INFO - Processing case 12345
2025-01-27 11:00:18 - INFO - Extracted metadata: district=Eastern District of NY, judge=Judge Smith
2025-01-27 11:00:18 - INFO - Successfully stored metadata for case 12345
...
2025-01-27 11:05:20 - INFO - Enrichment complete. Processed 10 cases for case_metadata.
```

### Batch Enrichment Runner

**Script**: `run_enrichment.py`

Advanced enrichment script with enhanced features and batch processing capabilities.

```bash
# Basic usage
python run_enrichment.py

# With specific table
python run_enrichment.py --table participants

# Batch processing
python run_enrichment.py --batch --limit 50
```

**Command Line Options:**

| Option | Description | Default |
|--------|-------------|---------|
| `--table TABLE` | Target enrichment table | All tables |
| `--limit N` | Process up to N cases | 100 |
| `--batch` | Enable batch processing mode | False |
| `--verbose` | Enable detailed logging | False |
| `--help` | Show help message | - |

**Examples:**

```bash
# Process all tables for 20 cases
python run_enrichment.py --limit 20

# Batch process participants table
python run_enrichment.py --table participants --batch --limit 50

# Verbose batch processing
python run_enrichment.py --batch --verbose
```

## 🗄️ Database Management

### Database Status Checker

**Script**: `check_db.py`

Check database status, run queries, and manage database operations.

```bash
# Check database status
python check_db.py

# Run custom query
python check_db.py --query "SELECT COUNT(*) FROM cases WHERE verified_1960 = 1"

# Rebuild enrichment tables
python check_db.py --rebuild
```

**Command Line Options:**

| Option | Description | Default |
|--------|-------------|---------|
| `--query SQL` | Execute custom SQL query | - |
| `--rebuild` | Drop and rebuild enrichment tables | False |
| `--help` | Show help message | - |

**Examples:**

```bash
# Check overall database status
python check_db.py

# Count verified 1960 cases
python check_db.py --query "SELECT COUNT(*) FROM cases WHERE verified_1960 = 1"

# Check enrichment progress
python check_db.py --query "SELECT table_name, COUNT(DISTINCT case_id) FROM enrichment_activity_log WHERE status='success' GROUP BY table_name"

# Rebuild all enrichment tables (WARNING: Destructive)
python check_db.py --rebuild
```

**Output Example:**
```
Database Status Report
======================
Database file: doj_cases.db
Total cases: 3,653
Cases mentioning 1960: 935
Verified 1960 cases: 2,860
Enrichment tables: 8

Enrichment Progress:
- case_metadata: 500 cases processed
- participants: 1,200 cases processed
- charges: 800 cases processed
- financial_actions: 300 cases processed
- victims: 150 cases processed
- quotes: 400 cases processed
- themes: 600 cases processed
- case_agencies: 700 cases processed
```

### Schema Migration Utility

**Script**: `migrate_schemas.py`

Safely migrate database schemas without data loss.

```bash
python migrate_schemas.py
```

**Features:**
- **Non-destructive**: Preserves all existing data
- **Safe to re-run**: Can be executed multiple times
- **Progress tracking**: Shows migration progress
- **Error handling**: Graceful handling of migration issues

**Output Example:**
```
Starting schema migration...
Checking current schema...
Adding missing columns to case_metadata table...
Adding missing columns to participants table...
...
Migration complete. All tables updated successfully.
```

## 🔄 Workflow Examples

### Complete Data Processing Workflow

```bash
# 1. Collect fresh data
python scraper.py

# 2. Classify cases for 1960 violations
python 1960-verify_modular.py --limit 50

# 3. Enrich verified cases with detailed data
python enrich_cases_modular.py --table case_metadata --limit 20
python enrich_cases_modular.py --table participants --limit 20
python enrich_cases_modular.py --table charges --limit 20

# 4. Check progress
python check_db.py
```

### Batch Processing Workflow

```bash
# 1. Set up database tables
python enrich_cases_modular.py --setup-only

# 2. Run batch enrichment for all tables
python run_enrichment.py --batch --limit 100

# 3. Monitor progress
python check_db.py --query "SELECT table_name, COUNT(DISTINCT case_id) FROM enrichment_activity_log WHERE status='success' GROUP BY table_name"
```

### Development and Testing Workflow

```bash
# 1. Test classification with dry run
python 1960-verify_modular.py --dry-run --limit 5 --verbose

# 2. Test enrichment on specific case
python enrich_cases_modular.py --table case_metadata --case_number 12345 --verbose

# 3. Check database status
python check_db.py

# 4. Run custom queries for testing
python check_db.py --query "SELECT * FROM cases WHERE id = '12345'"
```

## 🐛 Troubleshooting

### Common Issues and Solutions

**"VENICE_API_KEY environment variable is not set!"**
```bash
# Check if .env file exists and has correct API key
cat .env | grep VENICE_API_KEY

# Set API key manually (temporary)
export VENICE_API_KEY=your_api_key_here
```

**"No module named 'dirtyjson'"**
```bash
# Install missing dependencies
pip install -r requirements.txt
```

**"Database is locked"**
```bash
# Check for running processes
ps aux | grep python

# Kill any stuck processes
pkill -f "python.*enrich_cases"
```

**"No cases found to process"**
```bash
# Check if cases exist in database
python check_db.py --query "SELECT COUNT(*) FROM cases"

# Run scraper to collect data
python scraper.py
```

### Debug Mode

Most scripts support verbose logging for debugging:

```bash
# Enable verbose logging
python enrich_cases_modular.py --table case_metadata --verbose

# Check logs for detailed information
tail -f enrichment.log
```

## 📊 Performance Tips

### Optimization Strategies

1. **Batch Processing**: Use `--batch` flag for large datasets
2. **Limit Processing**: Use `--limit` to control processing volume
3. **Targeted Processing**: Use `--case_number` for specific cases
4. **Parallel Processing**: Run multiple scripts for different tables

### Resource Management

```bash
# Monitor system resources during processing
htop

# Check disk space
df -h

# Monitor database size
ls -lh doj_cases.db
```

## 🔧 Advanced Usage

### Custom Queries

```bash
# Find cases with specific characteristics
python check_db.py --query "SELECT title, date FROM cases WHERE mentions_crypto = 1 AND verified_1960 = 1 ORDER BY date DESC LIMIT 10"

# Analyze enrichment success rates
python check_db.py --query "SELECT table_name, status, COUNT(*) FROM enrichment_activity_log GROUP BY table_name, status"

# Find cases with financial actions over $1M
python check_db.py --query "SELECT c.title, SUM(fa.amount) as total FROM cases c JOIN financial_actions fa ON c.id = fa.case_id WHERE fa.amount > 1000000 GROUP BY c.id, c.title ORDER BY total DESC"
```

### Automation Scripts

Create shell scripts for common workflows:

```bash
#!/bin/bash
# daily_processing.sh

echo "Starting daily processing..."
python scraper.py
python 1960-verify_modular.py --limit 100
python enrich_cases_modular.py --table case_metadata --limit 50
python enrich_cases_modular.py --table participants --limit 50
echo "Daily processing complete."
```

---

*These command-line tools provide comprehensive control over the Project1960 data pipeline, from initial collection through detailed enrichment and analysis.* 