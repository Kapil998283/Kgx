<?php
// CRITICAL: Suppress ALL error output to prevent corrupting JSON response
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Set error handler to suppress all output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Get Available Teams Error: $errstr in $errfile on line $errline");
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

    // Get parameters
    $round_id = isset($_GET['round_id']) ? (int)$_GET['round_id'] : 0;
    $tournament_id = isset($_GET['tournament_id']) ? (int)$_GET['tournament_id'] : 0;

    if (!$round_id || !$tournament_id) {
        throw new Exception('Invalid round or tournament ID');
    }

    // Get tournament info to check if it's solo or team mode
    $tournament_data = $supabase->select('tournaments', 'mode', ['id' => $tournament_id], null, 1);
    $tournament = !empty($tournament_data) ? $tournament_data[0] : null;
    
    if (!$tournament) {
        throw new Exception('Tournament not found');
    }
    
    $is_solo = $tournament['mode'] === 'Solo';

    // Get round information first
    $round_data = $supabase->select('tournament_rounds', '*', ['id' => $round_id], null, 1);
    $round = !empty($round_data) ? $round_data[0] : null;

    if (!$round) {
        throw new Exception('Round not found');
    }

    // Get day information separately if day_id exists
    $day_number = 1; // Default to day 1
    if (isset($round['day_id']) && $round['day_id']) {
        $day_data = $supabase->select('tournament_days', 'day_number', ['id' => $round['day_id']], null, 1);
        if ($day_data && !empty($day_data)) {
            $day_number = $day_data[0]['day_number'];
        }
    }

    $participants = [];
    
    if ($day_number == 1) {
        // Day 1: Get all approved registrations
        if ($is_solo) {
            // Solo tournament: get user registrations
            // First get registrations with user_id not null
            $registration_data = $supabase->select(
                'tournament_registrations', 
                'id, user_id', 
                [
                    'tournament_id' => $tournament_id, 
                    'status' => 'approved'
                ]
            );
            
            // Filter for registrations with user_id
            if ($registration_data) {
                $user_registrations = array_filter($registration_data, function($reg) {
                    return !empty($reg['user_id']);
                });
                
                // Get user details for each registration
                foreach ($user_registrations as $reg) {
                    $user_data = $supabase->select('users', 'id, username, full_name', ['id' => $reg['user_id']], null, 1);
                    if ($user_data && !empty($user_data)) {
                        $user = $user_data[0];
                        $participants[] = [
                            'id' => $user['id'],
                            'name' => $user['username'] ?: $user['full_name'],
                            'type' => 'user'
                        ];
                    }
                }
            }
        } else {
            // Team tournament: get team registrations
            // First get registrations with team_id not null
            $registration_data = $supabase->select(
                'tournament_registrations', 
                'id, team_id', 
                [
                    'tournament_id' => $tournament_id, 
                    'status' => 'approved'
                ]
            );
            
            // Filter for registrations with team_id
            if ($registration_data) {
                $team_registrations = array_filter($registration_data, function($reg) {
                    return !empty($reg['team_id']);
                });
                
                // Get team details for each registration
                foreach ($team_registrations as $reg) {
                    $team_data = $supabase->select('teams', 'id, name', ['id' => $reg['team_id']], null, 1);
                    if ($team_data && !empty($team_data)) {
                        $team = $team_data[0];
                        $participants[] = [
                            'id' => $team['id'],
                            'name' => $team['name'],
                            'type' => 'team'
                        ];
                    }
                }
            }
        }
    } else {
        // Day 2+: Get qualified participants from previous day
        $previous_day_number = $day_number - 1;
        
        // Get qualified participants from previous day's rounds
        $qualified_query = "
            SELECT DISTINCT rt.team_id
            FROM round_teams rt
            JOIN tournament_rounds tr ON rt.round_id = tr.id
            JOIN tournament_days td ON tr.day_id = td.id
            WHERE tr.tournament_id = {$tournament_id} 
            AND td.day_number = {$previous_day_number}
            AND rt.status = 'qualified'
        ";
        
        $qualified_data = $supabase->query($qualified_query);
        
        if ($qualified_data) {
            $qualified_ids = array_column($qualified_data, 'team_id');
            
            if ($is_solo) {
                // For solo tournaments, team_id represents the user's ID directly
                if (!empty($qualified_ids)) {
                    $users_data = $supabase->select(
                        'users', 
                        'id, username, full_name', 
                        ['id' => ['in', $qualified_ids]]
                    );
                    
                    if ($users_data) {
                        foreach ($users_data as $user) {
                            $participants[] = [
                                'id' => $user['id'],
                                'name' => $user['username'] ?: $user['full_name'],
                                'type' => 'user'
                            ];
                        }
                    }
                }
            } else {
                // For team tournaments
if (!empty($qualified_ids)) {
                    $teams_data = $supabase->select(
                        'teams', 
                        'id, name', 
['id' => 'in.(' . implode(',', $qualified_ids) . ')']
                    );
                    
                    if ($teams_data) {
                        foreach ($teams_data as $team) {
                            $participants[] = [
                                'id' => $team['id'],
                                'name' => $team['name'],
                                'type' => 'team'
                            ];
                        }
                    }
                }
            }
        }
    }
    
    // Get currently selected participants for this round
    $selected_ids = [];
    
    if ($is_solo) {
        // For solo tournaments, get from solo_tournament_participants
        $solo_participants_data = $supabase->select('solo_tournament_participants', 'user_id', ['round_id' => $round_id]);
        $selected_ids = array_column($solo_participants_data, 'user_id');
    } else {
        // For team tournaments, get from round_teams
        $round_teams_data = $supabase->select('round_teams', 'team_id', ['round_id' => $round_id]);
        $selected_ids = array_column($round_teams_data, 'team_id');
    }

    // Mark selected participants
    $participants = array_map(function($participant) use ($selected_ids) {
        $participant['is_selected'] = in_array($participant['id'], $selected_ids);
        return $participant;
    }, $participants);

    echo json_encode([
        'success' => true,
        'teams' => $participants, // Keep 'teams' key for JS compatibility
        'is_solo' => $is_solo,
        'day_number' => $day_number,
        'total_participants' => count($participants)
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
