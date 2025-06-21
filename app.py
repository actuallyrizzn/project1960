from flask import Flask, render_template, request, jsonify, redirect, url_for
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

def get_stats():
    """Get database statistics."""
    conn = get_db_connection()
    stats = {}
    
    # Total cases
    stats['total'] = conn.execute('SELECT COUNT(*) FROM cases').fetchone()[0]
    
    # Cases by classification
    stats['yes'] = conn.execute('SELECT COUNT(*) FROM cases WHERE verified_1960 = 1').fetchone()[0]
    stats['no'] = conn.execute('SELECT COUNT(*) FROM cases WHERE verified_1960 = 0 AND classification IS NOT NULL').fetchone()[0]
    stats['unknown'] = conn.execute('SELECT COUNT(*) FROM cases WHERE classification = "unknown" OR classification IS NULL').fetchone()[0]
    
    # Cases mentioning 1960
    stats['mentions_1960'] = conn.execute('SELECT COUNT(*) FROM cases WHERE mentions_1960 = 1').fetchone()[0]
    
    # Cases mentioning crypto
    stats['mentions_crypto'] = conn.execute('SELECT COUNT(*) FROM cases WHERE mentions_crypto = 1').fetchone()[0]
    
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
    """Individual case detail page."""
    conn = get_db_connection()
    case = conn.execute('SELECT * FROM cases WHERE id = ?', (case_id,)).fetchone()
    conn.close()
    
    if case is None:
        return "Case not found", 404
    
    return render_template('case_detail.html', case=case)

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

if __name__ == '__main__':
    app.run(debug=DEBUG_MODE, host=HOST, port=PORT) 