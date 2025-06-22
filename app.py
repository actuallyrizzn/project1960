from flask import Flask, render_template, request, jsonify, redirect, url_for, flash, send_from_directory
import sqlite3
import json
from datetime import datetime
import os
from dotenv import load_dotenv

# Load environment variables
load_dotenv()

app = Flask(__name__)

# Configuration
DATABASE_NAME = os.getenv("DATABASE_NAME", "doj_cases.db")
DEBUG_MODE = os.getenv("FLASK_DEBUG", "False").lower() == "true"
HOST = os.getenv("FLASK_HOST", "0.0.0.0")
PORT = int(os.getenv("FLASK_PORT", "5000"))

app.config['DEBUG'] = DEBUG_MODE

def get_db_connection():
    """Create a database connection."""
    conn = sqlite3.connect(DATABASE_NAME)
    conn.row_factory = sqlite3.Row
    return conn

def get_enrichment_data(case_id):
    """Get all enrichment data for a specific case."""
    conn = get_db_connection()
    enrichment = {}
    
    # Get case metadata
    try:
        metadata = conn.execute('SELECT * FROM case_metadata WHERE case_id = ?', (case_id,)).fetchone()
        if metadata:
            enrichment['metadata'] = dict(metadata)
    except sqlite3.OperationalError:
        enrichment['metadata'] = None
    
    # Get participants
    try:
        participants = conn.execute('SELECT * FROM participants WHERE case_id = ? ORDER BY participant_id', (case_id,)).fetchall()
        enrichment['participants'] = [dict(p) for p in participants]
    except sqlite3.OperationalError:
        enrichment['participants'] = []
    
    # Get case agencies
    try:
        agencies = conn.execute('SELECT * FROM case_agencies WHERE case_id = ? ORDER BY agency_id', (case_id,)).fetchall()
        enrichment['agencies'] = [dict(a) for a in agencies]
    except sqlite3.OperationalError:
        enrichment['agencies'] = []
    
    # Get charges
    try:
        charges = conn.execute('SELECT * FROM charges WHERE case_id = ? ORDER BY charge_id', (case_id,)).fetchall()
        enrichment['charges'] = [dict(c) for c in charges]
    except sqlite3.OperationalError:
        enrichment['charges'] = []
    
    # Get financial actions
    try:
        financial_actions = conn.execute('SELECT * FROM financial_actions WHERE case_id = ? ORDER BY fin_id', (case_id,)).fetchall()
        enrichment['financial_actions'] = [dict(f) for f in financial_actions]
    except sqlite3.OperationalError:
        enrichment['financial_actions'] = []
    
    # Get victims
    try:
        victims = conn.execute('SELECT * FROM victims WHERE case_id = ? ORDER BY victim_id', (case_id,)).fetchall()
        enrichment['victims'] = [dict(v) for v in victims]
    except sqlite3.OperationalError:
        enrichment['victims'] = []
    
    # Get quotes
    try:
        quotes = conn.execute('SELECT * FROM quotes WHERE case_id = ? ORDER BY quote_id', (case_id,)).fetchall()
        enrichment['quotes'] = [dict(q) for q in quotes]
    except sqlite3.OperationalError:
        enrichment['quotes'] = []
    
    # Get themes
    try:
        themes = conn.execute('SELECT * FROM themes WHERE case_id = ? ORDER BY theme_id', (case_id,)).fetchall()
        enrichment['themes'] = [dict(t) for t in themes]
    except sqlite3.OperationalError:
        enrichment['themes'] = []
    
    conn.close()
    return enrichment

def get_stats():
    """Get database statistics including enrichment progress."""
    conn = get_db_connection()
    stats = {}
    
    # General Stats
    stats['total_cases'] = conn.execute('SELECT COUNT(*) FROM cases').fetchone()[0]
    stats['mentions_1960'] = conn.execute('SELECT COUNT(*) FROM cases WHERE mentions_1960 = 1').fetchone()[0]
    stats['mentions_crypto'] = conn.execute('SELECT COUNT(*) FROM cases WHERE mentions_crypto = 1').fetchone()[0]
    
    # 1960 Verification Stats (based on the cohort that mentions 1960)
    cohort_1960 = 'WHERE mentions_1960 = 1'
    stats['verified_yes'] = conn.execute(f'SELECT COUNT(*) FROM cases {cohort_1960} AND verified_1960 = 1').fetchone()[0]
    stats['verified_no'] = conn.execute(f'SELECT COUNT(*) FROM cases {cohort_1960} AND verified_1960 = 0').fetchone()[0]
    
    # Calculate unprocessed for the 1960 cohort
    processed_1960 = stats['verified_yes'] + stats['verified_no']
    stats['unprocessed_1960'] = stats['mentions_1960'] - processed_1960

    # Enrichment Progress Stats
    enrichment_tables = [
        'case_metadata', 'participants', 'case_agencies', 'charges', 
        'financial_actions', 'victims', 'quotes', 'themes'
    ]
    
    stats['enrichment'] = {}
    for table in enrichment_tables:
        try:
            count = conn.execute(f'SELECT COUNT(*) FROM {table}').fetchone()[0]
            stats['enrichment'][table] = count
        except sqlite3.OperationalError:
            stats['enrichment'][table] = 0

    conn.close()
    return stats

