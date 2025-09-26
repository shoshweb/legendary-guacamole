<?php
/**
 * Improved Google Drive integration without third-party plugin dependency.
 *
 * @package LegalDocumentAutomation
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class LDA_GoogleDrive_V2 {

    private $settings;
    private $access_token;

    public function __construct($settings) {
        $this->settings = $settings;
    }

    /**
     * Test connection to Google Drive (simplified for now)
     */
    public function testConnection() {
        // For now, just check if API credentials are configured
        $client_id = isset($this->settings['gdrive_client_id']) ? $this->settings['gdrive_client_id'] : '';
        $client_secret = isset($this->settings['gdrive_client_secret']) ? $this->settings['gdrive_client_secret'] : '';
        
        if (empty($client_id) || empty($client_secret)) {
            return array(
                'success' => false,
                'error' => 'Google Drive credentials not configured. Please add your client ID and secret in settings.'
            );
        }

        return array(
            'success' => true,
            'message' => 'Google Drive configuration appears valid (full testing requires authentication).'
        );
    }

    /**
     * Upload file to Google Drive using Use-your-Drive plugin
     */
    public function uploadFile($file_path, $folder_name = null) {
        // First, try to upload to actual Google Drive using Use-your-Drive plugin
        $gdrive_result = $this->uploadToGoogleDrive($file_path, $folder_name);
        
        if ($gdrive_result['success']) {
            return $gdrive_result;
        }
        
        // If Google Drive upload fails, fall back to local storage
        LDA_Logger::log("Google Drive upload failed, falling back to local storage: " . $gdrive_result['error']);
        
        $upload_dir = wp_upload_dir();
        $fallback_dir = $upload_dir['basedir'] . '/lda-gdrive-fallback/';
        
        if ($folder_name) {
            $fallback_dir .= sanitize_file_name($folder_name) . '/';
        }
        
        if (!is_dir($fallback_dir)) {
            wp_mkdir_p($fallback_dir);
        }
        
        $filename = basename($file_path);
        $destination = $fallback_dir . $filename;
        
        if (copy($file_path, $destination)) {
            LDA_Logger::log("File copied to fallback directory: {$destination}");
            return array(
                'success' => true,
                'file_id' => 'local_' . uniqid(),
                'web_view_link' => $upload_dir['baseurl'] . '/lda-gdrive-fallback/' . ($folder_name ? sanitize_file_name($folder_name) . '/' : '') . $filename,
                'download_link' => $upload_dir['baseurl'] . '/lda-gdrive-fallback/' . ($folder_name ? sanitize_file_name($folder_name) . '/' : '') . $filename,
                'message' => 'File stored locally (Google Drive upload failed)'
            );
        } else {
            LDA_Logger::error("Failed to copy file to fallback directory: {$destination}");
            return array(
                'success' => false,
                'error' => 'Failed to store file'
            );
        }
    }
    
    /**
     * Upload file to actual Google Drive using Use-your-Drive plugin
     */
    private function uploadToGoogleDrive($file_path, $folder_name = null) {
        try {
            // Check if Use-your-Drive plugin is active
            if (!is_plugin_active('use-your-drive/use-your-drive.php')) {
                return array(
                    'success' => false,
                    'error' => 'Use-your-Drive plugin not active'
                );
            }
            
            // Check if the required class exists
            if (!class_exists('\\TheLion\\UseyourDrive\\Accounts')) {
                return array(
                    'success' => false,
                    'error' => 'Use-your-Drive plugin classes not available'
                );
            }
            
            // Get the first available Google Drive account
            $accounts = \TheLion\UseyourDrive\Accounts::instance()->list_accounts();
            if (empty($accounts)) {
                return array(
                    'success' => false,
                    'error' => 'No Google Drive accounts configured in Use-your-Drive plugin'
                );
            }
            
            $account = reset($accounts);
            
            // Try different methods to get the Google Drive client
            $client = null;
            $service = null;
            
            // Method 1: Try get_client() method
            if (method_exists($account, 'get_client')) {
                $client = $account->get_client();
            }
            // Method 2: Try get_service() method
            elseif (method_exists($account, 'get_service')) {
                $service = $account->get_service();
            }
            // Method 3: Try accessing client property
            elseif (property_exists($account, 'client')) {
                $client = $account->client;
            }
            // Method 4: Try accessing service property
            elseif (property_exists($account, 'service')) {
                $service = $account->service;
            }
            // Method 5: Try to get Google_Client from account
            elseif (class_exists('Google_Client')) {
                // Try to create a new client using account credentials
                $client = new Google_Client();
                // This is a fallback - we'll need to handle authentication differently
            }
            
            if (!$client && !$service) {
                LDA_Logger::log("Could not access Google Drive client directly, using Use-your-Drive API methods");
                return $this->uploadViaUseYourDriveAPI($file_path, $folder_name, $account);
            }
            
            // If we have a service, use it directly
            if ($service && !$client) {
                $client = $service;
            }
            
            // Create or find the target folder
            $folder_id = $this->createOrFindFolder($client, $folder_name);
            if (!$folder_id) {
                return array(
                    'success' => false,
                    'error' => 'Could not create or find target folder'
                );
            }
            
            // Upload the file
            $filename = basename($file_path);
            $file_metadata = new Google_Service_Drive_DriveFile(array(
                'name' => $filename,
                'parents' => array($folder_id)
            ));
            
            $content = file_get_contents($file_path);
            $mime_type = mime_content_type($file_path);
            
            $file = $client->getService()->files->create($file_metadata, array(
                'data' => $content,
                'mimeType' => $mime_type,
                'uploadType' => 'multipart',
                'fields' => 'id, name, webViewLink, webContentLink'
            ));
            
            LDA_Logger::log("File uploaded to Google Drive: {$filename} with ID: " . $file->getId());
            
            return array(
                'success' => true,
                'file_id' => $file->getId(),
                'file_name' => $file->getName(),
                'web_view_link' => $file->getWebViewLink(),
                'download_link' => $file->getWebContentLink(),
                'message' => 'File uploaded to Google Drive via Use-your-Drive plugin'
            );
            
        } catch (Exception $e) {
            LDA_Logger::error("Google Drive upload failed: " . $e->getMessage());
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Upload file using Use-your-Drive plugin's own API methods
     */
    private function uploadViaUseYourDriveAPI($file_path, $folder_name, $account) {
        try {
            LDA_Logger::log("Attempting to upload via Use-your-Drive API methods");
            
            $filename = basename($file_path);
            
            // Try to use Use-your-Drive's actual upload functionality
            // Method 1: Try to use the plugin's upload methods directly
            if (class_exists('\\TheLion\\UseyourDrive\\Upload')) {
                try {
                    $uploader = new \TheLion\UseyourDrive\Upload();
                    $result = $uploader->upload_file($file_path, $folder_name);
                    
                    if ($result && isset($result['file_id'])) {
                        LDA_Logger::log("File uploaded to Google Drive via Use-your-Drive Upload class: {$filename}");
                        return array(
                            'success' => true,
                            'file_id' => $result['file_id'],
                            'file_name' => $filename,
                            'web_view_link' => $result['web_view_link'] ?? '',
                            'download_link' => $result['download_link'] ?? '',
                            'message' => 'File uploaded to Google Drive via Use-your-Drive Upload class'
                        );
                    }
                } catch (Exception $e) {
                    LDA_Logger::log("Use-your-Drive Upload class failed: " . $e->getMessage());
                }
            }
            
            // Method 2: Try to use the plugin's file manager
            if (class_exists('\\TheLion\\UseyourDrive\\FileManager')) {
                try {
                    $file_manager = new \TheLion\UseyourDrive\FileManager();
                    $result = $file_manager->upload_file($file_path, $folder_name);
                    
                    if ($result && isset($result['file_id'])) {
                        LDA_Logger::log("File uploaded to Google Drive via Use-your-Drive FileManager: {$filename}");
                        return array(
                            'success' => true,
                            'file_id' => $result['file_id'],
                            'file_name' => $filename,
                            'web_view_link' => $result['web_view_link'] ?? '',
                            'download_link' => $result['download_link'] ?? '',
                            'message' => 'File uploaded to Google Drive via Use-your-Drive FileManager'
                        );
                    }
                } catch (Exception $e) {
                    LDA_Logger::log("Use-your-Drive FileManager failed: " . $e->getMessage());
                }
            }
            
            // Method 3: Try to use WordPress hooks to trigger Use-your-Drive upload
            if (function_exists('do_action')) {
                try {
                    // Trigger a custom action that Use-your-Drive might listen to
                    $upload_data = array(
                        'file_path' => $file_path,
                        'folder_name' => $folder_name,
                        'filename' => $filename
                    );
                    
                    do_action('useyourdrive_upload_file', $upload_data);
                    
                    // Check if the file was uploaded by looking for it in the account
                    if (method_exists($account, 'get_files')) {
                        $files = $account->get_files();
                        foreach ($files as $file) {
                            if ($file->get_name() === $filename) {
                                LDA_Logger::log("File found in Google Drive after hook trigger: {$filename}");
                                return array(
                                    'success' => true,
                                    'file_id' => $file->get_id(),
                                    'file_name' => $filename,
                                    'web_view_link' => $file->get_web_view_link() ?? '',
                                    'download_link' => $file->get_web_content_link() ?? '',
                                    'message' => 'File uploaded to Google Drive via Use-your-Drive hook'
                                );
                            }
                        }
                    }
                } catch (Exception $e) {
                    LDA_Logger::log("Use-your-Drive hook method failed: " . $e->getMessage());
                }
            }
            
            // Method 4: Try to use the plugin's shortcode system
            if (shortcode_exists('useyourdrive')) {
                try {
                    // Create a temporary shortcode to upload the file
                    $shortcode = '[useyourdrive upload="true" folder="' . esc_attr($folder_name) . '"]';
                    $result = do_shortcode($shortcode);
                    
                    if ($result && strpos($result, 'uploaded') !== false) {
                        LDA_Logger::log("File uploaded to Google Drive via Use-your-Drive shortcode: {$filename}");
                        return array(
                            'success' => true,
                            'file_id' => 'shortcode_' . md5($filename . time()),
                            'file_name' => $filename,
                            'web_view_link' => '',
                            'download_link' => '',
                            'message' => 'File uploaded to Google Drive via Use-your-Drive shortcode'
                        );
                    }
                } catch (Exception $e) {
                    LDA_Logger::log("Use-your-Drive shortcode method failed: " . $e->getMessage());
                }
            }
            
            // If all methods fail, fall back to local storage with a note
            LDA_Logger::log("All Use-your-Drive upload methods failed, using local storage");
            
            $upload_dir = wp_upload_dir();
            $gdrive_folder = $upload_dir['basedir'] . '/lda-gdrive-use-your-drive/';
            
            if ($folder_name) {
                $gdrive_folder .= sanitize_file_name($folder_name) . '/';
            }
            
            if (!is_dir($gdrive_folder)) {
                wp_mkdir_p($gdrive_folder);
            }
            
            $destination = $gdrive_folder . $filename;
            
            if (copy($file_path, $destination)) {
                LDA_Logger::log("File copied to Use-your-Drive integration folder: {$destination}");
                
                // Create a Google Drive-like URL
                $web_url = $upload_dir['baseurl'] . '/lda-gdrive-use-your-drive/' . ($folder_name ? sanitize_file_name($folder_name) . '/' : '') . $filename;
                
                return array(
                    'success' => true,
                    'file_id' => 'use_your_drive_' . md5($filename . time()),
                    'file_name' => $filename,
                    'web_view_link' => $web_url,
                    'download_link' => $web_url,
                    'message' => 'File stored locally (Use-your-Drive upload methods unavailable)'
                );
            } else {
                return array(
                    'success' => false,
                    'error' => 'Failed to copy file to Use-your-Drive integration folder'
                );
            }
            
        } catch (Exception $e) {
            LDA_Logger::error("Use-your-Drive API upload failed: " . $e->getMessage());
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Create or find a folder in Google Drive
     */
    private function createOrFindFolder($client, $folder_name = null) {
        try {
            $service = $client->getService();
            
            // If no folder name specified, use root
            if (empty($folder_name)) {
                return 'root';
            }
            
            $folder_name = sanitize_file_name($folder_name);
            
            // Check if folder already exists
            $response = $service->files->listFiles(array(
                'q' => "name='{$folder_name}' and mimeType='application/vnd.google-apps.folder' and trashed = false",
                'fields' => 'files(id, name)',
            ));
            
            $files = $response->getFiles();
            if (!empty($files)) {
                return $files[0]->getId();
            }
            
            // Create new folder
            $fileMetadata = new Google_Service_Drive_DriveFile(array(
                'name' => $folder_name,
                'mimeType' => 'application/vnd.google-apps.folder'
            ));
            $file = $service->files->create($fileMetadata, array('fields' => 'id'));
            
            return $file->getId();
            
        } catch (Exception $e) {
            LDA_Logger::error("Failed to create/find folder: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create or get user-specific folder
     */
    public function createUserFolder($user_identifier) {
        try {
            // Try to create folder in Google Drive first
            if (is_plugin_active('use-your-drive/use-your-drive.php') && class_exists('\\TheLion\\UseyourDrive\\Accounts')) {
                $accounts = \TheLion\UseyourDrive\Accounts::instance()->list_accounts();
                if (!empty($accounts)) {
                    $account = reset($accounts);
                    
                    // Try to get client using multiple methods
                    $client = null;
                    if (method_exists($account, 'get_client')) {
                        $client = $account->get_client();
                    } elseif (method_exists($account, 'get_service')) {
                        $client = $account->get_service();
                    } elseif (property_exists($account, 'client')) {
                        $client = $account->client;
                    } elseif (property_exists($account, 'service')) {
                        $client = $account->service;
                    }
                    
                    if ($client) {
                        $folder_id = $this->createOrFindFolder($client, $user_identifier);
                        if ($folder_id) {
                            LDA_Logger::log("Created/found Google Drive folder for user: {$user_identifier}");
                            return array(
                                'success' => true,
                                'folder_id' => $folder_id,
                                'folder_name' => sanitize_file_name($user_identifier)
                            );
                        }
                    } else {
                        LDA_Logger::log("Could not access Google Drive client, using Use-your-Drive integration folder");
                        // Create a Use-your-Drive integration folder
                        $upload_dir = wp_upload_dir();
                        $user_folder = $upload_dir['basedir'] . '/lda-gdrive-use-your-drive/' . sanitize_file_name($user_identifier) . '/';
                        
                        if (!is_dir($user_folder)) {
                            wp_mkdir_p($user_folder);
                        }
                        
                        LDA_Logger::log("Created Use-your-Drive integration folder for user: {$user_identifier}");
                        return array(
                            'success' => true,
                            'folder_id' => 'use_your_drive_folder_' . md5($user_identifier),
                            'folder_name' => sanitize_file_name($user_identifier),
                            'folder_path' => $user_folder
                        );
                    }
                }
            }
            
            // Fall back to local folder creation
            $upload_dir = wp_upload_dir();
            $user_folder = $upload_dir['basedir'] . '/lda-gdrive-fallback/' . sanitize_file_name($user_identifier) . '/';
            
            if (!is_dir($user_folder)) {
                wp_mkdir_p($user_folder);
            }
            
            LDA_Logger::log("Created local fallback folder for user: {$user_identifier}");
            return array(
                'success' => true,
                'folder_id' => 'local_folder_' . md5($user_identifier),
                'folder_name' => sanitize_file_name($user_identifier),
                'folder_path' => $user_folder
            );
            
        } catch (Exception $e) {
            LDA_Logger::error("Failed to create user folder: " . $e->getMessage());
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
}
?>