<?php
define('SECURE_ACCESS', true);
require_once '../secure_config.php';

// Load secure configurations and includes
loadSecureConfig('supabase.php');

// Initialize SupabaseClient
$supabaseClient = new SupabaseClient();

// Get search query
$search = isset($_GET['query']) ? trim($_GET['query']) : '';

try {
// Search teams using enhanced view for better discovery
$teams = $supabaseClient->select('team_discovery', '*', [
    'or' => [
        'name.ilike' => "%{$search}%",
        'description.ilike' => "%{$search}%",
        'language.ilike' => "%{$search}%"
    ],
    'is_active' => true
], [
    'order' => ['win_rate' => 'desc', 'created_at' => 'desc']
]);

    // Get captain names and member counts
    foreach ($teams as &$team) {
        $captains = $supabaseClient->select('users', 'username', ['id' => $team['captain_id']]);
        $team['captain_name'] = !empty($captains) ? $captains[0]['username'] : 'Unknown';
        $members = $supabaseClient->select('team_members', 'id', ['team_id' => $team['id'], 'status' => 'active']);
        $team['current_members'] = count($members);
    }
    
    // Format the response
    $response = array_map(function($team) {
        return [
            'id' => $team['id'],
            'name' => $team['name'],
            'logo' => $team['logo'],
            'description' => $team['description'],
            'language' => $team['language'],
            'max_members' => $team['max_members'],
            'current_members' => $team['current_members'],
            'captain_name' => $team['captain_name']
        ];
    }, $teams);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'teams' => $response]);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error searching teams']);
}
