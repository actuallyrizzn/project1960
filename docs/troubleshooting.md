# Troubleshooting Guide

This guide provides solutions to common issues you may encounter while using Project1960. Each section includes detailed problem descriptions, diagnostic steps, and resolution methods.

## 🔍 Quick Diagnostic Commands

Before diving into specific issues, run these diagnostic commands:

```bash
# Check Python version
python --version

# Check if virtual environment is active
echo $VIRTUAL_ENV  # Linux/macOS
echo %VIRTUAL_ENV% # Windows

# Check if .env file exists and has API key
ls -la .env
cat .env | grep VENICE_API_KEY

# Check database status
python check_db.py

# Check if all dependencies are installed
pip list | grep -E "(flask|requests|beautifulsoup4|dirtyjson)"
```

## 🐛 Common Issues

### API and Authentication Issues

#### "VENICE_API_KEY environment variable is not set!"

**Symptoms:**
- Error message when running enrichment or verification scripts
- Scripts fail immediately after starting

**Diagnosis:**
```bash
# Check if .env file exists
ls -la .env

# Check if API key is set
cat .env | grep VENICE_API_KEY

# Check environment variable
echo $VENICE_API_KEY  # Linux/macOS
echo %VENICE_API_KEY% # Windows
```

**Solutions:**

1. **Create .env file:**
```bash
cp env.example .env
```

2. **Add API key to .env file:**
```bash
# Edit .env file
nano .env  # Linux/macOS
notepad .env  # Windows

# Add this line:
VENICE_API_KEY=your_actual_api_key_here
```

3. **Set environment variable manually (temporary):**
```bash
export VENICE_API_KEY=your_api_key_here  # Linux/macOS
set VENICE_API_KEY=your_api_key_here     # Windows
```

