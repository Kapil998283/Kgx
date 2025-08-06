<?php
// CRITICAL: Suppress ALL error output to prevent corrupting JSON response
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Set error handler to suppress all output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Cleanup Test Users Error: $errstr in $errfile on line $errline");
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
    // Get all test users
    $test_users = $supabase->select('users', 'id', ['email' => ['like', '%@testuser.com']]);
    
    if (empty($test_users)) {
        echo json_encode([
            'success' => true,
            'message' => 'No test users found to cleanup',
            'deleted_users' => 0,
            'deleted_registrations' => 0,
            'deleted_tickets' => 0,
            'deleted_coins' => 0,
            'deleted_games' => 0
        ]);
        exit;
    }
    
    $test_user_ids = array_column($test_users, 'id');
    
    $deleted_registrations = 0;
    $deleted_tickets = 0;
    $deleted_coins = 0;
    $deleted_games = 0;
    $deleted_users = 0;
    
    // Delete registrations first (foreign key constraints)
    try {
        $registrations = $supabase->select('tournament_registrations', 'id', ['user_id' => ['in', $test_user_ids]]);
        foreach ($registrations as $reg) {
            if ($supabase->delete('tournament_registrations', ['id' => $reg['id']])) {
                $deleted_registrations++;
            }
        }
    } catch (Exception $e) {
        error_log("Error deleting registrations: " . $e->getMessage());
    }
    
    // Delete user tickets
    try {
        $tickets = $supabase->select('user_tickets', 'id', ['user_id' => ['in', $test_user_ids]]);
        foreach ($tickets as $ticket) {
            if ($supabase->delete('user_tickets', ['id' => $ticket['id']])) {
                $deleted_tickets++;
            }
        }
    } catch (Exception $e) {
        error_log("Error deleting tickets: " . $e->getMessage());
    }
    
    // Delete user coins
    try {
        $coins = $supabase->select('user_coins', 'id', ['user_id' => ['in', $test_user_ids]]);
        foreach ($coins as $coin) {
            if ($supabase->delete('user_coins', ['id' => $coin['id']])) {
                $deleted_coins++;
            }
        }
    } catch (Exception $e) {
        error_log("Error deleting coins: " . $e->getMessage());
    }
    
    // Delete user games
    try {
        $games = $supabase->select('user_games', 'id', ['user_id' => ['in', $test_user_ids]]);
        foreach ($games as $game) {
            if ($supabase->delete('user_games', ['id' => $game['id']])) {
                $deleted_games++;
            }
        }
    } catch (Exception $e) {
        error_log("Error deleting user games: " . $e->getMessage());
    }
    
    // Delete users last
    try {
        foreach ($test_users as $user) {
            if ($supabase->delete('users', ['id' => $user['id']])) {
                $deleted_users++;
            }
        }
    } catch (Exception $e) {
        error_log("Error deleting users: " . $e->getMessage());
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Test users cleanup completed',
        'deleted_users' => $deleted_users,
        'deleted_registrations' => $deleted_registrations,
        'deleted_tickets' => $deleted_tickets,
        'deleted_coins' => $deleted_coins,
        'deleted_games' => $deleted_games
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
