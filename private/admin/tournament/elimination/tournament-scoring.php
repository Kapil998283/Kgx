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

// Get round ID from URL
$round_id = isset($_GET['round_id']) ? (int)$_GET['round_id'] : 0;

if (!$round_id) {
    $_SESSION['error'] = "Round ID not provided!";
    header("Location: ../index.php");
    exit();
}

// Get round details
$round_data = $supabase->select('tournament_rounds', '*', ['id' => $round_id], null, 1);
$round = !empty($round_data) ? $round_data[0] : null;

if (!$round) {
    $_SESSION['error'] = "Round not found!";
    header("Location: ../index.php");
    exit();
}

// Get tournament details
$tournament_data = $supabase->select('tournaments', '*', ['id' => $round['tournament_id']], null, 1);
$tournament = !empty($tournament_data) ? $tournament_data[0] : null;

if (!$tournament) {
    $_SESSION['error'] = "Tournament not found!";
    header("Location: ../index.php");
    exit();
}

// Check if this is a solo tournament
$is_solo = $tournament['mode'] === 'Solo';

// Check total registered participants to determine if qualification bonus should be disabled
if ($is_solo) {
    $registered_participants_data = $supabase->select('tournament_registrations', 'id', ['tournament_id' => $tournament['id'], 'status' => 'approved', 'user_id' => ['not.is', null]]);
    $totalRegisteredParticipants = count($registered_participants_data);
} else {
    $registered_participants_data = $supabase->select('tournament_registrations', 'id', ['tournament_id' => $tournament['id'], 'status' => 'approved', 'team_id' => ['not.is', null]]);
    $totalRegisteredParticipants = count($registered_participants_data);
}

// Determine if qualification bonus should be disabled (less than 30 participants)
$disableQualificationBonus = $totalRegisteredParticipants < 30;
$effectiveQualificationPoints = $disableQualificationBonus ? 0 : $round['qualification_points'];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action']) && $_POST['action'] === 'update_results') {
            $round_id = $_POST['round_id'];
            $participant_results = $_POST['team_results'] ?? []; // JavaScript sends 'team_results'

            if (empty($participant_results)) {
                throw new Exception('No results data provided');
            }

            $round_data = $supabase->select('tournament_rounds', '*', ['id' => $round_id], null, 1);
            $round = !empty($round_data) ? $round_data[0] : null;
            
            if (!$round) {
                throw new Exception('Round not found');
            }
            
            // Get tournament details separately
            $tournament_details = $supabase->select('tournaments', 'name, mode', ['id' => $round['tournament_id']], null, 1);
            $tournament_details = !empty($tournament_details) ? $tournament_details[0] : null;
            
            if (!$tournament_details) {
                throw new Exception('Tournament not found');
            }
            
            $is_round_solo = $tournament_details['mode'] === 'Solo';

            // Initialize notifications if the class exists
            $notifications = null;
            if (class_exists('TournamentNotifications')) {
                $notifications = new TournamentNotifications($supabase);
            }

            foreach ($participant_results as $participant_id => $result) {
                $kill_points = $result['kills'] * $_POST['kill_points'];
                
                $placement_points_array = json_decode($round['placement_points'], true);
                $placement_points = isset($placement_points_array[$result['placement']]) 
                    ? $placement_points_array[$result['placement']] 
                    : 0;

                // Use effective qualification points (0 if tournament has less than 30 participants)
                $qualification_points_to_use = $disableQualificationBonus ? 0 : $_POST['qualification_points'];
                $bonus_points = ($result['status'] === 'qualified') ? $qualification_points_to_use : 0;
                $total_points = $kill_points + $placement_points + $bonus_points;

                // Update round results directly
                $update_result = $supabase->update('round_teams', [
                    'kills' => $result['kills'],
                    'placement' => $result['placement'],
                    'kill_points' => $kill_points,
                    'placement_points' => $placement_points,
                    'bonus_points' => $bonus_points,
                    'total_points' => $total_points,
                    'status' => $result['status']
                ], [
                    'round_id' => $round_id,
                    'team_id' => $participant_id
                ]);
                
                if (!$update_result) {
                    throw new Exception('Failed to update results for participant ' . $participant_id);
                }

                if ($is_round_solo) {
                    $supabase->rpc('increment_user_score', ['user_id_param' => $participant_id, 'increment_by' => $total_points, 'tournament_id_param' => $round['tournament_id']]);
                } else {
                    $supabase->rpc('increment_team_score', ['team_id_param' => $participant_id, 'increment_by' => $total_points]);
                }

                if ($notifications) {
                    if ($is_round_solo) {
                        $notifications->roundResults($participant_id, $tournament_details['name'], $round['name'], $result['placement'], $result['kills'], $total_points);
                        if ($result['status'] === 'qualified') {
                            $notifications->teamQualified($participant_id, $tournament_details['name'], $round['name']);
                        } elseif ($result['status'] === 'eliminated') {
                            $notifications->teamEliminated($participant_id, $tournament_details['name'], $round['name']);
                        }
                    } else {
                        $notifications->roundResults($participant_id, $tournament_details['name'], $round['name'], $result['placement'], $result['kills'], $total_points);
                        if ($result['status'] === 'qualified') {
                            $notifications->teamQualified($participant_id, $tournament_details['name'], $round['name']);
                        } elseif ($result['status'] === 'eliminated') {
                            $notifications->teamEliminated($participant_id, $tournament_details['name'], $round['name']);
                        }
                    }
                }
            }
            $_SESSION['success'] = "Round results updated successfully!";
        }

    } catch (Exception $e) {
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    header("Location: tournament-scoring.php?round_id=" . $round_id);
    exit();
}

