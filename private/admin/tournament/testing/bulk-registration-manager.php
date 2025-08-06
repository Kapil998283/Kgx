<?php
// CRITICAL: Suppress ALL error output to prevent corrupting JSON response
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Set error handler to suppress all output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Bulk Registration Manager Error: $errstr in $errfile on line $errline");
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
    $action = isset($_GET['action']) ? $_GET['action'] : '';
    
    if (!$tournament_id) {
        throw new Exception('Tournament ID is required');
    }
    
    if (!in_array($action, ['approve', 'reject', 'reset'])) {
        throw new Exception('Invalid action. Must be approve, reject, or reset');
    }
    
    // Validate tournament exists
    $tournament_data = $supabase->select('tournaments', 'id, name', ['id' => $tournament_id], null, 1);
    if (empty($tournament_data)) {
        throw new Exception('Tournament not found');
    }
    
    $tournament = $tournament_data[0];
    
    // Define filters and new status based on action
    $filter = ['tournament_id' => $tournament_id];
    $new_status = '';
    
    switch ($action) {
        case 'approve':
            $filter['status'] = 'pending';
            $new_status = 'approved';
            break;
        case 'reject':
            $filter['status'] = 'pending';
            $new_status = 'rejected';
            break;
        case 'reset':
            $filter['status'] = ['in', ['approved', 'rejected']];
            $new_status = 'pending';
            break;
    }
    
    // Get registrations to update
    $registrations = $supabase->select('tournament_registrations', 'id, status', $filter);
    
    if (empty($registrations)) {
        echo json_encode([
            'success' => true,
            'message' => 'No registrations found to update',
            'updated_count' => 0
        ]);
        exit;
    }
    
    // Update all matching registrations
    $updated_count = 0;
    foreach ($registrations as $registration) {
        try {
            $result = $supabase->update(
                'tournament_registrations', 
                ['status' => $new_status], 
                ['id' => $registration['id']]
            );
            
            if ($result) {
                $updated_count++;
            }
        } catch (Exception $e) {
            error_log("Failed to update registration {$registration['id']}: " . $e->getMessage());
        }
    }
    
    $action_messages = [
        'approve' => 'Approved all pending registrations',
        'reject' => 'Rejected all pending registrations',
        'reset' => 'Reset all registrations to pending status'
    ];
    
    echo json_encode([
        'success' => true,
        'message' => $action_messages[$action],
        'tournament_name' => $tournament['name'],
        'updated_count' => $updated_count,
        'total_found' => count($registrations)
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
