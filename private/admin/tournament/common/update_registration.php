<?php
// CRITICAL: Suppress ALL error output to prevent corrupting JSON response
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Set error handler to suppress all output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Update Registration Error: $errstr in $errfile on line $errline");
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

// Set headers for JSON response
header('Content-Type: application/json');

// Check request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Invalid request method']);
    exit();
}

// Initialize Supabase client with admin privileges
$supabase = new SupabaseClient(true);

// Debug log
error_log("Received POST data: " . print_r($_POST, true));

// Validate required parameters
if (empty($_POST['registration_id']) || empty($_POST['status'])) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Missing required parameters',
        'received' => [
            'registration_id' => $_POST['registration_id'] ?? 'not set',
            'status' => $_POST['status'] ?? 'not set'
        ]
    ]);
    exit();
}

// Validate status
$allowed_statuses = ['approved', 'rejected'];
if (!in_array($_POST['status'], $allowed_statuses)) {
    echo json_encode(['error' => 'Invalid status']);
    exit();
}

try {
    // Determine if this is a solo or team registration
    $is_solo = !empty($_POST['user_id']);
    
    $filters = ['id' => $_POST['registration_id']];

    $registration_data = $supabase->select('tournament_registrations', 'status, tournament_id, user_id, team_id', $filters, null, 1);
    $registration = !empty($registration_data) ? $registration_data[0] : null;

    if (!$registration) {
        throw new Exception("Registration not found");
    }

    if ($registration['status'] !== 'pending') {
        throw new Exception("Registration has already been " . $registration['status']);
    }

    $tournament_id = $registration['tournament_id'];
    $user_id = $registration['user_id'];
    $team_id = $registration['team_id'];

    // Update registration status
    $success = $supabase->update('tournament_registrations', ['status' => $_POST['status']], $filters);

    if ($success) {
        $tournament_data = $supabase->select('tournaments', 'entry_fee, name', ['id' => $tournament_id], null, 1);
        $tournament_info = !empty($tournament_data) ? $tournament_data[0] : null;
        $entry_fee = $tournament_info['entry_fee'] ?? 0;
        
        // Determine if this is solo or team registration based on data from DB
        $is_solo_registration = !empty($user_id);
        
        if ($_POST['status'] === 'rejected' && $entry_fee > 0) {
            $user_to_refund = null;
            if ($is_solo_registration) {
                $user_to_refund = $user_id;
            } else {
                $team_captain = $supabase->select('team_members', 'user_id', ['team_id' => $team_id, 'role' => 'captain', 'status' => 'active'], null, 1);
                if (!empty($team_captain)) {
                    $user_to_refund = $team_captain[0]['user_id'];
                }
            }
            if ($user_to_refund) {
                $supabase->rpc('refund_tickets', ['user_id_param' => $user_to_refund, 'amount' => $entry_fee]);
            }
        }
        
        if ($_POST['status'] === 'approved') {
            // Add players to history
            if ($is_solo_registration) {
                $supabase->insert('tournament_player_history', [
                    'tournament_id' => $tournament_id,
                    'user_id' => $user_id,
                    'registration_date' => 'now()',
                    'status' => 'registered'
                ]);
            } else {
                $team_members = $supabase->select('team_members', 'user_id', ['team_id' => $team_id, 'status' => 'active']);
                if ($team_members) {
                    foreach ($team_members as $member) {
                        $supabase->insert('tournament_player_history', [
                            'tournament_id' => $tournament_id,
                            'user_id' => $member['user_id'],
                            'team_id' => $team_id,
                            'registration_date' => 'now()',
                            'status' => 'registered'
                        ], ['on_conflict' => 'tournament_id, user_id']);
                    }
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Registration status updated to ' . $_POST['status'] . ' successfully'
    ]);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?> 