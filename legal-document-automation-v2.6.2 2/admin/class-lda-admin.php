<?php
/**
 * Admin Interface for Legal Document Automation
 * 
 * @package LegalDocumentAutomation
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class LDA_Admin {
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'addAdminMenu'));
        add_action('admin_init', array($this, 'initSettings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueueAdminScripts'));
        
        // Form settings integration
        add_filter('gform_form_settings_fields', array($this, 'addFormSettings'), 10, 2);
        add_filter('gform_pre_form_settings_save', array($this, 'saveFormSettings'));

        // AJAX handlers
        add_action('wp_ajax_lda_clear_logs', array($this, 'handleAjaxClearLogs'));
        add_action('wp_ajax_lda_validate_template', array($this, 'handleAjaxValidateTemplate'));
        add_action('wp_ajax_lda_get_logs', array($this, 'handleAjaxGetLogs'));
        add_action('wp_ajax_lda_delete_template', array($this, 'handleAjaxDeleteTemplate'));
        add_action('wp_ajax_lda_test_template', array($this, 'handleAjaxTestTemplate'));
        add_action('wp_ajax_lda_save_test_email', array($this, 'handleAjaxSaveTestEmail'));
        add_action('wp_ajax_lda_test_processing', array($this, 'handleAjaxTestProcessing'));
        add_action('wp_ajax_lda_test_modifier', array($this, 'handleAjaxTestModifier'));
        add_action('wp_ajax_lda_test_email', array($this, 'handleAjaxTestEmail'));
        add_action('wp_ajax_lda_test_gdrive', array($this, 'handleAjaxTestGoogleDrive'));
        add_action('wp_ajax_lda_test_pdf', array($this, 'handleAjaxTestPdf'));
        add_action('wp_ajax_lda_get_templates', array($this, 'handleAjaxGetTemplates'));
        add_action('wp_ajax_lda_debug_template', array($this, 'handleAjaxDebugTemplate'));
        add_action('wp_ajax_lda_upload_gdrive_credentials', array($this, 'handleAjaxUploadGDriveCredentials'));
        
        // Field Mapping AJAX handlers
        add_action('wp_ajax_lda_assign_template', array($this, 'handleAjaxAssignTemplate'));
        add_action('wp_ajax_lda_auto_populate', array($this, 'handleAjaxAutoPopulate'));
        add_action('wp_ajax_lda_save_field_mapping', array($this, 'handleAjaxSaveFieldMapping'));
        add_action('wp_ajax_lda_get_form_fields', array($this, 'handleAjaxGetFormFields'));

        // Custom admin styles
        add_action('admin_head', array($this, 'adminIconStyles'));
    }

    /**
     * Add custom CSS to the admin head to style the menu icon.
     */
    public function adminIconStyles() {
        ?>
        <style>
            #toplevel_page_lda-settings .wp-menu-image.dashicons-before::before {
                color: #FF1493; /* DeepPink */
            }
            /* Correctly target the active state */
            #toplevel_page_lda-settings.current .wp-menu-image.dashicons-before::before,
            #toplevel_page_lda-settings.wp-has-current-submenu .wp-menu-image.dashicons-before::before,
            #toplevel_page_lda-settings:hover .wp-menu-image.dashicons-before::before {
                color: #fed919; /* Yellow for active/hover icon */
            }
            #toplevel_page_lda-settings.current a.menu-top,
            #toplevel_page_lda-settings.wp-has-current-submenu a.menu-top {
                background: #FF1493; /* Pink background for active item */
            }
            #toplevel_page_lda-settings.current a.menu-top .wp-menu-name,
            #toplevel_page_lda-settings.wp-has-current-submenu a.menu-top .wp-menu-name {
                color: white; /* White text for active item */
            }
        </style>
        <?php
    }

    /**
     * Handle AJAX request to clear logs
     */
    public function handleAjaxClearLogs() {
        check_ajax_referer('lda_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to clear logs.', 'legal-doc-automation'));
        }

        if (LDA_Logger::clearLogs()) {
            wp_send_json_success(__('Logs cleared successfully.', 'legal-doc-automation'));
        } else {
            wp_send_json_error(__('Failed to clear logs. The log file might not be writable.', 'legal-doc-automation'));
        }
    }

    /**
     * Handle AJAX template validation
     */
    public function handleAjaxValidateTemplate() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'lda_admin_nonce')) {
            wp_die(__('Security check failed', 'legal-doc-automation'));
        }

        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'legal-doc-automation'));
        }

        try {
            // Get the raw template filename from POST
            $raw_template_file = $_POST['template_file'];
            LDA_Logger::log("Raw POST template_file: " . $raw_template_file);
            LDA_Logger::log("Raw POST template_file length: " . strlen($raw_template_file));
            LDA_Logger::log("Raw POST template_file bytes: " . bin2hex($raw_template_file));
            
            // Clean the filename - remove any path components and decode if needed
            $template_file = basename($raw_template_file);
            // Remove any quotes that might have been added
            $template_file = trim($template_file, '"\'');
            // Remove any escaped backslashes
            $template_file = str_replace('\\', '', $template_file);
            
            LDA_Logger::log("Cleaned template_file: " . $template_file);
            LDA_Logger::log("Starting validation for template: " . $template_file);

            $template_folder = wp_upload_dir()['basedir'] . '/lda-templates/';
            $template_path = $template_folder . $template_file;
            LDA_Logger::log("Full template path: " . $template_path);
            LDA_Logger::log("File exists check: " . (file_exists($template_path) ? 'YES' : 'NO'));

            $settings = get_option('lda_settings', array());
            $merge_engine = new LDA_MergeEngine($settings);
            $result = $merge_engine->validateTemplate($template_path);

            if ($result['success']) {
                LDA_Logger::log("Template validation successful for: " . $template_file);
            } else {
                LDA_Logger::warning("Template validation failed for: " . $template_file . ". Reason: " . $result['message']);
            }

            wp_send_json_success($result);

        } catch (Exception $e) {
            LDA_Logger::error("Exception during template validation: " . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    /**
     * Handle AJAX request to get logs
     */
    public function handleAjaxGetLogs() {
        check_ajax_referer('lda_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to view logs.', 'legal-doc-automation'));
        }

        $logs_html = $this->getRecentLogsHTML();

        wp_send_json_success($logs_html);
    }

    /**
     * Handle AJAX request to delete a template file.
     */
    public function handleAjaxDeleteTemplate() {
        check_ajax_referer('lda_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to delete templates.', 'legal-doc-automation'));
        }

        if (empty($_POST['template'])) {
            wp_send_json_error(__('No template filename provided.', 'legal-doc-automation'));
        }

        $filename = $_POST['template']; // Don't sanitize - use original filename
        
        // Security check: ensure filename doesn't contain directory traversal
        if (strpos($filename, '..') !== false || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            wp_send_json_error(__('Invalid filename provided.', 'legal-doc-automation'));
        }
        
        $template_folder = wp_upload_dir()['basedir'] . '/lda-templates/';
        
        // Ensure the template folder exists
        if (!is_dir($template_folder)) {
            wp_mkdir_p($template_folder);
        }
        
        $filepath = $template_folder . $filename;
        
        // Security check: ensure the file is within the templates directory
        $real_template_folder = realpath($template_folder);
        $real_filepath = realpath($filepath);
        
        if (!$real_template_folder) {
            LDA_Logger::error("Template folder does not exist: " . $template_folder);
            wp_send_json_error(__('Template folder does not exist.', 'legal-doc-automation'));
        }
        
        if (!$real_filepath || strpos($real_filepath, $real_template_folder) !== 0) {
            LDA_Logger::error("Invalid file path: " . $filepath . " (template folder: " . $real_template_folder . ")");
            wp_send_json_error(__('Invalid file path or template does not exist.', 'legal-doc-automation'));
        }
        
        if (file_exists($real_filepath)) {
            if (unlink($real_filepath)) {
                LDA_Logger::log("Template deleted: " . $filename);
                wp_send_json_success(__('Template deleted successfully.', 'legal-doc-automation'));
            } else {
                LDA_Logger::error("Failed to delete template: " . $filename);
                wp_send_json_error(__('Could not delete the template. Please check file permissions.', 'legal-doc-automation'));
            }
        } else {
            LDA_Logger::error("Template file does not exist: " . $real_filepath);
            wp_send_json_error(__('Template not found.', 'legal-doc-automation'));
        }
    }
    
    /**
     * Enqueue admin scripts and styles
     */
    public function enqueueAdminScripts($hook_suffix) {
        // The hook_suffix check was too restrictive, preventing JS from loading.
        // A better long-term solution might be to find the exact hook, but for now,
        // we will enqueue the scripts and styles on all admin pages to ensure functionality.
        
        wp_enqueue_script('lda-admin-js', LDA_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), LDA_VERSION, true);
        wp_localize_script('lda-admin-js', 'lda_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('lda_admin_nonce'),
            'strings' => array(
                'testing' => __('Testing...', 'legal-doc-automation'),
                'validating' => __('Validating...', 'legal-doc-automation'),
                'success' => __('Success!', 'legal-doc-automation'),
                'error' => __('Error:', 'legal-doc-automation'),
                'confirm_delete' => __('Are you sure you want to delete this template?', 'legal-doc-automation')
            )
        ));
        
        wp_enqueue_style('lda-google-fonts', 'https://fonts.googleapis.com/css2?family=Raleway:wght@400;700&display=swap', false);
        wp_enqueue_style('lda-admin-css', LDA_PLUGIN_URL . 'assets/css/admin.css', array('lda-google-fonts'), LDA_VERSION);
    }
    
    /**
     * Add admin menu
     */
    public function addAdminMenu() {
        add_menu_page(
            __('A Legal Documents', 'legal-doc-automation'),
            __('Doc Automation', 'legal-doc-automation'),
            'manage_options',
            'lda-settings',
            array($this, 'settingsPage'),
            'dashicons-media-document'
        );
    }
    
    /**
     * Enhanced settings page with tabbed interface
     */
    public function settingsPage() {
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'templates';
        ?>
        <div class="wrap lda-admin-wrap">
            <h1><?php _e('A Legal Documents Settings', 'legal-doc-automation'); ?></h1>
            
            <nav class="nav-tab-wrapper">
                <a href="?page=lda-settings&tab=templates" class="nav-tab <?php echo $active_tab == 'templates' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Templates', 'legal-doc-automation'); ?>
                </a>
                <a href="?page=lda-settings&tab=mapping" class="nav-tab <?php echo $active_tab == 'mapping' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Field Mapping', 'legal-doc-automation'); ?>
                </a>
                <a href="?page=lda-settings&tab=gdrive" class="nav-tab <?php echo $active_tab == 'gdrive' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Google Drive', 'legal-doc-automation'); ?>
                    <?php 
                    $settings = get_option('lda_settings', array());
                    if (!isset($settings['google_drive_enabled']) || !$settings['google_drive_enabled']) {
                        echo '<span class="gdrive-warning-indicator" title="Google Drive is not enabled - Click to configure">⚠</span>';
                    }
                    ?>
                </a>
                <a href="?page=lda-settings&tab=email" class="nav-tab <?php echo $active_tab == 'email' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Email', 'legal-doc-automation'); ?>
                </a>
                <a href="?page=lda-settings&tab=documentation" class="nav-tab <?php echo $active_tab == 'documentation' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Documentation', 'legal-doc-automation'); ?>
                </a>
                <a href="?page=lda-settings&tab=status" class="nav-tab <?php echo $active_tab == 'status' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('System Status', 'legal-doc-automation'); ?>
                </a>
                <a href="?page=lda-settings&tab=logs" class="nav-tab <?php echo $active_tab == 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Activity Logs', 'legal-doc-automation'); ?>
                </a>
            </nav>

            <div class="tab-content">
                <?php
                switch ($active_tab) {
                    case 'templates':
                        $this->showTemplatesTab();
                        break;
                    case 'email':
                        $this->showEmailTab();
                        break;
                    case 'gdrive':
                        $this->showGoogleDriveTab();
                        break;
                    case 'mapping':
                        $this->showFieldMappingTab();
                        break;
                    case 'documentation':
                        $this->showDocumentationTab();
                        break;
                    case 'logs':
                        $this->showLogsTab();
                        break;
                    case 'status':
                        $this->showStatusTab();
                        break;
                    default:
                        $this->showGeneralTab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Show general settings tab
     */
    private function showGeneralTab() {
        ?>
        <form method="post" action="options.php" class="lda-settings-form">
            <?php
            settings_fields('lda_settings');
            do_settings_sections('lda_general');
            submit_button();
            ?>
        </form>
        <?php
    }
    
    /**
     * Show templates management tab
     */
    private function showTemplatesTab() {
        // This function doesn't need to get the template folder anymore,
        // as the functions it calls will get it directly.
        ?>
        <div class="lda-templates-section">
            <h2><?php _e('Template Management', 'legal-doc-automation'); ?></h2>

            <div class="lda-card lda-intro">
                <p><?php _e('This tab is for managing your .docx templates. Upload new templates, validate their syntax, and see a list of all available templates. The merge tags in these documents will be replaced with data from your Gravity Forms submissions to generate the final documents.', 'legal-doc-automation'); ?></p>
            </div>
            
            <!-- Template Upload -->
            <div class="lda-card">
                <h3><?php _e('Upload New Template', 'legal-doc-automation'); ?></h3>
                <form method="post" enctype="multipart/form-data" action="">
                    <?php wp_nonce_field('lda_upload_template', 'lda_upload_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Template File', 'legal-doc-automation'); ?></th>
                            <td>
                                <input type="file" name="template_file" accept=".docx" required>
                                <p class="description">
                                    <?php _e('Upload DOCX files with Webmerge-compatible syntax.', 'legal-doc-automation'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(__('Upload Template', 'legal-doc-automation')); ?>
                </form>
                <?php $this->handleTemplateUpload(); ?>
            </div>
            
            <!-- Template Actions Explanation -->
            <div class="lda-card">
                <h3><?php _e('Template Actions', 'legal-doc-automation'); ?></h3>
                <p><?php _e('For each uploaded template, you have the following actions:', 'legal-doc-automation'); ?></p>
                <ul>
                    <li><strong><?php _e('Validate:', 'legal-doc-automation'); ?></strong> <?php _e('Analyzes the template structure and merge tag syntax. Shows detailed report of merge tags, conditionals, and modifiers found.', 'legal-doc-automation'); ?></li>
                    <li><strong><?php _e('Test:', 'legal-doc-automation'); ?></strong> <?php _e('Generates a test document with sample data so you can preview how your template will look with real data.', 'legal-doc-automation'); ?></li>
                    <li><strong><?php _e('Delete:', 'legal-doc-automation'); ?></strong> <?php _e('Permanently removes the template file.', 'legal-doc-automation'); ?></li>
                </ul>
            </div>

            <!-- Test Email Configuration -->
            <div class="lda-card">
                <h3 class="test-configuration-title"><?php _e('Test Configuration', 'legal-doc-automation'); ?></h3>
                <p><?php _e('Configure test email address for comprehensive testing. When you click "Test" on a template, the system will:', 'legal-doc-automation'); ?></p>
                <ul>
                    <li><?php _e('Generate a test document using predefined sample data (no form mapping required)', 'legal-doc-automation'); ?></li>
                    <li><?php _e('Send the document as an email attachment (if email provided)', 'legal-doc-automation'); ?></li>
                    <li><?php _e('Upload the document to Google Drive (if enabled)', 'legal-doc-automation'); ?></li>
                    <li><?php _e('Provide a download link for immediate access', 'legal-doc-automation'); ?></li>
                </ul>
                <p><strong><?php _e('Note:', 'legal-doc-automation'); ?></strong> <?php _e('Testing uses sample data and works regardless of field mapping status. This allows you to test templates before setting up form integrations.', 'legal-doc-automation'); ?></p>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="test_email" class="test-email-label"><?php _e('Test Email Address', 'legal-doc-automation'); ?></label>
                        </th>
                        <td>
                            <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 5px;">
                                <input type="email" id="test_email" name="test_email" value="<?php echo esc_attr(get_option('lda_test_email', '')); ?>" class="regular-text" placeholder="admin@example.com" style="width: 200px;" />
                                <button type="button" id="save_test_email" class="button button-primary"><?php _e('Save test email address', 'legal-doc-automation'); ?></button>
                            </div>
                            <p class="description"><?php _e('Enter an email address to receive test documents. Leave empty to skip email testing.', 'legal-doc-automation'); ?></p>
                        </td>
                    </tr>
                </table>
            </div>

            <!-- Template List -->
            <div class="lda-card">
                <h3><?php _e('Existing Templates', 'legal-doc-automation'); ?></h3>
                <?php $this->displayTemplatesList(); ?>
            </div>
            
        </div>
        <?php
    }
    
    /**
     * Show email settings tab
     */
    private function showEmailTab() {
        ?>
        <form method="post" action="options.php" class="lda-settings-form">
            <?php
            settings_fields('lda_settings');
            do_settings_sections('lda_email');
            ?>
            
            <div class="lda-card">
                <h3><?php _e('Available Email Shortcodes', 'legal-doc-automation'); ?></h3>
                <p><?php _e('You can use these shortcodes in your email subject and message templates:', 'legal-doc-automation'); ?></p>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php _e('Shortcode', 'legal-doc-automation'); ?></th>
                            <th><?php _e('Description', 'legal-doc-automation'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>{FormTitle}</code></td>
                            <td><?php _e('The title of the form that was submitted', 'legal-doc-automation'); ?></td>
                        </tr>
                        <tr>
                            <td><code>{UserFirstName}</code></td>
                            <td><?php _e('First name of the logged-in user', 'legal-doc-automation'); ?></td>
                        </tr>
                        <tr>
                            <td><code>{UserLastName}</code></td>
                            <td><?php _e('Last name of the logged-in user', 'legal-doc-automation'); ?></td>
                        </tr>
                        <tr>
                            <td><code>{UserEmail}</code></td>
                            <td><?php _e('Email address of the logged-in user', 'legal-doc-automation'); ?></td>
                        </tr>
                        <tr>
                            <td><code>{SiteName}</code></td>
                            <td><?php _e('Name of your WordPress site', 'legal-doc-automation'); ?></td>
                        </tr>
                        <tr>
                            <td><code>{CurrentDate}</code></td>
                            <td><?php _e('Current date (YYYY-MM-DD format)', 'legal-doc-automation'); ?></td>
                        </tr>
                        <tr>
                            <td><code>{gdrive_docx_link}</code></td>
                            <td><?php _e('Google Drive link to view the DOCX document', 'legal-doc-automation'); ?></td>
                        </tr>
                        <tr>
                            <td><code>{gdrive_pdf_link}</code></td>
                            <td><?php _e('Google Drive link to view the PDF document', 'legal-doc-automation'); ?></td>
                        </tr>
                        <tr>
                            <td><code>{gdrive_folder_link}</code></td>
                            <td><?php _e('Google Drive link to the user\'s document folder', 'legal-doc-automation'); ?></td>
                        </tr>
                        <tr>
                            <td><code>{gdrive_docx_download}</code></td>
                            <td><?php _e('Direct download link for the DOCX document', 'legal-doc-automation'); ?></td>
                        </tr>
                        <tr>
                            <td><code>{gdrive_pdf_download}</code></td>
                            <td><?php _e('Direct download link for the PDF document', 'legal-doc-automation'); ?></td>
                        </tr>
                    </tbody>
                </table>
                <p><strong><?php _e('Note:', 'legal-doc-automation'); ?></strong> <?php _e('Documents are automatically attached to emails. Google Drive links are only available if Google Drive integration is enabled.', 'legal-doc-automation'); ?></p>
            </div>
            
            <div class="lda-card">
                <h3><?php _e('Email Test', 'legal-doc-automation'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Test Email Address', 'legal-doc-automation'); ?></th>
                        <td>
                            <input type="email" id="test-email" class="regular-text" placeholder="test@example.com" style="width: 200px;">
                            <button type="button" id="send-test-email" class="button"><?php _e('Send Test Email', 'legal-doc-automation'); ?></button>
                            <div id="email-test-result"></div>
                        </td>
                    </tr>
                </table>
            </div>
            
            <?php submit_button(); ?>
        </form>
        <?php
    }
    
    /**
     * Show Google Drive settings tab (Use-your-Drive only)
     */
    private function showGoogleDriveTab() {
        ?>
        <div class="lda-card">
            <h2><?php _e('Google Drive Integration', 'legal-doc-automation'); ?></h2>
            <p class="description"><?php _e('This plugin uses the Use-your-Drive plugin for Google Drive integration. Configure your Google Drive settings below.', 'legal-doc-automation'); ?></p>
            
            <form method="post" action="options.php" class="lda-settings-form">
                <?php
                settings_fields('lda_settings');
                ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Enable Google Drive', 'legal-doc-automation'); ?></th>
                        <td>
                            <?php $this->googleDriveEnabledCallback(); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Integration Method', 'legal-doc-automation'); ?></th>
                        <td>
                            <p><strong><?php _e('Use-Your-Drive Plugin', 'legal-doc-automation'); ?></strong></p>
                            <p class="description"><?php _e('This plugin uses the Use-your-Drive plugin for Google Drive integration. When Google Drive is enabled, documents will be automatically uploaded to the user\'s Google Drive folder.', 'legal-doc-automation'); ?></p>
                        </td>
                    </tr>
                </table>
                
                <div class="lda-card">
                    <h3><?php _e('Google Drive Status', 'legal-doc-automation'); ?></h3>
                    <?php $this->displayGoogleDriveStatus(); ?>
                </div>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Show field mapping tab
     */
    private function showFieldMappingTab() {
        // Handle form submission
        if (isset($_POST['save_mapping']) && wp_verify_nonce($_POST['mapping_nonce'], 'save_field_mapping')) {
            $this->saveFieldMapping();
        }
        
        // Template assignment is now handled in the templates tab
        
        // Handle auto-population trigger
        if (isset($_GET['auto_populate']) && $_GET['auto_populate'] == '1') {
            $this->handleAutoPopulate();
        }
        
        // Get current mappings and template assignments
        $mappings = get_option('lda_field_mappings', array());
        $template_assignments = get_option('lda_template_assignments', array());
        $selected_form = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
        $selected_template = isset($_GET['template']) ? basename($_GET['template']) : '';
        
        // If no template selected but form has an assigned template, use that
        if ($selected_form > 0 && empty($selected_template) && isset($template_assignments[$selected_form])) {
            $selected_template = $template_assignments[$selected_form];
        }
        
        // Check if form has no assigned template
        $form_has_template = ($selected_form > 0 && isset($template_assignments[$selected_form]));
        
        ?>
        <div class="lda-mapping-section">
            <h2><?php _e('Field Mapping', 'legal-doc-automation'); ?></h2>
            <p class="description"><?php _e('Manually map merge tags to Gravity Forms fields for document generation.', 'legal-doc-automation'); ?></p>
            
            <?php if (isset($_GET['saved']) && $_GET['saved'] == '1'): ?>
                <div class="notice notice-success"><p><?php _e('Field mappings saved successfully!', 'legal-doc-automation'); ?></p></div>
            <?php endif; ?>
            
            <!-- Form and Template Selection -->
            <div class="lda-card">
                <h3><?php _e('Select Form and Template', 'legal-doc-automation'); ?></h3>
                <form method="get" action="">
                    <input type="hidden" name="page" value="lda-settings">
                    <input type="hidden" name="tab" value="mapping">
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php _e('Form', 'legal-doc-automation'); ?></th>
                            <td>
                                <select name="form_id" onchange="this.form.submit()">
                                    <option value=""><?php _e('Select a form...', 'legal-doc-automation'); ?></option>
                                    <?php $this->populateFormOptions($selected_form); ?>
                                </select>
                            </td>
                        </tr>
                        <?php if ($selected_form > 0): ?>
                        <tr>
                            <th scope="row"><?php _e('Template', 'legal-doc-automation'); ?></th>
                            <td>
                                <?php if ($form_has_template): ?>
                                    <select name="template" onchange="this.form.submit()">
                                        <option value=""><?php _e('Select template to auto-populate merge tags', 'legal-doc-automation'); ?></option>
                                        <?php echo $this->populateTemplateOptions($selected_template); ?>
                                    </select>
                                    <p class="description"><?php _e('Select a template to automatically populate merge tags from the template.', 'legal-doc-automation'); ?></p>
                                    
                                    <?php if ($selected_template): ?>
                                    <div style="margin-top: 10px;">
                                        <button type="button" class="button button-secondary auto-populate-tags" data-form-id="<?php echo $selected_form; ?>" data-template="<?php echo esc_attr(json_encode($selected_template)); ?>">
                                            <?php _e('Auto-Populate Merge Tags', 'legal-doc-automation'); ?>
                                        </button>
                                    </div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <div class="notice notice-warning inline">
                                        <p>
                                            <strong><?php _e('No template assigned to this form.', 'legal-doc-automation'); ?></strong><br>
                                            <?php _e('Please go to the Templates tab to assign a template to this form before setting up field mappings.', 'legal-doc-automation'); ?>
                                            <a href="<?php echo admin_url('admin.php?page=lda-settings&tab=templates'); ?>" class="button button-secondary" style="margin-left: 10px;">
                                                <?php _e('Go to Templates Tab', 'legal-doc-automation'); ?>
                                            </a>
                                        </p>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </form>
            </div>
            
            <?php if ($selected_form > 0): ?>
                <!-- Field Mapping Interface -->
                <div class="lda-card">
                    <h3><?php _e('Field Mappings', 'legal-doc-automation'); ?></h3>
                    <?php if ($selected_template): ?>
                        <p class="description"><?php _e('Merge tags found in the selected template are shown below. You can edit, delete, or add new mappings.', 'legal-doc-automation'); ?></p>
                    <?php else: ?>
                        <p class="description"><?php _e('Type the merge tag in the Key field and select the corresponding Gravity Forms field in the Value dropdown.', 'legal-doc-automation'); ?></p>
                    <?php endif; ?>
                    
                    <form id="field_mapping_form">
                        <input type="hidden" name="form_id" value="<?php echo $selected_form; ?>">
                        
                        <table class="widefat" id="field_mapping_table">
                            <thead>
                                <tr>
                                    <th style="width: 40%;"><?php _e('Key (Merge Tag)', 'legal-doc-automation'); ?></th>
                                    <th style="width: 50%;"><?php _e('Value (Form Field)', 'legal-doc-automation'); ?></th>
                                    <th style="width: 10%;"><?php _e('Actions', 'legal-doc-automation'); ?></th>
                                </tr>
                            </thead>
                            <tbody id="mapping-rows">
                                <?php $this->renderManualMappingRows($selected_form, $mappings); ?>
                            </tbody>
                        </table>
                        
                        <p>
                            <button type="button" id="add_mapping_row" class="button"><?php _e('+ Add Row', 'legal-doc-automation'); ?></button>
                        </p>
                        
                        <p>
                            <input type="submit" name="save_mapping" class="button-primary" value="<?php _e('Save Mappings', 'legal-doc-automation'); ?>" />
                        </p>
                    </form>
                </div>
                
                <!-- Current Mappings Display -->
                <?php if (isset($mappings[$selected_form]) && !empty($mappings[$selected_form])): ?>
                <div class="lda-card">
                    <h3><?php _e('Current Mappings', 'legal-doc-automation'); ?></h3>
                    <div class="mapping-preview">
                        <?php $this->displayCurrentMappings($selected_form, $mappings); ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="lda-card">
                    <h3><?php _e('Current Mappings', 'legal-doc-automation'); ?></h3>
                    <p><?php _e('No custom mappings defined. Using automatic field detection.', 'legal-doc-automation'); ?></p>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <!-- JavaScript handled by admin.js -->
        
        <style>
        .mapping-row td {
            padding: 8px;
            vertical-align: middle;
        }
        .mapping-row input, .mapping-row select {
            margin: 0;
        }
        .remove-row {
            min-width: 30px;
            height: 30px;
            padding: 0;
            font-size: 16px;
            font-weight: bold;
        }
        #mapping-table {
            margin-bottom: 15px;
        }
        </style>
        <?php
    }
    
    /**
     * Show documentation tab
     */
    private function showDocumentationTab() {
        ?>
        <div class="lda-documentation-section">
            <h2><?php _e('Template Documentation', 'legal-doc-automation'); ?></h2>
            <p class="description"><?php _e('Learn how to use merge tags and conditional logic in your DOCX templates.', 'legal-doc-automation'); ?></p>
            
            <div class="lda-card">
                <h3><?php _e('Basic Merge Tags', 'legal-doc-automation'); ?></h3>
                <p><?php _e('Use merge tags to insert form data into your documents:', 'legal-doc-automation'); ?></p>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php _e('Pattern', 'legal-doc-automation'); ?></th>
                            <th><?php _e('Description', 'legal-doc-automation'); ?></th>
                            <th><?php _e('Example', 'legal-doc-automation'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>{$FieldName}</code></td>
                            <td><?php _e('Basic field replacement', 'legal-doc-automation'); ?></td>
                            <td><code>{$USR_Name}</code></td>
                        </tr>
                        <tr>
                            <td><code>{$FieldName|modifier}</code></td>
                            <td><?php _e('Field with modifier', 'legal-doc-automation'); ?></td>
                            <td><code>{$USR_Name|upper}</code></td>
                        </tr>
                        <tr>
                            <td><code>{$FieldName|modifier:"param"}</code></td>
                            <td><?php _e('Field with modifier and parameter', 'legal-doc-automation'); ?></td>
                            <td><code>{$Date|date_format:"d F Y"}</code></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div class="lda-card">
                <h3><?php _e('Available Modifiers', 'legal-doc-automation'); ?></h3>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php _e('Modifier', 'legal-doc-automation'); ?></th>
                            <th><?php _e('Description', 'legal-doc-automation'); ?></th>
                            <th><?php _e('Example', 'legal-doc-automation'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>upper</code></td>
                            <td><?php _e('Convert to uppercase', 'legal-doc-automation'); ?></td>
                            <td><code>{$USR_Name|upper}</code></td>
                        </tr>
                        <tr>
                            <td><code>lower</code></td>
                            <td><?php _e('Convert to lowercase', 'legal-doc-automation'); ?></td>
                            <td><code>{$USR_Name|lower}</code></td>
                        </tr>
                        <tr>
                            <td><code>phone_format:"format"</code></td>
                            <td><?php _e('Format phone number or ABN', 'legal-doc-automation'); ?></td>
                            <td><code>{$USR_ABN|phone_format:"%2 %3 %3 %3"}</code></td>
                        </tr>
                        <tr>
                            <td><code>date_format:"format"</code></td>
                            <td><?php _e('Format date', 'legal-doc-automation'); ?></td>
                            <td><code>{$Date|date_format:"d F Y"}</code></td>
                        </tr>
                        <tr>
                            <td><code>replace:"old":"new"</code></td>
                            <td><?php _e('Replace text', 'legal-doc-automation'); ?></td>
                            <td><code>{$State|replace:"NSW":"New South Wales"}</code></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div class="lda-card">
                <h3><?php _e('Conditional Logic', 'legal-doc-automation'); ?></h3>
                <p><?php _e('Use conditional statements to show content based on field values:', 'legal-doc-automation'); ?></p>
                
                <h4><?php _e('Basic If Statements', 'legal-doc-automation'); ?></h4>
                <pre><code>{if !empty($FieldName)}Content to show{/if}</code></pre>
                <p><?php _e('Shows content only if the field has a value.', 'legal-doc-automation'); ?></p>
                
                <h4><?php _e('If/Else Statements', 'legal-doc-automation'); ?></h4>
                <pre><code>{if $Type == "Premium"}Premium content{else}Standard content{/if}</code></pre>
                <p><?php _e('Shows different content based on field value.', 'legal-doc-automation'); ?></p>
                
                <h4><?php _e('If/ElseIf/Else Statements', 'legal-doc-automation'); ?></h4>
                <pre><code>{if $Type == "Premium"}Premium content{elseif $Type == "Basic"}Basic content{else}Default content{/if}</code></pre>
                <p><?php _e('Multiple conditions with fallback.', 'legal-doc-automation'); ?></p>
                
                <h4><?php _e('ListIf Statements', 'legal-doc-automation'); ?></h4>
                <pre><code>{listif $CheckboxField == "Option1"}✓ Option 1{/listif}
{listif $CheckboxField == "Option2"}✓ Option 2{/listif}
{listif $CheckboxField == "Option3"}✓ Option 3{/listif}</code></pre>
                <p><?php _e('Shows content only if the condition is met. Useful for checkboxes and multiple selections.', 'legal-doc-automation'); ?></p>
            </div>
            
            <div class="lda-card">
                <h3><?php _e('Checkbox Handling', 'legal-doc-automation'); ?></h3>
                <p><?php _e('Special patterns for handling checkbox fields:', 'legal-doc-automation'); ?></p>
                
                <h4><?php _e('Single Selection Display', 'legal-doc-automation'); ?></h4>
                <pre><code>{if count($CheckboxField) == 1}
    {listif $CheckboxField == "Option1"}✓ Option 1{/listif}
    {listif $CheckboxField == "Option2"}✓ Option 2{/listif}
{/if}</code></pre>
                
                <h4><?php _e('Multiple Selection Display', 'legal-doc-automation'); ?></h4>
                <pre><code>{if count($CheckboxField) > 1}
    Selected options:
    {listif $CheckboxField == "Option1"}• Option 1{/listif}
    {listif $CheckboxField == "Option2"}• Option 2{/listif}
    {listif $CheckboxField == "Option3"}• Option 3{/listif}
{/if}</code></pre>
                
                <h4><?php _e('Smart Display Logic', 'legal-doc-automation'); ?></h4>
                <pre><code>{if !empty($CheckboxField)}
    {if count($CheckboxField) == 1}
        Selected: {listif $CheckboxField == "Option1"}Option 1{/listif}
    {else}
        Selected options:
        {listif $CheckboxField == "Option1"}• Option 1{/listif}
        {listif $CheckboxField == "Option2"}• Option 2{/listif}
    {/if}
{else}
    No options selected
{/if}</code></pre>
            </div>
            
            <div class="lda-card">
                <h3><?php _e('Advanced Examples', 'legal-doc-automation'); ?></h3>
                
                <h4><?php _e('Business Information with Fallbacks', 'legal-doc-automation'); ?></h4>
                <pre><code>{if !empty($USR_Business)}
    {$USR_Business|upper}
{elseif !empty($USR_Name)}
    {$USR_Name|upper}
{else}
    [Business Name Not Provided]
{/if}</code></pre>
                
                <h4><?php _e('Address Formatting', 'legal-doc-automation'); ?></h4>
                <pre><code>{if !empty($USR_Address)}
    {$USR_Address}
    {if !empty($USR_Suburb)}{$USR_Suburb}{/if}
    {if !empty($USR_State)}{$USR_State}{/if}
    {if !empty($USR_Postcode)}{$USR_Postcode}{/if}
{/if}</code></pre>
                
                <h4><?php _e('Date with Formatting', 'legal-doc-automation'); ?></h4>
                <pre><code>{if !empty($Effective_Date)}
    Effective Date: {$Effective_Date|date_format:"d F Y"}
{else}
    Effective Date: {$Date|date_format:"d F Y"}
{/if}</code></pre>
                
                <h4><?php _e('ABN with Formatting', 'legal-doc-automation'); ?></h4>
                <pre><code>{if !empty($USR_ABN)}
    ABN: {$USR_ABN|phone_format:"%2 %3 %3 %3"}
{/if}</code></pre>
            </div>
            
            <div class="lda-card">
                <h3><?php _e('Tips and Best Practices', 'legal-doc-automation'); ?></h3>
                <ul>
                    <li><?php _e('Always use <code>{if !empty($FieldName)}</code> to check if a field has a value before displaying it.', 'legal-doc-automation'); ?></li>
                    <li><?php _e('Use <code>{listif}</code> for checkbox fields and multiple selections.', 'legal-doc-automation'); ?></li>
                    <li><?php _e('Combine conditions with <code>and</code> for multiple requirements: <code>{if $Field1 == "value" and $Field2 == "value"}</code>', 'legal-doc-automation'); ?></li>
                    <li><?php _e('Test your templates with different form submissions to ensure all conditions work correctly.', 'legal-doc-automation'); ?></li>
                    <li><?php _e('Use the Field Mapping tab to map your form fields to merge tags.', 'legal-doc-automation'); ?></li>
                    <li><?php _e('Check the Activity Logs tab if merge tags are not working as expected.', 'legal-doc-automation'); ?></li>
                </ul>
            </div>
        </div>
        
        <style>
        .lda-documentation-section pre {
            background: #f1f1f1;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.4;
        }
        .lda-documentation-section code {
            background: #f1f1f1;
            padding: 2px 4px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
        }
        .lda-documentation-section table {
            margin: 15px 0;
        }
        .lda-documentation-section h4 {
            margin-top: 20px;
            margin-bottom: 10px;
            color: #23282d;
        }
        .lda-documentation-section ul {
            margin: 15px 0;
            padding-left: 20px;
        }
        .lda-documentation-section li {
            margin: 8px 0;
        }
        </style>
        <?php
    }
    
    /**
     * Show activity logs tab
     */
    private function showLogsTab() {
        ?>
        <div class="lda-logs-section">
            <h2><?php _e('Activity Logs', 'legal-doc-automation'); ?></h2>
            
            <div class="lda-card">
                <div class="log-controls">
                    <select id="log-level-filter">
                        <option value=""><?php _e('All Levels', 'legal-doc-automation'); ?></option>
                        <option value="ERROR"><?php _e('Errors Only', 'legal-doc-automation'); ?></option>
                        <option value="WARN"><?php _e('Warnings', 'legal-doc-automation'); ?></option>
                        <option value="INFO"><?php _e('Info', 'legal-doc-automation'); ?></option>
                        <option value="DEBUG"><?php _e('Debug', 'legal-doc-automation'); ?></option>
                    </select>
                    <select id="log-days-filter">
                        <option value="1"><?php _e('Last 24 hours', 'legal-doc-automation'); ?></option>
                        <option value="7" selected><?php _e('Last 7 days', 'legal-doc-automation'); ?></option>
                        <option value="30"><?php _e('Last 30 days', 'legal-doc-automation'); ?></option>
                    </select>
                    <button type="button" id="refresh-logs" class="button"><?php _e('Refresh', 'legal-doc-automation'); ?></button>
                    <button type="button" id="copy-logs" class="button button-primary"><?php _e('Copy Logs', 'legal-doc-automation'); ?></button>
                    <button type="button" id="clear-logs" class="button button-secondary"><?php _e('Clear Logs', 'legal-doc-automation'); ?></button>
                </div>
                
                <div id="log-entries">
                    <?php $this->displayRecentLogs(); ?>
                </div>
            </div>
            
            <!-- Log Statistics -->
            <div class="lda-card">
                <h3><?php _e('Log Statistics', 'legal-doc-automation'); ?></h3>
                <?php $this->displayLogStats(); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Show system status tab
     */
    private function showStatusTab() {
        ?>
        <div class="lda-status-section">
            <h2><?php _e('System Status', 'legal-doc-automation'); ?></h2>
            
            <!-- System Requirements -->
            <div class="lda-card">
                <h3><?php _e('System Requirements', 'legal-doc-automation'); ?></h3>
                <?php $this->displaySystemStatus(); ?>
            </div>
            
            <!-- Plugin Dependencies -->
            <div class="lda-card">
                <h3><?php _e('Plugin Dependencies', 'legal-doc-automation'); ?></h3>
                <?php $this->displayPluginStatus(); ?>
            </div>
            
            <!-- Directory Permissions -->
            <div class="lda-card">
                <h3><?php _e('Directory Permissions', 'legal-doc-automation'); ?></h3>
                <?php $this->displayDirectoryStatus(); ?>
            </div>
            
            <!-- Processing Statistics -->
            <div class="lda-card">
                <h3><?php _e('Processing Statistics (Last 30 Days)', 'legal-doc-automation'); ?></h3>
                <?php $this->displayProcessingStats(); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Initialize plugin settings
     */
    public function initSettings() {
        register_setting('lda_settings', 'lda_settings', array(
            'sanitize_callback' => array($this, 'sanitizeSettings'),
            'default' => array(
                'google_drive_enabled' => 0,
                'google_drive_access_token' => '',
                'google_drive_folder_id' => '',
                'debug_mode' => 0,
            ),
        ));
        
        // General Settings
        add_settings_section('lda_general_section', __('General Settings', 'legal-doc-automation'), array($this, 'generalSectionCallback'), 'lda_general');
        
        add_settings_field('template_folder', __('Template Folder', 'legal-doc-automation'), array($this, 'templateFolderCallback'), 'lda_general', 'lda_general_section');
        
        // Email Settings
        add_settings_section('lda_email_section', __('Email Settings', 'legal-doc-automation'), array($this, 'emailSectionCallback'), 'lda_email');
        
        add_settings_field('email_subject', __('Email Subject', 'legal-doc-automation'), array($this, 'emailSubjectCallback'), 'lda_email', 'lda_email_section');
        add_settings_field('email_message', __('Email Message', 'legal-doc-automation'), array($this, 'emailMessageCallback'), 'lda_email', 'lda_email_section');
        add_settings_field('from_email', __('From Email', 'legal-doc-automation'), array($this, 'fromEmailCallback'), 'lda_email', 'lda_email_section');
        add_settings_field('from_name', __('From Name', 'legal-doc-automation'), array($this, 'fromNameCallback'), 'lda_email', 'lda_email_section');
        
        // Google Drive Settings (Use-your-Drive only)
        add_settings_section('lda_gdrive_section', __('Google Drive Settings', 'legal-doc-automation'), array($this, 'gdriveSectionCallback'), 'lda_gdrive');
        
        add_settings_field('google_drive_enabled', __('Enable Google Drive', 'legal-doc-automation'), array($this, 'googleDriveEnabledCallback'), 'lda_gdrive', 'lda_gdrive_section');
        add_settings_field('gdrive_root_folder', __('Root Folder Name', 'legal-doc-automation'), array($this, 'googleDriveRootFolderCallback'), 'lda_gdrive', 'lda_gdrive_section');
        add_settings_field('gdrive_folder_naming', __('Folder Naming Strategy', 'legal-doc-automation'), array($this, 'gdrivefolderNamingCallback'), 'lda_gdrive', 'lda_gdrive_section');
        add_settings_field('gdrive_filename_format', __('Filename Format', 'legal-doc-automation'), array($this, 'gdriveFilenameFormatCallback'), 'lda_gdrive', 'lda_gdrive_section');
        
        // Debug Settings
        add_settings_section('lda_debug_section', __('Debug Settings', 'legal-doc-automation'), array($this, 'debugSectionCallback'), 'lda_debug');
        
        add_settings_field('debug_mode', __('Debug Mode', 'legal-doc-automation'), array($this, 'debugModeCallback'), 'lda_debug', 'lda_debug_section');
        add_settings_field('max_processing_time', __('Max Processing Time (seconds)', 'legal-doc-automation'), array($this, 'maxProcessingTimeCallback'), 'lda_debug', 'lda_debug_section');
        add_settings_field('max_memory_usage', __('Max Memory Usage', 'legal-doc-automation'), array($this, 'maxMemoryUsageCallback'), 'lda_debug', 'lda_debug_section');
    }
    
    /**
     * Sanitize settings before saving
     */
    public function sanitizeSettings($input) {
        $sanitized = array();
        
        // Get existing settings to preserve values not being updated
        $existing_settings = get_option('lda_settings', array());

        // When a checkbox is unchecked, it's not sent in the POST request.
        // We must check the raw POST data to see if the key exists.
        // If it doesn't exist, it means the box was unchecked, so we save 0.
        
        // For Google Drive, preserve existing value if not being updated
        if (isset($input['google_drive_enabled'])) {
            $sanitized['google_drive_enabled'] = 1;
        } else {
            // Only set to 0 if we're explicitly updating Google Drive settings
            // Otherwise preserve the existing value
            $sanitized['google_drive_enabled'] = isset($existing_settings['google_drive_enabled']) ? $existing_settings['google_drive_enabled'] : 0;
        }
        
        $sanitized['debug_mode'] = isset($input['debug_mode']) ? 1 : 0;
        
        // Sanitize PDF settings

        // Sanitize text and email fields
        $sanitized['email_subject'] = isset($input['email_subject']) ? sanitize_text_field($input['email_subject']) : '';
        $sanitized['email_message'] = isset($input['email_message']) ? sanitize_textarea_field($input['email_message']) : '';
        $sanitized['from_email'] = isset($input['from_email']) ? sanitize_email($input['from_email']) : '';
        $sanitized['from_name'] = isset($input['from_name']) ? sanitize_text_field($input['from_name']) : '';
        
        // Sanitize Google Drive settings
        // Force use-your-drive method
        $sanitized['google_drive_method'] = 'use_your_drive';
        $sanitized['google_drive_access_token'] = isset($input['google_drive_access_token']) ? sanitize_text_field($input['google_drive_access_token']) : '';
        $sanitized['google_drive_folder_id'] = isset($input['google_drive_folder_id']) ? sanitize_text_field($input['google_drive_folder_id']) : '';
        $sanitized['gdrive_root_folder'] = isset($input['gdrive_root_folder']) ? sanitize_text_field($input['gdrive_root_folder']) : 'LegalDocuments';
        $sanitized['gdrive_folder_naming'] = isset($input['gdrive_folder_naming']) ? sanitize_text_field($input['gdrive_folder_naming']) : 'business_name';
        $sanitized['gdrive_filename_format'] = isset($input['gdrive_filename_format']) ? sanitize_text_field($input['gdrive_filename_format']) : 'form_business_date';
        
        $sanitized['max_processing_time'] = isset($input['max_processing_time']) ? intval($input['max_processing_time']) : 300;
        $sanitized['max_memory_usage'] = isset($input['max_memory_usage']) ? sanitize_text_field($input['max_memory_usage']) : '512M';
        
        return $sanitized;
    }
    
    // Section Callbacks
    public function generalSectionCallback() {
        echo '<p>' . __('Configure general document automation settings.', 'legal-doc-automation') . '</p>';
    }
    
    public function emailSectionCallback() {
        $text = __('<strong>OPTIONAL:</strong> These settings control the <strong>content</strong> of the email that is automatically sent to the user after they submit a form. The final merged document is sent as an attachment.', 'legal-doc-automation');
        $text .= '<br><br>';
        $text .= __('<strong>Important:</strong> If you leave these fields empty, the plugin will use default templates that include Google Drive links. The plugin will always send emails with document attachments.', 'legal-doc-automation');
        $text .= '<br><br>';
        $text .= __('The recipient\'s email address is automatically determined from the form submission; you do not need to set it here.', 'legal-doc-automation');
        $text .= '<br><br>';
        $text .= __('Use merge tags like {UserFirstName} or any form field label (e.g., {BusinessName}) in the Subject and Message fields to personalize the content.', 'legal-doc-automation');
        echo '<p>' . $text . '</p>';
    }
    
    public function gdriveSectionCallback() {
        echo '<p>' . __('Configure Google Drive integration settings. Files will be uploaded to Google Drive after document generation.', 'legal-doc-automation') . '</p>';
        echo '<p><strong>' . __('Integration Methods (in order of preference):', 'legal-doc-automation') . '</strong></p>';
        echo '<ol>';
        echo '<li><strong>OAuth Google Drive:</strong> Direct integration using access token and folder ID (Recommended)</li>';
        echo '<li><strong>Native Google Drive API:</strong> Direct integration using Google API credentials</li>';
        echo '<li><strong>Use-your-Drive Plugin:</strong> Integration via WP Cloud Plugins - Use-your-Drive</li>';
        echo '<li><strong>File-based Storage:</strong> Local storage with simulated Google Drive links</li>';
        echo '</ol>';
        echo '<p><strong>' . __('For OAuth Integration (Recommended):', 'legal-doc-automation') . '</strong></p>';
        echo '<ul>';
        echo '<li>Get your <strong>Access Token</strong> from your Google Drive application</li>';
        echo '<li>Get your <strong>Folder ID</strong> from the Google Drive folder URL</li>';
        echo '<li>Enter both values below to enable real Google Drive uploads</li>';
        echo '</ul>';
        echo '<p><strong>' . __('Can\'t find your Access Token?', 'legal-doc-automation') . '</strong></p>';
        echo '<ul>';
        echo '<li>Check your Google Drive app\'s settings or logs</li>';
        echo '<li>Look in browser Developer Tools (F12) → Network tab for API calls</li>';
        echo '<li><strong>OR</strong> leave these fields empty - the plugin will automatically use your Use-your-Drive plugin!</li>';
        echo '</ul>';
        echo '<p><strong>' . __('Recommended: Use Use-your-Drive Plugin', 'legal-doc-automation') . '</strong></p>';
        echo '<p>Since you already have the Use-your-Drive plugin installed and configured, you can simply:</p>';
        echo '<ol>';
        echo '<li>Leave the Access Token and Folder ID fields empty</li>';
        echo '<li>The plugin will automatically detect and use your Use-your-Drive plugin</li>';
        echo '<li>Files will be uploaded to your configured Google Drive account</li>';
        echo '<li>No additional setup required!</li>';
        echo '</ol>';
        echo '<p><em>' . __('For native API, upload your Google API credentials file to wp-content/uploads/lda-google-credentials.json', 'legal-doc-automation') . '</em></p>';
    }
    
    public function debugSectionCallback() {
        echo '<p>' . __('Debug and logging settings for troubleshooting.', 'legal-doc-automation') . '</p>';
    }
    
    // Field Callbacks
    public function templateFolderCallback() {
        $path = wp_upload_dir()['basedir'] . '/lda-templates/';
        
        // Make the path more readable by replacing the absolute server path with a more user-friendly version.
        $display_path = str_replace(ABSPATH, '[...]/', $path);

        echo '<code>' . esc_html($display_path) . '</code>';
        echo '<p class="description">' . __('This folder is automatically created and used by the plugin. Please upload your templates via the \'Templates\' tab.', 'legal-doc-automation') . '</p>';
    }
    
    
    
    public function emailSubjectCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['email_subject']) ? $options['email_subject'] : 'Your legal document is ready - {FormTitle}';
        echo '<input type="text" name="lda_settings[email_subject]" value="' . esc_attr($value) . '" class="regular-text" />';
    }
    
    public function emailMessageCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['email_message']) ? $options['email_message'] : "Dear {UserFirstName},\n\nYour legal document \"{FormTitle}\" has been generated and is ready for your review.\n\nPlease find the completed document attached to this email.\n\nBest regards,\n{SiteName}";
        echo '<textarea name="lda_settings[email_message]" rows="8" class="large-text">' . esc_textarea($value) . '</textarea>';
    }
    
    public function fromEmailCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['from_email']) ? $options['from_email'] : get_option('admin_email');
        echo '<input type="email" name="lda_settings[from_email]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Leave empty to use WordPress default', 'legal-doc-automation') . '</p>';
    }
    
    public function fromNameCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['from_name']) ? $options['from_name'] : get_bloginfo('name');
        echo '<input type="text" name="lda_settings[from_name]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Leave empty to use site name', 'legal-doc-automation') . '</p>';
    }
    
    public function googleDriveEnabledCallback() {
        $options = get_option('lda_settings');
        $checked = isset($options['google_drive_enabled']) && $options['google_drive_enabled'] ? 'checked' : '';
        echo '<input type="checkbox" name="lda_settings[google_drive_enabled]" value="1" ' . $checked . '>';
        echo '<p class="description">' . __('Save generated documents to user\'s Google Drive folders', 'legal-doc-automation') . '</p>';
    }

    public function googleDriveMethodCallback() {
        $options = get_option('lda_settings');
        $method = isset($options['google_drive_method']) ? $options['google_drive_method'] : 'oauth';
        
        echo '<select name="lda_settings[google_drive_method]" id="gdrive_method" onchange="toggleGDriveFields()">';
        echo '<option value="oauth" ' . selected($method, 'oauth', false) . '>' . __('OAuth (Real Google Drive)', 'legal-doc-automation') . '</option>';
        echo '<option value="native_api" ' . selected($method, 'native_api', false) . '>' . __('Native Google Drive API', 'legal-doc-automation') . '</option>';
        echo '<option value="use_your_drive" ' . selected($method, 'use_your_drive', false) . '>' . __('Use-your-Drive Plugin', 'legal-doc-automation') . '</option>';
        echo '</select>';
        echo '<p class="description">' . __('Choose how to connect to Google Drive. The form below will update to show only the relevant fields for your selection.', 'legal-doc-automation') . '</p>';
        
        // Add JavaScript to toggle fields
        echo '<script>
        function toggleGDriveFields() {
            var method = document.getElementById("gdrive_method").value;
            
            // Hide all method-specific sections
            var sections = ["native_api_section", "use_your_drive_section", "oauth_section"];
            sections.forEach(function(sectionId) {
                var section = document.getElementById(sectionId);
                if (section) {
                    section.style.display = "none";
                }
            });
            
            // Show the relevant section
            var targetSection = method + "_section";
            var targetElement = document.getElementById(targetSection);
            if (targetElement) {
                targetElement.style.display = "block";
            }
        }
        
        // Initialize on page load
        document.addEventListener("DOMContentLoaded", function() {
            toggleGDriveFields();
        });
        </script>';
    }

    public function googleDriveCredentialsCallback() {
        $options = get_option('lda_settings');
        $upload_dir = wp_upload_dir();
        $credentials_path = $upload_dir['basedir'] . '/lda-google-credentials.json';
        $credentials_exist = file_exists($credentials_path);
        
        echo '<div id="native_api_section" class="gdrive-method-section">';
        echo '<h4>' . __('Native Google Drive API Setup', 'legal-doc-automation') . '</h4>';
        
        if ($credentials_exist) {
            echo '<div class="notice notice-success inline"><p><strong>✓</strong> ' . __('Google Drive API credentials file found and ready to use.', 'legal-doc-automation') . '</p></div>';
            echo '<p><strong>' . __('Current credentials file:', 'legal-doc-automation') . '</strong> <code>' . $credentials_path . '</code></p>';
        } else {
            echo '<div class="notice notice-warning inline"><p><strong>⚠</strong> ' . __('Google Drive API credentials file not found.', 'legal-doc-automation') . '</p></div>';
        }
        
        echo '<h5>' . __('How to set up Google Drive API:', 'legal-doc-automation') . '</h5>';
        echo '<ol>';
        echo '<li>' . __('Go to the <a href="https://console.developers.google.com/" target="_blank">Google Cloud Console</a>', 'legal-doc-automation') . '</li>';
        echo '<li>' . __('Create a new project or select an existing one', 'legal-doc-automation') . '</li>';
        echo '<li>' . __('Enable the Google Drive API', 'legal-doc-automation') . '</li>';
        echo '<li>' . __('Create credentials (Service Account)', 'legal-doc-automation') . '</li>';
        echo '<li>' . __('Download the JSON credentials file', 'legal-doc-automation') . '</li>';
        echo '<li>' . __('Upload the file using the button below', 'legal-doc-automation') . '</li>';
        echo '<li>' . __('Share your Google Drive folder with the service account email', 'legal-doc-automation') . '</li>';
        echo '</ol>';
        
        echo '<p><strong>' . __('File upload:', 'legal-doc-automation') . '</strong></p>';
        echo '<input type="file" id="gdrive_credentials_upload" accept=".json" />';
        echo '<button type="button" id="upload_credentials" class="button">' . __('Upload Credentials', 'legal-doc-automation') . '</button>';
        echo '<div id="upload_result"></div>';
        
        echo '</div>';
    }

    public function googleDriveClientIdCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['google_drive_client_id']) ? $options['google_drive_client_id'] : '';
        
        echo '<div id="oauth_section" class="gdrive-method-section">';
        echo '<h4>' . __('Google Drive OAuth Setup', 'legal-doc-automation') . '</h4>';
        echo '<p class="description">' . __('Set up OAuth to upload files to your actual Google Drive account.', 'legal-doc-automation') . '</p>';
        
        echo '<p><label for="google_drive_client_id"><strong>' . __('Client ID:', 'legal-doc-automation') . '</strong></label></p>';
        echo '<input type="text" name="lda_settings[google_drive_client_id]" id="google_drive_client_id" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Your Google Drive OAuth Client ID from Google Cloud Console.', 'legal-doc-automation') . '</p>';
        echo '</div>';
    }
    
    public function googleDriveClientSecretCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['google_drive_client_secret']) ? $options['google_drive_client_secret'] : '';
        
        echo '<div id="oauth_section" class="gdrive-method-section">';
        echo '<p><label for="google_drive_client_secret"><strong>' . __('Client Secret:', 'legal-doc-automation') . '</strong></label></p>';
        echo '<input type="password" name="lda_settings[google_drive_client_secret]" id="google_drive_client_secret" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Your Google Drive OAuth Client Secret from Google Cloud Console.', 'legal-doc-automation') . '</p>';
        echo '</div>';
    }

    public function googleDriveAccessTokenCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['google_drive_access_token']) ? $options['google_drive_access_token'] : '';
        
        echo '<div id="oauth_section" class="gdrive-method-section">';
        echo '<h4>' . __('OAuth Access Token Setup', 'legal-doc-automation') . '</h4>';
        echo '<p class="description">' . __('If you have a Google Drive application that uses OAuth authentication, enter the access token here.', 'legal-doc-automation') . '</p>';
        
        echo '<p><label for="google_drive_access_token"><strong>' . __('Access Token:', 'legal-doc-automation') . '</strong></label></p>';
        echo '<input type="text" name="lda_settings[google_drive_access_token]" id="google_drive_access_token" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('OAuth access token from your Google Drive application. Get this from your Google Drive app that created the folder.', 'legal-doc-automation') . '</p>';
        
        echo '</div>';
    }

    public function googleDriveFolderIdCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['google_drive_folder_id']) ? $options['google_drive_folder_id'] : '';
        
        echo '<p><label for="google_drive_folder_id"><strong>' . __('Folder ID:', 'legal-doc-automation') . '</strong></label></p>';
        echo '<input type="text" name="lda_settings[google_drive_folder_id]" id="google_drive_folder_id" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('The ID of the Google Drive folder where documents will be stored. You can find this in the folder URL.', 'legal-doc-automation') . '</p>';
    }
    
    public function useYourDrivePluginCallback() {
        if (is_plugin_active('use-your-drive/use-your-drive.php')) {
            echo '<div class="notice notice-success inline"><p><strong>✓</strong> ' . __('Use-your-Drive plugin is active and ready to use.', 'legal-doc-automation') . '</p></div>';
            echo '<p class="description">' . __('The plugin will automatically use the Use-your-Drive plugin for Google Drive integration. Make sure you have configured your Google Drive accounts in the Use-your-Drive plugin settings.', 'legal-doc-automation') . '</p>';
        } else {
            echo '<div class="notice notice-error inline"><p><strong>✗</strong> ' . __('Use-your-Drive plugin is not active.', 'legal-doc-automation') . '</p></div>';
            echo '<p class="description">' . __('Please install and activate the <a href="https://wordpress.org/plugins/use-your-drive/" target="_blank">Use-your-Drive plugin</a> to use this integration method.', 'legal-doc-automation') . '</p>';
        }
    }
    
    public function googleDriveRootFolderCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['gdrive_root_folder']) ? $options['gdrive_root_folder'] : 'Legal Documents';
        echo '<input type="text" name="lda_settings[gdrive_root_folder]" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . __('Root folder name in Google Drive for all legal documents', 'legal-doc-automation') . '</p>';
    }
    
    public function gdrivefolderNamingCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['gdrive_folder_naming']) ? $options['gdrive_folder_naming'] : 'business_name';
        
        $strategies = array(
            'business_name' => __('Business Name + User ID', 'legal-doc-automation'),
            'user_name' => __('User Name + User ID', 'legal-doc-automation'),
            'user_id' => __('User ID Only', 'legal-doc-automation')
        );
        
        echo '<select name="lda_settings[gdrive_folder_naming]">';
        foreach ($strategies as $key => $label) {
            $selected = ($value == $key) ? 'selected' : '';
            echo '<option value="' . $key . '" ' . $selected . '>' . $label . '</option>';
        }
        echo '</select>';
    }
    
    public function gdriveFilenameFormatCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['gdrive_filename_format']) ? $options['gdrive_filename_format'] : 'form_business_date';
        
        $formats = array(
            'form_business_date' => __('Form_Business_Date', 'legal-doc-automation'),
            'business_form_date' => __('Business_Form_Date', 'legal-doc-automation'),
            'entry_id_date' => __('Entry_ID_Date', 'legal-doc-automation')
        );
        
        echo '<select name="lda_settings[gdrive_filename_format]">';
        foreach ($formats as $key => $label) {
            $selected = ($value == $key) ? 'selected' : '';
            echo '<option value="' . $key . '" ' . $selected . '>' . $label . '</option>';
        }
        echo '</select>';
    }
    
    public function debugModeCallback() {
        $options = get_option('lda_settings');
        $checked = isset($options['debug_mode']) && $options['debug_mode'] ? 'checked' : '';
        echo '<input type="checkbox" name="lda_settings[debug_mode]" value="1" ' . $checked . '>';
        echo '<p class="description">' . __('Enable detailed logging for troubleshooting', 'legal-doc-automation') . '</p>';
    }
    
    public function maxProcessingTimeCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['max_processing_time']) ? $options['max_processing_time'] : 300;
        echo '<input type="number" name="lda_settings[max_processing_time]" value="' . esc_attr($value) . '" min="30" max="3600" />';
        echo '<p class="description">' . __('Maximum time in seconds for document processing', 'legal-doc-automation') . '</p>';
    }
    
    public function maxMemoryUsageCallback() {
        $options = get_option('lda_settings');
        $value = isset($options['max_memory_usage']) ? $options['max_memory_usage'] : '512M';
        echo '<input type="text" name="lda_settings[max_memory_usage]" value="' . esc_attr($value) . '" class="small-text" />';
        echo '<p class="description">' . __('Maximum memory usage (e.g., 512M, 1G)', 'legal-doc-automation') . '</p>';
    }
    
    /**
     * Handle template upload
     */
    private function handleTemplateUpload() {
        if (!isset($_POST['lda_upload_nonce']) || !wp_verify_nonce($_POST['lda_upload_nonce'], 'lda_upload_template')) {
            return; // Nonce not set or invalid.
        }

        if (!current_user_can('manage_options')) {
            LDA_Logger::error('User without manage_options capability tried to upload a template.');
            return; // Permission denied.
        }

        if (!isset($_FILES['template_file']) || !is_uploaded_file($_FILES['template_file']['tmp_name'])) {
            // This handles the case where the form is submitted without a file.
            return;
        }

        $file = $_FILES['template_file'];

        // Check for PHP upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $this->showUploadError($file['error']);
            LDA_Logger::error('Template upload failed with PHP error code: ' . $file['error']);
            return;
        }

        // Validate file type
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file_ext !== 'docx') {
            echo '<div class="notice notice-error"><p>' . esc_html__('Error: Only .docx files are allowed.', 'legal-doc-automation') . '</p></div>';
            LDA_Logger::error('Template upload failed: Invalid file type uploaded (' . $file_ext . ').');
            return;
        }

        // Define the template folder path directly.
        $template_folder = wp_upload_dir()['basedir'] . '/lda-templates/';

        // Check if template directory exists and is writable
        if (!is_dir($template_folder)) {
            // Try to create it
            if (!wp_mkdir_p($template_folder)) {
                $error_msg = 'Template directory does not exist and could not be created: ' . esc_html($template_folder);
                echo '<div class="notice notice-error"><p>' . esc_html__('Error:', 'legal-doc-automation') . ' ' . $error_msg . '</p></div>';
                LDA_Logger::error($error_msg);
                return;
            }
        }

        if (!is_writable($template_folder)) {
            $error_msg = 'Template directory is not writable: ' . esc_html($template_folder);
            echo '<div class="notice notice-error"><p>' . esc_html__('Error:', 'legal-doc-automation') . ' ' . $error_msg . '</p></div>';
            LDA_Logger::error($error_msg);
            return;
        }

        $filename = sanitize_file_name($file['name']);
        $destination = $template_folder . $filename;

        if (move_uploaded_file($file['tmp_name'], $destination)) {
            echo '<div class="notice notice-success"><p>' . sprintf(esc_html__('Template "%s" uploaded successfully.', 'legal-doc-automation'), esc_html($filename)) . '</p></div>';
            LDA_Logger::log('Template uploaded successfully: ' . $filename);
        } else {
            $error_msg = 'Failed to move uploaded file to destination.';
            echo '<div class="notice notice-error"><p>' . esc_html__('Error:', 'legal-doc-automation') . ' ' . esc_html__($error_msg, 'legal-doc-automation') . '</p></div>';
            LDA_Logger::error($error_msg . ' Destination: ' . $destination);
        }
    }

    // Helper function to show human-readable upload errors
    private function showUploadError($error_code) {
        $error_messages = array(
            UPLOAD_ERR_INI_SIZE   => __('The uploaded file exceeds the upload_max_filesize directive in php.ini.', 'legal-doc-automation'),
            UPLOAD_ERR_FORM_SIZE  => __('The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.', 'legal-doc-automation'),
            UPLOAD_ERR_PARTIAL    => __('The uploaded file was only partially uploaded.', 'legal-doc-automation'),
            UPLOAD_ERR_NO_FILE    => __('No file was uploaded.', 'legal-doc-automation'),
            UPLOAD_ERR_NO_TMP_DIR => __('Missing a temporary folder.', 'legal-doc-automation'),
            UPLOAD_ERR_CANT_WRITE => __('Failed to write file to disk.', 'legal-doc-automation'),
            UPLOAD_ERR_EXTENSION  => __('A PHP extension stopped the file upload.', 'legal-doc-automation'),
        );
        $message = isset($error_messages[$error_code]) ? $error_messages[$error_code] : __('Unknown upload error.', 'legal-doc-automation');
        echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
    }
    
    /**
     * Display templates list
     */
    private function displayTemplatesList() {
        $template_folder = wp_upload_dir()['basedir'] . '/lda-templates/';
        
        if (!is_dir($template_folder)) {
            echo '<p>' . __('Template folder not configured or not accessible.', 'legal-doc-automation') . '</p>';
            return;
        }
        
        $templates = glob($template_folder . '*.docx');
        
        if (empty($templates)) {
            echo '<p>' . __('No template files found', 'legal-doc-automation') . '</p>';
        } else {
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>' . __('Template File', 'legal-doc-automation') . '</th>';
            echo '<th>' . __('Size', 'legal-doc-automation') . '</th>';
            echo '<th>' . __('Modified', 'legal-doc-automation') . '</th>';
            echo '<th>' . __('Tags', 'legal-doc-automation') . '</th>';
            echo '<th>' . __('Assigned to Form', 'legal-doc-automation') . '</th>';
            echo '<th>' . __('Actions', 'legal-doc-automation') . '</th>';
            echo '</tr></thead><tbody>';
            
            foreach ($templates as $template) {
                $filename = basename($template);
                $size = size_format(filesize($template));
                $modified = date('Y-m-d H:i:s', filemtime($template));
                
                // Quick analysis
                $tag_count = $this->analyzeTemplateQuick($template);
                
                // Get assigned form for this template
                $template_assignments = get_option('lda_template_assignments', array());
                $assigned_form_id = array_search($filename, $template_assignments);
                $assigned_form_name = '';
                
                if ($assigned_form_id && class_exists('GFAPI')) {
                    $form = GFAPI::get_form($assigned_form_id);
                    $assigned_form_name = $form ? $form['title'] : 'Form ID: ' . $assigned_form_id;
                }
                
                echo '<tr>';
                echo '<td>';
                echo '<strong>' . esc_html($filename) . '</strong><br>';
                echo '<div style="margin-top: 8px;">';
                echo '<select class="assign-template-form" data-template="' . esc_attr(json_encode($filename)) . '" style="width: 100%; margin-bottom: 5px;">';
                echo '<option value="">' . __('Assign to form...', 'legal-doc-automation') . '</option>';
                if (class_exists('GFAPI')) {
                    $forms = GFAPI::get_forms();
                    foreach ($forms as $form) {
                        $selected = ($assigned_form_id == $form['id']) ? 'selected' : '';
                        echo '<option value="' . $form['id'] . '" ' . $selected . '>Form #' . $form['id'] . ': ' . esc_html($form['title']) . '</option>';
                    }
                }
                echo '</select>';
                echo '<button type="button" class="button button-primary assign-template-confirm" data-template="' . esc_attr(json_encode($filename)) . '" style="width: 100%;">' . __('Assign Template', 'legal-doc-automation') . '</button>';
                echo '</div>';
                echo '</td>';
                echo '<td>' . $size . '</td>';
                echo '<td>' . $modified . '</td>';
                echo '<td>' . $tag_count . '</td>';
                echo '<td>';
                if ($assigned_form_name) {
                    echo '<span class="assigned-form" style="color: #46b450; font-weight: 600;">✓ Form #' . $assigned_form_id . ': ' . esc_html($assigned_form_name) . '</span>';
                } else {
                    echo '<span class="not-assigned" style="color: #d63638;">✗ ' . __('Not assigned', 'legal-doc-automation') . '</span>';
                }
                echo '</td>';
                echo '<td>';
                echo '<button type="button" class="button validate-template" data-template="' . esc_attr(json_encode($filename)) . '">' . __('Validate', 'legal-doc-automation') . '</button><br><br>';
                echo '<button type="button" class="button test-template" data-template="' . esc_attr(json_encode($filename)) . '">' . __('Test', 'legal-doc-automation') . '</button><br><br>';
                echo '<button type="button" class="button button-secondary delete-template" data-template="' . esc_attr(json_encode($filename)) . '">' . __('Delete', 'legal-doc-automation') . '</button>';
                echo '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        }
    }
    
    /**
     * Quick template analysis
     */
    private function analyzeTemplateQuick($template_path) {
        try {
            // Simple count of merge tags - this is just for display
            $content = '';
            $zip = new ZipArchive();
            if ($zip->open($template_path) === true) {
                $content = $zip->getFromName('word/document.xml');
                $zip->close();
            }
            
            // Count both regular {$TAG} and HTML entity {&#36;TAG} patterns
            $count_regular = substr_count($content, '{$');
            $count_entity = substr_count($content, '{&#36;');
            $total_count = $count_regular + $count_entity;
            
            return $total_count > 0 ? $total_count . ' tags' : 'No tags found';
            
        } catch (Exception $e) {
            return 'Analysis failed';
        }
    }
    
    /**
     * Extract merge tags from template for auto-population
     */
    private function extractMergeTagsFromTemplate($template_path) {
        try {
            $merge_tags = array();
            $zip = new ZipArchive();
            
            if ($zip->open($template_path) === true) {
                // Get content from main document and all headers/footers
                $xml_files = array(
                    'word/document.xml',
                    'word/header1.xml',
                    'word/header2.xml', 
                    'word/header3.xml',
                    'word/footer1.xml',
                    'word/footer2.xml',
                    'word/footer3.xml'
                );
                
                foreach ($xml_files as $xml_file) {
                    $content = $zip->getFromName($xml_file);
                    if ($content !== false) {
                        // Extract both regular {$TAG} and HTML entity {&#36;TAG} patterns
                        // Updated regex to capture tags with modifiers and complex formatting
                        preg_match_all('/\{\$([^}]+)\}/', $content, $regular_matches);
                        preg_match_all('/\{\&#36;([^}]+)\}/', $content, $entity_matches);
                        
                        // Look for split tags across XML elements (common DOCX issue)
                        // This pattern captures tags that are broken across XML elements
                        preg_match_all('/\{\$([^<}]*?)(?:<[^>]*>[^<}]*?)*?\}/', $content, $split_matches);
                        
                        // Also look for incomplete tags that might be cut off
                        preg_match_all('/\{\$([A-Z_][A-Z0-9_]*)(?:\|[^<}]*)?(?:<[^>]*>[^<}]*?)*?(?:\}|$)/', $content, $incomplete_matches);
                        
                        // Debug logging
                        if (!empty($regular_matches[1]) || !empty($entity_matches[1]) || !empty($split_matches[1])) {
                            LDA_Logger::log("Found tags in {$xml_file}: Regular=" . count($regular_matches[1]) . 
                                          ", Entity=" . count($entity_matches[1]) . 
                                          ", Split=" . count($split_matches[1]));
                        }
                        
                        // Add regular tags
                        foreach ($regular_matches[1] as $tag) {
                            $clean_tag = $this->cleanMergeTag($tag);
                            if ($clean_tag) {
                                $merge_tags[$clean_tag] = $clean_tag;
                            }
                        }
                        
                        // Add entity tags
                        foreach ($entity_matches[1] as $tag) {
                            $clean_tag = $this->cleanMergeTag($tag);
                            if ($clean_tag) {
                                $merge_tags[$clean_tag] = $clean_tag;
                            }
                        }
                        
                        // Add split tags (tags broken across XML elements)
                        foreach ($split_matches[1] as $tag) {
                            $clean_tag = $this->cleanMergeTag($tag);
                            if ($clean_tag) {
                                $merge_tags[$clean_tag] = $clean_tag;
                            }
                        }
                        
                        // Add incomplete tags (tags that might be cut off)
                        foreach ($incomplete_matches[1] as $tag) {
                            $clean_tag = $this->cleanMergeTag($tag);
                            if ($clean_tag) {
                                $merge_tags[$clean_tag] = $clean_tag;
                            }
                        }
                    }
                }
                
                $zip->close();
            }
            
            $final_tags = array_keys($merge_tags);
            LDA_Logger::log("Total merge tags extracted: " . count($final_tags) . " - " . implode(', ', $final_tags));
            
            return $final_tags;
            
        } catch (Exception $e) {
            LDA_Logger::log("Error extracting merge tags from template: " . $e->getMessage());
            return array();
        }
    }
    
    /**
     * Clean merge tag by removing XML elements and extracting just the tag name
     */
    private function cleanMergeTag($tag) {
        // First, try to handle the tag as-is if it's already clean
        $clean_tag = trim($tag);
        
        // If the tag contains XML elements, extract the actual content
        if (strpos($tag, '<') !== false) {
            // Remove all XML tags and extract text content
            $clean_tag = preg_replace('/<[^>]*>/', '', $tag);
            $clean_tag = preg_replace('/\s+/', ' ', $clean_tag);
            $clean_tag = trim($clean_tag);
            
            // Handle complex split patterns like {$USR_</w:t></w:r><w:r...>Signatory</w:t></w:r><w:r...>_FN}
            if (empty($clean_tag) || strlen($clean_tag) < 3) {
                // Try a more aggressive approach to extract content between XML elements
                $parts = preg_split('/<[^>]*>/', $tag);
                $clean_parts = array();
                
                foreach ($parts as $part) {
                    $part = trim($part);
                    if (!empty($part) && !preg_match('/^[{}|]*$/', $part)) {
                        $clean_parts[] = $part;
                    }
                }
                
                if (!empty($clean_parts)) {
                    $clean_tag = implode('', $clean_parts);
                }
            }
        }
        
        // Remove any leading/trailing curly braces or dollar signs
        $clean_tag = preg_replace('/^[{$]*/', '', $clean_tag);
        $clean_tag = preg_replace('/[}]*$/', '', $clean_tag);
        $clean_tag = trim($clean_tag);
        
        // Remove any pipe modifiers and everything after the first pipe
        // This converts {$USR_ABN|phone_format:%2 %3 %3 %3} to {$USR_ABN}
        if (strpos($clean_tag, '|') !== false) {
            $clean_tag = substr($clean_tag, 0, strpos($clean_tag, '|'));
        }
        
        // Remove trailing underscores (invalid tags like {$PT2_ABN|phone_} become {$PT2_ABN})
        $clean_tag = rtrim($clean_tag, '_');
        
        // Handle specific patterns from the logs
        // Convert incomplete tags to complete ones based on common patterns
        $tag_mappings = array(
            'USR_B' => 'USR_Business',
            'PT2_B' => 'PT2_Business', 
            'USR_N' => 'USR_Name',
            'PT2_N' => 'PT2_Name',
            'USR_A' => 'USR_ABN',
            'PT2_A' => 'PT2_ABN',
            'C' => 'Concept',
            'P' => 'Pmt_Purpose'
        );
        
        if (isset($tag_mappings[$clean_tag])) {
            $clean_tag = $tag_mappings[$clean_tag];
        }
        
        // Validate that it's a proper merge tag name
        // Must start with uppercase letter or underscore, followed by uppercase letters, numbers, or underscores
        if (preg_match('/^[A-Z_][A-Z0-9_]*$/', $clean_tag) && strlen($clean_tag) >= 2) {
            return $clean_tag;
        }
        
        return null;
    }
    
    
    /**
     * Display Google Drive status
     */
    private function displayGoogleDriveStatus() {
        $settings = get_option('lda_settings');
        
        if (!isset($settings['google_drive_enabled']) || !$settings['google_drive_enabled']) {
            echo '<p class="notice notice-warning inline">' . __('Google Drive integration is disabled.', 'legal-doc-automation') . '</p>';
            return;
        }
        
        // Check if Use-your-Drive plugin is active
        if (!is_plugin_active('use-your-drive/use-your-drive.php')) {
            echo '<div class="notice notice-error inline">';
            echo '<p><strong>' . __('Use-your-Drive Plugin: Not Active', 'legal-doc-automation') . '</strong></p>';
            echo '<p>' . __('Please install and activate the Use-your-Drive plugin to use Google Drive integration.', 'legal-doc-automation') . '</p>';
            echo '</div>';
            return;
        }
        
        try {
            // Use Use-your-Drive integration
            $gdrive = new LDA_GoogleDrive($settings);
            $status = $gdrive->testConnection();
            
            if ($status && $status['success']) {
                echo '<div class="notice notice-success inline">';
                echo '<p><strong>' . __('Google Drive Connection: OK', 'legal-doc-automation') . '</strong></p>';
                echo '<ul>';
                echo '<li>' . __('Method: Use-your-Drive Plugin', 'legal-doc-automation') . '</li>';
                if (isset($status['user_email'])) {
                    echo '<li>' . sprintf(__('Connected as: %s', 'legal-doc-automation'), $status['user_email']) . '</li>';
                }
                echo '<li>' . sprintf(__('Last checked: %s', 'legal-doc-automation'), current_time('Y-m-d H:i:s')) . '</li>';
                echo '</ul>';
                echo '</div>';
            } else {
                echo '<div class="notice notice-error inline">';
                echo '<p><strong>' . __('Google Drive Connection: Failed', 'legal-doc-automation') . '</strong></p>';
                $error_msg = isset($status['error']) ? $status['error'] : 'Unknown error';
                echo '<p>' . esc_html($error_msg) . '</p>';
                echo '<p>' . __('Please check your Use-your-Drive plugin configuration.', 'legal-doc-automation') . '</p>';
                echo '</div>';
            }
            
        } catch (Exception $e) {
            echo '<div class="notice notice-error inline">';
            echo '<p><strong>' . __('Google Drive Connection: Error', 'legal-doc-automation') . '</strong></p>';
            echo '<p>' . esc_html($e->getMessage()) . '</p>';
            echo '</div>';
        }
    }
    
    /**
     * Display recent logs
     */
    private function displayRecentLogs() {
        echo $this->getRecentLogsHTML();
    }

    /**
     * Gets HTML for recent logs.
     * @return string The logs formatted as HTML.
     */
    private function getRecentLogsHTML() {
        if (!class_exists('LDA_Logger')) {
            return '<p>' . __('Logger class not available.', 'legal-doc-automation') . '</p>';
        }
        
        $logs = LDA_Logger::getRecentLogs();
        
        if (empty($logs) || (isset($logs[0]) && strpos($logs[0], 'Log file not found') !== false)) {
            return '<p>' . __('No log entries found.', 'legal-doc-automation') . '</p>';
        }
        
        $html = '<div class="log-entries">';
        foreach (array_slice($logs, 0, 100) as $log) { // Show latest 100 entries
            preg_match('/\[(.*?)\]\s\[(.*?)\]\s(.*)/', $log, $matches);

            if (count($matches) === 4) {
                $timestamp = esc_html($matches[1]);
                $level     = esc_html($matches[2]);
                $message   = esc_html($matches[3]);

                $level_class = 'log-' . strtolower($level);
                $html .= '<div class="log-entry ' . $level_class . '">';
                $html .= '<span class="log-timestamp">' . $timestamp . '</span>';
                $html .= '<span class="log-level">' . $level . '</span>';
                $html .= '<span class="log-message">' . $message . '</span>';
                $html .= '</div>';
            }
        }
        $html .= '</div>';

        return $html;
    }
    
    /**
     * Display log statistics
     */
    private function displayLogStats() {
        if (!class_exists('LDA_Logger')) {
            return;
        }
        
        $stats = LDA_Logger::getLogStats(); // Get all log stats
        
        echo '<div class="log-stats">';
        echo '<div class="stat-item">';
        echo '<h4>' . __('Total Entries', 'legal-doc-automation') . '</h4>';
        echo '<span class="stat-number">' . $stats['total_entries'] . '</span>';
        echo '</div>';
        
        echo '<div class="stat-item error">';
        echo '<h4>' . __('Errors', 'legal-doc-automation') . '</h4>';
        echo '<span class="stat-number">' . $stats['error_count'] . '</span>';
        echo '</div>';
        
        echo '<div class="stat-item warning">';
        echo '<h4>' . __('Warnings', 'legal-doc-automation') . '</h4>';
        echo '<span class="stat-number">' . $stats['warning_count'] . '</span>';
        echo '</div>';
        
        if ($stats['latest_error']) {
            echo '<div class="latest-error">';
            echo '<h4>' . __('Latest Error', 'legal-doc-automation') . '</h4>';
            echo '<p><strong>' . $stats['latest_error']['timestamp'] . ':</strong> ' . esc_html($stats['latest_error']['message']) . '</p>';
            echo '</div>';
        }
        echo '</div>';
    }
    
    /**
     * Display system status
     */
    private function displaySystemStatus() {
        $requirements = array(
            'PHP Version' => array(
                'required' => LDA_MIN_PHP_VERSION,
                'current' => PHP_VERSION,
                'status' => version_compare(PHP_VERSION, LDA_MIN_PHP_VERSION, '>=')
            ),
            'WordPress Version' => array(
                'required' => LDA_MIN_WP_VERSION,
                'current' => get_bloginfo('version'),
                'status' => version_compare(get_bloginfo('version'), LDA_MIN_WP_VERSION, '>=')
            ),
            'DOCX Processing' => array(
                'required' => 'Available',
                'current' => LDA_SimpleDOCX::isAvailable() ? 'Available' : 'Not Found',
                'status' => LDA_SimpleDOCX::isAvailable()
            ),
            'ZIP Extension' => array(
                'required' => 'Available',
                'current' => extension_loaded('zip') ? 'Available' : 'Not Found',
                'status' => extension_loaded('zip')
            ),
            'XML Extension' => array(
                'required' => 'Available',
                'current' => extension_loaded('xml') ? 'Available' : 'Not Found',
                'status' => extension_loaded('xml')
            ),
            'mbstring Extension' => array(
                'required' => 'Available',
                'current' => extension_loaded('mbstring') ? 'Available' : 'Not Found',
                'status' => extension_loaded('mbstring')
            )
        );
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>' . __('Component', 'legal-doc-automation') . '</th><th>' . __('Status', 'legal-doc-automation') . '</th><th>' . __('Details', 'legal-doc-automation') . '</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($requirements as $name => $req) {
            $status_text = $req['status'] ? '<span class="status-ok">✓ OK</span>' : '<span class="status-error">✗ Failed</span>';
            $details = $req['current'];
            if (isset($req['required'])) {
                $details .= ' (Required: ' . $req['required'] . ')';
            }
            
            echo '<tr>';
            echo '<td><strong>' . $name . '</strong></td>';
            echo '<td>' . $status_text . '</td>';
            echo '<td>' . $details . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    /**
     * Display plugin status
     */
    private function displayPluginStatus() {
        if (!function_exists('is_plugin_active') || !function_exists('get_plugin_data')) {
            include_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }

        $required_plugins = array(
            'gravityforms/gravityforms.php' => 'Gravity Forms',
            'memberpress/memberpress.php' => 'MemberPress',
            'use-your-drive/use-your-drive.php' => 'WP Cloud Plugins - Use-your-Drive'
        );
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>' . esc_html__('Plugin', 'legal-doc-automation') . '</th><th>' . esc_html__('Status', 'legal-doc-automation') . '</th><th>' . esc_html__('Version', 'legal-doc-automation') . '</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($required_plugins as $plugin_path => $plugin_name) {
            $is_active = is_plugin_active($plugin_path);
            
            if ($is_active) {
                $status_text = '<span style="color: #4CAF50; font-weight: bold;">✓ ' . esc_html__('Active', 'legal-doc-automation') . '</span>';
            } else {
                $status_text = '<span style="color: #F44336; font-weight: bold;">✗ ' . esc_html__('Inactive', 'legal-doc-automation') . '</span>';
            }

            $version = esc_html__('N/A', 'legal-doc-automation');
            if (file_exists(WP_PLUGIN_DIR . '/' . $plugin_path)) {
                $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_path);
                $version = !empty($plugin_data['Version']) ? esc_html($plugin_data['Version']) : esc_html__('Unknown', 'legal-doc-automation');
            }
            
            echo '<tr>';
            echo '<td><strong>' . esc_html($plugin_name) . '</strong><br/><small><code>' . esc_html($plugin_path) . '</code></small></td>';
            echo '<td>' . $status_text . '</td>';
            echo '<td>' . $version . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '<p class="description" style="margin-top: 1em;">' . esc_html__('For full functionality, all plugins listed above must be installed and active. If a plugin is marked as "Inactive", please go to the main Plugins page and activate it. If you believe a plugin is active but it is showing as Inactive here, the file path listed under the plugin name may be incorrect. Please contact support for assistance.', 'legal-doc-automation') . '</p>';
    }
    
    /**
     * Display directory status
     */
    private function displayDirectoryStatus() {
        $upload_dir = wp_upload_dir();
        $directories = array(
            'Templates' => $upload_dir['basedir'] . '/lda-templates/',
            'Output' => $upload_dir['basedir'] . '/lda-output/',
            'Logs' => $upload_dir['basedir'] . '/lda-logs/',
            'Google Drive Fallback' => $upload_dir['basedir'] . '/lda-gdrive-fallback/'
        );
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>' . __('Directory', 'legal-doc-automation') . '</th><th>' . __('Status', 'legal-doc-automation') . '</th><th>' . __('Path', 'legal-doc-automation') . '</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($directories as $name => $path) {
            $exists = is_dir($path);
            $writable = $exists && is_writable($path);
            
            if ($exists && $writable) {
                $status = '<span class="status-ok">✓ OK</span>';
            } elseif ($exists) {
                $status = '<span class="status-warning">⚠ Not Writable</span>';
            } else {
                $status = '<span class="status-error">✗ Not Found</span>';
            }
            
            echo '<tr>';
            echo '<td><strong>' . $name . '</strong></td>';
            echo '<td>' . $status . '</td>';
            echo '<td><code>' . esc_html($path) . '</code></td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    /**
     * Display processing statistics
     */
    private function displayProcessingStats() {
        // This would require implementing statistics collection in the actual processing
        echo '<div class="processing-stats">';
        echo '<div class="stat-item">';
        echo '<h4>' . __('Documents Generated', 'legal-doc-automation') . '</h4>';
        echo '<span class="stat-number">--</span>';
        echo '<p class="description">' . __('Statistics collection coming soon', 'legal-doc-automation') . '</p>';
        echo '</div>';
        echo '</div>';
    }
    
    /**
     * Add form-specific settings to Gravity Forms
     */
    public function addFormSettings($fields, $form) {
        // Get current settings
        $enabled = gform_get_meta($form['id'], 'lda_enabled');
        $template_file = gform_get_meta($form['id'], 'lda_template_file');
        
        // Debug logging
        LDA_Logger::debug("Loading form settings - Enabled: " . ($enabled ? '1' : '0') . ", Template: " . $template_file);
        
        $fields[] = array(
            'title'  => __('Document Automation', 'legal-doc-automation'),
            'fields' => array(
                array(
                    'label'   => __('Enable Document Generation', 'legal-doc-automation'),
                    'type'    => 'checkbox',
                    'name'    => 'lda_enabled',
                    'tooltip' => __('Enable document generation for submissions to this form.', 'legal-doc-automation'),
                    'choices' => array(
                        array(
                            'label' => __('Enabled', 'legal-doc-automation'),
                            'name'  => 'lda_enabled',
                            'isSelected' => ($enabled === '1' || $enabled === 1 || $enabled === true),
                        ),
                    ),
                ),
                array(
                    'label'   => __('Template File', 'legal-doc-automation'),
                    'type'    => 'select',
                    'name'    => 'lda_template_file',
                    'tooltip' => __('Select the .docx template to be used for document generation for this form.', 'legal-doc-automation'),
                    'choices' => $this->get_template_choices($template_file),
                ),
            ),
        );

        return $fields;
    }
    
    /**
     * Gets a list of available templates for use in a select field.
     *
     * @param string $current_template The currently selected template file
     * @return array
     */
    private function get_template_choices($current_template = '') {
        $choices = array(
            array(
                'label' => __('Select a template', 'legal-doc-automation'),
                'value' => '',
                'isSelected' => empty($current_template)
            )
        );

        $template_folder = wp_upload_dir()['basedir'] . '/lda-templates/';
        if (is_dir($template_folder)) {
            $templates = glob($template_folder . '*.docx');
            foreach ($templates as $template) {
                $filename = basename($template);
                $choices[] = array(
                    'label' => $filename,
                    'value' => $filename,
                    'isSelected' => ($filename === $current_template)
                );
            }
        }

        return $choices;
    }

    /**
     * Save form-specific settings
     */
    public function saveFormSettings($form) {
        // Save the settings using Gravity Forms meta API
        if (isset($_POST['lda_template_file'])) {
            $raw_template_file = $_POST['lda_template_file'];
            $template_file = basename($raw_template_file);
            $template_file = trim($template_file, '"\'');
            $template_file = str_replace('\\', '', $template_file);
            gform_update_meta($form['id'], 'lda_template_file', $template_file);
            $form['lda_template_file'] = $template_file;
        }
        
        // Handle checkbox - if not in POST, it means unchecked
        $enabled = isset($_POST['lda_enabled']) ? '1' : '0';
        gform_update_meta($form['id'], 'lda_enabled', $enabled);
        $form['lda_enabled'] = $enabled;
        
        // Debug logging
        LDA_Logger::debug("Saving form settings - Enabled: " . $enabled . ", Template: " . (isset($_POST['lda_template_file']) ? $_POST['lda_template_file'] : 'not set'));
        
        return $form;
    }

    // --- New AJAX Handlers ---

    public function handleAjaxGetTemplates() {
        check_ajax_referer('lda_admin_nonce', 'nonce');
        // This is a simplified version for now.
        // A real version would scan the directory and return a list of files.
        wp_send_json_success(array());
    }

    public function handleAjaxTestTemplate() {
        try {
            check_ajax_referer('lda_admin_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Insufficient permissions');
            }
            
            $raw_template_file = $_POST['template_file'];
            $template_file = basename($raw_template_file);
            $template_file = trim($template_file, '"\'');
            $template_file = str_replace('\\', '', $template_file);
            $template_folder = wp_upload_dir()['basedir'] . '/lda-templates/';
            $template_path = $template_folder . $template_file;
            
            if (!file_exists($template_path)) {
                wp_send_json_error('Template file not found');
            }
            
            // Create sample merge data and add the associated form_id if it exists
            $sample_data = $this->generateSampleMergeData();
            
            $template_assignments = get_option('lda_template_assignments', array());
            $assigned_form_id = array_search($template_file, $template_assignments);

            if ($assigned_form_id) {
                LDA_Logger::log("Test initiated for template '{$template_file}' assigned to form ID: {$assigned_form_id}.");
                $sample_data['form_id'] = $assigned_form_id;
            } else {
                LDA_Logger::log("Test initiated for template '{$template_file}' with no assigned form. Field mappings will not be applied.");
            }

            // Test the merge process
            $upload_dir = wp_upload_dir();
            if (empty($upload_dir['basedir'])) {
                throw new Exception('WordPress upload directory not available');
            }
            
            $output_folder = $upload_dir['basedir'] . '/lda-output/';
            $output_filename = 'test_' . time() . '_' . $template_file;
            $output_path = $output_folder . $output_filename;
            
            LDA_Logger::log("Test output folder: " . $output_folder);
            LDA_Logger::log("Test output filename: " . $output_filename);
            LDA_Logger::log("Test output path: " . $output_path);
            
            // Use our enhanced merge engine
            $settings = get_option('lda_settings', array());
            $merge_engine = new LDA_MergeEngine($settings);
            $result = $merge_engine->mergeDocument($template_path, $sample_data, $output_path);
            
            if ($result['success']) {
                $test_results = array(
                    'test_file' => $output_filename,
                    'sample_data' => $sample_data,
                    'message' => 'Template test completed successfully. A test document has been generated with sample data.',
                    'email_sent' => false,
                    'google_drive_uploaded' => false,
                    'email_error' => '',
                    'gdrive_error' => '',
                    'pdf_generated' => false,
                    'pdf_file' => '',
                    'pdf_error' => ''
                );
                
                // Generate PDF if PDF output is enabled
                if (isset($settings['enable_pdf_output']) && $settings['enable_pdf_output']) {
                    try {
                        $pdf_handler = new LDA_PDFHandler($settings);
                        $pdf_filename = str_replace('.docx', '.pdf', $output_filename);
                        $pdf_path = $output_folder . $pdf_filename;
                        
                        $pdf_result = $pdf_handler->convertDocxToPdf($output_path, $pdf_path);
                        
                        if ($pdf_result['success']) {
                            $test_results['pdf_generated'] = true;
                            $test_results['pdf_file'] = $pdf_filename;
                            LDA_Logger::log("PDF generated successfully: " . $pdf_path);
                        } else {
                            $test_results['pdf_error'] = $pdf_result['error'];
                            LDA_Logger::log("PDF generation failed: " . $pdf_result['error']);
                        }
                    } catch (Exception $e) {
                        $test_results['pdf_error'] = 'PDF generation error: ' . $e->getMessage();
                        LDA_Logger::log("PDF generation exception: " . $e->getMessage());
                    }
                }
                
                // Test email functionality if test email is configured
                $test_email = get_option('lda_test_email', '');
                if (!empty($test_email) && is_email($test_email)) {
                    $email_result = $this->sendTestEmail($test_email, $output_path, $output_filename, $template_file, $test_results);
                    $test_results['email_sent'] = $email_result['success'];
                    if (!$email_result['success']) {
                        $test_results['email_error'] = $email_result['error'];
                    }
                }
                
                // Test Google Drive functionality if enabled
                if (isset($settings['google_drive_enabled']) && $settings['google_drive_enabled']) {
                    $gdrive_result = $this->uploadTestToGoogleDrive($output_path, $output_filename, $template_file);
                    $test_results['google_drive_uploaded'] = $gdrive_result['success'];
                    if (!$gdrive_result['success']) {
                        $test_results['gdrive_error'] = $gdrive_result['error'];
                    }
                }
                
                wp_send_json_success($test_results);
            } else {
                wp_send_json_error($result);
            }
            
        } catch (Exception $e) {
            wp_send_json_error('Test failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Send test email with document attachment
     */
    private function sendTestEmail($test_email, $file_path, $filename, $template_name, $test_results = array()) {
        try {
            if (!class_exists('LDA_EmailHandler')) {
                return array('success' => false, 'error' => 'Email handler class not found');
            }
            
            $settings = get_option('lda_settings', array());
            $email_handler = new LDA_EmailHandler($settings);
            
            $subject = 'Test Document: ' . $template_name;
            $message = "This is a test email from the Legal Document Automation plugin.\n\n";
            $message .= "Template: " . $template_name . "\n";
            $message .= "Generated: " . date('Y-m-d H:i:s') . "\n";
            $message .= "This document was created with sample data to test the template functionality.\n\n";
            
            // Add PDF information if generated
            if (isset($test_results['pdf_generated']) && $test_results['pdf_generated']) {
                $message .= "PDF Version: Generated successfully\n";
            } elseif (isset($test_results['pdf_error']) && !empty($test_results['pdf_error'])) {
                $message .= "PDF Generation: Failed - " . $test_results['pdf_error'] . "\n";
            }
            
            $message .= "\nIf you received this email, the email functionality is working correctly.";
            
            // Prepare attachments - include both DOCX and PDF if available
            $attachments = array($file_path);
            
            if (isset($test_results['pdf_generated']) && $test_results['pdf_generated'] && !empty($test_results['pdf_file'])) {
                $upload_dir = wp_upload_dir();
                $pdf_path = $upload_dir['basedir'] . '/lda-output/' . $test_results['pdf_file'];
                if (file_exists($pdf_path)) {
                    $attachments[] = $pdf_path;
                    LDA_Logger::log("Including PDF attachment: " . $pdf_path);
                }
            }
            
            $result = $email_handler->send_document_email($test_email, $subject, $message, $file_path, $attachments);
            
            if ($result) {
                return array('success' => true, 'message' => 'Test email sent successfully');
            } else {
                return array('success' => false, 'error' => 'Failed to send test email');
            }
            
        } catch (Exception $e) {
            return array('success' => false, 'error' => 'Email test failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Upload test document to Google Drive
     */
    private function uploadTestToGoogleDrive($file_path, $filename, $template_name) {
        try {
            $settings = get_option('lda_settings', array());
            
            // Determine which Google Drive method to use
            $gdrive_method = isset($settings['google_drive_method']) ? $settings['google_drive_method'] : 'use_your_drive';
            
            $gdrive_class = null;
            switch ($gdrive_method) {
                case 'use_your_drive':
                    if (class_exists('LDA_GoogleDrive')) {
                        $gdrive_class = new LDA_GoogleDrive($settings);
                    }
                    break;
                case 'real_google_drive':
                    if (class_exists('LDA_RealGoogleDrive')) {
                        $gdrive_class = new LDA_RealGoogleDrive($settings);
                    }
                    break;
                case 'simple_google_drive':
                    if (class_exists('LDA_SimpleGoogleDrive')) {
                        $gdrive_class = new LDA_SimpleGoogleDrive($settings);
                    }
                    break;
            }
            
            if (!$gdrive_class) {
                return array('success' => false, 'error' => 'Google Drive class not found for method: ' . $gdrive_method);
            }
            
            // Create a test folder name
            $test_folder_name = 'Test Documents - ' . date('Y-m-d');
            
            // Upload the file using the correct method
            if (method_exists($gdrive_class, 'uploadFile')) {
                $result = $gdrive_class->uploadFile($file_path, null, $filename);
            } else {
                return array('success' => false, 'error' => 'No upload method found in Google Drive class');
            }
            
            if ($result && isset($result['success']) && $result['success']) {
                return array('success' => true, 'message' => 'Test document uploaded to Google Drive successfully', 'url' => $result['url'] ?? '');
            } else {
                $error = isset($result['error']) ? $result['error'] : 'Unknown error';
                return array('success' => false, 'error' => 'Google Drive upload failed: ' . $error);
            }
            
        } catch (Exception $e) {
            return array('success' => false, 'error' => 'Google Drive test failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Handle AJAX request to save test email
     */
    public function handleAjaxSaveTestEmail() {
        check_ajax_referer('lda_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $test_email = isset($_POST['test_email']) ? sanitize_email($_POST['test_email']) : '';
        
        if (!empty($test_email) && !is_email($test_email)) {
            wp_send_json_error('Invalid email address');
        }
        
        update_option('lda_test_email', $test_email);
        
        wp_send_json_success(array(
            'message' => 'Test email address saved successfully',
            'email' => $test_email
        ));
    }
    
    /**
     * Handle auto-population of merge tags
     */
    private function handleAutoPopulate() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to auto-populate merge tags.', 'legal-doc-automation'));
        }
        
        $form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
        $template = isset($_GET['template']) ? basename($_GET['template']) : '';
        
        if ($form_id > 0 && !empty($template)) {
            $template_folder = wp_upload_dir()['basedir'] . '/lda-templates/';
            $template_path = $template_folder . $template;
            
            if (file_exists($template_path)) {
                $template_tags = $this->extractMergeTagsFromTemplate($template_path);
                
                if (!empty($template_tags)) {
                    // Clear existing mappings for this form to force auto-population
                    $mappings = get_option('lda_field_mappings', array());
                    unset($mappings[$form_id]);
                    update_option('lda_field_mappings', $mappings);
                    
                    echo '<div class="notice notice-success"><p>' . 
                         sprintf(__('Auto-populated %d merge tags from template "%s". Please select the corresponding Gravity Forms fields.', 'legal-doc-automation'), 
                                count($template_tags), $template) . 
                         '</p></div>';
                } else {
                    echo '<div class="notice notice-warning"><p>' . 
                         sprintf(__('No merge tags found in template "%s".', 'legal-doc-automation'), $template) . 
                         '</p></div>';
                }
            } else {
                echo '<div class="notice notice-error"><p>' . 
                     sprintf(__('Template file "%s" not found.', 'legal-doc-automation'), $template) . 
                     '</p></div>';
            }
        }
    }
    
    /**
     * Assign template to form
     */
    private function assignTemplateToForm() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to assign templates.', 'legal-doc-automation'));
        }
        
        $form_id = intval($_POST['form_id']);
        $template = basename($_POST['template']); // Remove any path components, preserve filename
        
        if ($form_id > 0 && !empty($template)) {
            $template_assignments = get_option('lda_template_assignments', array());
            $template_assignments[$form_id] = $template;
            update_option('lda_template_assignments', $template_assignments);
            
            echo '<div class="notice notice-success"><p>' . __('Template assigned successfully!', 'legal-doc-automation') . '</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>' . __('Invalid form or template selected.', 'legal-doc-automation') . '</p></div>';
        }
    }
    
    /**
     * Save field mapping configuration
     */
    private function saveFieldMapping() {
        $form_id = intval($_POST['form_id']);
        $mappings = get_option('lda_field_mappings', array());
        
        // Get manual mappings from form
        $merge_tags = isset($_POST['merge_tags']) ? $_POST['merge_tags'] : array();
        $field_ids = isset($_POST['field_ids']) ? $_POST['field_ids'] : array();
        
        $form_mappings = array();
        
        // Process each mapping row
        for ($i = 0; $i < count($merge_tags); $i++) {
            $merge_tag = isset($merge_tags[$i]) ? sanitize_text_field($merge_tags[$i]) : '';
            $field_id = isset($field_ids[$i]) ? sanitize_text_field($field_ids[$i]) : '';
            
            // Clean up merge tag (remove {$ and } if present)
            $merge_tag = trim($merge_tag);
            if (strpos($merge_tag, '{$') === 0) {
                $merge_tag = substr($merge_tag, 2);
            }
            if (substr($merge_tag, -1) === '}') {
                $merge_tag = substr($merge_tag, 0, -1);
            }
            
            // Only save if both merge tag and field ID are provided
            if (!empty($merge_tag) && !empty($field_id)) {
                $form_mappings[$merge_tag] = $field_id;
            }
        }
        
        $mappings[$form_id] = $form_mappings;
        update_option('lda_field_mappings', $mappings);
        
        // Add success message with redirect to prevent resubmission
        echo '<div class="notice notice-success"><p>' . __('Field mappings saved successfully!', 'legal-doc-automation') . '</p></div>';
        
        // Redirect to prevent form resubmission
        wp_redirect(add_query_arg(array('tab' => 'field_mapping', 'form_id' => $form_id, 'saved' => '1'), admin_url('admin.php?page=legal-doc-automation')));
        exit;
    }
    
    /**
     * Get standard Webmerge merge tags
     */
    private function getStandardMergeTags() {
        return array(
            'USR_Business' => 'User Business Name',
            'PT2_Business' => 'Counterparty Business Name',
            'USR_Name' => 'User Full Name',
            'PT2_Name' => 'Counterparty Full Name',
            'USR_ABN' => 'User ABN',
            'PT2_ABN' => 'Counterparty ABN',
            'USR_ABV' => 'User Business Abbreviation',
            'PT2_ABV' => 'Counterparty Business Abbreviation',
            'REF_State' => 'State/Jurisdiction',
            'Concept' => 'Business Concept/Description',
            'Effective_Date' => 'Effective Date',
            'Purpose' => 'Purpose of Agreement',
            'USR_Sign' => 'User Signature Name',
            'USR_Sign_Email' => 'User Signature Email',
            'USR_Sign_Prefix' => 'User Signature Prefix (Mr/Mrs/Dr)',
            'USR_Sign_First' => 'User Signature First Name',
            'USR_Sign_Middle' => 'User Signature Middle Name',
            'PT2_Sign' => 'Counterparty Signature Name',
            'PT2_Sign_Email' => 'Counterparty Signature Email',
            'PT2_Sign_Prefix' => 'Counterparty Signature Prefix (Mr/Mrs/Dr)',
            'PT2_Sign_First' => 'Counterparty Signature First Name',
            'USR_Address' => 'User Address',
            'PT2_Address' => 'Counterparty Address',
            'USR_Phone' => 'User Phone Number',
            'PT2_Phone' => 'Counterparty Phone Number',
            'USR_Email' => 'User Email Address',
            'PT2_Email' => 'Counterparty Email Address',
            'FormTitle' => 'Form/Agreement Title',
            'CounterpartyFirstName' => 'Counterparty First Name',
            'CounterpartyLastName' => 'Counterparty Last Name',
            'UserFirstName' => 'User First Name',
            'UserLastName' => 'User Last Name',
            'Eff_date' => 'Effective Date (Alternative)'
        );
    }
    
    /**
     * Render field mapping rows
     */
    private function renderFieldMappingRows($form_id, $mappings) {
        $merge_tags = $this->getStandardMergeTags();
        $form_fields = $this->getGravityFormFields($form_id);
        $current_mappings = isset($mappings[$form_id]) ? $mappings[$form_id] : array();
        
        foreach ($merge_tags as $tag => $description) {
            $current_field = isset($current_mappings[$tag]) ? $current_mappings[$tag] : '';
            ?>
            <tr>
                <td><strong>{$<?php echo $tag; ?>}</strong></td>
                <td>
                    <select name="field_<?php echo $tag; ?>">
                        <option value=""><?php _e('Select field...', 'legal-doc-automation'); ?></option>
                        <?php foreach ($form_fields as $field_id => $field_label): ?>
                            <option value="<?php echo $field_id; ?>" <?php selected($current_field, $field_id); ?>>
                                <?php echo esc_html($field_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
                <td><?php echo esc_html($description); ?></td>
            </tr>
            <?php
        }
    }
    
    /**
     * Get Gravity Forms fields for a specific form
     */
    private function getGravityFormFields($form_id) {
        if (!class_exists('GFAPI')) {
            LDA_Logger::log("GFAPI class not available");
            return array();
        }
        
        $form = GFAPI::get_form($form_id);
        if (is_wp_error($form)) {
            LDA_Logger::log("Error getting form {$form_id}: " . $form->get_error_message());
            return array();
        }
        
        if (empty($form['fields'])) {
            LDA_Logger::log("No fields found for form {$form_id}");
            return array();
        }
        
        $fields = array();
        LDA_Logger::log("Processing " . count($form['fields']) . " fields for form {$form_id}");
        
        foreach ($form['fields'] as $field) {
            // Skip certain field types that shouldn't be mapped
            $skip_types = array('page', 'section', 'html', 'captcha', 'honeypot');
            if (in_array($field->type, $skip_types)) {
                LDA_Logger::log("Skipping field ID {$field->id} of type {$field->type}");
                continue;
            }
            
            // Debug: Log all field types we're processing
            LDA_Logger::log("Processing field ID {$field->id}, type: {$field->type}, label: " . ($field->label ?: 'no label'));
            
            // Special debug for Field ID 8
            if ($field->id == 8) {
                LDA_Logger::log("*** FOUND FIELD ID 8 *** Type: {$field->type}, Label: " . ($field->label ?: 'no label'));
                LDA_Logger::log("Field ID 8 details: " . print_r($field, true));
            }
            
            // Handle Name fields specially - they have sub-fields
            if ($field->type === 'name') {
                LDA_Logger::log("Processing Name field ID {$field->id} with sub-fields");
                
                // Get the main name field
                $field_label = !empty($field->label) ? $field->label : 'Name';
                $display_label = $field_label . ' (Name - ID: ' . $field->id . ')';
                $fields[$field->id] = $display_label;
                LDA_Logger::log("Added main Name field ID {$field->id}: {$display_label}");
                
                // Add sub-fields for enabled name components
                if (isset($field->inputs) && is_array($field->inputs)) {
                    foreach ($field->inputs as $input) {
                        $sub_field_id = $input['id'];
                        $sub_field_label = $input['label'] ?: $input['name'];
                        $sub_display_label = $field_label . ' - ' . $sub_field_label . ' (Name - ID: ' . $sub_field_id . ')';
                        $fields[$sub_field_id] = $sub_display_label;
                        LDA_Logger::log("Added Name sub-field ID {$sub_field_id}: {$sub_display_label}");
                    }
                }
                continue;
            }
            
            // Handle Checkbox fields specially - they have individual options
            if ($field->type === 'checkbox') {
                LDA_Logger::log("Processing Checkbox field ID {$field->id} with options");
                
                // Add the main checkbox field
                $field_label = !empty($field->label) ? $field->label : 'Checkbox';
                $display_label = $field_label . ' (Checkbox - ID: ' . $field->id . ')';
                $fields[$field->id] = $display_label;
                LDA_Logger::log("Added main Checkbox field ID {$field->id}: {$display_label}");
                
                // Add individual checkbox options
                if (isset($field->choices) && is_array($field->choices)) {
                    foreach ($field->choices as $choice) {
                        $choice_id = $field->id . '.' . $choice['value'];
                        $choice_label = $choice['text'] ?: $choice['value'];
                        $choice_display_label = $field_label . ' - ' . $choice_label . ' (Checkbox - ID: ' . $choice_id . ')';
                        $fields[$choice_id] = $choice_display_label;
                        LDA_Logger::log("Added Checkbox option ID {$choice_id}: {$choice_display_label}");
                    }
                }
                continue;
            }
            
            // Handle other field types normally
            $field_label = !empty($field->label) ? $field->label : $field->type;
            $field_type = ucfirst($field->type);
            $display_label = $field_label . ' (' . $field_type . ' - ID: ' . $field->id . ')';
            
            $fields[$field->id] = $display_label;
            LDA_Logger::log("Added field ID {$field->id}: {$display_label}");
        }
        
        LDA_Logger::log("Total fields returned: " . count($fields));
        return $fields;
    }
    
    /**
     * Display current mappings
     */
    private function displayCurrentMappings($form_id, $mappings) {
        $current_mappings = $mappings[$form_id];
        $merge_tags = $this->getStandardMergeTags();
        $form_fields = $this->getGravityFormFields($form_id);
        
        echo '<table class="widefat">';
        echo '<thead><tr><th>Merge Tag</th><th>Mapped Field</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($current_mappings as $tag => $field_id) {
            $field_label = isset($form_fields[$field_id]) ? $form_fields[$field_id] : 'Unknown Field';
            echo '<tr>';
            echo '<td><code>{$' . $tag . '}</code></td>';
            echo '<td>' . esc_html($field_label) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    /**
     * Render manual mapping rows
     */
    private function renderManualMappingRows($form_id, $mappings) {
        $form_fields = $this->getGravityFormFields($form_id);
        $current_mappings = isset($mappings[$form_id]) ? $mappings[$form_id] : array();
        
        // Get selected template for auto-population
        $selected_template = isset($_GET['template']) ? basename($_GET['template']) : '';
        $template_assignments = get_option('lda_template_assignments', array());
        
        // If no template selected but form has an assigned template, use that
        if ($form_id > 0 && empty($selected_template) && isset($template_assignments[$form_id])) {
            $selected_template = $template_assignments[$form_id];
        }
        
        // Auto-populate with merge tags from template if no existing mappings
        if (empty($current_mappings) && !empty($selected_template)) {
            $template_folder = wp_upload_dir()['basedir'] . '/lda-templates/';
            $template_path = $template_folder . $selected_template;
            
            if (file_exists($template_path)) {
                $template_tags = $this->extractMergeTagsFromTemplate($template_path);
                
                if (!empty($template_tags)) {
                    // Show count of auto-detected tags
                    echo '<div class="notice notice-info inline" style="margin-bottom: 15px;">';
                    echo '<p><strong>' . sprintf(__('Auto-detected %d merge tags from template:', 'legal-doc-automation'), count($template_tags)) . '</strong></p>';
                    echo '</div>';
                    
                    $index = 0;
                    foreach ($template_tags as $tag) {
                        echo '<tr class="mapping-row">';
                        echo '<td><input type="text" name="merge_tags[' . $index . ']" value="{$' . esc_attr($tag) . '}" class="regular-text" style="width: 100%;" /></td>';
                        echo '<td>';
                        echo '<select name="field_ids[' . $index . ']" style="width: 100%;">';
                        echo '<option value="">' . __('Select field...', 'legal-doc-automation') . '</option>';
                        foreach ($form_fields as $field_id => $field_label) {
                            echo '<option value="' . $field_id . '">' . esc_html($field_label) . '</option>';
                        }
                        echo '</select>';
                        echo '</td>';
                        echo '<td><button type="button" class="button remove-row" style="color: #d63638;">' . __('−', 'legal-doc-automation') . '</button></td>';
                        echo '</tr>';
                        $index++;
                    }
                    
                    // Add one empty row for additional mappings
                    echo '<tr class="mapping-row">';
                    echo '<td><input type="text" name="merge_tags[' . $index . ']" placeholder="{$USR_Name}" class="regular-text" style="width: 100%;" /></td>';
                    echo '<td>';
                    echo '<select name="field_ids[' . $index . ']" style="width: 100%;">';
                    echo '<option value="">' . __('Select field...', 'legal-doc-automation') . '</option>';
                    foreach ($form_fields as $field_id => $field_label) {
                        echo '<option value="' . $field_id . '">' . esc_html($field_label) . '</option>';
                    }
                    echo '</select>';
                    echo '</td>';
                    echo '<td><button type="button" class="button remove-row" style="color: #d63638;">' . __('−', 'legal-doc-automation') . '</button></td>';
                    echo '</tr>';
                    
                    return; // Exit early since we've auto-populated
                }
            }
        }
        
        // If no existing mappings and no auto-population, show one empty row
        if (empty($current_mappings)) {
            echo '<tr class="mapping-row">';
            echo '<td><input type="text" name="merge_tags[0]" placeholder="{$USR_Name}" class="regular-text" style="width: 100%;" /></td>';
            echo '<td>';
            echo '<select name="field_ids[0]" style="width: 100%;">';
            echo '<option value="">' . __('Select field...', 'legal-doc-automation') . '</option>';
            foreach ($form_fields as $field_id => $field_label) {
                echo '<option value="' . $field_id . '">' . esc_html($field_label) . '</option>';
            }
            echo '</select>';
            echo '</td>';
            echo '<td><button type="button" class="button remove-row" style="color: #d63638;">' . __('−', 'legal-doc-automation') . '</button></td>';
            echo '</tr>';
        } else {
            // Show existing mappings
            $index = 0;
            foreach ($current_mappings as $tag => $field_id) {
                echo '<tr class="mapping-row">';
                echo '<td><input type="text" name="merge_tags[' . $index . ']" value="{$' . esc_attr($tag) . '}" class="regular-text" style="width: 100%;" /></td>';
                echo '<td>';
                echo '<select name="field_ids[' . $index . ']" style="width: 100%;">';
                echo '<option value="">' . __('Select field...', 'legal-doc-automation') . '</option>';
                foreach ($form_fields as $fid => $field_label) {
                    $selected = ($fid == $field_id) ? 'selected' : '';
                    echo '<option value="' . $fid . '" ' . $selected . '>' . esc_html($field_label) . '</option>';
                }
                echo '</select>';
                echo '</td>';
                echo '<td><button type="button" class="button remove-row" style="color: #d63638;">' . __('−', 'legal-doc-automation') . '</button></td>';
                echo '</tr>';
                $index++;
            }
        }
    }
    
    /**
     * Populate form options for dropdown
     */
    private function populateFormOptions($selected_form = 0) {
        if (!class_exists('GFAPI')) {
            echo '<option value="">' . __('Gravity Forms not available', 'legal-doc-automation') . '</option>';
            return;
        }
        
        $forms = GFAPI::get_forms();
        if (is_wp_error($forms)) {
            echo '<option value="">' . __('Error loading forms', 'legal-doc-automation') . '</option>';
            return;
        }
        
        if (empty($forms)) {
            echo '<option value="">' . __('No forms found', 'legal-doc-automation') . '</option>';
            return;
        }
        
        foreach ($forms as $form) {
            $selected = selected($selected_form, $form['id'], false);
            echo '<option value="' . $form['id'] . '" ' . $selected . '>';
            echo esc_html($form['title'] . ' (ID: ' . $form['id'] . ')');
            echo '</option>';
        }
    }
    
    /**
     * Populate template options for dropdown
     */
    private function populateTemplateOptions($selected_template = '') {
        $template_folder = wp_upload_dir()['basedir'] . '/lda-templates/';
        
        if (!is_dir($template_folder)) {
            echo '<option value="">' . __('No templates found', 'legal-doc-automation') . '</option>';
            return;
        }
        
        $templates = glob($template_folder . '*.docx');
        if (empty($templates)) {
            echo '<option value="">' . __('No templates found', 'legal-doc-automation') . '</option>';
            return;
        }
        
        foreach ($templates as $template) {
            $filename = basename($template);
            $selected = selected($selected_template, $filename, false);
            echo '<option value="' . esc_attr($filename) . '" ' . $selected . '>' . esc_html($filename) . '</option>';
        }
    }
    
    /**
     * Generate sample merge data for testing
     */
    private function generateSampleMergeData() {
        return array(
            'USR_Name' => 'John Smith',
            'PT2_Name' => 'Jane Doe',
            'USR_Business' => 'Smith & Associates',
            'PT2_Business' => 'Doe Enterprises',
            'USR_ABN' => '12345678901',
            'PT2_ABN' => '98765432109',
            'USR_ABV' => 'S&A',
            'PT2_ABV' => 'DE',
            'USR_Address' => '123 Business Street, Sydney NSW 2000',
            'PT2_Address' => '456 Corporate Avenue, Melbourne VIC 3000',
            'USR_Email' => 'john@smithassociates.com',
            'PT2_Email' => 'jane@doeenterprises.com',
            'USR_Phone' => '02 1234 5678',
            'PT2_Phone' => '03 9876 5432',
            'EffectiveDate' => date('d F Y'),
            'FormTitle' => 'Test Agreement',
            'Concept' => 'This is a test concept for template validation.',
            'UserFirstName' => 'John',
            'UserLastName' => 'Smith',
            'CounterpartyFirstName' => 'Jane',
            'CounterpartyLastName' => 'Doe',

            // Add data for conditional logic testing based on user feedback
            'Pmt_Services' => 'yes1',
            'Pmt_Agreements' => 'no', // Set to a non-matching value to test 'false' condition
            'Pmt_Other' => 'yes4',
            'Pmt_Relations' => 'yes3',
            'Pmt_Purpose' => 'This is the test purpose for the agreement.'
        );
    }

    public function handleAjaxTestProcessing() {
        check_ajax_referer('lda_admin_nonce', 'nonce');
        wp_send_json_success('Processing test functionality coming soon!');
    }

    public function handleAjaxTestModifier() {
        try {
        check_ajax_referer('lda_admin_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                wp_send_json_error('Insufficient permissions');
            }
            
            $expression = sanitize_text_field($_POST['expression']);
            $test_value = sanitize_text_field($_POST['test_value']);
            
            if (empty($expression) || empty($test_value)) {
                wp_send_json_error('Both expression and test value are required');
            }
            
            // Parse the modifier expression
            $result = $this->testModifierExpression($expression, $test_value);
            
            wp_send_json_success($result);
            
        } catch (Exception $e) {
            wp_send_json_error('Modifier test failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Test modifier expressions
     */
    private function testModifierExpression($expression, $test_value) {
        $result = array(
            'original_expression' => $expression,
            'test_value' => $test_value,
            'processed_value' => $test_value,
            'modifiers_applied' => array(),
            'success' => true,
            'error' => null
        );
        
        try {
            // Extract modifiers from expression like {$Field|modifier1|modifier2}
            if (preg_match('/\{\$[^|]+\|(.+)\}/', $expression, $matches)) {
                $modifier_part = $matches[1];
                $modifiers = explode('|', $modifier_part);
                
                $processed_value = $test_value;
                
                foreach ($modifiers as $modifier) {
                    $modifier = trim($modifier);
                    $before_value = $processed_value;
                    
                    // Apply different modifier types
                    if (strpos($modifier, 'upper') === 0) {
                        $processed_value = strtoupper($processed_value);
                        $result['modifiers_applied'][] = 'upper: ' . $before_value . ' → ' . $processed_value;
                    } elseif (strpos($modifier, 'lower') === 0) {
                        $processed_value = strtolower($processed_value);
                        $result['modifiers_applied'][] = 'lower: ' . $before_value . ' → ' . $processed_value;
                    } elseif (strpos($modifier, 'ucwords') === 0) {
                        $processed_value = ucwords($processed_value);
                        $result['modifiers_applied'][] = 'ucwords: ' . $before_value . ' → ' . $processed_value;
                    } elseif (strpos($modifier, 'phone_format') === 0) {
                        $format = str_replace('phone_format:', '', $modifier);
                        $format = trim($format, '"');
                        $processed_value = $this->formatPhoneNumber($processed_value, $format);
                        $result['modifiers_applied'][] = 'phone_format: ' . $before_value . ' → ' . $processed_value;
                    } elseif (strpos($modifier, 'date_format') === 0) {
                        $format = str_replace('date_format:', '', $modifier);
                        $format = trim($format, '"');
                        $processed_value = $this->formatDate($processed_value, $format);
                        $result['modifiers_applied'][] = 'date_format: ' . $before_value . ' → ' . $processed_value;
                    } elseif (strpos($modifier, 'replace') === 0) {
                        // Handle replace:old,new format
                        $replace_parts = explode(':', $modifier, 2);
                        if (count($replace_parts) === 2) {
                            $replace_data = explode(',', $replace_parts[1], 2);
                            if (count($replace_data) === 2) {
                                $old = trim($replace_data[0], '"');
                                $new = trim($replace_data[1], '"');
                                $processed_value = str_replace($old, $new, $processed_value);
                                $result['modifiers_applied'][] = 'replace: ' . $before_value . ' → ' . $processed_value;
                            }
                        }
                    } else {
                        $result['modifiers_applied'][] = 'unknown modifier: ' . $modifier;
                    }
                }
                
                $result['processed_value'] = $processed_value;
            } else {
                $result['error'] = 'Invalid modifier expression format';
                $result['success'] = false;
            }
            
        } catch (Exception $e) {
            $result['error'] = $e->getMessage();
            $result['success'] = false;
        }
        
        return $result;
    }
    
    /**
     * Format phone number
     */
    private function formatPhoneNumber($phone, $format) {
        // Remove all non-digits
        $digits = preg_replace('/[^0-9]/', '', $phone);
        
        // Apply format like "%2 %3 %3 %3" for 02 1234 5678
        $formatted = $format;
        $digit_index = 1;
        
        for ($i = 0; $i < strlen($digits); $i++) {
            $formatted = str_replace('%' . $digit_index, $digits[$i], $formatted);
            $digit_index++;
        }
        
        return $formatted;
    }
    
    /**
     * Format date
     */
    private function formatDate($date, $format) {
        try {
            $timestamp = strtotime($date);
            if ($timestamp === false) {
                return $date; // Return original if can't parse
            }
            return date($format, $timestamp);
        } catch (Exception $e) {
            return $date;
        }
    }

    public function handleAjaxTestEmail() {
        check_ajax_referer('lda_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to test emails.', 'legal-doc-automation'));
        }
        
        $test_email = sanitize_email($_POST['test_email']);
        if (!is_email($test_email)) {
            wp_send_json_error(__('Invalid email address provided.', 'legal-doc-automation'));
        }
        
        try {
            $settings = get_option('lda_settings', array());
            $email_handler = new LDA_EmailHandler($settings);
            
            // Create a test document
            $test_content = "This is a test document generated by Legal Document Automation plugin.\n\n";
            $test_content .= "Test Date: " . date('Y-m-d H:i:s') . "\n";
            $test_content .= "Test Email: " . $test_email . "\n";
            $test_content .= "Site: " . get_bloginfo('name') . "\n\n";
            $test_content .= "If you receive this email, the email configuration is working correctly!";
            
            // Create a temporary test file
            $upload_dir = wp_upload_dir();
            $test_file = $upload_dir['basedir'] . '/lda-output/test-email-' . time() . '.txt';
            file_put_contents($test_file, $test_content);
            
            // Send test email using custom subject from settings
            $subject = !empty($settings['email_subject']) ? $settings['email_subject'] : 'Test Email - Legal Document Automation';
            $message = !empty($settings['email_message']) ? $settings['email_message'] : 'This is a test email from the Legal Document Automation plugin. Please find the test document attached.';
            
            $result = $email_handler->send_document_email($test_email, $subject, $message, $test_file);
            
            // Clean up test file
            if (file_exists($test_file)) {
                unlink($test_file);
            }
            
            if ($result['success']) {
                wp_send_json_success(__('Test email sent successfully to: ', 'legal-doc-automation') . $test_email);
            } else {
                wp_send_json_error(__('Failed to send test email: ', 'legal-doc-automation') . $result['error_message']);
            }
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error sending test email: ', 'legal-doc-automation') . $e->getMessage());
        }
    }

    /**
     * AJAX handler for debugging template merge tags
     */
    public function handleAjaxDebugTemplate() {
        check_ajax_referer('lda_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to debug templates.', 'legal-doc-automation'));
        }

        $raw_template_file = $_POST['template_file'];
        $template_filename = basename($raw_template_file);
        $template_filename = trim($template_filename, '"\'');
        $template_filename = str_replace('\\', '', $template_filename);
        if (empty($template_filename)) {
            wp_send_json_error(__('No template file specified.', 'legal-doc-automation'));
        }

        $template_path = wp_upload_dir()['basedir'] . '/lda-templates/' . $template_filename;
        
        if (!file_exists($template_path)) {
            wp_send_json_error(__('Template file not found: ', 'legal-doc-automation') . $template_filename);
        }

        try {
            $merge_tags = LDA_DocumentProcessor::getTemplateMergeTags($template_path);
            
            $result = array(
                'template_file' => $template_filename,
                'merge_tags' => $merge_tags,
                'merge_tags_count' => count($merge_tags),
                'message' => 'Template debug information retrieved successfully.'
            );
            
            wp_send_json_success($result);
            
        } catch (Exception $e) {
            wp_send_json_error(__('Error reading template: ', 'legal-doc-automation') . $e->getMessage());
        }
    }

    public function handleAjaxTestGoogleDrive() {
        check_ajax_referer('lda_admin_nonce', 'nonce');
        wp_send_json_success('Google Drive test functionality coming soon!');
    }

    public function handleAjaxUploadGDriveCredentials() {
        check_ajax_referer('lda_admin_nonce', 'nonce');
        
        try {
            LDA_Logger::log("=== CREDENTIALS UPLOAD STARTED ===");
            LDA_Logger::log("FILES array: " . print_r($_FILES, true));
            
            if (!isset($_FILES['credentials_file']) || $_FILES['credentials_file']['error'] !== UPLOAD_ERR_OK) {
                $error_msg = 'No file uploaded or upload error occurred.';
                if (isset($_FILES['credentials_file'])) {
                    $error_msg .= ' Upload error code: ' . $_FILES['credentials_file']['error'];
                }
                LDA_Logger::error($error_msg);
                wp_send_json_error($error_msg);
                return;
            }
            
            $file = $_FILES['credentials_file'];
            
            // Validate file type
            if ($file['type'] !== 'application/json') {
                wp_send_json_error('Please upload a valid JSON file.');
                return;
            }
            
            // Validate file size (max 1MB)
            if ($file['size'] > 1024 * 1024) {
                wp_send_json_error('File size too large. Maximum 1MB allowed.');
                return;
            }
            
            // Read and validate JSON content
            $json_content = file_get_contents($file['tmp_name']);
            $credentials = json_decode($json_content, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                wp_send_json_error('Invalid JSON file: ' . json_last_error_msg());
                return;
            }
            
            // Validate required fields
            $required_fields = ['type', 'project_id', 'private_key_id', 'private_key', 'client_email', 'client_id'];
            foreach ($required_fields as $field) {
                if (!isset($credentials[$field])) {
                    wp_send_json_error("Missing required field: {$field}");
                    return;
                }
            }
            
            // Ensure it's a service account
            if ($credentials['type'] !== 'service_account') {
                wp_send_json_error('Only service account credentials are supported.');
                return;
            }
            
            // Save to uploads directory
            $upload_dir = wp_upload_dir();
            $credentials_path = $upload_dir['basedir'] . '/lda-google-credentials.json';
            
            if (!wp_mkdir_p($upload_dir['basedir'])) {
                wp_send_json_error('Failed to create uploads directory.');
                return;
            }
            
            if (file_put_contents($credentials_path, $json_content) === false) {
                wp_send_json_error('Failed to save credentials file.');
                return;
            }
            
            // Set proper permissions
            chmod($credentials_path, 0600);
            
            LDA_Logger::log("Google Drive credentials uploaded successfully. Service account: " . $credentials['client_email']);
            
            wp_send_json_success('Google Drive credentials uploaded successfully! Service account: ' . $credentials['client_email']);
            
        } catch (Exception $e) {
            LDA_Logger::error("Failed to upload Google Drive credentials: " . $e->getMessage());
            wp_send_json_error('Upload failed: ' . $e->getMessage());
        }
    }

    public function handleAjaxTestPdf() {
        check_ajax_referer('lda_admin_nonce', 'nonce');
        
        try {
            $options = get_option('lda_settings', array());
            $pdf_handler = new LDA_PDFHandler($options);
            
            $engine = isset($_POST['engine']) ? sanitize_text_field($_POST['engine']) : null;
            $result = $pdf_handler->testPdfGeneration($engine);
            
            if ($result['success']) {
                wp_send_json_success(array(
                    'message' => 'PDF generation test successful!',
                    'engine' => $result['engine'] ?? 'unknown',
                    'stats' => $pdf_handler->getPdfStats()
                ));
            } else {
                wp_send_json_error(array(
                    'message' => 'PDF generation test failed: ' . $result['error'],
                    'stats' => $pdf_handler->getPdfStats()
                ));
            }
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'PDF test error: ' . $e->getMessage()
            ));
        }
    }

    /**
     * Handle AJAX request to assign template to form
     */
    public function handleAjaxAssignTemplate() {
        check_ajax_referer('lda_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to assign templates.', 'legal-doc-automation'));
        }
        
        $form_id = intval($_POST['form_id']);
        $template = basename($_POST['template']); // Remove any path components, preserve filename
        
        if ($form_id > 0 && !empty($template)) {
            $template_assignments = get_option('lda_template_assignments', array());
            $template_assignments[$form_id] = $template;
            update_option('lda_template_assignments', $template_assignments);
            
            wp_send_json_success(__('Template assigned successfully!', 'legal-doc-automation'));
        } else {
            wp_send_json_error(__('Invalid form or template selected.', 'legal-doc-automation'));
        }
    }

    /**
     * Handle AJAX request to auto-populate merge tags
     */
    public function handleAjaxAutoPopulate() {
        check_ajax_referer('lda_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to auto-populate merge tags.', 'legal-doc-automation'));
        }
        
        $form_id = intval($_POST['form_id']);
        $template = basename($_POST['template']); // Remove any path components, preserve filename
        
        if ($form_id > 0 && !empty($template)) {
            $template_folder = wp_upload_dir()['basedir'] . '/lda-templates/';
            $template_path = $template_folder . $template;
            
            if (file_exists($template_path)) {
                $template_tags = $this->extractMergeTagsFromTemplate($template_path);
                
                if (!empty($template_tags)) {
                    // Clear existing mappings for this form to force auto-population
                    $mappings = get_option('lda_field_mappings', array());
                    unset($mappings[$form_id]);
                    update_option('lda_field_mappings', $mappings);
                    
                    wp_send_json_success(array(
                        'message' => sprintf(__('Auto-populated %d merge tags from template "%s". Please select the corresponding Gravity Forms fields.', 'legal-doc-automation'), 
                                    count($template_tags), $template),
                        'tags' => $template_tags
                    ));
                } else {
                    wp_send_json_error(sprintf(__('No merge tags found in template "%s".', 'legal-doc-automation'), $template));
                }
            } else {
                wp_send_json_error(sprintf(__('Template file "%s" not found.', 'legal-doc-automation'), $template));
            }
        } else {
            wp_send_json_error(__('Invalid form or template selected.', 'legal-doc-automation'));
        }
    }

    /**
     * Handle AJAX request to save field mappings
     */
    public function handleAjaxSaveFieldMapping() {
        check_ajax_referer('lda_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to save field mappings.', 'legal-doc-automation'));
        }
        
        $form_id = intval($_POST['form_id']);
        $merge_tags = isset($_POST['merge_tags']) ? $_POST['merge_tags'] : array();
        $field_ids = isset($_POST['field_ids']) ? $_POST['field_ids'] : array();
        
        if ($form_id > 0) {
            $mappings = get_option('lda_field_mappings', array());
            $mappings[$form_id] = array();
            
            // Process the mappings
            for ($i = 0; $i < count($merge_tags); $i++) {
                if (!empty($merge_tags[$i]) && !empty($field_ids[$i])) {
                    // Clean the merge tag format
                    $tag = trim($merge_tags[$i]);
                    if (strpos($tag, '{$') === 0) {
                        $tag = substr($tag, 2);
                    }
                    if (substr($tag, -1) === '}') {
                        $tag = substr($tag, 0, -1);
                    }
                    
                    $mappings[$form_id][$tag] = $field_ids[$i];
                }
            }
            
            update_option('lda_field_mappings', $mappings);
            
            wp_send_json_success(__('Field mappings saved successfully!', 'legal-doc-automation'));
        } else {
            wp_send_json_error(__('Invalid form ID.', 'legal-doc-automation'));
        }
    }
    
    /**
     * Handle AJAX request to get form fields
     */
    public function handleAjaxGetFormFields() {
        check_ajax_referer('lda_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to get form fields.', 'legal-doc-automation'));
        }
        
        $form_id = intval($_POST['form_id']);
        $exclude_fields = isset($_POST['exclude_fields']) ? $_POST['exclude_fields'] : array();
        
        LDA_Logger::log("AJAX GetFormFields called for form ID: {$form_id}");
        LDA_Logger::log("Exclude fields: " . implode(', ', $exclude_fields));
        
        // Special debug for Field ID 8 in AJAX
        if (in_array('8', $exclude_fields)) {
            LDA_Logger::log("*** FIELD ID 8 IS IN EXCLUDE LIST *** This is why it's missing!");
        } else {
            LDA_Logger::log("*** FIELD ID 8 IS NOT IN EXCLUDE LIST *** Should be available");
        }
        
        if ($form_id > 0) {
            $form_fields = $this->getGravityFormFields($form_id);
            
            // Filter out already assigned fields
            $available_fields = array();
            foreach ($form_fields as $field_id => $field_label) {
                if (!in_array($field_id, $exclude_fields)) {
                    $available_fields[$field_id] = $field_label;
                    // Special debug for Field ID 8
                    if ($field_id == 8) {
                        LDA_Logger::log("*** FIELD ID 8 IS AVAILABLE *** Label: {$field_label}");
                    }
                } else {
                    LDA_Logger::log("Excluding field ID {$field_id} from available fields");
                    // Special debug for Field ID 8
                    if ($field_id == 8) {
                        LDA_Logger::log("*** FIELD ID 8 IS BEING EXCLUDED *** Reason: Already assigned");
                    }
                }
            }
            
            LDA_Logger::log("Returning " . count($available_fields) . " available fields");
            wp_send_json_success($available_fields);
        } else {
            wp_send_json_error(__('Invalid form ID.', 'legal-doc-automation'));
        }
    }

}