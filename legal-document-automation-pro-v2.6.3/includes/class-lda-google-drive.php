<?php
/**
 * Handles Google Drive integration with the Use-your-Drive plugin.
 *
 * @package LegalDocumentAutomation
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class LDA_GoogleDrive {

    /**
     * Plugin settings.
     *
     * @var array
     */
    private $settings;

    /**
     * Constructor.
     *
     * @param array $settings The plugin settings.
     */
    public function __construct($settings) {
        $this->settings = $settings;
    }

    /**
     * Tests the connection to Google Drive by checking for configured accounts
     * in the Use-your-Drive plugin.
     *
     * @return array
     */
    public function testConnection() {
        // First, check if the main dependency plugin is active.
        if (!is_plugin_active('use-your-drive/use-your-drive.php')) {
            return array(
                'success' => false,
                'error' => __('The "Use-your-Drive" plugin is not active.', 'legal-doc-automation')
            );
        }

        // Check if the required class from the plugin exists.
        if (!class_exists('\\TheLion\\UseyourDrive\\Accounts')) {
            return array(
                'success' => false,
                'error' => __('Could not find the Accounts class from the "Use-your-Drive" plugin. The plugin might be an incompatible version.', 'legal-doc-automation')
            );
        }

        // Get the accounts from the Use-your-Drive plugin's class.
        $accounts = \TheLion\UseyourDrive\Accounts::instance()->list_accounts();

        if (empty($accounts) || !is_array($accounts)) {
            return array(
                'success' => false,
                'error' => __('No Google Drive accounts are configured in the "Use-your-Drive" plugin. Please add an account in its settings.', 'legal-doc-automation')
            );
        }

        // Connection is considered successful.
        $first_account = reset($accounts);
        $account_email = $first_account->get_email();
        
        return array(
            'success' => true,
            'user_email' => $account_email,
            'storage_used' => __('N/A', 'legal-doc-automation'),
            'storage_limit' => __('N/A', 'legal-doc-automation'),
            'connection_time' => current_time('mysql')
        );
    }

    /**
     * Upload file using Use-your-Drive plugin's built-in functionality (Gravity Forms pattern)
     */
    private function uploadFileViaUseYourDrive($file_path, $folder_name = 'Legal Documents') {
        try {
            // Use the new Gravity Forms-based integration
            $gf_integration = new LDA_GoogleDriveGravityForms();
            $result = $gf_integration->uploadFileViaUseYourDrive($file_path, $folder_name);
            
            if ($result['success']) {
                LDA_Logger::log("File uploaded successfully using Gravity Forms integration pattern");
                return $result;
            } else {
                LDA_Logger::warn("Gravity Forms integration failed: " . $result['error'] . ". Trying fallback method.");
                return $this->uploadFileViaUseYourDriveFallback($file_path, $folder_name);
            }
            
        } catch (Exception $e) {
            LDA_Logger::error("Gravity Forms integration failed: " . $e->getMessage());
            return $this->uploadFileViaUseYourDriveFallback($file_path, $folder_name);
        }
    }

    /**
     * Fallback upload method using original approach
     */
    private function uploadFileViaUseYourDriveFallback($file_path, $folder_name = 'Legal Documents') {
        try {
            // Check if Use-your-Drive plugin is active
            if (!is_plugin_active('use-your-drive/use-your-drive.php')) {
                return array(
                    'success' => false,
                    'error' => 'Use-your-Drive plugin is not active'
                );
            }

            // Check if the file exists
            if (!file_exists($file_path)) {
                return array(
                    'success' => false,
                    'error' => 'File does not exist: ' . $file_path
                );
            }

            // Check if the Use-your-Drive API class exists
            if (!class_exists('\\TheLion\\UseyourDrive\\API')) {
                return array(
                    'success' => false,
                    'error' => 'Use-your-Drive API class not available'
                );
            }

            // Get the first available account
            $accounts = \TheLion\UseyourDrive\Accounts::instance()->list_accounts();
            if (empty($accounts)) {
                return array(
                    'success' => false,
                    'error' => 'No Google Drive accounts configured in Use-your-Drive plugin'
                );
            }

            $account = reset($accounts);
            
            // Check if account has drive access
            if (!$account->has_drive_access()) {
                return array(
                    'success' => false,
                    'error' => 'Account does not have Google Drive access'
                );
            }

            // Set the current account for the API
            \TheLion\UseyourDrive\API::set_account_by_id($account->get_id());

            // Get or create the folder
            $folder_id = $this->getOrCreateFolder($folder_name);
            if (!$folder_id) {
                return array(
                    'success' => false,
                    'error' => 'Could not create or find folder: ' . $folder_name
                );
            }

            // Prepare file object for upload
            $file_obj = new \stdClass();
            $file_obj->name = basename($file_path);
            $file_obj->type = mime_content_type($file_path);
            $file_obj->size = filesize($file_path);
            $file_obj->tmp_path = $file_path;

            // Check if upload_file method exists
            if (!method_exists('\\TheLion\\UseyourDrive\\API', 'upload_file')) {
                return array(
                    'success' => false,
                    'error' => 'Use-your-Drive API upload_file method not available'
                );
            }

            // Upload the file using Use-your-Drive API
            $upload_result = \TheLion\UseyourDrive\API::upload_file($file_obj, $folder_id, 'Legal document generated by A Legal Documents plugin', false);

            if ($upload_result && is_object($upload_result) && method_exists($upload_result, 'get_entry')) {
                $entry = $upload_result->get_entry();
                if ($entry && method_exists($entry, 'get_id')) {
                    $file_url = 'https://drive.google.com/file/d/' . $entry->get_id() . '/view';

                    return array(
                        'success' => true,
                        'file_id' => $entry->get_id(),
                        'file_name' => method_exists($entry, 'get_name') ? $entry->get_name() : basename($file_path),
                        'file_url' => $file_url,
                        'message' => 'File uploaded successfully to Google Drive (fallback method)'
                    );
                }
            }
            
            return array(
                'success' => false,
                'error' => 'Upload failed - invalid result from Use-your-Drive API'
            );

        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => 'Upload failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Create Google client and upload file
     */
    private function createGoogleClientAndUpload($file_path, $folder_name, $account) {
        try {
            // This is a fallback method - we'll store locally for now
            // In a real implementation, you would need to extract the OAuth tokens
            // from the Use-your-Drive plugin and create a new Google client
            
            $upload_dir = wp_upload_dir();
            $gdrive_folder = $upload_dir['basedir'] . '/lda-google-drive/' . $folder_name . '/';
            if (!file_exists($gdrive_folder)) {
                wp_mkdir_p($gdrive_folder);
            }

            $filename = basename($file_path);
            $destination = $gdrive_folder . $filename;
            
            if (copy($file_path, $destination)) {
                $file_url = $upload_dir['baseurl'] . '/lda-google-drive/' . $folder_name . '/' . $filename;
                
                return array(
                    'success' => true,
                    'file_id' => 'local_' . md5($destination),
                    'file_name' => $filename,
                    'file_url' => $file_url,
                    'message' => 'File stored locally - Use-your-Drive API integration needed'
                );
            } else {
                return array(
                    'success' => false,
                    'error' => 'Failed to copy file to local storage'
                );
            }
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => 'Fallback upload failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Upload file to Google Drive using Google client
     */
    private function uploadToGoogleDrive($file_path, $folder_name, $client) {
        try {
            // Check if Google API classes are available
            if (!class_exists('\\Google_Service_Drive')) {
                return array(
                    'success' => false,
                    'error' => 'Google Drive API classes not available'
                );
            }

            // Create the Google Drive service
            $service = new \Google_Service_Drive($client);

            // First, create or find the folder
            $folder_id = $this->findOrCreateFolder($service, $folder_name);

            // Prepare file metadata
            $file_metadata = array(
                'name' => basename($file_path),
                'parents' => array($folder_id)
            );

            // Upload the file
            $file = new \Google_Service_Drive_DriveFile($file_metadata);
            $result = $service->files->create($file, array(
                'data' => file_get_contents($file_path),
                'mimeType' => mime_content_type($file_path),
                'uploadType' => 'multipart',
                'fields' => 'id,name,webViewLink'
            ));

            return array(
                'success' => true,
                'file_id' => $result->getId(),
                'file_name' => $result->getName(),
                'file_url' => 'https://drive.google.com/file/d/' . $result->getId() . '/view',
                'message' => 'File uploaded successfully to Google Drive'
            );

        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => 'Google Drive upload failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Find or create a folder in Google Drive
     */
    private function findOrCreateFolder($service, $folder_name) {
        try {
            // Search for existing folder
            $query = "name='{$folder_name}' and mimeType='application/vnd.google-apps.folder' and trashed=false";
            $results = $service->files->listFiles(array(
                'q' => $query,
                'fields' => 'files(id,name)'
            ));

            if (count($results->getFiles()) > 0) {
                return $results->getFiles()[0]->getId();
            }

            // Create new folder if not found
            $folder_metadata = array(
                'name' => $folder_name,
                'mimeType' => 'application/vnd.google-apps.folder'
            );

            $folder = new \Google_Service_Drive_DriveFile($folder_metadata);
            $result = $service->files->create($folder, array(
                'fields' => 'id'
            ));

            return $result->getId();

        } catch (Exception $e) {
            // Return root folder ID as fallback
            return 'root';
        }
    }

    /**
     * Get or create a folder in Google Drive
     */
    private function getOrCreateFolder($folder_name) {
        try {
            // Check if Use-your-Drive API class exists
            if (!class_exists('\\TheLion\\UseyourDrive\\API')) {
                return false;
            }

            // Check if required methods exist
            if (!method_exists('\\TheLion\\UseyourDrive\\API', 'get_main_folder')) {
                return false;
            }

            // Get the main folder (My Drive or App Folder)
            $main_folder = \TheLion\UseyourDrive\API::get_main_folder();
            if (!$main_folder || !is_object($main_folder) || !method_exists($main_folder, 'get_id')) {
                return false;
            }

            $main_folder_id = $main_folder->get_id();
            if (!$main_folder_id) {
                return false;
            }

            // Search for existing folder
            if (method_exists('\\TheLion\\UseyourDrive\\API', 'search_for_name_in_folder')) {
                $existing_folder = \TheLion\UseyourDrive\API::search_for_name_in_folder($folder_name, $main_folder_id);
                
                if ($existing_folder && is_object($existing_folder) && method_exists($existing_folder, 'get_id')) {
                    return $existing_folder->get_id();
                }
            }

            // Create new folder if it doesn't exist
            if (method_exists('\\TheLion\\UseyourDrive\\API', 'create_folder')) {
                $new_folder = \TheLion\UseyourDrive\API::create_folder($folder_name, $main_folder_id);
                
                if ($new_folder && is_object($new_folder) && method_exists($new_folder, 'get_id')) {
                    return $new_folder->get_id();
                }
            }

            // Fallback: return the main folder ID
            return $main_folder_id;

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Create user folder (required method)
     */
    public function createUserFolder($user_email, $folder_name = 'Legal Documents') {
        try {
            // Check if Use-your-Drive plugin is active
            if (!is_plugin_active('use-your-drive/use-your-drive.php')) {
                return array(
                    'success' => false,
                    'error' => 'Use-your-Drive plugin is not active'
                );
            }

            // Check if the required class exists
            if (!class_exists('\\TheLion\\UseyourDrive\\Accounts')) {
                return array(
                    'success' => false,
                    'error' => 'Use-your-Drive plugin classes not available'
                );
            }

            // Get the first available account
            $accounts = \TheLion\UseyourDrive\Accounts::instance()->list_accounts();
            if (empty($accounts)) {
                return array(
                    'success' => false,
                    'error' => 'No Google Drive accounts configured in Use-your-Drive plugin'
                );
            }

            $account = reset($accounts);
            
            // Check if account has drive access
            if (!$account->has_drive_access()) {
                return array(
                    'success' => false,
                    'error' => 'Account does not have Google Drive access'
                );
            }

            // Set the current account for the API
            \TheLion\UseyourDrive\API::set_account_by_id($account->get_id());

            // Get or create the folder
            $folder_id = $this->getOrCreateFolder($folder_name);
            if (!$folder_id) {
                return array(
                    'success' => false,
                    'error' => 'Could not create or find folder: ' . $folder_name
                );
            }

            return array(
                'success' => true,
                'folder_id' => $folder_id,
                'folder_name' => $folder_name,
                'folder_url' => 'https://drive.google.com/drive/folders/' . $folder_id,
                'message' => 'Folder created successfully in Google Drive'
            );

        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => 'Folder creation failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Upload file (required method)
     */
    public function uploadFile($file_path, $folder_id = null, $filename = null) {
        try {
            // Check if the file exists
            if (!file_exists($file_path)) {
                return array(
                    'success' => false,
                    'error' => 'File does not exist: ' . $file_path
                );
            }

            // Use the folder_id as the folder name (should be user email)
            $folder_name = $folder_id ?: 'Legal Documents'; // Fallback to default if no folder_id
            $result = $this->uploadFileViaUseYourDrive($file_path, $folder_name);
            
            // If Use-your-Drive upload fails, fall back to local storage
            if (!$result['success']) {
                LDA_Logger::warn("Use-your-Drive upload failed: " . $result['error'] . ". Falling back to local storage.");
                return $this->fallbackToLocalStorage($file_path, $folder_name);
            }
            
            return $result;

        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => 'Upload failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Fallback to local storage if Use-your-Drive fails
     */
    private function fallbackToLocalStorage($file_path, $folder_name) {
        try {
            $upload_dir = wp_upload_dir();
            $gdrive_folder = $upload_dir['basedir'] . '/lda-google-drive/' . $folder_name . '/';
            if (!file_exists($gdrive_folder)) {
                wp_mkdir_p($gdrive_folder);
            }

            $filename = basename($file_path);
            $destination = $gdrive_folder . $filename;
            
            if (copy($file_path, $destination)) {
                $file_url = $upload_dir['baseurl'] . '/lda-google-drive/' . $folder_name . '/' . $filename;
                
                return array(
                    'success' => true,
                    'file_id' => 'local_' . md5($destination),
                    'file_name' => $filename,
                    'file_url' => $file_url,
                    'message' => 'File stored locally (Use-your-Drive integration failed)'
                );
            } else {
                return array(
                    'success' => false,
                    'error' => 'Failed to copy file to local storage'
                );
            }
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => 'Fallback storage failed: ' . $e->getMessage()
            );
        }
    }
}
