<?php
define('SECURE_ACCESS', true);
require_once '../secure_config.php';
loadSecureConfig('supabase.php');
loadSecureInclude('auth.php');

// Initialize AuthManager and SupabaseClient
$authManager = new AuthManager();
$supabaseClient = new SupabaseClient();

// Check if user is logged in
if (!$authManager->isLoggedIn()) {
    // Store the intended destination
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
    // Redirect to login page
    header('Location: ' . BASE_URL . 'register/login.php');
    exit;
}

$currentUser = $authManager->getCurrentUser();
$user_id = $currentUser['user_id'];

// Retroactively grant "Account Registration" achievement if missing
try {
    $regTask = $supabaseClient->select('streak_tasks', 'id, reward_points', ['name' => 'Account Registration'], null, 1);
    if (!empty($regTask)) {
        $regTaskId = $regTask[0]['id'];
        $regTaskPoints = $regTask[0]['reward_points'];

        $regCompletion = $supabaseClient->select('user_streak_tasks', 'id', [
            'user_id' => $user_id,
            'task_id' => $regTaskId
        ]);

        if (empty($regCompletion)) {
            // Grant the achievement
            $supabaseClient->insert('user_streak_tasks', [
                'user_id' => $user_id,
                'task_id' => $regTaskId,
                'points_earned' => $regTaskPoints
            ]);

            // Update points, but don't affect the streak for a retroactive fix
            $currentStreak = $supabaseClient->select('user_streaks', '*', ['user_id' => $user_id]);
            if (!empty($currentStreak)) {
                $current = $currentStreak[0];
                $supabaseClient->update('user_streaks', [
                    'streak_points' => intval($current['streak_points']) + $regTaskPoints,
                    'total_earned_points' => intval($current['total_earned_points'] ?? $current['streak_points']) + $regTaskPoints,
                    'total_tasks_completed' => intval($current['total_tasks_completed']) + 1
                ], ['user_id' => $user_id]);
            }

            // Set a flag to reload the page once
            $_SESSION['achievement_granted'] = true;
        }
    }
} catch (Exception $e) {
    error_log("Streak Page: Error with retroactive registration achievement: " . $e->getMessage());
}

// Check for "Game Profile Setup" achievement
try {
    $gameProfileTask = $supabaseClient->select('streak_tasks', 'id, reward_points', ['name' => 'Game Profile Setup'], null, 1);
    if (!empty($gameProfileTask)) {
        $gameProfileTaskId = $gameProfileTask[0]['id'];
        $gameProfileTaskPoints = $gameProfileTask[0]['reward_points'];

        $gameProfileCompletion = $supabaseClient->select('user_streak_tasks', 'id', [
            'user_id' => $user_id,
            'task_id' => $gameProfileTaskId
        ]);

        if (empty($gameProfileCompletion)) {
            $userGame = $supabaseClient->select('user_games', 'id', ['user_id' => $user_id], null, 1);
            if (!empty($userGame)) {
                $supabaseClient->insert('user_streak_tasks', [
                    'user_id' => $user_id,
                    'task_id' => $gameProfileTaskId,
                    'points_earned' => $gameProfileTaskPoints
                ]);

            $updateData = [
                'streak_points' => intval($current['streak_points']) + $gameProfileTaskPoints,
                'total_earned_points' => intval($current['total_earned_points'] ?? $current['streak_points']) + $gameProfileTaskPoints,
                'total_tasks_completed' => intval($current['total_tasks_completed']) + 1
            ];
            $supabaseClient->update('user_streaks', $updateData, ['user_id' => $user_id]);
                $_SESSION['achievement_granted'] = true;
            }
        }
    }
} catch (Exception $e) {
    error_log("Streak Page: Error with game profile achievement: " . $e->getMessage());
}

