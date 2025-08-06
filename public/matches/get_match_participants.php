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
    // Fetch participants logic from view-participants.php, adapted for API response
    $participants = [];
    $participant_data = $supabaseClient->select('match_participants', 'user_id', ['match_id' => $match_id]);
    
    if (!empty($participant_data)) {
        // Also get match details to calculate coins earned
        $match_data = $supabaseClient->select('matches', 'coins_per_kill', ['id' => $match_id], null, 1);
        $coins_per_kill = $match_data[0]['coins_per_kill'] ?? 0;

        foreach ($participant_data as $p_data) {
            $user_id = $p_data['user_id'];
            
            $user_info = $supabaseClient->select('users', 'username', ['id' => $user_id], null, 1);
            $username = $user_info[0]['username'] ?? 'Unknown User';
            
            $kills_data = $supabaseClient->select('user_kills', 'kills', ['match_id' => $match_id, 'user_id' => $user_id], null, 1);
            $kills = $kills_data[0]['kills'] ?? 0;
            
            $participants[] = [
                'username' => $username,
                'kills' => $kills,
                'coins_earned' => $kills * $coins_per_kill
            ];
        }
        
        usort($participants, function($a, $b) {
            if ($a['kills'] == $b['kills']) {
                return strcmp($a['username'], $b['username']);
            }
            return $b['kills'] - $a['kills'];
        });
    }
    
    echo json_encode($participants);

} catch (Exception $e) {
    error_log("Error in get_match_participants.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}
