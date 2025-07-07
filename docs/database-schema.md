# Database Schema

Project1960 uses a **relational SQLite database** with a sophisticated schema designed to store both raw press release data and AI-extracted structured information. This document provides a complete overview of the database design, table relationships, and data model.

## 🗄️ Database Overview

### Database File
- **File**: `doj_cases.db`
- **Type**: SQLite 3
- **Size**: ~108MB (as of January 2025)
- **Tables**: 10 total (1 main + 8 enrichment + 1 activity log)

### Schema Design Philosophy
- **Normalized Structure**: Eliminates data redundancy
- **Relational Integrity**: Foreign key relationships between tables
- **Extensible Design**: Easy to add new enrichment tables
- **Audit Trail**: Comprehensive logging of all operations

## 📊 Core Tables

### 1. Cases Table (Primary)

The central table storing raw DOJ press release data:

```sql
CREATE TABLE cases (
    id TEXT PRIMARY KEY,                    -- Unique case identifier
    title TEXT,                             -- Press release title
    date TEXT,                              -- Publication date
    body TEXT,                              -- Full press release content
    url TEXT,                               -- Original DOJ URL
    teaser TEXT,                            -- Short description
    number TEXT,                            -- Case number (if available)
    component TEXT,                         -- DOJ component (FBI, IRS-CI, etc.)
    topic TEXT,                             -- DOJ topic classification
    changed TEXT,                           -- Last modified timestamp
    created TEXT,                           -- Creation timestamp
    mentions_1960 BOOLEAN,                  -- Whether text mentions 18 USC 1960
    mentions_crypto BOOLEAN,                -- Whether text mentions cryptocurrency
    verified_1960 BOOLEAN DEFAULT FALSE,    -- AI verification result
    verified_crypto BOOLEAN DEFAULT FALSE,  -- AI crypto verification result
    classification TEXT                     -- Final classification (yes/no/unknown)
);
```

**Key Features:**
- **Primary Key**: `id` (unique case identifier)
- **Content Storage**: `title`, `body` contain the raw press release text
- **Metadata**: `date`, `url`, `component`, `topic` provide context
- **Classification**: `mentions_1960`, `mentions_crypto`, `verified_1960` track AI analysis
- **Indexes**: Created on `mentions_1960`, `classification`, `date` for efficient querying

### 2. Enrichment Activity Log

Tracks all enrichment operations for monitoring and debugging:

```sql
CREATE TABLE enrichment_activity_log (
    log_id INTEGER PRIMARY KEY AUTOINCREMENT,
    timestamp TEXT,                         -- Operation timestamp
    case_id TEXT,                          -- Related case ID
    table_name TEXT,                       -- Target enrichment table
    status TEXT,                           -- Success/Error/Skipped
    notes TEXT                             -- Additional details or error messages
);
```

**Purpose:**
- **Audit Trail**: Complete history of all enrichment operations
- **Error Tracking**: Failed operations with detailed error messages
- **Progress Monitoring**: Real-time status of enrichment pipeline
- **Debugging**: Detailed logs for troubleshooting issues

## 🔗 Enrichment Tables

The system extracts structured data into 8 specialized tables, each linked to the main `cases` table via `case_id`:

### 1. Case Metadata

Core case details extracted from press releases:

```sql
CREATE TABLE case_metadata (
    metadata_id INTEGER PRIMARY KEY AUTOINCREMENT,
    case_id TEXT,                          -- Foreign key to cases table
    district TEXT,                         -- Federal district
    judge TEXT,                           -- Presiding judge
    case_number TEXT,                     -- Official case number
    filing_date TEXT,                     -- Case filing date
    sentencing_date TEXT,                 -- Sentencing date (if applicable)
    jurisdiction TEXT,                    -- Jurisdictional information
    court_type TEXT,                      -- Type of court
    FOREIGN KEY (case_id) REFERENCES cases(id)
);
```

**Extracted Information:**
- **Legal Context**: District, judge, case numbers
- **Timeline**: Filing and sentencing dates
- **Jurisdiction**: Court type and jurisdictional details

### 2. Participants

Individuals and organizations involved in cases:

```sql
CREATE TABLE participants (
    participant_id INTEGER PRIMARY KEY AUTOINCREMENT,
    case_id TEXT,                         -- Foreign key to cases table
    name TEXT,                           -- Participant name
    role TEXT,                           -- Role (defendant, prosecutor, agent, etc.)
    organization TEXT,                   -- Associated organization
    title TEXT,                          -- Job title or position
    location TEXT,                       -- Geographic location
    FOREIGN KEY (case_id) REFERENCES cases(id)
);
```

**Participant Types:**
- **Defendants**: Individuals charged with violations
- **Prosecutors**: Assistant U.S. Attorneys and legal team
- **Investigators**: FBI agents, IRS-CI agents, other law enforcement
- **Witnesses**: Individuals providing testimony
- **Victims**: Individuals or organizations harmed

