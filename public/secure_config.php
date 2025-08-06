<?php
/**
 * Secure Configuration Loader
 * This file safely loads configurations from the private directory
 * Never expose this file's contents to public access
 */

// Security: Prevent direct access
if (!defined('SECURE_ACCESS')) {
    http_response_code(403);
    die('Direct access forbidden');
}

// Define the base path for private files
define('PRIVATE_PATH', dirname(__DIR__) . '/private/');
define('CONFIG_PATH', PRIVATE_PATH . 'config/');
define('INCLUDES_PATH', PRIVATE_PATH . 'includes/');
define('ADMIN_PATH', PRIVATE_PATH . 'admin/');
define('DATABASE_PATH', dirname(__DIR__) . '/secure-database/');
define('LOGS_PATH', dirname(__DIR__) . '/logs/');

// Define the base URL for web assets
// Adjust this path according to your server's URL structure
define('BASE_URL', '/KGX/public/');

// Function to securely include configuration files
function loadSecureConfig($configFile) {
    $configPath = CONFIG_PATH . $configFile;
    
    if (!file_exists($configPath)) {
        error_log("Configuration file not found: " . $configPath);
        return false;
    }
    
    // Security check: ensure file is within allowed directory
    $realConfigPath = realpath($configPath);
    $realAllowedPath = realpath(CONFIG_PATH);
    
    if (strpos($realConfigPath, $realAllowedPath) !== 0) {
        error_log("Security violation: Attempted to access file outside config directory");
        return false;
    }
    
    return include_once $configPath;
}

// Function to securely include files from private/includes
function loadSecureInclude($includeFile) {
    $includePath = INCLUDES_PATH . $includeFile;
    
    if (!file_exists($includePath)) {
        error_log("Include file not found: " . $includePath);
        return false;
    }
    
    // Security check
    $realIncludePath = realpath($includePath);
    $realAllowedPath = realpath(INCLUDES_PATH);
    
    if (strpos($realIncludePath, $realAllowedPath) !== 0) {
        error_log("Security violation: Attempted to access file outside includes directory");
        return false;
    }
    
    return include_once $includePath;
}

// Set timezone for consistent date handling across all user-side files
date_default_timezone_set('Asia/Kolkata');

// Initialize secure access
if (!defined('SECURE_ACCESS')) {
    define('SECURE_ACCESS', true);
}
?>
