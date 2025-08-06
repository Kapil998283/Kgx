<?php
// CRITICAL: Suppress ALL error output to prevent corrupting HTML title
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Set error handler to suppress all output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Users View Error: $errstr in $errfile on line $errline");
    return true; // Don't execute PHP internal error handler
});

// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once dirname(__DIR__) . '/admin_secure_config.php';

// Load admin configuration with error handling
try {
    $adminConfig = loadAdminConfig('admin_config.php');
    if (!$adminConfig || !is_array($adminConfig)) {
        $adminConfig = ['system' => ['name' => 'KGX Admin']];
    }
} catch (Exception $e) {
    error_log("Admin config error: " . $e->getMessage());
    $adminConfig = ['system' => ['name' => 'KGX Admin']];
}

// Use the new admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

// Admin is automatically authenticated by admin-auth.php

// Initialize Supabase connection
$supabase = getSupabaseConnection();

// Check if user ID is provided
if(!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: index.php");
    exit();
}
$user_id = (int)$_GET['id'];



// Get user data using Supabase
try {
    $users_data = $supabase->select('users', '*', ['id' => $user_id]);
    $user = !empty($users_data) ? $users_data[0] : null;
    
    if (!$user) {
        header("Location: index.php");
        exit();
    }
    
    // Get user coins
    $coins_data = $supabase->select('user_coins', 'coins', ['user_id' => $user['id']]);
    $user['coins'] = !empty($coins_data) ? $coins_data[0]['coins'] : 0;
    
    // Get user tickets  
    $tickets_data = $supabase->select('user_tickets', 'tickets', ['user_id' => $user['id']]);
    $user['tickets'] = !empty($tickets_data) ? $tickets_data[0]['tickets'] : 0;
    
} catch (Exception $e) {
    error_log("User fetch error: " . $e->getMessage());
    header("Location: index.php");
    exit();
}

// Profile image
$profile_image = $user['profile_image'] ?? null;
if (!$profile_image) {
    try {
        $default_images = $supabase->select('profile_images', 'image_path', ['is_default' => true, 'is_active' => true]);
        $profile_image = !empty($default_images) ? $default_images[0]['image_path'] : '../assets/images/profile/profile3.png';
    } catch (Exception $e) {
        error_log("Profile image fetch error: " . $e->getMessage());
        $profile_image = '../assets/images/profile/profile3.png';
    }
}

// Main game profile
try {
    $main_games = $supabase->select('user_games', '*', ['user_id' => $user_id, 'is_primary' => true]);
    $main_game = !empty($main_games) ? $main_games[0] : null;
    
    if (!$main_game) {
        $all_user_games = $supabase->select('user_games', '*', ['user_id' => $user_id], 'created_at.desc');
        $main_game = !empty($all_user_games) ? $all_user_games[0] : null;
    }
} catch (Exception $e) {
    error_log("Main game fetch error: " . $e->getMessage());
    $main_game = null;
}

// All game profiles
try {
    $all_games = $supabase->select('user_games', '*', ['user_id' => $user_id]);
} catch (Exception $e) {
    error_log("All games fetch error: " . $e->getMessage());
    $all_games = [];
}

// Team info (captain/member) - simplified for now
try {
    $team_members = $supabase->select('team_members', '*', ['user_id' => $user_id, 'status' => 'active']);
    $teams = [];
    foreach ($team_members as $member) {
        $team_data = $supabase->select('teams', '*', ['id' => $member['team_id']]);
        if (!empty($team_data)) {
            $team = $team_data[0];
            $team['role'] = $member['role'];
            $teams[] = $team;
        }
    }
} catch (Exception $e) {
    error_log("Teams fetch error: " . $e->getMessage());
    $teams = [];
}

// Streaks
try {
    $streaks = $supabase->select('user_streaks', '*', ['user_id' => $user_id]);
    $streak = !empty($streaks) ? $streaks[0] : null;
} catch (Exception $e) {
    error_log("Streaks fetch error: " . $e->getMessage());
    $streak = null;
}

// Matches summary
try {
    $match_stats_data = $supabase->select('user_match_stats', '*', ['user_id' => $user_id]);
    $match_stats = !empty($match_stats_data) ? $match_stats_data[0] : ['matches_count' => 0, 'total_kills' => 0];
    // Ensure proper field names
    if (isset($match_stats['total_matches_played'])) {
        $match_stats['matches_count'] = $match_stats['total_matches_played'];
    }
} catch (Exception $e) {
    error_log("Match stats fetch error: " . $e->getMessage());
    $match_stats = ['matches_count' => 0, 'total_kills' => 0];
}

// Recent matches - simplified for now
try {
    $match_participants = $supabase->select('match_participants', '*', ['user_id' => $user_id]);
    $recent_matches = [];
    // This is a complex join - for now just get basic match data
    // In a real implementation, you'd need to handle the joins properly
    $recent_matches = [];
} catch (Exception $e) {
    error_log("Recent matches fetch error: " . $e->getMessage());
    $recent_matches = [];
}

