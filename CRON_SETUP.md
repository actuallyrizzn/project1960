# Cron Setup Guide for Progressive Enrichment

This guide explains how to set up automatic enrichment processing on your Ubuntu server using the modular architecture.

## Quick Setup

### 1. Test the script manually
```bash
# Test in dry-run mode with modular scripts
python3 enrich_cases_modular.py --table case_metadata --limit 5 --dry-run
```

### 2. Set up cron job
Edit your crontab:
```bash
crontab -e
```

Add one of these schedules:

#### Option A: Run every 6 hours
```bash
0 */6 * * * cd /path/to/your/project && python3 enrich_cases_modular.py --table case_metadata --limit 20 >> logs/enrichment_$(date +\%Y\%m\%d).log 2>&1
```

#### Option B: Run twice daily (6 AM and 6 PM)
```bash
0 6,18 * * * cd /path/to/your/project && python3 enrich_cases_modular.py --table case_metadata --limit 20 >> logs/enrichment_$(date +\%Y\%m\%d).log 2>&1
```

#### Option C: Run daily at 2 AM
```bash
0 2 * * * cd /path/to/your/project && python3 enrich_cases_modular.py --table case_metadata --limit 20 >> logs/enrichment_$(date +\%Y\%m\%d).log 2>&1
```

## Lock File Protection

The modular scripts automatically prevent multiple instances from running simultaneously:

- **Enrichment script**: Creates `enrichment.lock` file
- **Verification script**: Creates `verification.lock` file
- **Automatic cleanup**: Lock files are removed when scripts complete
- **Error handling**: If another instance is running, the script exits gracefully

### Manual lock file management (if needed)
```bash
# Check for lock files
ls -la *.lock

# Remove stale lock file (if needed)
rm -f enrichment.lock verification.lock
```

## Monitoring

### Check if cron is running
```bash
# View cron logs
sudo tail -f /var/log/cron

# Check if the script is currently running
ps aux | grep enrich_cases_modular

# Check for lock files
ls -la *.lock
```

### View enrichment logs
```bash
# Latest log file
tail -f logs/enrichment_$(date +%Y%m%d)*.log

# Search for errors
grep -i error logs/enrichment_*.log
```

### Check database progress
```bash
# Quick progress check
python3 -c "
import sqlite3
conn = sqlite3.connect('doj_cases.db')
cursor = conn.cursor()
cursor.execute('SELECT COUNT(*) FROM cases WHERE verified_1960 = 1')
verified = cursor.fetchone()[0]
tables = ['case_metadata', 'participants', 'case_agencies', 'charges', 'financial_actions', 'victims', 'quotes', 'themes']
for table in tables:
    try:
        cursor.execute(f'SELECT COUNT(*) FROM {table}')
        count = cursor.fetchone()[0]
        print(f'{table:20} {count:4d}/{verified:4d} ({count/verified*100:5.1f}%)')
    except:
        print(f'{table:20} {0:4d}/{verified:4d} ( 0.0%)')
conn.close()
"
```

## Advanced Configuration

### Run specific tables
```bash
# Process different tables on different schedules
0 */6 * * * cd /path/to/project && python3 enrich_cases_modular.py --table case_metadata --limit 20 >> logs/case_metadata_$(date +\%Y\%m\%d).log 2>&1
0 */8 * * * cd /path/to/project && python3 enrich_cases_modular.py --table participants --limit 15 >> logs/participants_$(date +\%Y\%m\%d).log 2>&1
0 */12 * * * cd /path/to/project && python3 enrich_cases_modular.py --table charges --limit 10 >> logs/charges_$(date +\%Y\%m\%d).log 2>&1
```

### Environment-specific settings

#### Development (small batches, frequent runs)
```bash
*/30 * * * * cd /path/to/project && python3 enrich_cases_modular.py --table case_metadata --limit 5 >> logs/enrichment_$(date +\%Y\%m\%d).log 2>&1
```

#### Production (larger batches, less frequent)
```bash
0 */4 * * * cd /path/to/project && python3 enrich_cases_modular.py --table case_metadata --limit 30 >> logs/enrichment_$(date +\%Y\%m\%d).log 2>&1
```

## Troubleshooting

### Common Issues

1. **Script not running**: Check file permissions and paths
   ```bash
   ls -la enrich_cases_modular.py
   which python3
   ```

2. **API errors**: Check your `.env` file
   ```bash
   cat .env | grep VENICE_API_KEY
   ```

3. **Database locked**: Remove stale lock file
   ```bash
   rm -f enrichment.lock
   ```

4. **Disk space**: Check available space
   ```bash
   df -h
   du -sh doj_cases.db
   ```

### Log Analysis
```bash
# Find failed runs
grep -i "failed\|error" logs/enrichment_*.log

# Monitor processing speed
grep "Successfully completed" logs/enrichment_*.log | tail -10
```

## Example Complete Setup

```bash
# 1. Navigate to project directory
cd /path/to/your/project

# 2. Set up environment
cp env.example .env
# Edit .env with your API key

# 3. Test the enrichment script
python3 enrich_cases_modular.py --table case_metadata --limit 5 --dry-run

# 4. Add to crontab
crontab -e
# Add: 0 */6 * * * cd /path/to/your/project && python3 enrich_cases_modular.py --table case_metadata --limit 20 >> logs/enrichment_$(date +\%Y\%m\%d).log 2>&1

# 5. Monitor
tail -f logs/enrichment_$(date +%Y%m%d)*.log
```

## Key Features

- **Automatic locking**: Prevents multiple instances from running
- **Built-in error handling**: Graceful failure recovery
- **Comprehensive logging**: Detailed activity tracking
- **Progress monitoring**: Real-time visibility into enrichment operations
- **Modular architecture**: Clean, maintainable codebase

This setup will automatically enrich your DOJ cases database as new data arrives, maintaining a continuously updated structured dataset for analysis. 