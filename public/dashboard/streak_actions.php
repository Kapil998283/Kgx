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

if (!$authManager->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$currentUser = $authManager->getCurrentUser();
$user_id = $currentUser['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'complete_task':
            if (!isset($_POST['task_id'])) {
                http_response_code(400);
                echo json_encode(['error' => 'Task ID is required']);
                exit;
            }
            
            $taskId = (int)$_POST['task_id'];
            
            try {
                // Check if task already completed today
                $existing_completion = $supabaseClient->query(
                    "SELECT id FROM user_streak_tasks WHERE user_id = $1 AND task_id = $2 AND DATE(completion_date) = CURRENT_DATE",
                    [$user_id, $taskId]
                );
                
                if (empty($existing_completion)) {
                    // Get task points
                    $task_data = $supabaseClient->select('streak_tasks', 'reward_points', ['id' => $taskId]);
                    $task_points = !empty($task_data) ? $task_data[0]['reward_points'] : 0;

                    // Record task completion
                    $supabaseClient->insert('user_streak_tasks', [
                        'user_id' => $user_id,
                        'task_id' => $taskId,
                        'points_earned' => $task_points
                    ]);

                    // Update user streak points
                    $supabaseClient->query(
                        "UPDATE user_streaks SET streak_points = streak_points + $1, total_tasks_completed = total_tasks_completed + 1 WHERE user_id = $2",
                        [$task_points, $user_id]
                    );
                    
                    // Get updated streak info
                    $streakInfo = $supabaseClient->select('user_streaks', '*', ['user_id' => $user_id]);
                    $streakInfo = !empty($streakInfo) ? $streakInfo[0] : null;
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'Task completed successfully!',
                        'streak_info' => $streakInfo
                    ]);
                } else {
                    http_response_code(400);
                    echo json_encode([
                        'error' => 'Task already completed today'
                    ]);
                }
            } catch (Exception $e) {
                error_log("Error completing task: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'error' => 'Failed to complete task'
                ]);
            }
            break;
            
        case 'get_streak_info':
            try {
                $streakInfo = $supabaseClient->select('user_streaks', '*', ['user_id' => $user_id]);
                $streakInfo = !empty($streakInfo) ? $streakInfo[0] : null;
                
                if (!$streakInfo) {
                    $streakInfo = [
                        'current_streak' => 0,
                        'longest_streak' => 0,
                        'streak_points' => 0,
                        'total_tasks_completed' => 0
                    ];
                }
                
                echo json_encode([
                    'success' => true,
                    'streak_info' => $streakInfo
                ]);
            } catch (Exception $e) {
                error_log("Error getting streak info: " . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'error' => 'Failed to get streak info'
                ]);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['error' => 'Invalid action']);
            break;
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?> 