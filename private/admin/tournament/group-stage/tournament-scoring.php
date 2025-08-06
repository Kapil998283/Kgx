<?php
// CRITICAL: Suppress ALL error output to prevent corrupting HTML title
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);

// Set default timezone to ensure consistency
date_default_timezone_set('Asia/Kolkata');
error_reporting(0);

// Set error handler to suppress all output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Tournament Scoring Error: $errstr in $errfile on line $errline");
    return true; // Don't execute PHP internal error handler
});

// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once dirname(dirname(__DIR__)) . '/admin_secure_config.php';

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

// Initialize Supabase connection with admin privileges
$supabase = new SupabaseClient(true);

// Get match ID from URL
$match_id = isset($_GET['match_id']) ? (int)$_GET['match_id'] : 0;

if (!$match_id) {
    $_SESSION['error'] = "Match ID not provided!";
    header("Location: ../index.php");
    exit();
}

// Get match details
$match_data = $supabase->select('group_matches', '*', ['id' => $match_id], null, 1);
$match = !empty($match_data) ? $match_data[0] : null;

if (!$match) {
    $_SESSION['error'] = "Match not found!";
    header("Location: ../index.php");
    exit();
}

// Get tournament details
$tournament_data = $supabase->select('tournaments', '*', ['id' => $match['tournament_id']], null, 1);
$tournament = !empty($tournament_data) ? $tournament_data[0] : null;

if (!$tournament) {
    $_SESSION['error'] = "Tournament not found!";
    header("Location: ../index.php");
    exit();
}

// Get group details
$group_data = $supabase->select('tournament_groups', '*', ['id' => $match['group_id']], null, 1);
$group = !empty($group_data) ? $group_data[0] : null;

// Check if this is a solo tournament
$is_solo = $tournament['mode'] === 'Solo';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action']) && $_POST['action'] === 'update_results') {
            $match_id = $_POST['match_id'];
            $participant_results = $_POST['participant_results'] ?? [];

            if (empty($participant_results)) {
                throw new Exception('No results data provided');
            }

            $match_data = $supabase->select('group_matches', '*', ['id' => $match_id], null, 1);
            $match = !empty($match_data) ? $match_data[0] : null;
            
            if (!$match) {
                throw new Exception('Match not found');
            }
            
            // Get tournament details separately
            $tournament_details = $supabase->select('tournaments', 'name, mode', ['id' => $match['tournament_id']], null, 1);
            $tournament_details = !empty($tournament_details) ? $tournament_details[0] : null;
            
            if (!$tournament_details) {
                throw new Exception('Tournament not found');
            }
            
            $is_match_solo = $tournament_details['mode'] === 'Solo';

            // Initialize notifications if the class exists
            $notifications = null;
            if (class_exists('TournamentNotifications')) {
                $notifications = new TournamentNotifications($supabase);
            }

            foreach ($participant_results as $participant_id => $result) {
                $kill_points = $result['kills'] * $match['kill_points'];
                
                $placement_points_array = json_decode($match['placement_points'], true);
                $placement_points = isset($placement_points_array[$result['placement']]) 
                    ? $placement_points_array[$result['placement']] 
                    : 0;

                $total_points = $kill_points + $placement_points;

                // Update match results
                if ($is_match_solo) {
                    $update_result = $supabase->update('group_match_participants', [
                        'kills' => $result['kills'],
                        'placement' => $result['placement'],
                        'kill_points' => $kill_points,
                        'placement_points' => $placement_points,
                        'total_points' => $total_points,
                        'updated_at' => date('Y-m-d H:i:s')
                    ], [
                        'match_id' => $match_id,
                        'user_id' => $participant_id
                    ]);
                } else {
                    $update_result = $supabase->update('group_match_teams', [
                        'kills' => $result['kills'],
                        'placement' => $result['placement'],
                        'kill_points' => $kill_points,
                        'placement_points' => $placement_points,
                        'total_points' => $total_points,
                        'updated_at' => date('Y-m-d H:i:s')
                    ], [
                        'match_id' => $match_id,
                        'team_id' => $participant_id
                    ]);
                }
                
                if (!$update_result) {
                    throw new Exception('Failed to update results for participant ' . $participant_id);
                }

                // Update overall tournament scores
                if ($is_match_solo) {
                    $supabase->rpc('increment_user_tournament_score', [
                        'user_id_param' => $participant_id, 
                        'increment_by' => $total_points, 
                        'tournament_id_param' => $match['tournament_id']
                    ]);
                } else {
                    $supabase->rpc('increment_team_tournament_score', [
                        'team_id_param' => $participant_id, 
                        'increment_by' => $total_points,
                        'tournament_id_param' => $match['tournament_id']
                    ]);
                }

                // Send notifications if available
                if ($notifications) {
                    $notifications->matchResults($participant_id, $tournament_details['name'], $match['match_name'], $result['placement'], $result['kills'], $total_points);
                }
            }
            
            // Update match status to completed if not already
            if ($match['status'] !== 'completed') {
                $supabase->update('group_matches', [
                    'status' => 'completed',
                    'updated_at' => date('Y-m-d H:i:s')
                ], ['id' => $match_id]);
            }
            
            $_SESSION['success'] = "Match results updated successfully!";
        }

    } catch (Exception $e) {
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    header("Location: tournament-scoring.php?match_id=" . $match_id);
    exit();
}

