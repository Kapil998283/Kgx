<?php
define('SECURE_ACCESS', true);
require_once '../secure_config.php';

// Load secure configurations and includes
loadSecureConfig('supabase.php');
loadSecureInclude('auth.php');

// Check if we have any user_games data
try {
    // Initialize SupabaseClient and database connection inside try-catch
    $supabaseClient = new SupabaseClient(); // Use anon key for user side
    $db = $supabaseClient->getConnection();
    echo "<h2>Debug: User Games Table</h2>";
    
    // Get all user_games entries
    $stmt = $db->prepare("SELECT * FROM user_games ORDER BY created_at DESC LIMIT 10");
    $stmt->execute();
    $all_games = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>All User Games (Latest 10 entries):</h3>";
    if (!empty($all_games)) {
        echo "<table border='1'>";
        echo "<tr><th>ID</th><th>User ID</th><th>Game Name</th><th>Username</th><th>UID</th><th>Level</th><th>Primary</th><th>Created</th></tr>";
        foreach ($all_games as $game) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($game['id']) . "</td>";
            echo "<td>" . htmlspecialchars($game['user_id']) . "</td>";
            echo "<td>" . htmlspecialchars($game['game_name']) . "</td>";
            echo "<td>" . htmlspecialchars($game['game_username']) . "</td>";
            echo "<td>" . htmlspecialchars($game['game_uid']) . "</td>";
            echo "<td>" . htmlspecialchars($game['game_level']) . "</td>";
            echo "<td>" . ($game['is_primary'] ? 'Yes' : 'No') . "</td>";
            echo "<td>" . htmlspecialchars($game['created_at']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: red;'>No user games found in the database!</p>";
    }
    
    // Get specific user if provided
    if (isset($_GET['user_id'])) {
        $user_id = (int)$_GET['user_id'];
        echo "<h3>Games for User ID: $user_id</h3>";
        
        $stmt = $db->prepare("SELECT * FROM user_games WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user_games = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($user_games)) {
            echo "<table border='1'>";
            echo "<tr><th>ID</th><th>Game Name</th><th>Username</th><th>UID</th><th>Level</th><th>Primary</th><th>Created</th></tr>";
            foreach ($user_games as $game) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($game['id']) . "</td>";
                echo "<td>" . htmlspecialchars($game['game_name']) . "</td>";
                echo "<td>" . htmlspecialchars($game['game_username']) . "</td>";
                echo "<td>" . htmlspecialchars($game['game_uid']) . "</td>";
                echo "<td>" . htmlspecialchars($game['game_level']) . "</td>";
                echo "<td>" . ($game['is_primary'] ? 'Yes' : 'No') . "</td>";
                echo "<td>" . htmlspecialchars($game['created_at']) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p style='color: red;'>No games found for user ID: $user_id</p>";
        }
    }
    
    // Test the API endpoint
    if (isset($_GET['test_api']) && isset($_GET['user_id'])) {
        $user_id = (int)$_GET['user_id'];
        echo "<h3>Testing API Response for User ID: $user_id</h3>";
        
        $api_url = "get_game_profile.php?user_id=$user_id";
        $response = file_get_contents($api_url);
        echo "<pre>" . htmlspecialchars($response) . "</pre>";
    }
    
    echo "<hr>";
    echo "<h3>Usage:</h3>";
    echo "<p>To check specific user: <a href='?user_id=1'>debug_user_games.php?user_id=1</a></p>";
    echo "<p>To test API: <a href='?user_id=1&test_api=1'>debug_user_games.php?user_id=1&test_api=1</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
