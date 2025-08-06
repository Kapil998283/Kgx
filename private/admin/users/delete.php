<?php
// CRITICAL: Suppress ALL error output to prevent corrupting response
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Set error handler to suppress all output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Delete User Error: $errstr in $errfile on line $errline");
    return true; // Don't execute PHP internal error handler
});

// Set JSON content type header early
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once dirname(__DIR__) . '/admin_secure_config.php';

// Use the new admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

// Check if user is authenticated admin
if (!isAdminAuthenticated()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

// Only allow GET or POST requests
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get user ID
$userId = null;
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $userId = $_GET['id'] ?? null;
} else {
    $input = json_decode(file_get_contents('php://input'), true);
    $userId = $input['id'] ?? $_POST['id'] ?? null;
}

// Validate user ID
if (!$userId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit;
}

// Sanitize user ID
$userId = filter_var($userId, FILTER_SANITIZE_STRING);
if (!$userId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid user ID format']);
    exit;
}

try {
    // Initialize Supabase connection
    $supabase = getSupabaseConnection();
    
    // First, verify the user exists and is not an admin
    $user = $supabase->select('users', '*', ['id' => $userId]);
    
    if (empty($user)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }
    
    $userData = $user[0];
    
    // Prevent deletion of admin users
    if (isset($userData['role']) && $userData['role'] === 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Cannot delete admin users']);
        exit;
    }
    
    // Start transaction-like operations
    $deletionSuccess = true;
    $errors = [];
    
    try {
        // Delete user's coins record
        $coinsResult = $supabase->delete('user_coins', ['user_id' => $userId]);
        if ($coinsResult === false) {
            $errors[] = 'Failed to delete user coins';
        }
    } catch (Exception $e) {
        error_log("Error deleting user coins for user $userId: " . $e->getMessage());
        // Continue with other deletions even if this fails
    }
    
    try {
        // Delete user's tickets record
        $ticketsResult = $supabase->delete('user_tickets', ['user_id' => $userId]);
        if ($ticketsResult === false) {
            $errors[] = 'Failed to delete user tickets';
        }
    } catch (Exception $e) {
        error_log("Error deleting user tickets for user $userId: " . $e->getMessage());
        // Continue with other deletions even if this fails
    }
    
    try {
        // Delete user's game history/sessions if they exist
        $gameHistoryResult = $supabase->delete('game_sessions', ['user_id' => $userId]);
        // This might fail if table doesn't exist, which is okay
    } catch (Exception $e) {
        error_log("Error deleting game history for user $userId: " . $e->getMessage());
        // This is optional, continue
    }
    
    try {
        // Delete user's transactions if they exist
        $transactionsResult = $supabase->delete('transactions', ['user_id' => $userId]);
        // This might fail if table doesn't exist, which is okay
    } catch (Exception $e) {
        error_log("Error deleting transactions for user $userId: " . $e->getMessage());
        // This is optional, continue
    }
    
    // Finally, delete the user record itself
    try {
        $userResult = $supabase->delete('users', ['id' => $userId]);
        if ($userResult === false) {
            $deletionSuccess = false;
            $errors[] = 'Failed to delete user account';
        }
    } catch (Exception $e) {
        error_log("Error deleting user $userId: " . $e->getMessage());
        $deletionSuccess = false;
        $errors[] = 'Failed to delete user account: ' . $e->getMessage();
    }
    
    // Determine response
    if ($deletionSuccess && empty($errors)) {
        // Complete success
        http_response_code(200);
        echo json_encode([
            'success' => true, 
            'message' => 'User deleted successfully',
            'user_id' => $userId,
            'username' => $userData['username'] ?? 'Unknown'
        ]);
        
        // Log the successful deletion
        error_log("Admin successfully deleted user: $userId (" . ($userData['username'] ?? 'Unknown') . ")");
        
    } else if (!empty($errors)) {
        // Partial failure
        http_response_code(207); // Multi-status
        echo json_encode([
            'success' => false,
            'message' => 'User deletion completed with some errors',
            'errors' => $errors,
            'user_id' => $userId
        ]);
        
        error_log("User deletion completed with errors for user $userId: " . implode(', ', $errors));
        
    } else {
        // Complete failure
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to delete user',
            'errors' => $errors,
            'user_id' => $userId
        ]);
        
        error_log("Failed to delete user $userId: " . implode(', ', $errors));
    }
    
} catch (Exception $e) {
    error_log("Unexpected error during user deletion for user $userId: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'An unexpected error occurred during deletion',
        'error' => $e->getMessage()
    ]);
}
?>