// Get match participants with current results
$match_participants = [];
if ($is_solo) {
    // For solo tournaments, get participants from group_match_participants
    $participants_data = $supabase->select('group_match_participants', '*', ['match_id' => $match_id]);
    if ($participants_data) {
        foreach ($participants_data as $participant) {
            $user_data = $supabase->select('users', 'id, username', ['id' => $participant['user_id']], null, 1);
            if ($user_data) {
                $participant['name'] = $user_data[0]['username'];
                $participant['participant_id'] = $participant['user_id'];
                $match_participants[] = $participant;
            }
        }
    }
} else {
    // For team tournaments, get teams from group_match_teams
    $teams_data = $supabase->select('group_match_teams', '*', ['match_id' => $match_id]);
    if ($teams_data) {
        foreach ($teams_data as $team) {
            $team_data = $supabase->select('teams', 'id, name', ['id' => $team['team_id']], null, 1);
            if ($team_data) {
                $team['name'] = $team_data[0]['name'];
                $team['participant_id'] = $team['team_id'];
                $match_participants[] = $team;
            }
        }
    }
}

// Sort participants by current placement (if any), then by name
usort($match_participants, function($a, $b) {
    if ($a['placement'] && $b['placement']) {
        return $a['placement'] - $b['placement'];
    } elseif ($a['placement']) {
        return -1;
    } elseif ($b['placement']) {
        return 1;
    }
    return strcmp($a['name'], $b['name']);
});

