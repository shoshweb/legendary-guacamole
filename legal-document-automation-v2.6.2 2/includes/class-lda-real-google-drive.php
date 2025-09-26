<?php
/**
 * Real Google Drive Integration
 * Uploads files to actual Google Drive using OAuth
 */

if (!defined('ABSPATH')) {
    exit;
}

class LDA_RealGoogleDrive {
    
    private $settings;
    private $access_token;
    private $client_id;
    private $client_secret;
    private $redirect_uri;
    
    public function __construct($settings = array()) {
        $this->settings = $settings;
        $this->client_id = isset($settings['google_drive_client_id']) ? $settings['google_drive_client_id'] : '';
        $this->client_secret = isset($settings['google_drive_client_secret']) ? $settings['google_drive_client_secret'] : '';
        $this->redirect_uri = admin_url('admin.php?page=legal-doc-automation&tab=google_drive');
        $this->access_token = isset($settings['google_drive_access_token']) ? $settings['google_drive_access_token'] : '';
    }
    
    /**
     * Test connection to Google Drive
     */
    public function testConnection() {
        try {
            if (empty($this->access_token)) {
                return array(
                    'success' => false,
                    'error' => 'No access token found. Please authorize the plugin with Google Drive.'
                );
            }
            
            // Test the access token by getting user info
            $response = wp_remote_get('https://www.googleapis.com/drive/v3/about?fields=user', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->access_token
                )
            ));
            
