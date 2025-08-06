# project1960

A comprehensive system for scraping, analyzing, and exploring Department of Justice press releases, with a focus on 18 USC 1960 (money transmission) and cryptocurrency-related cases.

<img width="1280" height="592" alt="image" src="https://github.com/user-attachments/assets/c28f43da-1b7f-48cf-84a5-f15122743165" />

## Features

- **Web Scraper**: Automatically scrapes DOJ press releases with idempotent operation
- **AI-Powered Enrichment**: Extracts detailed, structured data from press releases into a relational database
- **Modular Architecture**: Clean, maintainable codebase with separated concerns and reusable components
- **Robust Data Processing**: Advanced JSON parsing with multiple fallback strategies and error handling
- **Web Interface**: Modern Flask/Bootstrap UI for exploring and filtering cases with clickable enrichment logs
- **File Server**: Simple HTTP server for file downloads
- **Database**: SQLite storage with a relational schema for complex queries
- **Production-Ready**: Automatic table creation, database locking prevention, and comprehensive logging

## License

This project is licensed under the **Creative Commons Attribution-ShareAlike 4.0 International License** (CC BY-SA 4.0). See the [LICENSE](LICENSE) file for details.

You are free to:
- Share — copy and redistribute the material in any medium or format
- Adapt — remix, transform, and build upon the material for any purpose, even commercially

Under the terms of Attribution and ShareAlike.

## Setup

### Prerequisites

- Python 3.8+
- Venice AI API key

### Installation

1. Clone the repository:
```bash
git clone <repository-url>
cd ocp2-project
```

2. Install dependencies:
```bash
pip install -r requirements.txt
```

3. Set up environment variables:
```bash
cp env.example .env
```

4. Edit `.env` and add your Venice AI API key:
```
VENICE_API_KEY=your_actual_api_key_here
```

### Configuration

The following environment variables can be configured in `.env`:

- `VENICE_API_KEY`: Your Venice AI API key (required)
- `DATABASE_NAME`: Database filename (default: `doj_cases.db`)
- `FLASK_DEBUG`: Enable Flask debug mode (default: `False`)
- `FLASK_HOST`: Flask server host (default: `0.0.0.0`)
- `FLASK_PORT`: Flask server port (default: `5000`)
- `FILE_SERVER_PORT`: File server port (default: `8000`)
- `FILE_SERVER_DIRECTORY`: Directory to serve files from (default: `.`)

## Usage

### 1. Scrape DOJ Press Releases

First, populate the database with cases that mention our keywords of interest.

```bash
python scraper.py
```

The scraper will:
- Fetch press releases from the DOJ API.
- Filter for cases mentioning "18 USC 1960" or cryptocurrency-related terms.
- Store initial findings in the `cases` table in the database.
- Idempotently skip any press releases that have already been processed.

### 2. Enrich Cases with AI

Next, use the AI-powered enrichment script to perform detailed data extraction on the scraped cases. This script populates a series of relational tables with structured data pulled from the press release text.

**Key Features:**
- **Automatic Table Creation**: All necessary database tables are created automatically
- **Robust JSON Parsing**: Multiple strategies to handle AI responses with thinking text
- **Database Lock Prevention**: Non-blocking logging with proper connection isolation
- **Comprehensive Error Handling**: Graceful failure recovery and detailed logging

You must specify which table you want to populate using the `--table` argument.

```bash
# Example: Enrich data for the 'case_metadata' table
python enrich_cases.py --table case_metadata

# Example: Enrich data for the 'participants' table for up to 10 cases
python enrich_cases.py --table participants --limit 10

# Example: Run with verbose logging to see detailed processing
python enrich_cases.py --table case_metadata --limit 5 --verbose

# Example: Set up database tables only (no processing)
python enrich_cases.py --setup-only
```

Available tables for enrichment are:
- `case_metadata`
- `participants`
- `case_agencies`
- `charges`
- `financial_actions`
- `victims`
- `quotes`
- `themes`

