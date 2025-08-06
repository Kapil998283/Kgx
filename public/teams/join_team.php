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
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Please login first']);
    exit();
}

// Check if team_id is provided
if (!isset($_POST['team_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Team ID is required']);
    exit();
}


try {
    // Check if team exists and is not full
    $teams = $supabaseClient->select('teams', '*', ['id' => $_POST['team_id'], 'is_active' => true]);
    
    if (empty($teams)) {
        throw new Exception('Team not found or inactive');
    }

    $team = $teams[0];
    
    // Get current members count
    $members = $supabaseClient->select('team_members', 'id', ['team_id' => $team['id'], 'status' => 'active']);
    $team['current_members'] = count($members);

    if ($team['current_members'] >= $team['max_members']) {
        throw new Exception('Team is full');
    }

    // Check if user is already a member
    $existing_member = $supabaseClient->select('team_members', '*', ['team_id' => $team['id'], 'user_id' => $_SESSION['user_id']]);
    if (!empty($existing_member)) {
        throw new Exception('You are already a member of this team');
    }

    // Check for pending requests
    $pending_request = $supabaseClient->select('team_join_requests', '*', ['team_id' => $team['id'], 'user_id' => $_SESSION['user_id'], 'status' => 'pending']);
    if (!empty($pending_request)) {
        throw new Exception('You already have a pending request for this team');
    }

    // Create join request
    $requestData = [
        'team_id' => $team['id'],
        'user_id' => $_SESSION['user_id'],
        'status' => 'pending',
        'created_at' => date('c')
    ];
    $supabaseClient->insert('team_join_requests', $requestData);

    // Notify team captain
    $notificationData = [
        'user_id' => $team['captain_id'],
        'message' => "New join request for team: " . $team['name'],
        'type' => 'team_request',
        'created_at' => date('c')
    ];
    $supabaseClient->insert('notifications', $notificationData);

    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Join request sent successfully']);
} catch (Exception $e) {    
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?> 