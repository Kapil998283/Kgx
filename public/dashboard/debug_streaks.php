<?php
define('SECURE_ACCESS', true);
require_once '../secure_config.php';
loadSecureConfig('supabase.php');
loadSecureInclude('auth.php');

$authManager = new AuthManager();
if (!$authManager->isLoggedIn()) {
    die('Please log in to view this page.');
}

$currentUser = $authManager->getCurrentUser();
$user_id = $currentUser['user_id'];
$supabaseClient = new SupabaseClient();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Advanced Streak Debug</title>
    <style>
        body { font-family: sans-serif; padding: 20px; }
        .container { max-width: 800px; margin: auto; }
        .section { margin-bottom: 20px; padding: 15px; border: 1px solid #ccc; border-radius: 5px; }
        h2 { border-bottom: 1px solid #ccc; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Advanced Streak Debug</h1>

        <div class="section">
            <h2>User Information</h2>
            <p><strong>User ID:</strong> <?php echo htmlspecialchars($user_id); ?></p>
            <p><strong>Username:</strong> <?php echo htmlspecialchars($currentUser['username']); ?></p>
        </div>

        <div class="section">
            <h2>User Streaks Table</h2>
            <?php
            try {
                $streakInfo = $supabaseClient->select('user_streaks', '*', ['user_id' => $user_id]);
                if (empty($streakInfo)) {
                    echo "<p>No record found in user_streaks for this user.</p>";
                } else {
                    echo '<table>';
                    echo '<tr><th>Column</th><th>Value</th></tr>';
                    foreach ($streakInfo[0] as $key => $value) {
                        echo "<tr><td>" . htmlspecialchars($key) . "</td><td>" . htmlspecialchars($value) . "</td></tr>";
                    }
                    echo '</table>';
                }
            } catch (Exception $e) {
                echo "<p>Error fetching from user_streaks: " . $e->getMessage() . "</p>";
            }
            ?>
        </div>

        <div class="section">
            <h2>User Streak Tasks (Completed)</h2>
            <?php
            try {
                $userTasks = $supabaseClient->select('user_streak_tasks', '*', ['user_id' => $user_id], 'completion_date.desc');
                if (empty($userTasks)) {
                    echo "<p>No completed tasks found for this user.</p>";
                } else {
                    echo '<table>';
                    echo '<tr><th>Task ID</th><th>Points Earned</th><th>Completion Date</th></tr>';
                    $totalPoints = 0;
                    foreach ($userTasks as $task) {
                        echo "<tr><td>" . htmlspecialchars($task['task_id']) . "</td><td>" . htmlspecialchars($task['points_earned']) . "</td><td>" . htmlspecialchars($task['completion_date']) . "</td></tr>";
                        $totalPoints += intval($task['points_earned']);
                    }
                    echo '</table>';
                    echo "<h3>Total Points from Tasks: $totalPoints</h3>";
                }
            } catch (Exception $e) {
                echo "<p>Error fetching from user_streak_tasks: " . $e->getMessage() . "</p>";
            }
            ?>
        </div>

    </div>
</body>
</html>