// Tournament history - simplified for now
try {
    $tournament_history = $supabase->select('tournament_player_history', '*', ['user_id' => $user_id], 'registration_date.desc');
    $tournaments = [];
    // This is a complex join - for now just get basic tournament data
    // In a real implementation, you'd need to handle the joins properly
    $tournaments = [];
} catch (Exception $e) {
    error_log("Tournament history fetch error: " . $e->getMessage());
    $tournaments = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Player Profile - <?php echo htmlspecialchars($user['username']); ?></title>
    <link rel="stylesheet" href="../assets/css/root.css">
    <link rel="stylesheet" href="../assets/css/user/view.css">
</head>
<body>
    <div class="users-view">
        <!-- Header Section -->
        <div class="view-header">
            <div>
                <h1 class="view-title">Player Profile</h1>
                <p class="view-subtitle"><?php echo htmlspecialchars($user['username']); ?> - Gaming Stats</p>
            </div>
            <button class="btn-gaming" onclick="window.location.href='index.php'">← Back to Players</button>
        </div>
        <!-- Player Avatar & Main Info -->
        <div class="player-avatar-section">
            <div class="player-avatar-large">
                <?php echo strtoupper(substr($user['username'] ?? 'U', 0, 1)); ?>
            </div>
            <div class="player-main-info">
                <h2 class="player-username"><?php echo htmlspecialchars($user['username']); ?></h2>
                <p class="player-email"><?php echo htmlspecialchars($user['email']); ?></p>
                <div class="player-status">
                    ✅ Active Player
                </div>
            </div>
        </div>

        <!-- Player Stats Grid -->
        <div class="player-stats">
            <div class="stat-card">
                <div class="stat-icon">💰</div>
                <h3 class="stat-number"><?php echo number_format($user['coins']); ?></h3>
                <p class="stat-label">Coins Balance</p>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🎫</div>
                <h3 class="stat-number"><?php echo number_format($user['tickets']); ?></h3>
                <p class="stat-label">Tickets Balance</p>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⚡</div>
                <h3 class="stat-number"><?php echo $streak['streak_points'] ?? 0; ?></h3>
                <p class="stat-label">Streak Points</p>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🔥</div>
                <h3 class="stat-number"><?php echo $streak['current_streak'] ?? 0; ?></h3>
                <p class="stat-label">Current Streak</p>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🎮</div>
                <h3 class="stat-number"><?php echo $match_stats['matches_count'] ?? 0; ?></h3>
                <p class="stat-label">Matches Played</p>
            </div>
            <div class="stat-card">
                <div class="stat-icon">💀</div>
                <h3 class="stat-number"><?php echo $match_stats['total_kills'] ?? 0; ?></h3>
                <p class="stat-label">Total Kills</p>
            </div>
        </div>
        <!-- Player Information Section -->
        <div class="gaming-info-section">
            <div class="section-header">
                <h2 class="section-title">👤 Player Information</h2>
            </div>
            <div class="section-content">
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Player ID</span>
                        <span class="info-value"><?php echo $user['id']; ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Full Name</span>
                        <span class="info-value"><?php echo htmlspecialchars($user['full_name'] ?? 'Not Set'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Phone Number</span>
                        <span class="info-value"><?php echo htmlspecialchars($user['phone'] ?? 'Not Set'); ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Join Date</span>
                        <span class="info-value"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Gaming Profile Section -->
        <div class="gaming-info-section">
            <div class="section-header">
                <h2 class="section-title">🎮 Gaming Profile</h2>
            </div>
            <div class="section-content">
                <div class="info-grid">
                    <?php if ($main_game): ?>
                        <div class="info-item">
                            <span class="info-label">Main Game</span>
                            <span class="info-value"><?php echo htmlspecialchars($main_game['game_name']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Game Username</span>
                            <span class="info-value"><?php echo htmlspecialchars($main_game['game_username']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Game UID</span>
                            <span class="info-value"><?php echo htmlspecialchars($main_game['game_uid']); ?></span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Game Level</span>
                            <span class="info-value"><?php echo htmlspecialchars($main_game['game_level']); ?></span>
                        </div>
                    <?php else: ?>
                        <div class="info-item" style="grid-column: 1 / -1;">
                            <span class="info-label">Main Game</span>
                            <span class="info-value no-data">No main game profile set</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Team Memberships Section -->
        <div class="gaming-info-section">
            <div class="section-header">
                <h2 class="section-title">👥 Team Memberships</h2>
            </div>
            <div class="section-content">
                <div class="info-grid">
                    <?php if ($teams): ?>
                        <?php foreach ($teams as $team): ?>
                            <div class="info-item">
                                <span class="info-label"><?php echo htmlspecialchars($team['name']); ?> (<?php echo ucfirst($team['role']); ?>)</span>
                                <span class="info-value">Score: <?php echo number_format($team['total_score']); ?> | Members: <?php echo $team['max_members']; ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="info-item" style="grid-column: 1 / -1;">
                            <span class="info-label">Teams</span>
                            <span class="info-value no-data">Not a member of any team</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <!-- Gaming Activity Section -->
        <div class="gaming-info-section">
            <div class="section-header">
                <h2 class="section-title">🏆 Gaming Activity</h2>
            </div>
            <div class="section-content">
                <div class="info-grid">
                    <?php if (empty($recent_matches) && empty($tournaments)): ?>
                        <div class="info-item">
                            <span class="info-label">Match History</span>
                            <span class="info-value no-data">No match history available</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Tournament History</span>
                            <span class="info-value no-data">No tournament participation</span>
                        </div>
                    <?php else: ?>
                        <div class="info-item">
                            <span class="info-label">Recent Activity</span>
                            <span class="info-value">Player has gaming history data available</span>
                        </div>
                    <?php endif; ?>
                    <div class="info-item">
                        <span class="info-label">Account Status</span>
                        <span class="info-value" style="color: var(--primary-green); font-weight: var(--font-weight-bold);">✅ Active Player</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="action-buttons-container">
            <button class="btn-secondary" onclick="window.location.href='index.php'">
                📋 Back to List
            </button>
            <button class="btn-gaming" onclick="window.location.href='edit.php?id=<?php echo $user['id']; ?>'">
                ✏️ Edit Player
            </button>
        </div>
    </div>
</body>
</html>

