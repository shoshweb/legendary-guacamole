<?php
/**
 * Simple Google Drive API Integration
 * Uses WordPress HTTP API instead of the massive Google client library
 */

if (!defined('ABSPATH')) {
    exit;
}

class LDA_GoogleDriveSimpleAPI {
    
    private $credentials;
    private $access_token;
    
    public function __construct($credentials_path = null) {
        if ($credentials_path && file_exists($credentials_path)) {
            $this->credentials = json_decode(file_get_contents($credentials_path), true);
        }
    }
    
    /**
     * Get access token using service account credentials
     */
    private function getAccessToken() {
        if ($this->access_token) {
            return $this->access_token;
        }
        
        if (!$this->credentials) {
            throw new Exception('Google Drive credentials not found');
        }
        
        $jwt = $this->createJWT();
        
        $response = wp_remote_post('https://oauth2.googleapis.com/token', array(
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body' => http_build_query(array(
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ))
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('Failed to get access token: ' . $response->get_error_message());
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['access_token'])) {
            $this->access_token = $body['access_token'];
            return $this->access_token;
        }
        
        throw new Exception('Invalid response from Google: ' . wp_remote_retrieve_body($response));
    }
    
    /**
     * Create JWT token for service account authentication
     */
    private function createJWT() {
        $header = json_encode(array('typ' => 'JWT', 'alg' => 'RS256'));
        $now = time();
        $payload = json_encode(array(
            'iss' => $this->credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/drive.file',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now
        ));
        
        $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        
        $signature = '';
        $private_key = $this->credentials['private_key'];
        openssl_sign($base64Header . '.' . $base64Payload, $signature, $private_key, 'SHA256');
        $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return $base64Header . '.' . $base64Payload . '.' . $base64Signature;
    }
    
    /**
     * Upload file to Google Drive
     */
    public function uploadFile($file_path, $folder_id = null, $filename = null) {
        $access_token = $this->getAccessToken();
        
        if (!$filename) {
            $filename = basename($file_path);
        }
        
        $metadata = array(
            'name' => $filename,
            'parents' => $folder_id ? array($folder_id) : array()
        );
        
        // Upload metadata
        $response = wp_remote_post('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type' => 'multipart/related; boundary=foo_bar_baz'
            ),
            'body' => $this->createMultipartBody($metadata, $file_path)
        ));
        
        if (is_wp_error($response)) {
            throw new Exception('Upload failed: ' . $response->get_error_message());
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['id'])) {
            return array(
                'success' => true,
                'file_id' => $body['id'],
                'file_url' => 'https://drive.google.com/file/d/' . $body['id'] . '/view',
                'file_name' => $filename
            );
        }
        
        throw new Exception('Upload failed: ' . wp_remote_retrieve_body($response));
    }
    
    /**
     * Create multipart body for file upload
     */
    private function createMultipartBody($metadata, $file_path) {
        $boundary = 'foo_bar_baz';
        $body = '';
        
        // Metadata part
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Type: application/json; charset=UTF-8' . "\r\n\r\n";
        $body .= json_encode($metadata) . "\r\n";
        
        // File part
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Type: application/octet-stream' . "\r\n\r\n";
        $body .= file_get_contents($file_path) . "\r\n";
        
        $body .= '--' . $boundary . '--';
        
        return $body;
    }
    
    /**
     * Test connection
     */
    public function testConnection() {
        try {
            $access_token = $this->getAccessToken();
            return array(
                'success' => true,
                'message' => 'Connected successfully',
                'service_account' => $this->credentials['client_email']
            );
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
}
