# Cron Setup Guide for Progressive Enrichment

This guide explains how to set up automatic enrichment processing on your Ubuntu server.

## Files Created

- `run_enrichment.py` - Main Python orchestrator
- `run_enrichment.sh` - Shell script wrapper with error handling
- `enrich_cases.py` - Individual table enrichment script

## Quick Setup

### 1. Make the shell script executable
```bash
chmod +x run_enrichment.sh
```

### 2. Test the script manually
```bash
# Test in dry-run mode
./run_enrichment.sh

# Or test the Python script directly
python3 run_enrichment.py --dry-run --limit-per-table 5
```

### 3. Set up cron job

Edit your crontab:
```bash
crontab -e
```

Add one of these schedules:

#### Option A: Run every 6 hours
```bash
0 */6 * * * /path/to/your/project/run_enrichment.sh
```

#### Option B: Run twice daily (6 AM and 6 PM)
```bash
0 6,18 * * * /path/to/your/project/run_enrichment.sh
```

#### Option C: Run daily at 2 AM
```bash
0 2 * * * /path/to/your/project/run_enrichment.sh
```

#### Option D: Run every 4 hours during business hours
```bash
0 9,13,17,21 * * * /path/to/your/project/run_enrichment.sh
```

## Monitoring

### Check if cron is running
```bash
# View cron logs
sudo tail -f /var/log/cron

# Check if the script is currently running
ps aux | grep run_enrichment

# Check for lock files
ls -la enrichment.lock
```

### View enrichment logs
```bash
# Latest log file
tail -f logs/enrichment_$(date +%Y%m%d)*.log

# All log files
ls -la logs/

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

### Customize batch sizes
Edit `run_enrichment.py` and modify the `ENRICHMENT_ORDER` list:
```python
ENRICHMENT_ORDER = [
    ('case_metadata', 30),      # Increase from 20 to 30
    ('participants', 20),       # Increase from 15 to 20
    # ... etc
]
```

### Run specific tables only
```bash
# Only process case_metadata and participants
python3 run_enrichment.py --tables case_metadata participants

# Only process with smaller batches
python3 run_enrichment.py --limit-per-table 5
```

### Environment-specific settings
Create different cron entries for different environments:

#### Development (small batches, frequent runs)
```bash
*/30 * * * * /path/to/project/run_enrichment.sh  # Every 30 minutes
```

#### Production (larger batches, less frequent)
```bash
0 */4 * * * /path/to/project/run_enrichment.sh   # Every 4 hours
```

## Troubleshooting

### Common Issues

1. **Script not running**: Check file permissions and paths
   ```bash
   ls -la run_enrichment.sh
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

# Check API usage
grep -i "api" logs/enrichment_*.log

# Monitor processing speed
grep "Successfully completed" logs/enrichment_*.log | tail -10
```

## Performance Tips

1. **Start small**: Begin with `--limit-per-table 5` and increase gradually
2. **Monitor API usage**: Watch for rate limiting or quota issues
3. **Database maintenance**: Consider VACUUM periodically for large databases
4. **Log rotation**: Set up logrotate to manage log file sizes

## Security Considerations

1. **File permissions**: Ensure `.env` is readable only by the cron user
   ```bash
   chmod 600 .env
   ```

2. **API key security**: Rotate API keys periodically
3. **Database backup**: Set up regular backups of `doj_cases.db`
4. **Log security**: Ensure logs don't contain sensitive information

## Example Complete Setup

```bash
# 1. Navigate to project directory
cd /path/to/your/project

# 2. Set up environment
cp env.example .env
# Edit .env with your API key

# 3. Make script executable
chmod +x run_enrichment.sh

# 4. Test manually
./run_enrichment.sh

# 5. Add to crontab
crontab -e
# Add: 0 */6 * * * /path/to/your/project/run_enrichment.sh

# 6. Monitor
tail -f logs/enrichment_$(date +%Y%m%d)*.log
```

This setup will automatically enrich your DOJ cases database as new data arrives, maintaining a continuously updated structured dataset for analysis. 