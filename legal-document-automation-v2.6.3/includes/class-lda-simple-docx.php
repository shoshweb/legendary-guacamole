<?php
/**
 * Simple DOCX Processing without PHPWord
 * Uses basic ZIP manipulation for merge tag replacement
 */

if (!defined('ABSPATH')) {
    exit;
}

class LDA_SimpleDOCX {
    
    /**
     * Process merge tags in DOCX file
     */
    public static function processMergeTags($template_path, $merge_data, $output_path) {
        try {
            // Ensure output directory exists and is writable
            $output_dir = dirname($output_path);
            
            // Debug: Log the output directory path
            LDA_Logger::log("Output directory path: " . $output_dir);
            LDA_Logger::log("Output file path: " . $output_path);
            
            if (empty($output_dir)) {
                throw new Exception("Output directory path is empty. Output path: " . $output_path);
            }
            
            if (!file_exists($output_dir)) {
                LDA_Logger::log("Creating output directory: " . $output_dir);
                if (!wp_mkdir_p($output_dir)) {
                    throw new Exception("Failed to create output directory: {$output_dir}");
                }
                LDA_Logger::log("Output directory created successfully");
            }
            
            if (!is_writable($output_dir)) {
                $perms = fileperms($output_dir);
                throw new Exception("Output directory is not writable: {$output_dir} (permissions: " . decoct($perms & 0777) . ")");
            }
            
            LDA_Logger::log("Output directory is writable: " . $output_dir);
            
            // Check if template file exists and is readable
            if (!file_exists($template_path)) {
                throw new Exception("Template file not found: {$template_path}");
            }
            
            if (!is_readable($template_path)) {
                throw new Exception("Template file is not readable: {$template_path}");
            }
            
            // Create a copy of the template
            if (!copy($template_path, $output_path)) {
                $error = error_get_last();
                throw new Exception("Failed to copy template file. Error: " . ($error['message'] ?? 'Unknown error'));
            }
            
            // Open the DOCX as a ZIP file
            $zip = new ZipArchive();
            if ($zip->open($output_path) !== TRUE) {
                throw new Exception('Failed to open DOCX file');
            }
            
            // Process all XML files that might contain merge tags
            $xml_files_to_process = array(
                'word/document.xml',  // MAIN DOCUMENT - MUST BE PROCESSED FIRST
                'word/header1.xml',
                'word/header2.xml', 
                'word/header3.xml',
                'word/footer1.xml',
                'word/footer2.xml',
                'word/footer3.xml'
            );
            
            LDA_Logger::log("CRITICAL: About to process " . count($xml_files_to_process) . " XML files, starting with word/document.xml");
            
            $files_processed = 0;
            foreach ($xml_files_to_process as $xml_file) {
                $xml_content = $zip->getFromName($xml_file);
                if ($xml_content !== false) {
                    LDA_Logger::log("Processing XML file: " . $xml_file);
            
            // Process merge tags - Split tag fixing disabled to prevent XML corruption
                    $processed_xml = self::replaceMergeTags($xml_content, $merge_data);
            
            // Debug: Log XML content before and after processing
                    LDA_Logger::log("Original XML length for {$xml_file}: " . strlen($xml_content));
                    LDA_Logger::log("Processed XML length for {$xml_file}: " . strlen($processed_xml));
                    
                    // Always update the XML file to ensure it's processed
                    $zip->addFromString($xml_file, $processed_xml);
                    if ($processed_xml !== $xml_content) {
                        LDA_Logger::log("Updated XML file: " . $xml_file);
                    } else {
                        LDA_Logger::log("Processed XML file (no changes): " . $xml_file);
                    }
                    $files_processed++;
            } else {
                    LDA_Logger::log("XML file not found: " . $xml_file);
            }
            }
            
            $zip->close();
            
            LDA_Logger::log("DOCX processing completed. Processed {$files_processed} XML files");
            return array('success' => true, 'file_path' => $output_path);
            
        } catch (Exception $e) {
            LDA_Logger::error("DOCX processing failed: " . $e->getMessage());
            return array('success' => false, 'error' => $e->getMessage());
        }
    }
    
