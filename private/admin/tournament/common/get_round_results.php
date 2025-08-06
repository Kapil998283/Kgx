<?php
// CRITICAL: Suppress ALL error output to prevent corrupting JSON response
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Set error handler to suppress all output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Get Round Results Error: $errstr in $errfile on line $errline");
    return true; // Don't execute PHP internal error handler
});

// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once dirname(__DIR__, 2) . '/admin_secure_config.php';

// Use the new admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

header('Content-Type: application/json');

if (!isset($_GET['round_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Round ID is required']);
    exit();
}

$round_id = (int)$_GET['round_id'];

try {
    $supabase = new SupabaseClient(true);
    
    // Get tournament info to check if it's solo or team mode
    $round_data = $supabase->select('tournament_rounds', 'tournament_id', ['id' => $round_id], null, 1);
    if (!$round_data) {
        throw new Exception('Round not found');
    }
    
    $tournament_data = $supabase->select('tournaments', 'mode', ['id' => $round_data[0]['tournament_id']], null, 1);
    $is_solo = !empty($tournament_data) && $tournament_data[0]['mode'] === 'Solo';
    
    if ($is_solo) {
        // For solo tournaments, get users instead of teams
        $participants_data = $supabase->select(
            'round_teams', 
            '*, users:team_id(id, username, full_name)', 
            ['round_id' => $round_id]
        );
        
        $participants = array_map(function($p) { 
            $user = $p['users'] ?? null;
            return [
                'id' => $p['team_id'], // In solo mode, team_id contains user_id
                'name' => $user ? ($user['username'] ?: $user['full_name']) : 'Unknown User',
                'placement' => $p['placement'],
                'kills' => $p['kills'],
                'kill_points' => $p['kill_points'],
                'placement_points' => $p['placement_points'],
                'bonus_points' => $p['bonus_points'],
                'total_points' => $p['total_points'],
                'status' => $p['status'],
                'type' => 'user'
            ];
        }, $participants_data);
    } else {
        // For team tournaments
        $participants_data = $supabase->select(
            'round_teams', 
            '*, teams:team_id(id, name)', 
            ['round_id' => $round_id]
        );
        
        $participants = array_map(function($p) { 
            $team = $p['teams'] ?? null;
            return [
                'id' => $p['team_id'],
                'name' => $team ? $team['name'] : 'Unknown Team',
                'placement' => $p['placement'],
                'kills' => $p['kills'],
                'kill_points' => $p['kill_points'],
                'placement_points' => $p['placement_points'],
                'bonus_points' => $p['bonus_points'],
                'total_points' => $p['total_points'],
                'status' => $p['status'],
                'type' => 'team'
            ];
        }, $participants_data);
    }
    
    // Sort participants in PHP: first by placement (nulls last), then by name
    usort($participants, function($a, $b) {
        // Handle null placements (put them at the end)
        if ($a['placement'] === null && $b['placement'] === null) {
            return strcmp($a['name'], $b['name']);
        }
        if ($a['placement'] === null) return 1;
        if ($b['placement'] === null) return -1;
        
        // Sort by placement first
        $placementComparison = $a['placement'] - $b['placement'];
        if ($placementComparison !== 0) {
            return $placementComparison;
        }
        
        // If placements are equal, sort by name
        return strcmp($a['name'], $b['name']);
    });

    echo json_encode($participants);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
