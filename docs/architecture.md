# Architecture Overview

Project1960 is built with a **modular, layered architecture** that separates concerns and promotes maintainability. This document provides a comprehensive overview of the system design, technical decisions, and architectural patterns.

## 🏗️ System Architecture

### High-Level Overview

```
┌─────────────────────────────────────────────────────────────┐
│                    Web Interface Layer                      │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐        │
│  │   Dashboard │  │ Case Browser│  │ Enrichment  │        │
│  │   (Flask)   │  │   (Flask)   │  │ Dashboard   │        │
│  └─────────────┘  └─────────────┘  └─────────────┘        │
└─────────────────────────────────────────────────────────────┘
                              │
┌─────────────────────────────────────────────────────────────┐
│                 Orchestration Layer                         │
│  ┌─────────────────────┐  ┌─────────────────────┐          │
│  │ Enrichment          │  │ Verification        │          │
│  │ Orchestrator        │  │ Orchestrator        │          │
│  └─────────────────────┘  └─────────────────────┘          │
└─────────────────────────────────────────────────────────────┘
                              │
┌─────────────────────────────────────────────────────────────┐
│                  Domain Modules Layer                       │
│  ┌─────────────────────┐  ┌─────────────────────┐          │
│  │ Enrichment Module   │  │ Verification Module │          │
│  │ • Prompts           │  │ • Classifier        │          │
│  │ • Schemas           │  │ • Validation        │          │
│  │ • Storage           │  └─────────────────────┘          │
│  └─────────────────────┘                                    │
└─────────────────────────────────────────────────────────────┘
                              │
┌─────────────────────────────────────────────────────────────┐
│                   Core Utilities Layer                      │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐        │
│  │   Config    │  │  Database   │  │ API Client  │        │
│  └─────────────┘  └─────────────┘  └─────────────┘        │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐        │
│  │ JSON Parser │  │   Logging   │  │ File Utils  │        │
│  └─────────────┘  └─────────────┘  └─────────────┘        │
└─────────────────────────────────────────────────────────────┘
                              │
┌─────────────────────────────────────────────────────────────┐
│                    Data Layer                               │
│  ┌─────────────────────────────────────────────────────┐   │
│  │              SQLite Database                        │   │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐ │   │
│  │  │    Cases    │  │ Enrichment  │  │ Activity    │ │   │
│  │  │   Table     │  │   Tables    │  │    Log      │ │   │
│  │  └─────────────┘  └─────────────┘  └─────────────┘ │   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

## 📁 Directory Structure

```
project1960/
├── app.py                          # Flask web application
├── scraper.py                      # DOJ press release scraper
├── enrich_cases_modular.py         # Modular enrichment script
├── 1960-verify_modular.py          # Modular verification script
├── run_enrichment.py               # Batch enrichment runner
├── check_db.py                     # Database management utility
├── migrate_schemas.py              # Schema migration utility
├── requirements.txt                # Python dependencies
├── .env                           # Environment variables
├── env.example                    # Environment template
├── doj_cases.db                   # SQLite database
├── enrichment.log                 # Enrichment activity log
│
├── utils/                         # Core utilities
│   ├── __init__.py
│   ├── config.py                  # Configuration management
│   ├── database.py                # Database operations
│   ├── api_client.py              # Venice AI API client
│   ├── json_parser.py             # JSON parsing utilities
│   └── logging_config.py          # Logging configuration
│
├── modules/                       # Domain-specific modules
│   ├── __init__.py
│   ├── enrichment/                # Enrichment domain
│   │   ├── __init__.py
│   │   ├── prompts.py             # AI prompt templates
│   │   ├── schemas.py             # Database schema definitions
│   │   └── storage.py             # Data storage operations
│   └── verification/              # Verification domain
│       ├── __init__.py
│       └── classifier.py          # 1960 verification logic
│
├── orchestrators/                 # Process orchestration
│   ├── __init__.py
│   ├── enrichment_orchestrator.py # Enrichment workflow
│   └── verification_orchestrator.py # Verification workflow
│
├── templates/                     # Web interface templates
│   ├── base.html                  # Base template
│   ├── index.html                 # Dashboard
│   ├── cases.html                 # Case browser
│   ├── case_detail.html           # Case details
│   ├── enrichment.html            # Enrichment dashboard
│   └── about.html                 # About page
│
├── tests/                         # Test suite
│   ├── __init__.py
│   ├── test_enrichment_unit.py    # Unit tests
│   └── test_enrichment_integration.py # Integration tests
│
└── docs/                          # Documentation
    ├── README.md                  # Documentation index
    ├── quick-start.md             # Quick start guide
    ├── architecture.md            # This file
    └── ...                        # Other documentation
