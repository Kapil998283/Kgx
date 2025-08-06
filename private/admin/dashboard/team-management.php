<?php
// CRITICAL: Suppress ALL error output to prevent corrupting HTML
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();

// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once dirname(__DIR__) . '/admin_secure_config.php';

// Use the new admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

// Initialize Supabase connection
$supabase = getSupabaseConnection();

// Handle team status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $team_id = (int)$_POST['team_id'];
    
    try {
        switch ($_POST['action']) {
            case 'deactivate':
                $supabase->update('teams', ['is_active' => 0], ['id' => $team_id]);
                $message = "Team deactivated successfully";
                break;
            case 'activate':
                $supabase->update('teams', ['is_active' => 1], ['id' => $team_id]);
                $message = "Team activated successfully";
                break;
            case 'delete':
                $supabase->delete('teams', ['id' => $team_id]);
                $message = "Team deleted successfully";
                break;
            default:
                $_SESSION['error_message'] = "Invalid action";
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
        }
        $_SESSION['success_message'] = $message;
    } catch (Exception $e) {
        error_log("Team management error: " . $e->getMessage());
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
    
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// Get all teams with their details using Supabase
try {
    $teams = [];
    
    if (isset($_GET['search']) && !empty($_GET['search'])) {
        $searchTerm = $_GET['search'];
        // Search teams by name
        $teams = $supabase->select(
            'teams', 
            '*', 
            ['name.ilike' => '%' . $searchTerm . '%'],
            'created_at.desc'
        );
    } else {
        // Get all teams
        $teams = $supabase->select(
            'teams', 
            '*', 
            [], 
            'created_at.desc'
        );
    }
    
    // Enrich teams with additional data
    foreach ($teams as &$team) {
        // Get captain name
        try {
            $captain = $supabase->select('users', 'username', ['id' => $team['captain_id']]);
            $team['captain_name'] = !empty($captain) ? $captain[0]['username'] : 'Unknown';
        } catch (Exception $e) {
            $team['captain_name'] = 'Unknown';
        }
        
        // Get member count
        try {
            $members = $supabase->select('team_members', 'COUNT(*) as count', ['team_id' => $team['id']]);
            $team['member_count'] = !empty($members) ? $members[0]['count'] : 0;
        } catch (Exception $e) {
            $team['member_count'] = 0;
        }
        
        // Get banner path if exists
        try {
            if (isset($team['banner_id']) && $team['banner_id']) {
                $banner = $supabase->select('team_banners', 'image_path', ['id' => $team['banner_id']]);
                $team['banner_path'] = !empty($banner) ? $banner[0]['image_path'] : null;
            } else {
                $team['banner_path'] = null;
            }
        } catch (Exception $e) {
            $team['banner_path'] = null;
        }
    }
    
} catch (Exception $e) {
    error_log("Error fetching teams: " . $e->getMessage());
    $teams = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Management - Admin Dashboard</title>
    <link rel="stylesheet" href="../assets/css/root.css">
    <link rel="stylesheet" href="../assets/css/team/team-management.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
</head>
<body>
    <div class="team-management-container">
        <!-- Main content -->
        <main class="team-main-content">
                <div class="team-page-header">
                    <h1 class="team-page-title">Team Management</h1>
                    <div class="team-toolbar">
                        <a href="index.php" class="back-to-dashboard">
                            <i class="bi bi-arrow-left"></i> Back to Dashboard
                        </a>
                    </div>
                </div>

                <!-- Search Form -->
                <div class="team-search-card">
                    <form method="GET" class="team-search-form">
                        <div class="team-search-input-group">
                            <input type="text" class="team-search-input" id="searchTeam" name="search" 
                                   placeholder="Search team name..." 
                                   value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                            <button class="team-search-btn" type="submit">Search</button>
                        </div>
                        <?php if(isset($_GET['search'])): ?>
                            <a href="team-management.php" class="team-clear-btn">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="team-alert team-alert-error">
                        <?php 
                        echo htmlspecialchars($_SESSION['error_message']);
                        unset($_SESSION['error_message']);
                        ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="team-alert team-alert-success">
                        <?php 
                        echo htmlspecialchars($_SESSION['success_message']);
                        unset($_SESSION['success_message']);
                        ?>
                    </div>
                <?php endif; ?>

                <div class="team-table-card">
                    <div class="team-table-container">
                        <table class="team-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Team Name</th>
                                        <th>Logo</th>
                                        <th>Captain</th>
                                        <th>Members</th>
                                        <th>Status</th>
                                        <th>Total Score</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($teams as $team): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($team['id']); ?></td>
                                            <td><?php echo htmlspecialchars($team['name']); ?></td>
                                            <td>
                                                <img src="<?php echo htmlspecialchars($team['logo']); ?>" 
                                                     alt="Team Logo" 
                                                     class="team-logo">
                                            </td>
                                            <td><?php echo htmlspecialchars($team['captain_name']); ?></td>
                                            <td><?php echo htmlspecialchars($team['member_count']); ?>/<?php echo htmlspecialchars($team['max_members']); ?></td>
                                            <td>
                                                <span class="team-badge <?php echo $team['is_active'] ? 'team-badge-active' : 'team-badge-inactive'; ?>">
                                                    <?php echo $team['is_active'] ? 'Active' : 'Inactive'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="team-badge team-badge-score">
                                                    <?php echo number_format($team['total_score']); ?> pts
                                                </span>
                                            </td>
                                            <td>
                                                <div class="team-action-group">
                                                    <a href="team-details.php?id=<?php echo $team['id']; ?>" 
                                                       class="team-btn team-btn-view" title="View Team">
                                                        <i class="bi bi-eye team-btn-icon"></i>
                                                    </a>
                                                    <?php if ($team['is_active']): ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="team_id" value="<?php echo $team['id']; ?>">
                                                            <input type="hidden" name="action" value="deactivate">
                                                            <button type="submit" class="team-btn team-btn-deactivate" title="Deactivate Team"
                                                                    onclick="return confirm('Are you sure you want to deactivate this team?')">
                                                                <i class="bi bi-x-circle team-btn-icon"></i>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="team_id" value="<?php echo $team['id']; ?>">
                                                            <input type="hidden" name="action" value="activate">
                                                            <button type="submit" class="team-btn team-btn-activate" title="Activate Team">
                                                                <i class="bi bi-check-circle team-btn-icon"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="team_id" value="<?php echo $team['id']; ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <button type="submit" class="team-btn team-btn-delete" title="Delete Team"
                                                                onclick="return confirm('Are you sure you want to delete this team? This action cannot be undone.')">
                                                            <i class="bi bi-trash team-btn-icon"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
    </div>

</body>
</html>
