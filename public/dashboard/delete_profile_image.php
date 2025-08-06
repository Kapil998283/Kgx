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
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit;
}

$currentUser = $authManager->getCurrentUser();

// Check if image ID is provided
if (!isset($_POST['image_id'])) {
    echo json_encode(['success' => false, 'message' => 'Image ID not provided']);
    exit;
}

$image_id = $_POST['image_id'];
$user_id = $currentUser['user_id'];

try {
    // Get image path before deletion
    $image_data = $supabaseClient->select('profile_images', 'image_path', ['id' => $image_id]);

    if (empty($image_data)) {
        echo json_encode(['success' => false, 'message' => 'Image not found']);
        exit;
    }
    
    $image_path = $image_data[0]['image_path'];

    // Delete the image file
    $file_path = '../../' . $image_path;
    if (file_exists($file_path)) {
        unlink($file_path);
    }

    // Delete from database
    $result = $supabaseClient->delete('profile_images', ['id' => $image_id]);

    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Image deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete image']);
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
    error_log("Supabase Exception: " . $e->getMessage());
}
?> 