<?php
define('SECURE_ACCESS', true);
require_once '../secure_config.php';

// Load secure configurations and includes
loadSecureConfig('supabase.php');
loadSecureInclude('user-auth.php');


header('Content-Type: application/json');

// Initialize Supabase client
$supabaseClient = new SupabaseClient();

// Get POST data
$team_id = isset($_POST['team_id']) ? (int)$_POST['team_id'] : 0;
$name = trim($_POST['name'] ?? '');
$logo = trim($_POST['logo'] ?? '');
$banner_id = isset($_POST['banner_id']) ? (int)$_POST['banner_id'] : 0;
$language = trim($_POST['language'] ?? '');

// Validate inputs
if (!$team_id || !$name || !$logo || !$banner_id || !$language) {
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit;
}

// Verify if user is the captain of this team
$captain_check_response = $supabaseClient->from('team_members')
    ->select('*')
    ->eq('team_id', $team_id)
    ->eq('user_id', $_SESSION['user_id'])
    ->eq('role', 'captain')
    ->execute();

$captain_check = $captain_check_response['data'] ?? [];

if (count($captain_check) === 0) {
    echo json_encode(['success' => false, 'message' => 'You are not authorized to edit this team']);
    exit;
}

try {
    // Check if team name already exists (excluding current team)
    $check_name_response = $supabaseClient->from('teams')
        ->select('id')
        ->eq('name', strtolower($name))
        ->neq('id', $team_id)
        ->is('is_active', true)
        ->execute();

    if (count($check_name_response['data']) > 0) {
        throw new Exception('This team name is already taken. Please choose a different name.');
    }

    // Update team
    $update_response = $supabaseClient->from('teams')
        ->update([
            'name' => htmlspecialchars($name),
            'logo' => filter_var($logo, FILTER_SANITIZE_URL),
            'banner_id' => $banner_id,
            'language' => htmlspecialchars($language)
        ])
        ->eq('id', $team_id)
        ->execute();

    if ($update_response['error']) {
        throw new Exception('Failed to update team');
    }

    echo json_encode(['success' => true, 'message' => 'Team updated successfully']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
