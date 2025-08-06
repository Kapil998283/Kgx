<?php
// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Set content type to JSON
header('Content-Type: application/json');

// Import common functions
require_once __DIR__ . '/common.php';

// Get match ID from URL
$match_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($match_id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valid match ID is required']);
    exit;
}

try {
    // Use the common function to get match data
    $match = getMatchData($match_id);
    
    // Return success response
    echo json_encode([
        'success' => true,
        'data' => $match
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
