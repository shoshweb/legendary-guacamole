<?php
/**
 * Simple Merge Engine for Legal Document Automation
 * No external dependencies - uses only WordPress built-in functions
 */

if (!defined('ABSPATH')) {
    exit;
}

class LDA_MergeEngine {

    private $settings;

    public function __construct($settings = array()) {
        $this->settings = $settings;
    }

    /**
     * Merge data into DOCX template
     */
    public function mergeDocument($template_path, $merge_data, $output_path) {
        try {
            LDA_Logger::log("*** CRITICAL DEBUG: LDA_MergeEngine::mergeDocument() is being called ***");
            LDA_Logger::log("Starting document merge process");
            LDA_Logger::log("Template: {$template_path}");
            LDA_Logger::log("Output: {$output_path}");
            
            // Check if template exists
            if (!file_exists($template_path)) {
                throw new Exception("Template file not found: {$template_path}");
            }
            
            // NEW APPROACH: Apply field mappings directly here
            LDA_Logger::log("*** NEW APPROACH: Applying field mappings directly in merge engine ***");
            $enhanced_merge_data = $this->applyFieldMappingsDirectly($merge_data);
            LDA_Logger::log("Enhanced merge data with field mappings: " . json_encode($enhanced_merge_data, JSON_PRETTY_PRINT));
            
            // Use the enhanced Webmerge-compatible DOCX processing
            LDA_Logger::log("Using enhanced LDA_WebmergeDOCX processor");
            $result = LDA_WebmergeDOCX::processMergeTags($template_path, $output_path, $enhanced_merge_data);
            
            if ($result['success']) {
                LDA_Logger::log("Document merge completed successfully using Webmerge processor");
                return array('success' => true, 'file_path' => $output_path);
            } else {
                throw new Exception("Webmerge DOCX processing failed: " . (isset($result['error']) ? $result['error'] : 'Unknown error'));
            }
            
        } catch (Exception $e) {
            LDA_Logger::error("Document merge failed: " . $e->getMessage());
            return array('success' => false, 'error' => $e->getMessage());
        }
    }
    
    /**
     * NEW APPROACH: Apply field mappings directly - bypasses broken logic
     */
    private function applyFieldMappingsDirectly($merge_data) {
        LDA_Logger::log("*** NEW APPROACH: Applying field mappings directly ***");
        
        // Get the form ID from the merge data
        $form_id = isset($merge_data['form_id']) ? $merge_data['form_id'] : null;
        LDA_Logger::log("Form ID from merge data: " . ($form_id ?: 'NOT FOUND'));
        
        if (!$form_id) {
            LDA_Logger::log("No form ID found in merge data, skipping field mappings");
            return $merge_data;
        }
        
        // Get field mappings for this form
        $mappings = get_option('lda_field_mappings', array());
        $form_mappings = isset($mappings[$form_id]) ? $mappings[$form_id] : array();
        
        LDA_Logger::log("Found " . count($form_mappings) . " field mappings for form " . $form_id);
        
        // Apply field mappings
        foreach ($form_mappings as $merge_tag => $field_id) {
            // The value should already be in merge_data from the form submission or test data
            $field_value = isset($merge_data[$field_id]) ? $merge_data[$field_id] : '';
            
            if (!empty($field_value)) {
                $merge_data[$merge_tag] = $field_value;
                LDA_Logger::log("DYNAMIC MAPPING: {$merge_tag} = '{$field_value}' (from field {$field_id})");
            } else {
                LDA_Logger::log("Field {$field_id} is empty, skipping mapping for {$merge_tag}");
            }
        }
        
        LDA_Logger::log("Field mappings applied successfully");
        return $merge_data;
    }
    
