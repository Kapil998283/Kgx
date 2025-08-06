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

$response = ['success' => false, 'images' => [], 'message' => ''];

// Basic security check
if(!$authManager->isLoggedIn()) {
    $response['message'] = 'User not authenticated.';
    echo json_encode($response);
    exit();
}

try {
    // Fetch active profile images
    $images_data = $supabaseClient->select('profile_images', 'id, image_path', ['is_active' => true], 'created_at.desc');
    
    $images = [];
    if (!empty($images_data)) {
        foreach ($images_data as $row) {
            $path = $row['image_path'];
            if (strpos($path, '/assets/') === 0) {
                $images[] = ['id' => $row['id'], 'path' => '/KGX' . $path];
            } elseif (filter_var($path, FILTER_VALIDATE_URL)) {
                $images[] = ['id' => $row['id'], 'path' => $path];
            }
        }
    }
    
    $response['success'] = true;
    $response['images'] = $images;
} catch (Exception $e) {
    $response['message'] = 'Error fetching profile images: ' . $e->getMessage();
    error_log("Supabase Exception: " . $e->getMessage());
}

echo json_encode($response);
?> 