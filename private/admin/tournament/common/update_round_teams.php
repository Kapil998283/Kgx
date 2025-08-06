<?php
// CRITICAL: Suppress ALL error output to prevent corrupting JSON response
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Set error handler to suppress all output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Update Round Teams Error: $errstr in $errfile on line $errline");
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
    $supabase = new SupabaseClient(true);
    // Get POST data
    $round_id = isset($_POST['round_id']) ? (int)$_POST['round_id'] : 0;
    $tournament_id = isset($_POST['tournament_id']) ? (int)$_POST['tournament_id'] : 0;
    $selected_teams = isset($_POST['selected_teams']) ? $_POST['selected_teams'] : [];

    if (!$round_id || !$tournament_id) {
        throw new Exception('Invalid round or tournament ID');
    }

    $round_data = $supabase->select('tournament_rounds', '*', ['id' => $round_id], null, 1);
    $round = !empty($round_data) ? $round_data[0] : null;

    if (!$round) {
        throw new Exception('Round not found');
    }
    
    // Get tournament info to check if it's Solo mode
    $tournament_data = $supabase->select('tournaments', 'mode', ['id' => $tournament_id], null, 1);
    $tournament = !empty($tournament_data) ? $tournament_data[0] : null;
    
    if (!$tournament) {
        throw new Exception('Tournament not found');
    }
    
    $is_solo = $tournament['mode'] === 'Solo';
    error_log("Tournament mode: {$tournament['mode']}, Is Solo: " . ($is_solo ? 'true' : 'false'));
    
    // Debug logging
    error_log("Round ID: $round_id, Teams count: {$round['teams_count']}, Selected teams: " . count($selected_teams));
    
    // Handle edge case where teams_count might be 0 or null
    $max_teams = (int)$round['teams_count'];
    if ($max_teams <= 0) {
        // Try to calculate a reasonable default based on tournament structure
        $tournament_data = $supabase->select('tournaments', 'max_teams', ['id' => $tournament_id], null, 1);
        if ($tournament_data && !empty($tournament_data)) {
            $max_teams = min(100, (int)$tournament_data[0]['max_teams']); // Set a reasonable default
            error_log("Using fallback max teams: $max_teams for round $round_id");
        } else {
            $max_teams = 100; // Ultimate fallback
            error_log("Using ultimate fallback max teams: $max_teams for round $round_id");
        }
        
        // Update the round with the corrected teams_count
        $supabase->update('tournament_rounds', ['teams_count' => $max_teams], ['id' => $round_id]);
    }

    if (count($selected_teams) > $max_teams) {
        throw new Exception('Too many teams selected. Maximum allowed: ' . $max_teams);
    }

    // Get current participants to determine which to remove
    $current_participants = [];
    $current_participant_ids = [];
    
    if ($is_solo) {
        // For solo tournaments, get from solo_tournament_participants
        $current_participants = $supabase->select('solo_tournament_participants', 'user_id', ['round_id' => $round_id]);
        $current_participant_ids = array_column($current_participants, 'user_id');
    } else {
        // For team tournaments, get from round_teams
        $current_participants = $supabase->select('round_teams', 'team_id', ['round_id' => $round_id]);
        $current_participant_ids = array_column($current_participants, 'team_id');
    }
    
    // Participants to remove (in current but not in selected)
    $participants_to_remove = array_diff($current_participant_ids, $selected_teams);
    
    // Participants to add (in selected but not in current)
    $participants_to_add = array_diff($selected_teams, $current_participant_ids);
    
    // Log debugging information
    error_log("Round $round_id - Current participants: " . implode(',', $current_participant_ids));
    error_log("Round $round_id - Selected participants: " . implode(',', $selected_teams));
    error_log("Round $round_id - Participants to remove: " . implode(',', $participants_to_remove));
    error_log("Round $round_id - Participants to add: " . implode(',', $participants_to_add));
    
    $success_messages = [];
    $teams_added = 0;
    $teams_removed = 0;
    $failed_operations = 0;
    
    // Remove participants directly
    if (!empty($participants_to_remove)) {
        try {
            foreach ($participants_to_remove as $participant_id) {
                if ($is_solo) {
                    // Remove from solo_tournament_participants
                    $delete_result = $supabase->delete('solo_tournament_participants', [
                        'round_id' => $round_id,
                        'user_id' => $participant_id
                    ]);
                } else {
                    // Remove from round_teams
                    $delete_result = $supabase->delete('round_teams', [
                        'round_id' => $round_id,
                        'team_id' => $participant_id
                    ]);
                }
                
                if ($delete_result) {
                    $teams_removed++;
                    error_log("Removed " . ($is_solo ? 'player' : 'team') . " $participant_id from round $round_id");
                } else {
                    $failed_operations++;
                    error_log("Failed to remove " . ($is_solo ? 'player' : 'team') . " $participant_id from round $round_id");
                }
            }
            if ($teams_removed > 0) {
                $success_messages[] = "Removed $teams_removed " . ($is_solo ? 'players' : 'teams');
            }
        } catch (Exception $e) {
            error_log("Direct participant removal failed: " . $e->getMessage());
            $failed_operations += count($participants_to_remove);
        }
    }
    
    // Add participants directly
    if (!empty($participants_to_add)) {
        try {
            foreach ($participants_to_add as $participant_id) {
                $participant_id = (int)$participant_id;
                if ($participant_id <= 0) {
                    error_log("Invalid participant ID: $participant_id");
                    $failed_operations++;
                    continue;
                }

                if ($is_solo) {
                    // Add to solo_tournament_participants
                    $existing = $supabase->select('solo_tournament_participants', 'id', [
                        'round_id' => $round_id,
                        'user_id' => $participant_id
                    ], null, 1);

                    if (empty($existing)) {
                        $insert_result = $supabase->insert('solo_tournament_participants', [
                            'round_id' => $round_id,
                            'user_id' => $participant_id,
                            'tournament_id' => $tournament_id, // Add tournament_id
                            'status' => 'selected'
                        ]);
                        if ($insert_result) {
                            $teams_added++;
                        }
                    }
                } else {
                    // Add to round_teams
                    $existing = $supabase->select('round_teams', 'id', [
                        'round_id' => $round_id,
                        'team_id' => $participant_id
                    ], null, 1);

                    if (empty($existing)) {
                        $insert_result = $supabase->insert('round_teams', [
                            'round_id' => $round_id,
                            'team_id' => $participant_id,
                            'status' => 'selected'
                        ]);
                        if ($insert_result) {
                            $teams_added++;
                        }
                    }
                }
            }
            if ($teams_added > 0) {
                $success_messages[] = "Added $teams_added " . ($is_solo ? 'players' : 'teams');
            }
        } catch (Exception $e) {
            error_log("Direct participant addition failed: " . $e->getMessage());
            $failed_operations += count($participants_to_add);
        }
    }
    
    // Prepare detailed response message
    $final_message = [];
    if (!empty($success_messages)) {
        $final_message = array_merge($final_message, $success_messages);
    }
    if ($failed_operations > 0) {
        $final_message[] = "$failed_operations failed/duplicates";
    }
    
    echo json_encode([
        'success' => true,
        'message' => empty($final_message) ? 'No changes made' : implode(', ', $final_message),
        'teams_count' => count($selected_teams),
        'details' => [
            'teams_added' => $teams_added,
            'teams_removed' => $teams_removed,
            'failed_operations' => $failed_operations,
            'total_selected' => count($selected_teams)
        ]
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