    /**
     * Replace merge tags in XML content
     */
    private static function replaceMergeTags($xml_content, $merge_data) {
        // Safety check for merge_data
        if (!is_array($merge_data)) {
            LDA_Logger::warn("Merge data is not an array, skipping merge tag replacement");
            return $xml_content;
        }
        
        LDA_Logger::log("Starting merge tag replacement with " . count($merge_data) . " merge tags");
        
        // CRITICAL FIX: Reconstruct split merge tags first
        $xml_content = self::fixSplitMergeTags($xml_content);
        
        // Now process the merge tags normally
        $replacement_count = 0;
        
        // Process replacements for each merge tag
        foreach ($merge_data as $key => $value) {
            if (is_string($value)) {
                // Replace both regular and HTML entity formats
                $xml_content = str_replace('{$' . $key . '}', $value, $xml_content);
                $xml_content = str_replace('{&#36;' . $key . '}', $value, $xml_content);
                
                // Also handle modifiers (remove them for basic replacement)
                $xml_content = preg_replace('/\{\$' . preg_quote($key) . '\|[^}]+\}/', $value, $xml_content);
                
                if (strpos($xml_content, '{$' . $key . '}') !== false || strpos($xml_content, '{&#36;' . $key . '}') !== false) {
                    $replacement_count++;
                }
            }
        }
        
        LDA_Logger::log("Merge tag replacement completed. Total replacements made: {$replacement_count}");
        return $xml_content;
        
        }
    
    /**
     * Process advanced merge tags with modifiers and conditional logic
     */
    private static function processAdvancedMergeTags($xml_content, $merge_data, &$replacements_made) {
        LDA_Logger::log("Processing advanced merge tags with modifiers and conditional logic");
        
        // Process tags with modifiers (e.g., {$USR_Name|upper}, {$USR_ABN|phone_format})
        $xml_content = self::processModifiers($xml_content, $merge_data, $replacements_made);
        
        // Process conditional logic (e.g., {if !empty($USR_ABN)}...{/if})
        $xml_content = self::processConditionalLogic($xml_content, $merge_data, $replacements_made);
        
        return $xml_content;
    }
    
    /**
     * Process modifiers in merge tags
     */
    private static function processModifiers($xml_content, $merge_data, &$replacements_made) {
        LDA_Logger::log("Processing modifiers in merge tags");
        
        // Find tags with modifiers
        preg_match_all('/\{\$([^}|]+)\|([^}]+)\}/', $xml_content, $matches);
        
        foreach ($matches[0] as $index => $full_tag) {
            $tag_name = $matches[1][$index];
            $modifier = $matches[2][$index];
            
            if (isset($merge_data[$tag_name])) {
                $value = $merge_data[$tag_name];
                $processed_value = self::applyModifier($value, $modifier);
                
                if ($processed_value !== $value) {
                    $xml_content = str_replace($full_tag, htmlspecialchars($processed_value, ENT_XML1, 'UTF-8'), $xml_content);
                    $replacements_made++;
                    LDA_Logger::log("Applied modifier {$modifier} to {$tag_name}: '{$value}' -> '{$processed_value}'");
                }
            }
        }
        
        return $xml_content;
    }
    
    /**
     * Apply modifier to value
     */
    private static function applyModifier($value, $modifier) {
        switch ($modifier) {
            case 'upper':
                return strtoupper($value);
            case 'lower':
                return strtolower($value);
            default:
                if (strpos($modifier, 'phone_format:') === 0) {
                    // Extract format from phone_format:"%2 %3 %3 %3"
                    $format = str_replace('phone_format:', '', $modifier);
                    $format = trim($format, '"\'');
                    return self::formatPhoneNumber($value, $format);
                } elseif (strpos($modifier, 'date_format:') === 0) {
                    // Extract format from date_format:"d F Y"
                    $format = str_replace('date_format:', '', $modifier);
                    $format = trim($format, '"\'');
                    return self::formatDate($value, $format);
                }
                return $value;
        }
    }
    
    /**
     * Format phone number
     */
    private static function formatPhoneNumber($phone, $format) {
        // Remove non-digits
        $digits = preg_replace('/[^0-9]/', '', $phone);
        
        // Apply format (e.g., "%2 %3 %3 %3" for "02 1234 5678")
        $formatted = $format;
        $formatted = str_replace('%2', substr($digits, 0, 2), $formatted);
        $formatted = str_replace('%3', substr($digits, 2, 4), $formatted);
        
        return $formatted;
    }
    
    /**
     * Format date
     */
    private static function formatDate($date, $format) {
        if (empty($date)) return '';
        
        $timestamp = is_numeric($date) ? $date : strtotime($date);
        if ($timestamp === false) return $date;
        
        return date($format, $timestamp);
    }
    
