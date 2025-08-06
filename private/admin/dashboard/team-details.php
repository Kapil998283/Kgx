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

// Initialize database connection
$database = new Database();
$conn = $database->connect();

// Get team ID from URL
$team_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$team_id) {
    header('Location: team-management.php');
    exit();
}

// Fetch team details
$team_sql = "SELECT t.*, 
             u.username as captain_username,
             u.email as captain_email,
             u.created_at as captain_join_date
             FROM teams t
             LEFT JOIN team_members tm ON t.id = tm.team_id AND tm.role = 'captain'
             LEFT JOIN users u ON tm.user_id = u.id
             WHERE t.id = :team_id";
$stmt = $conn->prepare($team_sql);
$stmt->execute(['team_id' => $team_id]);
$team = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$team) {
    header('Location: team-management.php');
    exit();
}

// Fetch team members
$members_sql = "SELECT tm.*, u.username, u.email, u.created_at as join_date
                FROM team_members tm
                JOIN users u ON tm.user_id = u.id
                WHERE tm.team_id = :team_id
                ORDER BY tm.role";
$stmt = $conn->prepare($members_sql);
$stmt->execute(['team_id' => $team_id]);
$members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle score update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_score'])) {
    $new_score = (int)$_POST['total_score'];
    try {
        $update_sql = "UPDATE teams SET total_score = :score WHERE id = :team_id";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->execute([
            'score' => $new_score,
            'team_id' => $team_id
        ]);
        $_SESSION['success_message'] = "Team score updated successfully!";
        header("Location: team-details.php?id=" . $team_id);
        exit();
    } catch (PDOException $e) {
        $_SESSION['error_message'] = "Error updating score: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team Details - <?php echo htmlspecialchars($team['name']); ?></title>
    <link rel="stylesheet" href="../assets/css/root.css">
    <link rel="stylesheet" href="../assets/css/team/team-details.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
</head>
<body>
    <div class="team-details-container">
        <!-- Main Content -->
        <main class="team-main-content">
                <div class="team-page-header">
                    <h1 class="team-page-title">Team Details</h1>
                    <div class="team-toolbar">
                        <a href="team-management.php" class="team-btn team-btn-back">
                            <i class="bi bi-arrow-left"></i> Back to Teams
                        </a>
                    </div>
                </div>

                <!-- Team Information -->
                <div class="team-info-card">
                    <div class="team-info-header">
                        <h5 class="team-info-title">Team Information</h5>
                    </div>
                    <div class="team-info-body">
                        <div class="team-info-row">
                            <div class="team-logo-container">
                                <img src="<?php echo htmlspecialchars($team['logo']); ?>" 
                                     alt="Team Logo" 
                                     class="team-logo">
                                <h4 class="team-name"><?php echo htmlspecialchars($team['name']); ?></h4>
                            </div>
                            <div class="team-info-details">
                                <div class="team-info-col">
                                    <div class="team-info-label">Status</div>
                                    <span class="team-badge <?php echo $team['is_active'] ? 'team-badge-active' : 'team-badge-inactive'; ?>">
                                        <?php echo $team['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </div>
                                <div class="team-info-col">
                                    <div class="team-info-label">Language</div>
                                    <div class="team-info-value"><?php echo htmlspecialchars($team['language']); ?></div>
                                </div>
                                <div class="team-info-col">
                                    <div class="team-info-label">Created At</div>
                                    <div class="team-info-value"><?php echo date('F j, Y', strtotime($team['created_at'])); ?></div>
                                </div>
                                <div class="team-info-col">
                                    <div class="team-info-label">Total Score</div>
                                    <span class="team-badge team-badge-score">
                                        <?php echo number_format($team['total_score']); ?> pts
                                    </span>
                                    <button type="button" class="team-btn team-btn-edit-score" 
                                            data-bs-toggle="modal" data-bs-target="#updateScoreModal">
                                        <i class="bi bi-pencil team-btn-icon"></i> Edit Score
                                    </button>
                                </div>
                            </div>
                            <div class="team-info-extra">
                                <div class="team-info-col">
                                    <div class="team-info-label">Captain</div>
                                    <div class="team-info-value"><?php echo htmlspecialchars($team['captain_username']); ?></div>
                                </div>
                                <div class="team-info-col">
                                    <div class="team-info-label">Captain Email</div>
                                    <div class="team-info-value"><?php echo htmlspecialchars($team['captain_email']); ?></div>
                                </div>
                                <div class="team-info-col">
                                    <div class="team-info-label">Captain Since</div>
                                    <div class="team-info-value"><?php echo date('F j, Y', strtotime($team['captain_join_date'])); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Team Members -->
                <div class="team-member-card">
                    <div class="team-member-header">
                        <h5 class="team-member-title">Team Members</h5>
                    </div>
                    <div class="team-member-list">
                        <table class="team-table">
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Role</th>
                                        <th>Join Date</th>
                                        <th>Member Type</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($members as $member): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($member['username']); ?></td>
                                        <td><?php echo htmlspecialchars($member['email']); ?></td>
                                        <td>
                                            <span class="team-badge <?php echo $member['role'] === 'captain' ? 'team-badge-captain' : 'team-badge-member'; ?>">
                                                <?php echo ucfirst($member['role']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('F j, Y', strtotime($member['join_date'])); ?></td>
                                        <td>
                                            <span class="team-badge <?php echo $member['role'] === 'captain' ? 'team-badge-leader' : 'team-badge-info'; ?>">
                                                <?php echo $member['role'] === 'captain' ? 'Leader' : 'Member'; ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                        </table>
                    </div>
                </div>
            </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Update Score Modal -->
    <div class="modal fade" id="updateScoreModal" tabindex="-1" aria-labelledby="updateScoreModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="updateScoreModalLabel">Update Team Score</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="total_score" class="form-label">Total Score</label>
                            <input type="number" class="form-control" id="total_score" name="total_score" 
                                   value="<?php echo $team['total_score']; ?>" required min="0">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_score" class="btn btn-primary">Update Score</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add success/error message display -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show position-fixed top-0 end-0 m-3" role="alert">
            <?php 
                echo $_SESSION['success_message'];
                unset($_SESSION['success_message']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show position-fixed top-0 end-0 m-3" role="alert">
            <?php 
                echo $_SESSION['error_message'];
                unset($_SESSION['error_message']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
</body>
</html> 