<?php
/**
 * Native Google Drive integration for the Legal Document Automation plugin.
 * This provides a simple fallback when the Use-your-Drive plugin is not available.
 *
 * @package LegalDocumentAutomation
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class LDA_GoogleDriveNative {

    private $settings;
    private $upload_dir;

    public function __construct($settings = array()) {
        $this->settings = $settings;
        $this->upload_dir = wp_upload_dir();
    }

    /**
     * Check if Google Drive integration is available
     *
     * @return array Status array
     */
    public function isAvailable() {
        // For now, we'll use a simple file-based approach
        // In the future, this could be enhanced with actual Google Drive API
        return array(
            'available' => true,
            'method' => 'file_based',
            'message' => 'Using file-based storage (Google Drive API not configured)'
        );
    }

    /**
     * Create a user folder (simulated)
     *
     * @param string $user_identifier User identifier (email or ID)
     * @return array Result array
     */
    public function createUserFolder($user_identifier) {
        try {
            $folder_path = $this->upload_dir['basedir'] . '/lda-gdrive-fallback/' . sanitize_file_name($user_identifier);
            
            if (!is_dir($folder_path)) {
                wp_mkdir_p($folder_path);
                LDA_Logger::log("Created fallback folder: " . $folder_path);
            }

            return array(
                'success' => true,
                'folder_id' => sanitize_file_name($user_identifier),
                'folder_path' => $folder_path,
                'web_view_link' => $this->upload_dir['baseurl'] . '/lda-gdrive-fallback/' . sanitize_file_name($user_identifier),
                'message' => 'Folder created using file-based storage'
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
     * Upload a file (simulated)
     *
     * @param string $file_path Path to the file to upload
     * @param string $folder_identifier Folder identifier
     * @return array Result array
     */
    public function uploadFile($file_path, $folder_identifier) {
        try {
            if (!file_exists($file_path)) {
                return array(
                    'success' => false,
                    'error' => 'Source file does not exist: ' . $file_path
                );
            }

            $folder_path = $this->upload_dir['basedir'] . '/lda-gdrive-fallback/' . sanitize_file_name($folder_identifier);
            
            if (!is_dir($folder_path)) {
                wp_mkdir_p($folder_path);
            }

            $filename = basename($file_path);
            $destination = $folder_path . '/' . $filename;
            
            if (copy($file_path, $destination)) {
                $web_url = $this->upload_dir['baseurl'] . '/lda-gdrive-fallback/' . sanitize_file_name($folder_identifier) . '/' . $filename;
                
                LDA_Logger::log("File copied to fallback directory: " . $destination);
                
                return array(
                    'success' => true,
                    'file_id' => $filename,
                    'file_name' => $filename,
                    'web_view_link' => $web_url,
                    'download_link' => $web_url,
                    'message' => 'File uploaded using file-based storage'
                );
            } else {
                return array(
                    'success' => false,
                    'error' => 'Failed to copy file to destination'
                );
            }

        } catch (Exception $e) {
            LDA_Logger::error("Failed to upload file: " . $e->getMessage());
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }

    /**
     * Get file statistics
     *
     * @return array Statistics array
     */
    public function getStats() {
        $fallback_dir = $this->upload_dir['basedir'] . '/lda-gdrive-fallback';
        
        if (!is_dir($fallback_dir)) {
            return array(
                'total_files' => 0,
                'total_folders' => 0,
                'total_size' => 0,
                'method' => 'file_based'
            );
        }

        $total_files = 0;
        $total_size = 0;
        $folders = glob($fallback_dir . '/*', GLOB_ONLYDIR);
        $total_folders = count($folders);

        foreach ($folders as $folder) {
            $files = glob($folder . '/*');
            $total_files += count($files);
            
            foreach ($files as $file) {
                if (is_file($file)) {
                    $total_size += filesize($file);
                }
            }
        }

        return array(
            'total_files' => $total_files,
            'total_folders' => $total_folders,
            'total_size' => $total_size,
            'total_size_mb' => round($total_size / 1024 / 1024, 2),
            'method' => 'file_based'
        );
    }

    /**
     * Test the Google Drive integration
     *
     * @return array Test result
     */
    public function testConnection() {
        try {
            $test_content = "Test file created at " . date('Y-m-d H:i:s');
            $test_file = $this->upload_dir['basedir'] . '/lda-gdrive-fallback/test-' . time() . '.txt';
            
            // Create test file
            file_put_contents($test_file, $test_content);
            
            // Test folder creation
            $folder_result = $this->createUserFolder('test-user');
            if (!$folder_result['success']) {
                return array(
                    'success' => false,
                    'error' => 'Failed to create test folder: ' . $folder_result['error']
                );
            }

            // Test file upload
            $upload_result = $this->uploadFile($test_file, 'test-user');
            
            // Clean up test files
            if (file_exists($test_file)) {
                unlink($test_file);
            }
            
            if ($upload_result['success']) {
                $uploaded_file = $this->upload_dir['basedir'] . '/lda-gdrive-fallback/test-user/' . basename($test_file);
                if (file_exists($uploaded_file)) {
                    unlink($uploaded_file);
                }
            }

            if ($upload_result['success']) {
                return array(
                    'success' => true,
                    'message' => 'File-based storage is working correctly',
                    'method' => 'file_based'
                );
            } else {
                return array(
                    'success' => false,
                    'error' => 'Failed to upload test file: ' . $upload_result['error']
                );
            }

        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => 'Test failed: ' . $e->getMessage()
            );
        }
    }
}