include '../../includes/admin-header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include '../../includes/admin-sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <div>
                    <h1><i class="bi bi-trophy me-2"></i>Match Scoring</h1>
                    <h5 class="text-muted"><?php echo htmlspecialchars($tournament['name'] ?? ''); ?> - <?php echo htmlspecialchars($match['match_name'] ?? ''); ?></h5>
                    <p class="text-muted mb-0">
                        Game: <?php echo htmlspecialchars($tournament['game_name'] ?? ''); ?> | 
                        Mode: <?php echo $is_solo ? 'Solo' : 'Team'; ?> | 
                        Group: <?php echo htmlspecialchars($group['group_name'] ?? 'N/A'); ?>
                    </p>
                </div>
                <div class="btn-group">
                    <a href="tournament-schedule.php?id=<?php echo $tournament['id']; ?>" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Schedule
                    </a>
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

            <!-- Match Information Card -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Match Information</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3">
                                    <strong>Match:</strong><br>
                                    <span class="text-primary"><?php echo htmlspecialchars($match['match_name'] ?? ''); ?></span>
                                </div>
                                <div class="col-md-3">
                                    <strong>Map:</strong><br>
                                    <?php echo htmlspecialchars($match['map_name'] ?? 'N/A'); ?>
                                </div>
                                <div class="col-md-2">
                                    <strong>Kill Points:</strong><br>
                                    <span class="badge bg-success"><?php echo (int)$match['kill_points']; ?> pts</span>
                                </div>
                                <div class="col-md-2">
                                    <strong>Participants:</strong><br>
                                    <span class="badge bg-info"><?php echo count($match_participants); ?></span>
                                </div>
                                <div class="col-md-2">
                                    <strong>Status:</strong><br>
                                    <span class="badge bg-<?php 
                                        echo match($match['status']) {
                                            'upcoming' => 'primary',
                                            'live' => 'success',
                                            'completed' => 'secondary',
                                            default => 'info'
                                        };
                                    ?>">
                                        <?php echo ucfirst($match['status']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Scoring Form -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h6 class="mb-0"><i class="bi bi-trophy me-2"></i>Update Match Results</h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($match_participants)): ?>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    No participants found for this match. Please check the match setup.
                                </div>
                            <?php else: ?>
                                <form id="updateResultsForm" method="POST">
                                    <input type="hidden" name="action" value="update_results">
                                    <input type="hidden" name="match_id" value="<?php echo $match_id; ?>">
                                    <input type="hidden" name="kill_points" value="<?php echo $match['kill_points']; ?>">
                                    <input type="hidden" name="is_solo" value="<?php echo $is_solo ? '1' : '0'; ?>">

                                    <div class="alert alert-info mb-4">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <strong>Instructions:</strong>
                                                <ul class="mb-0">
                                                    <li>Enter placement (1st = 1, 2nd = 2, etc.)</li>
                                                    <li>Enter total kills for each <?php echo $is_solo ? 'player' : 'team'; ?></li>
                                                    <li>Points will be calculated automatically</li>
                                                </ul>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="text-end">
                                                    <strong>Points System:</strong><br>
                                                    <small class="text-muted">
                                                        Kill Points: <span><?php echo $match['kill_points']; ?></span> per kill<br>
                                                        Placement: <?php 
                                                            $placement_points = json_decode($match['placement_points'], true);
                                                            echo !empty($placement_points) ? 'Active' : 'Disabled'; 
                                                        ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th style="width: 30%"><?php echo $is_solo ? 'Player' : 'Team'; ?> Name</th>
                                                    <th style="width: 15%">Placement</th>
                                                    <th style="width: 15%">Kills</th>
                                                    <th style="width: 20%">Points Breakdown</th>
                                                    <th style="width: 20%">Total Points</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($match_participants as $participant): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($participant['name']); ?></strong>
                                                        </td>
                                                        <td>
                                                            <input type="number" class="form-control form-control-sm" 
                                                                   name="participant_results[<?php echo $participant['participant_id']; ?>][placement]"
                                                                   value="<?php echo $participant['placement'] ?? ''; ?>"
                                                                   min="1" max="<?php echo count($match_participants); ?>" 
                                                                   onchange="calculatePoints(<?php echo $participant['participant_id']; ?>)">
                                                        </td>
                                                        <td>
                                                            <input type="number" class="form-control form-control-sm" 
                                                                   name="participant_results[<?php echo $participant['participant_id']; ?>][kills]"
                                                                   value="<?php echo $participant['kills'] ?? 0; ?>"
                                                                   min="0" max="50" 
                                                                   onchange="calculatePoints(<?php echo $participant['participant_id']; ?>)">
                                                        </td>
                                                        <td>
                                                            <small class="points-breakdown" id="breakdown_<?php echo $participant['participant_id']; ?>">
                                                                <?php 
                                                                    $kill_pts = ($participant['kills'] ?? 0) * $match['kill_points'];
                                                                    $place_pts = $participant['placement_points'] ?? 0;
                                                                    echo "Kills: {$kill_pts} + Placement: {$place_pts}";
                                                                ?>
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-primary total-points" id="total_<?php echo $participant['participant_id']; ?>">
                                                                <?php echo ($participant['total_points'] ?? 0); ?>
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="mt-3">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="fillSequentialPlacements()">
                                                    <i class="bi bi-list-ol"></i> Auto-fill Placements (1,2,3...)
                                                </button>
                                            </div>
                                            <div class="col-md-6 text-end">
                                                <small class="text-muted">Points are calculated automatically as you type</small>
                                            </div>
                                        </div>
                                    </div>

                                    <hr>
                                    
                                    <div class="d-flex justify-content-end gap-2">
                                        <a href="tournament-schedule.php?id=<?php echo $tournament['id']; ?>" class="btn btn-secondary">
                                            <i class="bi bi-x-circle"></i> Cancel
                                        </a>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-check-circle"></i> Save Results
                                        </button>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Placement points data from PHP
const placementPoints = <?php echo $match['placement_points'] ?? '{}'; ?>;
const killPointsPerKill = <?php echo $match['kill_points']; ?>;

function calculatePoints(participantId) {
    const placementInput = document.querySelector(`input[name="participant_results[${participantId}][placement]"]`);
    const killsInput = document.querySelector(`input[name="participant_results[${participantId}][kills]"]`);
    
    const placement = parseInt(placementInput.value) || 0;
    const kills = parseInt(killsInput.value) || 0;
    
    const killPoints = kills * killPointsPerKill;
    const placementPts = placementPoints[placement] || 0;
    const totalPoints = killPoints + placementPts;
    
    // Update breakdown display
    document.getElementById(`breakdown_${participantId}`).textContent = 
        `Kills: ${killPoints} + Placement: ${placementPts}`;
    
    // Update total points
    document.getElementById(`total_${participantId}`).textContent = totalPoints;
}

function fillSequentialPlacements() {
    const placementInputs = document.querySelectorAll('input[name*="[placement]"]');
    placementInputs.forEach((input, index) => {
        input.value = index + 1;
        const participantId = input.name.match(/\[(\d+)\]/)[1];
        calculatePoints(participantId);
    });
}

// Auto-calculate points when page loads
document.addEventListener('DOMContentLoaded', function() {
    <?php foreach ($match_participants as $participant): ?>
        calculatePoints(<?php echo $participant['participant_id']; ?>);
    <?php endforeach; ?>
});
</script>

</body>
</html>
