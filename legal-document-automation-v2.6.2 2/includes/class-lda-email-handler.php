<?php
/**
 * Handles sending emails for the Legal Document Automation plugin.
 *
 * @package LegalDocumentAutomation
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class LDA_EmailHandler {

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
     * Sends an email with an attachment.
     *
     * @param string $to The recipient's email address.
     * @param string $subject The email subject.
     * @param string $message The email body.
     * @param string $attachment_path The path to the file to attach.
     * @return array
     */
    public function send_document_email($to, $subject, $message, $attachment_path) {
        return $this->send_document_email_with_attachments($to, $subject, $message, array($attachment_path));
    }

    /**
     * Sends an email with multiple attachments (DOCX and PDF).
     *
     * @param string $to The recipient's email address.
     * @param string $subject The email subject.
     * @param string $message The email body.
     * @param array $attachment_paths Array of file paths to attach.
     * @return array
     */
    public function send_document_email_with_attachments($to, $subject, $message, $attachment_paths) {
        $from_name = !empty($this->settings['from_name']) ? $this->settings['from_name'] : get_bloginfo('name');
        $from_email = !empty($this->settings['from_email']) ? $this->settings['from_email'] : get_option('admin_email');

        $headers = array(
            'From: ' . $from_name . ' <' . $from_email . '>',
            'Content-Type: text/html; charset=UTF-8',
        );

        LDA_Logger::log("Attempting to send document to: {$to} with subject: {$subject}");

        $valid_attachments = array();
        foreach ($attachment_paths as $path) {
            if (file_exists($path)) {
                $valid_attachments[] = $path;
            } else {
                LDA_Logger::error("Attachment file not found at path: {$path}");
            }
        }

        if (empty($valid_attachments)) {
            return array('success' => false, 'error_message' => 'No valid attachment files found.');
        }

        // Log email attempt details
        LDA_Logger::log("Email details - To: {$to}, Subject: {$subject}, Attachments: " . count($valid_attachments));
        
        $result = wp_mail($to, $subject, $message, $headers, $valid_attachments);

        if ($result) {
            LDA_Logger::log("Successfully sent document email to: {$to}");
            return array('success' => true);
        } else {
            global $phpmailer;
            $error_message = 'Unknown email error.';
            
            if (isset($phpmailer) && is_object($phpmailer)) {
                $error_message = $phpmailer->ErrorInfo ?? 'PHPMailer error occurred.';
            }
            
            // Check for common WordPress email issues
            if (!function_exists('wp_mail')) {
                $error_message = 'WordPress wp_mail function not available.';
            }
            
            LDA_Logger::error("Failed to send document email to {$to}: " . $error_message);
            return array('success' => false, 'error_message' => $error_message);
        }
    }

    /**
     * Sends a notification email to the site admin.
     *
     * @param string $subject The email subject.
     * @param string $message The email body.
     * @return array
     */
    public function send_admin_notification($subject, $message) {
        $admin_email = get_option('admin_email');
        $from_name = !empty($this->settings['from_name']) ? $this->settings['from_name'] : get_bloginfo('name');
        $from_email = !empty($this->settings['from_email']) ? $this->settings['from_email'] : $admin_email;

        $headers = array(
            'From: ' . $from_name . ' <' . $from_email . '>',
            'Content-Type: text/html; charset=UTF-8',
        );

        LDA_Logger::log("Attempting to send admin notification with subject: {$subject}");

        $result = wp_mail($admin_email, $subject, $message, $headers);

        if ($result) {
            LDA_Logger::log("Successfully sent admin notification email.");
            return array('success' => true);
        } else {
            $error_message = 'The admin notification email could not be sent. Please check your WordPress email configuration.';
            LDA_Logger::error("Failed to send admin notification email. " . $error_message);
            return array('success' => false, 'error_message' => $error_message);
        }
    }

    /**
     * A simple placeholder method for testing email sending functionality.
     *
     * @param string $test_email The email address to send a test to.
     * @return array
     */
    public function sendTestEmail($test_email) {
        $subject = !empty($this->settings['email_subject']) ? $this->settings['email_subject'] : 'Test Email from Legal Document Automation';
        $message = !empty($this->settings['email_message']) ? $this->settings['email_message'] : 'This is a test email to confirm that your email settings are working correctly.';

        $from_name = !empty($this->settings['from_name']) ? $this->settings['from_name'] : get_bloginfo('name');
        $from_email = !empty($this->settings['from_email']) ? $this->settings['from_email'] : get_option('admin_email');

        $headers = array(
            'From: ' . $from_name . ' <' . $from_email . '>',
            'Content-Type: text/html; charset=UTF-8',
        );

        LDA_Logger::log("Attempting to send test email to: {$test_email}");

        $result = wp_mail($test_email, $subject, $message, $headers);

        if ($result) {
            LDA_Logger::log("Successfully sent test email to: {$test_email}");
            return array('success' => true);
        } else {
            $error_message = 'The test email could not be sent. Please check your WordPress email configuration.';
            LDA_Logger::error("Failed to send test email to: {$test_email}. " . $error_message);
            return array('success' => false, 'error_message' => $error_message);
        }
    }
}