### 3. Modular Enrichment (Recommended)

For better maintainability and testing, use the modular enrichment script:

```bash
# Example: Enrich data for the 'case_metadata' table using modular architecture
python enrich_cases_modular.py --table case_metadata

# Example: Enrich data for the 'participants' table for up to 10 cases
python enrich_cases_modular.py --table participants --limit 10

# Example: Run with verbose logging to see detailed processing
python enrich_cases_modular.py --table case_metadata --limit 5 --verbose

# Example: Set up database tables only (no processing)
python enrich_cases_modular.py --setup-only
```

### 4. Verify Cases (Legacy)

The verification script checks if cases are related to 18 USC 1960 violations:

```bash
# Example: Verify up to 10 cases
python 1960-verify.py --limit 10

# Example: Run with verbose logging
python 1960-verify.py --limit 5 --verbose

# Example: Dry run to test without making changes
python 1960-verify.py --dry-run --limit 3
```

### 5. Modular Verification (Recommended)

For better maintainability, use the modular verification script:

```bash
# Example: Verify up to 10 cases using modular architecture
python 1960-verify_modular.py --limit 10

# Example: Run with verbose logging
python 1960-verify_modular.py --limit 5 --verbose

# Example: Dry run to test without making changes
python 1960-verify_modular.py --dry-run --limit 3
```

### 6. Launch Web Interface

```bash
python app.py
```

Visit `http://localhost:5000` to access the web interface.

**Enrichment Dashboard Features:**
- **Clickable Case IDs**: Click any case ID in the activity log to view case details and metadata
- **Real-time Progress**: See enrichment progress across all data tables
- **Activity Logging**: Track successful enrichments, errors, and skipped cases
- **Visual Indicators**: Color-coded status badges and progress bars

### 7. File Server (Optional)

```bash
python file_server.py
```

Serves files from the current directory on port 8000.

## Project Structure

```
ocp2-project/
├── scraper.py                    # DOJ press release scraper
├── enrich_cases.py               # AI-powered data extraction and enrichment (legacy)
├── enrich_cases_modular.py       # AI-powered data extraction (modular architecture)
├── run_enrichment.py             # Batch enrichment runner with verbose support
├── 1960-verify.py                # Legacy AI verification script
├── 1960-verify_modular.py        # AI verification script (modular architecture)
├── app.py                        # Flask web application
├── file_server.py                # Simple file server
├── doj_cases.db                  # SQLite database
├── requirements.txt              # Python dependencies
├── .env                          # Environment variables (create from env.example)
├── .gitignore                    # Git ignore rules
├── env.example                   # Environment template
├── LICENSE                       # CC BY-SA 4.0 license
├── CHANGELOG.md                  # Development history
├── templates/                    # Flask templates
│   ├── base.html
│   ├── index.html
│   ├── cases.html
│   ├── case_detail.html
│   └── enrichment.html
├── utils/                        # Core utilities (modular architecture)
│   ├── __init__.py
│   ├── config.py                 # Centralized configuration management
│   ├── database.py               # Database connection and schema management
│   ├── api_client.py             # Venice API client abstraction
│   ├── json_parser.py            # JSON parsing utilities
│   └── logging_config.py         # Logging setup and configuration
├── modules/                      # Domain-specific modules (modular architecture)
│   ├── __init__.py
│   ├── enrichment/               # Enrichment domain logic
│   │   ├── __init__.py
│   │   ├── prompts.py            # AI prompt templates
│   │   ├── schemas.py            # Database schema definitions
│   │   └── storage.py            # Data storage operations
│   └── verification/             # Verification domain logic
│       ├── __init__.py
│       └── classifier.py         # 1960 verification logic
├── orchestrators/                # Process orchestration (modular architecture)
│   ├── __init__.py
│   ├── enrichment_orchestrator.py    # Enrichment process coordination
│   └── verification_orchestrator.py  # Verification process coordination
├── docs/                         # Documentation
│   ├── modularization-plan.md    # Modularization architecture plan
│   ├── modularization-todos.md   # Implementation TODOs and constraints
│   └── project-plan.md           # Original project planning document
└── README.md                     # This file
```

