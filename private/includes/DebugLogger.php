<?php

/**
 * Simple debug logger for diagnosing issues with upload process.
 * Add this logger to catch and record any errors encountered.
 */

class DebugLogger {
    private $logFile;

    public function __construct($logFile = 'debug.log') {
        $this->logFile = __DIR__ . '/' . $logFile;
    }

    public function log($message) {
        $date = date('Y-m-d H:i:s');
        error_log("[{$date}] {$message}\n", 3, $this->logFile);
    }
}

// Example usage in your upload script
// $logger = new DebugLogger();
// catch (Exception $e) {
//     $logger->log('Upload failed: ' . $e->getMessage());
// }

?>
