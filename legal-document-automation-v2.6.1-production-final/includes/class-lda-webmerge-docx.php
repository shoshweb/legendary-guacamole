<?php
/**
 * Webmerge-compatible DOCX processor
 * 
 * This class processes DOCX files using the same approach as Webmerge,
 * handling merge tags in plain text first, then reconstructing the document.
 */

if (!defined('ABSPATH')) {
    exit;
}

class LDA_WebmergeDOCX {
    
    /**
     * Process merge tags in a DOCX file
     */
    public static function processMergeTags($template_path, $output_path, $merge_data) {
        LDA_Logger::log("Starting Webmerge-compatible DOCX processing");
        LDA_Logger::log("Template: $template_path");
        LDA_Logger::log("Output: $output_path");

        // Log merge data summary to avoid truncation
        $merge_summary = array();
        foreach ($merge_data as $key => $value) {
            if (is_scalar($value) && strlen($value) > 50) {
                $merge_summary[$key] = substr($value, 0, 50) . '...';
            } else {
                $merge_summary[$key] = $value;
            }
        }
        LDA_Logger::log("Merge data: " . json_encode($merge_summary, JSON_PRETTY_PRINT));
        
        // Copy template to output path
        if (!copy($template_path, $output_path)) {
            LDA_Logger::error("Failed to copy template to output path: $template_path -> $output_path");
            return array('success' => false, 'error' => 'Failed to copy template to output path');
        }
        
        // Open the DOCX file as a ZIP archive
        $zip = new ZipArchive();
        if ($zip->open($output_path) !== TRUE) {
            LDA_Logger::error("Failed to open DOCX file as ZIP archive: $output_path");
            return array('success' => false, 'error' => 'Failed to open DOCX file as ZIP archive');
        }
        
        // Define all possible XML parts of a DOCX file that can contain user content
        $xml_parts = array(
            'word/document.xml',
            'word/header1.xml', 'word/header2.xml', 'word/header3.xml',
            'word/footer1.xml', 'word/footer2.xml', 'word/footer3.xml',
        );
        
        $processed_files = 0;
        
        // Process each XML part
        foreach ($xml_parts as $part_name) {
            // Check if the part exists in the archive
            if ($zip->locateName($part_name) !== false) {
                $xml_content = $zip->getFromName($part_name);
                if ($xml_content === false) {
                    LDA_Logger::warn("Could not read XML part: $part_name");
                    continue;
                }

                LDA_Logger::log("Processing XML file: $part_name");

                // Process merge tags in the XML content
                $processed_xml = self::processMergeTagsInXML($xml_content, $merge_data);

                // Write the processed XML back to the archive
                if ($zip->addFromString($part_name, $processed_xml) === false) {
                    LDA_Logger::error("Failed to write processed XML back to DOCX for part: $part_name");
                    $zip->close();
                    return array('success' => false, 'error' => "Failed to write processed XML for $part_name");
                }

                $processed_files++;
                LDA_Logger::log("Processed XML file: $part_name");
            }
        }
        
        $zip->close();

        LDA_Logger::log("Enhanced DOCX processing completed. Processed $processed_files XML files");

        if ($processed_files === 0) {
            LDA_Logger::error("No XML parts were processed. The document may be empty or corrupt.");
            return array('success' => false, 'error' => 'No content found to process in the DOCX file.');
        }

        return array('success' => true, 'file_path' => $output_path);
    }
    
