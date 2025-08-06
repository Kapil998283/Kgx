<?php
// CRITICAL: Suppress ALL error output to prevent corrupting JSON response
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Set error handler to suppress all output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Get Registrations Error: $errstr in $errfile on line $errline");
    return true; // Don't execute PHP internal error handler
});

header('Content-Type: application/json');

// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once dirname(__DIR__, 2) . '/admin_secure_config.php';

// Use the new admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

// Initialize Supabase connection with admin privileges
$supabase = new SupabaseClient(true);

if (!isset($_GET['tournament_id'])) {
    echo json_encode(['success' => false, 'message' => 'Tournament ID is required']);
    exit();
}

try {
    // Get tournament details
    $tournament_data = $supabase->select('tournaments', 'name, mode, game_name', ['id' => $_GET['tournament_id']], null, 1);
    $tournament = !empty($tournament_data) ? $tournament_data[0] : null;

    if (!$tournament) {
        echo json_encode(['success' => false, 'message' => 'Tournament not found']);
        exit();
    }

    $game_name = $tournament['game_name'];
    $mode = $tournament['mode'];
    $registrations = [];

    if ($mode === 'Solo') {
        // Get registered players with game details
        $registrations_data = $supabase->select('tournament_registrations', 'id, status, tournament_id, user_id, registration_date', ['tournament_id' => $_GET['tournament_id'], 'user_id' => ['not.is', null]]);
        
        if ($registrations_data) {
            foreach ($registrations_data as $reg) {
                // Get user details
                $user_data = $supabase->select('users', 'id, username, email, full_name', ['id' => $reg['user_id']], null, 1);
                $user = !empty($user_data) ? $user_data[0] : [];
                
                // Get user game details
                $user_games_data = $supabase->select('user_games', '*', ['user_id' => $reg['user_id'], 'game_name' => $game_name], null, 1);
                $user_game = !empty($user_games_data) ? $user_games_data[0] : [];
                
                $registrations[] = [
                    'id' => $reg['id'],
                    'status' => $reg['status'],
                    'registration_date' => $reg['registration_date'],
                    'username' => $user['username'] ?? 'Unknown',
                    'team_name' => null, // Solo mode doesn't have teams
                    'game_username' => $user_game['game_username'] ?? null,
                    'game_uid' => $user_game['game_uid'] ?? null,
                    'game_level' => $user_game['game_level'] ?? null
                ];
            }
        }
    } else {
        // Get registered teams with details
        $teams_data = $supabase->select('tournament_registrations', 'id, status, tournament_id, team_id, registration_date', ['tournament_id' => $_GET['tournament_id'], 'team_id' => ['not.is', null]]);
        
        if ($teams_data) {
            foreach ($teams_data as $team_reg) {
                // Get team details
                $team_data = $supabase->select('teams', 'id, name', ['id' => $team_reg['team_id']], null, 1);
                $team = !empty($team_data) ? $team_data[0] : [];
                
                // Get team leader for username display
                $team_members_data = $supabase->select('team_members', 'user_id, role', ['team_id' => $team_reg['team_id'], 'role' => 'leader'], null, 1);
                $leader_username = 'Unknown';
                if (!empty($team_members_data)) {
                    $leader_data = $supabase->select('users', 'username', ['id' => $team_members_data[0]['user_id']], null, 1);
                    $leader_username = !empty($leader_data) ? $leader_data[0]['username'] : 'Unknown';
                }
                
                $registrations[] = [
                    'id' => $team_reg['id'],
                    'status' => $team_reg['status'],
                    'registration_date' => $team_reg['registration_date'],
                    'username' => $leader_username,
                    'team_name' => $team['name'] ?? 'Unknown Team',
                    'game_username' => null,
                    'game_uid' => null,
                    'game_level' => null
                ];
            }
        }
    }

    echo json_encode([
        'success' => true,
        'registrations' => $registrations,
        'tournament' => $tournament
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error loading registrations: ' . $e->getMessage()
    ]);
}
?>
