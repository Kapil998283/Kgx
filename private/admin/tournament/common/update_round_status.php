<?php
// CRITICAL: Suppress ALL error output to prevent corrupting JSON response
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Set error handler to suppress all output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Update Round Status Error: $errstr in $errfile on line $errline");
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

try {
    // Get JSON data
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['round_id']) || !isset($data['status'])) {
        throw new Exception('Missing required parameters');
    }

    $round_id = (int)$data['round_id'];
    $status = $data['status'];

    $valid_statuses = ['upcoming', 'in_progress', 'completed'];
    if (!in_array($status, $valid_statuses)) {
        throw new Exception('Invalid status value');
    }

    $supabase = new SupabaseClient(true);

    $round_data = $supabase->select('tournament_rounds', '*', ['id' => $round_id], null, 1);
    $round = !empty($round_data) ? $round_data[0] : null;

    if (!$round) {
        throw new Exception('Round not found');
    }

    // Update round status directly
    $update_result = $supabase->update('tournament_rounds', [
        'status' => $status
    ], [
        'id' => $round_id
    ]);

    if ($update_result) {
        // Get tournament and round details for notifications
        $tournament_data = $supabase->select('tournaments', 'name', ['id' => $round['tournament_id']], null, 1);
        $tournament = $tournament_data ? $tournament_data[0] : null;
        
        // Get participating teams for this round
        $round_teams = $supabase->select('round_teams', 'team_id', ['round_id' => $round_id]);
        
        $users = [];
        if ($round_teams) {
            foreach ($round_teams as $rt) {
                // Get team members for each team
                $team_members = $supabase->select('team_members', 'user_id', [
                    'team_id' => $rt['team_id'],
                    'status' => 'active'
                ]);
                if ($team_members) {
                    foreach ($team_members as $member) {
                        $users[] = $member['user_id'];
                    }
                }
            }
        }
        
        // Remove duplicates
        $users = array_unique($users);
        
        // Create notifications for users in participating teams
        $status_message = match($status) {
            'upcoming' => 'scheduled',
            'in_progress' => 'started',
            'completed' => 'completed',
            default => 'updated'
        };
        
        $notificationMessage = "Round {$round['name']} has been {$status_message}" . 
            ($tournament ? " in tournament {$tournament['name']}" : "");
        
        foreach ($users as $userId) {
            $supabase->insert('notifications', [
                'user_id' => $userId,
                'type' => 'round_status',
                'message' => $notificationMessage,
                'related_id' => $round_id,
                'related_type' => 'tournament_round',
                'created_at' => (new DateTime())->format('Y-m-d H:i:s')
            ]);
        }

        echo json_encode(['success' => true, 'message' => 'Round status updated successfully']);
    } else {
        throw new Exception('Failed to update round status');
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