// Check for "Team Membership" achievement
try {
    $teamTask = $supabaseClient->select('streak_tasks', 'id, reward_points', ['name' => 'Team Membership'], null, 1);
    if (!empty($teamTask)) {
        $teamTaskId = $teamTask[0]['id'];
        $teamTaskPoints = $teamTask[0]['reward_points'];

        $teamCompletion = $supabaseClient->select('user_streak_tasks', 'id', [
            'user_id' => $user_id,
            'task_id' => $teamTaskId
        ]);

        if (empty($teamCompletion)) {
            $teamMembership = $supabaseClient->select('team_members', 'id', ['user_id' => $user_id, 'status' => 'active'], null, 1);
            if (!empty($teamMembership)) {
                $supabaseClient->insert('user_streak_tasks', [
                    'user_id' => $user_id,
                    'task_id' => $teamTaskId,
                    'points_earned' => $teamTaskPoints
                ]);

            $updateData = [
                'streak_points' => intval($current['streak_points']) + $teamTaskPoints,
                'total_earned_points' => intval($current['total_earned_points'] ?? $current['streak_points']) + $teamTaskPoints,
                'total_tasks_completed' => intval($current['total_tasks_completed']) + 1
            ];
            $supabaseClient->update('user_streaks', $updateData, ['user_id' => $user_id]);
                $_SESSION['achievement_granted'] = true;
            }
        }
    }
} catch (Exception $e) {
    error_log("Streak Page: Error with team membership achievement: " . $e->getMessage());
}

// Reload page once if an achievement was granted, then clear the flag
if (isset($_SESSION['achievement_granted'])) {
    unset($_SESSION['achievement_granted']);
    header("Location: {$_SERVER['REQUEST_URI']}");
    exit;
}

try {
    // Auto-complete the "Daily Login" task
    $dailyLoginTask = $supabaseClient->select('streak_tasks', 'id, reward_points', ['name' => 'Daily Login', 'is_daily' => true], null, 1);

    if (!empty($dailyLoginTask)) {
        $loginTaskId = $dailyLoginTask[0]['id'];
        $loginTaskPoints = $dailyLoginTask[0]['reward_points'];
        
        // Check if it's already completed today
        $today = date('Y-m-d');
        $completionCheck = $supabaseClient->select('user_streak_tasks', 'id', [
            'user_id' => $user_id,
            'task_id' => $loginTaskId,
            'completion_date' => ['gte', $today]
        ]);

        // If not completed, grant the points and mark as done
        if (empty($completionCheck)) {
            $supabaseClient->insert('user_streak_tasks', [
                'user_id' => $user_id,
                'task_id' => $loginTaskId,
                'points_earned' => $loginTaskPoints
            ]);

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
                // If last activity was more than 1 day ago, streak resets to 1
                
                $newLongestStreak = max(intval($current['longest_streak']), $newCurrentStreak);
                
                $updateData = [
                    'streak_points' => intval($current['streak_points']) + $loginTaskPoints,
                    'total_earned_points' => intval($current['total_earned_points'] ?? $current['streak_points']) + $loginTaskPoints,
                    'total_tasks_completed' => intval($current['total_tasks_completed']) + 1,
                    'current_streak' => $newCurrentStreak,
                    'longest_streak' => $newLongestStreak,
                    'last_activity_date' => date('Y-m-d H:i:s')
                ];
                $supabaseClient->update('user_streaks', $updateData, ['user_id' => $user_id]);
            }
            
            // Reload the page to show the updated status
            header("Location: {$_SERVER['REQUEST_URI']}");
            exit;
        }
    }
} catch (Exception $e) {
    // Log error but don't break the page
    error_log("Streak Page: Error with automatic daily login task: " . $e->getMessage());
}

// Get user's streak information
try {
    $streakInfo = $supabaseClient->select('user_streaks', 'current_streak, longest_streak, streak_points, total_tasks_completed', ['user_id' => $user_id]);
    $streakInfo = !empty($streakInfo) ? $streakInfo[0] : null;
} catch (Exception $e) {
    error_log("Error fetching streak info: " . $e->getMessage());
    $streakInfo = null;
}

// Synchronize points if there's a discrepancy
if ($streakInfo) {
    try {
        // Calculate actual total points from user_streak_tasks using Supabase API
        $allUserTasks = $supabaseClient->select('user_streak_tasks', 'points_earned', ['user_id' => $user_id]);
        $actualTotalPoints = 0;
        $actualTaskCount = count($allUserTasks);
        
        foreach ($allUserTasks as $task) {
            $actualTotalPoints += intval($task['points_earned']);
        }
        
        $currentPoints = intval($streakInfo['streak_points'] ?? 0);
        
        // If there's a discrepancy, fix it
        if ($actualTotalPoints !== $currentPoints) {
            $supabaseClient->update('user_streaks', [
                'streak_points' => $actualTotalPoints,
                'total_earned_points' => $actualTotalPoints,
                'total_tasks_completed' => $actualTaskCount
            ], ['user_id' => $user_id]);
            
            // Refresh streak info
            $streakInfo['streak_points'] = $actualTotalPoints;
            $streakInfo['total_tasks_completed'] = $actualTaskCount;
            $streakInfo['total_earned_points'] = $actualTotalPoints;
        }
    } catch (Exception $e) {
        error_log("Error synchronizing points: " . $e->getMessage());
    }
}

