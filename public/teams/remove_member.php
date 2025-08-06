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

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error_message'] = 'Invalid request method';
    header("Location: yourteams.php");
    exit;
}

// Get POST data
$member_id = isset($_POST['member_id']) ? (int)$_POST['member_id'] : 0;
$team_id = isset($_POST['team_id']) ? (int)$_POST['team_id'] : 0;

if (!$member_id || !$team_id) {
    $_SESSION['error_message'] = 'Invalid request parameters';
    header("Location: yourteams.php");
    exit;
}

try {


    // Get team and captain information
    $team_info = $supabaseClient->select('teams', 'name', ['id' => $team_id]);
    $captain_info = $supabaseClient->select('users', 'username', ['id' => $team_info[0]['captain_id']]);

    // Verify that the logged-in user is the team captain
    $is_captain = $supabaseClient->select('team_members', 'id', [
        'team_id' => $team_id,
        'user_id' => $_SESSION['user_id'],
        'role' => 'captain'
    ]);

    if (!count($is_captain)) {
        $_SESSION['error_message'] = 'You are not authorized to remove members from this team';
        header("Location: yourteams.php?tab=players&team_id=" . $team_id);
        exit;
    }

    // Verify that the member to be removed is not the captain
    $member_role = $supabaseClient->select('team_members', 'role', [
        'team_id' => $team_id,
        'user_id' => $member_id
    ]);

    if (empty($member_role)) {
        $_SESSION['error_message'] = 'Member not found in the team';
        header("Location: yourteams.php?tab=players&team_id=" . $team_id);
        exit;
    }

    if ($member_role[0]['role'] === 'captain') {
        $_SESSION['error_message'] = 'Cannot remove the team captain';
        header("Location: yourteams.php?tab=players&team_id=" . $team_id);
        exit;
    }

    // Remove the member (excluding captain by checking the role first)
    $supabaseClient->delete('team_members', [
        'team_id' => $team_id,
        'user_id' => $member_id
    ]);

    // Create notification for the removed member
    $notificationData = [
        'user_id' => $member_id,
        'type' => 'team_removal',
        'message' => "You have been removed from team '" . $team_info[0]['name'] . "' by captain " . $captain_info[0]['username'],
        'created_at' => date('c'),
        'is_read' => false
    ];
    $supabaseClient->insert('notifications', $notificationData);

    $_SESSION['success_message'] = 'Member removed successfully';

} catch (Exception $e) {
    error_log("Error removing member: " . $e->getMessage());
    $_SESSION['error_message'] = 'Database error while removing member';
}

header("Location: yourteams.php?tab=players&team_id=" . $team_id);
exit; 
