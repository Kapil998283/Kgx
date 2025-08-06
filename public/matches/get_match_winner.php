<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
define('SECURE_ACCESS', true);
require_once '../secure_config.php';

// Load Supabase client
loadSecureConfig('supabase.php');
loadSecureInclude('SupabaseClient.php');
loadSecureInclude('auth.php');

$authManager = new AuthManager();
$supabaseClient = new SupabaseClient();

header('Content-Type: application/json');

// Check if user is logged in
if (!$authManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Get match ID from request
$match_id = isset($_GET['match_id']) ? intval($_GET['match_id']) : 0;

if (!$match_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid match ID']);
    exit;
}

try {
    // Logic from view-winner.php, adapted for API response
    $match_data = $supabaseClient->select('matches', 'status', ['id' => $match_id], null, 1);
    if (empty($match_data) || $match_data[0]['status'] !== 'completed') {
        http_response_code(400);
        echo json_encode(['error' => 'Match not found or not completed']);
        exit;
    }

    $winner_data = $supabaseClient->select('match_participants', 'user_id, position', ['match_id' => $match_id, 'position.isnot' => null], 'position.asc');
    
    $winners_response = [];
    if (!empty($winner_data)) {
        foreach ($winner_data as $winner) {
            $user_info = $supabaseClient->select('users', 'username', ['id' => $winner['user_id']], null, 1);
            $winners_response[] = [
                'username' => $user_info[0]['username'] ?? 'Unknown',
                'position' => $winner['position']
            ];
        }
    }
    
    echo json_encode(['winners' => $winners_response]);

} catch (Exception $e) {
    error_log("Error in get_match_winner.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