```

## 🔧 Core Components

### 1. Core Utilities (`utils/`)

The utilities layer provides foundational services used throughout the application:

#### Configuration Management (`utils/config.py`)
- **Purpose**: Centralized configuration management
- **Features**: Environment variable handling, validation, API configuration
- **Key Classes**: `Config` class with validation methods

#### Database Operations (`utils/database.py`)
- **Purpose**: Database connection and operation management
- **Features**: Connection pooling, error handling, retry logic
- **Key Classes**: `DatabaseManager` with standardized query execution

#### API Client (`utils/api_client.py`)
- **Purpose**: Venice AI API integration
- **Features**: Model fallback system, timeout handling, error recovery
- **Key Classes**: `VeniceAPIClient` with intelligent model selection

#### JSON Parsing (`utils/json_parser.py`)
- **Purpose**: Robust JSON extraction from AI responses
- **Features**: Multiple parsing strategies, dirty JSON handling
- **Key Functions**: `clean_and_parse_json()`, `extract_json_from_content()`

#### Logging (`utils/logging_config.py`)
- **Purpose**: Standardized logging across the application
- **Features**: Configurable log levels, consistent formatting
- **Key Functions**: `setup_logging()`, `get_logger()`

### 2. Domain Modules (`modules/`)

Domain-specific business logic organized by functional areas:

#### Enrichment Module (`modules/enrichment/`)
- **Purpose**: AI-powered data extraction from press releases
- **Components**:
  - `prompts.py`: AI prompt templates for each data table
  - `schemas.py`: Database schema definitions
  - `storage.py`: Data storage and validation operations

#### Verification Module (`modules/verification/`)
- **Purpose**: 18 USC 1960 classification logic
- **Components**:
  - `classifier.py`: AI classification and validation

### 3. Orchestration Layer (`orchestrators/`)

High-level process coordination and workflow management:

#### Enrichment Orchestrator (`orchestrators/enrichment_orchestrator.py`)
- **Purpose**: Coordinates the enrichment workflow
- **Features**: Batch processing, error handling, progress tracking
- **Key Methods**: `process_table()`, `get_unprocessed_cases()`

#### Verification Orchestrator (`orchestrators/verification_orchestrator.py`)
- **Purpose**: Coordinates the verification workflow
- **Features**: Case selection, classification, result storage
- **Key Methods**: `verify_cases()`, `get_sample_cases()`

### 4. Web Interface Layer

Modern Flask-based web application:

#### Flask Application (`app.py`)
- **Purpose**: Web interface for data exploration
- **Features**: Dashboard, case browser, enrichment monitoring
- **Key Routes**: `/`, `/cases`, `/enrichment`, `/case/<id>`

#### Templates (`templates/`)
- **Framework**: Bootstrap 5 with responsive design
- **Features**: Dark mode, filtering, search, pagination
- **Key Templates**: `base.html`, `index.html`, `cases.html`

## 🔄 Data Flow Architecture

### 1. Data Collection Flow

```
DOJ API → Scraper → Database → Classification → Enrichment → Analysis
```

1. **Scraping**: `scraper.py` fetches press releases from DOJ API
2. **Storage**: Raw data stored in `cases` table
3. **Classification**: AI identifies 1960-related cases
4. **Enrichment**: Structured data extracted to relational tables
5. **Analysis**: Web interface provides exploration tools

### 2. Enrichment Flow

```
Unprocessed Cases → AI Processing → JSON Parsing → Validation → Storage
```

1. **Case Selection**: Orchestrator selects unprocessed cases
2. **AI Processing**: Venice AI extracts structured data
3. **JSON Parsing**: Robust parsing with multiple fallback strategies
4. **Validation**: Data type checking and normalization
5. **Storage**: Structured data stored in appropriate tables

### 3. Web Interface Flow

```
User Request → Flask Route → Database Query → Template Rendering → Response
```

1. **Request Handling**: Flask routes handle user requests
2. **Data Retrieval**: Database queries fetch relevant data
3. **Template Processing**: Jinja2 templates render HTML
4. **Response**: Bootstrap-styled pages returned to user

## 🛡️ Error Handling Architecture

### Multi-Layer Error Handling

1. **API Layer**: Retry logic, timeout handling, model fallback
2. **Database Layer**: Connection retry, transaction rollback
3. **Processing Layer**: Graceful degradation, error logging
4. **Web Layer**: User-friendly error messages, fallback content

### Error Recovery Strategies

- **Model Fallback**: Automatic switching to alternative AI models
- **JSON Parsing**: Multiple strategies for malformed responses
- **Database Operations**: Retry logic with exponential backoff
- **Web Interface**: Graceful handling of missing data

## 🔒 Security Architecture

### Environment-Based Configuration
- **API Keys**: Stored in `.env` files, never in code
- **Database**: Local SQLite with file-based permissions
- **Web Interface**: No authentication (public dataset)

### Data Protection
- **Input Validation**: All user inputs validated and sanitized
- **SQL Injection Prevention**: Parameterized queries throughout
- **XSS Prevention**: Template auto-escaping enabled

## 📊 Performance Architecture

### Optimization Strategies

1. **Database Optimization**:
   - Indexed queries for common filters
   - Connection pooling for web interface
   - Efficient schema design

2. **API Optimization**:
   - Intelligent model selection based on document size
   - Token limit management
   - Request batching where possible

3. **Web Interface Optimization**:
   - Pagination for large datasets
   - Efficient database queries
   - Responsive design for mobile devices

### Scalability Considerations

- **Modular Design**: Easy to extend with new features
- **Database Schema**: Supports complex queries and relationships
- **API Architecture**: Can scale to multiple AI providers
- **Web Interface**: Stateless design supports horizontal scaling

## 🔄 Deployment Architecture

### Development Environment
- **Local SQLite**: File-based database for development
- **Flask Development Server**: Built-in server for testing
- **Environment Variables**: Local `.env` configuration

### Production Considerations
- **Database**: Can migrate to PostgreSQL/MySQL for larger scale
- **Web Server**: Can deploy behind Nginx/Apache
- **Process Management**: Can use systemd/supervisor
- **Monitoring**: Comprehensive logging for operational visibility

## 🧪 Testing Architecture

### Test Organization
- **Unit Tests**: Individual component testing
- **Integration Tests**: End-to-end workflow testing
- **Test Utilities**: Shared test helpers and fixtures

### Testing Strategy
- **Mocking**: API calls mocked for reliable testing
- **Database**: Test database with sample data
- **Coverage**: Comprehensive test coverage for critical paths

## 📈 Monitoring Architecture

### Logging Strategy
- **Structured Logging**: Consistent format across all components
- **Log Levels**: Configurable verbosity for different environments
- **Activity Tracking**: Comprehensive audit trail of all operations

### Metrics Collection
- **Processing Metrics**: Success rates, processing times
- **API Usage**: Model usage, token consumption
- **Database Metrics**: Query performance, table sizes

---

*This architecture provides a solid foundation for the current system while maintaining flexibility for future enhancements and scaling.* 