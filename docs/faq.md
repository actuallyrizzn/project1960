# Frequently Asked Questions (FAQ)

This FAQ addresses the most common questions about Project1960. If you don't find your answer here, check the [Troubleshooting Guide](troubleshooting.md) or create a new issue on GitHub.

## 🤔 General Questions

### What is Project1960?

Project1960 is a sophisticated legal data analysis system that tracks and analyzes Department of Justice prosecutions under **18 U.S.C. § 1960** (money transmission without a license), with a particular focus on cryptocurrency-related cases.

The system:
- **Scrapes** DOJ press releases automatically
- **Classifies** cases using AI to identify 1960 violations
- **Enriches** data by extracting structured information
- **Provides** a modern web interface for exploration

### What is 18 USC 1960?

**18 U.S.C. § 1960** is a federal law that prohibits operating an unlicensed money transmitting business. It applies when:

✅ A business transmits money for others without a required state or federal license
✅ A person or entity moves funds without registering with FinCEN
✅ The money transmission activity is linked to illicit activities

It does **NOT** apply to:
❌ General fraud, tax evasion, or theft (unless money transmission is involved)
❌ Bank fraud, securities fraud, or insurance fraud (without money transmission aspect)
❌ Cases where money movement is internal corporate transfers

### Why focus on cryptocurrency cases?

Cryptocurrency has become a major focus of federal enforcement because:
- **Regulatory gaps** in early cryptocurrency regulation
- **Cross-border nature** of cryptocurrency transactions
- **Anonymity features** that can facilitate illicit activity
- **High-profile cases** involving large sums of money

### Is this project affiliated with the DOJ?

**No.** Project1960 is an independent research project that analyzes publicly available DOJ press releases. We are not affiliated with, endorsed by, or connected to the Department of Justice or any government agency.

## 💻 Technical Questions

### What technology stack does Project1960 use?

**Backend:**
- **Python 3.8+** - Core programming language
- **Flask** - Web framework
- **SQLite** - Database
- **Venice AI API** - AI/ML services

**Frontend:**
- **Bootstrap 5** - CSS framework
- **HTML5/CSS3/JavaScript** - Web technologies

**Data Processing:**
- **BeautifulSoup4** - Web scraping
- **Requests** - HTTP client
- **Pandas** - Data manipulation
- **DirtyJSON** - JSON parsing

### How accurate is the AI classification?

The AI classification system uses Venice AI's advanced language models with:
- **High confidence thresholds** (85%+ for yes/no decisions)
- **Multiple model fallback** for complex cases
- **Robust error handling** and validation
- **Continuous improvement** through prompt engineering

Accuracy varies by case complexity, but the system is designed to be conservative to avoid false positives.

### How much data does the system process?

**Current Database (January 2025):**
- **~3,653** total DOJ press releases
- **~935** cases mentioning 18 USC 1960
- **~2,860** verified 1960 cases
- **~1,200** cryptocurrency-related cases

**Processing Capacity:**
- Can handle thousands of press releases
- Processes ~100-200 cases per hour (depending on complexity)
- Scales automatically with available resources

### How much does it cost to run?

**Free Components:**
- ✅ Open-source code (no licensing fees)
- ✅ Local database storage
- ✅ Web interface hosting

**Paid Components:**
- 💰 **Venice AI API** - Pay-per-use pricing
  - ~$0.15-3.50 per API call (depending on model)
  - Typical cost: $50-200/month for active use
  - Free tier available for testing

**Total Cost:** Typically $50-200/month for active research use.

## 🔧 Setup and Installation

### What are the system requirements?

**Minimum Requirements:**
- **OS**: Windows 10+, macOS 10.14+, or Linux (Ubuntu 18.04+)
- **Python**: 3.8 or higher
- **RAM**: 4GB (8GB recommended)
- **Storage**: 2GB free space
- **Network**: Internet connection

**Recommended:**
- **RAM**: 8GB or more
- **Storage**: SSD preferred
- **CPU**: Multi-core processor
- **Network**: Stable broadband connection

