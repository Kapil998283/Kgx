<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
define('SECURE_ACCESS', true);
require_once '../secure_config.php';

// Load Supabase client and auth
loadSecureConfig('supabase.php');
loadSecureInclude('SupabaseClient.php');
loadSecureInclude('auth.php');

$authManager = new AuthManager();
$supabaseClient = new SupabaseClient();

header('Content-Type: application/json');

// Only admins can save room details
if (!$authManager->isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!isset($_POST['match_id']) || !isset($_POST['room_code']) || !isset($_POST['room_password'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$match_id = intval($_POST['match_id']);

try {
    // Update room details
    $update_data = [
        'room_code' => $_POST['room_code'],
        'room_password' => $_POST['room_password'],
        'room_details_added_at' => date('Y-m-d H:i:s')
    ];
    $supabaseClient->update('matches', $update_data, ['id' => $match_id]);

    // Get participants to notify them
    $participants = $supabaseClient->select('match_participants', 'user_id', ['match_id' => $match_id]);
    $matchInfo = $supabaseClient->select('matches', 'game_id, match_type', ['id' => $match_id], null, 1);
    $game_info = $supabaseClient->select('games', 'name', ['id' => $matchInfo[0]['game_id']], null, 1);

    $notificationMessage = "Room details added for {$game_info[0]['name']} {$matchInfo[0]['match_type']} match";
    
    $notifications = [];
    foreach ($participants as $participant) {
        $notifications[] = [
            'user_id' => $participant['user_id'],
            'type' => 'room_details',
            'message' => $notificationMessage,
            'related_id' => $match_id,
            'related_type' => 'match'
        ];
    }
    
    if (!empty($notifications)) {
        $supabaseClient->insert('notifications', $notifications);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Room details saved and notifications sent to ' . count($participants) . ' users'
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
