<?php
// CRITICAL: Suppress ALL error output to prevent corrupting JSON response
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Set error handler to suppress all output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Remove Team Error: $errstr in $errfile on line $errline");
    return true; // Don't execute PHP internal error handler
});

// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once dirname(__DIR__) . '/admin_secure_config.php';

// Use the new admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

header('Content-Type: application/json');

try {
    $supabase = new SupabaseClient(true);
    
    // Get POST data
    $round_id = isset($_POST['round_id']) ? (int)$_POST['round_id'] : 0;
    $team_id = isset($_POST['team_id']) ? (int)$_POST['team_id'] : 0;
    $tournament_id = isset($_POST['tournament_id']) ? (int)$_POST['tournament_id'] : 0;

    if (!$round_id || !$team_id || !$tournament_id) {
        throw new Exception('Invalid parameters provided');
    }

    // Get tournament info to check if it's Solo mode
    $tournament_data = $supabase->select('tournaments', 'mode', ['id' => $tournament_id], null, 1);
    $tournament = !empty($tournament_data) ? $tournament_data[0] : null;
    
    if (!$tournament) {
        throw new Exception('Tournament not found');
    }
    
    $is_solo = $tournament['mode'] === 'Solo';

    // Get participant name for logging
    $participant_name = 'Unknown';
    if ($is_solo) {
        // For solo tournaments, get the user name from the team's captain
        $team_data = $supabase->select('teams', 'captain_id', ['id' => $team_id], null, 1);
        if (!empty($team_data)) {
            $user_data = $supabase->select('users', 'username', ['id' => $team_data[0]['captain_id']], null, 1);
            $participant_name = !empty($user_data) ? $user_data[0]['username'] : "Player_" . $team_data[0]['captain_id'];
        }
    } else {
        // For team tournaments, get the team name
        $team_data = $supabase->select('teams', 'name', ['id' => $team_id], null, 1);
        $participant_name = !empty($team_data) ? $team_data[0]['name'] : "Team_$team_id";
    }

    // Remove the team from the round
    $delete_result = $supabase->delete('round_teams', [
        'round_id' => $round_id,
        'team_id' => $team_id
    ]);

    if ($delete_result) {
        error_log("Successfully removed $participant_name (Team ID: $team_id) from round $round_id");
        
        echo json_encode([
            'success' => true,
            'message' => "Successfully removed $participant_name from the round",
            'participant_name' => $participant_name
        ]);
    } else {
        throw new Exception("Failed to remove $participant_name from the round");
    }

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