@app.route('/')
def index():
    """Main dashboard page."""
    stats = get_stats()
    return render_template('index.html', stats=stats)

@app.route('/cases')
def cases():
    """Cases listing page with filtering and pagination."""
    page = request.args.get('page', 1, type=int)
    per_page = 20
    offset = (page - 1) * per_page
    
    # Get filter parameters
    classification = request.args.get('classification', '')
    mentions_1960 = request.args.get('mentions_1960', '')
    mentions_crypto = request.args.get('mentions_crypto', '')
    search = request.args.get('search', '')
    
    conn = get_db_connection()
    
    # Build query with filters
    query = "SELECT * FROM cases WHERE 1=1"
    params = []
    
    if classification:
        query += " AND classification = ?"
        params.append(classification)
    
    if mentions_1960:
        query += " AND mentions_1960 = ?"
        params.append(int(mentions_1960))
    
    if mentions_crypto:
        query += " AND mentions_crypto = ?"
        params.append(int(mentions_crypto))
    
    if search:
        query += " AND (title LIKE ? OR body LIKE ?)"
        search_term = f"%{search}%"
        params.extend([search_term, search_term])
    
    # Get total count for pagination
    count_query = query.replace("SELECT *", "SELECT COUNT(*)")
    total = conn.execute(count_query, params).fetchone()[0]
    
    # Get paginated results
    query += " ORDER BY date DESC LIMIT ? OFFSET ?"
    params.extend([per_page, offset])
    
    cases = conn.execute(query, params).fetchall()
    conn.close()
    
    total_pages = (total + per_page - 1) // per_page
    
    return render_template('cases.html', 
                         cases=cases, 
                         page=page, 
                         total_pages=total_pages,
                         total=total,
                         classification=classification,
                         mentions_1960=mentions_1960,
                         mentions_crypto=mentions_crypto,
                         search=search)

@app.route('/case/<case_id>')
def case_detail(case_id):
    """Individual case detail page with enrichment data."""
    conn = get_db_connection()
    case = conn.execute('SELECT * FROM cases WHERE id = ?', (case_id,)).fetchone()
    conn.close()
    
    if case is None:
        return "Case not found", 404
    
    # Get enrichment data
    enrichment = get_enrichment_data(case_id)
    
    return render_template('case_detail.html', case=case, enrichment=enrichment)

@app.route('/enrichment')
def enrichment_dashboard():
    """Enrichment progress dashboard."""
    conn = sqlite3.connect('doj_cases.db')
    cursor = conn.cursor()
    # Fetch recent activity log (last 50 entries)
    cursor.execute('''
        SELECT timestamp, case_id, table_name, status, notes
        FROM enrichment_activity_log
        ORDER BY timestamp DESC
        LIMIT 50
    ''')
    activity_log = cursor.fetchall()
    conn.close()
    return render_template('enrichment.html', activity_log=activity_log)

@app.route('/api/stats')
def api_stats():
    """API endpoint for statistics."""
    return jsonify(get_stats())

@app.route('/api/cases')
def api_cases():
    """API endpoint for cases data."""
    conn = get_db_connection()
    cases = conn.execute('SELECT id, title, date, classification, verified_1960, mentions_1960, mentions_crypto FROM cases ORDER BY date DESC LIMIT 100').fetchall()
    conn.close()
    
    return jsonify([dict(case) for case in cases])

@app.route('/api/enrichment/<case_id>')
def api_enrichment(case_id):
    """API endpoint for enrichment data."""
    enrichment = get_enrichment_data(case_id)
    return jsonify(enrichment)

@app.route('/about')
def about():
    """About page explaining the project's purpose, methodology, and roadmap."""
    # Get statistics for the current status section
    conn = get_db_connection()
    stats = {}
    
    # Total cases
    stats['total_cases'] = conn.execute('SELECT COUNT(*) FROM cases').fetchone()[0]
    
    # Cases with 18 USC 1960 mentions
    stats['cases_1960'] = conn.execute('SELECT COUNT(*) FROM cases WHERE mentions_1960 = 1').fetchone()[0]
    
    # Cases with crypto mentions
    stats['cases_crypto'] = conn.execute('SELECT COUNT(*) FROM cases WHERE mentions_crypto = 1').fetchone()[0]
    
    conn.close()
    
    return render_template('about.html', stats=stats)

if __name__ == '__main__':
    app.run(debug=DEBUG_MODE, host=HOST, port=PORT) 