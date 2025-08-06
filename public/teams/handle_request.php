<?php
// Only start session if one isn't already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
define('SECURE_ACCESS', true);
require_once '../secure_config.php';

// Load secure configurations and includes
loadSecureConfig('supabase.php');
loadSecureInclude('auth.php');

// Initialize AuthManager
$authManager = new AuthManager();

// Check if user is logged in
if (!$authManager->isLoggedIn()) {
    $_SESSION['error_message'] = 'Please login first';
    header("Location: ../register/login.php");
    exit;
}

$currentUser = $authManager->getCurrentUser();
$user_id = $currentUser['user_id'];

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = 'Invalid request method';
    header("Location: yourteams.php");
    exit;
}

// Get POST data
$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$action = isset($_POST['action']) ? $_POST['action'] : ''; // 'approve' or 'reject'
$team_id = isset($_POST['team_id']) ? (int)$_POST['team_id'] : 0;
$active_tab = isset($_POST['active_tab']) ? $_POST['active_tab'] : 'requests';

// Debug logging
error_log("Processing request - Request ID: $request_id, Action: $action, Team ID: $team_id");

if (!$request_id || !in_array($action, ['approve', 'reject']) || !$team_id) {
    $_SESSION['error_message'] = 'Invalid request parameters';
    error_log("Invalid parameters - Request ID: $request_id, Action: $action, Team ID: $team_id");
    header("Location: yourteams.php?team_id=" . $team_id . "&tab=" . $active_tab);
    exit;
}

try {
    $supabaseClient = new SupabaseClient();

// Get request details and verify captain
$request = $supabaseClient->select('team_join_requests', '*', ['id' => $request_id, 'status' => 'pending']);
$request = $request[0] ?? null;

if ($request) {
    $teamDetails = $supabaseClient->select('teams', 'captain_id,max_members,name', ['id' => $request['team_id']]);
    $team = $teamDetails[0] ?? null;

    if ($team) {
        $memberCount = $supabaseClient->select('team_members', '*', ['team_id' => $request['team_id'], 'status' => 'active']);
        $member_count = count($memberCount);
        
        // Check if captain is already in team_members table
        $captain_in_members = false;
        $captain_check = $supabaseClient->select('team_members', 'id', [
            'team_id' => $request['team_id'], 
            'user_id' => $team['captain_id'], 
            'status' => 'active'
        ]);
        $captain_in_members = !empty($captain_check);
        
        // If captain is not in team_members, add 1 to count
        $request['current_members'] = $captain_in_members ? $member_count : $member_count + 1;
        $request['team_name'] = $team['name'];
        $request['captain_id'] = $team['captain_id'];
        $request['max_members'] = $team['max_members'];
    }
}

    // Debug logging
    error_log("Request details: " . print_r($request, true));
    error_log("Current user ID: " . $user_id);
    error_log("Team ID from form: " . $team_id);
    error_log("Team ID from request: " . ($request ? $request['team_id'] : 'not found'));

    if (!$request) {
        $_SESSION['error_message'] = 'Request not found or already processed';
        error_log("Request not found or already processed - Request ID: $request_id");
        header("Location: yourteams.php?team_id=" . $team_id . "&tab=" . $active_tab);
        exit;
    }

    // Verify team ID matches
if ($request['team_id'] != $team_id) {
        $_SESSION['error_message'] = 'Team ID mismatch';
        error_log("Team ID mismatch - Form: $team_id, DB: " . $request['team_id']);
        header("Location: yourteams.php?team_id=" . $team_id . "&tab=" . $active_tab);
        exit;
    }

    // Verify user is the team captain
    if ($request['captain_id'] != $user_id) {
        $_SESSION['error_message'] = 'You are not authorized to handle this request';
        error_log("Unauthorized - User ID: {$user_id}, Captain ID: {$request['captain_id']}");
        header("Location: yourteams.php?team_id=" . $team_id . "&tab=" . $active_tab);
        exit;
    }

    // Check if team is full when approving
    if ($action === 'approve' && $request['current_members'] >= $request['max_members']) {
        $_SESSION['error_message'] = 'Team is full';
        error_log("Team is full - Current: {$request['current_members']}, Max: {$request['max_members']}");
        header("Location: yourteams.php?team_id=" . $team_id . "&tab=" . $active_tab);
        exit;
    }

    // All checks passed, proceed with processing the request
    
    // Update request status
    $updateResult = $supabaseClient->update('team_join_requests', [
        'status' => $action === 'approve' ? 'approved' : 'rejected'
    ], [
        'id' => $request_id,
        'status' => 'pending'
    ]);

    // Check if the update was successful by checking affected rows
    // Note: Supabase REST v1 doesn't return a count. We assume success if no exception is thrown.
    // A more robust check might be to re-fetch the request and check its status.

    if ($action === 'approve') {
        // Check if user is already a member to prevent duplicates
        $existingMember = $supabaseClient->select('team_members', 'id', [
            'team_id' => $team_id,
            'user_id' => $request['user_id']
        ]);

        if (!empty($existingMember)) {
            throw new Exception('User is already a member of this team.');
        }

        // Add user to the team_members table
        $addResult = $supabaseClient->insert('team_members', [
            'team_id' => $team_id,
            'user_id' => $request['user_id'],
            'role' => 'member',
            'status' => 'active',
            'joined_at' => date('c')
        ]);

        // Supabase insert returns the inserted data on success, or throws an exception on failure
        // If we reach this point without an exception, the insert was successful
        error_log("User added to team successfully. Insert result: " . print_r($addResult, true));

        // Cancel any other pending join requests from this user for other teams
        $otherPendingRequests = $supabaseClient->select('team_join_requests', 'id', [
            'user_id' => $request['user_id'],
            'status' => 'pending'
        ]);
        
        foreach ($otherPendingRequests as $req) {
            $supabaseClient->update('team_join_requests', ['status' => 'cancelled'], ['id' => $req['id']]);
        }
    }

    // Create a notification for the user whose request was processed
    $notificationMessage = $action === 'approve'
        ? "Your request to join team '{$request['team_name']}' has been approved!"
        : "Your request to join team '{$request['team_name']}' has been rejected.";
        
    $supabaseClient->insert('notifications', [
        'user_id' => $request['user_id'],
        'type' => $action === 'approve' ? 'request_approved' : 'request_rejected',
        'message' => $notificationMessage,
        'created_at' => date('c'),
        'is_read' => false
    ]);

    // Set success message and redirect
    $requesterData = $supabaseClient->select('users', 'username', ['id' => $request['user_id']]);
    $requesterUsername = !empty($requesterData) ? $requesterData[0]['username'] : 'User';

    $_SESSION['success_message'] = $action === 'approve'
        ? "Successfully approved {$requesterUsername}'s request to join the team!"
        : "Request from {$requesterUsername} has been rejected.";

} catch (Exception $e) {
    // Catch any exception during the process, log it, and set an error message
    error_log("Error in handle_request.php: " . $e->getMessage());
    $_SESSION['error_message'] = 'An error occurred while processing the request. ' . $e->getMessage();
} finally {
    // Always redirect back to the yourteams page
    header("Location: yourteams.php?team_id=" . $team_id . "&tab=" . $active_tab);
    exit;
}
