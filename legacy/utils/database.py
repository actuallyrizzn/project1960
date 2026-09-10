"""
Database connection and schema management for the Project1960.
"""
import sqlite3
import logging
from typing import Optional, List, Tuple, Any
from .config import Config

logger = logging.getLogger(__name__)

class DatabaseManager:
    """Database connection and management utilities."""
    
    def __init__(self, db_path: Optional[str] = None):
        """Initialize database manager with optional custom path."""
        self.db_path = db_path or Config.DATABASE_NAME
    
    def get_connection(self, timeout: float = 30.0, isolation_level: Optional[str] = None) -> sqlite3.Connection:
        """Get database connection with proper configuration."""
        try:
            conn = sqlite3.connect(
                self.db_path, 
                timeout=timeout,
                isolation_level=isolation_level
            )
            return conn
        except sqlite3.Error as e:
            logger.error(f"Failed to connect to database {self.db_path}: {e}")
            raise
    
    def execute_query(self, query: str, params: Optional[Tuple] = None) -> List[Tuple]:
        """Execute query with error handling."""
        conn = None
        try:
            conn = self.get_connection()
            cursor = conn.cursor()
            if params:
                cursor.execute(query, params)
            else:
                cursor.execute(query)
            
            if query.strip().upper().startswith('SELECT'):
                return cursor.fetchall()
            else:
                conn.commit()
                return []
        except sqlite3.Error as e:
            logger.error(f"Database query failed: {e}")
            if conn:
                conn.rollback()
            raise
        finally:
            if conn:
                conn.close()
    
    def execute_many(self, query: str, params_list: List[Tuple]) -> None:
        """Execute multiple queries with error handling."""
        conn = None
        try:
            conn = self.get_connection()
            cursor = conn.cursor()
            cursor.executemany(query, params_list)
            conn.commit()
        except sqlite3.Error as e:
            logger.error(f"Database executemany failed: {e}")
            if conn:
                conn.rollback()
            raise
        finally:
            if conn:
                conn.close()
    
    def create_tables(self, schemas: dict) -> None:
        """Create tables from schema definitions."""
        conn = None
        try:
            conn = self.get_connection()
            cursor = conn.cursor()
            
            for table_name, schema_sql in schemas.items():
                logger.debug(f"Creating table '{table_name}'...")
                cursor.execute(schema_sql)
            
            conn.commit()
            logger.info("All tables created successfully or already exist.")
        except sqlite3.Error as e:
            logger.error(f"Database error during table setup: {e}")
            if conn:
                conn.rollback()
            raise
        finally:
            if conn:
                conn.close()
    
    def table_exists(self, table_name: str) -> bool:
        """Check if table exists."""
        try:
            result = self.execute_query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name=?",
                (table_name,)
            )
            return len(result) > 0
        except sqlite3.Error as e:
            logger.error(f"Failed to check if table {table_name} exists: {e}")
            return False
    
    def get_table_info(self, table_name: str) -> List[Tuple]:
        """Get table schema information."""
        try:
            return self.execute_query(f"PRAGMA table_info({table_name})")
        except sqlite3.Error as e:
            logger.error(f"Failed to get table info for {table_name}: {e}")
            return []
    
    def get_table_count(self, table_name: str) -> int:
        """Get row count for a table."""
        try:
            result = self.execute_query(f"SELECT COUNT(*) FROM {table_name}")
            return result[0][0] if result else 0
        except sqlite3.Error as e:
            logger.error(f"Failed to get count for table {table_name}: {e}")
            return 0 