// Initialize streak info if not found
if (!$streakInfo) {
    $streakInfo = [
        'current_streak' => 0,
        'longest_streak' => 0,
        'streak_points' => 0,
        'total_tasks_completed' => 0
    ];
    
    // Initialize user_streaks record if it doesn't exist
    try {
        $supabaseClient->insert('user_streaks', [
            'user_id' => $user_id,
            'current_streak' => 0,
            'longest_streak' => 0,
            'streak_points' => 0,
            'total_tasks_completed' => 0
        ]);
    } catch (Exception $e) {
        error_log("Error initializing streak record: " . $e->getMessage());
    }
}

// Get daily tasks from streak_tasks table
try {
    $daily_tasks = $supabaseClient->select('streak_tasks', '*', ['is_daily' => true, 'is_active' => true]);
} catch (Exception $e) {
    error_log("Error fetching daily tasks: " . $e->getMessage());
    $daily_tasks = [];
}

// Get one-time tasks (achievements)
try {
    $onetime_tasks_raw = $supabaseClient->select('streak_tasks', '*', ['is_daily' => false, 'is_active' => true]);
    
    // Deduplicate achievements by name, keeping the one with the lowest ID
    $onetime_tasks = [];
    $achievementNames = [];
    foreach ($onetime_tasks_raw as $task) {
        if (!in_array($task['name'], $achievementNames)) {
            $onetime_tasks[] = $task;
            $achievementNames[] = $task['name'];
        }
    }
} catch (Exception $e) {
    error_log("Error fetching onetime tasks: " . $e->getMessage());
    $onetime_tasks = [];
}

// Get user's completed tasks for today
try {
    $today = date('Y-m-d');
    $today_completed = $supabaseClient->select('user_streak_tasks', '*', [
        'user_id' => $user_id,
        'completion_date' => ['gte', $today]
    ]);
    $today_tasks = ['completed_count' => count($today_completed)];
} catch (Exception $e) {
    error_log("Error fetching today's completed tasks: " . $e->getMessage());
    $today_tasks = ['completed_count' => 0];
}

// Mark tasks as completed if user has completed them
foreach ($daily_tasks as &$task) {
    $task['completed'] = false;
    foreach ($today_completed as $completed) {
        if ($completed['task_id'] == $task['id']) {
            $task['completed'] = true;
            break;
        }
    }
}

// Auto-grant eligible one-time achievements
try {
    foreach ($onetime_tasks as $task) {
        // Check if already completed
        $alreadyCompleted = $supabaseClient->select('user_streak_tasks', 'id', [
            'user_id' => $user_id,
            'task_id' => $task['id']
        ]);
        
        if (empty($alreadyCompleted)) {
            // Check if user meets requirements
            $meetsRequirements = checkAchievementRequirements($supabaseClient, $user_id, $task['name']);
            if ($meetsRequirements) {
                // Auto-grant the achievement
                $supabaseClient->insert('user_streak_tasks', [
                    'user_id' => $user_id,
                    'task_id' => $task['id'],
                    'points_earned' => $task['reward_points']
                ]);
                
                // Update user streak points
                $currentStreak = $supabaseClient->select('user_streaks', '*', ['user_id' => $user_id]);
                if (!empty($currentStreak)) {
                    $current = $currentStreak[0];
                    $supabaseClient->update('user_streaks', [
                        'streak_points' => intval($current['streak_points']) + $task['reward_points'],
                        'total_earned_points' => intval($current['total_earned_points'] ?? $current['streak_points']) + $task['reward_points'],
                        'total_tasks_completed' => intval($current['total_tasks_completed']) + 1
                    ], ['user_id' => $user_id]);
                }
                
                $_SESSION['achievement_granted'] = true;
            }
        }
    }
} catch (Exception $e) {
    error_log("Error auto-granting achievements: " . $e->getMessage());
}

