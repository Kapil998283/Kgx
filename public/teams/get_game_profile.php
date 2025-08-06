<?php
define('SECURE_ACCESS', true);
require_once '../secure_config.php';

// Load secure configurations and includes
loadSecureConfig('supabase.php');
loadSecureInclude('auth.php');

// Initialize response array
$response = ['success' => false, 'message' => '', 'game_profile' => null];

try {
    // Get user ID from query parameter
    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
    
    if ($user_id <= 0) {
        throw new Exception('Invalid user ID');
    }

    // Initialize SupabaseClient and database connection inside try-catch
    $supabaseClient = new SupabaseClient(); // Use anon key for user side
    $db = $supabaseClient->getConnection();

    // First, try to get user's primary game profile
    $stmt = $db->prepare("SELECT * FROM user_games WHERE user_id = ? AND is_primary = true LIMIT 1");
    $stmt->execute([$user_id]);
    $game_profile = $stmt->fetch(PDO::FETCH_ASSOC);

    // If no primary game found, get the most recent game profile
    if (!$game_profile) {
        $stmt = $db->prepare("SELECT * FROM user_games WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$user_id]);
        $game_profile = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($game_profile) {
        $response['success'] = true;
        $response['game_profile'] = $game_profile;
        $response['message'] = 'Game profile found successfully';
    } else {
        $response['message'] = 'No game profile found for this user';
    }

} catch (Exception $e) {
    $response['message'] = 'Error: ' . $e->getMessage();
    error_log("Game profile fetch error: " . $e->getMessage());
}

// Send JSON response with proper headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
echo json_encode($response);
