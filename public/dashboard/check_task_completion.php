<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('SECURE_ACCESS', true);
require_once '../secure_config.php';
loadSecureConfig('supabase.php');
loadSecureInclude('auth.php');

$authManager = new AuthManager();
$supabaseClient = new SupabaseClient();

header('Content-Type: application/json');

if (!$authManager->isLoggedIn()) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$currentUser = $authManager->getCurrentUser();
$user_id = $currentUser['user_id'];

$task_name = $_POST['task_name'] ?? '';

if (empty($task_name)) {
    echo json_encode(['completed' => false]);
    exit;
}

function checkTaskCompletion($supabaseClient, $user_id, $task_name) {
    switch($task_name) {
        case 'Daily Login':
            return true;
            
        case 'Join a Match':
            $count = $supabaseClient->query(
                "SELECT COUNT(*) as count FROM (SELECT user_id, join_date FROM match_participants WHERE user_id = $1 AND DATE(join_date) = CURRENT_DATE UNION ALL SELECT user_id, match_date as join_date FROM match_history_archive WHERE user_id = $1 AND DATE(match_date) = CURRENT_DATE) combined_matches",
                [$user_id]
            );
            return !empty($count) && $count[0]['count'] > 0;
            
        case 'Win a Match':
            $count = $supabaseClient->query(
                "SELECT COUNT(*) as count FROM (SELECT user_id, join_date FROM match_participants WHERE user_id = $1 AND status = 'winner' AND DATE(join_date) = CURRENT_DATE UNION ALL SELECT user_id, match_date as join_date FROM match_history_archive WHERE user_id = $1 AND position = 1 AND DATE(match_date) = CURRENT_DATE) combined_matches",
                [$user_id]
            );
            return !empty($count) && $count[0]['count'] > 0;

        case 'Account Registration':
            return true;

        case 'Game Profile Setup':
            $count = $supabaseClient->select('user_games', 'id', ['user_id' => $user_id], null, 1);
            return !empty($count);

        case 'First Match':
            $count = $supabaseClient->query(
                "SELECT COUNT(*) as count FROM (SELECT user_id FROM match_participants WHERE user_id = $1 UNION ALL SELECT user_id FROM match_history_archive WHERE user_id = $1) combined_matches",
                [$user_id]
            );
            return !empty($count) && $count[0]['count'] > 0;

        case 'Team Membership':
            $count = $supabaseClient->select('team_members', 'id', ['user_id' => $user_id, 'status' => 'active'], null, 1);
            return !empty($count);

        case 'First Tournament':
            $count = $supabaseClient->select('tournament_player_history', 'id', ['user_id' => $user_id, 'status' => ['registered', 'playing', 'completed']], null, 1);
            return !empty($count);

        case 'Match Veteran':
            $count = $supabaseClient->query(
                "SELECT COUNT(*) as count FROM (SELECT user_id FROM match_participants WHERE user_id = $1 UNION ALL SELECT user_id FROM match_history_archive WHERE user_id = $1) combined_matches",
                [$user_id]
            );
            return !empty($count) && $count[0]['count'] >= 50;

        case 'Tournament Veteran':
            $count = $supabaseClient->select('tournament_player_history', 'id', ['user_id' => $user_id, 'status' => ['playing', 'completed']], null);
            return !empty($count) && count($count) >= 50;
            
        default:
            return false;
    }
}

$completed = checkTaskCompletion($supabaseClient, $user_id, $task_name);

echo json_encode(['completed' => $completed]);

