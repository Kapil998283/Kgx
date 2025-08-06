<?php
// Only start session if one isn't already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
define('SECURE_ACCESS', true);
require_once '../secure_config.php';

// Load secure configurations and includes
loadSecureConfig('supabase.php');
loadSecureInclude('auth.php');

header('Content-Type: text/plain');

if (!isset($_GET['name'])) {
    echo 'Team name is required';
    exit;
}

$name = trim($_GET['name']);

if (strlen($name) < 3) {
    echo 'Team name must be at least 3 characters long';
    exit;
}

if (strlen($name) > 50) {
    echo 'Team name cannot exceed 50 characters';
    exit;
}

try {
    // Initialize SupabaseClient
    $supabaseClient = new SupabaseClient();
    
    // Check if team name exists using Supabase REST API
    // Note: We'll use a simple approach since Supabase REST API doesn't directly support LOWER() functions
    $teams = $supabaseClient->select('teams', 'name', ['is_active' => true]);
    
    $nameExists = false;
    foreach ($teams as $team) {
        if (strtolower($team['name']) === strtolower($name)) {
            $nameExists = true;
            break;
        }
    }
    
    if ($nameExists) {
        echo 'taken';
    } else {
        echo 'available';
    }
} catch (Exception $e) {
    error_log('Team name check error: ' . $e->getMessage());
    echo 'error';
}
?> 