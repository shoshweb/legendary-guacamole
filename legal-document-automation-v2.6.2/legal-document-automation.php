<?php
/**
 * Plugin Name: A Legal Documents
 * Plugin URI: https://mode.law/plugins/legal-document-automation
 * Description: Automated legal document generation with Webmerge-compatible merge tags, integrating Gravity Forms, MemberPress, and Google Drive.
 * Version: 2.6.2
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: Mode.law
 * Author URI: https://mode.law
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: legal-doc-automation
 * Domain Path: /languages
 * Network: false
 * 
 * @package LegalDocumentAutomation
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('LDA_VERSION', '2.6.0');
define('LDA_PLUGIN_FILE', __FILE__);
define('LDA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LDA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LDA_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('LDA_MIN_PHP_VERSION', '7.4');
define('LDA_MIN_WP_VERSION', '5.0');

/**
 * Main plugin class
 */
class LegalDocumentAutomation {
    
    private static $instance = null;
    private $error_messages = array();
    private $missing_dependencies = array();
    private $settings = array();
    
    /**
     * Get singleton instance
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // Check system requirements
        if (!$this->checkSystemRequirements()) {
            return;
        }
        
        add_action('plugins_loaded', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        register_uninstall_hook(__FILE__, array('LDA_LegalDocumentAutomation', 'uninstall'));
        
        // Emergency shutdown hook for critical errors
        add_action('shutdown', array($this, 'emergencyShutdown'));
    }
    
    /**
     * Check system requirements
     */
    private function checkSystemRequirements() {
        $errors = array();
        
        // Check PHP version
        if (version_compare(PHP_VERSION, LDA_MIN_PHP_VERSION, '<')) {
            $errors[] = sprintf(
                __('Legal Document Automation requires PHP %s or higher. Current version: %s', 'legal-doc-automation'),
                LDA_MIN_PHP_VERSION,
                PHP_VERSION
            );
        }
        
        // Check WordPress version
        if (version_compare(get_bloginfo('version'), LDA_MIN_WP_VERSION, '<')) {
            $errors[] = sprintf(
                __('Legal Document Automation requires WordPress %s or higher. Current version: %s', 'legal-doc-automation'),
                LDA_MIN_WP_VERSION,
                get_bloginfo('version')
            );
        }
        
        // Check required PHP extensions
        $required_extensions = array('zip', 'xml', 'mbstring');
        foreach ($required_extensions as $ext) {
            if (!extension_loaded($ext)) {
                $errors[] = sprintf(
                    __('Legal Document Automation requires PHP %s extension.', 'legal-doc-automation'),
                    $ext
                );
            }
        }
        
        if (!empty($errors)) {
            $this->error_messages = $errors;
            add_action('admin_notices', array($this, 'showSystemRequirementsNotice'));
            return false;
        }
        
        return true;
    }
    
    /**
     * Show system requirements notice
     */
    public function showSystemRequirementsNotice() {
        echo '<div class="notice notice-error"><p>';
        echo '<strong>' . __('Legal Document Automation:', 'legal-doc-automation') . '</strong><br>';
        foreach ($this->error_messages as $error) {
            echo $error . '<br>';
        }
        echo '</p></div>';
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        // Load text domain for translations
        load_plugin_textdomain('legal-doc-automation', false, dirname(LDA_PLUGIN_BASENAME) . '/languages');
        
        // Check if required plugins are active
        $this->missing_dependencies = $this->checkRequiredPlugins();
        if (!empty($this->missing_dependencies)) {
            add_action('admin_notices', array($this, 'showRequiredPluginsNotice'));
            // Continue loading even with missing dependencies
        }
        
        // Load dependencies
        $this->loadDependencies();
        
        // Initialize components
        $this->initHooks();
        
        // Initialize admin if in admin area
        if (is_admin()) {
            if (class_exists('LDA_Admin')) {
            new LDA_Admin();
            } else {
                add_action('admin_notices', array($this, 'adminClassMissingNotice'));
                add_action('admin_notices', array($this, 'displayErrorInfo'));
            }
        }
    }
    
    /**
     * Admin notice for missing admin class
     */
    public function adminClassMissingNotice() {
        echo '<div class="notice notice-error"><p><strong>A Legal Documents Plugin Error:</strong> Admin class not found. Please deactivate and reactivate the plugin.</p></div>';
    }
    
    /**
     * Comprehensive error reporting for debugging
     */
    public function getDetailedErrorInfo() {
        $error_info = array(
            'plugin_version' => LDA_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'loaded_classes' => array(),
            'missing_classes' => array(),
            'file_permissions' => array(),
            'directory_status' => array(),
            'plugin_files' => array()
        );
        
        // Check loaded classes
        $required_classes = array(
            'LDA_Logger',
            'LDA_Admin',
            'LDA_DocumentProcessor',
            'LDA_EmailHandler',
            'LDA_MergeEngine',
            'LDA_GoogleDrive',
            'LDA_SimpleGoogleDrive',
            'LDA_RealGoogleDrive',
            'LDA_GoogleDriveGravityForms'
        );
        
        foreach ($required_classes as $class) {
            if (class_exists($class)) {
                $error_info['loaded_classes'][] = $class;
            } else {
                $error_info['missing_classes'][] = $class;
            }
        }
        
        // Check file permissions
        $upload_dir = wp_upload_dir();
        $directories = array(
            $upload_dir['basedir'] . '/lda-templates/',
            $upload_dir['basedir'] . '/lda-output/',
            $upload_dir['basedir'] . '/lda-backup/',
            $upload_dir['basedir'] . '/lda-logs/'
        );
        
        foreach ($directories as $dir) {
            $error_info['directory_status'][$dir] = array(
                'exists' => file_exists($dir),
                'writable' => is_writable($dir),
                'permissions' => file_exists($dir) ? substr(sprintf('%o', fileperms($dir)), -4) : 'N/A'
            );
        }
        
        // Check plugin files
        $plugin_files = array(
            'legal-document-automation.php',
            'includes/class-lda-logger.php',
            'includes/class-lda-admin.php',
            'includes/class-lda-document-processor.php',
            'includes/class-lda-email-handler.php',
            'includes/class-lda-merge-engine.php',
            'includes/class-lda-google-drive.php',
            'includes/class-lda-google-drive-gravity-forms.php',
            'admin/class-lda-admin.php'
        );
        
        foreach ($plugin_files as $file) {
            $file_path = LDA_PLUGIN_DIR . $file;
            $error_info['plugin_files'][$file] = array(
                'exists' => file_exists($file_path),
                'readable' => is_readable($file_path),
                'size' => file_exists($file_path) ? filesize($file_path) : 0
            );
        }
        
        return $error_info;
    }
    