foreach ($onetime_tasks as &$task) {
    $task['completed'] = false;
    try {
        $completed = $supabaseClient->select('user_streak_tasks', '*', [
            'user_id' => $user_id,
            'task_id' => $task['id']
        ]);
        if (!empty($completed)) {
            $task['completed'] = true;
        }
    } catch (Exception $e) {
        error_log("Error checking task completion: " . $e->getMessage());
    }
}

// Get last 7 days activity
try {
    $sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));
    $allTasks = $supabaseClient->select('user_streak_tasks', 'completion_date, points_earned', 
        ['user_id' => $user_id, 'completion_date' => ['gte', $sevenDaysAgo]],
        'completion_date.desc'
    );
    
    // Group tasks by date manually
    $streak_history = [];
    foreach ($allTasks as $task) {
        $date = date('Y-m-d', strtotime($task['completion_date']));
        if (!isset($streak_history[$date])) {
            $streak_history[$date] = [
                'date' => $date,
                'tasks_completed' => 0,
                'points_earned' => 0
            ];
        }
        $streak_history[$date]['tasks_completed']++;
        $streak_history[$date]['points_earned'] += intval($task['points_earned']);
    }
    
    // Convert to indexed array and sort by date descending
    $streak_history = array_values($streak_history);
    usort($streak_history, function($a, $b) {
        return strcmp($b['date'], $a['date']);
    });
} catch (Exception $e) {
    error_log("Error fetching streak history: " . $e->getMessage());
    $streak_history = [];
}

// Get milestones
try {
    $milestones = $supabaseClient->select('streak_milestones', '*', ['is_active' => true], 'points_required.asc');
    $next_milestone = null;
    foreach ($milestones as $milestone) {
        if (($streakInfo['streak_points'] ?? 0) < $milestone['points_required']) {
            $next_milestone = $milestone;
            break;
        }
    }
} catch (Exception $e) {
    error_log("Error fetching milestones: " . $e->getMessage());
    $next_milestone = null;
}

// Get user achievements
try {
    $achievements = $supabaseClient->select('user_streak_milestones usm', 
        'usm.*, sm.name, sm.description, sm.reward_points, usm.achieved_at',
        ['usm.user_id' => $user_id],
        'usm.achieved_at.desc',
        null,
        ['streak_milestones sm' => 'usm.milestone_id = sm.id']
    );
} catch (Exception $e) {
    error_log("Error fetching achievements: " . $e->getMessage());
    $achievements = [];
}

