<?php

// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

require_once 'common.php';

// Use the new admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

$supabase = getSupabaseClient(true);

// Set JSON response header
header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get match ID from POST data
$match_id = isset($_POST['match_id']) ? intval($_POST['match_id']) : 0;

if ($match_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid match ID']);
    exit;
}

try {
    // Fetch all screenshots for this match
    $screenshots = $supabase->select('match_screenshots', '*', ['match_id' => $match_id]);
    
    if ($screenshots === false) {
        throw new Exception('Failed to fetch screenshots from database');
    }
    
    // Return the screenshots data
    echo json_encode([
        'success' => true,
        'screenshots' => $screenshots,
        'count' => count($screenshots)
    ]);
    
} catch (Exception $e) {
    error_log("Error in get_match_screenshots.php: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => 'Failed to fetch screenshot data: ' . $e->getMessage()
    ]);
}
?>
