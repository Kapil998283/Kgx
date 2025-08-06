<?php
session_start();
define('SECURE_ACCESS', true);
require_once '../secure_config.php';
loadSecureConfig('supabase.php');
loadSecureInclude('SupabaseClient.php');

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'User not authenticated']);
    exit();
}

$round_id = isset($_GET['round_id']) ? (int)$_GET['round_id'] : 0;

if (!$round_id) {
    echo json_encode(['error' => 'Invalid round ID']);
    exit();
}

try {
    $supabaseClient = new SupabaseClient();
    $teams = $supabaseClient->select('round_teams', '*, teams(*, users(username), team_members(*, users(username)))', ['round_id' => $round_id]);
    
    $formatted_teams = [];
    if($teams) {
        foreach($teams as $team) {
            $team_data = $team['teams'];
            $team_data['status'] = $team['status'];
            $team_data['captain_name'] = $team_data['users']['username'];
            $team_data['member_count'] = count($team_data['team_members']);
            $team_data['members'] = implode(', ', array_map(function($m) { return $m['users']['username']; }, $team_data['team_members']));
            $formatted_teams[] = $team_data;
        }
    }
    
    echo json_encode(['teams' => $formatted_teams]);
} catch (Exception $e) {
    echo json_encode(['error' => 'Supabase error: ' . $e->getMessage()]);
}
