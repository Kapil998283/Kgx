<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
define('SECURE_ACCESS', true);
require_once '../secure_config.php';

// Load Supabase client
loadSecureConfig('supabase.php');
loadSecureInclude('SupabaseClient.php');

$supabaseClient = new SupabaseClient();

header('Content-Type: application/json');

if (!isset($_GET['match_id'])) {
    echo json_encode(['error' => 'Match ID not provided']);
    exit;
}

try {
    $match_id = intval($_GET['match_id']);
    $room_details = $supabaseClient->select('matches', 'room_code, room_password', ['id' => $match_id], null, 1);
    
    echo json_encode($room_details[0] ?? ['room_code' => '', 'room_password' => '']);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