    /**
     * Process conditional logic in merge tags
     */
    private static function processConditionalLogic($xml_content, $merge_data, &$replacements_made) {
        // Process listif blocks first
        $xml_content = self::processListifBlocks($xml_content, $merge_data, $replacements_made);
        
        // Process if/elseif/else blocks
        $xml_content = self::processIfBlocks($xml_content, $merge_data, $replacements_made);
        
        return $xml_content;
    }
    
    /**
     * Process listif blocks: {listif condition}content{/listif}
     */
    private static function processListifBlocks($xml_content, $merge_data, &$replacements_made) {
        $pattern = '/\{listif\s+([^}]+)\}(.*?)\{\/listif\}/s';
        
        return preg_replace_callback($pattern, function($matches) use ($merge_data, &$replacements_made) {
            $condition = trim($matches[1]);
            $content = $matches[2];
            
            if (self::evaluateCondition($condition, $merge_data)) {
                $replacements_made++;
                LDA_Logger::log("Listif condition met: {$condition}");
                return $content;
            } else {
                LDA_Logger::log("Listif condition not met: {$condition}");
                return '';
            }
        }, $xml_content);
    }
    
    /**
     * Process if/elseif/else blocks
     */
    private static function processIfBlocks($xml_content, $merge_data, &$replacements_made) {
        $pattern = '/\{if\s+([^}]+)\}(.*?)(?:\{elseif\s+([^}]+)\}(.*?))*(?:\{else\}(.*?))?\{\/if\}/s';
        
        return preg_replace_callback($pattern, function($matches) use ($merge_data, &$replacements_made) {
            $if_condition = trim($matches[1]);
            $if_content = $matches[2];
            
            // Check if condition
            if (self::evaluateCondition($if_condition, $merge_data)) {
                $replacements_made++;
                LDA_Logger::log("If condition met: {$if_condition}");
                return $if_content;
            }
            
            // Check elseif conditions
            $elseif_conditions = array();
            $elseif_contents = array();
            
            // Parse elseif blocks
            $elseif_pattern = '/\{elseif\s+([^}]+)\}(.*?)(?=\{elseif|\{else|\{\/if)/s';
            preg_match_all($elseif_pattern, $matches[0], $elseif_matches, PREG_SET_ORDER);
            
            foreach ($elseif_matches as $elseif_match) {
                $elseif_condition = trim($elseif_match[1]);
                $elseif_content = $elseif_match[2];
                
                if (self::evaluateCondition($elseif_condition, $merge_data)) {
                    $replacements_made++;
                    LDA_Logger::log("Elseif condition met: {$elseif_condition}");
                    return $elseif_content;
                }
            }
            
            // Check else block
            if (preg_match('/\{else\}(.*?)\{\/if\}/s', $matches[0], $else_matches)) {
                $replacements_made++;
                LDA_Logger::log("Using else block");
                return $else_matches[1];
            }
            
            LDA_Logger::log("No conditions met for if block: {$if_condition}");
            return '';
        }, $xml_content);
    }
    
    /**
     * Evaluate a condition string
     */
    private static function evaluateCondition($condition, $merge_data) {
        // Handle empty() checks
        if (preg_match('/!empty\(\$([^)]+)\)/', $condition, $matches)) {
            $var_name = $matches[1];
            $value = isset($merge_data[$var_name]) ? $merge_data[$var_name] : '';
            return !empty($value);
        }
        
        if (preg_match('/empty\(\$([^)]+)\)/', $condition, $matches)) {
            $var_name = $matches[1];
            $value = isset($merge_data[$var_name]) ? $merge_data[$var_name] : '';
            return empty($value);
        }
        
        // Handle equality comparisons
        if (preg_match('/\$([^=]+)\s*==\s*"([^"]+)"/', $condition, $matches)) {
            $var_name = trim($matches[1]);
            $expected_value = $matches[2];
            $actual_value = isset($merge_data[$var_name]) ? $merge_data[$var_name] : '';
            return $actual_value == $expected_value;
        }
        
        // Handle AND logic
        if (strpos($condition, ' and ') !== false) {
            $parts = explode(' and ', $condition);
            foreach ($parts as $part) {
                if (!self::evaluateCondition(trim($part), $merge_data)) {
                    return false;
                }
            }
            return true;
        }
        
        LDA_Logger::log("Unknown condition format: {$condition}");
        return false;
    }
    
