<?php
// CRITICAL: Suppress ALL error output to prevent corrupting JSON response
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Set error handler to suppress all output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Tournament Registrar Error: $errstr in $errfile on line $errline");
    return true; // Don't execute PHP internal error handler
});

// Define admin secure access
define('ADMIN_SECURE_ACCESS', true);

// Load admin secure configuration
require_once dirname(__DIR__, 2) . '/admin_secure_config.php';

// Use the new admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

// Initialize Supabase connection with admin privileges
$supabase = new SupabaseClient(true);

header('Content-Type: application/json');

try {
    $tournament_id = isset($_GET['tournament_id']) ? (int)$_GET['tournament_id'] : 0;
    $count = isset($_GET['count']) ? min(50, max(1, (int)$_GET['count'])) : 20; // Default 20, max 50
    $status = isset($_GET['status']) ? $_GET['status'] : 'pending'; // pending, approved, rejected
    
    if (!$tournament_id) {
        throw new Exception('Tournament ID is required');
    }
    
    // Validate tournament exists
    $tournament_data = $supabase->select('tournaments', 'id, name, mode, game_name, entry_fee', ['id' => $tournament_id], null, 1);
    if (empty($tournament_data)) {
        throw new Exception('Tournament not found');
    }
    
    $tournament = $tournament_data[0];
    
    // Get test users that aren't already registered for this tournament
    $registered_users = [];
    if ($tournament['mode'] === 'Solo') {
        $existing_registrations = $supabase->select('tournament_registrations', 'user_id', [
            'tournament_id' => $tournament_id,
            'user_id' => ['not.is', null]
        ]);
        $registered_users = array_column($existing_registrations, 'user_id');
    }
    
    // Build filter for available users
    $user_filter = ['email' => ['like', '%@testuser.com']];
    if (!empty($registered_users)) {
        $user_filter['id'] = ['not.in', $registered_users];
    }
    
    // Get available test users
    $available_users = $supabase->select(
        'users', 
        'id, username, email',
        $user_filter,
        'created_at.desc',
        $count
    );
    
    if (empty($available_users)) {
        throw new Exception('No available test users found. Create some fake users first.');
    }
    
    $registered_count = 0;
    $failed_registrations = [];
    
    foreach ($available_users as $user) {
        try {
            // Check if user has enough tickets
            $user_tickets = $supabase->select('user_tickets', 'tickets', ['user_id' => $user['id']], null, 1);
            $available_tickets = !empty($user_tickets) ? $user_tickets[0]['tickets'] : 0;
            
            if ($available_tickets < $tournament['entry_fee']) {
                $failed_registrations[] = [
                    'username' => $user['username'],
                    'error' => 'Insufficient tickets'
                ];
                continue;
            }
            
            // Deduct entry fee
            $supabase->update('user_tickets', 
                ['tickets' => $available_tickets - $tournament['entry_fee']], 
                ['user_id' => $user['id']]
            );
            
            // Register for tournament
            $registration_data = [
                'tournament_id' => $tournament_id,
                'user_id' => $user['id'],
                'status' => $status,
                'registration_date' => date('Y-m-d H:i:s')
            ];
            
            $result = $supabase->insert('tournament_registrations', $registration_data);
            
            if ($result) {
                $registered_count++;
            } else {
                $failed_registrations[] = [
                    'username' => $user['username'],
                    'error' => 'Registration insert failed'
                ];
            }
            
        } catch (Exception $e) {
            $failed_registrations[] = [
                'username' => $user['username'],
                'error' => $e->getMessage()
            ];
        }
    }
    
    // Update tournament current_teams count
    if ($registered_count > 0 && $tournament['mode'] === 'Solo') {
        $current_registrations = $supabase->select('tournament_registrations', 'id', [
            'tournament_id' => $tournament_id,
            'user_id' => ['not.is', null]
        ]);
        
        $total_registered = count($current_registrations);
        $supabase->update('tournaments', ['current_teams' => $total_registered], ['id' => $tournament_id]);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Tournament registrations completed',
        'tournament_name' => $tournament['name'],
        'tournament_mode' => $tournament['mode'],
        'registered_count' => $registered_count,
        'failed_count' => count($failed_registrations),
        'status_set' => $status,
        'failed_registrations' => $failed_registrations
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