### How long does installation take?

**Quick Setup (5-10 minutes):**
1. Clone repository: 1-2 minutes
2. Install dependencies: 2-3 minutes
3. Configure API key: 1-2 minutes
4. Test installation: 1-2 minutes

**First Data Collection (30-60 minutes):**
1. Scrape DOJ press releases: 10-20 minutes
2. Classify cases: 20-40 minutes
3. Initial enrichment: 10-20 minutes

### Do I need programming experience?

**No programming experience required** for basic usage:
- ✅ Web interface is user-friendly
- ✅ Command-line tools have clear instructions
- ✅ Documentation covers all common tasks

**Programming helpful for:**
- 🔧 Custom modifications
- 🔧 Advanced data analysis
- 🔧 Contributing to the project

### Can I run this without an API key?

**Limited functionality without API key:**
- ✅ View existing data in web interface
- ✅ Browse already processed cases
- ✅ Use database management tools

**Requires API key for:**
- 🔧 Scraping new press releases
- 🔧 AI classification of cases
- 🔧 Data enrichment processing

## 📊 Data and Usage

### How often is the data updated?

**Manual Updates:**
- Run `python scraper.py` to collect new press releases
- Run `python 1960-verify_modular.py` to classify new cases
- Run enrichment scripts to extract structured data

**Automated Updates:**
- Can be set up with cron jobs for daily/weekly updates
- See [Cron Setup Guide](cron-setup.md) for automation

### How accurate is the extracted data?

**Data Quality:**
- **High accuracy** for structured fields (dates, names, amounts)
- **Good accuracy** for complex extraction (charges, relationships)
- **Variable accuracy** for subjective content (themes, quotes)

**Quality Assurance:**
- Multiple validation layers
- Error logging and monitoring
- Manual review capabilities
- Continuous improvement through feedback

### Can I export the data?

**Current Export Options:**
- ✅ **Web Interface**: Browse and view data
- ✅ **Database Queries**: Use `check_db.py --query`
- ✅ **CSV Export**: Available through web interface
- ✅ **JSON API**: RESTful endpoints for programmatic access

**Future Plans:**
- 📋 Bulk CSV/JSON export
- 📋 API documentation
- 📋 Data visualization tools

### Is the data publicly available?

**Current Status:**
- 🔒 **Private repository** - Code and documentation
- 🔒 **Local database** - Data stored locally
- 🔒 **No public API** - No external data access

**Future Plans:**
- 📋 Public dataset releases
- 📋 API for researchers
- 📋 Open data initiatives

## 🔒 Privacy and Security

### What data is collected?

**Public Data Only:**
- ✅ DOJ press releases (publicly available)
- ✅ Case information (public court records)
- ✅ No personal information beyond what's in press releases

**No Collection Of:**
- ❌ Private personal data
- ❌ Financial information
- ❌ Sensitive case details
- ❌ User personal information

### Is my API key secure?

**Security Measures:**
- ✅ Stored in local `.env` file (not in code)
- ✅ Never committed to version control
- ✅ Environment variable isolation
- ✅ No logging of API keys

**Best Practices:**
- 🔒 Keep `.env` file secure
- 🔒 Rotate API keys regularly
- 🔒 Monitor API usage
- 🔒 Use different keys for development/production

### Can others access my data?

**Local Installation:**
- ✅ Data stored locally on your machine
- ✅ No external data transmission
- ✅ Full control over your data

**Network Access:**
- 🔒 Web interface accessible only locally by default
- 🔒 Can be configured for network access if needed
- 🔒 No automatic external sharing

## 🚀 Advanced Usage

### Can I customize the AI prompts?

**Yes, full customization available:**
- 📝 Edit `modules/enrichment/prompts.py` for enrichment prompts
- 📝 Edit `modules/verification/classifier.py` for classification prompts
- 📝 Modify system prompts for different use cases
- 📝 Add new extraction categories