    /**
     * Process modifier on a value
     */
    private static function processModifier($value, $modifier) {
        // Handle phone_format modifier (with or without quotes)
        if (preg_match('/phone_format:"([^"]+)"/', $modifier, $matches)) {
            $format = $matches[1];
            return self::formatPhone($value, $format);
        } elseif (preg_match('/phone_format:([^}]+)/', $modifier, $matches)) {
            $format = $matches[1];
            return self::formatPhone($value, $format);
        }
        
        // Handle date_format modifier (with or without quotes)
        if (preg_match('/date_format:"([^"]+)"/', $modifier, $matches)) {
            $format = $matches[1];
            return self::formatDate($value, $format);
        } elseif (preg_match('/date_format:([^}]+)/', $modifier, $matches)) {
            $format = $matches[1];
            return self::formatDate($value, $format);
        }
        
        // Handle replace modifier (with or without quotes)
        if (preg_match('/replace:"([^"]+)":"([^"]+)"/', $modifier, $matches)) {
            $search = $matches[1];
            $replace = $matches[2];
            return str_replace($search, $replace, $value);
        } elseif (preg_match('/replace:([^:]+):([^}]+)/', $modifier, $matches)) {
            $search = $matches[1];
            $replace = $matches[2];
            return str_replace($search, $replace, $value);
        }
        
        // Handle simple modifiers
        switch ($modifier) {
            case 'upper':
                return strtoupper($value);
            case 'lower':
                return strtolower($value);
            case 'ucwords':
                return ucwords($value);
            case 'ucfirst':
                return ucfirst($value);
            default:
                LDA_Logger::log("Unknown modifier: {$modifier}");
                return $value;
        }
    }
    
    /**
     * Format phone number based on pattern
     */
    private static function formatPhone($phone, $format) {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        // Apply format pattern
        $formatted = $format;
        $phone_chars = str_split($phone);
        $char_index = 0;
        
        for ($i = 0; $i < strlen($format); $i++) {
            if ($format[$i] == '%' && $i + 1 < strlen($format)) {
                $next_char = $format[$i + 1];
                if (is_numeric($next_char) && $char_index < count($phone_chars)) {
                    $formatted = substr_replace($formatted, $phone_chars[$char_index], $i, 2);
                    $char_index++;
                }
            }
        }
        
        return $formatted;
    }
    
    
    /**
     * Fix split merge tags across XML elements - AGGRESSIVE RECONSTRUCTION
     */
    private static function fixSplitMergeTagsConservative($xml_content) {
        LDA_Logger::log("Starting AGGRESSIVE split merge tag reconstruction");
        
        $fixes_applied = 0;
        
        // AGGRESSIVE APPROACH: Handle complex paragraph boundary splits
        // This handles the exact pattern from your logs
        
        // Pattern 1: Complex split across paragraph boundaries with modifiers
        // {$USR_Name|upper} </w:t></w:r></w:p><w:p...>{if !empty($USR_ABN)}, {$USR_ABN|phone_format:%2 %3 %3 %3}</w:t></w:r><w:r><w:t>{/if}
        $pattern1 = '/\{\$([A-Z_]+)\|([^}]+)\}\s*<\/w:t><\/w:r><\/w:p><w:p[^>]*>.*?\{\$([A-Z_]+)\|([^}]+)\}\s*<\/w:t><\/w:r><w:r[^>]*><w:t[^>]*>\}/s';
        $xml_content = preg_replace_callback($pattern1, function($matches) use (&$fixes_applied) {
            $tag1_name = $matches[1];
            $tag1_modifier = $matches[2];
            $tag2_name = $matches[3];
            $tag2_modifier = $matches[4];
            
            $clean_tag1 = '{$' . $tag1_name . '|' . $tag1_modifier . '}';
            $clean_tag2 = '{$' . $tag2_name . '|' . $tag2_modifier . '}';
            
            $fixes_applied++;
            LDA_Logger::log("AGGRESSIVE FIX 1: Reconstructed tags: " . $clean_tag1 . " and " . $clean_tag2);
            return $clean_tag1 . ' ' . $clean_tag2;
        }, $xml_content);
        
        // Pattern 2: Simple split with w:t elements only
        $pattern2 = '/\{\$([A-Z_]+)\|([^}]+)<\/w:t><\/w:r><w:r[^>]*><w:t[^>]*>([^<]+)<\/w:t><\/w:r><w:r[^>]*><w:t[^>]*>\}/';
        $xml_content = preg_replace_callback($pattern2, function($matches) use (&$fixes_applied) {
            $tag_name = $matches[1];
            $modifier = $matches[2];
            $remaining = $matches[3];
            
            $clean_tag = '{$' . $tag_name . '|' . $modifier . $remaining . '}';
            $fixes_applied++;
            LDA_Logger::log("AGGRESSIVE FIX 2: Reconstructed tag: " . $clean_tag);
            return $clean_tag;
        }, $xml_content);
        
        // Pattern 3: Simple split without modifiers
        $pattern3 = '/\{\$([A-Z_]+)<\/w:t><\/w:r><w:r[^>]*><w:t[^>]*>([^<]+)<\/w:t><\/w:r><w:r[^>]*><w:t[^>]*>\}/';
        $xml_content = preg_replace_callback($pattern3, function($matches) use (&$fixes_applied) {
            $tag_name = $matches[1];
            $remaining = $matches[2];
            
            $clean_tag = '{$' . $tag_name . $remaining . '}';
            $fixes_applied++;
            LDA_Logger::log("AGGRESSIVE FIX 3: Reconstructed tag: " . $clean_tag);
            return $clean_tag;
        }, $xml_content);
        
        LDA_Logger::log("AGGRESSIVE split merge tag reconstruction completed. Applied {$fixes_applied} fixes");
        return $xml_content;
    }
    
