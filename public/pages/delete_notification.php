<?php
// Start session first
session_start();

// Set secure access flag
define('SECURE_ACCESS', true);
require_once '../secure_config.php';
loadSecureConfig('supabase.php');
loadSecureInclude('SupabaseClient.php');

// Make sure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "register/login.php");
    exit();
}

// Check if notification ID is provided
if (!isset($_POST['notification_id'])) {
    header("Location: " . BASE_URL . "index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$notification_id = $_POST['notification_id'];

try {
    // Initialize Supabase connection with user-level permissions (anon key)
    $supabase = new SupabaseClient();

    // First verify the notification belongs to the user and isn't already deleted
    $notification = $supabase->select('notifications', 'id', [
        'id' => $notification_id,
        'user_id' => $user_id,
        'deleted_at' => 'is.null'
    ], null, 1);

    // If notification exists and belongs to user, soft delete it
    if (!empty($notification)) {
        $supabase->update('notifications', 
            ['deleted_at' => date('Y-m-d H:i:s')], 
            [
                'id' => $notification_id,
                'user_id' => $user_id
            ]
        );
    }

    // Redirect back to referring page or home
    $redirect_to = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : BASE_URL . 'index.php';
    header("Location: " . $redirect_to);
    exit();
} catch (Exception $e) {
    error_log("Error deleting notification: " . $e->getMessage());
    header("Location: " . BASE_URL . "index.php");
    exit();
}
?>
