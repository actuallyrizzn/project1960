"""
Logging configuration for the Project1960.
"""
import logging
from .config import Config

def setup_logging(level: str = None, format_string: str = None) -> None:
    """
    Setup logging configuration with standardized format.
    
    Args:
        level: Logging level (DEBUG, INFO, WARNING, ERROR, CRITICAL)
        format_string: Custom log format string
    """
    # Use configuration defaults if not provided
    level = level or Config.LOG_LEVEL
    format_string = format_string or Config.LOG_FORMAT
    
    # Convert string level to logging constant
    numeric_level = getattr(logging, level.upper(), logging.INFO)
    
    # Configure basic logging
    logging.basicConfig(
        level=numeric_level,
        format=format_string,
        handlers=[
            logging.StreamHandler(),  # Console output
        ]
    )
    
    # Set specific logger levels
    logging.getLogger('urllib3').setLevel(logging.WARNING)
    logging.getLogger('requests').setLevel(logging.WARNING)
    
    # Log the configuration
    logger = logging.getLogger(__name__)
    logger.debug(f"Logging configured with level: {level}, format: {format_string}")

def get_logger(name: str) -> logging.Logger:
    """
    Get a logger with the specified name.
    
    Args:
        name: Logger name (usually __name__)
        
    Returns:
        Configured logger instance
    """
    return logging.getLogger(name)

def set_log_level(level: str) -> None:
    """
    Set the logging level for the root logger.
    
    Args:
        level: Logging level (DEBUG, INFO, WARNING, ERROR, CRITICAL)
    """
    numeric_level = getattr(logging, level.upper(), logging.INFO)
    logging.getLogger().setLevel(numeric_level)
    
    logger = logging.getLogger(__name__)
    logger.info(f"Log level set to: {level}")

def enable_debug_logging() -> None:
    """Enable debug level logging."""
    set_log_level('DEBUG')

def enable_info_logging() -> None:
    """Enable info level logging."""
    set_log_level('INFO')

def enable_warning_logging() -> None:
    """Enable warning level logging."""
    set_log_level('WARNING') 