    /**
     * Display detailed error information in admin
     */
    public function displayErrorInfo() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $error_info = $this->getDetailedErrorInfo();
        
        echo '<div class="notice notice-info">';
        echo '<h3>A Legal Documents Plugin - Debug Information</h3>';
        echo '<p><strong>Copy this information and provide it to support:</strong></p>';
        echo '<textarea readonly style="width: 100%; height: 300px; font-family: monospace; font-size: 12px;">';
        echo json_encode($error_info, JSON_PRETTY_PRINT);
        echo '</textarea>';
        echo '</div>';
    }
    
    /**
     * Check if required plugins are active
     */
    private function checkRequiredPlugins() {
        $required_plugins = array(
            'gravityforms/gravityforms.php' => 'Gravity Forms',
            'memberpress/memberpress.php' => 'MemberPress'
        );

        // Only check for Use-your-Drive if it's actually needed
        $settings = get_option('lda_settings', array());
        $gdrive_method = isset($settings['google_drive_method']) ? $settings['google_drive_method'] : 'native_api';
        
        // Only require Use-your-Drive if it's the selected method or if Google Drive is enabled but no method is set
        if ($gdrive_method === 'use_your_drive' || 
            (isset($settings['google_drive_enabled']) && $settings['google_drive_enabled'] && $gdrive_method === 'auto')) {
            $required_plugins['use-your-drive/use-your-drive.php'] = 'WP Cloud Plugins - Use-your-Drive';
        }

        $missing_plugins = array();
        
        foreach ($required_plugins as $plugin_path => $plugin_name) {
            if (!is_plugin_active($plugin_path)) {
                $missing_plugins[] = $plugin_name;
            }
        }
        
        return $missing_plugins;
    }
    
    /**
     * Show required plugins notice
     */
    public function showRequiredPluginsNotice() {
        if (empty($this->missing_dependencies)) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo '<strong>' . esc_html__('Legal Document Automation Error:', 'legal-doc-automation') . '</strong><br>';
        echo esc_html__('The following required plugins are not active. Please install and activate them:', 'legal-doc-automation');
        echo '<ul style="list-style: disc; margin-left: 20px;">';
        foreach ($this->missing_dependencies as $plugin_name) {
            echo '<li>' . esc_html($plugin_name) . '</li>';
        }
        echo '</ul>';
        echo '</p></div>';
    }
    
    /**
     * Load plugin dependencies
     */
    private function loadDependencies() {
        // No external dependencies needed - uses only WordPress built-in functions
        
        // Load classes from the 'includes' directory
        $includes = array(
            'class-lda-logger.php',
                'class-lda-simple-docx.php', // Simple DOCX processing
                'class-lda-webmerge-docx.php', // Webmerge-compatible DOCX processing
            'class-lda-merge-engine.php',
            'class-lda-document-processor.php',
            'class-lda-email-handler.php',
                'class-lda-google-drive.php', // Use-your-Drive integration
                'class-lda-google-drive-gravity-forms.php', // Gravity Forms integration pattern
                'class-lda-google-drive-direct.php', // Use-your-Drive direct integration
                'class-lda-google-drive-v2.php', // Use-your-Drive V2 integration
                'class-lda-google-drive-simple.php', // Use-your-Drive simple integration
                'class-lda-real-google-drive.php', // Real Google Drive
                'class-lda-simple-google-drive.php', // Simple Google Drive
                'class-lda-google-drive-wordpress.php', // WordPress-native Google Drive
            'class-lda-pdf-handler.php',
        );
        
        foreach ($includes as $file) {
            $file_path = LDA_PLUGIN_DIR . 'includes/' . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            } else {
                // It's okay if these don't exist yet, we're just scaffolding.
                // In a real scenario, we'd need to create these files.
                // error_log("LDA: Missing required file: " . $file);
            }
        }

        // Load the admin class from the 'admin' directory
        $admin_class_path = LDA_PLUGIN_DIR . 'admin/class-lda-admin.php';
        if (is_admin() && file_exists($admin_class_path)) {
            require_once $admin_class_path;
        }
    }
    
    /**
     * Initialize hooks
     */
    private function initHooks() {
        // Gravity Forms integration
        add_action('gform_after_submission', array($this, 'processFormSubmission'), 10, 2);
        
        // Settings link on plugins page
        add_filter('plugin_action_links_' . LDA_PLUGIN_BASENAME, array($this, 'addSettingsLink'));
        
        // AJAX handlers (moved to admin class)
        
        // Cleanup scheduled tasks
        add_action('lda_cleanup_old_files', array($this, 'cleanupOldFiles'));
        add_action('lda_cleanup_old_logs', array($this, 'cleanupOldLogs'));
        
        // Schedule cleanup if not already scheduled
        if (!wp_next_scheduled('lda_cleanup_old_files')) {
            wp_schedule_event(time(), 'daily', 'lda_cleanup_old_files');
        }
        
        if (!wp_next_scheduled('lda_cleanup_old_logs')) {
            wp_schedule_event(time(), 'weekly', 'lda_cleanup_old_logs');
        }
    }
    
    /**
     * Process Gravity Forms submission
     */
    public function processFormSubmission($entry, $form) {
        LDA_Logger::log("=== FORM SUBMISSION STARTED ===");
        LDA_Logger::log("Form ID: " . $form['id']);
        LDA_Logger::log("Entry ID: " . $entry['id']);
        LDA_Logger::log("Form Title: " . $form['title']);
        
        try {
            LDA_Logger::log("Step 1: Getting plugin settings");
            // Check if this form is enabled for document generation
            $settings = get_option('lda_settings', array());
            $this->settings = $settings; // Store settings for later use
            LDA_Logger::log("Settings retrieved: " . (empty($settings) ? 'EMPTY' : 'FOUND'));
            
            LDA_Logger::log("Step 2: Checking form-specific settings");
            // Check form-specific settings (enable by default for testing)
            $form_enabled = true; // Enable all forms by default
            if (function_exists('gform_get_meta')) {
                LDA_Logger::log("gform_get_meta function exists, checking meta");
                $meta_enabled = gform_get_meta($form['id'], 'lda_enabled');
                LDA_Logger::log("Meta enabled value: " . ($meta_enabled !== null ? $meta_enabled : 'NULL'));
                if ($meta_enabled !== null) {
                    $form_enabled = $meta_enabled;
                }
            } else {
                LDA_Logger::log("gform_get_meta function does not exist");
            }
            
            LDA_Logger::log("Form enabled status: " . ($form_enabled ? 'TRUE' : 'FALSE'));
            
            if (!$form_enabled) {
                LDA_Logger::log("Form {$form['id']} not specifically enabled for document generation - EXITING");
                return;
            }
            
            LDA_Logger::log("Step 3: Starting document generation process");
            LDA_Logger::log("Processing document generation for form {$form['id']}, entry {$entry['id']}");
            
            LDA_Logger::log("Step 4: Creating document processor");
            // Create document processor
            if (!class_exists('LDA_DocumentProcessor')) {
                LDA_Logger::error("LDA_DocumentProcessor class not found");
                GFFormsModel::add_note($entry['id'], 0, 'Legal Document Automation', 'Error: Document processor class not found');
                return;
            }
            
            try {
            $processor = new LDA_DocumentProcessor($entry, $form, $settings);
            LDA_Logger::log("Document processor created successfully");
            } catch (Exception $e) {
                LDA_Logger::error("Failed to create document processor: " . $e->getMessage());
                GFFormsModel::add_note($entry['id'], 0, 'Legal Document Automation', 'Error: Failed to create document processor - ' . $e->getMessage());
                return;
            }
            
            LDA_Logger::log("Step 5: PDF generation DISABLED - using DOCX only");
            // PDF generation is DISABLED to prevent conflicts
            $enable_pdf = false;
            LDA_Logger::log("PDF generation disabled: " . ($enable_pdf ? 'TRUE' : 'FALSE'));
            
            LDA_Logger::log("Step 6: Processing document (DOCX only)");
            // Process the document (DOCX only - no PDF conflicts)
            LDA_Logger::log("Processing document WITHOUT PDF generation (PDF disabled to prevent conflicts)");
            $result = $processor->process();
            
            LDA_Logger::log("Document processing result: " . json_encode($result, JSON_PRETTY_PRINT));
            
            if ($result['success']) {
                LDA_Logger::log("Step 7: Document generation successful for entry {$entry['id']}");
                LDA_Logger::log("Generated files: " . json_encode($result, JSON_PRETTY_PRINT));

                LDA_Logger::log("Step 8: Adding entry note");
                // Add entry note for tracking
                $note_message = 'Document generated successfully';
                if ($enable_pdf && isset($result['pdf_path'])) {
                    $note_message .= ' (DOCX + PDF)';
                }
                GFFormsModel::add_note($entry['id'], 0, 'Legal Document Automation', $note_message);
                LDA_Logger::log("Entry note added successfully");

                    LDA_Logger::log("Step 9: Handling Google Drive upload");
                    // Handle Google Drive upload (both DOCX and PDF if available)
                    try {
                        $this->handleGoogleDriveUpload($entry, $form, $result);
                        LDA_Logger::log("Google Drive upload handling completed");
                    } catch (Exception $e) {
                        LDA_Logger::error("Google Drive upload failed but continuing: " . $e->getMessage());
                    }
                    
                    LDA_Logger::log("Step 10: Handling email notifications");
                    // Now, handle the email notifications (both DOCX and PDF if available)
                    try {
                        $this->handleEmailNotifications($entry, $form, $result);
                        LDA_Logger::log("Email notifications handling completed");
                    } catch (Exception $e) {
                        LDA_Logger::error("Email notifications failed but continuing: " . $e->getMessage());
                    }
                
                LDA_Logger::log("=== FORM SUBMISSION COMPLETED SUCCESSFULLY ===");
                
            } else {
                LDA_Logger::error("Step 7: Document generation failed for entry {$entry['id']}: " . $result['error_message']);
                
                LDA_Logger::log("Step 8: Adding error note");
                // Add error note
                GFFormsModel::add_note($entry['id'], 0, 'Legal Document Automation', 'Document generation failed: ' . $result['error_message']);
                LDA_Logger::log("Error note added");
                
                LDA_Logger::log("=== FORM SUBMISSION COMPLETED WITH ERRORS ===");
            }
            
        } catch (Exception $e) {
            LDA_Logger::error("=== CRITICAL ERROR IN FORM SUBMISSION ===");
            LDA_Logger::error("Error message: " . $e->getMessage());
            LDA_Logger::error("Error file: " . $e->getFile());
            LDA_Logger::error("Error line: " . $e->getLine());
            LDA_Logger::error("Error trace: " . $e->getTraceAsString());
            LDA_Logger::error("=== END CRITICAL ERROR ===");
        }
    }

    /**
     * Handle email notifications after document generation.
     *
     * @param array $entry The Gravity Forms entry object.
     * @param array $form The Gravity Forms form object.
     * @param array $result The result from document processing (may contain DOCX and PDF paths).
     */
    private function handleEmailNotifications($entry, $form, $result) {
        LDA_Logger::log("=== EMAIL NOTIFICATIONS STARTED ===");
        LDA_Logger::log("Entry ID: " . $entry['id']);
        LDA_Logger::log("Form ID: " . $form['id']);
        
        LDA_Logger::log("Step 1: Creating email handler");
        if (!class_exists('LDA_EmailHandler')) {
            LDA_Logger::error("LDA_EmailHandler class not found");
            return;
        }
        
        try {
        $email_handler = new LDA_EmailHandler($this->settings);
        } catch (Exception $e) {
            LDA_Logger::error("Failed to create email handler: " . $e->getMessage());
            return;
        }
        LDA_Logger::log("Email handler created successfully");
        
        LDA_Logger::log("Step 2: Getting merge data");
        $merge_data = $this->_get_merge_data($entry, $form);
        LDA_Logger::log("Merge data retrieved");

        // --- Send email to user ---
        $user_email = $this->_find_email_in_entry($entry, $form);
        LDA_Logger::log("User email found: " . ($user_email ? $user_email : 'NONE'));
        
        if ($user_email) {
            // Get email templates with proper defaults
            $subject_template = !empty($this->settings['email_subject']) ? $this->settings['email_subject'] : 'Your legal document is ready - {FormTitle}';
            $message_template = !empty($this->settings['email_message']) ? $this->settings['email_message'] : "Dear {UserFirstName},\n\nThank you for your submission. Your legal document \"{FormTitle}\" has been generated and is ready for your review.\n\nPlease find the completed document attached to this email.\n\n{gdrive_docx_link}\n\nBest regards,\n{SiteName}";
            
            LDA_Logger::log("Using email subject: " . $subject_template);
            LDA_Logger::log("Using email message: " . substr($message_template, 0, 100) . "...");

            // Get Google Drive links if available
            $gdrive_links = $this->_get_google_drive_links($entry);
            $merge_data = array_merge($merge_data, $gdrive_links);

            LDA_Logger::log("Before merge tag replacement - Subject: " . $subject_template);
            LDA_Logger::log("Before merge tag replacement - Message: " . substr($message_template, 0, 200) . "...");
            // Log merge data summary instead of full array to avoid truncation
            $merge_summary = array();
            foreach ($merge_data as $key => $value) {
                if (strlen($value) > 50) {
                    $merge_summary[$key] = substr($value, 0, 50) . '...';
                } else {
                    $merge_summary[$key] = $value;
                }
            }
            LDA_Logger::log("Merge data for email (" . count($merge_data) . " items): " . json_encode($merge_summary, JSON_PRETTY_PRINT));
            
            $subject = $this->_replace_merge_tags($subject_template, $merge_data);
            $message = $this->_replace_merge_tags($message_template, $merge_data);
            
            LDA_Logger::log("After merge tag replacement - Subject: " . $subject);
            LDA_Logger::log("After merge tag replacement - Message: " . substr($message, 0, 200) . "...");

            // Prepare attachments (DOCX and PDF if available)
            $attachments = array();
            if (isset($result['output_path'])) {
                $attachments[] = $result['output_path']; // DOCX file
                LDA_Logger::log("Added DOCX attachment: " . $result['output_path']);
            }
            if (isset($result['pdf_path']) && $result['pdf_path']) {
                $attachments[] = $result['pdf_path']; // PDF file
                LDA_Logger::log("Added PDF attachment: " . $result['pdf_path']);
            }
            
            LDA_Logger::log("Total attachments prepared: " . count($attachments));

            // Send email with all attachments
            if (count($attachments) > 1) {
                LDA_Logger::log("Sending email with multiple attachments");
                $email_result = $email_handler->send_document_email_with_attachments($user_email, $subject, nl2br($message), $attachments);
            } elseif (count($attachments) == 1) {
                LDA_Logger::log("Sending email with single attachment");
                $email_result = $email_handler->send_document_email($user_email, $subject, nl2br($message), $attachments[0]);
            } else {
                LDA_Logger::error("No attachments available for email");
                $email_result = array('success' => false, 'error_message' => 'No attachments available');
            }

            if ($email_result['success']) {
                GFFormsModel::add_note($entry['id'], 0, 'Legal Document Automation', "Document successfully emailed to {$user_email}.");
            } else {
                GFFormsModel::add_note($entry['id'], 0, 'Legal Document Automation', "Failed to email document to {$user_email}. Error: " . $email_result['error_message']);
            }
        } else {
            $message = "Could not find a user email field in Form ID {$form['id']} (Entry ID {$entry['id']}) to send the document to.";
            LDA_Logger::warning($message);
            GFFormsModel::add_note($entry['id'], 0, 'Legal Document Automation', $message);
        }

        // --- Send notification to admin ---
        $admin_subject = "New Document Generated for " . $form['title'];
        $admin_message = "A new document has been generated from the form '{$form['title']}' (Entry #{$entry['id']}).\n\n";
        if ($user_email) {
            $admin_message .= "The document was sent to the user at {$user_email}.\n";
        }
        
        // Add file paths to admin message
        if (isset($result['output_path'])) {
            $admin_message .= "The generated DOCX file is stored at: " . $result['output_path'] . "\n";
        }
        if (isset($result['pdf_path']) && $result['pdf_path']) {
            $admin_message .= "The generated PDF file is stored at: " . $result['pdf_path'] . "\n";
        }

        $email_handler->send_admin_notification($admin_subject, nl2br($admin_message));
    }

    /**
     * Handle Google Drive upload after document generation.
     *
     * @param array $entry The Gravity Forms entry object.
     * @param array $form The Gravity Forms form object.
     * @param array $result The result from document processing (may contain DOCX and PDF paths).
     */
    private function handleGoogleDriveUpload($entry, $form, $result) {
        LDA_Logger::log("=== GOOGLE DRIVE UPLOAD STARTED ===");
        LDA_Logger::log("Entry ID: " . $entry['id']);
        LDA_Logger::log("Form ID: " . $form['id']);
        
        // Google Drive integration is now re-enabled with better error handling
        LDA_Logger::log("Google Drive integration re-enabled with enhanced error handling");
        
        try {
            LDA_Logger::log("Step 1: Finding user email");
            $gdrive_links = array();
            $user_email = $this->_find_email_in_entry($entry, $form);
            LDA_Logger::log("User email found: " . ($user_email ?: 'NOT FOUND'));
            
            $folder_identifier = $user_email ? sanitize_file_name($user_email) : 'entry_' . $entry['id'];
            LDA_Logger::log("Folder identifier: " . $folder_identifier);
            
            LDA_Logger::log("Step 2: Checking Google Drive settings");
            $gdrive = null;
            $method = 'none';
            
            if (isset($this->settings['google_drive_enabled']) && $this->settings['google_drive_enabled']) {
                LDA_Logger::log("Google Drive is enabled in settings");
                
                // Check the preferred method from settings
                $preferred_method = isset($this->settings['google_drive_method']) ? $this->settings['google_drive_method'] : 'native_api';
                LDA_Logger::log("Preferred Google Drive method: " . $preferred_method);
                
                // Check preferred method and try accordingly
                if ($preferred_method === 'use_your_drive') {
                    LDA_Logger::log("Step 3: Trying Use-your-Drive plugin integration");
                    if (is_plugin_active('use-your-drive/use-your-drive.php')) {
                        if (!class_exists('LDA_GoogleDrive')) {
                            LDA_Logger::error("LDA_GoogleDrive class not found");
                        } else {
                            try {
                                $gdrive = new LDA_GoogleDrive($this->settings);
                            $api_status = $gdrive->testConnection();
                            LDA_Logger::log("Use-your-Drive status: " . json_encode($api_status, JSON_PRETTY_PRINT));
                            
                            if ($api_status['success']) {
                                $method = 'use_your_drive';
                                LDA_Logger::log("Using Use-your-Drive plugin integration");
                        } else {
                                LDA_Logger::log("Use-your-Drive not available: " . $api_status['error']);
                        }
                    } catch (Exception $e) {
                                LDA_Logger::error("Use-your-Drive integration failed: " . $e->getMessage());
                            }
                        }
                    } else {
                        LDA_Logger::log("Use-your-Drive plugin is not active");
                    }
                } else {
                    // Try real Google Drive integration first
                    LDA_Logger::log("Step 3: Trying real Google Drive integration");
                        if (!class_exists('LDA_RealGoogleDrive')) {
                            LDA_Logger::error("LDA_RealGoogleDrive class not found");
                        } else {
                            try {
                                $gdrive = new LDA_RealGoogleDrive($this->settings);
                        $api_status = $gdrive->testConnection();
                        LDA_Logger::log("Real Google Drive status: " . json_encode($api_status, JSON_PRETTY_PRINT));
                        
                        if ($api_status['success']) {
                            $method = 'real_google_drive';
                            LDA_Logger::log("Using real Google Drive integration");
                        } else {
                            LDA_Logger::log("Real Google Drive not available: " . $api_status['error']);
                        }
                    } catch (Exception $e) {
                        LDA_Logger::error("Real Google Drive integration failed: " . $e->getMessage());
                    }
                        }
                }
                
                // Fallback to simple Google Drive if no other method worked
                if (!$gdrive) {
                    LDA_Logger::log("Step 4: Falling back to simple Google Drive integration");
                    if (!class_exists('LDA_SimpleGoogleDrive')) {
                        LDA_Logger::error("LDA_SimpleGoogleDrive class not found");
                    } else {
                        try {
                            $gdrive = new LDA_SimpleGoogleDrive($this->settings);
                    $api_status = $gdrive->testConnection();
                    LDA_Logger::log("Simple Google Drive status: " . json_encode($api_status, JSON_PRETTY_PRINT));
                    
                    if ($api_status['success']) {
                        $method = 'simple_google_drive';
                        LDA_Logger::log("Using simple Google Drive integration as fallback");
                    }
                        } catch (Exception $e) {
                            LDA_Logger::error("Simple Google Drive integration failed: " . $e->getMessage());
                        }
                    }
                }
                
                // Try Use-your-Drive plugin if other methods failed or is preferred
                if (!$gdrive && ($preferred_method === 'use_your_drive' || $preferred_method === 'auto')) {
                    LDA_Logger::log("Step 5: Trying Use-your-Drive plugin");
                    if (is_plugin_active('use-your-drive/use-your-drive.php')) {
                        LDA_Logger::log("Use-your-Drive plugin is active");
                        
                        if (!class_exists('LDA_GoogleDriveDirect')) {
                            LDA_Logger::error("LDA_GoogleDriveDirect class not found");
                        } else {
                        try {
                            LDA_Logger::log("Step 6: Creating LDA_GoogleDriveDirect");
                            $gdrive_direct = new LDA_GoogleDriveDirect($this->settings);
                            LDA_Logger::log("LDA_GoogleDriveDirect created successfully");
                            
                            LDA_Logger::log("Step 7: Checking direct integration availability");
                            $direct_status = $gdrive_direct->isAvailable();
                            LDA_Logger::log("Direct integration status: " . json_encode($direct_status, JSON_PRETTY_PRINT));
                            
                            if ($direct_status['available']) {
                                $gdrive = $gdrive_direct;
                                $method = 'use_your_drive_direct';
                                LDA_Logger::log("Using direct Use-your-Drive integration for Google Drive");
                            } else {
                                LDA_Logger::log("Direct integration not available, trying V2 fallback");
                                // Fall back to the V2 integration
                                if (class_exists('LDA_GoogleDrive_V2')) {
                                    try {
                                $gdrive = new LDA_GoogleDrive_V2($this->settings);
                                $method = 'use_your_drive';
                                LDA_Logger::log("Using Use-your-Drive plugin for Google Drive integration (fallback)");
                                    } catch (Exception $e) {
                                        LDA_Logger::error("V2 integration failed: " . $e->getMessage());
                                    }
                                } else {
                                    LDA_Logger::error("LDA_GoogleDrive_V2 class not found");
                                }
                            }
                        } catch (Exception $e) {
                            LDA_Logger::error("=== CRITICAL ERROR IN GOOGLE DRIVE DIRECT INTEGRATION ===");
                            LDA_Logger::error("Error message: " . $e->getMessage());
                            LDA_Logger::error("Error file: " . $e->getFile());
                            LDA_Logger::error("Error line: " . $e->getLine());
                            LDA_Logger::error("Error trace: " . $e->getTraceAsString());
                            LDA_Logger::error("=== END CRITICAL ERROR ===");
                        }
                        }
                    } else {
                        LDA_Logger::log("Use-your-Drive plugin is not active");
                    }
                }
                
                // Use simple file-based storage as final fallback
                if (!$gdrive) {
                    LDA_Logger::log("Step 8: Using simple file-based storage as fallback");
                    if (!class_exists('LDA_GoogleDriveSimple')) {
                        LDA_Logger::error("LDA_GoogleDriveSimple class not found");
                    } else {
                        try {
                    $gdrive = new LDA_GoogleDriveSimple($this->settings);
                    $method = 'simple_file_based';
                    LDA_Logger::log("Using simple file-based storage for Google Drive integration");
                        } catch (Exception $e) {
                            LDA_Logger::error("Simple file-based storage failed: " . $e->getMessage());
                        }
                    }
                }
            } else {
                LDA_Logger::log("Google Drive integration disabled in settings");
                return;
            }
            
            // Only proceed if we have a valid Google Drive integration
            if ($gdrive && $method !== 'none') {
                LDA_Logger::log("Step 9: Creating user folder");
                try {
                    $folder_result = $gdrive->createUserFolder($folder_identifier);
                    LDA_Logger::log("Folder creation result: " . json_encode($folder_result, JSON_PRETTY_PRINT));
                    
                    if ($folder_result['success']) {
                        LDA_Logger::log("Created/found user folder for: {$folder_identifier} using method: {$method}");
                    } else {
                        LDA_Logger::error("Failed to create user folder: " . $folder_result['error']);
                        LDA_Logger::log("Skipping Google Drive upload due to folder creation failure");
                        return;
                    }
                } catch (Exception $e) {
                    LDA_Logger::error("=== CRITICAL ERROR IN FOLDER CREATION ===");
                    LDA_Logger::error("Error message: " . $e->getMessage());
                    LDA_Logger::error("Error file: " . $e->getFile());
                    LDA_Logger::error("Error line: " . $e->getLine());
                    LDA_Logger::error("Error trace: " . $e->getTraceAsString());
                    LDA_Logger::error("=== END CRITICAL ERROR ===");
                    LDA_Logger::log("Skipping Google Drive upload due to folder creation error");
                    return;
                }
            } else {
                LDA_Logger::log("No valid Google Drive integration available, skipping upload");
                return;
            }
            
                // Upload DOCX file
                LDA_Logger::log("Step 10: Uploading DOCX file");
            if (isset($result['output_path'])) {
                try {
                    LDA_Logger::log("Attempting to upload DOCX file: " . $result['output_path']);
                    $docx_upload = $gdrive->uploadFile($result['output_path'], $folder_identifier);
                    LDA_Logger::log("DOCX upload result: " . json_encode($docx_upload, JSON_PRETTY_PRINT));
                    
                    if ($docx_upload['success']) {
                        LDA_Logger::log("DOCX file uploaded using {$method} for entry {$entry['id']}");
                        GFFormsModel::add_note($entry['id'], 0, 'Legal Document Automation', 'DOCX uploaded to ' . ucfirst($method) . ': ' . $docx_upload['web_view_link']);
                        $gdrive_links['gdrive_docx_link'] = $docx_upload['web_view_link'];
                        $gdrive_links['gdrive_docx_download'] = $docx_upload['download_link'] ?? $docx_upload['web_view_link'];
                    } else {
                        LDA_Logger::error("Failed to upload DOCX using {$method} for entry {$entry['id']}: " . $docx_upload['error']);
                    }
                } catch (Exception $e) {
                    LDA_Logger::error("=== CRITICAL ERROR IN DOCX UPLOAD ===");
                    LDA_Logger::error("Error message: " . $e->getMessage());
                    LDA_Logger::error("Error file: " . $e->getFile());
                    LDA_Logger::error("Error line: " . $e->getLine());
                    LDA_Logger::error("Error trace: " . $e->getTraceAsString());
                    LDA_Logger::error("=== END CRITICAL ERROR ===");
                }
            } else {
                LDA_Logger::log("No DOCX output path found in result");
            }
            
            // Upload PDF file if available
            LDA_Logger::log("Step 11: Uploading PDF file");
            if (isset($result['pdf_path']) && $result['pdf_path']) {
                $pdf_upload = $gdrive->uploadFile($result['pdf_path'], $folder_identifier);
                if ($pdf_upload['success']) {
                    LDA_Logger::log("PDF file uploaded using {$method} for entry {$entry['id']}");
                    GFFormsModel::add_note($entry['id'], 0, 'Legal Document Automation', 'PDF uploaded to ' . ucfirst($method) . ': ' . $pdf_upload['web_view_link']);
                    $gdrive_links['gdrive_pdf_link'] = $pdf_upload['web_view_link'];
                    $gdrive_links['gdrive_pdf_download'] = $pdf_upload['download_link'] ?? $pdf_upload['web_view_link'];
                } else {
                    LDA_Logger::error("Failed to upload PDF using {$method} for entry {$entry['id']}: " . $pdf_upload['error']);
                }
            }
            
            // Store Google Drive links in entry meta for email shortcodes
            LDA_Logger::log("Step 12: Storing Google Drive links");
            if (!empty($gdrive_links)) {
                foreach ($gdrive_links as $key => $link) {
                    gform_update_meta($entry['id'], $key, $link);
                }
                LDA_Logger::log("Stored " . count($gdrive_links) . " Google Drive links for email shortcodes");
            } else {
                LDA_Logger::log("No Google Drive links to store");
            }
            
            LDA_Logger::log("=== GOOGLE DRIVE UPLOAD COMPLETED ===");
            
        } catch (Exception $e) {
            LDA_Logger::error("=== CRITICAL ERROR IN GOOGLE DRIVE UPLOAD ===");
            LDA_Logger::error("Error message: " . $e->getMessage());
            LDA_Logger::error("Error file: " . $e->getFile());
            LDA_Logger::error("Error line: " . $e->getLine());
            LDA_Logger::error("Error trace: " . $e->getTraceAsString());
            LDA_Logger::error("=== END CRITICAL ERROR ===");
        }
    }

    /**
     * Get Google Drive links from entry meta for email shortcodes.
     *
     * @param array $entry The Gravity Forms entry object.
     * @return array Google Drive links array.
     */
    private function _get_google_drive_links($entry) {
        $gdrive_links = gform_get_meta($entry['id'], 'lda_gdrive_links');
        
        if (empty($gdrive_links)) {
            return array(
                'gdrive_docx_link' => '',
                'gdrive_docx_download' => '',
                'gdrive_pdf_link' => '',
                'gdrive_pdf_download' => '',
                'gdrive_folder_link' => ''
            );
        }
        
        // Add folder link if we have any files
        if (!empty($gdrive_links['gdrive_docx_link']) || !empty($gdrive_links['gdrive_pdf_link'])) {
            // Extract folder ID from any existing link
            $folder_link = '';
            if (!empty($gdrive_links['gdrive_docx_link'])) {
                $folder_link = $gdrive_links['gdrive_docx_link'];
            } elseif (!empty($gdrive_links['gdrive_pdf_link'])) {
                $folder_link = $gdrive_links['gdrive_pdf_link'];
            }
            
            // Convert file link to folder link (remove file ID, keep folder path)
            if ($folder_link) {
                $folder_link = preg_replace('/\/file\/d\/[^\/]+/', '/folders', $folder_link);
                $gdrive_links['gdrive_folder_link'] = $folder_link;
            }
        }
        
        return $gdrive_links;
    }

    /**
     * Gathers merge data from a form entry.
     *
     * @param array $entry The Gravity Forms entry object.
     * @param array $form The Gravity Forms form object.
     * @return array
     */
    private function _get_merge_data($entry, $form) {
        $merge_data = array();
        foreach ($form['fields'] as $field) {
            $merge_tag = !empty($field->adminLabel) ? $field->adminLabel : $field->label;
            if (!empty($merge_tag)) {
                $value = rgar($entry, (string) $field->id);
                if (is_array($value)) {
                    $value = implode(' ', array_filter($value));
                }
                $merge_data[$merge_tag] = $value;
            }
        }
        $merge_data['FormTitle'] = $form['title'];
        $merge_data['SiteName'] = get_bloginfo('name');

        // Extract first and last name for convenience
        $merge_data['UserFirstName'] = '';
        $merge_data['UserLastName'] = '';
        foreach ($form['fields'] as $field) {
            if ($field->type == 'name') {
                $merge_data['UserFirstName'] = rgar($entry, (string) $field->id . '.3');
                $merge_data['UserLastName'] = rgar($entry, (string) $field->id . '.6');
                break;
            }
        }

        return $merge_data;
    }

    /**
     * Finds the first email field in a form entry and returns its value.
     *
     * @param array $entry The Gravity Forms entry object.
     * @param array $form The Gravity Forms form object.
     * @return string|null
     */
    private function _find_email_in_entry($entry, $form) {
        // First, look for email field types
        foreach ($form['fields'] as $field) {
            if ($field->type == 'email') {
                $email = rgar($entry, (string) $field->id);
                if (is_email($email)) {
                    LDA_Logger::debug("Found email in email field {$field->id}: {$email}");
                    return $email;
                }
            }
        }
        
        // If no email field found, look for text fields that might contain emails
        foreach ($form['fields'] as $field) {
            if ($field->type == 'text' || $field->type == 'name') {
                $value = rgar($entry, (string) $field->id);
                if (is_email($value)) {
                    LDA_Logger::debug("Found email in text field {$field->id}: {$value}");
                    return $value;
                }
                
                // For name fields, check if it's an array and look for email-like values
                if (is_array($value)) {
                    foreach ($value as $sub_value) {
                        if (is_email($sub_value)) {
                            LDA_Logger::debug("Found email in name field array {$field->id}: {$sub_value}");
                            return $sub_value;
                        }
                    }
                }
            }
        }
        
        // Last resort: look for any field value that looks like an email
        foreach ($entry as $key => $value) {
            if (is_string($value) && is_email($value)) {
                LDA_Logger::debug("Found email in entry field {$key}: {$value}");
                return $value;
            }
        }
        
        LDA_Logger::warning("No email found in form {$form['id']} entry {$entry['id']}");
        return null;
    }

    /**
     * Replaces merge tags in a string with their values.
     *
     * @param string $template The string containing merge tags (e.g., {TagName}).
     * @param array $merge_data An associative array of merge data.
     * @return string
     */
    private function _replace_merge_tags($template, $merge_data) {
        foreach ($merge_data as $key => $value) {
            $template = str_replace('{' . $key . '}', $value, $template);
        }
        return $template;
    }
    
    /**
     * Add settings link to plugins page
     */
    public function addSettingsLink($links) {
        $settings_link = '<a href="' . admin_url('options-general.php?page=lda-settings') . '">' . __('Settings', 'legal-doc-automation') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    
    /**
     * Plugin activation
     */
    public function activate() {
        try {
        // Check requirements again during activation
        if (!$this->checkSystemRequirements()) {
            deactivate_plugins(LDA_PLUGIN_BASENAME);
            wp_die(implode('<br>', $this->error_messages));
        }
        
        // Create necessary directories
        $this->createDirectories();
        
        // Set default options
        $this->setDefaultOptions();
        
        // Schedule cleanup tasks
        if (!wp_next_scheduled('lda_cleanup_old_files')) {
            wp_schedule_event(time(), 'daily', 'lda_cleanup_old_files');
        }
        
        if (!wp_next_scheduled('lda_cleanup_old_logs')) {
            wp_schedule_event(time(), 'weekly', 'lda_cleanup_old_logs');
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Log activation
        if (class_exists('LDA_Logger')) {
            LDA_Logger::log('Legal Document Automation plugin activated');
        }
        
        } catch (Exception $e) {
            error_log('A Legal Documents Plugin - Activation failed: ' . $e->getMessage());
            deactivate_plugins(LDA_PLUGIN_BASENAME);
            wp_die('Plugin activation failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        try {
        // Clear scheduled tasks
        wp_clear_scheduled_hook('lda_cleanup_old_files');
        wp_clear_scheduled_hook('lda_cleanup_old_logs');
        
        // Clear logs on deactivation (for clean reinstall)
        if (class_exists('LDA_Logger')) {
            LDA_Logger::log('Legal Document Automation plugin deactivated');
            LDA_Logger::clearLogs();
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
            
        } catch (Exception $e) {
            error_log('A Legal Documents Plugin - Deactivation error: ' . $e->getMessage());
        }
    }
    
    /**
     * Create necessary directories
     */
    private function createDirectories() {
        $upload_dir = wp_upload_dir();
        $directories = array(
            $upload_dir['basedir'] . '/lda-templates/',
            $upload_dir['basedir'] . '/lda-output/',
            $upload_dir['basedir'] . '/lda-logs/',
            $upload_dir['basedir'] . '/lda-gdrive-fallback/'
        );
        
        foreach ($directories as $dir) {
            if (!file_exists($dir)) {
                wp_mkdir_p($dir);
                
                // Add .htaccess for security
                $htaccess_content = "Order deny,allow\nDeny from all\n";
                file_put_contents($dir . '.htaccess', $htaccess_content);
            }
        }
    }
    
    /**
     * Set default plugin options
     */
    private function setDefaultOptions() {
        $upload_dir = wp_upload_dir();
        $default_options = array(
            'template_folder' => $upload_dir['basedir'] . '/lda-templates/',
            'enabled_forms' => array(),
            'enable_pdf_output' => false,
            'email_subject' => 'Your legal document is ready - {FormTitle}',
            'email_message' => 'Dear {UserFirstName},\n\nYour legal document "{FormTitle}" has been generated and is ready for your review.\n\nPlease find the completed document attached to this email.\n\nBest regards,\n{SiteName}',
            'from_email' => get_option('admin_email'),
            'from_name' => get_bloginfo('name'),
            'google_drive_enabled' => true,
            'gdrive_root_folder' => 'Legal Documents',
            'gdrive_folder_naming' => 'business_name',
            'gdrive_filename_format' => 'form_business_date',
            'debug_mode' => false,
            'max_processing_time' => 300,
            'max_memory_usage' => '512M'
        );
        
        // Only set defaults if options don't exist
        if (!get_option('lda_settings')) {
            add_option('lda_settings', $default_options);
        }
    }
    
    /**
     * Cleanup old files
     */
    public function cleanupOldFiles() {
        $upload_dir = wp_upload_dir();
        $output_dir = $upload_dir['basedir'] . '/lda-output/';
        
        if (!is_dir($output_dir)) {
            return;
        }
        
        $cutoff_time = time() - (24 * 60 * 60); // 24 hours ago
        $deleted_count = 0;
        
        $files = glob($output_dir . '*.docx');
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff_time) {
                unlink($file);
                $deleted_count++;
            }
        }
        
        if ($deleted_count > 0) {
            LDA_Logger::log("Cleaned up {$deleted_count} old output files");
        }
    }
    
    /**
     * Cleanup old logs
     */
    public function cleanupOldLogs() {
        if (class_exists('LDA_Logger')) {
            LDA_Logger::cleanOldLogs(30); // Keep 30 days of logs
        }
    }
    
    /**
     * Emergency shutdown handler
     */
    public function emergencyShutdown() {
        $error = error_get_last();
        if ($error && in_array($error['type'], array(E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR))) {
            if (strpos($error['file'], LDA_PLUGIN_DIR) !== false) {
                // Log critical error
                error_log('LDA Critical Error: ' . $error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line']);
                
                // Try to log with our logger if available
                if (class_exists('LDA_Logger')) {
                    LDA_Logger::error('Critical error: ' . $error['message'], array(
                        'file' => $error['file'],
                        'line' => $error['line']
                    ));
                }
            }
        }
    }
    
    /**
     * Plugin uninstall - static method called when plugin is deleted
     */
    public static function uninstall() {
        try {
            // Clear logs on uninstall
            if (class_exists('LDA_Logger')) {
                LDA_Logger::clearLogs();
            }
            
            // Clear scheduled tasks
            wp_clear_scheduled_hook('lda_cleanup_old_files');
            wp_clear_scheduled_hook('lda_cleanup_old_logs');
            
            // Note: We don't delete plugin options or uploaded files
            // as users might want to keep their settings and templates
            
        } catch (Exception $e) {
            error_log('A Legal Documents Plugin - Uninstall error: ' . $e->getMessage());
        }
    }
}

// Initialize the plugin
LegalDocumentAutomation::getInstance();