4. **Verify API key is valid:**
- Visit [https://api.venice.ai](https://api.venice.ai)
- Check your account settings
- Generate a new key if needed

#### "API request failed" or "Connection timeout"

**Symptoms:**
- Scripts hang or fail with timeout errors
- Network-related error messages

**Diagnosis:**
```bash
# Test internet connectivity
ping api.venice.ai

# Test API endpoint
curl -H "Authorization: Bearer YOUR_API_KEY" https://api.venice.ai/api/v1/models
```

**Solutions:**

1. **Check internet connection:**
```bash
ping google.com
```

2. **Check firewall settings:**
- Ensure outbound HTTPS (port 443) is allowed
- Check if corporate firewall is blocking requests

3. **Increase timeout settings:**
```bash
# Edit utils/config.py to increase timeout
API_TIMEOUT = 300  # Increase from 120 to 300 seconds
```

4. **Use different network:**
- Try from different network (mobile hotspot, etc.)
- Check if VPN is interfering

### Database Issues

#### "Database is locked" or "database table is locked"

**Symptoms:**
- Scripts fail with SQLite locking errors
- Multiple processes trying to access database simultaneously

**Diagnosis:**
```bash
# Check for running processes
ps aux | grep python | grep -E "(enrich|verify|scraper)"

# Check database file permissions
ls -la doj_cases.db

# Check if database is corrupted
python check_db.py
```

**Solutions:**

1. **Kill stuck processes:**
```bash
# Find and kill Python processes
pkill -f "python.*enrich_cases"
pkill -f "python.*1960-verify"
pkill -f "python.*scraper"

# Or kill specific process IDs
kill -9 <PID>
```

2. **Restart terminal/command prompt:**
- Close all terminals
- Open new terminal
- Reactivate virtual environment

3. **Check for file locks (Windows):**
```cmd
# Check what's using the database file
handle doj_cases.db
```

4. **Rebuild database (last resort):**
```bash
# Backup current database
cp doj_cases.db doj_cases_backup_$(date +%Y%m%d).db

# Remove and recreate database
rm doj_cases.db
python enrich_cases_modular.py --setup-only
```

#### "No such table" or "table doesn't exist"

**Symptoms:**
- Database queries fail with table errors
- Enrichment scripts can't find expected tables

**Diagnosis:**
```bash
# Check database schema
python check_db.py

# List all tables
python check_db.py --query "SELECT name FROM sqlite_master WHERE type='table';"
```

**Solutions:**

1. **Create missing tables:**
```bash
python enrich_cases_modular.py --setup-only
```

2. **Run schema migration:**
```bash
python migrate_schemas.py
```

3. **Rebuild all tables:**
```bash
python check_db.py --rebuild
```

### Dependency and Installation Issues

#### "No module named 'dirtyjson'" or similar import errors

**Symptoms:**
- Python import errors when running scripts
- Missing package errors

**Diagnosis:**
```bash
# Check if virtual environment is active
which python  # Should point to venv directory

# Check installed packages
pip list

# Check specific package
pip show dirtyjson
```

**Solutions:**

1. **Activate virtual environment:**
```bash
source venv/bin/activate  # Linux/macOS
venv\Scripts\activate     # Windows
```

2. **Install missing dependencies:**
```bash
pip install -r requirements.txt
```

3. **Install specific package:**
```bash
pip install dirtyjson
```

4. **Upgrade pip and reinstall:**
```bash
pip install --upgrade pip
pip install -r requirements.txt --force-reinstall
```

#### "Microsoft Visual C++ required" (Windows)

**Symptoms:**
- Compilation errors during pip install
- Missing Visual C++ build tools

**Solutions:**

1. **Install Visual C++ Build Tools:**
- Download from Microsoft Visual Studio
- Install "Build Tools for Visual Studio"
- Select C++ build tools

2. **Use pre-compiled wheels:**
```bash
pip install --only-binary=all -r requirements.txt
```

3. **Use conda instead of pip:**
```bash
conda install -c conda-forge flask requests beautifulsoup4
```

### Web Interface Issues

#### "Flask app won't start" or "Port already in use"

**Symptoms:**
- `python app.py` fails to start
- Port 5000 already in use error

**Diagnosis:**
```bash
# Check what's using port 5000
netstat -tulpn | grep :5000  # Linux
netstat -an | findstr :5000  # Windows
lsof -i :5000                # macOS
```

**Solutions:**

1. **Use different port:**
```bash
export FLASK_PORT=5001
python app.py
```

2. **Kill process using port:**
```bash
# Find process ID
lsof -ti:5000

# Kill process
kill -9 $(lsof -ti:5000)
```

3. **Check for other Flask apps:**
```bash
ps aux | grep flask
pkill -f flask
```

#### "Web interface shows no data" or "empty dashboard"

**Symptoms:**
- Dashboard loads but shows 0 cases
- No data appears in case browser

**Diagnosis:**
```bash
# Check if database has data
python check_db.py --query "SELECT COUNT(*) FROM cases;"

# Check if scraper has been run
python check_db.py --query "SELECT MAX(date) FROM cases;"
```

**Solutions:**

1. **Run scraper to collect data:**
```bash
python scraper.py
```

2. **Verify some cases:**
```bash
python 1960-verify_modular.py --limit 10
```

3. **Check database file:**
```bash
ls -la doj_cases.db
```

### Performance Issues

#### "Scripts are very slow" or "taking too long"

**Symptoms:**
- Enrichment scripts take hours to complete
- API calls are slow or timing out

**Diagnosis:**
```bash
# Check system resources
htop
free -h
df -h

# Check API response times
python -c "import time; import requests; start=time.time(); requests.get('https://api.venice.ai/api/v1/models'); print(f'API response time: {time.time()-start:.2f}s')"
```

**Solutions:**

1. **Reduce batch size:**
```bash
python enrich_cases_modular.py --table case_metadata --limit 5
```

2. **Use verbose mode to monitor progress:**
```bash
python enrich_cases_modular.py --table participants --limit 10 --verbose
```

3. **Check API rate limits:**
- Monitor your Venice AI usage
- Consider upgrading API plan if needed

4. **Optimize system resources:**
```bash
# Close unnecessary applications
# Increase available RAM
# Use SSD storage if possible
```

#### "Memory errors" or "out of memory"

**Symptoms:**
- Python memory errors
- System becomes unresponsive

**Solutions:**

1. **Reduce batch processing:**
```bash
python enrich_cases_modular.py --table case_metadata --limit 1
```

2. **Monitor memory usage:**
```bash
# Linux/macOS
top -p $(pgrep python)

# Windows
tasklist | findstr python
```

3. **Increase swap space (Linux):**
```bash
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
```

### Data Quality Issues

#### "Enrichment data looks wrong" or "missing data"

**Symptoms:**
- AI extraction produces incorrect data
- Some cases have no enrichment data

**Diagnosis:**
```bash
# Check enrichment success rates
python check_db.py --query "SELECT table_name, status, COUNT(*) FROM enrichment_activity_log GROUP BY table_name, status;"

# Check specific case
python check_db.py --query "SELECT * FROM case_metadata WHERE case_id = 'YOUR_CASE_ID';"
```

**Solutions:**

1. **Re-run enrichment for specific case:**
```bash
python enrich_cases_modular.py --table case_metadata --case_number YOUR_CASE_ID --verbose
```

2. **Check enrichment logs:**
```bash
tail -f enrichment.log
```

3. **Verify API responses:**
```bash
# Test with dry run
python 1960-verify_modular.py --dry-run --limit 1 --verbose
```

4. **Check data validation:**
```bash
python check_db.py --query "SELECT * FROM enrichment_activity_log WHERE status = 'error' ORDER BY timestamp DESC LIMIT 10;"
```

## 🔧 Advanced Troubleshooting

### Debug Mode

Enable verbose logging for detailed debugging:

```bash
# Enable debug mode for all scripts
export DEBUG=1

# Run with verbose output
python enrich_cases_modular.py --table case_metadata --limit 1 --verbose

# Check detailed logs
tail -f enrichment.log
```

### Database Recovery

If database becomes corrupted:

```bash
# Create backup
cp doj_cases.db doj_cases_backup_$(date +%Y%m%d_%H%M%S).db

# Check database integrity
python check_db.py --query "PRAGMA integrity_check;"

# Rebuild database
python check_db.py --rebuild

# Restore data if needed
python scraper.py
python 1960-verify_modular.py --limit 100
```

### Network Diagnostics

For network-related issues:

```bash
# Test DNS resolution
nslookup api.venice.ai

# Test connectivity
curl -v https://api.venice.ai/api/v1/models

# Check proxy settings
echo $http_proxy
echo $https_proxy

# Test with different DNS
nslookup api.venice.ai 8.8.8.8
```

### System Diagnostics

For system-level issues:

```bash
# Check Python environment
python -c "import sys; print(sys.version); print(sys.executable)"

# Check package versions
pip freeze

# Check file permissions
ls -la *.py
ls -la doj_cases.db

# Check disk space
df -h
du -sh *
```

## 📞 Getting Additional Help

If you can't resolve an issue:

1. **Collect diagnostic information:**
```bash
# Create diagnostic report
python check_db.py > diagnostic_report.txt
echo "Python version: $(python --version)" >> diagnostic_report.txt
echo "OS: $(uname -a)" >> diagnostic_report.txt
pip freeze >> diagnostic_report.txt
```

2. **Check existing issues:**
- Search GitHub issues for similar problems
- Check the [FAQ](faq.md) for common questions

3. **Create detailed issue report:**
- Include error messages and stack traces
- Provide diagnostic information
- Describe steps to reproduce the issue
- Mention your operating system and Python version

4. **Contact support:**
- Create a new GitHub issue with detailed information
- Include diagnostic report and error logs

---

*This troubleshooting guide should help you resolve most common issues with Project1960. If you continue to experience problems, please create a detailed issue report with the diagnostic information requested above.* 