<?php
define('SECURE_ACCESS', true);
require_once '../secure_config.php';

// Load secure configurations and includes
loadSecureConfig('supabase.php');
loadSecureInclude('user-auth.php');

// Initialize SupabaseClient
$supabaseClient = new SupabaseClient();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error_message'] = 'Please login first';
    header("Location: register/login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = 'Invalid request method';
    header("Location: index.php");
    exit;
}

$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;

if (!$request_id) {
    $_SESSION['error_message'] = 'Invalid request ID';
    header("Location: index.php");
    exit;
}

try {

    // Verify the request belongs to the user and is pending
    $requests = $supabaseClient->select('team_join_requests', '*', [
        'id' => $request_id,
        'user_id' => $_SESSION['user_id'],
        'status' => 'pending'
    ]);

    if (empty($requests)) {
        $_SESSION['error_message'] = 'Request not found or already processed';
        header("Location: index.php");
        exit;
    }

    // Delete the request
    $supabaseClient->delete('team_join_requests', ['id' => $request_id]);

    $_SESSION['success_message'] = 'Join request cancelled successfully';
    header("Location: index.php");
    exit;

} catch (Exception $e) {
    error_log("Error in cancel_request.php: " . $e->getMessage());
    $_SESSION['error_message'] = 'An error occurred while cancelling the request';
    header("Location: index.php");
    exit;
}
