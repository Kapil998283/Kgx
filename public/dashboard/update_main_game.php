<?php
// Start session first
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('SECURE_ACCESS', true);
require_once '../secure_config.php';
loadSecureConfig('supabase.php');
loadSecureInclude('auth.php');

// Initialize AuthManager and SupabaseClient
$authManager = new AuthManager();
$supabaseClient = new SupabaseClient();

// Initialize response array
$response = ['success' => false, 'message' => ''];

try {
    // Check if user is logged in
    if (!$authManager->isLoggedIn()) {
        throw new Exception('User not logged in');
    }
    
    $currentUser = $authManager->getCurrentUser();
    $user_id = $currentUser['user_id'];

    // Get the game name from POST data
    $game_name = $_POST['game_name'] ?? '';
    if (empty($game_name)) {
        throw new Exception('Game name is required');
    }

    try {
        // First, check if the user has this game
        $existing_games = $supabaseClient->select('user_games', 'id', [
            'user_id' => $user_id,
            'game_name' => $game_name
        ]);

        if (empty($existing_games)) {
            // If user doesn't have this game, insert it as primary
            $supabaseClient->insert('user_games', [
                'user_id' => $user_id,
                'game_name' => $game_name,
                'is_primary' => true
            ]);
            
            // Set all other games to non-primary
            $supabaseClient->query(
                "UPDATE user_games SET is_primary = false WHERE user_id = $1 AND game_name != $2",
                [$user_id, $game_name]
            );
        } else {
            // Update all games - set the selected game to primary, others to non-primary
            $supabaseClient->query(
                "UPDATE user_games 
                 SET is_primary = CASE 
                     WHEN game_name = $2 THEN true 
                     ELSE false 
                 END 
                 WHERE user_id = $1",
                [$user_id, $game_name]
            );
        }

        $response['success'] = true;
        $response['message'] = 'Main game updated successfully';

    } catch (Exception $e) {
        // Log the specific database error
        $response['error_details'] = $e->getMessage();
        throw new Exception('Database error occurred: ' . $e->getMessage());
    }

} catch (Exception $e) {
    // Rollback transaction if there was an error
    if (isset($conn)) {
        $conn->rollBack();
    }
    $response['message'] = $e->getMessage();
}

// Send JSON response
header('Content-Type: application/json');
echo json_encode($response); 