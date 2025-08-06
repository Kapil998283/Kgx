<?php
// CRITICAL: Suppress ALL error output to prevent corrupting JSON response
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Set error handler to suppress all output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Fix Round Teams Count Error: $errstr in $errfile on line $errline");
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
    
    // Get tournament ID from URL parameter
    $tournament_id = isset($_GET['tournament_id']) ? (int)$_GET['tournament_id'] : 0;
    
    if (!$tournament_id) {
        throw new Exception('Tournament ID is required');
    }
    
    // Get all rounds for this tournament
    $rounds_data = $supabase->select('tournament_rounds', '*', ['tournament_id' => $tournament_id]);
    
    if (!$rounds_data) {
        throw new Exception('No rounds found for this tournament');
    }
    
    $fixed_rounds = [];
    $errors = [];
    
    foreach ($rounds_data as $round) {
        try {
            $round_id = $round['id'];
            $current_teams_count = (int)$round['teams_count'];
            
            // Check if teams_count is 0 or seems incorrect
            if ($current_teams_count <= 0) {
                // Get tournament info to calculate a reasonable default
                $tournament_data = $supabase->select('tournaments', 'max_teams', ['id' => $tournament_id], null, 1);
                
                if ($tournament_data && !empty($tournament_data)) {
                    $max_tournament_teams = (int)$tournament_data[0]['max_teams'];
                    
                    // Try to get the day info to understand round structure
                    $day_data = null;
                    if (isset($round['day_id']) && $round['day_id']) {
                        $day_data = $supabase->select('tournament_days', '*', ['id' => $round['day_id']], null, 1);
                    }
                    
                    // Calculate reasonable teams per round
                    // If we can't determine day structure, use a safe default
                    $rounds_on_same_day = $supabase->select('tournament_rounds', 'id', ['day_id' => $round['day_id']]);
                    $rounds_count = count($rounds_on_same_day);
                    
                    if ($rounds_count > 0) {
                        $suggested_teams_count = ceil($max_tournament_teams / $rounds_count);
                    } else {
                        $suggested_teams_count = min(25, $max_tournament_teams); // Safe default
                    }
                    
                    // Ensure it's at least 1 and at most the tournament max
                    $suggested_teams_count = max(1, min($suggested_teams_count, $max_tournament_teams));
                    
                    // Update the round
                    $update_result = $supabase->update('tournament_rounds', [
                        'teams_count' => $suggested_teams_count
                    ], ['id' => $round_id]);
                    
                    $fixed_rounds[] = [
                        'round_id' => $round_id,
                        'round_name' => $round['name'],
                        'old_teams_count' => $current_teams_count,
                        'new_teams_count' => $suggested_teams_count,
                        'rounds_on_day' => $rounds_count
                    ];
                    
                    error_log("Fixed round $round_id: teams_count changed from $current_teams_count to $suggested_teams_count");
                } else {
                    $errors[] = "Could not get tournament data for round {$round['name']} (ID: $round_id)";
                }
            }
        } catch (Exception $e) {
            $errors[] = "Error fixing round {$round['name']} (ID: {$round['id']}): " . $e->getMessage();
            error_log("Error fixing round {$round['id']}: " . $e->getMessage());
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Round teams_count fix completed',
        'fixed_rounds' => $fixed_rounds,
        'errors' => $errors,
        'total_rounds_checked' => count($rounds_data),
        'rounds_fixed' => count($fixed_rounds)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
