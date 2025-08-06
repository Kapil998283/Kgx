<?php
// Start session first
session_start();

// Define secure access constant
define('SECURE_ACCESS', true);

// Load secure configuration
require_once '../secure_config.php';
loadSecureConfig('supabase.php');
loadSecureInclude('SupabaseClient.php');

// Make sure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "register/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

try {
    // Initialize Supabase connection with user-level permissions (anon key)
    $supabase = new SupabaseClient();

    // Get all unread notifications for this user first
    $unread_notifications = $supabase->select('notifications', 'id', [
        'user_id' => $user_id,
        'is_read' => false,
        'deleted_at' => 'is.null'
    ]);

    // Mark each unread notification as read
    if (!empty($unread_notifications)) {
        foreach ($unread_notifications as $notification) {
            $supabase->update('notifications', 
                ['is_read' => true], 
                ['id' => $notification['id']]
            );
        }
    }

    // Safe redirect back to referring page or home
    $redirect_to = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : BASE_URL . 'index.php';
    header("Location: " . $redirect_to);
    exit();
} catch (Exception $e) {
    error_log("Error marking notifications as read: " . $e->getMessage());
    header("Location: " . BASE_URL . "index.php");
    exit();
}
?>
