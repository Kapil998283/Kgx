<?php
// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once dirname(__DIR__, 2) . '/admin_secure_config.php';

// Use the new admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

header('Content-Type: application/json');

$round_id = isset($_GET['round_id']) ? (int)$_GET['round_id'] : 0;

if (!$round_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Round ID is required']);
    exit();
}

try {
    $supabase = new SupabaseClient(true);

    // Get teams assigned to this round with their details
    $round_teams = $supabase->select('round_teams', '*, teams:team_id(id, name)', ['round_id' => $round_id]);
    
    $teams = [];
    if ($round_teams) {
        foreach ($round_teams as $rt) {
            $team = $rt['teams'] ?? null;
            $teams[] = [
                'id' => $rt['team_id'],
                'name' => $team ? $team['name'] : 'Unknown Team',
                'status' => $rt['status'],
                'points' => $rt['total_points'] ?? 0,
                'rank' => $rt['placement'] ?? null
            ];
        }
        
        // Sort by placement (nulls last), then by name
        usort($teams, function($a, $b) {
            if ($a['rank'] === null && $b['rank'] === null) {
                return strcmp($a['name'], $b['name']);
            }
            if ($a['rank'] === null) return 1;
            if ($b['rank'] === null) return -1;
            return $a['rank'] - $b['rank'];
        });
    }

    echo json_encode($teams);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
