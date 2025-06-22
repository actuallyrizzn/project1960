"""
Venice API client for the DOJ cases project.
"""
import requests
import logging
import time
from typing import Optional, Dict, Any
from .config import Config
import re

logger = logging.getLogger(__name__)

class VeniceAPIClient:
    """Venice API client with retry logic and error handling."""
    
    def __init__(self):
        """Initialize API client with configuration."""
        self.api_key = Config.VENICE_API_KEY
        self.api_url = Config.VENICE_API_URL
        self.model = Config.MODEL_NAME
        self.timeout = Config.API_TIMEOUT
        self.retry_attempts = Config.RETRY_ATTEMPTS
        self.retry_delay = Config.RETRY_DELAY
        
        # Validate configuration
        if not self.api_key:
            raise ValueError("VENICE_API_KEY is not set")
    
    def call_api(self, prompt: str, max_tokens: int = 2000, temperature: float = 0.1) -> Optional[Dict[str, Any]]:
        """Make API call with retry logic and error handling."""
        payload = {
            "model": self.model,
            "messages": [{"role": "user", "content": prompt}],
            "max_tokens": max_tokens,
            "temperature": temperature
        }
        
        headers = Config.get_api_headers()
        
        for attempt in range(self.retry_attempts):
            try:
                logger.debug(f"Sending request to Venice API (attempt {attempt + 1})...")
                response = requests.post(
                    self.api_url, 
                    headers=headers, 
                    json=payload, 
                    timeout=self.timeout
                )
                logger.debug(f"Received response with status code: {response.status_code}")
                
                if response.status_code == 429:
                    logger.warning(f"Rate limit hit on attempt {attempt + 1}. Response: {response.text}")
                    if attempt < self.retry_attempts - 1:
                        time.sleep(self.retry_delay)
                        continue
                    else:
                        logger.error("All retry attempts failed due to rate limiting")
                        return None
                
                elif response.status_code != 200:
                    logger.error(f"API call failed with status {response.status_code}. Response: {response.text}")
                    return None
                
                # Success - parse response
                data = response.json()
                logger.debug(f"Raw API JSON response: {data}")
                return data
                
            except requests.exceptions.ConnectionError as e:
                logger.error(f"Connection error on attempt {attempt + 1}: {e}")
                if attempt < self.retry_attempts - 1:
                    time.sleep(self.retry_delay)
                    continue
                else:
                    logger.error("All retry attempts failed due to connection errors")
                    return None
                    
            except requests.exceptions.ReadTimeout as e:
                logger.error(f"Read timeout on attempt {attempt + 1}: {e}")
                if attempt < self.retry_attempts - 1:
                    time.sleep(self.retry_delay)
                    continue
                else:
                    logger.error("All retry attempts failed due to timeouts")
                    return None
                    
            except Exception as e:
                logger.error(f"Unexpected error on attempt {attempt + 1}: {e}")
                if attempt < self.retry_attempts - 1:
                    time.sleep(self.retry_delay)
                    continue
                else:
                    logger.error("All retry attempts failed due to unexpected errors")
                    return None
        
        return None
    
    def extract_content(self, response_data: Optional[Dict[str, Any]]) -> Optional[str]:
        """Extract content from API response with multiple fallback strategies."""
        if not response_data or not isinstance(response_data, dict):
            logger.debug("Response data is None or not a dict")
            return None
        
        logger.debug(f"Extracting content from response data with keys: {list(response_data.keys())}")
        
        # Strategy 1: Standard OpenAI-style response
        try:
            if "choices" in response_data and response_data["choices"]:
                choice = response_data["choices"][0]
                if "message" in choice and "content" in choice["message"]:
                    content = choice["message"]["content"]
                    logger.debug(f"✅ Strategy 1 (OpenAI format) succeeded: {repr(content[:100])}...")
                    return content
        except (KeyError, IndexError, TypeError) as e:
            logger.debug(f"❌ Strategy 1 (OpenAI format) failed: {e}")
        
        # Strategy 2: Direct content field
        try:
            if "content" in response_data:
                content = response_data["content"]
                logger.debug(f"✅ Strategy 2 (direct content) succeeded: {repr(content[:100])}...")
                return content
        except (KeyError, TypeError) as e:
            logger.debug(f"❌ Strategy 2 (direct content) failed: {e}")
        
        # Strategy 3: Look for any text field
        text_fields = ["text", "response", "result", "output", "message"]
        for field in text_fields:
            try:
                if field in response_data:
                    content = response_data[field]
                    if isinstance(content, str):
                        logger.debug(f"✅ Strategy 3 (text field '{field}') succeeded: {repr(content[:100])}...")
                        return content
                    elif isinstance(content, dict) and "content" in content:
                        content = content["content"]
                        logger.debug(f"✅ Strategy 3 (nested content in '{field}') succeeded: {repr(content[:100])}...")
                        return content
            except (KeyError, TypeError) as e:
                logger.debug(f"❌ Strategy 3 (text field '{field}') failed: {e}")
        
        # Strategy 4: Convert response to string and look for JSON-like content
        try:
            import json
            response_str = str(response_data)
            json_match = re.search(r'\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}', response_str)
            if json_match:
                content = json_match.group(0)
                logger.debug(f"✅ Strategy 4 (string conversion) succeeded: {repr(content)}")
                return content
        except Exception as e:
            logger.debug(f"❌ Strategy 4 (string conversion) failed: {e}")
        
        logger.debug("❌ All content extraction strategies failed")
        return None 