    /**
     * Process merge tags in XML content using comprehensive approach
     */
    private static function processMergeTagsInXML($xml_content, $merge_data) {
        LDA_Logger::log("Processing merge tags in XML using comprehensive approach");
        
        // Debug: Log available merge data keys
        $available_keys = array_keys($merge_data);
        LDA_Logger::log("Available merge data keys (" . count($available_keys) . "): " . implode(', ', array_slice($available_keys, 0, 20)) . (count($available_keys) > 20 ? '...' : ''));
        
        $replacements_made = 0;
        
        // Step 1: Fix split merge tags first (common DOCX issue)
        $xml_content = self::fixSplitMergeTags($xml_content);
        
        // Step 2: Process conditional logic
        $xml_content = self::processConditionalLogic($xml_content, $merge_data, $replacements_made);
        
        // Step 2: Find ALL merge tags in the XML (including split ones across XML elements)
        // First, try to find complete merge tags
        preg_match_all('/\{\$([^}|]+)(?:\|[^}]+)?\}/', $xml_content, $xml_tags);
        
        // Also look for split merge tags across XML elements (common DOCX issue)
        preg_match_all('/\{\$([^<]*?)(?:<\/w:t><\/w:r>.*?<w:r[^>]*>.*?<w:t[^>]*>([^<]*?))+\}/', $xml_content, $split_tags);
        
        // Look for any remaining split patterns that might have been missed
        preg_match_all('/\{\$([^}]*?)(?:<[^>]*>)+([^}]*?)\}/', $xml_content, $remaining_split_tags);
        
        $all_tags = array();
        if (!empty($xml_tags[1])) {
            $all_tags = array_merge($all_tags, $xml_tags[1]);
        }
        if (!empty($split_tags[1])) {
            $all_tags = array_merge($all_tags, $split_tags[1]);
        }
        if (!empty($remaining_split_tags[1])) {
            // Combine the parts of split tags
            for ($i = 0; $i < count($remaining_split_tags[1]); $i++) {
                $combined_tag = $remaining_split_tags[1][$i] . $remaining_split_tags[2][$i];
                if (preg_match('/^[A-Za-z0-9_]+$/', $combined_tag)) {
                    $all_tags[] = $combined_tag;
                }
            }
        }
        
        if (!empty($all_tags)) {
            $unique_xml_tags = array_unique($all_tags);
            LDA_Logger::log("Found merge tags in XML (" . count($unique_xml_tags) . "): " . implode(', ', $unique_xml_tags));
            
            // Step 3: Process each found merge tag
            foreach ($unique_xml_tags as $tag) {
                $tag = trim($tag);
                if (empty($tag)) continue;
                
                // Get value from merge data (try multiple variations)
                $value = self::getMergeTagValue($tag, $merge_data);
                
                if ($value !== null) {
                    $xml_content = self::replaceMergeTagInXML($xml_content, $tag, $value, $replacements_made);
                } else {
                    LDA_Logger::log("No value found for merge tag: {\$$tag}");
                }
            }
        } else {
            LDA_Logger::log("No merge tags found in XML content");
        }
        
        LDA_Logger::log("Total replacements made in XML: " . $replacements_made);
        
        return $xml_content;
    }
    
    /**
     * Fix split merge tags that are broken across XML elements.
     * This new function is more robust and handles all tag types ({$var}, {if...}, {/if}, etc.).
     */
    private static function fixSplitMergeTags($xml_content) {
        LDA_Logger::log("Fixing split merge tags in XML (Enhanced Method)");

        $fixed_count = 0;
        $max_iterations = 5; // Usually one is enough, but let's be safe.
        $iteration = 0;

        while ($iteration < $max_iterations) {
            $iteration++;
            $before_content = $xml_content;

            // General-purpose fixer for any {...} block.
            // It finds any content enclosed in curly braces.
            $xml_content = preg_replace_callback(
                '/\{([^}]+)\}/s',
                function($matches) use (&$fixed_count) {
                    $original_content = $matches[1];
                    
                    // If the content inside braces contains XML tags, it's a split tag.
                    if (strpos($original_content, '<') !== false) {
                        // To fix it, we simply strip all XML tags from the content.
                        $cleaned_content = preg_replace('/<[^>]+>/s', '', $original_content);
                        // Also, consolidate whitespace that might result from stripping tags.
                        $cleaned_content = trim(preg_replace('/\s+/', ' ', $cleaned_content));

                        // Only log if a change was actually made.
                        if ($original_content !== $cleaned_content) {
                            $fixed_count++;
                            LDA_Logger::log("Fixed split tag: {" . substr($original_content, 0, 100) . "} -> {" . $cleaned_content . "}");
                            return '{' . $cleaned_content . '}';
                        }
                    }
                    
                    // Not a split tag or no change needed, return the original match.
                    return '{' . $original_content . '}';
                }
            );

            // If no changes were made in this iteration, the document is clean and we can stop.
            if ($xml_content === $before_content) {
                break;
            }
        }

        LDA_Logger::log("Fixed {$fixed_count} split tags in {$iteration} iteration(s).");
        return $xml_content;
    }
    
