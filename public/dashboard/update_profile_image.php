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

$response = ['success' => false, 'message' => ''];

// Check if user is logged in
if (!$authManager->isLoggedIn()) {
    $response['message'] = 'User not authenticated.';
    echo json_encode($response);
    exit();
}

$currentUser = $authManager->getCurrentUser();

// Check if image path is provided
if (!isset($_POST['image_path']) || empty(trim($_POST['image_path']))) {
    $response['message'] = 'No image path provided.';
    echo json_encode($response);
    exit();
}

$user_id = $currentUser['user_id'];
$image_path = trim($_POST['image_path']);

// Update user's profile image using SupabaseClient
try {
    $result = $supabaseClient->update('users', 
        ['profile_image' => $image_path], 
        ['id' => $user_id]
    );
    
    if ($result) {
        $response['success'] = true;
        $response['message'] = 'Profile image updated successfully.';
        // Update session with new profile image if using sessions
        if (isset($_SESSION)) {
            $_SESSION['user_profile_image'] = $image_path;
        }
    } else {
        $response['success'] = true;
        $response['message'] = 'Profile image is already set to this image.';
    }
} catch (Exception $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log("Supabase Error: " . $e->getMessage());
}

echo json_encode($response);
?> 