// Function to check task completion status
function checkTaskCompletion($user_id, $task_name) {
    // This would check if the user has completed specific actions
    // For now, return false as placeholder
    return false;
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


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Streak Dashboard</title>
    <link rel="stylesheet" href="../assets/css/root.css">
    <link rel="stylesheet" href="../assets/css/dashboard/streak.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
    <a href="index.php" class="back-button">
        <ion-icon name="arrow-back-outline"></ion-icon>
        Back to Dashboard
    </a>

    <div class="main-content">
        
        <div class="streak-stats">
            <div class="stat-card">
                <div class="stat-number"><?php echo $streakInfo['current_streak'] ?? 0; ?></div>
                <div class="stat-label">Current Streak</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $today_tasks['completed_count'] ?? 0; ?></div>
                <div class="stat-label">Tasks Completed Today</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $streakInfo['streak_points'] ?? 0; ?></div>
                <div class="stat-label">Available Points</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo floor(($streakInfo['streak_points'] ?? 0) / 10); ?></div>
                <div class="stat-label">Convertible Coins</div>
                <button onclick="convertPoints()" class="convert-btn">
                    <ion-icon name="swap-horizontal-outline"></ion-icon>
                    Convert to Coins
                </button>
            </div>
        </div>

        <?php if ($next_milestone): ?>
        <div class="milestone-progress">
            <h3>Next Milestone: <?php echo htmlspecialchars($next_milestone['name']); ?></h3>
            <div class="progress-bar">
                <div class="progress" style="width: <?php 
                    echo min(100, (($streakInfo['streak_points'] ?? 0) / $next_milestone['points_required']) * 100);
                ?>%"></div>
            </div>
            <div class="milestone-reward">
                <ion-icon name="trophy-outline"></ion-icon>
                Reward: <?php echo $next_milestone['reward_points']; ?> Points
            </div>
            <div class="milestone-description">
                <?php echo htmlspecialchars($next_milestone['description']); ?>
            </div>
        </div>
        <?php endif; ?>

        <h2>Daily Tasks</h2>
        <div class="tasks-grid">
            <?php foreach ($daily_tasks as $task): ?>
            <div class="task-card <?php echo $task['completed'] ? 'completed' : ''; ?>">
                <div class="task-header">
                    <div class="task-name"><?php echo htmlspecialchars($task['name']); ?></div>
                    <div class="task-points"><?php echo $task['reward_points']; ?> Points</div>
                </div>
                <div class="task-description">
                    <?php echo htmlspecialchars($task['description']); ?>
                </div>
                <div class="task-status">
                    <?php if ($task['completed']): ?>
                        <div class="status-icon completed">
                            <ion-icon name="checkmark-circle"></ion-icon>
                            <span>Task Completed! +<?php echo $task['reward_points']; ?> points earned</span>
                        </div>
                    <?php else: ?>
                        <button onclick="completeTask(<?php echo $task['id']; ?>)" class="complete-btn">
                            <ion-icon name="arrow-forward-circle-outline"></ion-icon>
                            Complete Task
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($onetime_tasks)): ?>
        <h2>One-Time Achievements</h2>
        <div class="tasks-grid">
            <?php foreach ($onetime_tasks as $task): ?>
            <div class="task-card <?php echo $task['completed'] ? 'completed' : ''; ?>">
                <div class="task-header">
                    <div class="task-name"><?php echo htmlspecialchars($task['name']); ?></div>
                    <div class="task-points"><?php echo $task['reward_points']; ?> Points</div>
                </div>
                <div class="task-description">
                    <?php echo htmlspecialchars($task['description']); ?>
                </div>
                <div class="task-status">
                    <?php if ($task['completed']): ?>
                        <div class="status-icon completed">
                            <ion-icon name="checkmark-circle"></ion-icon>
                            <span>Achievement Unlocked! +<?php echo $task['reward_points']; ?> points earned</span>
                        </div>
                    <?php else: ?>
                        <button onclick="completeTask(<?php echo $task['id']; ?>)" class="complete-btn">
                            <ion-icon name="trophy-outline"></ion-icon>
                            Claim Achievement
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <h2>Last 7 Days Activity</h2>
        <div class="history-section">
            <table class="history-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Tasks Completed</th>
                        <th>Points Earned</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // Get the last 7 days, including days with no activity
                    $last7Days = array();
                    for ($i = 0; $i < 7; $i++) {
                        $date = date('Y-m-d', strtotime("-$i days"));
                        $last7Days[$date] = array(
                            'date' => $date,
                            'tasks_completed' => 0,
                            'points_earned' => 0
                        );
                    }
                    
                    // Fill in the actual data
                    foreach ($streak_history as $day) {
                        if (isset($last7Days[$day['date']])) {
                            $last7Days[$day['date']] = $day;
                        }
                    }
                    
                    foreach ($last7Days as $day): 
                    ?>
                    <tr class="<?php echo $day['tasks_completed'] > 0 ? 'active-day' : ''; ?>">
                        <td><?php echo date('D, M j', strtotime($day['date'])); ?></td>
                        <td><?php echo $day['tasks_completed']; ?></td>
                        <td><?php echo $day['points_earned'] ?? 0; ?></td>
                        <td>
                            <?php if ($day['tasks_completed'] > 0): ?>
                                <span class="status-badge success">
                                    <ion-icon name="checkmark-circle"></ion-icon> Active
                                </span>
                            <?php else: ?>
                                <span class="status-badge inactive">
                                    <ion-icon name="close-circle"></ion-icon> Inactive
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <h2>Milestone Achievements</h2>
        <div class="achievements-section">
            <?php if (empty($achievements)): ?>
                <div class="no-achievements">
                    <ion-icon name="trophy-outline" class="large-icon"></ion-icon>
                    <p>Complete tasks and earn points to unlock achievements!</p>
                    <div class="upcoming-milestones">
                        <h3>Upcoming Milestones:</h3>
                        <ul>
                            <li>
                                <div class="milestone-name">Bronze Streak</div>
                                <div class="milestone-points">100 points</div>
                                <div class="milestone-reward">Earn exclusive profile badge</div>
                            </li>
                            <li>
                                <div class="milestone-name">Silver Streak</div>
                                <div class="milestone-points">250 points</div>
                                <div class="milestone-reward">Get 50 bonus coins</div>
                            </li>
                            <li>
                                <div class="milestone-name">Gold Streak</div>
                                <div class="milestone-points">500 points</div>
                                <div class="milestone-reward">Unlock special tournament access</div>
                            </li>
                            <li>
                                <div class="milestone-name">Diamond Streak</div>
                                <div class="milestone-points">1000 points</div>
                                <div class="milestone-reward">Earn premium membership benefits</div>
                            </li>
                        </ul>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($achievements as $achievement): ?>
                <div class="achievement-card">
                    <div class="achievement-icon">
                        <?php
                        $iconName = 'trophy';
                        switch($achievement['name']) {
                            case 'Bronze Streak':
                                $iconName = 'medal';
                                break;
                            case 'Silver Streak':
                                $iconName = 'ribbon';
                                break;
                            case 'Gold Streak':
                                $iconName = 'star';
                                break;
                            case 'Diamond Streak':
                                $iconName = 'diamond';
                                break;
                        }
                        ?>
                        <ion-icon name="<?php echo $iconName; ?>-outline"></ion-icon>
                    </div>
                    <div class="achievement-info">
                        <div class="achievement-name">
                            <?php echo htmlspecialchars($achievement['name']); ?>
                        </div>
                        <div class="achievement-description">
                            <?php echo htmlspecialchars($achievement['description']); ?>
                        </div>
                        <div class="achievement-date">
                            Achieved on <?php echo date('M j, Y', strtotime($achievement['achieved_at'])); ?>
                        </div>
                    </div>
                    <div class="achievement-points">
                        +<?php echo $achievement['reward_points']; ?> Points
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
    <script>
        function convertPoints() {
            document.getElementById('conversion-modal').style.display = 'flex';
            updatePointsNeeded();
        }

        function closeModal() {
            document.getElementById('conversion-modal').style.display = 'none';
        }

        function updatePointsNeeded() {
            const coinsInput = document.getElementById('coins-to-convert');
            const pointsNeeded = document.getElementById('points-needed');
            pointsNeeded.textContent = coinsInput.value * 10;
        }

        function incrementCoins() {
            const input = document.getElementById('coins-to-convert');
            const maxCoins = parseInt(input.getAttribute('max'));
            const currentValue = parseInt(input.value);
            if (currentValue < maxCoins) {
                input.value = currentValue + 1;
                updatePointsNeeded();
            }
        }

        function decrementCoins() {
            const input = document.getElementById('coins-to-convert');
            const currentValue = parseInt(input.value);
            if (currentValue > 1) {
                input.value = currentValue - 1;
                updatePointsNeeded();
            }
        }

        document.getElementById('coins-to-convert').addEventListener('input', updatePointsNeeded);

        function confirmConversion() {
            const coinsToConvert = document.getElementById('coins-to-convert').value;
            
            fetch('streak_convert.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    coins: parseInt(coinsToConvert)
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert(data.message || 'Error converting points');
                }
                closeModal();
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error converting points');
                closeModal();
            });
        }

        function completeTask(taskId) {
            if (!confirm('Are you sure you want to complete this task?')) {
                return;
            }

            fetch('complete_task.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    task_id: taskId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message);
                    location.reload();
                } else {
                    alert(data.message || 'Error completing task');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error completing task');
            });
        }

        // Add auto-refresh functionality
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (isset($_SESSION['task_completed'])): ?>
                // Clear the flag
                <?php unset($_SESSION['task_completed']); ?>
                // Refresh the page after 2 seconds
                setTimeout(function() {
                    location.reload();
                }, 2000);
            <?php endif; ?>

            // Check for new task completions every 30 seconds
            setInterval(function() {
                fetch('check_tasks.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.new_completion) {
                            location.reload();
                        }
                    })
                    .catch(error => console.error('Error checking tasks:', error));
            }, 30000);
        });
    </script>
</body>
</html>

