# Web Interface Guide

Project1960 provides a modern, responsive web interface built with Flask and Bootstrap 5. This guide covers all aspects of the web application, from navigation to advanced features.

## 🌐 Accessing the Interface

### Local Development
```bash
python app.py
```
Visit: [http://localhost:5000](http://localhost:5000)

### Production
The interface is typically available at your server's domain or IP address.

## 🏠 Dashboard (Home Page)

**URL**: `/` or `/index`

The main dashboard provides an overview of the entire system with key statistics and quick access to important features.

### Key Features

#### Statistics Cards
- **Total Cases**: Number of DOJ press releases in the database
- **Verified 1960**: Cases confirmed to involve 18 USC 1960 violations
- **Mentions Crypto**: Cases mentioning cryptocurrency terms
- **Unprocessed 1960**: Cases that mention 1960 but haven't been verified

#### Enrichment Progress
Visual progress bars showing data extraction status across all 8 enrichment tables:
- `case_metadata` - Core case details
- `participants` - People and organizations involved
- `case_agencies` - Investigating agencies
- `charges` - Legal charges and statutes
- `financial_actions` - Monetary penalties
- `victims` - Individuals or organizations harmed
- `quotes` - Notable statements
- `themes` - Thematic tags

#### Quick Actions
- **Browse Cases**: Direct link to case browser
- **Enrichment Dashboard**: View detailed enrichment progress
- **About**: Project information and methodology

### Dashboard Layout

```
┌─────────────────────────────────────────────────────────────┐
│                    Navigation Bar                           │
│  [Project1960] [Dashboard] [Cases] [Enrichment] [About] [🌙]│
└─────────────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────────────┐
│                    Welcome Section                          │
│  Project1960 Dashboard                                      │
│  Explore and analyze Department of Justice press releases   │
│  for 18 USC 1960 violations                                 │
└─────────────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────────────┐
│                    Statistics Cards                         │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐        │
│  │   Total     │  │  Verified   │  │   Crypto    │        │
│  │   Cases     │  │    1960     │  │  Mentions   │        │
│  │   3,653     │  │   2,860     │  │    1,200    │        │
│  └─────────────┘  └─────────────┘  └─────────────┘        │
└─────────────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────────────┐
│                    Enrichment Progress                      │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ case_metadata: ████████████████████░░░░ 80%        │   │
│  │ participants:   ████████████████████████ 100%      │   │
│  │ charges:        ████████████████░░░░░░░░ 60%        │   │
│  │ ...             ████████████████████████ 100%      │   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

## 📋 Cases Browser

**URL**: `/cases`

The cases browser provides comprehensive filtering, search, and pagination for exploring all DOJ press releases in the database.

### Filtering Options

#### Search Filter
- **Text Search**: Search across titles and content
- **Real-time Filtering**: Results update as you type
- **Case-insensitive**: Searches are not case-sensitive

#### Classification Filter
- **All**: Show all cases regardless of classification
- **Yes (1960)**: Only cases verified as 1960 violations
- **No**: Cases that don't involve 1960 violations
- **Unknown**: Cases that haven't been classified yet

#### Mention Filters
- **Mentions 1960**: Filter by whether text mentions 18 USC 1960
- **Mentions Crypto**: Filter by cryptocurrency mentions

### Case List Features

#### Case Information Display
Each case shows:
- **Title**: Press release title (clickable)
- **Date**: Publication date
- **Classification**: Yes/No/Unknown badge
- **Mentions**: 1960 and crypto mention indicators
- **Enrichment Status**: Visual indicators for enrichment progress

#### Pagination
- **20 cases per page** by default
- **Navigation controls**: Previous/Next buttons
- **Page numbers**: Direct page navigation
- **Results counter**: Shows current range and total

#### Sorting
- **Default**: Most recent cases first
- **Date-based**: Chronological ordering
- **Title-based**: Alphabetical ordering

### Case List Example

```
┌─────────────────────────────────────────────────────────────┐
│                    Filter Controls                          │
│  [Search: "money transmitter"] [Classification: All] [1960: All] [Crypto: All] │
└─────────────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────────────┐
│                    Cases List                               │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ DOJ Announces Money Transmitter Charges             │   │
│  │ 2025-01-15 | ✅ Yes | 🎯 1960 | 💰 Crypto          │   │
│  │ ████████████████████████████████████████████████████│   │
│  └─────────────────────────────────────────────────────┘   │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ Cryptocurrency Exchange Operator Sentenced         │   │
│  │ 2025-01-14 | ❌ No  | 🎯 1960 | 💰 Crypto          │   │
│  │ ████████████████████████████████████████████████████│   │
│  └─────────────────────────────────────────────────────┘   │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ International Money Laundering Scheme Uncovered    │   │
│  │ 2025-01-13 | ❓ Unknown | 🎯 1960 | 💰 Crypto      │   │
│  │ ████████████████████████████████████████████████████│   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────────────┐
│                    Pagination                               │
│  [Previous] [1] [2] [3] ... [183] [Next]                   │
│  Showing 1-20 of 3,653 cases                               │
└─────────────────────────────────────────────────────────────┘
```

## 📄 Case Detail Page

**URL**: `/case/<case_id>`

Individual case pages provide comprehensive information about each DOJ press release, including all extracted enrichment data.

### Page Layout

#### Header Section
- **Case Title**: Full press release title
- **Publication Date**: Formatted date
- **Classification Status**: Visual badge (Yes/No/Unknown)
- **Breadcrumb Navigation**: Dashboard → Cases → Case Detail

#### Content Sections

##### 1. Press Release Content
- **Full Text**: Complete press release body
- **Original URL**: Link to DOJ website
- **Metadata**: Component, topic, case number

##### 2. Enrichment Data
Organized into collapsible sections:

###### Case Metadata
- **District**: Federal district
- **Judge**: Presiding judge
- **Case Number**: Official case number
- **Filing Date**: When case was filed
- **Sentencing Date**: When sentencing occurred

###### Participants
- **Name**: Person or organization name
- **Role**: Defendant, prosecutor, agent, etc.
- **Organization**: Associated agency or company
- **Title**: Job title or position
- **Location**: Geographic location

###### Case Agencies
- **Agency Name**: FBI, IRS-CI, DEA, etc.
- **Role**: Lead investigator, supporting agency
- **Location**: Agency location
- **Contact Info**: Contact information

###### Charges
- **Statute**: Legal statute (18 USC 1960, etc.)
- **Description**: Detailed charge description
- **Severity**: Felony, misdemeanor
- **Count**: Number of counts

###### Financial Actions
- **Type**: Forfeiture, fine, restitution
- **Amount**: Dollar amount
- **Currency**: USD, etc.
- **Description**: Detailed description
- **Recipient**: Who receives the funds

###### Victims
- **Name**: Victim name/organization
- **Type**: Individual, business, government
- **Location**: Geographic location
- **Harm Description**: Description of harm
- **Loss Amount**: Financial loss

###### Quotes
- **Speaker**: Person making statement
- **Title**: Speaker's position
- **Quote**: The actual statement
- **Context**: Context of statement

###### Themes
- **Theme Name**: Thematic tag
- **Category**: Scam type, technology, geography
- **Description**: Theme description
- **Relevance Score**: 1-10 relevance rating

### Case Detail Example

```
┌─────────────────────────────────────────────────────────────┐
│                    Case Header                              │
│  DOJ Announces Money Transmitter Charges                   │
│  2025-01-15 | ✅ Verified 1960 Case                        │
│  [Dashboard] > [Cases] > Case Detail                       │
└─────────────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────────────┐
│                    Press Release                            │
│  The Department of Justice today announced charges...      │
│  [Full press release text]                                 │
│  Original: https://www.justice.gov/news/...                │
└─────────────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────────────┐
│                    Enrichment Data                         │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐        │
│  │ Case        │  │ Participants│  │ Agencies    │        │
│  │ Metadata    │  │             │  │             │        │
│  │ [Expand]    │  │ [Expand]    │  │ [Expand]    │        │
│  └─────────────┘  └─────────────┘  └─────────────┘        │
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐        │
│  │ Charges     │  │ Financial   │  │ Victims     │        │
│  │             │  │ Actions     │  │             │        │
│  │ [Expand]    │  │ [Expand]    │  │ [Expand]    │        │
│  └─────────────┘  └─────────────┘  └─────────────┘        │
│  ┌─────────────┐  ┌─────────────┐                        │
│  │ Quotes      │  │ Themes      │                        │
│  │             │  │             │                        │
│  │ [Expand]    │  │ [Expand]    │                        │
│  └─────────────┘  └─────────────┘                        │
└─────────────────────────────────────────────────────────────┘
```

## 📊 Enrichment Dashboard

**URL**: `/enrichment`

The enrichment dashboard provides detailed monitoring of the AI-powered data extraction process, including progress tracking and activity logs.

### Dashboard Features

#### Overall Statistics
- **Total 1960 Cases**: Number of cases available for enrichment
- **Overall Progress**: Percentage of cases processed across all tables
- **Success Rate**: Percentage of successful enrichments

#### Table Progress
Detailed progress for each enrichment table:
- **Progress Bar**: Visual representation of completion
- **Case Count**: Number of cases processed
- **Percentage**: Completion percentage
- **Status**: Active, complete, or pending

#### Activity Log
Real-time log of enrichment operations:
- **Timestamp**: When operation occurred
- **Case ID**: Clickable link to case detail
- **Table**: Target enrichment table
- **Status**: Success, Error, or Skipped
- **Notes**: Additional details or error messages

### Enrichment Dashboard Example

```
┌─────────────────────────────────────────────────────────────┐
│                    Enrichment Overview                      │
│  Total 1960 Cases: 2,860 | Overall Progress: 75%           │
│  Success Rate: 92% | Active Tables: 3                      │
└─────────────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────────────┐
│                    Table Progress                           │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ case_metadata: ████████████████████░░░░ 80% (2,288)│   │
│  │ participants:   ████████████████████████ 100% (2,860)│   │
│  │ charges:        ████████████████░░░░░░░░ 60% (1,716)│   │
│  │ financial_actions: ████████████████████████ 100% (2,860)│   │
│  │ victims:        ████████████░░░░░░░░░░░░ 40% (1,144)│   │
│  │ quotes:         ████████████████████████ 100% (2,860)│   │
│  │ themes:         ████████████████████████ 100% (2,860)│   │
│  │ case_agencies:  ████████████████████████ 100% (2,860)│   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
┌─────────────────────────────────────────────────────────────┐
│                    Activity Log                             │
│  ┌─────────────────────────────────────────────────────┐   │
│  │ 2025-01-27 14:30:15 | 12345 | case_metadata | ✅ Success│   │
│  │ 2025-01-27 14:30:12 | 12344 | participants | ✅ Success│   │
│  │ 2025-01-27 14:30:08 | 12343 | charges | ❌ Error: API timeout│   │
│  │ 2025-01-27 14:30:05 | 12342 | victims | ⏭️ Skipped: Already processed│   │
│  └─────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

## ℹ️ About Page

**URL**: `/about`

The about page provides comprehensive information about Project1960, including mission, methodology, and technical details.

### Page Content

#### Mission Statement
- **Purpose**: Track and analyze 18 USC 1960 prosecutions
- **Focus**: Cryptocurrency-related cases
- **Goal**: Create open dataset for researchers and policymakers

#### Methodology
- **Data Collection**: Automated DOJ press release scraping
- **AI Classification**: Venice AI-powered case identification
- **Data Enrichment**: Structured extraction of case details
- **Analysis**: Web interface for exploration and filtering

#### Technical Architecture
- **Backend**: Python, Flask, SQLite
- **AI/ML**: Venice AI API with multiple models
- **Frontend**: Bootstrap 5, responsive design
- **Data Processing**: Robust JSON parsing and validation

#### Database Schema
Visual representation of the relational database structure:
- **Central Table**: `cases` with raw press release data
- **Enrichment Tables**: 8 specialized tables for structured data
- **Relationships**: Foreign key connections between tables

#### Contact & Resources
- **GitHub Repository**: Link to source code
- **Documentation**: Setup guides and technical docs
- **Legal Reference**: 18 USC 1960 statute link

## 🎨 User Interface Features

### Dark Mode Toggle

**Location**: Top navigation bar (moon icon)

**Features:**
- **Toggle Switch**: Click to switch between light and dark themes
- **Persistent**: Preference saved in browser local storage
- **Automatic**: Remembers your choice across sessions
- **Responsive**: Works on all pages and screen sizes

### Responsive Design

**Mobile Support:**
- **Bootstrap 5**: Mobile-first responsive framework
- **Touch-friendly**: Optimized for touch interfaces
- **Readable**: Appropriate text sizes on small screens
- **Navigation**: Collapsible navigation on mobile

**Desktop Features:**
- **Wide Layout**: Optimized for larger screens
- **Hover Effects**: Interactive elements with hover states
- **Keyboard Navigation**: Full keyboard accessibility
- **Print-friendly**: Clean layout for printing

### Search and Filtering

**Real-time Search:**
- **Instant Results**: Updates as you type
- **Multiple Fields**: Searches titles and content
- **Case-insensitive**: Not affected by capitalization
- **Highlighting**: Search terms highlighted in results

**Advanced Filtering:**
- **Multiple Filters**: Combine search, classification, mentions
- **URL Parameters**: Filters preserved in URL for sharing
- **Reset Options**: Clear all filters easily
- **Visual Feedback**: Active filters clearly indicated

## 🔧 Technical Features

### Performance Optimization

**Database Queries:**
- **Efficient Indexing**: Optimized database indexes
- **Pagination**: Load only necessary data
- **Caching**: Browser caching for static assets
- **Lazy Loading**: Load enrichment data on demand

**Frontend Performance:**
- **Minified Assets**: Compressed CSS and JavaScript
- **CDN Resources**: Bootstrap and icons from CDN
- **Image Optimization**: Optimized images and icons
- **Progressive Enhancement**: Works without JavaScript

### Error Handling

**User-friendly Errors:**
- **Graceful Degradation**: System works even with errors
- **Clear Messages**: Understandable error descriptions
- **Recovery Options**: Suggestions for resolving issues
- **Logging**: Detailed error logging for debugging

**Data Validation:**
- **Input Sanitization**: All user inputs validated
- **SQL Injection Prevention**: Parameterized queries
- **XSS Prevention**: Template auto-escaping
- **CSRF Protection**: Built-in Flask security features

## 📱 Mobile Experience

### Mobile Navigation
- **Hamburger Menu**: Collapsible navigation on mobile
- **Touch Targets**: Appropriately sized buttons and links
- **Swipe Support**: Touch gestures for navigation
- **Viewport Optimization**: Proper mobile viewport settings

### Mobile Features
- **Responsive Tables**: Tables that work on small screens
- **Mobile Search**: Optimized search interface
- **Touch-friendly Filters**: Easy-to-use filter controls
- **Fast Loading**: Optimized for mobile networks

## 🔍 Advanced Features

### Keyboard Shortcuts
- **Navigation**: Arrow keys for pagination
- **Search**: Ctrl+F for page search
- **Accessibility**: Full keyboard navigation support
- **Shortcuts**: Common actions accessible via keyboard

### Data Export
- **CSV Export**: Export filtered results as CSV
- **JSON API**: RESTful API endpoints for data access
- **Print Support**: Clean print layouts
- **Share URLs**: Filtered views shareable via URL

### Accessibility
- **ARIA Labels**: Proper accessibility markup
- **Screen Reader Support**: Compatible with screen readers
- **High Contrast**: Works with high contrast themes
- **Keyboard Navigation**: Full keyboard accessibility

---

*The Project1960 web interface provides a comprehensive, user-friendly way to explore and analyze DOJ press release data with modern design and robust functionality.* 