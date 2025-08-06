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
    header('Location: ' . BASE_URL . 'register/login.php');
    exit;
}

$currentUser = $authManager->getCurrentUser();
$user_id = $currentUser['user_id'];

try {
    $tasks_data = $supabaseClient->query(
        "SELECT st.*, CASE WHEN ust.id IS NOT NULL THEN 1 ELSE 0 END as completed
         FROM streak_tasks st
         LEFT JOIN user_streak_tasks ust ON st.id = ust.task_id AND ust.user_id = $1 AND DATE(ust.completion_date) = CURRENT_DATE
         ORDER BY st.reward_points ASC",
        [$user_id]
    );
    $tasks = !empty($tasks_data) ? $tasks_data : [];
} catch (Exception $e) {
    error_log("Error fetching tasks: " . $e->getMessage());
    $tasks = [];
}

try {
    $streakInfo = $supabaseClient->select('user_streaks', '*', ['user_id' => $user_id]);
    $streakInfo = !empty($streakInfo) ? $streakInfo[0] : null;
} catch (Exception $e) {
    error_log("Error fetching streak info: " . $e->getMessage());
    $streakInfo = null;
}

if (!$streakInfo) {
    $streakInfo = [
        'current_streak' => 0,
        'longest_streak' => 0,
        'streak_points' => 0,
        'total_tasks_completed' => 0
    ];
}

try {
    $nextMilestone = $supabaseClient->query(
        "SELECT * FROM streak_milestones WHERE points_required > $1 ORDER BY points_required ASC LIMIT 1",
        [$streakInfo['streak_points']]
    );
    $nextMilestone = !empty($nextMilestone) ? $nextMilestone[0] : null;
} catch (Exception $e) {
    error_log("Error fetching next milestone: " . $e->getMessage());
    $nextMilestone = null;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily Streak Tasks</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/streak/streak.css">
    <link rel="stylesheet" href="../assets/css/streak/alerts.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="streak.js"></script>
</head>
<body>
    <!-- Header content removed - include if needed -->

    <div class="container">
        <div class="dashboard-container">
            <div class="streak-info-card">
                <h2>Your Streak Stats</h2>
                <div class="streak-stats">
                    <div class="stat">
                        <span class="label">Current Streak</span>
                        <span class="value" id="current-streak"><?php echo $streakInfo['current_streak']; ?></span>
                    </div>
                    <div class="stat">
                        <span class="label">Longest Streak</span>
                        <span class="value"><?php echo $streakInfo['longest_streak']; ?></span>
                    </div>
                    <div class="stat">
                        <span class="label">Total Points</span>
                        <span class="value" id="streak-points"><?php echo $streakInfo['streak_points']; ?></span>
                    </div>
                </div>
                <?php if ($nextMilestone): ?>
                <div class="milestone-progress">
                    <h3>Next Milestone: <?php echo htmlspecialchars($nextMilestone['name']); ?></h3>
                    <div class="progress-bar">
                        <?php 
                        $progress = min(100, ($streakInfo['streak_points'] / $nextMilestone['points_required']) * 100);
                        ?>
                        <div class="progress" style="width: <?php echo $progress; ?>%"></div>
                    </div>
                    <span class="progress-text"><?php echo $streakInfo['streak_points']; ?> / <?php echo $nextMilestone['points_required']; ?> points</span>
                </div>
                <?php endif; ?>
            </div>

            <div class="tasks-container">
                <h2>Today's Tasks</h2>
                <div id="alert-container"></div>
                <div class="tasks-grid">
                    <?php foreach ($tasks as $task): ?>
                    <div class="task-card <?php echo $task['completed'] ? 'completed' : ''; ?>" data-task-id="<?php echo $task['id']; ?>">
                        <h3><?php echo htmlspecialchars($task['name']); ?></h3>
                        <p><?php echo htmlspecialchars($task['description']); ?></p>
                        <div class="task-footer">
                            <span class="points"><?php echo $task['reward_points']; ?> points</span>
                            <?php if (!$task['completed']): ?>
                            <button class="complete-task-btn" onclick="completeTask(<?php echo $task['id']; ?>)">Complete</button>
                            <?php else: ?>
                            <span class="completed-label">Completed</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer content removed - include if needed -->
</body>
</html> 