            if (is_wp_error($response)) {
                return array(
                    'success' => false,
                    'error' => 'Failed to connect to Google Drive: ' . $response->get_error_message()
                );
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (isset($body['user'])) {
                return array(
                    'success' => true,
                    'user_email' => $body['user']['emailAddress'],
                    'user_name' => $body['user']['displayName'],
                    'message' => 'Connected to Google Drive successfully'
                );
            } else {
                return array(
                    'success' => false,
                    'error' => 'Invalid response from Google Drive API'
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
     * Get authorization URL for OAuth
     */
    public function getAuthorizationUrl() {
        $params = array(
            'client_id' => $this->client_id,
            'redirect_uri' => $this->redirect_uri,
            'scope' => 'https://www.googleapis.com/auth/drive.file',
            'response_type' => 'code',
            'access_type' => 'offline',
            'prompt' => 'consent'
        );
        
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }
    
    /**
     * Exchange authorization code for access token
     */
    public function exchangeCodeForToken($code) {
        try {
            $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
                'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
                'body' => http_build_query(array(
                    'client_id' => $this->client_id,
                    'client_secret' => $this->client_secret,
                    'code' => $code,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->redirect_uri
                ))
            ));
            
            if (is_wp_error($response)) {
                return array(
                    'success' => false,
                    'error' => 'Failed to exchange code: ' . $response->get_error_message()
                );
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (isset($body['access_token'])) {
                // Save the access token to settings
                $settings = get_option('lda_settings', array());
                $settings['google_drive_access_token'] = $body['access_token'];
                if (isset($body['refresh_token'])) {
                    $settings['google_drive_refresh_token'] = $body['refresh_token'];
                }
                update_option('lda_settings', $settings);
                
                $this->access_token = $body['access_token'];
                
                return array(
                    'success' => true,
                    'access_token' => $body['access_token'],
                    'message' => 'Successfully authorized with Google Drive'
                );
            } else {
                return array(
                    'success' => false,
                    'error' => 'Failed to get access token: ' . (isset($body['error_description']) ? $body['error_description'] : 'Unknown error')
                );
            }
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => 'Token exchange failed: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Create folder in Google Drive
     */
    public function createFolder($folder_name, $parent_folder_id = null) {
        try {
            if (empty($this->access_token)) {
                return array('success' => false, 'error' => 'No access token available');
            }
            
            $metadata = array(
                'name' => $folder_name,
                'mimeType' => 'application/vnd.google-apps.folder'
            );
            
            if ($parent_folder_id) {
                $metadata['parents'] = array($parent_folder_id);
            }
            
            $response = wp_remote_post('https://www.googleapis.com/drive/v3/files', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->access_token,
                    'Content-Type' => 'application/json'
                ),
                'body' => json_encode($metadata)
            ));
            
            if (is_wp_error($response)) {
                return array('success' => false, 'error' => 'Failed to create folder: ' . $response->get_error_message());
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (isset($body['id'])) {
                return array(
                    'success' => true,
                    'folder_id' => $body['id'],
                    'folder_name' => $folder_name,
                    'folder_url' => 'https://drive.google.com/drive/folders/' . $body['id']
                );
            } else {
                return array('success' => false, 'error' => 'Failed to create folder: Invalid response');
            }
            
        } catch (Exception $e) {
            return array('success' => false, 'error' => 'Folder creation failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Upload file to Google Drive
     */
    public function uploadFile($file_path, $folder_id = null, $filename = null) {
        try {
            if (empty($this->access_token)) {
                return array('success' => false, 'error' => 'No access token available');
            }
            
            if (!file_exists($file_path)) {
                return array('success' => false, 'error' => 'File not found: ' . $file_path);
            }
            
            $filename = $filename ?: basename($file_path);
            $mime_type = $this->getMimeType($file_path);
            
            // Prepare metadata
            $metadata = array(
                'name' => $filename
            );
            
            if ($folder_id) {
                $metadata['parents'] = array($folder_id);
            }
            
            // Create multipart upload
            $boundary = wp_generate_password(16, false);
            $delimiter = '--' . $boundary;
            $close_delimiter = $delimiter . '--';
            
            $body = '';
            $body .= $delimiter . "\r\n";
            $body .= 'Content-Type: application/json; charset=UTF-8' . "\r\n\r\n";
            $body .= json_encode($metadata) . "\r\n";
            $body .= $delimiter . "\r\n";
            $body .= 'Content-Type: ' . $mime_type . "\r\n\r\n";
            $body .= file_get_contents($file_path) . "\r\n";
            $body .= $close_delimiter;
            
            $response = wp_remote_post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->access_token,
                    'Content-Type' => 'multipart/related; boundary=' . $boundary
                ),
                'body' => $body
            ));
            
            if (is_wp_error($response)) {
                return array('success' => false, 'error' => 'Upload failed: ' . $response->get_error_message());
            }
            
            $body = json_decode(wp_remote_retrieve_body($response), true);
            
            if (isset($body['id'])) {
                return array(
                    'success' => true,
                    'file_id' => $body['id'],
                    'file_name' => $filename,
                    'file_url' => 'https://drive.google.com/file/d/' . $body['id'] . '/view',
                    'file_share_url' => 'https://drive.google.com/open?id=' . $body['id']
                );
            } else {
                return array('success' => false, 'error' => 'Upload failed: Invalid response');
            }
            
        } catch (Exception $e) {
            return array('success' => false, 'error' => 'Upload failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Create user folder (alias for createFolder)
     */
    public function createUserFolder($user_email, $folder_name = 'Legal Documents') {
        return $this->createFolder($folder_name);
    }
    
    /**
     * Get MIME type for file
     */
    private function getMimeType($file_path) {
        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
        
        $mime_types = array(
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'pdf' => 'application/pdf',
            'txt' => 'text/plain',
            'doc' => 'application/msword'
        );
        
        return isset($mime_types[$extension]) ? $mime_types[$extension] : 'application/octet-stream';
    }
    
    /**
     * Check if integration is available
     */
    public function isAvailable() {
        return array(
            'available' => !empty($this->client_id) && !empty($this->client_secret),
            'message' => !empty($this->client_id) && !empty($this->client_secret) ? 
                'Google Drive OAuth credentials configured' : 
                'Google Drive OAuth credentials not configured'
        );
    }
}
