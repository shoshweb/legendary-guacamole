<?php
/**
 * A simple file-based logger.
 *
 * @package LegalDocumentAutomation
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class LDA_Logger {

    private static function get_log_file_path() {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/lda-logs/';

        if (!is_dir($log_dir)) {
            wp_mkdir_p($log_dir);
        }

        return $log_dir . 'lda-main.log';
    }

    /**
     * Logs a message to a file.
     *
     * @param string $message The message to log.
     * @param string $level The log level (e.g., INFO, WARNING, ERROR).
     */
    public static function log($message, $level = 'INFO') {
        $log_file = self::get_log_file_path();
        $timestamp = current_time('mysql');
        $level_str = strtoupper($level);
        $formatted_message = "[{$timestamp}] [{$level_str}] " . $message . "\n";

        @file_put_contents($log_file, $formatted_message, FILE_APPEND);
    }

    /**
     * Logs an error message.
     *
     * @param string $message The error message to log.
     */
    public static function error($message) {
        self::log($message, 'ERROR');
    }

    /**
     * Logs a warning message.
     *
     * @param string $message The warning message to log.
     */
    public static function warn($message) {
        self::log($message, 'WARN');
    }

    /**
     * Logs a warning message (alias for warn).
     *
     * @param string $message The warning message to log.
     */
    public static function warning($message) {
        self::log($message, 'WARN');
    }

    /**
     * Logs an array with truncation to avoid log file issues.
     *
     * @param string $label The label for the array.
     * @param array $array The array to log.
     * @param int $max_value_length Maximum length for array values.
     */
    public static function logArray($label, $array, $max_value_length = 50) {
        $summary = array();
        foreach ($array as $key => $value) {
            if (is_string($value) && strlen($value) > $max_value_length) {
                $summary[$key] = substr($value, 0, $max_value_length) . '...';
            } else {
                $summary[$key] = $value;
            }
        }
        self::log($label . " (" . count($array) . " items): " . json_encode($summary, JSON_PRETTY_PRINT));
    }

    /**
     * Logs a debug message.
     *
     * @param string $message The debug message to log.
     */
    public static function debug($message) {
        $settings = get_option('lda_settings', array());
        if (!empty($settings['debug_mode'])) {
            self::log($message, 'DEBUG');
        }
    }

    /**
     * Retrieves recent log entries.
     *
     * @return array An array of log entry strings.
     */
    public static function getRecentLogs() {
        return self::getFilteredLogs('', 7, 100);
    }

    /**
     * Retrieves filtered log entries.
     *
     * @param string $level The log level to filter by (e.g., 'ERROR', 'INFO').
     * @param int $days The number of days of logs to retrieve.
     * @param int $limit The maximum number of log entries to return.
     * @return array An array of log entry strings.
     */
    public static function getFilteredLogs($level = '', $days = 7, $limit = 100) {
        $log_file = self::get_log_file_path();

        if (!file_exists($log_file)) {
            return array('Log file not found.');
        }

        $logs = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (empty($logs)) {
            return array();
        }

        $filtered_logs = array();
        $cutoff_timestamp = time() - ($days * 24 * 60 * 60);

        foreach (array_reverse($logs) as $log_entry) {
            if (count($filtered_logs) >= $limit) {
                break;
            }

            preg_match('/\[(.*?)\]\s\[(.*?)\]/', $log_entry, $matches);
            
            if (count($matches) === 3) {
                $log_timestamp = strtotime($matches[1]);
                $log_level = $matches[2];

                if ($log_timestamp >= $cutoff_timestamp) {
                    if (empty($level) || $log_level === $level) {
                        $filtered_logs[] = $log_entry;
                    }
                }
            }
        }
        
        return $filtered_logs;
    }

    /**
     * Retrieves statistics about the logs.
     *
     * @return array An array of log statistics.
     */
    public static function getLogStats() {
        $log_file = self::get_log_file_path();

        if (!file_exists($log_file) || !is_readable($log_file)) {
            return array(
                'total_entries' => 0,
                'error_count' => 0,
                'warning_count' => 0,
                'latest_error' => 'Log file not found or not readable.',
            );
        }

        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            $lines = array();
        }

        $stats = array(
            'total_entries' => count($lines),
            'error_count' => 0,
            'warning_count' => 0,
            'latest_error' => null,
        );

        $latest_error_timestamp = 0;

        foreach ($lines as $line) {
            if (strpos($line, '[ERROR]') !== false) {
                $stats['error_count']++;

                // Parse log format: [timestamp] [LEVEL] message
                preg_match('/^\[([^\]]+)\]\s*\[([^\]]+)\]\s*(.*)$/', $line, $matches);
                if (isset($matches[1])) {
                    $timestamp = strtotime($matches[1]);
                    if ($timestamp > $latest_error_timestamp) {
                        $latest_error_timestamp = $timestamp;
                        // Store error info as array with timestamp and message
                        $stats['latest_error'] = array(
                            'timestamp' => $matches[1],
                            'message' => isset($matches[3]) ? trim($matches[3]) : $line
                        );
                    }
                }
            }
            if (strpos($line, '[WARNING]') !== false) {
                $stats['warning_count']++;
            }
        }

        return $stats;
    }

    /**
     * Cleans up old log entries.
     *
     * @param int $days_to_keep The number of days to keep log entries for.
     */
    public static function cleanOldLogs($days_to_keep = 30) {
        $log_file = self::get_log_file_path();

        if (!file_exists($log_file) || !is_readable($log_file) || !is_writable($log_file)) {
            return;
        }

        $lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (empty($lines)) {
            return;
        }

        $cutoff_timestamp = time() - ($days_to_keep * 24 * 60 * 60);
        $fresh_logs = array();

        foreach ($lines as $line) {
            preg_match('/\[(.*?)\]/', $line, $matches);
            if (isset($matches[1])) {
                $log_timestamp = strtotime($matches[1]);
                if ($log_timestamp >= $cutoff_timestamp) {
                    $fresh_logs[] = $line . "\n";
                }
            }
        }

        file_put_contents($log_file, implode('', $fresh_logs));
    }

    /**
     * Clears the entire log file.
     *
     * @return bool True on success, false on failure.
     */
    public static function clearLogs() {
        $log_file = self::get_log_file_path();
        if (file_exists($log_file) && is_writable($log_file)) {
            if (file_put_contents($log_file, '') !== false) {
                // Add a new entry to say the log was cleared.
                self::log('Log file cleared.');
                return true;
            }
        }
        return false;
    }
}
