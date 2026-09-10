#!/usr/bin/env python3
"""
Debug script for production issues with model fallback mechanism.
"""
import logging
import sys
from utils.api_client import VeniceAPIClient
from utils.config import Config

# Setup logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

def test_model_availability():
    """Test which models are available in the current environment."""
    logger.info("Testing model availability...")
    
    try:
        # Initialize the API client
        client = VeniceAPIClient()
        
        logger.info(f"Primary model: {client.model}")
        logger.info(f"All fallback models: {client.fallback_models}")
        logger.info(f"Available fallback models: {client.available_fallback_models}")
        
        # Test a simple API call with the primary model
        logger.info("Testing primary model with simple request...")
        test_prompt = "Say 'hello' in one word."
        
        response = client.call_api(test_prompt, max_tokens=10, temperature=0.1)
        
        if response:
            logger.info("✅ Primary model test successful")
            return True
        else:
            logger.error("❌ Primary model test failed")
            return False
            
    except Exception as e:
        logger.error(f"Error during model availability test: {e}")
        return False

def test_fallback_mechanism():
    """Test the fallback mechanism with a large prompt."""
    logger.info("Testing fallback mechanism...")
    
    try:
        # Initialize the API client
        client = VeniceAPIClient()
        
        # Create a large prompt that should trigger fallback
        large_prompt = "You are a legal data extraction expert. " + "This is a test. " * 10000
        
        logger.info(f"Testing with large prompt ({len(large_prompt)} characters)")
        
        response = client.call_api(large_prompt, max_tokens=100, temperature=0.1)
        
        if response:
            logger.info("✅ Fallback mechanism test successful")
            return True
        else:
            logger.error("❌ Fallback mechanism test failed")
            return False
            
    except Exception as e:
        logger.error(f"Error during fallback mechanism test: {e}")
        return False

def main():
    """Main debug function."""
    logger.info("Starting production debug tests...")
    
    # Validate configuration
    try:
        Config.validate()
        logger.info("✅ Configuration validation passed")
    except Exception as e:
        logger.error(f"❌ Configuration validation failed: {e}")
        return 1
    
    # Test model availability
    if not test_model_availability():
        logger.error("Model availability test failed")
        return 1
    
    # Test fallback mechanism
    if not test_fallback_mechanism():
        logger.error("Fallback mechanism test failed")
        return 1
    
    logger.info("✅ All tests passed!")
    return 0

if __name__ == "__main__":
    sys.exit(main()) 