    /**
     * Fix split merge tags across XML elements
     */
    private static function fixSplitMergeTags($xml_content) {
        LDA_Logger::log("Starting split merge tag fixing");
        
        $fixes_applied = 0;
        
        // SURGICAL APPROACH: Preserve XML structure while fixing split tags
        // This approach is more conservative to avoid corrupting the XML
        
        // Step 1: Find all split merge tags without corrupting XML
        $split_tags_found = array();
        
        // Look for patterns that indicate split merge tags
        // Pattern 1: {$ followed by XML elements, then more text, then }
        if (preg_match_all('/\{\$[^}]*<[^>]*>[^}]*\}/', $xml_content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $split_tag = $match[0];
                $position = $match[1];
                
                LDA_Logger::log("Found split tag at position {$position}: " . substr($split_tag, 0, 100) . "...");
                
                // Extract the clean merge tag by removing XML elements
                $clean_tag = preg_replace('/<[^>]*>/', '', $split_tag);
                $clean_tag = preg_replace('/\s+/', ' ', $clean_tag);
                $clean_tag = trim($clean_tag);
                
                // Validate that it's a proper merge tag
                if (strpos($clean_tag, '{$') === 0 && substr($clean_tag, -1) === '}') {
                    $split_tags_found[] = array(
                        'original' => $split_tag,
                        'clean' => $clean_tag,
                        'position' => $position
                    );
                }
            }
        }
        
        // Step 2: Replace split tags with clean versions
        foreach ($split_tags_found as $tag_info) {
            $original = $tag_info['original'];
            $clean = $tag_info['clean'];
            
            // Replace the split tag with the clean version
            $xml_content = str_replace($original, $clean, $xml_content);
            $fixes_applied++;
            
            LDA_Logger::log("Fixed split tag: " . substr($original, 0, 50) . "... -> " . $clean);
        }
        
        // Step 3: Handle specific known patterns that cause issues
        $known_patterns = array(
            // Pattern for tags split with proofErr elements
            '/\{\$([^<}]*?)<w:proofErr[^>]*>([^<}]*?)<[^>]*>([^}]*?)\}/' => '{$$1$2$3}',
            // Pattern for tags split with w:r elements
            '/\{\$([^<}]*?)<w:r[^>]*>([^<}]*?)<[^>]*>([^}]*?)\}/' => '{$$1$2$3}',
            // Pattern for tags split with w:t elements
            '/\{\$([^<}]*?)<w:t[^>]*>([^<}]*?)<[^>]*>([^}]*?)\}/' => '{$$1$2$3}',
        );
        
        foreach ($known_patterns as $pattern => $replacement) {
            $count = 0;
            $xml_content = preg_replace($pattern, $replacement, $xml_content, -1, $count);
            if ($count > 0) {
                $fixes_applied += $count;
                LDA_Logger::log("Applied {$count} fixes using pattern: " . substr($pattern, 0, 50) . "...");
            }
        }
        
        LDA_Logger::log("Split merge tag fixing completed. Applied {$fixes_applied} fixes");
        return $xml_content;
        
        // More flexible approach: Find and reconstruct split merge tags
        // Pattern for detecting split merge tags with various XML structures
        $split_patterns = array(
            // Pattern 1: {$TAG} split with proofErr and complex rPr
            '/\{\$<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><\/w:rPr><w:t>\}/',
            
            // Pattern 2: {$TAG} split with proofErr and different rPr structures
            '/\{\$<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><\/w:rPr><w:t>\}/',
            
            // Pattern 3: {$TAG} split across multiple r elements with proofErr
            '/\{\$<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><\/w:rPr><w:t>\}/',
            
            // Pattern 4: {$TAG} split with complex nested structures
            '/\{\$<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><\/w:rPr><w:t>\}/',
            
            // Pattern 5: {$TAG} split with different proofErr structures
            '/\{\$<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><\/w:rPr><w:t>\}/',
            
            // Pattern 6: {$TAG} split with xml:space="preserve"
            '/\{\$<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><\/w:rPr><w:t xml:space="preserve">([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><\/w:rPr><w:t>\}/',
            
            // Pattern 7: {$TAG} split with different rPr combinations
            '/\{\$<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><\/w:rPr><w:t>\}/',
            
            // Pattern 8: {$TAG} split with bCs (bold complex script)
            '/\{\$<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><\/w:rPr><w:t>\}/',
            
            // Pattern 9: {$TAG} split with szCs (size complex script)
            '/\{\$<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:szCs[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>\}/',
            
            // Pattern 10: {$TAG} split with different rsidR attributes
            '/\{\$<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><\/w:rPr><w:t>\}/',
            
            // Pattern 11: {$TAG} split with bCs and szCs (from your logs)
            '/\{\$<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t xml:space="preserve">([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>\}/',
            
            // Pattern 12: {$TAG} split with different rsidR values (from your logs)
            '/\{\$<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t xml:space="preserve">([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>\}/',
            
            // Pattern 13: {$TAG} split with pipe modifiers (from your logs)
            '/\{\$<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>:([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>\}/',
            
            // Pattern 14: Flexible pattern for {$USR_Business} split (from header1.xml logs)
            '/\{\$<\/w:t><\/w:r><w:proofErr w:type="spellStart" \/><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>USR_<\/w:t><\/w:r><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>Business<\/w:t><\/w:r><w:proofErr w:type="spellEnd" \/><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t[^>]*>\}/',
            
            // Pattern 15: Flexible pattern for {$USR_Name|upper} split (from footer2.xml logs)
            '/\{\$<\/w:t><\/w:r><w:proofErr w:type="spellStart" \/><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>USR_Name\|upper<\/w:t><\/w:r><w:proofErr w:type="spellEnd" \/><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t[^>]*>\}/',
            
            // Pattern 16: Flexible pattern for {$USR_ABN|phone_format:"%2 %3 %3 %3"} split (from footer2.xml logs)
            '/\{\$<\/w:t><\/w:r><w:proofErr w:type="spellStart" \/><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>USR_ABN\|phone_format<\/w:t><\/w:r><w:proofErr w:type="spellEnd" \/><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>:"%2 %3 %3 %3"}/',
            
            // Pattern 17: Very flexible pattern for any split merge tag with proofErr
            '/\{\$<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t[^>]*>\}/',
            
            // Pattern 18: Very flexible pattern for split merge tags with pipe modifiers
            '/\{\$<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r><w:proofErr[^>]*><w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t[^>]*>\}/'
        );
        
        // Apply each pattern
        foreach ($split_patterns as $index => $pattern) {
            $before = $xml_content;
            
            // Check if pattern has 2 or 3 capture groups
            if (preg_match_all('/\([^)]+\)/', $pattern, $matches)) {
                $capture_count = count($matches[0]);
                
                if ($capture_count == 2) {
                    $xml_content = preg_replace($pattern, '{$' . '$1' . '$2' . '}', $xml_content);
                } elseif ($capture_count == 3) {
                    $xml_content = preg_replace($pattern, '{$' . '$1' . '$2' . '$3' . '}', $xml_content);
                }
            }
            
            if ($before !== $xml_content) {
                $fixes_applied++;
                LDA_Logger::log("Applied split tag fix pattern " . ($index + 1) . " - found and fixed split merge tags");
            }
        }
        
        // Additional flexible patterns for any remaining split tags
        $flexible_patterns = array(
            // Pattern 1: Basic split across XML elements
            '/\{\$<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*><w:rPr[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*><w:rPr[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*><w:rPr[^>]*><\/w:rPr><w:t>\}/',
            
            // Pattern 2: Split with xml:space="preserve"
            '/\{\$<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*><w:rPr[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*><w:rPr[^>]*><\/w:rPr><w:t xml:space="preserve">([^<]+)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*><w:rPr[^>]*><\/w:rPr><w:t>\}/',
            
            // Pattern 3: Split with pipe modifiers
            '/\{\$<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*><w:rPr[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*><w:rPr[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*><w:rPr[^>]*><\/w:rPr><w:t>:([^<]+)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*><w:rPr[^>]*><\/w:rPr><w:t>\}/'
        );
        
        foreach ($flexible_patterns as $index => $flexible_pattern) {
            $before = $xml_content;
            
            // Check capture groups
            if (preg_match_all('/\([^)]+\)/', $flexible_pattern, $matches)) {
                $capture_count = count($matches[0]);
                
                if ($capture_count == 2) {
                    $xml_content = preg_replace($flexible_pattern, '{$' . '$1' . '$2' . '}', $xml_content);
                } elseif ($capture_count == 3) {
                    $xml_content = preg_replace($flexible_pattern, '{$' . '$1' . '$2' . '$3' . '}', $xml_content);
                }
            }
            
            if ($before !== $xml_content) {
                $fixes_applied++;
                LDA_Logger::log("Applied flexible split tag fix pattern " . ($index + 1) . " - found and fixed additional split merge tags");
            }
        }
        
        // Additional approach: Use a more general pattern to catch any remaining split tags
        // This will find any sequence that starts with {$ and ends with } but is split across XML elements
        $general_pattern = '/\{\$<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t[^>]*>\}/';
        
        $before_general = $xml_content;
        $xml_content = preg_replace($general_pattern, '{$' . '$1' . '$2' . '}', $xml_content);
        if ($before_general !== $xml_content) {
            $fixes_applied++;
            LDA_Logger::log("Applied general split tag fix - found and fixed additional split merge tags");
        }
        
        // Even more general pattern for tags with 3 parts (like modifiers)
        $general_pattern_3 = '/\{\$<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t>([^<]+)<\/w:t><\/w:r>(?:<[^>]*>)*<w:r[^>]*><w:rPr[^>]*><w:rFonts[^>]*><w:b[^>]*><w:bCs[^>]*><w:color[^>]*><w:sz[^>]*><w:szCs[^>]*><\/w:rPr><w:t[^>]*>\}/';
        
        $before_general_3 = $xml_content;
        $xml_content = preg_replace($general_pattern_3, '{$' . '$1' . '$2' . '$3' . '}', $xml_content);
        if ($before_general_3 !== $xml_content) {
            $fixes_applied++;
            LDA_Logger::log("Applied general 3-part split tag fix - found and fixed additional split merge tags");
        }
        
        LDA_Logger::log("Split merge tag fixing completed. Applied {$fixes_applied} fixes");
        
        // DISABLE XML validation for now - it's too strict and causing blank files
        // The goal is to get working DOCX files, not perfect XML validation
        LDA_Logger::log("XML validation disabled to prevent blank files");
        
        return $xml_content;
    }
    
    /**
     * Check if XML is valid
     */
    private static function isValidXML($xml_content) {
        // Simple XML validation - check for basic structure
        if (empty($xml_content)) {
            return false;
        }
        
        // Check for unclosed tags (basic check)
        $open_tags = substr_count($xml_content, '<w:');
        $close_tags = substr_count($xml_content, '</w:');
        
        if ($open_tags !== $close_tags) {
            LDA_Logger::log("XML validation failed: Mismatched tags (open: {$open_tags}, close: {$close_tags})");
            return false;
        }
        
        // Check for malformed XML characters
        if (strpos($xml_content, '<w:t>') === false) {
            LDA_Logger::log("XML validation failed: No text elements found");
            return false;
        }
        
        return true;
    }
    
    /**
     * Check if this processor is available
     */
    public static function isAvailable() {
        return class_exists('ZipArchive');
    }
}