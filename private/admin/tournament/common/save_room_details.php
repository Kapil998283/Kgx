<?php
// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once dirname(__DIR__, 2) . '/admin_secure_config.php';

// Use the new admin authentication system (loads SupabaseClient)
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

header('Content-Type: application/json');

if (!isset($_POST['round_id']) || !isset($_POST['room_code']) || !isset($_POST['room_password'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    // Initialize Supabase connection with admin privileges
    $supabase = new SupabaseClient(true);
    
    // Debug: Log the data being saved
    error_log("Saving room details for round ID: " . $_POST['round_id']);
    error_log("Room code: " . $_POST['room_code']);
    error_log("Room password: " . $_POST['room_password']);
    
    // Helper function for consistent datetime formatting
    function formatAdminDateTime() {
        $dateTime = new DateTime();
        return $dateTime->format('Y-m-d H:i:s');
    }
    
    // Update room details and change status to in_progress
    $result = $supabase->update('tournament_rounds', [
        'room_code' => $_POST['room_code'],
        'room_password' => $_POST['room_password'],
        'room_details_added_at' => formatAdminDateTime(),
        'status' => 'in_progress'
    ], ['id' => $_POST['round_id']]);
    
    // Debug: Log the update result
    error_log("Update result: " . json_encode($result));
    
    // Get round and tournament details for notifications
    $round_data = $supabase->select('tournament_rounds', 'name, tournament_id', ['id' => $_POST['round_id']], null, 1);
    if ($round_data) {
        $round = $round_data[0];
        $tournament_data = $supabase->select('tournaments', 'name', ['id' => $round['tournament_id']], null, 1);
        $tournament = $tournament_data ? $tournament_data[0] : null;
        
        // Get participating teams for this round
        $round_teams = $supabase->select('round_teams', 'team_id', ['round_id' => $_POST['round_id']]);
        
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
        $notificationMessage = "Room details added for {$round['name']}" . 
            ($tournament ? " in tournament {$tournament['name']}" : "");
        
        foreach ($users as $userId) {
            $supabase->insert('notifications', [
                'user_id' => $userId,
                'type' => 'room_details',
                'message' => $notificationMessage,
                'related_id' => $_POST['round_id'],
                'related_type' => 'tournament_round',
                'created_at' => formatAdminDateTime()
            ]);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Room details saved, round status changed to In Progress, and notifications sent to ' . count($users) . ' users',
            'refresh' => true
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'Room details saved and round status changed to In Progress',
            'refresh' => true
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
