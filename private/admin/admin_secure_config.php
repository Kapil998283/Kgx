<?php
/**
 * Admin Secure Configuration Loader
 * This file safely loads admin configurations and defines secure paths
 * Provides admin-specific paths, URLs, and security functions
 * 
 * @version 1.0
 * @author KGX Admin System
 */

// Security: Prevent direct access
if (!defined('ADMIN_SECURE_ACCESS')) {
    http_response_code(403);
    die('Direct access forbidden - Admin area only');
}

// Set timezone for consistent date handling across all admin-side files
date_default_timezone_set('Asia/Kolkata');

// =====================================================
// ADMIN PATH DEFINITIONS
// =====================================================

// Base paths
define('ADMIN_ROOT_PATH', __DIR__ . '/');
define('ADMIN_PRIVATE_PATH', dirname(dirname(__DIR__)) . '/private/');
define('ADMIN_PUBLIC_PATH', dirname(dirname(__DIR__)) . '/public/');
define('ADMIN_PROJECT_ROOT', dirname(dirname(dirname(__DIR__))));

// Admin specific paths
define('ADMIN_PATH', ADMIN_PRIVATE_PATH . 'admin/');
define('ADMIN_INCLUDES_PATH', ADMIN_PATH . 'includes/');
define('ADMIN_CONFIG_PATH', ADMIN_PATH . 'config/');
define('ADMIN_VIEWS_PATH', ADMIN_PATH . 'views/');
define('ADMIN_ASSETS_PATH', ADMIN_PATH . 'assets/');
define('ADMIN_CSS_PATH', ADMIN_PATH . 'css/');
define('ADMIN_JS_PATH', ADMIN_PATH . 'js/');
define('ADMIN_UPLOADS_PATH', ADMIN_PATH . 'uploads/');

// Dashboard and modules
define('ADMIN_DASHBOARD_PATH', ADMIN_PATH . 'dashboard/');
define('ADMIN_USERS_PATH', ADMIN_PATH . 'users/');
define('ADMIN_TOURNAMENT_PATH', ADMIN_PATH . 'tournament/');
define('ADMIN_MATCHES_PATH', ADMIN_PATH . 'matches/');
define('ADMIN_STREAMS_PATH', ADMIN_PATH . 'live-streams/');

// Shared private paths
define('ADMIN_SHARED_INCLUDES_PATH', ADMIN_PRIVATE_PATH . 'includes/');
define('ADMIN_SHARED_CONFIG_PATH', ADMIN_PRIVATE_PATH . 'config/');

// Logs and security - Cross-platform paths
// Try multiple locations based on system capabilities
function getSecureAdminPath($type) {
    $basePaths = [
        // Primary: Outside web root (most secure)
        dirname(dirname(dirname(__DIR__))) . '/admin-' . $type . '/',
        // Fallback 1: Inside private directory
        ADMIN_PRIVATE_PATH . 'admin-' . $type . '/',
        // Fallback 2: Inside admin directory (least secure but works everywhere)
        ADMIN_PATH . $type . '/'
    ];
    
    foreach ($basePaths as $path) {
        // Check if we can create/access this directory
        if (is_dir($path) || @mkdir($path, 0750, true)) {
            return $path;
        }
    }
    
    // Last resort: use system temp directory
    return sys_get_temp_dir() . '/kgx-admin-' . $type . '/';
}

define('ADMIN_LOGS_PATH', getSecureAdminPath('logs'));
define('ADMIN_TEMP_PATH', getSecureAdminPath('temp'));
define('ADMIN_BACKUP_PATH', getSecureAdminPath('backups'));

// =====================================================
// ADMIN URL DEFINITIONS
// =====================================================

// Determine protocol
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? 'https://' : 'http://';

// Get host
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';

// Get script directory path
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);

// Clean up script directory for admin URLs
$adminDir = str_replace('\\', '/', $scriptDir);
if (strpos($adminDir, '/private/admin') !== false) {
    $adminDir = str_replace('/private/admin', '', $adminDir);
}

// Define base URLs
define('ADMIN_BASE_URL', $protocol . $host . $adminDir . '/private/admin/');
define('ADMIN_PUBLIC_URL', $protocol . $host . $adminDir . '/public/');
define('ADMIN_ASSETS_URL', ADMIN_BASE_URL . 'assets/');
define('ADMIN_CSS_URL', ADMIN_BASE_URL . 'css/');
define('ADMIN_JS_URL', ADMIN_BASE_URL . 'js/');
define('ADMIN_UPLOADS_URL', ADMIN_BASE_URL . 'uploads/');

