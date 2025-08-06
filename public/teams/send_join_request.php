<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('SECURE_ACCESS', true);
require_once '../secure_config.php';

// Load secure configurations and includes
loadSecureConfig('supabase.php');
loadSecureInclude('SupabaseClient.php');
loadSecureInclude('auth.php');

// Initialize AuthManager and SupabaseClient
$authManager = new AuthManager();
$supabaseClient = new SupabaseClient();

// Check if user is logged in
if (!$authManager->isLoggedIn()) {
    $_SESSION['error_message'] = 'Please login first';
    header("Location: " . BASE_URL . "register/login.php");
    exit;
}

$currentUser = $authManager->getCurrentUser();
$user_id = $currentUser['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = 'Invalid request method';
    header("Location: index.php");
    exit;
}

$team_id = isset($_POST['team_id']) ? (int)$_POST['team_id'] : 0;

if (!$team_id) {
    $_SESSION['error_message'] = 'Invalid team ID';
    header("Location: index.php");
    exit;
}

try {
    // Check if user is already in a team or has a pending request for this team
    $joinRequests = $supabaseClient->select('team_join_requests', '*', [
        'user_id' => $user_id, 
        'team_id' => $team_id, 
        'status' => 'pending'
    ]);
    
    $currentTeam = $supabaseClient->select('team_members', '*', [
        'user_id' => $user_id,
        'status' => 'active'
    ]);
    
    $isInTeam = count($currentTeam) > 0;

    if (!$isInTeam && count($joinRequests) > 0) {
        $_SESSION['error_message'] = 'You already have a pending request for this team';
        header("Location: index.php");
        exit;
    } elseif ($isInTeam) {
        $_SESSION['error_message'] = 'You are already a member of another team. Leave that team first to join a new one.';
        header("Location: index.php");
        exit;
    }

    // Get team details to check if it's full
$team_details = $supabaseClient->select('teams', 'max_members, current_members', [
    'id' => $team_id,
    'is_active' => true
]);
    
    if (empty($team_details)) {
        $_SESSION['error_message'] = 'Team not found or inactive';
        header("Location: index.php");
        exit;
    }
    
    $team = $team_details[0];
    
    // Count current team members
    $current_members = $supabaseClient->select('team_members', 'id', [
        'team_id' => $team_id,
        'status' => 'active'
    ]);
    
    $current_member_count = count($current_members);

    if ($current_member_count >= $team['max_members']) {
        $_SESSION['error_message'] = 'This team is full';
        header("Location: index.php");
        exit;
    }

    // Create join request
    $request_data = [
        'team_id' => $team_id,
        'user_id' => $user_id,
        'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s')
    ];
    
    $result = $supabaseClient->insert('team_join_requests', $request_data);
    
    if (empty($result)) {
        throw new Exception('Failed to create join request');
    }

    $_SESSION['success_message'] = 'Join request sent successfully';
    header("Location: index.php");
    exit;

} catch (Exception $e) {
    error_log("Error in send_join_request.php: " . $e->getMessage());
    $_SESSION['error_message'] = 'An error occurred while sending the join request';
    header("Location: index.php");
    exit;
}
