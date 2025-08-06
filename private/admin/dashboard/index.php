<?php
// CRITICAL: Suppress ALL error output to prevent corrupting HTML title
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Set error handler to suppress all output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Admin Dashboard Error: $errstr in $errfile on line $errline");
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

try {
    // Get total users count - try different possible table structures
    $users = [];
    try {
        // First try 'users' table with role filter
        $users = $supabase->select('users', 'id', ['role' => 'user']);
    } catch (Exception $e) {
        try {
            // Try 'users' table without role filter
            $users = $supabase->select('users', 'id');
        } catch (Exception $e2) {
            try {
                // Try 'auth.users' if using Supabase auth table directly
                $users = $supabase->select('auth.users', 'id');
            } catch (Exception $e3) {
                error_log("Could not fetch users from any table: users, auth.users");
                $users = [];
            }
        }
    }
    $total_users = count($users);
    
    // Get total coins in circulation
    $total_coins = 0;
    try {
        $coins = $supabase->select('user_coins', 'coins');
        $total_coins = array_sum(array_column($coins, 'coins'));
    } catch (Exception $e) {
        error_log("Could not fetch coins: " . $e->getMessage());
    }
    
    // Get total tickets in circulation
    $total_tickets = 0;
    try {
        $tickets = $supabase->select('user_tickets', 'tickets');
        $total_tickets = array_sum(array_column($tickets, 'tickets'));
    } catch (Exception $e) {
        error_log("Could not fetch tickets: " . $e->getMessage());
    }
    
    // Get total teams count
    $total_teams = 0;
    try {
        $teams = $supabase->select('teams', 'id');
        $total_teams = count($teams);
    } catch (Exception $e) {
        error_log("Could not fetch teams: " . $e->getMessage());
    }
    
    // Get recent users
    $recent_users = [];
    try {
        // Try different approaches to get recent users
        try {
            $recent_users = $supabase->select('users', 'id, username, email, created_at', ['role' => 'user'], 'created_at.desc', 10);
        } catch (Exception $e) {
            try {
                $recent_users = $supabase->select('users', 'id, username, email, created_at', [], 'created_at.desc', 10);
            } catch (Exception $e2) {
                try {
                    $recent_users = $supabase->select('users', '*', [], 'created_at.desc', 10);
                } catch (Exception $e3) {
                    error_log("Could not fetch recent users: " . $e3->getMessage());
                    $recent_users = [];
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching recent users: " . $e->getMessage());
        $recent_users = [];
    }
    
} catch (Exception $e) {
    error_log("Dashboard data loading error: " . $e->getMessage());
    $total_users = 0;
    $total_coins = 0;
    $total_tickets = 0;
    $total_teams = 0;
    $recent_users = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php 
        $title = 'KGX Admin';
        if (is_array($adminConfig) && isset($adminConfig['system']['name']) && !empty($adminConfig['system']['name'])) {
            $title = $adminConfig['system']['name'];
        }
        echo htmlspecialchars($title ?? 'KGX Admin');
    ?> - Dashboard</title>
    
    <!-- Bootstrap & Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    
    <!-- Gaming Theme CSS -->
    <link rel="stylesheet" href="../assets/css/dashboard/gaming-admin.css">
    
    <?php if (ADMIN_DEBUG): ?>
        <meta name="admin-debug" content="true">
        <meta name="admin-environment" content="<?php echo ADMIN_ENVIRONMENT; ?>">
    <?php endif; ?>
</head>
<body>
    <div class="container-fluid p-0">
        <div class="row g-0">
            <!-- Include Sidebar -->
            <?php include '../includes/sidebar.php'; ?>

            <!-- Main Content -->
            <main class="main-content">
                <!-- Mobile Toggle Button -->
                <button class="sidebar-toggle d-lg-none" id="sidebarToggle">
                    <i class="bi bi-list"></i>
                </button>
                
                <!-- Dashboard Header -->
                <div class="dashboard-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h1>🎮 Gaming Dashboard</h1>
                            <p class="welcome-text">Welcome back, <?php 
                                $currentAdmin = getCurrentAdmin();
                                echo htmlspecialchars($currentAdmin ? $currentAdmin['full_name'] : 'Administrator'); 
                            ?>! Ready to manage your gaming platform?</p>
                        </div>
                        <button class="btn export-btn">⚡ Export Data</button>
                    </div>
                </div>

                <!-- Stats Cards -->
                <div class="row">
                    <div class="col-xl-3 col-md-6">
                        <div class="stats-card blue pulse-animation">
                            <i class="bi bi-people stats-icon"></i>
                            <div class="stats-number"><?php echo number_format($total_users); ?></div>
                            <div class="stats-label">Total Gamers</div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="stats-card purple">
                            <i class="bi bi-coin stats-icon"></i>
                            <div class="stats-number"><?php echo number_format($total_coins); ?></div>
                            <div class="stats-label">Total Coins</div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="stats-card green">
                            <i class="bi bi-ticket-perforated stats-icon"></i>
                            <div class="stats-number"><?php echo number_format($total_tickets); ?></div>
                            <div class="stats-label">Total Tickets</div>
                        </div>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <div class="stats-card orange">
                            <i class="bi bi-people-fill stats-icon"></i>
                            <div class="stats-number"><?php echo number_format($total_teams); ?></div>
                            <div class="stats-label">Total Teams</div>
                        </div>
                    </div>
                </div>

                <!-- Recent Users Table -->
                <div class="data-table gamer-table">
                    <div class="card-header">
                        <div class="table-header-content">
                            <div class="table-title">
                                <i class="bi bi-controller me-2"></i>
                                <span class="title-text">🎯 Recent Gamers</span>
                                <span class="gamer-count" id="gamerCount">(<?php echo count($recent_users); ?>)</span>
                            </div>
                            <div class="enhanced-search-container">
                                <div class="search-input-wrapper">
                                    <i class="bi bi-search search-icon"></i>
                                    <input type="text" class="search-input" id="gamerSearch" placeholder="Search gamers by name, email, or ID..." autocomplete="off">
                                    <button class="search-clear" id="clearSearch" title="Clear search">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </div>
                                <div class="search-results-info" id="searchResults"></div>
                            </div>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th><i class="bi bi-hash"></i> ID</th>
                                        <th><i class="bi bi-person"></i> Gamer Tag</th>
                                        <th><i class="bi bi-envelope"></i> Contact</th>
                                        <th><i class="bi bi-calendar"></i> Joined</th>
                                        <th><i class="bi bi-gear"></i> Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($recent_users)): ?>
                                        <?php foreach($recent_users as $user): ?>
                                        <tr class="gamer-row" data-id="<?php echo htmlspecialchars($user['id'] ?? ''); ?>" data-username="<?php echo htmlspecialchars($user['username'] ?? ''); ?>" data-email="<?php echo htmlspecialchars($user['email'] ?? ''); ?>">
                                            <td><span class="gamer-id">#<?php echo htmlspecialchars($user['id'] ?? 'N/A'); ?></span></td>
                                            <td>
                                                <span class="gamer-badge">🎮</span>
                                                <span class="gamer-name"><?php echo htmlspecialchars($user['username'] ?? $user['email'] ?? 'Unknown User'); ?></span>
                                            </td>
                                            <td><span class="gamer-email"><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></span></td>
                                            <td><?php 
                                                if (isset($user['created_at']) && $user['created_at']) {
                                                    echo date('M d, Y', strtotime($user['created_at']));
                                                } else {
                                                    echo 'N/A';
                                                }
                                            ?></td>
                                            <td>
                                                <a href="../users/view.php?id=<?php echo htmlspecialchars($user['id'] ?? ''); ?>" class="btn btn-sm btn-info action-btn me-1">
                                                    <i class="bi bi-eye"></i> View
                                                </a>
                                                <a href="../users/edit.php?id=<?php echo htmlspecialchars($user['id'] ?? ''); ?>" class="btn btn-sm btn-warning action-btn">
                                                    <i class="bi bi-pencil"></i> Edit
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-4">
                                                <i class="bi bi-emoji-neutral display-4 text-muted"></i>
                                                <p class="text-muted mt-2">No players found yet. Time to build your gaming community! 🚀</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Mobile Sidebar Overlay -->
    <div class="sidebar-overlay d-lg-none" id="sidebarOverlay"></div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Dashboard JavaScript Files -->
    <script src="../js/dashboard-animations.js"></script>
    <script src="../js/gamer-search.js"></script>
    <script src="../js/sidebar.js"></script>
</body>
</html>
