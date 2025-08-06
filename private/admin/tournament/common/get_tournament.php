<?php
// CRITICAL: Suppress ALL error output to prevent corrupting JSON response
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Set error handler to suppress all output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Get Tournament Error: $errstr in $errfile on line $errline");
    return true; // Don't execute PHP internal error handler
});

header('Content-Type: application/json');

// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once dirname(__DIR__, 2) . '/admin_secure_config.php';

// Use the new admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

// Initialize Supabase connection with admin privileges
$supabase = new SupabaseClient(true);

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Tournament ID is required']);
    exit();
}

try {
    $tournament_data = $supabase->select('tournaments', '*', ['id' => $_GET['id']], null, 1);
    $tournament = !empty($tournament_data) ? $tournament_data[0] : null;
    
    if (!$tournament) {
        http_response_code(404);
        echo json_encode(['error' => 'Tournament not found']);
        exit();
    }
    
    // Helper function for consistent date formatting
    function formatAdminDate($dateString) {
        if (empty($dateString)) return '';
        try {
            $dateTime = new DateTime($dateString);
            return $dateTime->format('Y-m-d');
        } catch (Exception $e) {
            error_log("Date formatting error: " . $e->getMessage());
            return '';
        }
    }
    
    // Format dates for HTML date inputs
    $tournament['registration_open_date'] = formatAdminDate($tournament['registration_open_date']);
    $tournament['registration_close_date'] = formatAdminDate($tournament['registration_close_date']);
    $tournament['playing_start_date'] = formatAdminDate($tournament['playing_start_date']);
    $tournament['finish_date'] = formatAdminDate($tournament['finish_date']);
    
    echo json_encode(['success' => true, 'tournament' => $tournament]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
?> 