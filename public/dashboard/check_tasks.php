<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('SECURE_ACCESS', true);
require_once '../secure_config.php';
loadSecureConfig('supabase.php');
loadSecureInclude('auth.php');

$authManager = new AuthManager();
$supabaseClient = new SupabaseClient();

header('Content-Type: application/json');

if (!$authManager->isLoggedIn()) {
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$currentUser = $authManager->getCurrentUser();
$user_id = $currentUser['user_id'];

// Get last check timestamp from session or set current time
$last_check = $_SESSION['last_task_check'] ?? time();
$_SESSION['last_task_check'] = time();

try {
    // Check for any new task completions since last check
    $new_completions = $supabaseClient->query(
        "SELECT COUNT(*) as count FROM user_streak_tasks WHERE user_id = $1 AND completion_date > to_timestamp($2)",
        [$user_id, $last_check]
    );
    
    echo json_encode([
        'new_completion' => !empty($new_completions) && $new_completions[0]['count'] > 0
    ]);

} catch (Exception $e) {
    error_log("Error checking for new tasks: " . $e->getMessage());
    echo json_encode(['new_completion' => false]);
}
