"""
JSON parsing utilities for the Project1960.
"""
import json
import re
import logging
from typing import Optional, Any, Dict, List

logger = logging.getLogger(__name__)

def clean_json_string(json_str: str) -> Optional[str]:
    """
    Clean and normalize a potentially dirty JSON string.
    Handles common issues like trailing commas, unquoted keys, etc.
    """
    if not json_str or not isinstance(json_str, str):
        logger.debug("JSON string is None or not a string")
        return None
    
    logger.debug(f"Cleaning JSON string: {repr(json_str)}")
    
    # Remove leading/trailing whitespace
    original = json_str
    json_str = json_str.strip()
    if json_str != original:
        logger.debug(f"  Stripped whitespace: {repr(json_str)}")
    
    # Common fixes for dirty JSON
    fixes = [
        # Remove trailing commas before closing braces/brackets
        (r',(\s*[}\]])', r'\1'),
        # Fix unquoted keys (basic cases)
        (r'(\s*)(\w+)(\s*):', r'\1"\2"\3:'),
        # Fix single quotes to double quotes
        (r"'([^']*)'", r'"\1"'),
        # Remove any non-printable characters
        (r'[\x00-\x1f\x7f-\x9f]', ''),
        # Fix common escape issues
        (r'\\"', '"'),
        (r'\\n', ' '),
        (r'\\t', ' '),
    ]
    
    for i, (pattern, replacement) in enumerate(fixes, 1):
        before = json_str
        json_str = re.sub(pattern, replacement, json_str)
        if json_str != before:
            logger.debug(f"  Fix {i}: {pattern} -> {replacement}")
            logger.debug(f"    Before: {repr(before)}")
            logger.debug(f"    After:  {repr(json_str)}")
    
    logger.debug(f"Final cleaned JSON: {repr(json_str)}")
    return json_str

def extract_json_from_content(content: str) -> Optional[Dict[str, Any]]:
    """
    Extract JSON from AI response content using multiple strategies.
    Returns the parsed JSON object or None if extraction fails.
    """
    if not content or not isinstance(content, str):
        logger.debug("Content is None or not a string")
        return None
    
    logger.debug(f"Attempting to extract JSON from content: {repr(content[:200])}...")
    
    # Strategy 1: Look for complete JSON objects with balanced braces
    json_patterns = [
        # Standard JSON object
        r'\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}',
        # JSON with nested objects (more complex)
        r'\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}(?=\s*$|\s*[^,])',
        # Simple key-value pairs
        r'\{[^}]*"answer"[^}]*\}',
    ]
    
    for i, pattern in enumerate(json_patterns, 1):
        logger.debug(f"Strategy 1.{i}: Trying pattern {pattern}")
        matches = re.findall(pattern, content, re.DOTALL | re.IGNORECASE)
        logger.debug(f"Found {len(matches)} matches with pattern {i}")
        
        for j, match in enumerate(matches):
            logger.debug(f"  Match {j+1}: {repr(match)}")
            try:
                # Clean the JSON string
                cleaned = clean_json_string(match)
                logger.debug(f"  Cleaned: {repr(cleaned)}")
                
                if cleaned:
                    parsed = json.loads(cleaned)
                    logger.debug(f"  Parsed successfully: {parsed}")
                    
                    if isinstance(parsed, dict) and "answer" in parsed:
                        logger.debug(f"  ✅ Valid JSON with 'answer' field found: {parsed}")
                        return parsed
                    else:
                        logger.debug(f"  ❌ JSON parsed but missing 'answer' field: {parsed}")
                else:
                    logger.debug(f"  ❌ JSON cleaning failed for: {repr(match)}")
            except (json.JSONDecodeError, TypeError) as e:
                logger.debug(f"  ❌ JSON decode error: {e}")
                continue
    
    # Strategy 2: Look for answer patterns in the text
    logger.debug("Strategy 2: Looking for answer patterns in text")
    answer_patterns = [
        r'"answer"\s*:\s*"([^"]+)"',
        r"'answer'\s*:\s*'([^']+)'",
        r'answer\s*:\s*"([^"]+)"',
        r'answer\s*:\s*\'([^\']+)\'',
    ]
    
    for i, pattern in enumerate(answer_patterns, 1):
        logger.debug(f"  Trying answer pattern {i}: {pattern}")
        match = re.search(pattern, content, re.IGNORECASE)
        if match:
            answer = match.group(1).lower().strip()
            logger.debug(f"  Found answer: {repr(answer)}")
            if answer in ["yes", "no", "unknown"]:
                logger.debug(f"  ✅ Valid answer extracted: {answer}")
                return {"answer": answer}
            else:
                logger.debug(f"  ❌ Invalid answer value: {repr(answer)}")
    
    # Strategy 3: Look for direct yes/no/unknown responses
    logger.debug("Strategy 3: Looking for direct yes/no/unknown responses")
    direct_patterns = [
        r'\b(yes|no|unknown)\b',
        r'{\s*"answer"\s*:\s*"(yes|no|unknown)"\s*}',
    ]
    
    for i, pattern in enumerate(direct_patterns, 1):
        logger.debug(f"  Trying direct pattern {i}: {pattern}")
        match = re.search(pattern, content, re.IGNORECASE)
        if match:
            answer = match.group(1).lower().strip()
            logger.debug(f"  ✅ Direct answer found: {answer}")
            return {"answer": answer}
    
    logger.debug("❌ All extraction strategies failed")
    return None

def clean_and_parse_json(raw_text: str) -> Optional[Any]:
    """
    Cleans and parses JSON from raw text, gracefully handling strings that
    are malformed or wrapped in other text (like markdown).

    This function first tries to find a JSON object or array that might be
    wrapped in markdown or other text. It greedily looks for the largest
line-spanning text between `{...}` or `[...]`.

    Args:
        raw_text: The raw string response from the AI.

    Returns:
        A parsed Python object (dict or list), or None if no valid JSON
        could be extracted.
    """
    if not isinstance(raw_text, str):
        return None

    # Strategy 1: Find JSON within markdown code blocks.
    # This is often the most reliable way to get clean JSON from LLMs.
    match = re.search(r'```(json)?\s*([\s\S]*?)\s*```', raw_text)
    if match:
        potential_json = match.group(2).strip()
        try:
            return json.loads(potential_json)
        except json.JSONDecodeError:
            logger.warning(f"Failed to decode JSON from markdown block: {potential_json[:200]}...")

    # Strategy 2: Find the last complete JSON object or array in the text.
    # This is more robust than a simple greedy search, as it respects nesting.
    json_pattern = re.compile(
        r"""
        (
            \{
                [^{}]*
                (?:
                    \{ [^{}]* \}
                    [^{}]*
                )*
            \}
            |
            \[
                [^[\]]*
                (?:
                    \[ [^[]]* \]
                    [^[\]]*
                )*
            \]
        )
        """, re.VERBOSE
    )

    matches = json_pattern.findall(raw_text)

    if not matches:
        logger.warning("No complete JSON object or array found in the text.")
        return None

    # The last match is the most likely candidate for the final, correct output.
    last_match = matches[-1]
    logger.debug(f"Found potential JSON in last match: {last_match[:200]}...")

    try:
        return json.loads(last_match)
    except json.JSONDecodeError as e:
        logger.error(f"Failed to decode the last found JSON object/array: {e}")
        return None