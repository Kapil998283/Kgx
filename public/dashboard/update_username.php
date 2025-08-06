<?php
// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('SECURE_ACCESS', true);
require_once '../secure_config.php';
loadSecureConfig('supabase.php');
loadSecureInclude('auth.php');

// Initialize AuthManager and SupabaseClient
$authManager = new AuthManager();
$supabaseClient = new SupabaseClient();

header('Content-Type: application/json');

// Check if user is logged in
if (!$authManager->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$currentUser = $authManager->getCurrentUser();

// Check if it's a POST request
if($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Get the new username
$new_username = trim($_POST['username'] ?? '');
$user_id = $currentUser['user_id'];

// Validate username
if(empty($new_username)) {
    echo json_encode(['success' => false, 'message' => 'Username cannot be empty']);
    exit();
}

try {
    // Check if username already exists
    $existing_users = $supabaseClient->query(
        "SELECT id FROM users WHERE username = $1 AND id != $2",
        [$new_username, $user_id]
    );

    if(!empty($existing_users)) {
        echo json_encode(['success' => false, 'message' => 'Username already exists']);
        exit();
    }

    // Update username
    $result = $supabaseClient->update('users', 
        ['username' => $new_username], 
        ['id' => $user_id]
    );

    if($result) {
        // Update session if using sessions
        if (isset($_SESSION)) {
            $_SESSION['username'] = $new_username;
        }
        echo json_encode(['success' => true, 'message' => 'Username updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error updating username']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    error_log("Supabase Exception: " . $e->getMessage());
}
?> 