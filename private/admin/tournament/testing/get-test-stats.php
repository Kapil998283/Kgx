<?php
// CRITICAL: Suppress ALL error output to prevent corrupting JSON response
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Set error handler to suppress all output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Get Test Stats Error: $errstr in $errfile on line $errline");
    return true; // Don't execute PHP internal error handler
});

// Define admin secure access
define('ADMIN_SECURE_ACCESS', true);

// Load admin secure configuration
require_once dirname(__DIR__, 2) . '/admin_secure_config.php';

// Use the new admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

// Initialize Supabase connection with admin privileges
$supabase = new SupabaseClient(true);

header('Content-Type: application/json');

try {
    // Get total test users
    $test_users_count = $supabase->select('users', 'count', ['email' => ['like', '%@testuser.com']]);
    $total_test_users = !empty($test_users_count) ? $test_users_count[0]['count'] : 0;
    
    // Get total test user registrations
    $test_users = $supabase->select('users', 'id', ['email' => ['like', '%@testuser.com']]);
    $test_user_ids = array_column($test_users, 'id');
    
    $total_registrations = 0;
    $approved_registrations = 0;
    
    if (!empty($test_user_ids)) {
        $total_regs_data = $supabase->select('tournament_registrations', 'count', ['user_id' => ['in', $test_user_ids]]);
        $total_registrations = !empty($total_regs_data) ? $total_regs_data[0]['count'] : 0;
        
        $approved_regs_data = $supabase->select('tournament_registrations', 'count', [
            'user_id' => ['in', $test_user_ids],
            'status' => 'approved'
        ]);
        $approved_registrations = !empty($approved_regs_data) ? $approved_regs_data[0]['count'] : 0;
    }
    
    echo json_encode([
        'success' => true,
        'total_test_users' => $total_test_users,
        'total_registrations' => $total_registrations,
        'approved_registrations' => $approved_registrations
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
