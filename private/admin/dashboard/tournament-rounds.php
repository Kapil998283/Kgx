<?php
// CRITICAL: Suppress ALL error output to prevent corrupting HTML title
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Set error handler to suppress all output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Tournament Error: $errstr in $errfile on line $errline");
    // Don't show timestamp errors on page, just log them
    if (strpos($errstr, 'timestamp') !== false || strpos($errstr, 'strtotime') !== false) {
        return true;
    }
    return true; // Don't execute PHP internal error handler
});

// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once dirname(__DIR__) . '/admin_secure_config.php';

// Configuration Loading
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

// Initialize Supabase connection with admin privileges
$supabase = new SupabaseClient(true);

// Get tournament ID from URL
$tournament_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get tournament details
$tournament_data = $supabase->select('tournaments', '*', ['id' => $tournament_id], null, 1);
$tournament = !empty($tournament_data) ? $tournament_data[0] : null;

if (!$tournament) {
    $_SESSION['error'] = "Tournament not found!";
    header("Location: tournaments.php");
    exit();
}
// Check if this is a solo tournament
$is_solo = $tournament['mode'] === 'Solo';

// Get tournament rounds
$rounds_data = $supabase->select('tournament_rounds', '*', ['tournament_id' => $tournament_id], 'round_number.asc');
$rounds = [];
if ($rounds_data) {
    foreach ($rounds_data as $round) {
        // Get round teams separately and count them
        $round_teams_data = $supabase->select('round_teams', '*', ['round_id' => $round['id']]);
        
        // Debug: Log the query result
        error_log("Round {$round['id']} teams query result: " . print_r($round_teams_data, true));
        
        $round['round_teams'] = $round_teams_data ?: [];
        $round['actual_teams_count'] = count($round['round_teams']);
        
        // Debug: Log final count
        error_log("Round {$round['id']} final teams count: " . $round['actual_teams_count']);
        
        $rounds[] = $round;
    }
}

// Get all registered participants
$allParticipants = [];
if ($is_solo) {
    // Get solo player registrations
    $all_participants_data = $supabase->select('tournament_registrations', 'id, status, user_id', [
        'tournament_id' => $tournament_id, 
        'status' => 'approved', 
        'user_id' => ['not.is', null]
    ]);
    if ($all_participants_data) {
        foreach ($all_participants_data as $participant) {
            $user_data = $supabase->select('users', 'id, username', ['id' => $participant['user_id']], null, 1);
            if ($user_data) {
                $allParticipants[] = array_merge($user_data[0], ['registration_status' => $participant['status']]);
            }
        }
    }
} else {
    // Get team registrations
    $all_participants_data = $supabase->select('tournament_registrations', 'id, status, team_id', [
        'tournament_id' => $tournament_id, 
        'status' => 'approved', 
        'team_id' => ['not.is', null]
    ]);
    if ($all_participants_data) {
        foreach ($all_participants_data as $participant) {
            $team_data = $supabase->select('teams', 'id, name', ['id' => $participant['team_id']], null, 1);
            if ($team_data) {
                $allParticipants[] = array_merge($team_data[0], ['registration_status' => $participant['status']]);
            }
        }
    }
}

include '../includes/admin-header.php';

?>

<style>
.btn-action {
    background-color: #563d7c;
    color: white;
    border: none;
    border-radius: 5px;
    padding: 6px 12px;
    margin: 2px;
}

.btn-action:hover {
    background-color: #6c5d9f;
    color: white;
    box-shadow: 0 0 8px rgba(86, 61, 124, 0.3);
    transform: translateY(-1px);
}

.btn-action i {
    margin-right: 4px;
}

.dropdown-toggle.btn-action::after {
    margin-left: 6px;
}
</style>