// Get round participants
$round_participants = [];
if ($is_solo) {
    // For solo tournaments, get participants from solo_tournament_participants
    $solo_participants_data = $supabase->select('solo_tournament_participants', '*', ['round_id' => $round_id]);
    $round_participants = $solo_participants_data ?: [];
} else {
    // For team tournaments, get round teams
    $round_teams_data = $supabase->select('round_teams', '*', ['round_id' => $round_id]);
    $round_participants = $round_teams_data ?: [];
}

include '../../includes/admin-header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include '../../includes/admin-sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <div>
                    <h1><i class="bi bi-trophy me-2"></i>Tournament Scoring</h1>
                    <h5 class="text-muted"><?php echo htmlspecialchars($tournament['name'] ?? ''); ?> - <?php echo htmlspecialchars($round['name'] ?? ''); ?></h5>
                    <p class="text-muted mb-0">Game: <?php echo htmlspecialchars($tournament['game_name'] ?? ''); ?> | Mode: <?php echo $is_solo ? 'Solo' : 'Team'; ?></p>
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

            <!-- Round Information Card -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h6 class="mb-0"><i class="bi bi-info-circle me-2"></i>Round Information</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3">
                                    <strong>Round:</strong><br>
                                    <span class="text-primary"><?php echo htmlspecialchars($round['name'] ?? ''); ?></span>
                                </div>
                                <div class="col-md-3">
                                    <strong>Map:</strong><br>
                                    <?php echo htmlspecialchars($round['map_name'] ?? 'N/A'); ?>
                                </div>
                                <div class="col-md-2">
                                    <strong>Kill Points:</strong><br>
                                    <span class="badge bg-success"><?php echo (int)$round['kill_points']; ?> pts</span>
                                </div>
                                <div class="col-md-2">
                                    <strong>Qualification Bonus:</strong><br>
                                    <span class="badge bg-warning"><?php echo (int)$round['qualification_points']; ?> pts</span>
                                </div>
                                <div class="col-md-2">
                                    <strong>Participants:</strong><br>
                                    <span class="badge bg-info"><?php echo count($round_participants); ?></span>
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
                            <h6 class="mb-0"><i class="bi bi-trophy me-2"></i>Update Round Results</h6>
                        </div>
                        <div class="card-body">
                            <?php if (empty($round_participants)): ?>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    No participants found for this round. Please assign participants first.
                                </div>
                            <?php else: ?>
                                <form id="updateResultsForm" method="POST">
                                    <input type="hidden" name="action" value="update_results">
                                    <input type="hidden" name="round_id" value="<?php echo $round_id; ?>">
                                    <input type="hidden" name="kill_points" value="<?php echo $round['kill_points']; ?>">
                                    <input type="hidden" name="qualification_points" value="<?php echo $round['qualification_points']; ?>">
                                    <input type="hidden" name="is_solo" value="<?php echo $is_solo ? '1' : '0'; ?>">

                                    <div class="alert alert-info mb-4">
                                        <div class="row">
                                            <div class="col-md-8">
                                                <strong>Instructions:</strong>
                                                <ul class="mb-0">
                                                    <li>Enter placement (1st = 1, 2nd = 2, etc.)</li>
                                                    <li>Enter total kills for each <?php echo $is_solo ? 'player' : 'team'; ?></li>
                                                    <li><strong>Status:</strong> Selected (in round) → Eliminated/Qualified (final result)</li>
                                                </ul>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="text-end">
                                                    <strong>Points System:</strong><br>
                                                    <small class="text-muted">Kill Points: <span><?php echo $round['kill_points']; ?></span> per kill<br>
                                                    Qualification Bonus: <span><?php echo $round['qualification_points']; ?></span> pts</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead class="table-dark">
                                                <tr>
                                                    <th style="width: 25%"><?php echo $is_solo ? 'Player' : 'Team'; ?> Name</th>
                                                    <th style="width: 15%">Placement</th>
                                                    <th style="width: 15%">Kills</th>
                                                    <th style="width: 20%">Status</th>
                                                    <th style="width: 25%">Points Preview</th>
                                                </tr>
                                            </thead>
                                            <tbody id="participantsTableBody">
                                                <!-- Participants will be loaded here -->
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
// Load participants data
document.addEventListener('DOMContentLoaded', function() {
    loadParticipants();
});

