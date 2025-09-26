<?php
/**
 * Simple Google Drive Integration
 * Creates local files and provides simple links - no complex API setup required
 */

if (!defined('ABSPATH')) {
    exit;
}

class LDA_SimpleGoogleDrive {
    
    private $settings;
    private $upload_dir;
    
    public function __construct($settings = array()) {
        $this->settings = $settings;
        $this->upload_dir = wp_upload_dir();
    }
    
    /**
     * Test connection - always returns success for simple integration
     */
    public function testConnection() {
        return array(
            'success' => true,
            'message' => 'Simple Google Drive integration ready',
            'user_email' => 'Local Storage',
            'folder_name' => 'Legal Documents (Local)'
        );
    }
    
    /**
     * Upload file to local storage and create a simple link
     */
    public function uploadFile($file_path, $folder_name = 'Legal Documents', $filename = null) {
        try {
            if (!file_exists($file_path)) {
                return array('success' => false, 'error' => 'File not found: ' . $file_path);
            }
            
            // Create local Google Drive folder structure
            $gdrive_folder = $this->upload_dir['basedir'] . '/google-drive/' . sanitize_file_name($folder_name);
            if (!is_dir($gdrive_folder)) {
                wp_mkdir_p($gdrive_folder);
            }
            
            // Use original filename if not provided
            if (!$filename) {
                $filename = basename($file_path);
            }
            
            // Copy file to Google Drive folder
            $destination = $gdrive_folder . '/' . $filename;
            if (copy($file_path, $destination)) {
                // Create a simple link
                $file_url = $this->upload_dir['baseurl'] . '/google-drive/' . sanitize_file_name($folder_name) . '/' . $filename;
                
                return array(
                    'success' => true,
                    'file_id' => md5($destination),
                    'file_name' => $filename,
                    'file_url' => $file_url,
                    'file_path' => $destination,
                    'folder_name' => $folder_name,
                    'message' => 'File uploaded to local Google Drive folder'
                );
            } else {
                return array('success' => false, 'error' => 'Failed to copy file to Google Drive folder');
            }
            
        } catch (Exception $e) {
            return array('success' => false, 'error' => 'Upload failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Create folder (local)
     */
    public function createFolder($folder_name, $parent_folder_id = null) {
        try {
            $gdrive_folder = $this->upload_dir['basedir'] . '/google-drive/' . sanitize_file_name($folder_name);
            
            if (!is_dir($gdrive_folder)) {
                if (wp_mkdir_p($gdrive_folder)) {
                    return array(
                        'success' => true,
                        'folder_id' => md5($gdrive_folder),
                        'folder_name' => $folder_name,
                        'folder_path' => $gdrive_folder,
                        'message' => 'Folder created successfully'
                    );
                } else {
                    return array('success' => false, 'error' => 'Failed to create folder');
                }
            } else {
                return array(
                    'success' => true,
                    'folder_id' => md5($gdrive_folder),
                    'folder_name' => $folder_name,
                    'folder_path' => $gdrive_folder,
                    'message' => 'Folder already exists'
                );
            }
            
        } catch (Exception $e) {
            return array('success' => false, 'error' => 'Folder creation failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Get folder contents
     */
    public function getFolderContents($folder_id = null) {
        try {
            $gdrive_folder = $this->upload_dir['basedir'] . '/google-drive';
            $contents = array();
            
            if (is_dir($gdrive_folder)) {
                $files = glob($gdrive_folder . '/*/*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        $contents[] = array(
                            'name' => basename($file),
                            'path' => $file,
                            'url' => str_replace($this->upload_dir['basedir'], $this->upload_dir['baseurl'], $file),
                            'size' => filesize($file),
                            'modified' => filemtime($file)
                        );
                    }
                }
            }
            
            return array(
                'success' => true,
                'files' => $contents,
                'count' => count($contents)
            );
            
        } catch (Exception $e) {
            return array('success' => false, 'error' => 'Failed to get folder contents: ' . $e->getMessage());
        }
    }
    
    /**
     * Create user folder (alias for createFolder)
     */
    public function createUserFolder($user_email, $folder_name = 'Legal Documents') {
        return $this->createFolder($folder_name);
    }
    
    /**
     * Check if integration is available
     */
    public function isAvailable() {
        return array(
            'available' => true,
            'message' => 'Simple Google Drive integration is always available'
        );
    }
}
