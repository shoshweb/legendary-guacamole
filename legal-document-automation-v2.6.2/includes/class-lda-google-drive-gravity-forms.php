<?php
/**
 * Google Drive Integration for Legal Document Automation
 * Based on Use-your-Drive Gravity Forms integration patterns
 */

if (!defined('ABSPATH')) {
    exit;
}

class LDA_GoogleDriveGravityForms {
    
    private $logger;
    
    public function __construct() {
        $this->logger = new LDA_Logger();
    }
    
    /**
     * Test connection to Use-your-Drive
     */
    public function testConnection() {
        try {
            // Check if Use-your-Drive is active
            if (!class_exists('\\TheLion\\UseyourDrive\\Core')) {
                return array(
                    'success' => false,
                    'error' => 'Use-your-Drive plugin not found'
                );
            }
            
            // Check if we can access the core instance
            $core = \TheLion\UseyourDrive\Core::instance();
            if (!$core) {
                return array(
                    'success' => false,
                    'error' => 'Cannot access Use-your-Drive core instance'
                );
            }
            
            // Check if accounts are configured
            $accounts = \TheLion\UseyourDrive\Accounts::instance();
            if (!$accounts || !$accounts->get_accounts()) {
                return array(
                    'success' => false,
                    'error' => 'No Google Drive accounts configured in Use-your-Drive'
                );
            }
            
            return array(
                'success' => true,
                'message' => 'Use-your-Drive connection successful'
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => 'Connection test failed: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Create user folder following Use-your-Drive patterns
     */
    public function createUserFolder($user_email, $folder_name = null) {
        try {
            $this->logger->log("Creating user folder for: {$user_email}");
            
            // Use the user's email as the folder name if not specified
            if (!$folder_name) {
                $folder_name = $user_email;
            }
            
            // Check if Use-your-Drive UserFolders class exists
            if (!class_exists('\\TheLion\\UseyourDrive\\UserFolders')) {
                return array(
                    'success' => false,
                    'error' => 'Use-your-Drive UserFolders class not available'
                );
            }
            
            // Get the user ID from email
            $user = get_user_by('email', $user_email);
            if (!$user) {
                // For guest users, create a temporary user ID
                $user_id = 'guest_' . md5($user_email);
            } else {
                $user_id = $user->ID;
            }
            
            // Use Use-your-Drive's user folder creation
            $result = \TheLion\UseyourDrive\UserFolders::user_register($user_id);
            
            if ($result) {
                $this->logger->log("User folder created successfully for: {$user_email} with folder name: {$folder_name}");
                return array(
                    'success' => true,
                    'folder_id' => $user_id,
                    'folder_name' => $folder_name,
                    'message' => 'User folder created successfully'
                );
            } else {
                return array(
                    'success' => false,
                    'error' => 'Failed to create user folder'
                );
            }
            
        } catch (Exception $e) {
            $this->logger->error("Error creating user folder: " . $e->getMessage());
            return array(
                'success' => false,
                'error' => 'User folder creation failed: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Upload file using Use-your-Drive's file upload system
     */
    public function uploadFile($file_path, $folder_id, $filename = null) {
        try {
            if (!file_exists($file_path)) {
                return array(
                    'success' => false,
                    'error' => 'File does not exist: ' . $file_path
                );
            }
            
            $this->logger->log("Uploading file: {$file_path} to folder: {$folder_id}");
            
            // Check if Use-your-Drive Upload class exists
            if (!class_exists('\\TheLion\\UseyourDrive\\Upload')) {
                return array(
                    'success' => false,
                    'error' => 'Use-your-Drive Upload class not available'
                );
            }
            
            // Create upload instance
            $uploader = new \TheLion\UseyourDrive\Upload();
            
            // Prepare file data similar to Gravity Forms integration
            $file_data = array(
                'name' => $filename ?: basename($file_path),
                'type' => mime_content_type($file_path),
                'size' => filesize($file_path),
                'tmp_name' => $file_path,
                'error' => 0
            );
            
            // Upload the file
            $result = $uploader->upload_file($file_data, $folder_id);
            
            if ($result && isset($result['file_id'])) {
                $this->logger->log("File uploaded successfully: " . $result['file_id']);
                
                // Format response similar to Gravity Forms integration
                return array(
                    'success' => true,
                    'file_id' => $result['file_id'],
                    'file_name' => $result['file_name'] ?? basename($file_path),
                    'file_url' => $result['web_view_link'] ?? '',
                    'download_link' => $result['download_link'] ?? $result['web_view_link'] ?? '',
                    'preview_url' => $result['preview_url'] ?? $result['web_view_link'] ?? '',
                    'shared_url' => $result['shared_url'] ?? $result['web_view_link'] ?? '',
                    'message' => 'File uploaded successfully to Google Drive'
                );
            } else {
                return array(
                    'success' => false,
                    'error' => 'Upload failed - no file ID returned'
                );
            }
            
        } catch (Exception $e) {
            $this->logger->error("File upload failed: " . $e->getMessage());
            return array(
                'success' => false,
                'error' => 'Upload failed: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Get public link for uploaded file
     */
    public function getPublicLink($file_id) {
        try {
            // Check if Use-your-Drive API class exists
            if (!class_exists('\\TheLion\\UseyourDrive\\API')) {
                return array(
                    'success' => false,
                    'error' => 'Use-your-Drive API class not available'
                );
            }
            
            // Get file information
            $file_info = \TheLion\UseyourDrive\API::get_file_info($file_id);
            
            if ($file_info && isset($file_info['web_view_link'])) {
                return array(
                    'success' => true,
                    'file_url' => $file_info['web_view_link'],
                    'download_link' => $file_info['download_link'] ?? $file_info['web_view_link'],
                    'preview_url' => $file_info['preview_url'] ?? $file_info['web_view_link']
                );
            } else {
                return array(
                    'success' => false,
                    'error' => 'Could not retrieve file information'
                );
            }
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => 'Failed to get public link: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Create user folder (alias for compatibility)
     */
    public function createFolder($folder_name) {
        return $this->createUserFolder('system', $folder_name);
    }
    
    /**
     * Upload file via Use-your-Drive (alias for compatibility)
     */
    public function uploadFileViaUseYourDrive($file_path, $folder_name) {
        // Create folder first
        $folder_result = $this->createUserFolder('system', $folder_name);
        if (!$folder_result['success']) {
            return $folder_result;
        }
        
        // Upload file
        return $this->uploadFile($file_path, $folder_result['folder_id']);
    }
}