function loadParticipants() {
    const roundId = <?php echo $round_id; ?>;
    const killPoints = <?php echo $round['kill_points']; ?>;
    const qualificationPoints = <?php echo $round['qualification_points']; ?>;
    const isSolo = <?php echo $is_solo ? 'true' : 'false'; ?>;
    
    fetch(`../common/get_round_results.php?round_id=${roundId}`)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                showNotification('error', 'Error Loading Results', data.error);
                return;
            }
            
            const tbody = document.getElementById('participantsTableBody');
            
            if (!data || data.length === 0) {
                tbody.innerHTML = `
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            <i class="bi bi-exclamation-triangle"></i>
                            No participants found for this round.
                        </td>
                    </tr>
                `;
                return;
            }
            
            // Create participant rows
            tbody.innerHTML = data.map((team, index) => {
                const killPointsCalc = (team.kills || 0) * killPoints;
                const placementPoints = team.placement_points || 0;
                const bonusPoints = team.status === 'qualified' ? qualificationPoints : 0;
                const totalPoints = killPointsCalc + placementPoints + bonusPoints;
                
                return `
                <tr>
                    <td>
                        <div class="d-flex align-items-center">
                            <i class="bi bi-person-circle me-2 text-primary"></i>
                            <strong>${team.name}</strong>
                        </div>
                        <input type="hidden" name="team_results[${team.id}][team_id]" value="${team.id}">
                    </td>
                    <td>
                        <input type="number" class="form-control form-control-sm" 
                               name="team_results[${team.id}][placement]" 
                               value="${team.placement || ''}" 
                               min="1" 
                               max="100" 
                               placeholder="#" 
                               onchange="updatePointsPreview(${team.id}, ${killPoints}, ${qualificationPoints})" 
                               required>
                    </td>
                    <td>
                        <input type="number" class="form-control form-control-sm" 
                               name="team_results[${team.id}][kills]" 
                               value="${team.kills || 0}" 
                               min="0" 
                               max="50" 
                               onchange="updatePointsPreview(${team.id}, ${killPoints}, ${qualificationPoints})" 
                               required>
                    </td>
                    <td>
                        <select class="form-select form-select-sm" 
                                name="team_results[${team.id}][status]" 
                                onchange="updatePointsPreview(${team.id}, ${killPoints}, ${qualificationPoints})">
                            <option value="selected" ${team.status === 'selected' ? 'selected' : ''}>Selected</option>
                            <option value="eliminated" ${team.status === 'eliminated' ? 'selected' : ''}>Eliminated</option>
                            <option value="qualified" ${team.status === 'qualified' ? 'selected' : ''}>Qualified</option>
                        </select>
                    </td>
                    <td>
                        <div class="small" id="points_preview_${team.id}">
                            <div class="text-muted">
                                Kills: <span class="kill-points">${killPointsCalc}</span> pts<br>
                                Position: <span class="placement-points">${placementPoints}</span> pts<br>
                                Bonus: <span class="bonus-points">${bonusPoints}</span> pts<br>
                                <strong>Total: <span class="total-points text-primary">${totalPoints}</span> pts</strong>
                            </div>
                        </div>
                    </td>
                </tr>
                `;
            }).join('');
        })
        .catch(error => {
            console.error('Error loading results:', error);
            showNotification('error', 'Network Error', 'Failed to load round results. Please try again.');
        });
}

