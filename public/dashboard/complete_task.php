<?php
define('SECURE_ACCESS', true);
require_once '../secure_config.php';
loadSecureConfig('supabase.php');
loadSecureInclude('auth.php');

$authManager = new AuthManager();
if (!$authManager->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

$currentUser = $authManager->getCurrentUser();
$user_id = $currentUser['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$taskId = $data['task_id'] ?? null;

if (!$taskId) {
    echo json_encode(['success' => false, 'message' => 'Task ID is missing']);
    exit;
}

$supabaseClient = new SupabaseClient();

// Get task details
try {
    $task = $supabaseClient->select('streak_tasks', '*', ['id' => $taskId]);
    if (empty($task)) {
        echo json_encode(['success' => false, 'message' => 'Invalid task']);
        exit;
    }
    $task = $task[0];
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error fetching task details']);
    exit;
}

// Check if task is already completed
try {
    $completionCondition = ['user_id' => $user_id, 'task_id' => $taskId];
    if ($task['is_daily']) {
        $completionCondition['completion_date'] = ['gte', date('Y-m-d')];
    }
    $existingCompletion = $supabaseClient->select('user_streak_tasks', 'id', $completionCondition);
    if (!empty($existingCompletion)) {
        echo json_encode(['success' => false, 'message' => 'Task already completed']);
        exit;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error checking task completion']);
    exit;
}

// For one-time achievements, check if user meets the requirements
if (!$task['is_daily']) {
    $meetsRequirements = checkAchievementRequirements($supabaseClient, $user_id, $task['name']);
    if (!$meetsRequirements) {
        echo json_encode(['success' => false, 'message' => 'You do not meet the requirements for this achievement yet']);
        exit;
    }
}

// Complete the task
try {
    $supabaseClient->insert('user_streak_tasks', [
        'user_id' => $user_id,
        'task_id' => $taskId,
        'points_earned' => $task['reward_points']
    ]);

    // Update user streak using Supabase API
    $currentStreak = $supabaseClient->select('user_streaks', '*', ['user_id' => $user_id]);
    if (!empty($currentStreak)) {
        $current = $currentStreak[0];
        
        // Calculate new streak based on last activity date
        $lastActivityDate = $current['last_activity_date'] ? date('Y-m-d', strtotime($current['last_activity_date'])) : null;
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $today = date('Y-m-d');
        
        // Determine new streak count
        $newCurrentStreak = 1; // Default for first day or broken streak
        if ($lastActivityDate === $yesterday) {
            // Consecutive day - increment streak
            $newCurrentStreak = intval($current['current_streak']) + 1;
        } elseif ($lastActivityDate === $today) {
            // Same day - maintain current streak
            $newCurrentStreak = intval($current['current_streak']);
        }
        
        $newLongestStreak = max(intval($current['longest_streak']), $newCurrentStreak);
        
        $updateData = [
            'streak_points' => intval($current['streak_points']) + $task['reward_points'],
            'total_earned_points' => intval($current['total_earned_points'] ?? $current['streak_points']) + $task['reward_points'],
            'total_tasks_completed' => intval($current['total_tasks_completed']) + 1,
            'current_streak' => $newCurrentStreak,
            'longest_streak' => $newLongestStreak,
            'last_activity_date' => date('Y-m-d H:i:s')
        ];
        $supabaseClient->update('user_streaks', $updateData, ['user_id' => $user_id]);
    }

    echo json_encode(['success' => true, 'message' => 'Achievement unlocked! +' . $task['reward_points'] . ' points earned']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error completing task: ' . $e->getMessage()]);
}

// Function to check if user meets achievement requirements
function checkAchievementRequirements($supabaseClient, $user_id, $task_name) {
    switch($task_name) {
        case 'Account Registration':
            return true; // Always true if user is logged in
            
        case 'Game Profile Setup':
            $count = $supabaseClient->select('user_games', 'id', ['user_id' => $user_id], null, 1);
            return !empty($count);
            
        case 'First Match':
            try {
                // Check match_participants table
                $participants = $supabaseClient->select('match_participants', 'id', ['user_id' => $user_id], null, 1);
                if (!empty($participants)) {
                    return true;
                }
                
                // Check match_history_archive table
                $archive = $supabaseClient->select('match_history_archive', 'id', ['user_id' => $user_id], null, 1);
                return !empty($archive);
            } catch (Exception $e) {
                return false;
            }
            
        case 'Team Membership':
            $count = $supabaseClient->select('team_members', 'id', ['user_id' => $user_id, 'status' => 'active'], null, 1);
            return !empty($count);
            
        case 'First Tournament':
            $count = $supabaseClient->select('tournament_player_history', 'id', ['user_id' => $user_id], null, 1);
            return !empty($count);
            
        case 'Match Veteran':
            try {
                // Count from both tables
                $participants = $supabaseClient->select('match_participants', 'id', ['user_id' => $user_id]);
                $archive = $supabaseClient->select('match_history_archive', 'id', ['user_id' => $user_id]);
                
                $totalMatches = count($participants) + count($archive);
                return $totalMatches >= 50;
            } catch (Exception $e) {
                return false;
            }
            
        default:
            return false;
    }
}

