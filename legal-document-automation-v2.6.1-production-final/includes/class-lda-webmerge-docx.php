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
            if (strlen($value) > 50) {
                $merge_summary[$key] = substr($value, 0, 50) . '...';
            } else {
                $merge_summary[$key] = $value;
            }
        }
        LDA_Logger::log("Merge data: " . json_encode($merge_summary, JSON_PRETTY_PRINT));
        
        // Copy template to output
        if (!copy($template_path, $output_path)) {
            LDA_Logger::log("Failed to copy template to output path");
            return array('success' => false, 'error' => 'Failed to copy template to output path');
        }
        
        // Open the DOCX file as a ZIP archive
        $zip = new ZipArchive();
        if ($zip->open($output_path) !== TRUE) {
            LDA_Logger::log("Failed to open DOCX file as ZIP archive");
            return array('success' => false, 'error' => 'Failed to open DOCX file as ZIP archive');
        }
        
        // Process the main document XML (original approach that worked)
        $document_xml = $zip->getFromName('word/document.xml');
        if ($document_xml === false) {
            LDA_Logger::log("Failed to read document.xml from DOCX");
            $zip->close();
            return array('success' => false, 'error' => 'Failed to read document.xml from DOCX');
        }
        
        // Process merge tags
        $processed_xml = self::processMergeTagsInXML($document_xml, $merge_data);
        
        // Write the processed XML back to the ZIP
        if ($zip->addFromString('word/document.xml', $processed_xml) === false) {
            LDA_Logger::log("Failed to write processed document.xml back to DOCX");
            $zip->close();
            return array('success' => false, 'error' => 'Failed to write processed document.xml back to DOCX');
        }
        
        $zip->close();
        LDA_Logger::log("Webmerge-compatible DOCX processing completed successfully");
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
     * Fix split merge tags that are broken across XML elements
     */
    private static function fixSplitMergeTags($xml_content) {
        LDA_Logger::log("Fixing split merge tags in XML");
        
        $fixed_count = 0;
        $max_iterations = 10; // Prevent infinite loops
        $iteration = 0;
        
        // Debug: Log a sample of the XML content to see what we're working with
        $sample = substr($xml_content, 0, 500);
        LDA_Logger::log("XML sample (first 500 chars): " . $sample);
        
        while ($iteration < $max_iterations) {
            $iteration++;
            $found_split = false;
            
            // Pattern 1: Simple split across w:t elements
            // Matches: {$USR_</w:t></w:r>...<w:t>Business}
            $pattern1 = '/\{\$([^<]*?)<\/w:t><\/w:r>(?:[^<]*?<w:r[^>]*>[^<]*?<w:t[^>]*>([^<]*?))+\}/';
            
            if (preg_match($pattern1, $xml_content, $matches)) {
                $full_match = $matches[0];
                $tag_parts = array();
                
                // Extract all parts of the split tag
                preg_match_all('/\{\$([^<]*?)<\/w:t><\/w:r>(?:[^<]*?<w:r[^>]*>[^<]*?<w:t[^>]*>([^<]*?))+\}/', $full_match, $parts);
                
                if (!empty($parts[1]) && !empty($parts[2])) {
                    // Reconstruct the complete tag
                    $complete_tag = '{$' . $parts[1][0] . implode('', $parts[2]) . '}';
                    
                    // Replace the split tag with the complete one
                    $xml_content = str_replace($full_match, $complete_tag, $xml_content);
                    $fixed_count++;
                    $found_split = true;
                    
                    LDA_Logger::log("Fixed split merge tag (pattern 1): " . substr($full_match, 0, 100) . "... -> " . $complete_tag);
                }
            }
            
            // Pattern 1b: Specific pattern for Login_ID and similar tags
            // Matches: {$</w:t></w:r><w:proofErr...><w:r...><w:t>Login_ID}
            $pattern1b = '/\{\$<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*>(?:<[^>]*>)*<w:t[^>]*>([A-Za-z0-9_]+)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*>(?:<[^>]*>)*<w:t[^>]*>\}/';
            
            if (preg_match($pattern1b, $xml_content, $matches)) {
                $full_match = $matches[0];
                $tag_name = $matches[1];
                $complete_tag = '{$' . $tag_name . '}';
                
                // Replace the split tag with the complete one
                $xml_content = str_replace($full_match, $complete_tag, $xml_content);
                $fixed_count++;
                $found_split = true;
                
                LDA_Logger::log("Fixed split merge tag (pattern 1b): " . substr($full_match, 0, 100) . "... -> " . $complete_tag);
            }
            
            // Pattern 2: Complex split with proofErr and other elements
            // Matches: {$</w:t></w:r><w:proofErr...><w:r...><w:t>Login_ID}
            $pattern2 = '/\{\$<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*>(?:<[^>]*>)*<w:t[^>]*>([^<]+)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*>(?:<[^>]*>)*<w:t[^>]*>\}/';
            
            if (preg_match($pattern2, $xml_content, $matches)) {
                $full_match = $matches[0];
                $tag_name = $matches[1];
                $complete_tag = '{$' . $tag_name . '}';
                
                // Replace the split tag with the complete one
                $xml_content = str_replace($full_match, $complete_tag, $xml_content);
                $fixed_count++;
                $found_split = true;
                
                LDA_Logger::log("Fixed split merge tag (pattern 2): " . substr($full_match, 0, 100) . "... -> " . $complete_tag);
            }
            
            // Pattern 2b: Split with proofErr and xml:space="preserve"
            // Matches: {$DISPLAY_</w:t></w:r><w:proofErr...><w:t xml:space="preserve">EMAIL}
            $pattern2b = '/\{\$([^<]*?)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*>(?:<[^>]*>)*<w:t[^>]*>([^<]+)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*>(?:<[^>]*>)*<w:t[^>]*>\}/';
            
            if (preg_match($pattern2b, $xml_content, $matches)) {
                $full_match = $matches[0];
                $tag_part1 = $matches[1];
                $tag_part2 = $matches[2];
                $complete_tag = '{$' . $tag_part1 . $tag_part2 . '}';
                
                // Replace the split tag with the complete one
                $xml_content = str_replace($full_match, $complete_tag, $xml_content);
                $fixed_count++;
                $found_split = true;
                
                LDA_Logger::log("Fixed split merge tag (pattern 2b): " . substr($full_match, 0, 100) . "... -> " . $complete_tag);
            }
            
            // Pattern 3: More complex split with multiple XML elements
            // Matches patterns like {$USR_</w:t></w:r><w:proofErr...><w:r...><w:t>Business}
            $pattern3 = '/\{\$([^<]*?)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*>(?:<[^>]*>)*<w:t[^>]*>([^<]+)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*>(?:<[^>]*>)*<w:t[^>]*>\}/';
            
            if (preg_match($pattern3, $xml_content, $matches)) {
                $full_match = $matches[0];
                $tag_part1 = $matches[1];
                $tag_part2 = $matches[2];
                $complete_tag = '{$' . $tag_part1 . $tag_part2 . '}';
                
                // Replace the split tag with the complete one
                $xml_content = str_replace($full_match, $complete_tag, $xml_content);
                $fixed_count++;
                $found_split = true;
                
                LDA_Logger::log("Fixed split merge tag (pattern 3): " . substr($full_match, 0, 100) . "... -> " . $complete_tag);
            }
            
            // Pattern 4: Specific pattern for Signatory tags
            // Matches: {$USR_</w:t></w:r><w:r...>Signatory_FN}
            $pattern4 = '/\{\$([A-Za-z0-9_]+)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*>(?:<[^>]*>)*<w:t[^>]*>([A-Za-z0-9_]+)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*>(?:<[^>]*>)*<w:t[^>]*>\}/';
            
            if (preg_match($pattern4, $xml_content, $matches)) {
                $full_match = $matches[0];
                $tag_part1 = $matches[1];
                $tag_part2 = $matches[2];
                $complete_tag = '{$' . $tag_part1 . $tag_part2 . '}';
                
                // Replace the split tag with the complete one
                $xml_content = str_replace($full_match, $complete_tag, $xml_content);
                $fixed_count++;
                $found_split = true;
                
                LDA_Logger::log("Fixed split merge tag (pattern 4): " . substr($full_match, 0, 100) . "... -> " . $complete_tag);
            }
            
            // Pattern 5: Nuclear option - find any {$...} that contains XML elements
            // This catches any merge tag that has been split by XML elements
            $pattern5 = '/\{\$([^}]*?)(?:<[^>]*>)+([^}]*?)\}/';
            
            if (preg_match($pattern5, $xml_content, $matches)) {
                $full_match = $matches[0];
                $tag_part1 = $matches[1];
                $tag_part2 = $matches[2];
                
                // Only fix if it looks like a real merge tag (not just random XML)
                if (preg_match('/^[A-Za-z0-9_]+$/', $tag_part1 . $tag_part2)) {
                    $complete_tag = '{$' . $tag_part1 . $tag_part2 . '}';
                    
                    // Replace the split tag with the complete one
                    $xml_content = str_replace($full_match, $complete_tag, $xml_content);
                    $fixed_count++;
                    $found_split = true;
                    
                    LDA_Logger::log("Fixed split merge tag (pattern 5): " . substr($full_match, 0, 100) . "... -> " . $complete_tag);
                }
            }
            
            // Pattern 6: Ultra-aggressive pattern for the specific cases we're seeing
            // This will catch patterns like {$DISPLAY_</w:t></w:r><w:proofErr...>EMAIL}
            $pattern6 = '/\{\$([A-Za-z0-9_]+)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*>(?:<[^>]*>)*<w:t[^>]*>([A-Za-z0-9_]+)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*>(?:<[^>]*>)*<w:t[^>]*>\}/';
            
            if (preg_match($pattern6, $xml_content, $matches)) {
                $full_match = $matches[0];
                $tag_part1 = $matches[1];
                $tag_part2 = $matches[2];
                $complete_tag = '{$' . $tag_part1 . $tag_part2 . '}';
                
                // Replace the split tag with the complete one
                $xml_content = str_replace($full_match, $complete_tag, $xml_content);
                $fixed_count++;
                $found_split = true;
                
                LDA_Logger::log("Fixed split merge tag (pattern 6): " . substr($full_match, 0, 100) . "... -> " . $complete_tag);
            }
            
            // Pattern 7: Even more aggressive - catch any split merge tag
            // This will catch the exact patterns from the logs
            $pattern7 = '/\{\$([A-Za-z0-9_]*?)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*>(?:<[^>]*>)*<w:t[^>]*>([A-Za-z0-9_]+)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*>(?:<[^>]*>)*<w:t[^>]*>\}/';
            
            if (preg_match($pattern7, $xml_content, $matches)) {
                $full_match = $matches[0];
                $tag_part1 = $matches[1];
                $tag_part2 = $matches[2];
                $complete_tag = '{$' . $tag_part1 . $tag_part2 . '}';
                
                // Replace the split tag with the complete one
                $xml_content = str_replace($full_match, $complete_tag, $xml_content);
                $fixed_count++;
                $found_split = true;
                
                LDA_Logger::log("Fixed split merge tag (pattern 7): " . substr($full_match, 0, 100) . "... -> " . $complete_tag);
            }
            
            // If no split tags were found in this iteration, we're done
            if (!$found_split) {
                break;
            }
        }
        
        LDA_Logger::log("Fixed {$fixed_count} split merge tags in {$iteration} iterations");
        return $xml_content;
    }
    
    /**
     * Process conditional logic like {if !empty($VARIABLE)}...{/if}
     */
    private static function processConditionalLogic($xml_content, $merge_data, &$replacements_made) {
        try {
            LDA_Logger::log("Processing conditional logic in XML");
            
            // Pattern to match {if !empty($VARIABLE)}...{/if} blocks
            $conditional_pattern = '/\{if\s+!empty\(\$([^)]+)\)\}(.*?)\{\/if\}/s';
            
            $xml_content = preg_replace_callback($conditional_pattern, function($matches) use ($merge_data, &$replacements_made) {
                try {
                    $variable = trim($matches[1]);
                    $content = $matches[2];
                    
                    // Get the value for the variable
                    $value = self::getMergeTagValue($variable, $merge_data);
                    
                    if (!empty($value)) {
                        LDA_Logger::log("Conditional block for {\$$variable} is TRUE, including content");
                        $replacements_made++;
                        return $content; // Include the content
                    } else {
                        LDA_Logger::log("Conditional block for {\$$variable} is FALSE, removing content");
                        $replacements_made++;
                        return ''; // Remove the content
                    }
                } catch (Exception $e) {
                    LDA_Logger::error("Error processing conditional logic: " . $e->getMessage());
                    return $matches[0]; // Return original content on error
                }
            }, $xml_content);
        } catch (Exception $e) {
            LDA_Logger::error("Error in processConditionalLogic: " . $e->getMessage());
            return $xml_content; // Return original content on error
        }
        
        // Also handle simple {if $VARIABLE}...{/if} patterns
        try {
            $simple_conditional_pattern = '/\{if\s+\$([^}]+)\}(.*?)\{\/if\}/s';
            
            $xml_content = preg_replace_callback($simple_conditional_pattern, function($matches) use ($merge_data, &$replacements_made) {
                try {
                    $variable = trim($matches[1]);
                    $content = $matches[2];
                    
                    // Get the value for the variable
                    $value = self::getMergeTagValue($variable, $merge_data);
                    
                    if (!empty($value)) {
                        LDA_Logger::log("Simple conditional block for {\$$variable} is TRUE, including content");
                        $replacements_made++;
                        return $content; // Include the content
                    } else {
                        LDA_Logger::log("Simple conditional block for {\$$variable} is FALSE, removing content");
                        $replacements_made++;
                        return ''; // Remove the content
                    }
                } catch (Exception $e) {
                    LDA_Logger::error("Error processing simple conditional logic: " . $e->getMessage());
                    return $matches[0]; // Return original content on error
                }
            }, $xml_content);
        } catch (Exception $e) {
            LDA_Logger::error("Error in simple conditional processing: " . $e->getMessage());
        }
        
        return $xml_content;
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