    /**
     * Process conditional logic like {if ...}, {elseif ...}, {else}, {/if}
     * This advanced processor handles nested blocks and complex conditions.
     */
    private static function processConditionalLogic($xml_content, $merge_data, &$replacements_made) {
        LDA_Logger::log("Processing conditional logic (Advanced)");

        $max_iterations = 20; // Safety break for deep nesting or errors
        $iteration = 0;

        // This pattern finds the innermost conditional blocks first.
        // It uses a backreference \1 to ensure {if} is closed by {/if} and {listif} by {/listif}.
        $pattern = '/\{(if|listif)\s+([^}]+)\}((?:[^{}]|\{(?!\/?\1\b))*?)\{\/\1\}/s';

        while (preg_match($pattern, $xml_content) && $iteration < $max_iterations) {
            $iteration++;
            
            $xml_content = preg_replace_callback($pattern, function($matches) use ($merge_data, &$replacements_made) {
                $tag_type = $matches[1]; // 'if' or 'listif'
                $main_condition = $matches[2];
                $inner_content = $matches[3];

                // Evaluate the main {if} condition
                if (self::evaluateCondition($main_condition, $merge_data)) {
                    LDA_Logger::log("Conditional TRUE: {{$tag_type} {$main_condition}}");
                    // Condition is true, so we only need the content before the first {else} or {elseif}.
                    $content_parts = preg_split('/\{(elseif|else)/s', $inner_content, 2);
                    $replacements_made++;
                    return $content_parts[0];
                }

                // Main condition is false, check for {elseif} and {else} clauses.
                // Pattern to find all {elseif ...} and {else} clauses within the block.
                preg_match_all('/\{(elseif\s+([^}]+)|else)\}(.*?)(?=\{(?:elseif|else)\}|\z)/s', $inner_content, $clause_matches, PREG_SET_ORDER);

                foreach ($clause_matches as $clause) {
                    $is_elseif = strpos($clause[1], 'elseif') === 0;
                    if ($is_elseif) {
                        $elseif_condition = $clause[2];
                        if (self::evaluateCondition($elseif_condition, $merge_data)) {
                            LDA_Logger::log("Conditional TRUE: {elseif {$elseif_condition}}");
                            $replacements_made++;
                            return $clause[3]; // Return the content of this true elseif
                        }
                    } else { // It's an {else}
                        LDA_Logger::log("Conditional ELSE triggered.");
                        $replacements_made++;
                        return $clause[3]; // Return the content of the else block
                    }
                }

                // All conditions were false, remove the entire block.
                LDA_Logger::log("All conditionals FALSE for {{$tag_type} {$main_condition}}. Removing block.");
                $replacements_made++;
                return '';

            }, $xml_content, 1); // Limit to 1 replacement per iteration to handle nesting correctly.
        }

        if ($iteration >= $max_iterations) {
            LDA_Logger::error("Exceeded max iterations in conditional logic processing. Check for unclosed tags or infinite loops in template.");
        }
        
        return $xml_content;
    }

    /**
     * Evaluate a condition string from the template.
     * Handles `and`, `==`, `!=`, `empty()`, `!empty()`, and simple variable checks.
     */
    private static function evaluateCondition($condition, $merge_data) {
        $condition = trim($condition);
        LDA_Logger::log("Evaluating condition: [{$condition}]");

        // Split by 'and' or '&&' to evaluate each part of the condition.
        $sub_conditions = preg_split('/\s+(and|&&)\s+/i', $condition);

        foreach ($sub_conditions as $sub_c) {
            $sub_c = trim($sub_c);
            $result = false;

            // Check for empty($VAR) or !empty($VAR)
            if (preg_match('/^(!?)\s*empty\(\$([a-zA-Z0-9_]+)\)$/', $sub_c, $matches)) {
                $negation = $matches[1] === '!';
                $variable_name = $matches[2];
                $value = self::getMergeTagValue($variable_name, $merge_data);
                $is_empty = (empty($value) || $value === '');
                $result = $negation ? !$is_empty : $is_empty;
            }
            // Check for comparisons like $VAR == "string" or $VAR != 'string'
            else if (preg_match('/^\$([a-zA-Z0-9_]+)\s*(==|!=)\s*["\'](.*?)["\']$/', $sub_c, $matches)) {
                $variable_name = $matches[1];
                $operator = $matches[2];
                $literal_value = $matches[3];
                $actual_value = self::getMergeTagValue($variable_name, $merge_data);
                if ($operator === '==') {
                    $result = (strval($actual_value) == strval($literal_value));
                } else { // !=
                    $result = (strval($actual_value) != strval($literal_value));
                }
            }
            // Check for simple variable existence like {$VAR}
            else if (preg_match('/^\$([a-zA-Z0-9_]+)$/', $sub_c, $matches)) {
                $variable_name = $matches[1];
                $actual_value = self::getMergeTagValue($variable_name, $merge_data);
                $result = !empty($actual_value) && $actual_value !== '';
            }
            else {
                LDA_Logger::warn("Could not parse sub-condition: [{$sub_c}]");
                return false; // Fail safe to false
            }

            // If any part of an AND chain is false, the whole condition is false.
            if (!$result) {
                LDA_Logger::log("Sub-condition [{$sub_c}] evaluated to FALSE. Entire condition is FALSE.");
                return false;
            }
        }
        
        // If the loop completes, all sub-conditions were true.
        LDA_Logger::log("All sub-conditions evaluated to TRUE for [{$condition}]. Entire condition is TRUE.");
        return true;
    }
    
