# Project1960 Documentation

Welcome to the comprehensive documentation for **Project1960** - a sophisticated legal data analysis system that tracks and analyzes Department of Justice prosecutions under 18 U.S.C. § 1960 (money transmission without a license), with a particular focus on cryptocurrency-related cases.

## 📚 Documentation Index

### Getting Started
- **[Quick Start Guide](quick-start.md)** - Get up and running in minutes
- **[Installation Guide](installation.md)** - Detailed setup instructions
- **[Configuration Guide](configuration.md)** - Environment setup and API configuration

### User Guides
- **[Web Interface Guide](web-interface.md)** - Using the Flask dashboard
- **[Command Line Tools](cli-tools.md)** - Using the enrichment and verification scripts
- **[Data Enrichment Guide](enrichment-guide.md)** - Understanding the AI-powered data extraction process

### Technical Documentation
- **[Architecture Overview](architecture.md)** - System design and modular structure
- **[Database Schema](database-schema.md)** - Complete database design and relationships
- **[API Documentation](api-docs.md)** - Venice AI API integration and usage
- **[Development Guide](development.md)** - Contributing and extending the system

### Operational Documentation
- **[Production Deployment](production.md)** - Deploying to production environments
- **[Monitoring & Logging](monitoring.md)** - System monitoring and troubleshooting
- **[Data Pipeline](data-pipeline.md)** - Understanding the complete data flow
- **[Cron Setup](cron-setup.md)** - Automated data collection and processing

### Reference
- **[Project History](project-history.md)** - Development timeline and milestones
- **[Technical Specifications](specifications.md)** - Detailed technical requirements
- **[Troubleshooting](troubleshooting.md)** - Common issues and solutions
- **[FAQ](faq.md)** - Frequently asked questions

## 🎯 Project Overview

Project1960 creates an open dataset for researchers, journalists, and policymakers to understand how federal authorities are enforcing money transmission laws in the digital age. The system:

- **Scrapes** DOJ press releases automatically and idempotently
- **Classifies** cases using AI to identify 18 USC 1960 violations
- **Enriches** data by extracting structured information from unstructured text
- **Provides** a modern web interface for exploration and analysis
- **Maintains** a comprehensive audit trail of all operations

## 🏗️ System Architecture

The project uses a **modular architecture** with clear separation of concerns:

```
Project1960/
├── utils/           # Core utilities (config, database, API client)
├── modules/         # Domain-specific logic (enrichment, verification)
├── orchestrators/   # Process coordination and workflow management
├── templates/       # Web interface templates
└── docs/           # This documentation
```

## 🚀 Key Features

- **AI-Powered Classification**: Uses Venice AI to identify 18 USC 1960 violations
- **Advanced Data Enrichment**: Extracts structured data into 8 relational tables
- **Modern Web Interface**: Bootstrap 5 dashboard with filtering and search
- **Robust Error Handling**: Comprehensive fallback systems and error recovery
- **Production Ready**: Database locking prevention, logging, and monitoring
- **Modular Design**: Clean, maintainable codebase with separated concerns

## 📊 Data Pipeline

1. **Data Collection**: Automated scraping of DOJ press releases
2. **Initial Classification**: AI-powered identification of 1960-related cases
3. **Data Enrichment**: Structured extraction of case details, participants, charges, etc.
4. **Storage**: SQLite database with relational schema
5. **Analysis**: Web interface for exploration and filtering

## 🔧 Technology Stack

- **Backend**: Python 3.8+, Flask, SQLite
- **AI/ML**: Venice AI API (qwen-2.5-qwq-32b, qwen3-235b, deepseek-r1-671b)
- **Frontend**: Bootstrap 5, HTML5, CSS3, JavaScript
- **Data Processing**: pandas, beautifulsoup4, rapidfuzz
- **JSON Parsing**: dirtyjson, custom robust parsing utilities

## 📈 Current Status

- ✅ **Core System**: Fully functional with modular architecture
- ✅ **Data Collection**: Automated DOJ press release scraping
- ✅ **AI Classification**: Robust 1960 violation identification
- ✅ **Data Enrichment**: 8-table relational data extraction
- ✅ **Web Interface**: Modern dashboard with filtering and search
- ✅ **Production Ready**: Comprehensive error handling and monitoring
- ✅ **Documentation**: Complete technical and user documentation

## 🤝 Contributing

See the [Development Guide](development.md) for information on contributing to Project1960.

## 📄 License

This project is licensed under the **Creative Commons Attribution-ShareAlike 4.0 International License** (CC BY-SA 4.0). See the [LICENSE](../LICENSE) file for details.

## 📞 Contact & Resources

- **GitHub Repository**: [https://github.com/actuallyrizzn/project1960](https://github.com/actuallyrizzn/project1960)
- **Main Dashboard**: [http://localhost:5000](http://localhost:5000) (when running locally)
- **18 USC 1960 Statute**: [https://www.law.cornell.edu/uscode/text/18/1960](https://www.law.cornell.edu/uscode/text/18/1960)

---

*Last updated: January 27, 2025* 