## Modular Architecture

The project has been refactored into a clean, modular architecture with the following layers:

### 1. Core Utilities (`utils/`)
- **Configuration Management**: Centralized environment variable handling
- **Database Operations**: Standardized database connections and operations
- **API Client**: Abstracted Venice AI API interactions
- **JSON Parsing**: Robust JSON extraction and validation utilities
- **Logging**: Standardized logging configuration and setup

### 2. Domain-Specific Modules (`modules/`)
- **Enrichment**: AI-powered data extraction logic, prompts, and storage
- **Verification**: 18 USC 1960 classification and validation logic

### 3. Orchestration Layer (`orchestrators/`)
- **Process Coordination**: High-level workflow management
- **Error Handling**: Centralized error recovery and monitoring
- **Batch Processing**: Efficient handling of large datasets

### 4. CLI Interface
- **Modular Scripts**: `enrich_cases_modular.py` and `1960-verify_modular.py`
- **Legacy Support**: Original scripts maintained for backward compatibility
- **Consistent Interface**: Same CLI arguments across modular and legacy versions

## Database Schema

The project uses a relational database schema to store extracted data. The main `cases` table holds the raw press release content, and several satellite tables hold the structured data extracted by the AI.

```
cases (Primary Table)
   │
   ├─ case_metadata        (1-to-1: Core details like district, judge, case number)
   ├─ participants         (1-to-many: Defendants, prosecutors, agents, etc.)
   ├─ case_agencies        (1-to-many: Investigating agencies like FBI, IRS-CI)
   ├─ charges              (1-to-many: Specific legal charges and statutes)
   ├─ financial_actions    (1-to-many: Forfeitures, fines, restitution amounts)
   ├─ victims              (1-to-many: Details about victims mentioned)
   ├─ quotes               (1-to-many: Pull-quotes from officials)
   ├─ themes               (1-to-many: Thematic tags like 'romance_scam', 'darknet')
   └─ enrichment_activity_log (Audit trail of enrichment operations)
```

- **`cases`**: Stores the original press release data (title, date, body, URL).
- **Enrichment Tables**: Each table is linked to the `cases` table via a `case_id` and contains specific, structured fields extracted by the AI from the press release text. This relational model allows for complex queries and detailed analysis.
- **`enrichment_activity_log`**: Tracks all enrichment operations with timestamps, status, and notes for debugging and monitoring.

## Technical Improvements

### Modular Architecture
- **Separation of Concerns**: Clear boundaries between utilities, domain logic, and orchestration
- **Code Reusability**: Common functionality extracted into reusable modules
- **Maintainability**: Smaller, focused files with single responsibilities
- **Testability**: Isolated components that can be tested independently

### Robust Data Processing
- **Multi-Strategy JSON Parsing**: Handles AI responses with thinking text, incomplete JSON, and various formatting issues
- **JSON Validation**: Ensures extracted data has the expected structure before storage
- **Data Normalization**: Converts AI output variations into consistent database formats

### Database Reliability
- **Automatic Table Creation**: All tables are created automatically if they don't exist
- **Connection Isolation**: Separate database connections for logging to prevent deadlocks
- **Non-Blocking Logging**: Enrichment continues even if logging fails
- **Timeout Handling**: Proper connection timeouts and retry logic

### Production-Ready Features
- **Environment Configuration**: Centralized configuration management
- **Error Recovery**: Comprehensive error handling and graceful degradation
- **Logging**: Detailed logging for debugging and monitoring
- **CLI Interface**: Consistent command-line interface with help and validation 
