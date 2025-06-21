#!/bin/bash

# Progressive Enrichment Runner for DOJ Cases
# Shell script wrapper for Ubuntu server environment

set -e  # Exit on any error

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG_DIR="$SCRIPT_DIR/logs"
ENRICHMENT_LOG="$LOG_DIR/enrichment_$(date +%Y%m%d_%H%M%S).log"
LOCK_FILE="$SCRIPT_DIR/enrichment.lock"

# Create log directory if it doesn't exist
mkdir -p "$LOG_DIR"

# Function to log messages
log() {
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" | tee -a "$ENRICHMENT_LOG"
}

# Function to cleanup on exit
cleanup() {
    if [ -f "$LOCK_FILE" ]; then
        rm -f "$LOCK_FILE"
        log "Lock file removed"
    fi
}

# Set up trap to cleanup on script exit
trap cleanup EXIT

# Check if already running
if [ -f "$LOCK_FILE" ]; then
    PID=$(cat "$LOCK_FILE" 2>/dev/null || echo "")
    if [ -n "$PID" ] && kill -0 "$PID" 2>/dev/null; then
        log "ERROR: Enrichment process already running (PID: $PID)"
        exit 1
    else
        log "WARNING: Stale lock file found, removing"
        rm -f "$LOCK_FILE"
    fi
fi

# Create lock file
echo $$ > "$LOCK_FILE"
log "Lock file created (PID: $$)"

# Change to script directory
cd "$SCRIPT_DIR"
log "Changed to directory: $SCRIPT_DIR"

# Check if Python script exists
if [ ! -f "run_enrichment.py" ]; then
    log "ERROR: run_enrichment.py not found in $SCRIPT_DIR"
    exit 1
fi

# Check if enrich_cases.py exists
if [ ! -f "enrich_cases.py" ]; then
    log "ERROR: enrich_cases.py not found in $SCRIPT_DIR"
    exit 1
fi

# Check if database exists
if [ ! -f "doj_cases.db" ]; then
    log "ERROR: doj_cases.db not found in $SCRIPT_DIR"
    exit 1
fi

# Check if .env file exists
if [ ! -f ".env" ]; then
    log "ERROR: .env file not found in $SCRIPT_DIR"
    exit 1
fi

# Load environment variables
if [ -f ".env" ]; then
    log "Loading environment variables from .env"
    export $(cat .env | grep -v '^#' | xargs)
fi

# Check if VENICE_API_KEY is set
if [ -z "$VENICE_API_KEY" ]; then
    log "ERROR: VENICE_API_KEY not set in environment"
    exit 1
fi

# Start enrichment process
log "🚀 Starting progressive enrichment process"
log "Log file: $ENRICHMENT_LOG"

# Run the Python enrichment script
# You can modify these arguments as needed:
# --dry-run: for testing
# --limit-per-table 5: to process only 5 cases per table
# --verbose: for detailed logging
python3 run_enrichment.py 2>&1 | tee -a "$ENRICHMENT_LOG"

# Capture exit code
EXIT_CODE=${PIPESTATUS[0]}

if [ $EXIT_CODE -eq 0 ]; then
    log "✅ Enrichment process completed successfully"
else
    log "❌ Enrichment process failed with exit code $EXIT_CODE"
fi

exit $EXIT_CODE 