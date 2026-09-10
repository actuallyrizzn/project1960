import sqlite3

conn = sqlite3.connect('doj_cases.db')
cursor = conn.cursor()

# List all tables
cursor.execute("SELECT name FROM sqlite_master WHERE type='table'")
tables = cursor.fetchall()
print("All tables in database:")
for table in tables:
    print(f"  {table[0]}")

# Check if case_agencies exists
cursor.execute("SELECT name FROM sqlite_master WHERE type='table' AND name='case_agencies'")
if cursor.fetchone():
    print("\ncase_agencies table exists")
    
    # Get table schema
    cursor.execute("PRAGMA table_info(case_agencies)")
    columns = cursor.fetchall()
    print("Columns in case_agencies:")
    for col in columns:
        print(f"  {col[1]} ({col[2]})")
else:
    print("\ncase_agencies table does not exist")

conn.close() 