### 3. Case Agencies

Investigating and prosecuting agencies:

```sql
CREATE TABLE case_agencies (
    agency_id INTEGER PRIMARY KEY AUTOINCREMENT,
    case_id TEXT,                        -- Foreign key to cases table
    agency_name TEXT,                    -- Agency name (FBI, IRS-CI, etc.)
    role TEXT,                          -- Role in investigation
    location TEXT,                      -- Agency location
    contact_info TEXT,                  -- Contact information
    FOREIGN KEY (case_id) REFERENCES cases(id)
);
```

**Common Agencies:**
- **FBI**: Federal Bureau of Investigation
- **IRS-CI**: Internal Revenue Service Criminal Investigation
- **DEA**: Drug Enforcement Administration
- **HSI**: Homeland Security Investigations
- **USPIS**: U.S. Postal Inspection Service

### 4. Charges

Legal charges and statutes:

```sql
CREATE TABLE charges (
    charge_id INTEGER PRIMARY KEY AUTOINCREMENT,
    case_id TEXT,                       -- Foreign key to cases table
    statute TEXT,                       -- Legal statute (18 USC 1960, etc.)
    charge_description TEXT,            -- Detailed charge description
    severity TEXT,                      -- Charge severity (felony, misdemeanor)
    count INTEGER,                      -- Number of counts
    FOREIGN KEY (case_id) REFERENCES cases(id)
);
```

**Charge Types:**
- **Primary Charges**: 18 USC 1960 violations
- **Related Charges**: Money laundering, wire fraud, etc.
- **Conspiracy Charges**: Conspiracy to commit violations
- **Aiding and Abetting**: Secondary liability charges

### 5. Financial Actions

Monetary penalties and financial outcomes:

```sql
CREATE TABLE financial_actions (
    fin_id INTEGER PRIMARY KEY AUTOINCREMENT,
    case_id TEXT,                       -- Foreign key to cases table
    action_type TEXT,                   -- Type (forfeiture, fine, restitution)
    amount DECIMAL(15,2),               -- Dollar amount
    currency TEXT,                      -- Currency type
    description TEXT,                   -- Detailed description
    recipient TEXT,                     -- Who receives the funds
    FOREIGN KEY (case_id) REFERENCES cases(id)
);
```

**Financial Action Types:**
- **Forfeiture**: Assets seized by the government
- **Fines**: Monetary penalties imposed
- **Restitution**: Payments to victims
- **Civil Penalties**: Administrative fines

### 6. Victims

Individuals or organizations harmed by violations:

```sql
CREATE TABLE victims (
    victim_id INTEGER PRIMARY KEY AUTOINCREMENT,
    case_id TEXT,                       -- Foreign key to cases table
    name TEXT,                          -- Victim name/organization
    type TEXT,                          -- Type (individual, business, government)
    location TEXT,                      -- Geographic location
    harm_description TEXT,              -- Description of harm suffered
    loss_amount DECIMAL(15,2),          -- Financial loss amount
    FOREIGN KEY (case_id) REFERENCES cases(id)
);
```

**Victim Categories:**
- **Individual Victims**: People defrauded or harmed
- **Business Victims**: Companies affected by violations
- **Government Victims**: Tax revenue or regulatory violations
- **Financial Institutions**: Banks or payment processors

### 7. Quotes

Notable statements from officials and participants:

```sql
CREATE TABLE quotes (
    quote_id INTEGER PRIMARY KEY AUTOINCREMENT,
    case_id TEXT,                       -- Foreign key to cases table
    speaker TEXT,                       -- Person making the statement
    speaker_title TEXT,                 -- Speaker's title/position
    quote_text TEXT,                    -- The actual quote
    context TEXT,                       -- Context of the statement
    FOREIGN KEY (case_id) REFERENCES cases(id)
);
```

**Quote Sources:**
- **DOJ Officials**: Press release statements
- **Prosecutors**: Legal commentary
- **Investigators**: Law enforcement statements
- **Defendants**: Statements from charged individuals

### 8. Themes

Thematic tags and categories:

```sql
CREATE TABLE themes (
    theme_id INTEGER PRIMARY KEY AUTOINCREMENT,
    case_id TEXT,                       -- Foreign key to cases table
    theme_name TEXT,                    -- Theme name
    theme_category TEXT,                -- Category (scam_type, technology, etc.)
    description TEXT,                   -- Theme description
    relevance_score INTEGER,            -- Relevance score (1-10)
    FOREIGN KEY (case_id) REFERENCES cases(id)
);
```

**Theme Categories:**
- **Scam Types**: Romance scams, investment fraud, etc.
- **Technology**: Cryptocurrency, darknet, mobile apps
- **Geography**: International operations, specific regions
- **Industry**: Financial services, e-commerce, etc.

## 🔄 Table Relationships

### Entity Relationship Diagram

