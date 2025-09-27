<?php
/**
 * OAuth-based Google Drive integration for the Legal Document Automation plugin.
 * This provides Google Drive integration using OAuth authentication (folder-specific access).
 *
 * @package LegalDocumentAutomation
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class LDA_GoogleDriveOAuth {

    private $settings;
    private $access_token;
    private $folder_id;

    public function __construct($settings = array()) {
        $this->settings = $settings;
        $this->access_token = isset($settings['google_drive_access_token']) ? $settings['google_drive_access_token'] : '';
        $this->folder_id = isset($settings['google_drive_folder_id']) ? $settings['google_drive_folder_id'] : '';
    }

    /**
     * Check if Google Drive OAuth integration is available
     */
    public function isAvailable() {
        if (empty($this->access_token) || empty($this->folder_id)) {
            return array(
                'available' => false,
                'method' => 'none',
                'message' => 'Google Drive OAuth not configured. Please set access token and folder ID.'
            );
        }

        // Test the connection by making a simple API call
        $test_result = $this->testConnection();
        if ($test_result['success']) {
            return array(
                'available' => true,
                'method' => 'google_drive_oauth',
                'message' => 'Google Drive OAuth is ready'
            );
        } else {
            return array(
                'available' => false,
                'method' => 'none',
                'message' => 'Google Drive OAuth connection failed: ' . $test_result['error']
            );
        }
    }

    /**
     * Test the Google Drive connection
     */
    public function testConnection() {
        try {
            $url = "https://www.googleapis.com/drive/v3/files/{$this->folder_id}";
            $headers = array(
                'Authorization: Bearer ' . $this->access_token,
                'Content-Type: application/json'
            );

            $response = wp_remote_get($url, array(
                'headers' => $headers,
                'timeout' => 30
            ));

            if (is_wp_error($response)) {
                return array(
                    'success' => false,
                    'error' => 'HTTP request failed: ' . $response->get_error_message()
                );
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($response_code === 200) {
                $data = json_decode($response_body, true);
                return array(
                    'success' => true,
                    'folder_name' => $data['name'] ?? 'Unknown',
                    'folder_id' => $data['id'] ?? $this->folder_id
                );
            } else {
                $error_data = json_decode($response_body, true);
                $error_message = $error_data['error']['message'] ?? 'Unknown error';
                return array(
                    'success' => false,
                    'error' => "API Error {$response_code}: {$error_message}"
                );
            }

        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => 'Connection test failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Create or get user-specific folder within the main Google Drive folder
     */
    public function createUserFolder($user_identifier) {
        try {
            $folder_name = sanitize_file_name($user_identifier);
            
            // First, check if the user folder already exists
            $existing_folder = $this->findUserFolder($folder_name);
            if ($existing_folder) {
                return array(
                    'success' => true,
                    'folder_id' => $existing_folder['id'],
                    'folder_name' => $existing_folder['name'],
                    'web_view_link' => "https://drive.google.com/drive/folders/{$existing_folder['id']}"
                );
            }

            // Create new user folder
            $folder_metadata = array(
                'name' => $folder_name,
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => array($this->folder_id)
            );

            $url = 'https://www.googleapis.com/drive/v3/files';
            $headers = array(
                'Authorization: Bearer ' . $this->access_token,
                'Content-Type: application/json'
            );

            $response = wp_remote_post($url, array(
                'headers' => $headers,
                'body' => json_encode($folder_metadata),
                'timeout' => 30
            ));

            if (is_wp_error($response)) {
                throw new Exception('HTTP request failed: ' . $response->get_error_message());
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($response_code === 200) {
                $data = json_decode($response_body, true);
                LDA_Logger::log("Created Google Drive user folder: {$folder_name} (ID: {$data['id']})");
                
                return array(
                    'success' => true,
                    'folder_id' => $data['id'],
                    'folder_name' => $data['name'],
                    'web_view_link' => "https://drive.google.com/drive/folders/{$data['id']}"
                );
            } else {
                $error_data = json_decode($response_body, true);
                $error_message = $error_data['error']['message'] ?? 'Unknown error';
                throw new Exception("API Error {$response_code}: {$error_message}");
            }

        } catch (Exception $e) {
            LDA_Logger::error("Failed to create user folder: " . $e->getMessage());
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }

    /**
     * Find existing user folder
     */
    private function findUserFolder($folder_name) {
        try {
            $url = "https://www.googleapis.com/drive/v3/files?q=name='{$folder_name}' and parents in '{$this->folder_id}' and mimeType='application/vnd.google-apps.folder' and trashed=false";
            $headers = array(
                'Authorization: Bearer ' . $this->access_token,
                'Content-Type: application/json'
            );

            $response = wp_remote_get($url, array(
                'headers' => $headers,
                'timeout' => 30
            ));

            if (is_wp_error($response)) {
                return null;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($response_code === 200) {
                $data = json_decode($response_body, true);
                if (!empty($data['files'])) {
                    return $data['files'][0];
                }
            }

            return null;

        } catch (Exception $e) {
            LDA_Logger::error("Failed to find user folder: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Upload file to Google Drive
     */
    public function uploadFile($file_path, $folder_identifier) {
        try {
            if (!file_exists($file_path)) {
                throw new Exception("Source file does not exist: {$file_path}");
            }

            // Get or create user folder
            $folder_result = $this->createUserFolder($folder_identifier);
            if (!$folder_result['success']) {
                throw new Exception($folder_result['error']);
            }

            $user_folder_id = $folder_result['folder_id'];
            $filename = basename($file_path);
            $mime_type = $this->getMimeType($file_path);

            // Create file metadata
            $file_metadata = array(
                'name' => $filename,
                'parents' => array($user_folder_id)
            );

            // Prepare multipart upload
            $boundary = wp_generate_password(16, false);
            $delimiter = '--' . $boundary;
            $close_delimiter = $delimiter . '--';

            $body = '';
            $body .= $delimiter . "\r\n";
            $body .= 'Content-Type: application/json; charset=UTF-8' . "\r\n\r\n";
            $body .= json_encode($file_metadata) . "\r\n";
            $body .= $delimiter . "\r\n";
            $body .= 'Content-Type: ' . $mime_type . "\r\n\r\n";
            $body .= file_get_contents($file_path) . "\r\n";
            $body .= $close_delimiter;

            $url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart';
            $headers = array(
                'Authorization: Bearer ' . $this->access_token,
                'Content-Type: multipart/related; boundary=' . $boundary
            );

            $response = wp_remote_post($url, array(
                'headers' => $headers,
                'body' => $body,
                'timeout' => 60
            ));

            if (is_wp_error($response)) {
                throw new Exception('HTTP request failed: ' . $response->get_error_message());
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($response_code === 200) {
                $data = json_decode($response_body, true);
                LDA_Logger::log("File uploaded to Google Drive: {$filename} (ID: {$data['id']})");

                return array(
                    'success' => true,
                    'file_id' => $data['id'],
                    'file_name' => $data['name'],
                    'web_view_link' => "https://drive.google.com/file/d/{$data['id']}/view",
                    'download_link' => "https://drive.google.com/uc?export=download&id={$data['id']}",
                    'message' => 'File uploaded to Google Drive successfully'
                );
            } else {
                $error_data = json_decode($response_body, true);
                $error_message = $error_data['error']['message'] ?? 'Unknown error';
                throw new Exception("API Error {$response_code}: {$error_message}");
            }

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
            $url = 'https://www.googleapis.com/drive/v3/about?fields=user,storageQuota';
            $headers = array(
                'Authorization: Bearer ' . $this->access_token,
                'Content-Type: application/json'
            );

            $response = wp_remote_get($url, array(
                'headers' => $headers,
                'timeout' => 30
            ));

            if (is_wp_error($response)) {
                return array('error' => $response->get_error_message());
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);

            if ($response_code === 200) {
                $data = json_decode($response_body, true);
                return array(
                    'user_email' => $data['user']['emailAddress'] ?? 'Unknown',
                    'storage_used' => $data['storageQuota']['usage'] ?? 'Unknown',
                    'storage_limit' => $data['storageQuota']['limit'] ?? 'Unknown',
                    'method' => 'google_drive_oauth'
                );
            } else {
                return array('error' => "API Error {$response_code}");
            }

        } catch (Exception $e) {
            return array('error' => $e->getMessage());
        }
    }
}
?>