// Module URLs
define('ADMIN_DASHBOARD_URL', ADMIN_BASE_URL . 'dashboard/');
define('ADMIN_USERS_URL', ADMIN_BASE_URL . 'users/');
define('ADMIN_TOURNAMENT_URL', ADMIN_BASE_URL . 'tournament/');
define('ADMIN_MATCHES_URL', ADMIN_BASE_URL . 'matches/');
define('ADMIN_STREAMS_URL', ADMIN_BASE_URL . 'live-streams/');

// API and AJAX URLs
define('ADMIN_API_URL', ADMIN_BASE_URL . 'api/');
define('ADMIN_AJAX_URL', ADMIN_BASE_URL . 'ajax/');

// =====================================================
// SECURITY FUNCTIONS
// =====================================================

/**
 * Securely include admin configuration files
 */
function loadAdminConfig($configFile) {
    $configPath = ADMIN_CONFIG_PATH . $configFile;
    
    if (!file_exists($configPath)) {
        error_log("Admin configuration file not found: " . $configPath);
        return false;
    }
    
    // Security check: ensure file is within allowed directory
    $realConfigPath = realpath($configPath);
    $realAllowedPath = realpath(ADMIN_CONFIG_PATH);
    
    if (strpos($realConfigPath, $realAllowedPath) !== 0) {
        error_log("Admin Security violation: Attempted to access config file outside admin config directory");
        return false;
    }
    
    return include_once $configPath;
}

/**
 * Securely include admin files
 */
function loadAdminInclude($includeFile) {
    $includePath = ADMIN_INCLUDES_PATH . $includeFile;
    
    if (!file_exists($includePath)) {
        error_log("Admin include file not found: " . $includePath);
        return false;
    }
    
    // Security check
    $realIncludePath = realpath($includePath);
    $realAllowedPath = realpath(ADMIN_INCLUDES_PATH);
    
    if (strpos($realIncludePath, $realAllowedPath) !== 0) {
        error_log("Admin Security violation: Attempted to access file outside admin includes directory");
        return false;
    }
    
    return include_once $includePath;
}

/**
 * Securely include shared private files
 */
function loadSharedInclude($includeFile) {
    $includePath = ADMIN_SHARED_INCLUDES_PATH . $includeFile;
    
    if (!file_exists($includePath)) {
        error_log("Shared include file not found: " . $includePath);
        return false;
    }
    
    // Security check
    $realIncludePath = realpath($includePath);
    $realAllowedPath = realpath(ADMIN_SHARED_INCLUDES_PATH);
    
    if (strpos($realIncludePath, $realAllowedPath) !== 0) {
        error_log("Admin Security violation: Attempted to access file outside shared includes directory");
        return false;
    }
    
    return include_once $includePath;
}

/**
 * Generate secure admin URL
 */
function adminUrl($path = '', $params = []) {
    $url = ADMIN_BASE_URL . ltrim($path, '/');
    
    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }
    
    return $url;
}

/**
 * Generate admin asset URL
 */
function adminAssetUrl($asset = '') {
    return ADMIN_ASSETS_URL . ltrim($asset, '/');
}

/**
 * Generate admin CSS URL
 */
function adminCssUrl($css = '') {
    return ADMIN_CSS_URL . ltrim($css, '/');
}

/**
 * Generate admin JS URL
 */
function adminJsUrl($js = '') {
    return ADMIN_JS_URL . ltrim($js, '/');
}

/**
 * Check if file is within admin directory
 */
function isAdminPath($filePath) {
    $realPath = realpath($filePath);
    $realAdminPath = realpath(ADMIN_PATH);
    
    return $realPath && $realAdminPath && strpos($realPath, $realAdminPath) === 0;
}

/**
 * Sanitize admin input
 */
function adminSanitize($input, $type = 'string') {
    switch ($type) {
        case 'int':
            return (int) $input;
        case 'float':
            return (float) $input;
        case 'email':
            return filter_var($input, FILTER_SANITIZE_EMAIL);
        case 'url':
            return filter_var($input, FILTER_SANITIZE_URL);
        case 'html':
            return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        default:
            return trim(strip_tags($input));
    }
}

