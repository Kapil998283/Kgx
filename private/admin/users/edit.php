<?php
// Set error handling to prevent display errors
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Set error handler to log errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Edit User Error: $errstr in $errfile on line $errline");
    return true;
});

session_start();

// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once dirname(__DIR__) . '/admin_secure_config.php';

// Use the new admin authentication system (this loads the Supabase connection)
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

// Initialize Supabase connection
try {
    $supabase = getSupabaseConnection();
    if (!$supabase) {
        throw new Exception('Failed to initialize Supabase connection');
    }
} catch (Exception $e) {
    error_log("Supabase connection error: " . $e->getMessage());
    die("Database connection error. Please try again later.");
}

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

// Admin is automatically authenticated by admin-auth.php (already included above)

// Check if user ID is provided
if(!isset($_GET['id'])) {
    header("Location: index.php");
    exit();
}

$user_id = (int)$_GET['id'];
$success = '';
$error = '';

// Supabase is already initialized, use it directly.

// Handle form submission
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    $coins = (int)$_POST['coins'];
    $tickets = (int)$_POST['tickets'];
    
    try {
        // Use the exact same pattern as tournaments - Supabase API with service role
        // Check if user_coins record exists
        $existing_coins = $supabase->select('user_coins', '*', ['user_id' => $user_id]);
        if (!empty($existing_coins)) {
            // Update existing record - only update coins field
            $supabase->update('user_coins', ['coins' => $coins], ['user_id' => $user_id]);
        } else {
            // Insert new record
            $supabase->insert('user_coins', ['user_id' => $user_id, 'coins' => $coins]);
        }
        
        // Check if user_tickets record exists
        $existing_tickets = $supabase->select('user_tickets', '*', ['user_id' => $user_id]);
        if (!empty($existing_tickets)) {
            // Update existing record - only update tickets field
            $supabase->update('user_tickets', ['tickets' => $tickets], ['user_id' => $user_id]);
        } else {
            // Insert new record
            $supabase->insert('user_tickets', ['user_id' => $user_id, 'tickets' => $tickets]);
        }
        
        $success = "User resources updated successfully";
    } catch (Exception $e) {
        error_log("Edit user error: " . $e->getMessage());
        $error = "Error updating user resources: " . $e->getMessage();
    }
}

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/root.css">
    <link rel="stylesheet" href="../assets/css/user/edit.css">
</head>
<body>
    <div class="users-edit">
        <!-- Header Section -->
        <div class="edit-header">
            <div>
                <h1 class="edit-title">Edit Player</h1>
                <p class="edit-subtitle">Update <?php echo htmlspecialchars($user['username']); ?>'s gaming resources</p>
            </div>
            <button class="btn-gaming" onclick="window.location.href='index.php'">← Back to Players</button>
        </div>

        <!-- Success/Error Messages -->
        <?php if($success): ?>
            <div class="alert-success">
                ✅ <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <?php if($error): ?>
            <div class="alert-error">
                ⚠️ <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <!-- Player Info Card -->
        <div class="player-info-card">
            <div class="player-info-content">
                <div class="player-avatar-edit">
                    <?php echo strtoupper(substr($user['username'] ?? 'U', 0, 1)); ?>
                </div>
                <div class="player-info-details">
                    <h3><?php echo htmlspecialchars($user['username']); ?></h3>
                    <p><?php echo htmlspecialchars($user['email']); ?></p>
                </div>
            </div>
        </div>

        <!-- Current Stats Display -->
        <div class="current-stats">
            <div class="current-stat">
                <h4 class="current-stat-value"><?php echo number_format($user['coins']); ?></h4>
                <p class="current-stat-label">💰 Current Coins</p>
            </div>
            <div class="current-stat">
                <h4 class="current-stat-value"><?php echo number_format($user['tickets']); ?></h4>
                <p class="current-stat-label">🎫 Current Tickets</p>
            </div>
            <div class="current-stat">
                <h4 class="current-stat-value"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></h4>
                <p class="current-stat-label">📅 Member Since</p>
            </div>
        </div>

        <!-- Gaming Form Container -->
        <div class="gaming-form-container">
            <div class="form-content">
                <form method="POST" action="">
                    <!-- Player Information Section -->
                    <div class="form-section">
                        <h2 class="form-section-title">👤 Player Information</h2>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">Username</label>
                                <input type="text" class="gaming-input" value="<?php echo htmlspecialchars($user['username']); ?>" readonly>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Email Address</label>
                                <input type="email" class="gaming-input" value="<?php echo htmlspecialchars($user['email']); ?>" readonly>
                            </div>
                            <div class="form-group form-grid-full">
                                <label class="form-label">Registration Date</label>
                                <input type="text" class="gaming-input" value="<?php echo date('M d, Y', strtotime($user['created_at'])); ?>" readonly>
                            </div>
                        </div>
                    </div>

                    <!-- Gaming Resources Section -->
                    <div class="form-section">
                        <h2 class="form-section-title">🎮 Gaming Resources</h2>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label">💰 Coins Balance</label>
                                <input type="number" class="gaming-input" name="coins" value="<?php echo $user['coins']; ?>" required min="0" step="1">
                            </div>
                            <div class="form-group">
                                <label class="form-label">🎫 Tickets Balance</label>
                                <input type="number" class="gaming-input" name="tickets" value="<?php echo $user['tickets']; ?>" required min="0" step="1">
                            </div>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="form-actions">
                        <button type="button" class="btn-secondary" onclick="window.location.href='view.php?id=<?php echo $user['id']; ?>'">👁️ View Player</button>
                        <button type="submit" class="btn-gaming">⚡ Update Player</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 
