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

if (!isset($_GET['round_id'])) {
    echo json_encode(['error' => 'Round ID not provided']);
    exit;
}

try {
    // Initialize Supabase connection with admin privileges
    $supabase = new SupabaseClient(true);
    
    // Debug: Log the round ID being requested
    error_log("Getting room details for round ID: " . $_GET['round_id']);
    
    $result = $supabase->select('tournament_rounds', 'room_code, room_password', ['id' => $_GET['round_id']], null, 1);
    
    // Debug: Log the result
    error_log("Room details result: " . json_encode($result));
    
    if ($result && !empty($result)) {
        echo json_encode($result[0]);
    } else {
        echo json_encode(['room_code' => '', 'room_password' => '']);
    }
} catch (Exception $e) {
    error_log("Error getting room details: " . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
?>