    /**
     * Merge document and generate PDF
     */
    public function mergeDocumentWithPdf($template_path, $merge_data, $docx_output_path, $pdf_output_path) {
        try {
            // First merge the DOCX document
            $docx_result = $this->mergeDocument($template_path, $merge_data, $docx_output_path);
            if (!$docx_result['success']) {
                return $docx_result;
            }

            // Generate PDF version (optional)
            if (isset($this->settings['enable_pdf_output']) && $this->settings['enable_pdf_output']) {
                $pdf_handler = new LDA_PDFHandler($this->settings);
                $pdf_result = $pdf_handler->convertDocxToPdf($docx_output_path, $pdf_output_path);

                if (!$pdf_result['success']) {
                    LDA_Logger::warn("PDF generation failed, but DOCX was created successfully: " . $pdf_result['error']);
                    return array(
                        'success' => true,
                        'docx_path' => $docx_output_path,
                        'pdf_path' => null,
                        'pdf_error' => $pdf_result['error'],
                        'message' => 'DOCX created successfully, but PDF generation failed'
                    );
                }

                LDA_Logger::log("Document and PDF generated successfully");
                return array(
                    'success' => true,
                    'docx_path' => $docx_output_path,
                    'pdf_path' => $pdf_output_path,
                    'message' => 'Both DOCX and PDF generated successfully'
                );
            }

            return array(
                'success' => true,
                'docx_path' => $docx_output_path,
                'pdf_path' => null,
                'message' => 'DOCX generated successfully (PDF disabled)'
            );

        } catch (Exception $e) {
            LDA_Logger::error("Document merge with PDF failed: " . $e->getMessage());
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Validate template file
     */
    public function validateTemplate($template_path) {
        try {
            if (!file_exists($template_path)) {
                return array('success' => false, 'message' => 'Template file not found');
            }

            if (!is_readable($template_path)) {
                return array('success' => false, 'message' => 'Template file is not readable');
            }

            // Check if it's a valid DOCX file
            $zip = new ZipArchive();
            if ($zip->open($template_path) !== TRUE) {
                return array('success' => false, 'message' => 'Invalid DOCX file format');
            }

            // Check for required files
            if ($zip->locateName('word/document.xml') === false) {
                $zip->close();
                return array('success' => false, 'message' => 'Invalid DOCX structure - missing document.xml');
            }

            // Enhanced validation: Check for merge tags and syntax
            $validation_details = $this->validateMergeTags($zip);
            
            $zip->close();
            
            if ($validation_details['has_errors']) {
                return array(
                    'success' => false, 
                    'message' => 'Template has merge tag errors',
                    'details' => $validation_details['details']
                );
            }
            
            return array(
                'success' => true, 
                'message' => 'Template is valid and ready for use',
                'details' => $validation_details['details']
            );

        } catch (Exception $e) {
            return array('success' => false, 'message' => 'Template validation error: ' . $e->getMessage());
        }
    }
    
    /**
     * Validate merge tags in the template
     */
    private function validateMergeTags($zip) {
        $details = array();
        $has_errors = false;
        $merge_tags_found = 0;
        $conditional_blocks = 0;
        $modifiers_found = 0;
        
        // Check main document
        $document_xml = $zip->getFromName('word/document.xml');
        if ($document_xml) {
            $result = $this->analyzeMergeTags($document_xml, 'Main Document');
            $details[] = $result['summary'];
            $merge_tags_found += $result['merge_tags'];
            $conditional_blocks += $result['conditionals'];
            $modifiers_found += $result['modifiers'];
            if ($result['has_errors']) $has_errors = true;
        }
        
        // Check headers
        for ($i = 1; $i <= 3; $i++) {
            $header_xml = $zip->getFromName("word/header{$i}.xml");
            if ($header_xml) {
                $result = $this->analyzeMergeTags($header_xml, "Header {$i}");
                $details[] = $result['summary'];
                $merge_tags_found += $result['merge_tags'];
                $conditional_blocks += $result['conditionals'];
                $modifiers_found += $result['modifiers'];
                if ($result['has_errors']) $has_errors = true;
            }
        }
        
        // Check footers
        for ($i = 1; $i <= 3; $i++) {
            $footer_xml = $zip->getFromName("word/footer{$i}.xml");
            if ($footer_xml) {
                $result = $this->analyzeMergeTags($footer_xml, "Footer {$i}");
                $details[] = $result['summary'];
                $merge_tags_found += $result['merge_tags'];
                $conditional_blocks += $result['conditionals'];
                $modifiers_found += $result['modifiers'];
                if ($result['has_errors']) $has_errors = true;
            }
        }
        
        // Summary
        $summary = "Validation Summary:\n";
        $summary .= "• Merge tags found: {$merge_tags_found}\n";
        $summary .= "• Conditional blocks: {$conditional_blocks}\n";
        $summary .= "• Modifiers used: {$modifiers_found}\n";
        $summary .= "• Sections analyzed: " . count($details) . "\n";
        
        array_unshift($details, $summary);
        
        return array(
            'has_errors' => $has_errors,
            'details' => implode("\n", $details)
        );
    }
    
    /**
     * Analyze merge tags in XML content
     */
    private function analyzeMergeTags($xml_content, $section_name) {
        $merge_tags = 0;
        $conditionals = 0;
        $modifiers = 0;
        $errors = array();
        
        // Count merge tags (both regular and HTML entity formats)
        preg_match_all('/\{\$[^}]+\}/', $xml_content, $matches);
        preg_match_all('/\{&#36;[^}]+\}/', $xml_content, $entity_matches);
        $merge_tags = count($matches[0]) + count($entity_matches[0]);
        
        // Count conditionals
        preg_match_all('/\{if[^}]+\}/', $xml_content, $if_matches);
        preg_match_all('/\{\/if\}/', $xml_content, $endif_matches);
        $conditionals = min(count($if_matches[0]), count($endif_matches[0]));
        
        // Count modifiers (both regular and HTML entity formats)
        preg_match_all('/\{\$[^|]+\|[^}]+\}/', $xml_content, $modifier_matches);
        preg_match_all('/\{&#36;[^|]+\|[^}]+\}/', $xml_content, $entity_modifier_matches);
        $modifiers = count($modifier_matches[0]) + count($entity_modifier_matches[0]);
        
        // Check for syntax errors
        if (count($if_matches[0]) !== count($endif_matches[0])) {
            $errors[] = "Mismatched conditional blocks (if/endif)";
        }
        
        // Check for unclosed merge tags
        preg_match_all('/\{\$[^}]*$/', $xml_content, $unclosed_matches);
        if (count($unclosed_matches[0]) > 0) {
            $errors[] = "Unclosed merge tags detected";
        }
        
        $summary = "{$section_name}: {$merge_tags} merge tags, {$conditionals} conditionals, {$modifiers} modifiers";
        if (!empty($errors)) {
            $summary .= " - ERRORS: " . implode(", ", $errors);
        }
        
        return array(
            'summary' => $summary,
            'merge_tags' => $merge_tags,
            'conditionals' => $conditionals,
            'modifiers' => $modifiers,
            'has_errors' => !empty($errors)
        );
    }
}