```
cases (1) ──── (many) case_metadata
   │
   ├── (many) participants
   ├── (many) case_agencies
   ├── (many) charges
   ├── (many) financial_actions
   ├── (many) victims
   ├── (many) quotes
   ├── (many) themes
   └── (many) enrichment_activity_log
```

### Relationship Rules

1. **One-to-Many**: Each case can have multiple records in enrichment tables
2. **Foreign Key Constraints**: All enrichment tables reference `cases.id`
3. **Cascade Behavior**: Case deletion would cascade to enrichment records
4. **Data Integrity**: Foreign keys ensure referential integrity

## 📈 Data Volume Statistics

### Current Database Size (January 2025)
- **Total Cases**: ~3,653 press releases
- **1960 Mentions**: ~935 cases
- **Verified 1960**: ~2,860 cases
- **Enrichment Progress**: Varies by table (see dashboard)

### Table Sizes
- **cases**: ~3,653 rows
- **case_metadata**: ~500+ rows (enrichment in progress)
- **participants**: ~1,200+ rows (enrichment in progress)
- **charges**: ~800+ rows (enrichment in progress)
- **enrichment_activity_log**: ~5,000+ rows

## 🔍 Query Patterns

### Common Queries

#### 1. Find All 1960 Cases
```sql
SELECT * FROM cases 
WHERE mentions_1960 = 1 AND verified_1960 = 1
ORDER BY date DESC;
```

#### 2. Get Case with All Enrichment Data
```sql
SELECT 
    c.*,
    cm.district, cm.judge, cm.case_number,
    p.name as participant_name, p.role as participant_role,
    ch.statute, ch.charge_description,
    fa.action_type, fa.amount
FROM cases c
LEFT JOIN case_metadata cm ON c.id = cm.case_id
LEFT JOIN participants p ON c.id = p.case_id
LEFT JOIN charges ch ON c.id = ch.case_id
LEFT JOIN financial_actions fa ON c.id = fa.case_id
WHERE c.id = ?;
```

#### 3. Enrichment Progress by Table
```sql
SELECT 
    table_name,
    COUNT(DISTINCT case_id) as processed_cases,
    (SELECT COUNT(*) FROM cases WHERE mentions_1960 = 1) as total_1960_cases
FROM enrichment_activity_log 
WHERE status = 'success'
GROUP BY table_name;
```

#### 4. Financial Impact Analysis
```sql
SELECT 
    c.title,
    SUM(fa.amount) as total_financial_impact,
    COUNT(fa.fin_id) as number_of_actions
FROM cases c
JOIN financial_actions fa ON c.id = fa.case_id
WHERE c.verified_1960 = 1
GROUP BY c.id, c.title
ORDER BY total_financial_impact DESC;
```

## 🛠️ Database Management

### Schema Migration

The system includes automated schema migration tools:

```bash
# Run schema migration
python migrate_schemas.py

# Check database status
python check_db.py

# Rebuild enrichment tables
python check_db.py --rebuild
```

### Backup and Recovery

```bash
# Create database backup
cp doj_cases.db doj_cases_backup_$(date +%Y%m%d).db

# Restore from backup
cp doj_cases_backup_20250127.db doj_cases.db
```

### Performance Optimization

#### Indexes
```sql
-- Create indexes for common queries
CREATE INDEX IF NOT EXISTS idx_cases_mentions_1960 ON cases(mentions_1960);
CREATE INDEX IF NOT EXISTS idx_cases_classification ON cases(classification);
CREATE INDEX IF NOT EXISTS idx_cases_date ON cases(date);
CREATE INDEX IF NOT EXISTS idx_enrichment_case_id ON enrichment_activity_log(case_id);
```

#### Query Optimization
- **Pagination**: Use `LIMIT` and `OFFSET` for large result sets
- **Selective Columns**: Only select needed columns
- **Join Optimization**: Use appropriate join types
- **Index Usage**: Ensure queries use available indexes

## 🔒 Data Integrity

### Constraints
- **Primary Keys**: All tables have unique primary keys
- **Foreign Keys**: Enrichment tables reference cases table
- **NOT NULL**: Critical fields marked as required
- **Data Types**: Appropriate types for each field

### Validation
- **Input Validation**: All data validated before insertion
- **Type Checking**: Automatic type conversion and validation
- **Constraint Enforcement**: Database-level constraint checking
- **Error Handling**: Graceful handling of constraint violations

## 📊 Data Quality

### Quality Measures
- **Completeness**: Percentage of cases with enrichment data
- **Accuracy**: Manual review of AI-extracted data
- **Consistency**: Standardized formats and values
- **Timeliness**: Regular updates and processing

### Quality Monitoring
- **Enrichment Logs**: Track success/failure rates
- **Data Validation**: Automated checks for data quality
- **Manual Review**: Periodic human review of extracted data
- **Error Tracking**: Monitor and address extraction errors

---

*This schema provides a robust foundation for storing and analyzing DOJ press release data with comprehensive enrichment capabilities.* 