// Helper function to update points preview when values change
function updatePointsPreview(teamId, killPoints, qualificationPoints) {
    const placementInput = document.querySelector(`input[name="team_results[${teamId}][placement]"]`);
    const killsInput = document.querySelector(`input[name="team_results[${teamId}][kills]"]`);
    const statusSelect = document.querySelector(`select[name="team_results[${teamId}][status]"]`);
    const previewDiv = document.getElementById(`points_preview_${teamId}`);
    
    if (!previewDiv || !placementInput || !killsInput || !statusSelect) return;
    
    const placement = parseInt(placementInput.value) || 0;
    const kills = parseInt(killsInput.value) || 0;
    const status = statusSelect.value;
    
    // Calculate points
    const calculatedKillPoints = kills * (killPoints || 0);
    const placementPointsValue = 0; // Will be calculated by backend based on placement
    const bonusPoints = status === 'qualified' ? (qualificationPoints || 0) : 0;
    const totalPoints = calculatedKillPoints + placementPointsValue + bonusPoints;
    
    // Update preview
    previewDiv.innerHTML = `
        <div class="text-muted">
            Kills: <span class="kill-points">${calculatedKillPoints}</span> pts<br>
            Position: <span class="placement-points">TBD</span> pts<br>
            Bonus: <span class="bonus-points">${bonusPoints}</span> pts<br>
            <strong>Est. Total: <span class="total-points text-primary">${calculatedKillPoints + bonusPoints}+</span> pts</strong>
        </div>
    `;
}

// Helper function to auto-fill sequential placements
function fillSequentialPlacements() {
    const placementInputs = document.querySelectorAll('input[name*="[placement]"]');
    let placement = 1;
    
    placementInputs.forEach(input => {
        input.value = placement;
        placement++;
        
        // Trigger change event to update points preview
        input.dispatchEvent(new Event('change'));
    });
    
    showNotification('success', 'Placements Filled', 'Sequential placements (1, 2, 3...) have been filled automatically.');
}

// Enhanced notification function
function showNotification(type = 'info', title = 'Notification', message = '') {
    const colors = {
        'success': { bg: 'bg-success', icon: 'bi-check-circle-fill' },
        'error': { bg: 'bg-danger', icon: 'bi-exclamation-triangle-fill' },
        'warning': { bg: 'bg-warning', icon: 'bi-exclamation-triangle' },
        'info': { bg: 'bg-info', icon: 'bi-info-circle-fill' }
    };
    
    const config = colors[type] || colors.info;
    
    // Create a styled alert instead of basic alert
    const alertId = 'dynamicAlert_' + Date.now();
    const alertHtml = `
        <div id="${alertId}" class="alert alert-${type} alert-dismissible fade show position-fixed" 
             style="top: 20px; right: 20px; z-index: 9999; min-width: 300px; max-width: 500px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
            <div class="d-flex align-items-center">
                <i class="bi ${config.icon} me-2"></i>
                <div>
                    <strong>${title}</strong><br>
                    <small>${message.replace(/\n/g, '<br>')}</small>
                </div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;
    
    // Append to body
    document.body.insertAdjacentHTML('beforeend', alertHtml);
    
    // Auto-dismiss after 5 seconds for success messages, 8 seconds for errors
    const timeout = type === 'success' ? 5000 : 8000;
    setTimeout(() => {
        const alertElement = document.getElementById(alertId);
        if (alertElement) {
            const bsAlert = new bootstrap.Alert(alertElement);
            bsAlert.close();
        }
    }, timeout);
}

// Add form submission handler
document.getElementById('updateResultsForm').addEventListener('submit', function(e) {
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn ? submitBtn.innerHTML : 'Save Results';
    
    // Show loading state
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving Results...';
    }
    
    // The form will submit normally, but we want to show loading state
    // Form will redirect after submission
});
</script>

<?php include '../../includes/admin-footer.php'; ?>
