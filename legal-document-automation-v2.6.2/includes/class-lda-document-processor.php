<?php
/**
 * Handles processing a Gravity Form entry and generating a document.
 *
 * @package LegalDocumentAutomation
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class LDA_DocumentProcessor {

    private $entry;
    private $form;
    private $settings;

    /**
     * Constructor.
     *
     * @param array $entry The Gravity Forms entry object.
     * @param array $form The Gravity Forms form object.
     * @param array $settings The plugin settings.
     */
    public function __construct($entry, $form, $settings) {
        $this->entry = $entry;
        $this->form = $form;
        $this->settings = $settings;
    }

    /**
     * Check if PHPWord is available and properly loaded.
     * DEPRECATED: Now using LDA_SimpleDOCX instead
     *
     * @return array Array with 'available' boolean and 'error' string if not available.
     */
    public static function checkPhpWordAvailability() {
        $result = array(
            'available' => false,
            'error' => '',
            'details' => array()
        );

        // Check if autoloader exists
        $autoloader = LDA_PLUGIN_DIR . 'vendor/autoload.php';
        if (!file_exists($autoloader)) {
            $result['error'] = 'Composer autoloader not found at: ' . $autoloader;
            $result['details'][] = 'Autoloader path: ' . $autoloader;
            return $result;
        }
        $result['details'][] = 'Autoloader found: ' . $autoloader;

        // Check if PHPWord class exists
        if (!class_exists('PhpOffice\PhpWord\TemplateProcessor')) {
            $result['error'] = 'PHPWord TemplateProcessor class not found. Please ensure PHPWord is properly installed.';
            $result['details'][] = 'TemplateProcessor class not available';
            return $result;
        }
        $result['details'][] = 'TemplateProcessor class found';

        // Check if we can create a TemplateProcessor instance
        try {
            // This is a basic test - we'll create a minimal test
            $result['available'] = true;
            $result['details'][] = 'PHPWord is properly loaded and functional';
        } catch (Exception $e) {
            $result['error'] = 'PHPWord initialization failed: ' . $e->getMessage();
            $result['details'][] = 'Exception: ' . $e->getMessage();
        }

        return $result;
    }

    /**
     * Get available merge tags from a template file.
     *
     * @param string $template_path Path to the template file.
     * @return array Array of available merge tags.
     */
    public static function getTemplateMergeTags($template_path) {
        if (!file_exists($template_path)) {
            return array();
        }

        try {
            $templateProcessor = new \PhpOffice\PhpWord\TemplateProcessor($template_path);
            return $templateProcessor->getVariables();
        } catch (Exception $e) {
            LDA_Logger::error("Error reading template variables: " . $e->getMessage());
            return array();
        }
    }

    /**
     * Processes the form entry and generates the document.
     *
     * @return array
     */
    public function process() {
        LDA_Logger::log("=== DOCUMENT PROCESSOR STARTED ===");
        LDA_Logger::log("Entry ID: " . $this->entry['id']);
        LDA_Logger::log("Form ID: " . $this->form['id']);
        LDA_Logger::log("CRITICAL DEBUG: process() method called");
        
        try {
            LDA_Logger::log("About to call processDocument(false)");
            $result = $this->processDocument(false);
            LDA_Logger::log("processDocument(false) completed");
            LDA_Logger::log("=== DOCUMENT PROCESSOR COMPLETED ===");
            return $result;
        } catch (Exception $e) {
            LDA_Logger::error("=== CRITICAL ERROR IN DOCUMENT PROCESSOR ===");
            LDA_Logger::error("Error message: " . $e->getMessage());
            LDA_Logger::error("Error file: " . $e->getFile());
            LDA_Logger::error("Error line: " . $e->getLine());
            LDA_Logger::error("Error trace: " . $e->getTraceAsString());
            LDA_Logger::error("=== END CRITICAL ERROR ===");
            throw $e;
        }
    }

    /**
     * Processes the form entry and generates both DOCX and PDF documents.
     *
     * @return array
     */
    public function processWithPdf() {
        LDA_Logger::log("=== DOCUMENT PROCESSOR WITH PDF STARTED ===");
        LDA_Logger::log("Entry ID: " . $this->entry['id']);
        LDA_Logger::log("Form ID: " . $this->form['id']);
        
        try {
            $result = $this->processDocument(true);
            LDA_Logger::log("=== DOCUMENT PROCESSOR WITH PDF COMPLETED ===");
            return $result;
        } catch (Exception $e) {
            LDA_Logger::error("=== CRITICAL ERROR IN DOCUMENT PROCESSOR WITH PDF ===");
            LDA_Logger::error("Error message: " . $e->getMessage());
            LDA_Logger::error("Error file: " . $e->getFile());
            LDA_Logger::error("Error line: " . $e->getLine());
            LDA_Logger::error("Error trace: " . $e->getTraceAsString());
            LDA_Logger::error("=== END CRITICAL ERROR ===");
            throw $e;
        }
    }

    /**
     * Main processing method that can generate DOCX only or both DOCX and PDF
     *
     * @param bool $generate_pdf Whether to generate PDF version
     * @return array
     */
    private function processDocument($generate_pdf = false) {
        LDA_Logger::log("=== PROCESS DOCUMENT METHOD STARTED ===");
        LDA_Logger::log("Generate PDF: " . ($generate_pdf ? 'TRUE' : 'FALSE'));
        LDA_Logger::log("CRITICAL DEBUG: processDocument method called with generate_pdf = " . ($generate_pdf ? 'TRUE' : 'FALSE'));
        
        // 1. Defensive Check for DOCX Processing
        LDA_Logger::log("Step 1: Checking DOCX processing availability");
        $docx_check = LDA_SimpleDOCX::isAvailable();
        LDA_Logger::log("DOCX processing check result: " . ($docx_check ? 'Available' : 'Not Available'));
        
        if (!$docx_check) {
            $error_msg = 'DOCX processing is not available. ZIP extension may be missing.';
            LDA_Logger::error($error_msg);
            return array('success' => false, 'error_message' => $error_msg);
        }
        LDA_Logger::log("DOCX processing is available - continuing");

        // 2. Get Template Path
        LDA_Logger::log("Step 2: Getting template path");
        $template_folder = isset($this->settings['template_folder']) ? $this->settings['template_folder'] : 'lda-templates';
        LDA_Logger::log("Template folder setting: " . $template_folder);
        
        $upload_dir = wp_upload_dir();
        $template_dir = $upload_dir['basedir'] . '/' . $template_folder . '/';
        LDA_Logger::log("Template directory: " . $template_dir);
        
        // Get the assigned template for this form
        LDA_Logger::log("Step 3: Getting assigned template for form " . $this->form['id']);
        $template_assignments = get_option('lda_template_assignments', array());
        $assigned_template = isset($template_assignments[$this->form['id']]) ? $template_assignments[$this->form['id']] : '';
        LDA_Logger::log("Assigned template for form " . $this->form['id'] . ": " . ($assigned_template ?: 'NONE'));
        
        if (empty($assigned_template)) {
            // Fallback: use form meta if available
            if (function_exists('gform_get_meta')) {
                $meta_template = gform_get_meta($this->form['id'], 'lda_template_file');
                LDA_Logger::log("Form meta template: " . ($meta_template ?: 'NONE'));
                if ($meta_template) {
                    $assigned_template = $meta_template;
                }
            }
        }
        
        if (empty($assigned_template)) {
            $error_msg = 'No template assigned to form ' . $this->form['id'] . '. Please assign a template in the Templates tab.';
            LDA_Logger::error($error_msg);
            return array('success' => false, 'error_message' => $error_msg);
        }
        
        $template_path = $template_dir . $assigned_template;
        LDA_Logger::log("Template path: " . $template_path);
        
        if (!file_exists($template_path)) {
            $error_msg = 'Assigned template file not found: ' . $assigned_template . ' (Path: ' . $template_path . ')';
            LDA_Logger::error($error_msg);
            return array('success' => false, 'error_message' => $error_msg);
        }
        
        LDA_Logger::log("Using assigned template: " . basename($template_path));
        LDA_Logger::log("Full template path: " . $template_path);

        // 3. Prepare Merge Data
        LDA_Logger::log("Step 4: Preparing merge data");
        LDA_Logger::log("About to call prepareMergeData() method");
        $merge_data = $this->prepareMergeData();
        LDA_Logger::log("prepareMergeData() method completed");
        
        // Log the merge data for debugging
        // Log merge data summary to avoid truncation issues
        $merge_summary = array();
        foreach ($merge_data as $key => $value) {
            if (strlen($value) > 50) {
                $merge_summary[$key] = substr($value, 0, 50) . '...';
            } else {
                $merge_summary[$key] = $value;
            }
        }
        LDA_Logger::log("Merge data prepared (" . count($merge_data) . " items): " . json_encode($merge_summary, JSON_PRETTY_PRINT));
        
        // Log specific merge tags that should match the template
        $template_merge_tags = array('USR_Business', 'PT2_Business');
        foreach ($template_merge_tags as $tag) {
            if (isset($merge_data[$tag])) {
                LDA_Logger::log("Template merge tag {$tag} found with value: " . $merge_data[$tag]);
            } else {
                LDA_Logger::warn("Template merge tag {$tag} NOT FOUND in merge data");
            }
        }

        try {
            // 4. Perform the Merge using the merge engine
            LDA_Logger::log("Step 5: Creating merge engine");
            $merge_engine = new LDA_MergeEngine();
            LDA_Logger::log("Merge engine created successfully");
            
            // 5. Save the Output File
            LDA_Logger::log("Step 6: Setting up output directory");
            $upload_dir = wp_upload_dir();
            if (empty($upload_dir['basedir'])) {
                throw new Exception('WordPress upload directory not available');
            }
            
            $output_dir = $upload_dir['basedir'] . '/lda-output/';
            LDA_Logger::log("Output directory: " . $output_dir);
            
            if (!is_dir($output_dir)) {
                LDA_Logger::log("Output directory doesn't exist, creating it");
                if (!wp_mkdir_p($output_dir)) {
                    throw new Exception("Failed to create output directory: {$output_dir}");
                }
                LDA_Logger::log("Output directory created successfully");
            }
            
            if (!is_writable($output_dir)) {
                $perms = fileperms($output_dir);
                throw new Exception("Output directory is not writable: {$output_dir} (permissions: " . decoct($perms & 0777) . ")");
            }

            $output_filename = sanitize_file_name($this->form['title'] . '-' . $this->entry['id'] . '-' . time() . '.docx');
            $output_path = $output_dir . $output_filename;
            LDA_Logger::log("Output filename: " . $output_filename);
            LDA_Logger::log("Full output path: " . $output_path);
            
            // Use the merge engine to process the document
            LDA_Logger::log("Step 7: Starting document merge");
            $merge_result = $merge_engine->mergeDocument($template_path, $merge_data, $output_path);
            LDA_Logger::log("Merge result: " . json_encode($merge_result, JSON_PRETTY_PRINT));
            
            if (!$merge_result['success']) {
                LDA_Logger::error("Merge failed: " . $merge_result['error']);
                throw new Exception($merge_result['error']);
            }
            LDA_Logger::log("Document merge completed successfully");

            // 6. Generate PDF if requested
            if ($generate_pdf) {
                $pdf_filename = str_replace('.docx', '.pdf', $output_filename);
                $pdf_path = $output_dir . $pdf_filename;
                
                $pdf_handler = new LDA_PDFHandler($this->settings);
                $pdf_result = $pdf_handler->convertDocxToPdf($output_path, $pdf_path);
                
                if ($pdf_result['success']) {
                    LDA_Logger::log("PDF generated successfully: " . basename($pdf_path));
                    return array(
                        'success' => true,
                        'output_path' => $output_path,
                        'output_filename' => $output_filename,
                        'pdf_path' => $pdf_path,
                        'pdf_filename' => $pdf_filename,
                        'pdf_engine' => $pdf_result['engine']
                    );
                } else {
                    LDA_Logger::warn("PDF generation failed, but DOCX was created: " . $pdf_result['error']);
                    return array(
                        'success' => true,
                        'output_path' => $output_path,
                        'output_filename' => $output_filename,
                        'pdf_path' => null,
                        'pdf_error' => $pdf_result['error']
                    );
                }
            }

            // 7. Return Result (DOCX only)
            return array(
                'success' => true,
                'output_path' => $output_path,
                'output_filename' => $output_filename
            );

        } catch (Exception $e) {
            $error_msg = 'An error occurred during document generation: ' . $e->getMessage();
            LDA_Logger::error($error_msg);
            return array('success' => false, 'error_message' => $error_msg);
        }
    }

    /**
     * Prepare merge data from the form entry
     *
     * @return array
     */
    private function prepareMergeData() {
        LDA_Logger::log("=== PREPARE MERGE DATA METHOD CALLED ===");
        LDA_Logger::log("Form ID: " . $this->form['id']);
        LDA_Logger::log("Entry ID: " . $this->entry['id']);
        LDA_Logger::log("*** CRITICAL DEBUG: prepareMergeData() method is being called ***");
        LDA_Logger::log("*** THIS LOG SHOULD APPEAR IN THE LOGS - IF NOT, THE METHOD IS NOT BEING CALLED ***");
        
        $merge_data = array();
        
        // Get field mappings for this form
        $mappings = get_option('lda_field_mappings', array());
        $form_mappings = isset($mappings[$this->form['id']]) ? $mappings[$this->form['id']] : array();
        
        LDA_Logger::log("Found " . count($form_mappings) . " field mappings for form " . $this->form['id']);
        LDA_Logger::log("All stored mappings: " . json_encode($mappings, JSON_PRETTY_PRINT));
        LDA_Logger::log("Form mappings for form " . $this->form['id'] . ": " . json_encode($form_mappings, JSON_PRETTY_PRINT));
        
        // CRITICAL: Check if we have any field mappings at all
        if (empty($form_mappings)) {
            LDA_Logger::log("*** CRITICAL ERROR: NO FIELD MAPPINGS FOUND FOR FORM " . $this->form['id'] . " ***");
            LDA_Logger::log("*** This explains why field mappings are not being applied! ***");
            LDA_Logger::log("*** Available form IDs in mappings: " . implode(', ', array_keys($mappings)) . " ***");
        }
        
        // Debug: Check if form ID is correct
        LDA_Logger::log("DEBUG: Form ID from form object: " . $this->form['id']);
        LDA_Logger::log("DEBUG: Form ID type: " . gettype($this->form['id']));
        LDA_Logger::log("DEBUG: Available form IDs in mappings: " . implode(', ', array_keys($mappings)));
        
        // Apply dynamic field mappings FIRST
        LDA_Logger::log("CRITICAL: Starting to apply field mappings. Total mappings to process: " . count($form_mappings));
        LDA_Logger::log("CRITICAL: Available form field mappings: " . json_encode($form_mappings, JSON_PRETTY_PRINT));
        
        foreach ($form_mappings as $merge_tag => $field_id) {
            $field_value = function_exists('rgar') ? rgar($this->entry, (string) $field_id) : (isset($this->entry[(string) $field_id]) ? $this->entry[(string) $field_id] : '');
            LDA_Logger::log("CRITICAL: Processing mapping {$merge_tag} -> field {$field_id}, value: '{$field_value}'");
            LDA_Logger::log("CRITICAL: Entry data keys: " . implode(', ', array_keys($this->entry)));
            LDA_Logger::log("CRITICAL: Entry data sample: " . json_encode(array_slice($this->entry, 0, 5), JSON_PRETTY_PRINT));
            if (!empty($field_value)) {
                $merge_data[$merge_tag] = $field_value;
                LDA_Logger::log("CRITICAL MAPPING APPLIED: {$merge_tag} = '{$field_value}' (from field {$field_id})");
            } else {
                LDA_Logger::log("CRITICAL: Field {$field_id} is empty, skipping mapping for {$merge_tag}");
                LDA_Logger::log("CRITICAL: Available entry fields: " . implode(', ', array_keys($this->entry)));
            }
        }
        LDA_Logger::log("CRITICAL: Finished applying field mappings. Total merge data items: " . count($merge_data));
        
        // CRITICAL: Add form_id to merge data so field mappings can be applied in merge engine
        $merge_data['form_id'] = $this->form['id'];
        LDA_Logger::log("CRITICAL: Added form_id to merge data: " . $this->form['id']);
        
        // Add form data with multiple naming conventions (fallback)
        foreach ($this->form['fields'] as $field) {
            $value = function_exists('rgar') ? rgar($this->entry, (string) $field->id) : (isset($this->entry[(string) $field->id]) ? $this->entry[(string) $field->id] : '');
            
            // Debug: Log field information
            LDA_Logger::log("Processing field ID {$field->id}: '{$field->label}' (Admin: '{$field->adminLabel}') = '{$value}'");
            
            // Special debugging for ABN fields (including ABN Lookup plugin patterns)
            $is_abn_field = false;
            $abn_field_type = '';
            
            // Check for ABN Lookup plugin field types
            if (isset($field->enable_abnlookup) && $field->enable_abnlookup) {
                $is_abn_field = true;
                $abn_field_type = 'ABN_LOOKUP_MAIN';
            } elseif (isset($field->abnlookup_results_enable) && $field->abnlookup_results_enable && !empty($field->abnlookup_results)) {
                $is_abn_field = true;
                $abn_field_type = 'ABN_LOOKUP_RESULT_' . $field->abnlookup_results;
            } elseif (isset($field->abnlookup_enable_gst) && !empty($field->abnlookup_enable_gst)) {
                $is_abn_field = true;
                $abn_field_type = 'ABN_LOOKUP_GST';
            } elseif (isset($field->abnlookup_enable_business_name) && !empty($field->abnlookup_enable_business_name)) {
                $is_abn_field = true;
                $abn_field_type = 'ABN_LOOKUP_BUSINESS_NAME';
            } elseif (stripos($field->label, 'abn') !== false || 
                      stripos($field->adminLabel, 'abn') !== false ||
                      stripos($field->label, 'abnlookup') !== false ||
                      stripos($field->adminLabel, 'abnlookup') !== false) {
                $is_abn_field = true;
                $abn_field_type = 'ABN_TEXT_FIELD';
            }
            
            if ($is_abn_field) {
                LDA_Logger::log("ABN FIELD DETECTED - ID: {$field->id}, Label: '{$field->label}', Admin: '{$field->adminLabel}', Type: '{$field->type}', ABN_Type: '{$abn_field_type}', Value: '{$value}'");
            }
            
            // Handle complex fields like Name and Address that return arrays
            if (is_array($value)) {
                $value = implode(' ', array_filter($value));
                LDA_Logger::log("Field {$field->id} was array, converted to: '{$value}'");
            }
            
            // Handle checkbox fields specially
            if ($field->type === 'checkbox' && is_array($value)) {
                // For checkbox fields, join selected values with commas
                $value = implode(', ', array_filter($value));
                LDA_Logger::log("Checkbox field {$field->id} processed: '{$value}'");
            }
            
            // Create multiple variations of field names for better compatibility
            $field_labels = array();
            
            // Primary label (admin label preferred)
            if (!empty($field->adminLabel)) {
                $field_labels[] = $field->adminLabel;
                // Also create uppercase version for {$VARIABLE} format
                $field_labels[] = strtoupper($field->adminLabel);
            }
            if (!empty($field->label)) {
                $field_labels[] = $field->label;
                // Also create uppercase version for {$VARIABLE} format
                $field_labels[] = strtoupper($field->label);
            }
            
            // Add field ID as a merge tag (webhook-style patterns)
            $field_labels[] = 'field_' . $field->id;
            $field_labels[] = 'FIELD_' . $field->id;
            $field_labels[] = 'input_' . $field->id;
            $field_labels[] = 'INPUT_' . $field->id;
            
            // Special handling for ABN Lookup plugin fields
            if ($is_abn_field) {
                // Add ABN merge tags based on field type
                switch ($abn_field_type) {
                    case 'ABN_LOOKUP_MAIN':
                        $field_labels[] = 'USR_ABN';
                        $field_labels[] = 'PT2_ABN';
                        $field_labels[] = 'ABN';
                        LDA_Logger::log("ABN Lookup main field detected - adding ABN merge tags for field ID {$field->id}");
                        break;
                    case 'ABN_LOOKUP_RESULT_abnlookup_entity_name':
                        $field_labels[] = 'USR_Name';
                        $field_labels[] = 'PT2_Name';
                        $field_labels[] = 'BUSINESS_NAME';
                        LDA_Logger::log("ABN Lookup entity name field detected - adding name merge tags for field ID {$field->id}");
                        break;
                    case 'ABN_LOOKUP_RESULT_abnlookup_entity_type':
                        $field_labels[] = 'ENTITY_TYPE';
                        LDA_Logger::log("ABN Lookup entity type field detected - adding entity type merge tags for field ID {$field->id}");
                        break;
                    case 'ABN_LOOKUP_RESULT_abnlookup_entity_status':
                        $field_labels[] = 'ABN_STATUS';
                        LDA_Logger::log("ABN Lookup entity status field detected - adding status merge tags for field ID {$field->id}");
                        break;
                    case 'ABN_LOOKUP_RESULT_abnlookup_entity_postcode':
                        $field_labels[] = 'USR_Postcode';
                        $field_labels[] = 'PT2_Postcode';
                        $field_labels[] = 'POSTCODE';
                        LDA_Logger::log("ABN Lookup entity postcode field detected - adding postcode merge tags for field ID {$field->id}");
                        break;
                    case 'ABN_LOOKUP_RESULT_abnlookup_entity_state':
                        $field_labels[] = 'USR_State';
                        $field_labels[] = 'PT2_State';
                        $field_labels[] = 'REF_State';
                        LDA_Logger::log("ABN Lookup entity state field detected - adding state merge tags for field ID {$field->id}");
                        break;
                    case 'ABN_LOOKUP_GST':
                        $field_labels[] = 'GST_REGISTERED';
                        LDA_Logger::log("ABN Lookup GST field detected - adding GST merge tags for field ID {$field->id}");
                        break;
                    case 'ABN_LOOKUP_BUSINESS_NAME':
                        $field_labels[] = 'USR_Business';
                        $field_labels[] = 'PT2_Business';
                        $field_labels[] = 'BUSINESS_NAME';
                        LDA_Logger::log("ABN Lookup business name field detected - adding business name merge tags for field ID {$field->id}");
                        break;
                    case 'ABN_TEXT_FIELD':
                        $field_labels[] = 'USR_ABN';
                        $field_labels[] = 'PT2_ABN';
                        $field_labels[] = 'ABN';
                        LDA_Logger::log("ABN text field detected - adding ABN merge tags for field ID {$field->id}");
                        break;
                }
            }
            
            // Add sanitized versions
            if (!empty($field->label)) {
                $sanitized = sanitize_title($field->label);
                $field_labels[] = $sanitized;
                $field_labels[] = strtoupper($sanitized);
                $field_labels[] = strtolower($sanitized);
                
                // Add variations with underscores and hyphens
                $field_labels[] = str_replace('-', '_', $sanitized);
                $field_labels[] = str_replace('_', '-', $sanitized);
                $field_labels[] = strtoupper(str_replace('-', '_', $sanitized));
            }
            
            // Add specific merge tag patterns based on common legal document fields
            $this->addLegalDocumentMergeTags($field, $value, $field_labels);
            
            // Add all variations to merge data
            foreach ($field_labels as $label) {
                if (!empty($label)) {
                    $merge_data[$label] = $value;
                }
            }
        }
        
        // Add system data with multiple naming conventions
        $merge_data['FormTitle'] = $this->form['title'];
        $merge_data['FORMTITLE'] = $this->form['title'];
        $merge_data['Form_Title'] = $this->form['title']; // Legal document format
        $merge_data['EntryId'] = $this->entry['id'];
        $merge_data['ENTRYID'] = $this->entry['id'];
        $merge_data['Entry_ID'] = $this->entry['id']; // Legal document format
        $merge_data['Entry_Date'] = $this->entry['date_created']; // Legal document format
        $merge_data['User_IP'] = $this->entry['ip']; // Legal document format
        $merge_data['Source_URL'] = $this->entry['source_url']; // Legal document format
        $merge_data['SiteName'] = get_bloginfo('name');
        $merge_data['SITENAME'] = get_bloginfo('name');
        $merge_data['CurrentDate'] = date('Y-m-d');
        $merge_data['CURRENTDATE'] = date('Y-m-d');
        $merge_data['CurrentTime'] = date('H:i:s');
        $merge_data['CURRENTTIME'] = date('H:i:s');
        
        // Add user data if available
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            $merge_data['UserFirstName'] = $user->first_name;
            $merge_data['USERFIRSTNAME'] = $user->first_name;
            $merge_data['UserLastName'] = $user->last_name;
            $merge_data['USERLASTNAME'] = $user->last_name;
            $merge_data['UserEmail'] = $user->user_email;
            $merge_data['USEREMAIL'] = $user->user_email;
            $merge_data['UserName'] = $user->display_name;
            $merge_data['USERNAME'] = $user->display_name;
            
            // Add legal document format user data
            $merge_data['user_id'] = $user->ID;
            $merge_data['user_login'] = $user->user_login;
            $merge_data['user_email'] = $user->user_email;
            $merge_data['display_nam'] = $user->display_name;
        } else {
            // For non-logged-in users, try to extract user info from form fields
            $this->extractUserInfoFromFormFields($merge_data);
        }
        
        // Add common business-related variables that might be used in templates
        $merge_data['USR_Business'] = isset($merge_data['USR_BUSINESS']) ? $merge_data['USR_BUSINESS'] : '';
        $merge_data['PT2_Business'] = isset($merge_data['PT2_BUSINESS']) ? $merge_data['PT2_BUSINESS'] : '';
        
        // Try to find business names from form fields if not already set
        if (empty($merge_data['USR_Business'])) {
            foreach ($merge_data as $key => $value) {
                if (stripos($key, 'business') !== false && !empty($value)) {
                    $merge_data['USR_Business'] = $value;
                    LDA_Logger::log("Found USR_Business from field: {$key} = {$value}");
                    break;
                }
            }
        }
        
        if (empty($merge_data['PT2_Business'])) {
            // Look for second business name (could be same as first for now)
            $business_fields = array();
            foreach ($merge_data as $key => $value) {
                if (stripos($key, 'business') !== false && !empty($value)) {
                    $business_fields[] = $value;
                }
            }
            if (count($business_fields) > 1) {
                $merge_data['PT2_Business'] = $business_fields[1];
            } elseif (count($business_fields) == 1) {
                $merge_data['PT2_Business'] = $business_fields[0];
            }
        }
        
        // Generate missing standard merge tags
        $this->generateMissingMergeTags($merge_data);
        
        // DISABLED: Add specific field mappings based on Gravity Forms analysis
        // $this->addSpecificFieldMappings($merge_data);
        LDA_Logger::log("*** DISABLED: addSpecificFieldMappings() method to prevent hardcoded same values ***");
        
        // Debug: Log all merge data keys to see what's available
        LDA_Logger::log("Available merge data keys: " . implode(', ', array_keys($merge_data)));
        
        // Try to find business-related fields with different naming patterns
        foreach ($merge_data as $key => $value) {
            if (stripos($key, 'business') !== false || stripos($key, 'company') !== false) {
                LDA_Logger::log("Found business-related field: {$key} = {$value}");
            }
        }
        
        // Add fallback business fields if not found
        if (empty($merge_data['USR_Business'])) {
            // Try to find any business field for USR
            foreach ($merge_data as $key => $value) {
                if (stripos($key, 'usr') !== false && (stripos($key, 'business') !== false || stripos($key, 'company') !== false)) {
                    $merge_data['USR_Business'] = $value;
                    LDA_Logger::log("Set USR_Business from field {$key}: {$value}");
                    break;
                }
            }
        }
        
        if (empty($merge_data['PT2_Business'])) {
            // Try to find any business field for PT2
            foreach ($merge_data as $key => $value) {
                if (stripos($key, 'pt2') !== false && (stripos($key, 'business') !== false || stripos($key, 'company') !== false)) {
                    $merge_data['PT2_Business'] = $value;
                    LDA_Logger::log("Set PT2_Business from field {$key}: {$value}");
                    break;
                }
            }
        }
        
        // Map specific form field names to merge tag keys
        if (empty($merge_data['USR_Business'])) {
            // Look for user business fields
            foreach ($merge_data as $key => $value) {
                if (stripos($key, 'trading') !== false || stripos($key, 'business') !== false) {
                    if (stripos($key, 'counterparty') === false) { // Not counterparty (PT2)
                        $merge_data['USR_Business'] = $value;
                        LDA_Logger::log("Set USR_Business from trading/business field {$key}: {$value}");
                        break;
                    }
                }
            }
        }
        
        if (empty($merge_data['PT2_Business'])) {
            // Look for counterparty business fields
            foreach ($merge_data as $key => $value) {
                if (stripos($key, 'counterparty') !== false && (stripos($key, 'business') !== false || stripos($key, 'trading') !== false)) {
                    $merge_data['PT2_Business'] = $value;
                    LDA_Logger::log("Set PT2_Business from counterparty field {$key}: {$value}");
                    break;
                }
            }
        }
        
        // Dynamic field mapping based on field content and context
        $this->mapFieldsDynamically($merge_data);
        
        return $merge_data;
    }
    
    /**
     * Dynamically map form fields to merge tag keys based on field content and context
     */
    private function mapFieldsDynamically(&$merge_data) {
        LDA_Logger::log("Starting dynamic field mapping");
        
        // Define comprehensive mapping rules based on the screenshots
        $mapping_rules = array(
            // User/Business Information
            'USR_Name' => array(
                'keywords' => array('legal', 'name', 'business', 'company'),
                'exclude_keywords' => array('client', 'counterparty', 'pt2', 'contact', 'signatory'),
                'priority' => 1.0
            ),
            'USR_ABN' => array(
                'keywords' => array('abn', 'business', 'registration', 'number', 'acn', 'abnlookup', 'australian business number'),
                'exclude_keywords' => array('client', 'counterparty', 'pt2', 'second', 'other'),
                'priority' => 1.2
            ),
            'USR_ABV' => array(
                'keywords' => array('abbreviated', 'abbreviation', 'short', 'business'),
                'exclude_keywords' => array('client', 'counterparty', 'pt2'),
                'priority' => 0.9
            ),
            'USR_Business' => array(
                'keywords' => array('business', 'trading', 'company', 'corporate'),
                'exclude_keywords' => array('client', 'counterparty', 'pt2'),
                'priority' => 1.0
            ),
            'USR_Address' => array(
                'keywords' => array('address', 'location', 'business', 'company'),
                'exclude_keywords' => array('client', 'counterparty', 'pt2'),
                'priority' => 0.8
            ),
            'USR_URL' => array(
                'keywords' => array('website', 'url', 'web', 'site', 'business'),
                'exclude_keywords' => array('client', 'counterparty', 'pt2'),
                'priority' => 0.7
            ),
            'USR_Notice' => array(
                'keywords' => array('notice', 'period', 'business'),
                'exclude_keywords' => array('client', 'counterparty', 'pt2'),
                'priority' => 0.7
            ),
            
            // User Contact Information
            'USR_Contact_FN' => array(
                'keywords' => array('contact', 'first', 'name', 'business'),
                'exclude_keywords' => array('client', 'counterparty', 'pt2', 'signatory'),
                'priority' => 0.8
            ),
            'USR_Contact_LN' => array(
                'keywords' => array('contact', 'last', 'name', 'business'),
                'exclude_keywords' => array('client', 'counterparty', 'pt2', 'signatory'),
                'priority' => 0.8
            ),
            'USR_Contact_Role' => array(
                'keywords' => array('contact', 'role', 'position', 'business'),
                'exclude_keywords' => array('client', 'counterparty', 'pt2', 'signatory'),
                'priority' => 0.7
            ),
            'USR_Contact_Email' => array(
                'keywords' => array('contact', 'email', 'business'),
                'exclude_keywords' => array('client', 'counterparty', 'pt2', 'signatory'),
                'priority' => 0.8
            ),
            
            // User Signatory Information
            'USR_Signatory_FN' => array(
                'keywords' => array('signatory', 'first', 'name', 'business'),
                'exclude_keywords' => array('client', 'counterparty', 'pt2', 'contact'),
                'priority' => 0.8
            ),
            'USR_Signatory_LN' => array(
                'keywords' => array('signatory', 'last', 'name', 'business'),
                'exclude_keywords' => array('client', 'counterparty', 'pt2', 'contact'),
                'priority' => 0.8
            ),
            'USR_Signatory_Role' => array(
                'keywords' => array('signatory', 'role', 'position', 'business'),
                'exclude_keywords' => array('client', 'counterparty', 'pt2', 'contact'),
                'priority' => 0.7
            ),
            'USR_Sign_Fir' => array(
                'keywords' => array('signatory', 'signature', 'role', 'business'),
                'exclude_keywords' => array('client', 'counterparty', 'pt2'),
                'priority' => 0.6
            ),
            
            // User Banking Information
            'USR_Bnk' => array(
                'keywords' => array('bank', 'banking', 'details', 'business'),
                'exclude_keywords' => array('client', 'counterparty', 'pt2'),
                'priority' => 0.7
            ),
            'USR_Acct_Name' => array(
                'keywords' => array('account', 'name', 'bank', 'business'),
                'exclude_keywords' => array('client', 'counterparty', 'pt2'),
                'priority' => 0.7
            ),
            'USR_Acct_BSB' => array(
                'keywords' => array('bsb', 'bank', 'account', 'business'),
                'exclude_keywords' => array('client', 'counterparty', 'pt2'),
                'priority' => 0.7
            ),
            'USR_Acct_Nmr' => array(
                'keywords' => array('account', 'number', 'bank', 'business'),
                'exclude_keywords' => array('client', 'counterparty', 'pt2'),
                'priority' => 0.7
            ),
            
            // Counterparty/Client Information
            'PT2_Name' => array(
                'keywords' => array('legal', 'name', 'client', 'counterparty'),
                'exclude_keywords' => array('your', 'usr', 'business', 'contact', 'signatory'),
                'priority' => 1.0
            ),
            'PT2_ABN' => array(
                'keywords' => array('abn', 'client', 'counterparty', 'registration', 'number', 'acn', 'abnlookup', 'australian business number'),
                'exclude_keywords' => array('your', 'usr', 'business', 'first', 'primary'),
                'priority' => 1.2
            ),
            'PT2_ABV' => array(
                'keywords' => array('abbreviated', 'abbreviation', 'short', 'client', 'counterparty'),
                'exclude_keywords' => array('your', 'usr', 'business'),
                'priority' => 0.9
            ),
            'PT2_Business' => array(
                'keywords' => array('business', 'trading', 'company', 'corporate', 'client', 'counterparty'),
                'exclude_keywords' => array('your', 'usr'),
                'priority' => 1.0
            ),
            'PT2_Address' => array(
                'keywords' => array('address', 'location', 'client', 'counterparty'),
                'exclude_keywords' => array('your', 'usr', 'business'),
                'priority' => 0.8
            ),
            'PT2_Notice' => array(
                'keywords' => array('notice', 'period', 'client', 'counterparty'),
                'exclude_keywords' => array('your', 'usr', 'business'),
                'priority' => 0.7
            ),
            
            // Counterparty Contact Information
            'PT2_Contact_FN' => array(
                'keywords' => array('contact', 'first', 'name', 'client', 'counterparty'),
                'exclude_keywords' => array('your', 'usr', 'business', 'signatory'),
                'priority' => 0.8
            ),
            'PT2_Contact_LN' => array(
                'keywords' => array('contact', 'last', 'name', 'client', 'counterparty'),
                'exclude_keywords' => array('your', 'usr', 'business', 'signatory'),
                'priority' => 0.8
            ),
            'PT2_Contact_Role' => array(
                'keywords' => array('contact', 'role', 'position', 'client', 'counterparty'),
                'exclude_keywords' => array('your', 'usr', 'business', 'signatory'),
                'priority' => 0.7
            ),
            'PT2_Contact_Email' => array(
                'keywords' => array('contact', 'email', 'client', 'counterparty'),
                'exclude_keywords' => array('your', 'usr', 'business', 'signatory'),
                'priority' => 0.8
            ),
            
            // Counterparty Signatory Information
            'PT2_Signatory_FN' => array(
                'keywords' => array('signatory', 'first', 'name', 'client', 'counterparty'),
                'exclude_keywords' => array('your', 'usr', 'business', 'contact'),
                'priority' => 0.8
            ),
            'PT2_Signatory_LN' => array(
                'keywords' => array('signatory', 'last', 'name', 'client', 'counterparty'),
                'exclude_keywords' => array('your', 'usr', 'business', 'contact'),
                'priority' => 0.8
            ),
            'PT2_Signatory_Role' => array(
                'keywords' => array('signatory', 'role', 'position', 'client', 'counterparty'),
                'exclude_keywords' => array('your', 'usr', 'business', 'contact'),
                'priority' => 0.7
            ),
            'PT2_Sign_Fir' => array(
                'keywords' => array('signatory', 'signature', 'role', 'client', 'counterparty'),
                'exclude_keywords' => array('your', 'usr'),
                'priority' => 0.6
            ),
            
            // Duty/Clause Information
            'Duty_1' => array(
                'keywords' => array('duty', '1', 'first', 'clause'),
                'exclude_keywords' => array(),
                'priority' => 0.6
            ),
            'Duty_2' => array(
                'keywords' => array('duty', '2', 'second', 'clause'),
                'exclude_keywords' => array(),
                'priority' => 0.6
            ),
            'Duty_3' => array(
                'keywords' => array('duty', '3', 'third', 'clause'),
                'exclude_keywords' => array(),
                'priority' => 0.6
            ),
            'Duty_4' => array(
                'keywords' => array('duty', '4', 'fourth', 'clause'),
                'exclude_keywords' => array(),
                'priority' => 0.6
            ),
            'Duty_5' => array(
                'keywords' => array('duty', '5', 'fifth', 'clause'),
                'exclude_keywords' => array(),
                'priority' => 0.6
            ),
            'Duty_6' => array(
                'keywords' => array('duty', '6', 'sixth', 'clause'),
                'exclude_keywords' => array(),
                'priority' => 0.6
            ),
            'Duty_7' => array(
                'keywords' => array('duty', '7', 'seventh', 'clause'),
                'exclude_keywords' => array(),
                'priority' => 0.6
            ),
            'Duty_8' => array(
                'keywords' => array('duty', '8', 'eighth', 'clause'),
                'exclude_keywords' => array(),
                'priority' => 0.6
            ),
            'Duty_9' => array(
                'keywords' => array('duty', '9', 'ninth', 'clause'),
                'exclude_keywords' => array(),
                'priority' => 0.6
            ),
            'Duty_10' => array(
                'keywords' => array('duty', '10', 'tenth', 'clause'),
                'exclude_keywords' => array(),
                'priority' => 0.6
            ),
            
            // Reference Information
            'REF_City' => array(
                'keywords' => array('city', 'location'),
                'exclude_keywords' => array(),
                'priority' => 0.7
            ),
            'REF_State' => array(
                'keywords' => array('state', 'territory', 'province'),
                'exclude_keywords' => array(),
                'priority' => 0.7
            ),
            
            // Other Fields
            'Comc_date' => array(
                'keywords' => array('commencement', 'start', 'date', 'begin'),
                'exclude_keywords' => array(),
                'priority' => 0.7
            ),
            'Inv_Due' => array(
                'keywords' => array('invoice', 'due', 'timeframe', 'payment'),
                'exclude_keywords' => array(),
                'priority' => 0.7
            ),
            'Late_Interest' => array(
                'keywords' => array('late', 'interest', 'rate', 'penalty'),
                'exclude_keywords' => array(),
                'priority' => 0.7
            ),
            'IP_Owner' => array(
                'keywords' => array('intellectual', 'property', 'ip', 'owner'),
                'exclude_keywords' => array(),
                'priority' => 0.7
            ),
            'SW_DEV' => array(
                'keywords' => array('software', 'development', 'dev', 'sw'),
                'exclude_keywords' => array(),
                'priority' => 0.7
            ),
            'terminate' => array(
                'keywords' => array('terminate', 'termination', 'convenience'),
                'exclude_keywords' => array(),
                'priority' => 0.7
            ),
            'Entry_id' => array(
                'keywords' => array('entry', 'id', 'identifier'),
                'exclude_keywords' => array(),
                'priority' => 0.5
            ),
            
            // Additional ABN Lookup plugin fields
            'ENTITY_TYPE' => array(
                'keywords' => array('entity', 'type', 'abnlookup'),
                'exclude_keywords' => array(),
                'priority' => 0.6
            ),
            'ABN_STATUS' => array(
                'keywords' => array('abn', 'status', 'abnlookup'),
                'exclude_keywords' => array(),
                'priority' => 0.6
            ),
            'GST_REGISTERED' => array(
                'keywords' => array('gst', 'registered', 'abnlookup'),
                'exclude_keywords' => array(),
                'priority' => 0.6
            ),
            'USR_Postcode' => array(
                'keywords' => array('postcode', 'zip', 'business'),
                'exclude_keywords' => array('client', 'counterparty', 'pt2'),
                'priority' => 0.7
            ),
            'PT2_Postcode' => array(
                'keywords' => array('postcode', 'zip', 'client', 'counterparty'),
                'exclude_keywords' => array('your', 'usr', 'business'),
                'priority' => 0.7
            ),
            'USR_State' => array(
                'keywords' => array('state', 'territory', 'business'),
                'exclude_keywords' => array('client', 'counterparty', 'pt2'),
                'priority' => 0.7
            ),
            'PT2_State' => array(
                'keywords' => array('state', 'territory', 'client', 'counterparty'),
                'exclude_keywords' => array('your', 'usr', 'business'),
                'priority' => 0.7
            )
        );
        
        // Process each mapping rule
        $mappings_made = 0;
        foreach ($mapping_rules as $merge_key => $rule) {
            if (empty($merge_data[$merge_key])) {
                $best_match = $this->findBestFieldMatch($merge_data, $rule);
                if ($best_match) {
                    $merge_data[$merge_key] = $best_match['value'];
                    $mappings_made++;
                    LDA_Logger::log("Dynamically mapped {$merge_key} from field '{$best_match['field']}' with score {$best_match['score']}: {$best_match['value']}");
                }
            }
        }
        
        LDA_Logger::log("Dynamic field mapping completed. {$mappings_made} mappings made out of " . count($mapping_rules) . " possible merge tags.");
        
        // Debug ABN fields specifically
        LDA_Logger::log("ABN Field Debug - USR_ABN: '" . (isset($merge_data['USR_ABN']) ? $merge_data['USR_ABN'] : 'NOT SET') . "'");
        LDA_Logger::log("ABN Field Debug - PT2_ABN: '" . (isset($merge_data['PT2_ABN']) ? $merge_data['PT2_ABN'] : 'NOT SET') . "'");
        
        // Log all fields that contain 'abn' for debugging
        foreach ($merge_data as $key => $value) {
            if (stripos($key, 'abn') !== false) {
                LDA_Logger::log("ABN-related field found: '{$key}' = '{$value}'");
            }
        }
        
        // Log webhook-related patterns for debugging
        LDA_Logger::log("Webhook-style field processing completed. Total merge data items: " . count($merge_data));
        
        // Check for webhook-style field patterns
        $webhook_patterns = array('field_', 'FIELD_', 'input_', 'INPUT_');
        foreach ($webhook_patterns as $pattern) {
            $count = 0;
            foreach ($merge_data as $key => $value) {
                if (strpos($key, $pattern) === 0) {
                    $count++;
                }
            }
            if ($count > 0) {
                LDA_Logger::log("Found {$count} fields with '{$pattern}' pattern (webhook-style)");
            }
        }
    }
    
    /**
     * Find the best field match for a given mapping rule
     */
    private function findBestFieldMatch($merge_data, $rule) {
        $best_match = null;
        $best_score = 0;
        
        foreach ($merge_data as $field_name => $field_value) {
            if (empty($field_value)) continue;
            
            $score = $this->calculateFieldScore($field_name, $rule);
            
            if ($score > $best_score) {
                $best_score = $score;
                $best_match = array(
                    'field' => $field_name,
                    'value' => $field_value,
                    'score' => $score
                );
            }
        }
        
        return $best_match;
    }
    
    /**
     * Calculate a score for how well a field matches a mapping rule
     */
    private function calculateFieldScore($field_name, $rule) {
        $score = 0;
        $field_lower = strtolower($field_name);
        
        // Check for required keywords
        foreach ($rule['keywords'] as $keyword) {
            if (stripos($field_lower, $keyword) !== false) {
                $score += 10; // Base score for keyword match
                
                // Bonus for exact matches
                if ($field_lower === $keyword) {
                    $score += 20;
                }
                
                // Bonus for keyword at start of field name
                if (strpos($field_lower, $keyword) === 0) {
                    $score += 5;
                }
            }
        }
        
        // Penalty for exclude keywords
        foreach ($rule['exclude_keywords'] as $exclude_keyword) {
            if (stripos($field_lower, $exclude_keyword) !== false) {
                $score -= 15; // Heavy penalty for exclude keywords
            }
        }
        
        // Apply priority multiplier
        $score *= $rule['priority'];
        
        return $score;
    }
    
    /**
     * Add legal document specific merge tags based on field patterns
     *
     * @param object $field Gravity Forms field object
     * @param mixed $value Field value
     * @param array $field_labels Array to add labels to
     */
    private function addLegalDocumentMergeTags($field, $value, &$field_labels) {
        $field_label = strtolower($field->label ?? '');
        $field_admin_label = strtolower($field->adminLabel ?? '');
        $field_id = $field->id;
        
        // Map common field patterns to legal document merge tags
        $legal_mappings = array(
            // Business/Company fields
            'business' => array('USR_Business', 'PT2_Business', 'Business'),
            'company' => array('USR_Business', 'PT2_Business', 'Company'),
            'trading' => array('USR_Business', 'PT2_Business', 'Trading'),
            'legal name' => array('USR_Name', 'PT2_Name', 'Legal_Name'),
            'abn' => array('USR_ABN', 'PT2_ABN', 'ABN'),
            'acn' => array('USR_ACN', 'PT2_ACN', 'ACN'),
            
            // Signatory fields
            'signatory' => array('USR_Sign', 'PT2_Sign'),
            'signature' => array('USR_Sign', 'PT2_Sign'),
            'sign' => array('USR_Sign', 'PT2_Sign'),
            'first name' => array('USR_Sign_Fir', 'PT2_Sign_Fir'),
            'last name' => array('USR_Sign_La', 'PT2_Sign_Las'),
            'middle name' => array('USR_Sign_Mi', 'PT2_Sign_Mic'),
            'suffix' => array('USR_Sign_Su', 'PT2_Sign_Suf'),
            'title' => array('USR_Sign_Pro', 'PT2_Sign_Pre'),
            'role' => array('USR_Sign_Ro', 'PT2_Sign_Rol'),
            'email' => array('USR_Sign_En', 'PT2_Sign_Em'),
            
            // Address/Location fields
            'address' => array('USR_Address', 'PT2_Address'),
            'state' => array('REF_State'),
            'jurisdiction' => array('REF_State'),
            
            // Date fields
            'date' => array('Effective_Da'),
            'effective' => array('Effective_Da'),
            
            // Purpose/Concept fields
            'purpose' => array('Purpose'),
            'concept' => array('Concept'),
            'description' => array('Concept'),
            
            // Payment fields
            'payment' => array('Pmt_Service', 'Pmt_Negotia', 'Pmt_Busines', 'Pmt_Other'),
            'service' => array('Pmt_Service'),
            'negotiation' => array('Pmt_Negotia'),
            'business' => array('Pmt_Busines'),
            'other' => array('Pmt_Other'),
        );
        
        // Check if field matches any legal document patterns
        foreach ($legal_mappings as $pattern => $tags) {
            if (strpos($field_label, $pattern) !== false || strpos($field_admin_label, $pattern) !== false) {
                foreach ($tags as $tag) {
                    $field_labels[] = $tag;
                    LDA_Logger::debug("Added legal merge tag: {$tag} for field: {$field_label}");
                }
            }
        }
        
        // Add user-specific tags
        if (strpos($field_label, 'user') !== false || strpos($field_admin_label, 'user') !== false) {
            $field_labels[] = 'user_id';
            $field_labels[] = 'user_login';
            $field_labels[] = 'user_email';
            $field_labels[] = 'display_nam';
        }
        
        // Add form-specific tags
        $field_labels[] = 'Form_Title';
        $field_labels[] = 'Entry_ID';
        $field_labels[] = 'Entry_Date';
        $field_labels[] = 'User_IP';
        $field_labels[] = 'Source_URL';
    }
    
    /**
     * Generate missing standard merge tags based on available data
     *
     * @param array $merge_data Reference to merge data array
     */
    private function generateMissingMergeTags(&$merge_data) {
        LDA_Logger::log("*** CRITICAL DEBUG: generateMissingMergeTags() method is being called ***");
        LDA_Logger::log("*** THIS IS WHERE THE MERGE DATA IS BEING PREPARED ***");
        LDA_Logger::log("Generating missing standard merge tags");
        
        // Standard merge tags that should always be available
        $standard_tags = array(
            'USR_Business', 'PT2_Business',
            'USR_Name', 'PT2_Name', 
            'USR_ABN', 'PT2_ABN',
            'USR_ABV', 'PT2_ABV',
            'USR_Signatory_FN', 'USR_Signatory_LN', 'USR_Signatory_Role',
            'PT2_Signatory_FN', 'PT2_Signatory_LN', 'PT2_Signatory_Role',
            'Eff_date', 'EffectiveDate',
            'DISPLAY_NAME', 'DISPLAY_EMAIL', 'Login_ID',
            'Concept', 'Purpose'
        );
        
        foreach ($standard_tags as $tag) {
            if (!isset($merge_data[$tag]) || empty($merge_data[$tag])) {
                // Try to find a value from existing data
                $found_value = $this->findValueForTag($tag, $merge_data);
                if ($found_value !== null) {
                    $merge_data[$tag] = $found_value;
                    LDA_Logger::log("Generated missing tag {$tag} = {$found_value}");
                }
            }
        }
    }
    
    /**
     * Find a value for a missing merge tag from existing data
     *
     * @param string $tag The merge tag to find a value for
     * @param array $merge_data The existing merge data
     * @return string|null The found value or null
     */
    private function findValueForTag($tag, $merge_data) {
        $tag_lower = strtolower($tag);
        
        // Direct matches
        if (isset($merge_data[$tag])) {
            return $merge_data[$tag];
        }
        
        // Pattern-based matching
        if (strpos($tag_lower, 'business') !== false) {
            foreach ($merge_data as $key => $value) {
                if (stripos($key, 'business') !== false && !empty($value)) {
                    return $value;
                }
            }
        }
        
        if (strpos($tag_lower, 'name') !== false) {
            foreach ($merge_data as $key => $value) {
                if (stripos($key, 'name') !== false && !empty($value)) {
                    return $value;
                }
            }
        }
        
        if (strpos($tag_lower, 'abn') !== false) {
            foreach ($merge_data as $key => $value) {
                if (stripos($key, 'abn') !== false && !empty($value)) {
                    return $value;
                }
            }
        }
        
        if (strpos($tag_lower, 'signatory') !== false) {
            foreach ($merge_data as $key => $value) {
                if (stripos($key, 'signatory') !== false && !empty($value)) {
                    return $value;
                }
            }
        }
        
        if (strpos($tag_lower, 'date') !== false) {
            foreach ($merge_data as $key => $value) {
                if (stripos($key, 'date') !== false && !empty($value)) {
                    return $value;
                }
            }
        }
        
        if (strpos($tag_lower, 'display') !== false) {
            foreach ($merge_data as $key => $value) {
                if (stripos($key, 'display') !== false && !empty($value)) {
                    return $value;
                }
            }
        }
        
        return null;
    }
    
    /**
     * Extract user information from form fields for non-logged-in users
     *
     * @param array $merge_data Reference to merge data array
     */
    private function extractUserInfoFromFormFields(&$merge_data) {
        $first_name = '';
        $last_name = '';
        $email = '';
        $full_name = '';
        
        // Look for common field patterns that might contain user information
        foreach ($this->form['fields'] as $field) {
            $value = function_exists('rgar') ? rgar($this->entry, (string) $field->id) : (isset($this->entry[(string) $field->id]) ? $this->entry[(string) $field->id] : '');
            $field_label = strtolower($field->label ?? '');
            $field_admin_label = strtolower($field->adminLabel ?? '');
            
            // Handle complex fields like Name that return arrays
            if (is_array($value)) {
                $value = implode(' ', array_filter($value));
            }
            
            // Look for first name fields
            if (empty($first_name) && (
                strpos($field_label, 'first name') !== false ||
                strpos($field_admin_label, 'first name') !== false ||
                strpos($field_label, 'firstname') !== false ||
                strpos($field_admin_label, 'firstname') !== false ||
                strpos($field_label, 'given name') !== false ||
                strpos($field_admin_label, 'given name') !== false
            )) {
                $first_name = $value;
            }
            
            // Look for last name fields
            if (empty($last_name) && (
                strpos($field_label, 'last name') !== false ||
                strpos($field_admin_label, 'last name') !== false ||
                strpos($field_label, 'lastname') !== false ||
                strpos($field_admin_label, 'lastname') !== false ||
                strpos($field_label, 'surname') !== false ||
                strpos($field_admin_label, 'surname') !== false ||
                strpos($field_label, 'family name') !== false ||
                strpos($field_admin_label, 'family name') !== false
            )) {
                $last_name = $value;
            }
            
            // Look for email fields
            if (empty($email) && (
                strpos($field_label, 'email') !== false ||
                strpos($field_admin_label, 'email') !== false ||
                $field->type === 'email'
            )) {
                $email = $value;
            }
            
            // Look for full name fields
            if (empty($full_name) && (
                strpos($field_label, 'full name') !== false ||
                strpos($field_admin_label, 'full name') !== false ||
                strpos($field_label, 'name') !== false ||
                strpos($field_admin_label, 'name') !== false
            ) && $field->type === 'name') {
                $full_name = $value;
            }
        }
        
        // If we found a full name but no first/last names, try to split it
        if (!empty($full_name) && empty($first_name) && empty($last_name)) {
            $name_parts = explode(' ', trim($full_name));
            if (count($name_parts) >= 2) {
                $first_name = $name_parts[0];
                $last_name = implode(' ', array_slice($name_parts, 1));
            } else {
                $first_name = $full_name;
            }
        }
        
        // Set the user information in merge data
        if (!empty($first_name)) {
            $merge_data['UserFirstName'] = $first_name;
            $merge_data['USERFIRSTNAME'] = $first_name;
        }
        
        if (!empty($last_name)) {
            $merge_data['UserLastName'] = $last_name;
            $merge_data['USERLASTNAME'] = $last_name;
        }
        
        if (!empty($email)) {
            $merge_data['UserEmail'] = $email;
            $merge_data['USEREMAIL'] = $email;
        }
        
        // Create a display name
        if (!empty($first_name) || !empty($last_name)) {
            $display_name = trim($first_name . ' ' . $last_name);
            $merge_data['UserName'] = $display_name;
            $merge_data['USERNAME'] = $display_name;
        }
        
        LDA_Logger::log("Extracted user info from form fields - First: '{$first_name}', Last: '{$last_name}', Email: '{$email}'");
    }
    
    /**
     * Add specific field mappings based on Gravity Forms analysis
     */
    private function addSpecificFieldMappings(&$merge_data) {
        // Map specific Gravity Forms field IDs to expected merge tags
        // Based on the JSON analysis, these are the key field mappings:
        
        // Field ID 2: "business legal name" -> USR_Business, PT2_Business
        if (isset($merge_data['field_2']) && !empty($merge_data['field_2'])) {
            $merge_data['USR_Business'] = $merge_data['field_2'];
            $merge_data['PT2_Business'] = $merge_data['field_2'];
            $merge_data['USR_ABV'] = $this->generateAbbreviation($merge_data['field_2']);
            $merge_data['PT2_ABV'] = $this->generateAbbreviation($merge_data['field_2']);
            LDA_Logger::log("Mapped field_2 (business legal name) to USR_Business and PT2_Business: " . $merge_data['field_2']);
        }
        
        // Field ID 5: "business ABN" -> USR_ABN, PT2_ABN
        if (isset($merge_data['field_5']) && !empty($merge_data['field_5'])) {
            $merge_data['USR_ABN'] = $merge_data['field_5'];
            $merge_data['PT2_ABN'] = $merge_data['field_5'];
            LDA_Logger::log("Mapped field_5 (business ABN) to USR_ABN and PT2_ABN: " . $merge_data['field_5']);
        }
        
        // Field ID 16: "advisor's ABN" -> PT2_ABN (if not already set)
        if (isset($merge_data['field_16']) && !empty($merge_data['field_16']) && empty($merge_data['PT2_ABN'])) {
            $merge_data['PT2_ABN'] = $merge_data['field_16'];
            LDA_Logger::log("Mapped field_16 (advisor's ABN) to PT2_ABN: " . $merge_data['field_16']);
        }
        
        // Field ID 116: "business trading name" -> USR_Business, PT2_Business (if not already set)
        if (isset($merge_data['field_116']) && !empty($merge_data['field_116'])) {
            if (empty($merge_data['USR_Business'])) {
                $merge_data['USR_Business'] = $merge_data['field_116'];
                $merge_data['USR_ABV'] = $this->generateAbbreviation($merge_data['field_116']);
            }
            if (empty($merge_data['PT2_Business'])) {
                $merge_data['PT2_Business'] = $merge_data['field_116'];
                $merge_data['PT2_ABV'] = $this->generateAbbreviation($merge_data['field_116']);
            }
            LDA_Logger::log("Mapped field_116 (business trading name) to business fields: " . $merge_data['field_116']);
        }
        
        // Generate business abbreviations if not set
        if (empty($merge_data['USR_ABV']) && !empty($merge_data['USR_Business'])) {
            $merge_data['USR_ABV'] = $this->generateAbbreviation($merge_data['USR_Business']);
        }
        if (empty($merge_data['PT2_ABV']) && !empty($merge_data['PT2_Business'])) {
            $merge_data['PT2_ABV'] = $this->generateAbbreviation($merge_data['PT2_Business']);
        }
        
        // Generate names if not set - try WordPress user data first
        if (empty($merge_data['USR_Name'])) {
            // Try WordPress user display name first
            if (isset($merge_data['user_display_name']) && !empty($merge_data['user_display_name'])) {
                $merge_data['USR_Name'] = $merge_data['user_display_name'];
            } elseif (isset($merge_data['display_name']) && !empty($merge_data['display_name'])) {
                $merge_data['USR_Name'] = $merge_data['display_name'];
            } elseif (!empty($merge_data['USR_Business'])) {
                $merge_data['USR_Name'] = $merge_data['USR_Business'];
            }
        }
        
        if (empty($merge_data['PT2_Name'])) {
            // Try WordPress user display name first
            if (isset($merge_data['user_display_name']) && !empty($merge_data['user_display_name'])) {
                $merge_data['PT2_Name'] = $merge_data['user_display_name'];
            } elseif (isset($merge_data['display_name']) && !empty($merge_data['display_name'])) {
                $merge_data['PT2_Name'] = $merge_data['display_name'];
            } elseif (!empty($merge_data['PT2_Business'])) {
                $merge_data['PT2_Name'] = $merge_data['PT2_Business'];
            }
        }
        
        // Add Concept field if not set - try to find from form fields
        if (empty($merge_data['Concept'])) {
            // Look for concept-related fields in the form data
            foreach ($merge_data as $key => $value) {
                if ((stripos($key, 'concept') !== false || stripos($key, 'description') !== false) && !empty($value)) {
                    $merge_data['Concept'] = $value;
                    LDA_Logger::log("Found Concept from field {$key}: " . substr($value, 0, 100) . "...");
                    break;
                }
            }
            
            // If still not found, use default
            if (empty($merge_data['Concept'])) {
                $merge_data['Concept'] = 'the business concept'; // Default value
                LDA_Logger::log("Using default Concept: the business concept");
            }
        }
        
        // Add REF_State if not set - try to find from form fields first
        if (empty($merge_data['REF_State'])) {
            // Look for state-related fields in the form data
            foreach ($merge_data as $key => $value) {
                if (stripos($key, 'state') !== false && !empty($value)) {
                    $merge_data['REF_State'] = $value;
                    LDA_Logger::log("Found REF_State from field {$key}: {$value}");
                    break;
                }
            }
            
            // If still not found, use default
            if (empty($merge_data['REF_State'])) {
                $merge_data['REF_State'] = 'NSW'; // Default value
                LDA_Logger::log("Using default REF_State: NSW");
            }
        }
        
        LDA_Logger::log("Specific field mappings completed");
        
        // Add effective date if not set - try to find from form fields
        if (empty($merge_data['Effective_Date'])) {
            // Look for date-related fields in the form data
            foreach ($merge_data as $key => $value) {
                if ((stripos($key, 'date') !== false || stripos($key, 'effective') !== false) && !empty($value)) {
                    $merge_data['Effective_Date'] = $value;
                    LDA_Logger::log("Found Effective_Date from field {$key}: " . $value);
                    break;
                }
            }
            
            // If still not found, use current date
            if (empty($merge_data['Effective_Date'])) {
                $merge_data['Effective_Date'] = date('d/m/Y');
                LDA_Logger::log("Using current date as Effective_Date: " . $merge_data['Effective_Date']);
            }
        }
        
        // Add purpose fields based on CSV mapping
        if (empty($merge_data['Purpose'])) {
            // Look for purpose-related fields in the form data
            foreach ($merge_data as $key => $value) {
                if (stripos($key, 'purpose') !== false && !empty($value)) {
                    $merge_data['Purpose'] = $value;
                    LDA_Logger::log("Found Purpose from field {$key}: " . substr($value, 0, 100) . "...");
                    break;
                }
            }
        }
        
        // Add signatory information if not set
        if (empty($merge_data['USR_Sign'])) {
            // Try to construct from user display name or business name
            if (isset($merge_data['user_display_name']) && !empty($merge_data['user_display_name'])) {
                $merge_data['USR_Sign'] = $merge_data['user_display_name'];
            } elseif (isset($merge_data['display_name']) && !empty($merge_data['display_name'])) {
                $merge_data['USR_Sign'] = $merge_data['display_name'];
            } elseif (!empty($merge_data['USR_Name'])) {
                $merge_data['USR_Sign'] = $merge_data['USR_Name'];
            }
        }
        
        // Add signatory email if not set
        if (empty($merge_data['USR_Sign_Email'])) {
            if (isset($merge_data['user_email']) && !empty($merge_data['user_email'])) {
                $merge_data['USR_Sign_Email'] = $merge_data['user_email'];
            }
        }
        
        // Log key merge tags for debugging (based on CSV mapping)
        $key_tags = array(
            'USR_Business', 'PT2_Business', 'USR_Name', 'PT2_Name', 
            'USR_ABN', 'PT2_ABN', 'USR_ABV', 'PT2_ABV',
            'REF_State', 'Concept', 'Effective_Date', 'Purpose',
            'USR_Sign', 'USR_Sign_Email', 'PT2_Sign', 'PT2_Sign_Email',
            'user_id', 'user_login', 'user_email', 'display_name'
        );
        foreach ($key_tags as $tag) {
            if (isset($merge_data[$tag])) {
                LDA_Logger::log("Key merge tag {$tag}: " . $merge_data[$tag]);
            } else {
                LDA_Logger::log("Key merge tag {$tag}: NOT SET");
            }
        }
    }
    
    /**
     * Generate business abbreviation from full business name
     */
    private function generateAbbreviation($business_name) {
        if (empty($business_name)) {
            return '';
        }
        
        // Remove common suffixes
        $name = preg_replace('/\s+(Pty\s+Ltd|Ltd|Inc|Corp|LLC|Co\.?)$/i', '', $business_name);
        
        // Extract first letters of words
        $words = preg_split('/\s+/', $name);
        $abbreviation = '';
        
        foreach ($words as $word) {
            if (!empty($word)) {
                $abbreviation .= strtoupper(substr($word, 0, 1));
            }
        }
        
        // Limit to reasonable length
        if (strlen($abbreviation) > 6) {
            $abbreviation = substr($abbreviation, 0, 6);
        }
        
        return $abbreviation;
    }
}