    /**
     * Get merge tag value with multiple fallback strategies
     */
    private static function getMergeTagValue($tag, $merge_data) {
        try {
            if (!is_array($merge_data) || empty($tag)) {
                return null;
            }
            
            // Try exact match first
            if (isset($merge_data[$tag])) {
                return $merge_data[$tag];
            }
            
            // Try case-insensitive match
            foreach ($merge_data as $key => $value) {
                if (strcasecmp($key, $tag) === 0) {
                    return $value;
                }
            }
            
            // Try partial matches (for dynamic field names)
            foreach ($merge_data as $key => $value) {
                if (stripos($key, $tag) !== false || stripos($tag, $key) !== false) {
                    LDA_Logger::log("Found partial match for tag '{$tag}' in key '{$key}' with value: '{$value}'");
                    return $value;
                }
            }
            
            return null;
        } catch (Exception $e) {
            LDA_Logger::error("Error in getMergeTagValue: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Replace a specific merge tag in XML with multiple patterns
     */
    private static function replaceMergeTagInXML($xml_content, $tag, $value, &$replacements_made) {
        try {
            if (empty($tag) || empty($xml_content)) {
                return $xml_content;
            }
            
            // Handle tags with modifiers first
            $modifier_pattern = '/\{\$' . preg_quote($tag, '/') . '\|([^}]+)\}/';
            if (preg_match($modifier_pattern, $xml_content, $matches)) {
                try {
                    $modifier_part = $matches[1];
                    $processed_value = self::processModifiersInText($value, $modifier_part);
                    $before = $xml_content;
                    $xml_content = preg_replace($modifier_pattern, htmlspecialchars($processed_value, ENT_XML1, 'UTF-8'), $xml_content);
                    if ($before !== $xml_content) {
                        $replacements_made++;
                        LDA_Logger::log("Replaced {\$$tag|modifier} in XML with: " . $processed_value);
                        return $xml_content;
                    }
                } catch (Exception $e) {
                    LDA_Logger::error("Error processing modifier for tag {$tag}: " . $e->getMessage());
                }
            }
            
            // Handle simple tags with multiple patterns
            $patterns = array(
                // Standard pattern
                '/\{\$' . preg_quote($tag, '/') . '\}/',
                // Pattern to catch tags split across XML elements
                '/\{\$' . preg_quote($tag, '/') . '(?:<[^>]*>[^<]*)*\}/',
                // Pattern to catch tags with any content between $ and }
                '/\{\$' . preg_quote($tag, '/') . '[^}]*\}/',
                // Pattern to catch tags split by any XML tags
                '/\{\$' . preg_quote($tag, '/') . '(?:[^<}]|<[^>]*>)*\}/',
                // Nuclear option - catch anything between {$VARIABLE and }
                '/\{\$' . preg_quote($tag, '/') . '.*?\}/s'
            );
            
            foreach ($patterns as $pattern_index => $pattern) {
                try {
                    $before = $xml_content;
                    $xml_content = preg_replace($pattern, htmlspecialchars($value, ENT_XML1, 'UTF-8'), $xml_content);
                    if ($before !== $xml_content) {
                        $replacements_made++;
                        LDA_Logger::log("Replaced {\$$tag} in XML with pattern " . ($pattern_index + 1) . ": " . $value);
                        return $xml_content;
                    }
                } catch (Exception $e) {
                    LDA_Logger::error("Error with pattern " . ($pattern_index + 1) . " for tag {$tag}: " . $e->getMessage());
                }
            }
            
            return $xml_content;
        } catch (Exception $e) {
            LDA_Logger::error("Error in replaceMergeTagInXML for tag {$tag}: " . $e->getMessage());
            return $xml_content;
        }
    }
    
    /**
     * Process modifiers in plain text
     */
    private static function processModifiersInText($value, $modifier_part) {
        // Handle date_format modifier
        if (strpos($modifier_part, 'date_format') === 0) {
            $format = str_replace('date_format:', '', $modifier_part);
            $format = trim($format, '"');
            return self::formatDate($value, $format);
        }
        
        // Handle phone_format modifier
        if (strpos($modifier_part, 'phone_format') === 0) {
            $format = str_replace('phone_format:', '', $modifier_part);
            $format = trim($format, '"');
            return self::formatPhone($value, $format);
        }
        
        // Handle replace modifier
        if (strpos($modifier_part, 'replace') === 0) {
            $params = str_replace('replace:', '', $modifier_part);
            $params = trim($params, '"');
            $parts = explode(':', $params);
            if (count($parts) >= 2) {
                return str_replace($parts[0], $parts[1], $value);
            }
        }
        
        // Handle upper modifier
        if ($modifier_part === 'upper') {
            return strtoupper($value);
        }
        
        // Handle lower modifier
        if ($modifier_part === 'lower') {
            return strtolower($value);
        }
        
        return $value;
    }
    
    /**
     * Replace text content in XML while preserving structure
     */
    private static function replaceTextInXML($xml_content, $new_text) {
        // Instead of trying to replace the entire text content (which corrupts the XML),
        // we'll process the merge tags directly in the XML using a more careful approach
        
        // Extract merge tags from the new text and apply them to the XML
        preg_match_all('/\{\$([^}|]+)(?:\|([^}]+))?\}/', $new_text, $matches, PREG_SET_ORDER);
        
        foreach ($matches as $match) {
            $full_tag = $match[0];
            $tag_name = $match[1];
            $modifier = isset($match[2]) ? $match[2] : '';
            
            // Find the replacement value in the new text
            $replacement = '';
            if (preg_match('/' . preg_quote($full_tag, '/') . '\s*([^{]*?)(?=\{\$|$)/', $new_text, $replacement_matches)) {
                $replacement = trim($replacement_matches[1]);
            }
            
            if (!empty($replacement)) {
                // Replace the merge tag in the XML with the processed value
                $xml_content = preg_replace('/\{\$' . preg_quote($tag_name, '/') . '(?:\|[^}]+)?\}/', htmlspecialchars($replacement, ENT_XML1, 'UTF-8'), $xml_content);
            }
        }
        
        return $xml_content;
    }
    
    /**
     * Format date according to format string
     */
    private static function formatDate($date, $format) {
        if (empty($date)) {
            return '';
        }
        
        // Try to parse the date
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return $date; // Return original if can't parse
        }
        
        // Convert format string to PHP date format
        $php_format = str_replace(
            array('d', 'F', 'Y', 'm', 'y'),
            array('d', 'F', 'Y', 'm', 'y'),
            $format
        );
        
        return date($php_format, $timestamp);
    }
    
    /**
     * Format phone number according to format string
     */
    private static function formatPhone($phone, $format) {
        if (empty($phone)) {
            return '';
        }
        
        // Remove all non-digits
        $digits = preg_replace('/\D/', '', $phone);
        
        // Apply format pattern
        $formatted = $format;
        $digit_index = 0;
        
        for ($i = 0; $i < strlen($format); $i++) {
            if ($format[$i] === '%' && $i + 1 < strlen($format)) {
                $next_char = $format[$i + 1];
                if (is_numeric($next_char) && $digit_index < strlen($digits)) {
                    $formatted = str_replace('%' . $next_char, $digits[$digit_index], $formatted);
                    $digit_index++;
                }
            }
        }
        
        return $formatted;
    }
    
    /**
     * Check if this processor is available
     */
    public static function isAvailable() {
        return class_exists('ZipArchive');
    }
}