<div class="container-fluid">
    <div class="row">
        <?php include '../includes/admin-sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <div>
                    <h1>Tournament Rounds</h1>
                    <h5 class="text-muted"><?php echo htmlspecialchars($tournament['name'] ?? ''); ?> (<?php echo htmlspecialchars($tournament['game_name'] ?? ''); ?>)</h5>
                </div>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Round</th>
                            <th>Name</th>
                            <th>Time</th>
                            <th>Map</th>
                            <th><?php echo $is_solo ? 'Players' : 'Teams'; ?></th>
                            <th>Points System</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($rounds): ?>
                            <?php foreach ($rounds as $round): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($round['round_number'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($round['name'] ?? ''); ?></td>
                                    <td><?php 
                                        if ($round['start_time']) {
                                            try {
                                                // Handle PostgreSQL timestamp format
                                                $timestamp = $round['start_time'];
                                                
                                                // If it's just time format like "22:32", skip processing
                                                if (preg_match('/^\d{2}:\d{2}$/', $timestamp)) {
                                                    echo $timestamp;
                                                } else {
                                                    // Convert PostgreSQL timestamp to PHP timestamp
                                                    $time = strtotime($timestamp);
                                                    echo $time ? date('H:i', $time) : $timestamp;
                                                }
                                            } catch (Exception $e) {
                                                // Fallback: just show the raw value
                                                echo htmlspecialchars($round['start_time']);
                                            }
                                        }
                                    ?></td>
                                    <td><?php echo htmlspecialchars($round['map_name'] ?? ''); ?></td>
                                    <td>
                                        <?php if (isset($round['teams_count'])): ?>
                                            <?php 
                                            $actual_count = count($round['round_teams']);
                                            $expected_count = (int)$round['teams_count'];
                                            echo $actual_count . ' / ' . $expected_count;
                                            
                                            // Debug: Show detailed information
                                            echo '<br><small class="text-info">DEBUG: Round ID: ' . $round['id'] . '</small>';
                                            echo '<br><small class="text-info">DEBUG: round_teams type: ' . gettype($round['round_teams']) . '</small>';
                                            echo '<br><small class="text-info">DEBUG: round_teams data: ' . json_encode($round['round_teams']) . '</small>';
                                            
                                            if ($actual_count === 0 && !empty($round['round_teams'])) {
                                                echo '<br><small class="text-danger">(Debug: Array not empty but count is 0)</small>';
                                            }
                                            ?>
                                            <br>
                                            <small class="text-muted">Qualifying: <?php echo (int)$round['qualifying_teams']; ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (isset($round['kill_points'])): ?>
                                            Kill: <?php echo (int)$round['kill_points']; ?> pts
                                            <br>
                                            Position: 
                                            <?php 
                                            $placement_points = json_decode($round['placement_points'] ?? '{}', true);
                                            echo isset($placement_points[1]) ? "1st: {$placement_points[1]} pts" : '';
                                            ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo match($round['status']) {
                                                'upcoming' => 'primary',
                                                'in_progress' => 'success',
                                                'completed' => 'secondary',
                                                default => 'info'
                                            };
                                        ?>">
                                            <?php echo ucfirst($round['status']); ?>
                                        </span>
                                        <div class="dropdown d-inline-block ms-1">
                                            <button class="btn btn-sm btn-action dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                <i class="bi bi-gear-fill"></i>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li><a class="dropdown-item" href="#" onclick="changeRoundStatus(<?php echo $round['id']; ?>, 'upcoming')">Upcoming</a></li>
                                                <li><a class="dropdown-item" href="#" onclick="changeRoundStatus(<?php echo $round['id']; ?>, 'in_progress')">In Progress</a></li>
                                                <li><a class="dropdown-item" href="#" onclick="changeRoundStatus(<?php echo $round['id']; ?>, 'completed')">Completed</a></li>
                                            </ul>
                                        </div>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-action" onclick="editRound(<?php echo htmlspecialchars(json_encode($round)); ?>)" title="Edit Round">
                                            <i class="bi bi-pencil-fill"></i>
                                        </button>
                                        <button class="btn btn-sm btn-action" onclick="viewResults(<?php echo $round['id']; ?>)" title="View Results">
                                            <i class="bi bi-trophy-fill"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    No tournament rounds found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>
function changeRoundStatus(roundId, newStatus) {
    if (!roundId || !newStatus) return;
    
    fetch('update_round_status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `round_id=${roundId}&status=${newStatus}&tournament_id=<?php echo $tournament_id; ?>`
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Error updating round status: ' + data.message);
        }
    })
    .catch(error => {
        alert('Error updating round status');
        console.error(error);
    });
}

function editRound(round) {
    document.getElementById('edit_id').value = round.id;
    document.getElementById('edit_round_number').value = round.round_number;
    document.getElementById('edit_name').value = round.name;
    document.getElementById('edit_description').value = round.description;
    
    // Handle timestamp more safely
    let time = '';
    if (round.start_time) {
        try {
            // If it's already in HH:MM format, use it directly
            if (/^\d{2}:\d{2}$/.test(round.start_time)) {
                time = round.start_time;
            } else {
                // Try to parse as full timestamp
                const date = new Date(round.start_time);
                if (!isNaN(date.getTime())) {
                    time = date.toTimeString().substring(0, 5);
                } else {
                    time = round.start_time; // Fallback to original value
                }
            }
        } catch (e) {
            console.error('Error processing timestamp:', e);
            time = round.start_time || '';
        }
    }
    document.getElementById('edit_start_time').value = time;
    
    document.getElementById('edit_teams_count').value = round.teams_count;
    document.getElementById('edit_qualifying_teams').value = round.qualifying_teams;
    document.getElementById('edit_map_name').value = round.map_name;
    document.getElementById('edit_kill_points').value = round.kill_points;
    document.getElementById('edit_qualification_points').value = round.qualification_points;
    document.getElementById('edit_special_rules').value = round.special_rules;

    new bootstrap.Modal(document.getElementById('editRoundModal')).show();
}
</script> 