<?php
// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once dirname(__DIR__, 2) . '/admin_secure_config.php';

// Use the new admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

header('Content-Type: application/json');

// Initialize Supabase connection with admin privileges
$supabase = new SupabaseClient(true);

// Get game_id from query parameter
$game_id = isset($_GET['game_id']) ? (int)$_GET['game_id'] : 0;

if ($game_id > 0) {
    // Fetch maps for the selected game
    $maps_data = $supabase->select('game_maps', 'map_name', ['game_id' => $game_id, 'status' => 'active'], 'map_name');
    $maps = $maps_data ? array_map(function($map) { return ['map_name' => $map['map_name']]; }, $maps_data) : [];
    
    echo json_encode($maps);
} else {
    echo json_encode([]);
}
?> 