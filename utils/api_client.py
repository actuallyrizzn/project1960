"""
API client for Venice AI with fallback model support.
"""
import logging
import requests
import time
from typing import Dict, Any, Optional, List
from utils.config import Config
import re

logger = logging.getLogger(__name__)

class VeniceAPIClient:
    """Client for Venice AI API with automatic fallback to larger models."""
    
    def __init__(self):
        """Initialize the API client."""
        self.api_url = Config.VENICE_API_URL
        self.model = Config.MODEL_NAME
        self.retry_attempts = Config.RETRY_ATTEMPTS
        self.retry_delay = Config.RETRY_DELAY
        self.timeout = Config.API_TIMEOUT
        
        # Validate configuration
        if not Config.VENICE_API_KEY:
            raise ValueError("VENICE_API_KEY is not set")
        
        # Fallback models ordered by context size (largest first) and reasoning capability
        # Prioritize reasoning models (supportsReasoning: true) for better JSON extraction
        self.fallback_models = [
            "qwen3-235b",      # 131,072 tokens - Venice Large ($1.5/$6) - reasoning ✅
            "deepseek-r1-671b", # 131,072 tokens - DeepSeek R1 671B ($3.5/$14) - reasoning ✅
            "llama-3.2-3b",    # 131,072 tokens - Llama 3.2 3B ($0.15/$0.6) - reasoning ❌ (last resort)
            "mistral-31-24b",  # 131,072 tokens - Venice Medium ($0.5/$2) - reasoning ❌ (last resort)
            "llama-3.3-70b",   # 65,536 tokens - Llama 3.3 70B ($0.7/$2.8) - reasoning ❌ (last resort)
            "llama-3.1-405b",  # 65,536 tokens - Llama 3.1 405B ($1.5/$6) - reasoning ❌ (last resort)
        ]
        
        # Skip availability check in production - use all models and let the API tell us which ones work
        self.available_fallback_models = self.fallback_models
        logger.info(f"Using all fallback models: {self.available_fallback_models}")
        
        # Track which models have been tried
        self.tried_models = set()
    
    def _is_token_limit_error(self, response_text: str) -> bool:
        """Check if the error is due to token limit exceeded."""
        return (
            "maximum context length" in response_text.lower() and
            "tokens" in response_text.lower() and
            ("requested" in response_text.lower() or "exceeded" in response_text.lower())
        )
    
    def _get_next_fallback_model(self) -> Optional[str]:
        """Get the next available fallback model."""
        logger.debug(f"Looking for next fallback model. Tried models: {self.tried_models}")
        logger.debug(f"Available fallback models: {self.available_fallback_models}")
        
        for model in self.available_fallback_models:
            if model not in self.tried_models:
                logger.info(f"Next available fallback model: {model}")
                return model
        
        logger.warning("No more fallback models available")
        logger.warning(f"All models tried: {self.tried_models}")
        return None
    
    def _estimate_tokens(self, text: str) -> int:
        """Rough estimate of token count (approximately 3 characters per token for English text)."""
        return len(text) // 3
    
    def _truncate_prompt(self, prompt: str, model: str) -> str:
        """Truncate prompt to fit within model context limits."""
        context_limits = {
            "qwen-2.5-qwq-32b": 32768,
            "mistral-31-24b": 131072,
            "llama-3.2-3b": 131072,
            "qwen3-235b": 131072,
            "deepseek-r1-671b": 131072,
            "llama-3.3-70b": 65536,
            "llama-3.1-405b": 65536,
        }
        
        context_limit = context_limits.get(model, 32768)
        
        # Reserve tokens for response and system overhead
        max_prompt_tokens = context_limit - 2000  # Reserve 2000 tokens for response
        
        estimated_tokens = self._estimate_tokens(prompt)
        logger.debug(f"Model: {model}, Context limit: {context_limit}, Max prompt tokens: {max_prompt_tokens}, Estimated tokens: {estimated_tokens}")
        
        if estimated_tokens <= max_prompt_tokens:
            logger.debug(f"Prompt fits within limits, no truncation needed")
            return prompt
        
        logger.warning(f"Prompt too large for {model}. Estimated tokens: {estimated_tokens}, Max allowed: {max_prompt_tokens}")
        
        # Find the document content in the prompt (usually after the instructions)
        # Look for common patterns in our prompts
        if "Press Release Title:" in prompt:
            # Extract the title and truncate the body
            title_start = prompt.find("Press Release Title:")
            title_end = prompt.find("Press Release Body:", title_start)
            if title_end != -1:
                title_section = prompt[:title_end]
                body_section = prompt[title_end:]
                
                # Calculate how much of the body we can keep
                title_tokens = self._estimate_tokens(title_section)
                available_tokens = max_prompt_tokens - title_tokens
                
                logger.debug(f"Title tokens: {title_tokens}, Available for body: {available_tokens}")
                
                if available_tokens > 0:
                    # Truncate the body section
                    max_body_chars = available_tokens * 3
                    if len(body_section) > max_body_chars:
                        truncated_body = body_section[:max_body_chars] + "\n\n[Document truncated due to length]"
                        result = title_section + truncated_body
                        logger.info(f"Truncated body from {len(body_section)} to {len(truncated_body)} characters")
                        return result
                
        # Fallback: simple truncation
        max_chars = max_prompt_tokens * 3
        if len(prompt) > max_chars:
            truncated = prompt[:max_chars] + "\n\n[Document truncated due to length]"
            logger.warning(f"Simple truncation: from {len(prompt)} to {len(truncated)} characters for model {model}")
            return truncated
        
        return prompt
    
    def _adjust_max_tokens(self, prompt: str, model: str) -> int:
        """Adjust max_tokens based on model context limits and prompt size."""
        # Model context limits (from actual API data)
        context_limits = {
            "qwen-2.5-qwq-32b": 32768,
            "mistral-31-24b": 131072,
            "llama-3.2-3b": 131072,
            "qwen3-235b": 131072,
            "deepseek-r1-671b": 131072,
            "llama-3.3-70b": 65536,
            "llama-3.1-405b": 65536,
        }
        
        context_limit = context_limits.get(model, 32768)
        estimated_prompt_tokens = self._estimate_tokens(prompt)
        
        # Reserve some tokens for the response
        available_tokens = context_limit - estimated_prompt_tokens - 1000  # 1000 token buffer
        
        logger.debug(f"Context limit: {context_limit}, Estimated prompt tokens: {estimated_prompt_tokens}, Available tokens: {available_tokens}")
        
        if available_tokens <= 0:
            logger.warning(f"Prompt too large for model {model}. Estimated tokens: {estimated_prompt_tokens}, Context limit: {context_limit}")
            return 1000  # Minimum response size
        
        return min(available_tokens, 4000)  # Cap at 4000 tokens
    
    def call_api(self, prompt: str, max_tokens: int = 2000, temperature: float = 0.1) -> Optional[Dict[str, Any]]:
        """Make API call with retry logic and automatic model fallback."""
        # Start with the primary model
        current_model = self.model
        self.tried_models = {current_model}
        
        logger.info(f"Starting API call with primary model: {current_model}")
        logger.info(f"Available fallback models: {self.available_fallback_models}")
        
        # Track the current prompt (may be truncated)
        current_prompt = prompt
        
        while True:
            for attempt in range(self.retry_attempts):
                try:
                    logger.debug(f"Making API call with model: {current_model} (attempt {attempt + 1})")
                    
                    # Truncate prompt if needed for this model
                    truncated_prompt = self._truncate_prompt(current_prompt, current_model)
                    
                    # Adjust max_tokens based on model and prompt size
                    adjusted_max_tokens = self._adjust_max_tokens(truncated_prompt, current_model)
                    
                    payload = {
                        "model": current_model,
                        "messages": [{"role": "user", "content": truncated_prompt}],
                        "max_tokens": adjusted_max_tokens,
                        "temperature": temperature
                    }
                    
                    headers = Config.get_api_headers()
                    
                    logger.debug(f"Making API call to {self.api_url}")
                    logger.debug(f"Model: {current_model}")
                    logger.debug(f"Max tokens: {adjusted_max_tokens} (adjusted from {max_tokens})")
                    logger.debug(f"Temperature: {temperature}")
                    logger.debug(f"Prompt length: {len(truncated_prompt)} characters (original: {len(prompt)})")
                    logger.debug(f"Prompt preview: {truncated_prompt[:200]}...")
                    
                    # Make the API call
                    model_timeout = self._get_model_timeout(current_model)
                    logger.info(f"Using timeout of {model_timeout} seconds for model {current_model}")
                    
                    response = requests.post(
                        self.api_url,
                        headers=headers,
                        json=payload,
                        timeout=model_timeout
                    )
                    
                    if response.status_code == 200:
                        logger.debug(f"Received response with status code: {response.status_code}")
                        response_data = response.json()
                        logger.debug(f"Raw API JSON response keys: {list(response_data.keys())}")
                        logger.debug(f"Raw API response preview: {str(response_data)[:500]}...")
                        return response_data
                    
                    elif response.status_code == 429:
                        logger.warning(f"Rate limit hit on attempt {attempt + 1}. Response: {response.text}")
                        if attempt < self.retry_attempts - 1:
                            time.sleep(self.retry_delay)
                            continue
                        else:
                            logger.error("All retry attempts failed due to rate limiting")
                            return None
                    
                    else:
                        response_text = response.text
                        logger.error(f"API call failed with status {response.status_code}. Response: {response_text}")
                        
                        # Check if this is a token limit error
                        if self._is_token_limit_error(response_text):
                            logger.warning(f"Token limit exceeded for model {current_model}")
                            
                            # Try next fallback model
                            next_model = self._get_next_fallback_model()
                            if next_model:
                                logger.info(f"Switching to fallback model: {next_model}")
                                current_model = next_model
                                self.tried_models.add(current_model)
                                # Use the truncated prompt for the next model
                                current_prompt = truncated_prompt
                                break  # Break out of retry loop and try new model
                            else:
                                logger.error("All available models have been tried. Cannot process this document.")
                                return None
                        else:
                            # Check if this is a model not found error or any model-related error
                            model_error_keywords = ["model", "not found", "not available", "invalid", "unsupported"]
                            is_model_error = any(keyword in response_text.lower() for keyword in model_error_keywords)
                            
                            if is_model_error:
                                logger.warning(f"Model {current_model} error: {response_text}")
                                
                                # Try next fallback model immediately (don't retry model errors)
                                next_model = self._get_next_fallback_model()
                                if next_model:
                                    logger.info(f"Switching to fallback model: {next_model}")
                                    current_model = next_model
                                    self.tried_models.add(current_model)
                                    # Use the truncated prompt for the next model
                                    current_prompt = truncated_prompt
                                    break  # Break out of retry loop and try new model
                                else:
                                    logger.error("All available models have been tried. Cannot process this document.")
                                    return None
                            else:
                                # Not a token limit or model availability error, retry if attempts remain
                                if attempt < self.retry_attempts - 1:
                                    logger.warning(f"Retrying {current_model} due to non-model error (attempt {attempt + 1}/{self.retry_attempts})")
                                    time.sleep(self.retry_delay)
                                    continue
                                else:
                                    logger.error(f"All retry attempts failed for {current_model}: {response_text}")
                                    # Try next fallback model
                                    next_model = self._get_next_fallback_model()
                                    if next_model:
                                        logger.info(f"Switching to fallback model: {next_model}")
                                        current_model = next_model
                                        self.tried_models.add(current_model)
                                        # Use the truncated prompt for the next model
                                        current_prompt = truncated_prompt
                                        break  # Break out of retry loop and try new model
                                    else:
                                        logger.error("All available models have been tried. Cannot process this document.")
                                        return None
                            
                except requests.exceptions.RequestException as e:
                    logger.error(f"Request failed: {e}")
                    if attempt < self.retry_attempts - 1:
                        time.sleep(self.retry_delay)
                        continue
                    else:
                        return None
                except Exception as e:
                    logger.error(f"Unexpected error: {e}")
                    if attempt < self.retry_attempts - 1:
                        time.sleep(self.retry_delay)
                        continue
                    else:
                        return None
            
            # If we get here, all retry attempts for current model failed
            # Try next fallback model if available
            next_model = self._get_next_fallback_model()
            if next_model:
                logger.info(f"All retry attempts failed for {current_model}, switching to fallback model: {next_model}")
                current_model = next_model
                self.tried_models.add(current_model)
                # Use the truncated prompt for the next model
                current_prompt = truncated_prompt
                continue
            else:
                logger.error("All available models have been tried and failed.")
                return None
    
    def extract_content(self, response_data: Optional[Dict[str, Any]]) -> Optional[str]:
        """Extract content from API response with multiple fallback strategies."""
        if not response_data or not isinstance(response_data, dict):
            logger.debug("Response data is None or not a dict")
            return None
        
        logger.debug(f"Extracting content from response data with keys: {list(response_data.keys())}")
        logger.debug(f"Full response data: {response_data}")
        
        # Strategy 1: Standard OpenAI-style response
        try:
            if "choices" in response_data and response_data["choices"]:
                choice = response_data["choices"][0]
                logger.debug(f"Found choices: {choice}")
                if "message" in choice and "content" in choice["message"]:
                    content = choice["message"]["content"]
                    logger.debug(f"✅ Strategy 1 (OpenAI format) succeeded: {repr(content[:100])}...")
                    return content
                else:
                    logger.debug(f"❌ Strategy 1 failed: choice structure: {choice}")
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
                    logger.debug(f"Found field '{field}': {content}")
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
            logger.debug(f"Strategy 4: Converting response to string: {response_str[:500]}...")
            json_match = re.search(r'\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}', response_str)
            if json_match:
                content = json_match.group(0)
                logger.debug(f"✅ Strategy 4 (string conversion) succeeded: {repr(content)}")
                return content
        except Exception as e:
            logger.debug(f"❌ Strategy 4 (string conversion) failed: {e}")
        
        logger.debug("❌ All content extraction strategies failed")
        logger.debug(f"Final response data that couldn't be parsed: {response_data}")
        return None 

    def _check_model_availability(self, model: str) -> bool:
        """Check if a model is available by making a simple test call."""
        try:
            test_payload = {
                "model": model,
                "messages": [{"role": "user", "content": "test"}],
                "max_tokens": 10,
                "temperature": 0.1
            }
            
            headers = Config.get_api_headers()
            
            response = requests.post(
                self.api_url,
                headers=headers,
                json=test_payload,
                timeout=30  # Short timeout for availability check
            )
            
            if response.status_code == 200:
                logger.debug(f"Model {model} is available")
                return True
            elif response.status_code == 400 and "model" in response.text.lower():
                logger.warning(f"Model {model} is not available: {response.text}")
                return False
            else:
                logger.warning(f"Model {model} availability check failed with status {response.status_code}")
                return True  # Assume available if we can't determine
                
        except Exception as e:
            logger.warning(f"Error checking model {model} availability: {e}")
            return True  # Assume available if we can't check
    
    def _get_available_fallback_models(self) -> List[str]:
        """Get list of available fallback models."""
        available_models = []
        for model in self.fallback_models:
            if self._check_model_availability(model):
                available_models.append(model)
            else:
                logger.info(f"Skipping unavailable model: {model}")
        return available_models 

    def _get_model_timeout(self, model: str) -> int:
        """Get appropriate timeout for a model based on its size and complexity."""
        # Timeout configuration based on model size and complexity
        timeout_config = {
            # Primary model - fast
            "qwen-2.5-qwq-32b": 120,
            
            # Large reasoning models - need more time
            "qwen3-235b": 300,      # 235B parameters, reasoning model
            "deepseek-r1-671b": 600, # 671B parameters, reasoning model - very slow
            
            # Large non-reasoning models - moderate time
            "llama-3.2-3b": 180,    # 3B parameters but 131k context
            "mistral-31-24b": 240,  # 24B parameters, 131k context
            
            # Medium models - standard time
            "llama-3.3-70b": 180,   # 70B parameters, 65k context
            "llama-3.1-405b": 300,  # 405B parameters, 65k context
        }
        
        timeout = timeout_config.get(model, 120)  # Default to 120 seconds
        logger.debug(f"Using timeout of {timeout} seconds for model {model}")
        return timeout 