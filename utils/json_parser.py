"""
JSON parsing utilities for the DOJ cases project.
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
    Clean and parse JSON from raw text using multiple strategies.
    Returns parsed JSON object/array or None if parsing fails.
    """
    if raw_text is None or (isinstance(raw_text, str) and raw_text.strip() == ''):
        logger.debug("Input to clean_and_parse_json is None or empty string.")
        return None
    
    logger.debug(f"Raw text length: {len(raw_text) if isinstance(raw_text, str) else 'not a string'}")
    logger.debug(f"Raw text preview: {raw_text[:500] if isinstance(raw_text, str) else 'not a string'}...")
    
    # Strategy 1: Remove think tags and other common AI artifacts
    cleaned_text = raw_text
    if isinstance(cleaned_text, str):
        # Remove think tags more aggressively
        cleaned_text = re.sub(r'<think>.*?</think>', '', cleaned_text, flags=re.DOTALL | re.IGNORECASE)
        cleaned_text = re.sub(r'<thinking>.*?</thinking>', '', cleaned_text, flags=re.DOTALL | re.IGNORECASE)
        cleaned_text = re.sub(r'<reasoning>.*?</reasoning>', '', cleaned_text, flags=re.DOTALL | re.IGNORECASE)
        # Remove any remaining XML-like tags
        cleaned_text = re.sub(r'<[^>]+>', '', cleaned_text)
        logger.debug(f"After cleaning, text length: {len(cleaned_text)}")
        logger.debug(f"Cleaned text preview: {cleaned_text[:500]}...")
    
    # Strategy 2: Extract JSON from markdown code blocks first
    if isinstance(cleaned_text, str):
        # Look for JSON in markdown code blocks
        code_block_patterns = [
            r'```json\s*(\{.*?\})\s*```',  # ```json { ... } ```
            r'```json\s*(\[.*?\])\s*```',  # ```json [ ... ] ```
            r'```\s*(\{.*?\})\s*```',      # ``` { ... } ```
            r'```\s*(\[.*?\])\s*```',      # ``` [ ... ] ```
        ]
        
        for i, pattern in enumerate(code_block_patterns, 1):
            logger.debug(f"Strategy 2.{i}: Looking for JSON in code blocks with pattern: {pattern}")
            matches = list(re.finditer(pattern, cleaned_text, re.DOTALL | re.IGNORECASE))
            logger.debug(f"Found {len(matches)} code block matches with pattern {i}")
            
            for match in matches:
                json_str = match.group(1)
                logger.debug(f"  Extracted from code block: {repr(json_str)}")
                try:
                    # Clean the JSON string
                    cleaned = clean_json_string(json_str)
                    logger.debug(f"  Cleaned: {repr(cleaned)}")
                    
                    if cleaned:
                        parsed = json.loads(cleaned)
                        logger.debug(f"  Parsed successfully: {type(parsed)}")
                        
                        # Validate that this is a complete object with expected fields
                        if isinstance(parsed, dict):
                            # Check if this looks like a complete metadata object
                            expected_fields = ['district_office', 'usa_name', 'event_type', 'judge_name', 'judge_title', 'case_number', 'max_penalty_text', 'sentence_summary', 'money_amounts', 'crypto_assets', 'statutes_json', 'timeline_json']
                            found_fields = [field for field in expected_fields if field in parsed]
                            if len(found_fields) >= 3:  # At least 3 expected fields present
                                logger.debug(f"  ✅ Valid complete JSON object found in code block with {len(found_fields)} expected fields")
                                return parsed
                            else:
                                logger.debug(f"  ❌ JSON object missing expected fields. Found: {found_fields}")
                        else:
                            logger.debug(f"  ❌ Parsed result is not a dict: {type(parsed)}")
                    else:
                        logger.debug(f"  ❌ JSON cleaning failed for: {repr(json_str)}")
                except (json.JSONDecodeError, TypeError) as e:
                    logger.debug(f"  ❌ JSON decode error: {e}")
                    continue
    
    # Strategy 3: Look for the LAST complete JSON object in the text (most likely the actual output)
    if isinstance(cleaned_text, str):
        # Use a more robust pattern to find complete JSON objects
        json_patterns = [
            # Standard JSON object with balanced braces
            r'\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}',
            # JSON object that ends at line end or followed by non-comma
            r'\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}(?=\s*$|\s*[^,])',
        ]
        
        for i, pattern in enumerate(json_patterns, 1):
            logger.debug(f"Strategy 3.{i}: Trying pattern for complete JSON objects")
            matches = list(re.finditer(pattern, cleaned_text, re.DOTALL | re.IGNORECASE))
            logger.debug(f"Found {len(matches)} matches with pattern {i}")
            
            # Process matches in reverse order to get the LAST (most recent) JSON object
            for match in reversed(matches):
                json_str = match.group(0)
                logger.debug(f"  Match: {repr(json_str)}")
                try:
                    # Clean the JSON string
                    cleaned = clean_json_string(json_str)
                    logger.debug(f"  Cleaned: {repr(cleaned)}")
                    
                    if cleaned:
                        parsed = json.loads(cleaned)
                        logger.debug(f"  Parsed successfully: {type(parsed)}")
                        
                        # Validate that this is a complete object with expected fields
                        if isinstance(parsed, dict):
                            # Check if this looks like a complete metadata object
                            expected_fields = ['district_office', 'usa_name', 'event_type', 'judge_name', 'judge_title', 'case_number', 'max_penalty_text', 'sentence_summary', 'money_amounts', 'crypto_assets', 'statutes_json', 'timeline_json']
                            found_fields = [field for field in expected_fields if field in parsed]
                            if len(found_fields) >= 3:  # At least 3 expected fields present
                                logger.debug(f"  ✅ Valid complete JSON object found with {len(found_fields)} expected fields")
                                return parsed
                            else:
                                logger.debug(f"  ❌ JSON object missing expected fields. Found: {found_fields}")
                        else:
                            logger.debug(f"  ❌ Parsed result is not a dict: {type(parsed)}")
                    else:
                        logger.debug(f"  ❌ JSON cleaning failed for: {repr(json_str)}")
                except (json.JSONDecodeError, TypeError) as e:
                    logger.debug(f"  ❌ JSON decode error: {e}")
                    continue
    
    # Strategy 4: Look for JSON arrays (for tables that expect arrays)
    if isinstance(cleaned_text, str):
        # Look for complete JSON arrays
        array_pattern = r'\[\s*(?:[^[\]]*|\[[^[\]]*\])*\s*\]'
        array_matches = list(re.finditer(array_pattern, cleaned_text, re.DOTALL))
        if array_matches:
            # Process matches in reverse order to get the LAST array
            for match in reversed(array_matches):
                json_str = match.group(0)
                try:
                    cleaned = clean_json_string(json_str)
                    if cleaned:
                        parsed = json.loads(cleaned)
                        logger.debug(f"Successfully parsed JSON array with {len(parsed) if isinstance(parsed, list) else 'unknown'} items")
                        return parsed
                except json.JSONDecodeError as e:
                    logger.debug(f"Failed to parse array JSON: {e}")
                    continue
    
    # Strategy 5: Try to extract JSON after common markers
    if isinstance(cleaned_text, str):
        json_markers = [
            r'JSON Output:\s*(\{.*?\})',
            r'JSON Output:\s*(\[.*?\])',
            r'Output:\s*(\{.*?\})',
            r'Output:\s*(\[.*?\])',
            r'Result:\s*(\{.*?\})',
            r'Result:\s*(\[.*?\])'
        ]
        for pattern in json_markers:
            match = re.search(pattern, cleaned_text, re.DOTALL)
            if match:
                json_str = match.group(1)
                try:
                    cleaned = clean_json_string(json_str)
                    if cleaned:
                        parsed = json.loads(cleaned)
                        logger.debug(f"Successfully parsed JSON from marker pattern: {type(parsed)}")
                        return parsed
                except json.JSONDecodeError as e:
                    logger.debug(f"Failed to parse JSON from marker: {e}")
                    continue
    
    # Strategy 6: Look for the last occurrence of a JSON-like structure in the text
    if isinstance(cleaned_text, str):
        # Split the text and look for JSON in the last few lines
        lines = cleaned_text.split('\n')
        for line in reversed(lines[-10:]):  # Check last 10 lines
            line = line.strip()
            if line.startswith('{') and line.endswith('}'):
                try:
                    cleaned = clean_json_string(line)
                    if cleaned:
                        parsed = json.loads(cleaned)
                        logger.debug(f"Successfully parsed JSON from last lines: {type(parsed)}")
                        return parsed
                except json.JSONDecodeError:
                    continue
    
    # Strategy 7: Handle truncated responses by looking for the last complete JSON object
    if isinstance(cleaned_text, str):
        # Look for the last complete JSON object that might be truncated
        # This handles cases where the AI response was cut off mid-JSON
        json_start_pattern = r'\{[^{}]*$'  # JSON object that starts but doesn't end
        matches = list(re.finditer(json_start_pattern, cleaned_text, re.DOTALL))
        
        if matches:
            # Get the last match and try to complete it
            last_match = matches[-1]
            start_pos = last_match.start()
            partial_json = cleaned_text[start_pos:]
            
            # Try to find a reasonable ending point
            # Look for common patterns that might indicate where the JSON should end
            end_patterns = [
                r'\n\s*\n',  # Double newline
                r'\n\s*[A-Z]',  # Newline followed by capital letter (start of new sentence)
                r'\n\s*[0-9]',  # Newline followed by number
                r'\n\s*[•\-*]',  # Newline followed by bullet point
            ]
            
            for pattern in end_patterns:
                end_match = re.search(pattern, partial_json)
                if end_match:
                    partial_json = partial_json[:end_match.start()]
                    break
            
            # Try to complete the JSON by adding missing closing braces
            brace_count = partial_json.count('{') - partial_json.count('}')
            if brace_count > 0:
                partial_json += '}' * brace_count
            
            try:
                cleaned = clean_json_string(partial_json)
                if cleaned:
                    parsed = json.loads(cleaned)
                    logger.debug(f"Successfully parsed truncated JSON: {type(parsed)}")
                    return parsed
            except json.JSONDecodeError:
                pass
    
    # Strategy 8: Try to parse the entire cleaned text as JSON
    if isinstance(cleaned_text, str):
        try:
            cleaned = clean_json_string(cleaned_text.strip())
            if cleaned:
                parsed = json.loads(cleaned)
                logger.debug(f"Successfully parsed entire cleaned text as JSON: {type(parsed)}")
                return parsed
        except json.JSONDecodeError as e:
            logger.debug(f"Failed to parse entire text as JSON: {e}")
    
    logger.warning("All JSON extraction strategies failed.")
    logger.debug(f"Final cleaned text that couldn't be parsed: {cleaned_text[:1000] if isinstance(cleaned_text, str) else 'not a string'}...")
    return None 