/**
 * Validate admin CSRF token
 */
function validateAdminCSRF($token) {
    return isset($_SESSION['admin_csrf_token']) && 
           hash_equals($_SESSION['admin_csrf_token'], $token);
}

/**
 * Generate admin CSRF token
 */
function generateAdminCSRF() {
    if (!isset($_SESSION['admin_csrf_token'])) {
        $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['admin_csrf_token'];
}

/**
 * Log admin security events
 */
function logAdminSecurity($event, $details = '', $level = 'WARNING') {
    $logFile = ADMIN_LOGS_PATH . 'security-' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    $logEntry = "[{$timestamp}] {$level}: {$event} | IP: {$ip} | Details: {$details} | User-Agent: {$userAgent}" . PHP_EOL;
    
    // Create logs directory if it doesn't exist
    if (!is_dir(ADMIN_LOGS_PATH)) {
        @mkdir(ADMIN_LOGS_PATH, 0750, true);
    }
    
    // Try to write to log file with error handling
    if (is_dir(ADMIN_LOGS_PATH) && is_writable(ADMIN_LOGS_PATH)) {
        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    } else {
        // Fallback: use error_log for critical security events
        error_log("[ADMIN SECURITY] {$level}: {$event} | IP: {$ip} | Details: {$details}");
    }
}

/**
 * Check admin rate limiting
 */
function checkAdminRateLimit($action, $limit = 10, $window = 300) {
    $key = 'admin_rate_' . $action . '_' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    
    if (!isset($_SESSION[$key])) {
        $_SESSION[$key] = ['count' => 0, 'time' => time()];
    }
    
    $data = $_SESSION[$key];
    
    // Reset if window expired
    if (time() - $data['time'] > $window) {
        $_SESSION[$key] = ['count' => 1, 'time' => time()];
        return true;
    }
    
    // Check limit
    if ($data['count'] >= $limit) {
        logAdminSecurity("Rate limit exceeded for action: {$action}");
        return false;
    }
    
    $_SESSION[$key]['count']++;
    return true;
}

// =====================================================
// ENVIRONMENT DETECTION
// =====================================================

// Detect environment
function getAdminEnvironment() {
    if (defined('ADMIN_ENVIRONMENT')) {
        return ADMIN_ENVIRONMENT;
    }
    
    // Check for common development indicators
    if (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false ||
        strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false ||
        strpos($_SERVER['HTTP_HOST'] ?? '', '.local') !== false) {
        return 'development';
    }
    
    return 'production';
}

define('ADMIN_ENVIRONMENT', getAdminEnvironment());
define('ADMIN_DEBUG', ADMIN_ENVIRONMENT === 'development');

// =====================================================
// SUPABASE CONNECTION
// =====================================================

// Include Supabase configuration and client
require_once ADMIN_SHARED_CONFIG_PATH . 'supabase.php';
require_once ADMIN_SHARED_INCLUDES_PATH . 'SupabaseClient.php';

// Note: getSupabaseConnection() function is defined in admin-auth.php
// to avoid duplication conflicts

// =====================================================
// INITIALIZATION
// =====================================================

// Set admin secure access
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Initialize error reporting based on environment
if (ADMIN_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ERROR | E_PARSE);
    ini_set('display_errors', 0);
}

// Ensure required directories exist (already handled by getSecureAdminPath function)
// Just verify they are writable
$requiredDirs = [
    'logs' => ADMIN_LOGS_PATH,
    'temp' => ADMIN_TEMP_PATH, 
    'uploads' => ADMIN_UPLOADS_PATH
];

foreach ($requiredDirs as $type => $dir) {
    if (!is_dir($dir)) {
        // This shouldn't happen since getSecureAdminPath handles creation
        if (ADMIN_DEBUG) {
            error_log("Warning: Admin {$type} directory does not exist: " . $dir);
        }
    } elseif (!is_writable($dir)) {
        // Try to make it writable
        @chmod($dir, 0750);
        if (ADMIN_DEBUG && !is_writable($dir)) {
            error_log("Warning: Admin {$type} directory is not writable: " . $dir);
        }
    }
}

// Log admin system initialization
if (ADMIN_DEBUG) {
    error_log("Admin Secure Config initialized for environment: " . ADMIN_ENVIRONMENT);
}

?>