### Can I add new data sources?

**Extensible Architecture:**
- 🔧 Add new scrapers in `scraper.py`
- 🔧 Create new enrichment tables
- 🔧 Integrate additional APIs
- 🔧 Support for multiple data formats

### Can I run this in production?

**Production Considerations:**
- ✅ **Database**: Consider PostgreSQL for large scale
- ✅ **Web Server**: Use Nginx/Apache with WSGI
- ✅ **Process Management**: Use systemd/supervisor
- ✅ **Monitoring**: Add logging and metrics
- ✅ **Backup**: Regular database backups

**See [Production Deployment Guide](production.md) for details.**

### Can I contribute to the project?

**Yes! We welcome contributions:**
- 🐛 **Bug Reports**: Create GitHub issues
- 💡 **Feature Requests**: Suggest improvements
- 🔧 **Code Contributions**: Submit pull requests
- 📚 **Documentation**: Help improve docs
- 🧪 **Testing**: Test and report issues

**See [Development Guide](development.md) for contribution guidelines.**

## 📈 Performance and Scaling

### How fast is the processing?

**Typical Performance:**
- **Scraping**: ~100-200 press releases per minute
- **Classification**: ~50-100 cases per hour
- **Enrichment**: ~20-50 cases per hour (per table)
- **Web Interface**: Sub-second response times

**Factors Affecting Speed:**
- API rate limits
- Document complexity
- System resources
- Network connectivity

### Can it handle large datasets?

**Current Capacity:**
- ✅ **Thousands** of press releases
- ✅ **Hundreds** of enrichment tables
- ✅ **Millions** of data points

**Scaling Options:**
- 🔧 **Database**: Migrate to PostgreSQL/MySQL
- 🔧 **Processing**: Parallel processing
- 🔧 **Storage**: Cloud storage integration
- 🔧 **API**: Multiple API providers

### What are the API rate limits?

**Venice AI Limits:**
- **Free Tier**: Limited requests per day
- **Paid Plans**: Higher limits based on plan
- **Rate Limiting**: Requests per minute/second
- **Model Availability**: Some models may be temporarily unavailable

**Best Practices:**
- 🔧 Implement retry logic
- 🔧 Use multiple API providers
- 🔧 Cache responses when possible
- 🔧 Monitor usage and costs

## 🆘 Support and Help

### Where can I get help?

**Documentation:**
- 📚 [Quick Start Guide](quick-start.md)
- 📚 [Installation Guide](installation.md)
- 📚 [Troubleshooting Guide](troubleshooting.md)
- 📚 [CLI Tools Guide](cli-tools.md)

**Community:**
- 💬 GitHub Issues
- 💬 GitHub Discussions
- 💬 Project Wiki

### How do I report bugs?

**Bug Report Process:**
1. **Search** existing issues first
2. **Create** new issue with detailed information
3. **Include** error messages and stack traces
4. **Provide** steps to reproduce
5. **Attach** diagnostic information

**Required Information:**
- Operating system and version
- Python version
- Error messages
- Steps to reproduce
- Expected vs actual behavior

### Can I request new features?

**Feature Request Process:**
1. **Check** existing feature requests
2. **Create** new issue with detailed description
3. **Explain** use case and benefits
4. **Provide** examples if possible
5. **Discuss** implementation approach

**Popular Requests:**
- 📋 Additional data sources
- 📋 New enrichment categories
- 📋 Export functionality
- 📋 API endpoints
- 📋 Visualization tools

### Is there a community or forum?

**Current Community:**
- 💬 **GitHub Issues**: Bug reports and feature requests
- 💬 **GitHub Discussions**: General questions and discussions
- 💬 **Project Wiki**: Community-contributed content

**Future Plans:**
- 📋 Discord/Slack community
- 📋 Regular community calls
- 📋 User meetups
- 📋 Conference presentations

---

*This FAQ covers the most common questions about Project1960. If you have additional questions, please create a GitHub issue or check the other documentation guides.* 