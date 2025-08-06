<?php
define('SECURE_ACCESS', true);
require_once '../secure_config.php';

// Load secure configurations and includes
loadSecureConfig('supabase.php');
loadSecureInclude('user-auth.php');



// Initialize Supabase client
$supabaseClient = new SupabaseClient();

// Get POST data
$team_id = isset($_POST['team_id']) ? (int)$_POST['team_id'] : 0;

if (!$team_id) {
    echo json_encode(['success' => false, 'message' => 'Team ID is required']);
    exit();
}

// Verify if user is the captain
$user_id = $_SESSION['user_id'];
$captain_check_response = $supabaseClient->from('team_members')
    ->select('*')
    ->eq('team_id', $team_id)
    ->eq('user_id', $user_id)
    ->eq('role', 'captain')
    ->execute();

$captain_check = $captain_check_response['data'] ?? [];

if (count($captain_check) === 0) {
    echo json_encode(['success' => false, 'message' => 'Not authorized']);
    exit();
}

try {
    // Delete team members first
    $delete_members_response = $supabaseClient->from('team_members')
        ->delete()
        ->eq('team_id', $team_id)
        ->execute();

    if ($delete_members_response['error']) {
        throw new Exception('Error deleting team members');
    }

    // Delete team
    $delete_team_response = $supabaseClient->from('teams')
        ->delete()
        ->eq('id', $team_id)
        ->execute();

    if ($delete_team_response['error']) {
        throw new Exception('Error deleting team');
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
