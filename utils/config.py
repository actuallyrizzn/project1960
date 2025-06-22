"""
Centralized configuration management for the DOJ cases project.
"""
import os
from dotenv import load_dotenv

# Load environment variables
load_dotenv()

class Config:
    """Centralized configuration for the application."""
    
    # API Configuration
    VENICE_API_KEY = os.getenv("VENICE_API_KEY")
    VENICE_API_URL = "https://api.venice.ai/api/v1/chat/completions"
    MODEL_NAME = "qwen-2.5-qwq-32b"
    
    # Database Configuration
    DATABASE_NAME = os.getenv("DATABASE_NAME", "doj_cases.db")
    
    # Processing Configuration
    DEFAULT_PROCESSING_LIMIT = 100
    API_TIMEOUT = 120
    RETRY_ATTEMPTS = 3
    RETRY_DELAY = 2
    
    # Logging Configuration
    LOG_FORMAT = '%(asctime)s - %(levelname)s - %(message)s'
    LOG_LEVEL = 'INFO'
    
    # Validation
    @classmethod
    def validate(cls):
        """Validate that required configuration is present."""
        if not cls.VENICE_API_KEY:
            raise ValueError("VENICE_API_KEY environment variable is not set!")
        return True
    
    @classmethod
    def get_api_headers(cls):
        """Get standard API headers."""
        return {
            "Authorization": f"Bearer {cls.VENICE_API_KEY}",
            "Content-Type": "application/json"
        } 