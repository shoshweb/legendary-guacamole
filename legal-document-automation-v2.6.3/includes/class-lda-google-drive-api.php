<?php
/**
 * Native Google Drive API integration for the Legal Document Automation plugin.
 * This provides direct Google Drive API integration without requiring third-party plugins.
 *
 * @package LegalDocumentAutomation
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class LDA_GoogleDriveAPI {

    private $settings;
    private $client;
    private $service;

    public function __construct($settings = array()) {
        $this->settings = $settings;
        $this->initializeGoogleDriveAPI();
    }

    /**
     * Initialize Google Drive API client
     */
    private function initializeGoogleDriveAPI() {
        try {
            // Check if Google API credentials are configured
            $credentials_path = $this->getCredentialsPath();
            
            if (!file_exists($credentials_path)) {
                LDA_Logger::log("Google Drive API credentials not found at: {$credentials_path}");
                return false;
            }

            // Use WordPress-native Google Drive integration
            $this->wordpress_api = new LDA_GoogleDriveWordPress($credentials_path);
            
            LDA_Logger::log("Google Drive API initialized successfully");
            return true;

        } catch (Exception $e) {
            LDA_Logger::error("Failed to initialize Google Drive API: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get the path to Google API credentials file
     */
    private function getCredentialsPath() {
        $upload_dir = wp_upload_dir();
        return $upload_dir['basedir'] . '/lda-google-credentials.json';
    }

    /**
     * Check if Google Drive integration is available
     */
    public function isAvailable() {
        if (!$this->client || !$this->service) {
            return array(
                'available' => false,
                'method' => 'none',
                'message' => 'Google Drive API not initialized. Please configure credentials.'
            );
        }

        try {
            // Test API connection
            $this->service->about->get(array('fields' => 'user'));
            
            return array(
                'available' => true,
                'method' => 'google_drive_api',
                'message' => 'Google Drive API is ready'
            );

        } catch (Exception $e) {
            return array(
                'available' => false,
                'method' => 'none',
                'message' => 'Google Drive API connection failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Create or get user-specific folder
     */
    public function createUserFolder($user_identifier) {
        try {
            if (!$this->service) {
                throw new Exception("Google Drive API not initialized");
            }

            $folder_name = sanitize_file_name($user_identifier);
            $root_folder_name = !empty($this->settings['gdrive_root_folder']) ? 
                sanitize_file_name($this->settings['gdrive_root_folder']) : 'LegalDocuments';

            // First, find or create the root folder
            $root_folder_id = $this->findOrCreateFolder($root_folder_name, 'root');
            
            // Then, find or create the user folder within the root folder
            $user_folder_id = $this->findOrCreateFolder($folder_name, $root_folder_id);

            return array(
                'success' => true,
                'folder_id' => $user_folder_id,
                'folder_name' => $folder_name,
                'web_view_link' => "https://drive.google.com/drive/folders/{$user_folder_id}"
            );

        } catch (Exception $e) {
            LDA_Logger::error("Failed to create user folder: " . $e->getMessage());
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }

    /**
     * Find or create a folder
     */
    private function findOrCreateFolder($folder_name, $parent_id = 'root') {
        try {
            // Search for existing folder
            $query = "name='{$folder_name}' and parents in '{$parent_id}' and mimeType='application/vnd.google-apps.folder' and trashed=false";
            $results = $this->service->files->listFiles(array(
                'q' => $query,
                'fields' => 'files(id, name)'
            ));

            if (count($results->getFiles()) > 0) {
                return $results->getFiles()[0]->getId();
            }

            // Create new folder if not found
            $folder_metadata = new Google_Service_Drive_DriveFile(array(
                'name' => $folder_name,
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => array($parent_id)
            ));

            $folder = $this->service->files->create($folder_metadata, array(
                'fields' => 'id'
            ));

            LDA_Logger::log("Created Google Drive folder: {$folder_name} (ID: {$folder->getId()})");
            return $folder->getId();

        } catch (Exception $e) {
            LDA_Logger::error("Failed to find or create folder '{$folder_name}': " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Upload file to Google Drive
     */
    public function uploadFile($file_path, $folder_identifier) {
        try {
            if (!$this->service) {
                throw new Exception("Google Drive API not initialized");
            }

            if (!file_exists($file_path)) {
                throw new Exception("Source file does not exist: {$file_path}");
            }

            // Get or create user folder
            $folder_result = $this->createUserFolder($folder_identifier);
            if (!$folder_result['success']) {
                throw new Exception($folder_result['error']);
            }

            $folder_id = $folder_result['folder_id'];
            $filename = basename($file_path);
            $mime_type = $this->getMimeType($file_path);

            // Create file metadata
            $file_metadata = new Google_Service_Drive_DriveFile(array(
                'name' => $filename,
                'parents' => array($folder_id)
            ));

            // Upload file
            $content = file_get_contents($file_path);
            $file = $this->service->files->create($file_metadata, array(
                'data' => $content,
                'mimeType' => $mime_type,
                'uploadType' => 'multipart',
                'fields' => 'id,name,webViewLink,webContentLink'
            ));

            LDA_Logger::log("File uploaded to Google Drive: {$filename} (ID: {$file->getId()})");

            return array(
                'success' => true,
                'file_id' => $file->getId(),
                'file_name' => $file->getName(),
                'web_view_link' => $file->getWebViewLink(),
                'download_link' => $file->getWebContentLink(),
                'message' => 'File uploaded to Google Drive successfully'
            );

        } catch (Exception $e) {
            LDA_Logger::error("Failed to upload file to Google Drive: " . $e->getMessage());
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }

    /**
     * Get MIME type for file
     */
    private function getMimeType($file_path) {
        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        
        $mime_types = array(
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'txt' => 'text/plain'
        );

        return isset($mime_types[$extension]) ? $mime_types[$extension] : 'application/octet-stream';
    }

    /**
     * Get file statistics
     */
    public function getStats() {
        try {
            if (!$this->service) {
                return array('error' => 'Google Drive API not initialized');
            }

            // Get user info
            $about = $this->service->about->get(array('fields' => 'user,storageQuota'));
            
            return array(
                'user_email' => $about->getUser()->getEmailAddress(),
                'storage_used' => $about->getStorageQuota()->getUsage(),
                'storage_limit' => $about->getStorageQuota()->getLimit(),
                'method' => 'google_drive_api'
            );

        } catch (Exception $e) {
            return array('error' => $e->getMessage());
        }
    }
}
?>
