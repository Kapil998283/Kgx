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
    error_log("Tournament Error: $errstr in $errfile on line $errline");
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

$tournament_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get tournament details
$tournament_data = $supabase->select('tournaments', '*', ['id' => $tournament_id], null, 1);
$tournament = !empty($tournament_data) ? $tournament_data[0] : null;

if (!$tournament) {
    $_SESSION['error'] = "Tournament not found!";
    header("Location: ../index.php");
    exit();
}

// Check if this is a solo tournament
$is_solo = $tournament['mode'] === 'Solo';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add_round':
                    // Handle datetime properly based on day selection
                    $start_datetime = $_POST['start_time'];
                    if (isset($_POST['day_id']) && !empty($_POST['day_id'])) {
                        // Get the selected day's date
                        $day_data = $supabase->select('tournament_days', 'date', ['id' => $_POST['day_id']], null, 1);
                        if ($day_data) {
                            $date_part = $day_data[0]['date'];
                            $start_datetime = $date_part . ' ' . $_POST['start_time'] . ':00';
                        }
                    } else {
                        // Use today's date if no day selected
                        $start_datetime = date('Y-m-d') . ' ' . $_POST['start_time'] . ':00';
                    }
                    
                    $round_data = [
                        'tournament_id' => $tournament_id,
                        'round_number' => $_POST['round_number'],
                        'name' => $_POST['name'],
                        'description' => $_POST['description'],
                        'start_time' => $start_datetime,
                        'teams_count' => $_POST['teams_count'],
                        'qualifying_teams' => $_POST['qualifying_teams'],
                        'map_name' => $_POST['map_name'],
                        'kill_points' => $_POST['kill_points'],
                        'qualification_points' => $_POST['qualification_points'],
                        'special_rules' => $_POST['special_rules'],
                        'status' => 'upcoming',
                    ];
                    
                    // Add day_id if provided
                    if (isset($_POST['day_id']) && !empty($_POST['day_id'])) {
                        $round_data['day_id'] = $_POST['day_id'];
                    }
                    
                    $supabase->insert('tournament_rounds', $round_data);
                    $_SESSION['success'] = "Round added successfully!";
                    break;

                case 'update_round':
                    // Get the current round to preserve the date part
                    $current_round = $supabase->select('tournament_rounds', 'start_time', ['id' => $_POST['id']], null, 1);
                    $current_start_time = $current_round ? $current_round[0]['start_time'] : null;
                    
                    // Create proper timestamp by combining existing date with new time
                    $start_datetime = $_POST['start_time'];
                    if ($current_start_time && preg_match('/^\d{2}:\d{2}$/', $_POST['start_time'])) {
                        // Extract date from current timestamp and combine with new time
                        $date_part = date('Y-m-d', strtotime($current_start_time));
$start_datetime = $date_part . ' ' . $_POST['start_time'] . ':00';

// Format date for consistent admin display
function formatAdminDate($datetime) {
    return $datetime->format('Y-m-d H:i:s');
}
                    } elseif (preg_match('/^\d{2}:\d{2}$/', $_POST['start_time'])) {
                        // If no existing timestamp, use today's date
                        $start_datetime = date('Y-m-d') . ' ' . $_POST['start_time'] . ':00';
                    }
                    
                    $supabase->update('tournament_rounds', [
                        'round_number' => $_POST['round_number'],
                        'name' => $_POST['name'],
                        'description' => $_POST['description'],
                        'start_time' => $start_datetime,
                        'teams_count' => $_POST['teams_count'],
                        'qualifying_teams' => $_POST['qualifying_teams'],
                        'map_name' => $_POST['map_name'],
                        'kill_points' => $_POST['kill_points'],
                        'qualification_points' => $_POST['qualification_points'],
                        'special_rules' => $_POST['special_rules'],
                        'status' => $_POST['status'],
                    ], ['id' => $_POST['id']]);
                    $_SESSION['success'] = "Round updated successfully!";
                    break;

                case 'delete_round':
                    $round_id = $_POST['id'];
                    
                    // First delete related data
                    if ($is_solo) {
                        $supabase->delete('solo_tournament_participants', ['round_id' => $round_id]);
                    } else {
                        $supabase->delete('round_teams', ['round_id' => $round_id]);
                    }
                    
                    // Then delete the round
                    $supabase->delete('tournament_rounds', ['id' => $round_id]);
                    $_SESSION['success'] = "Round deleted successfully!";
                    break;
            }
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    header("Location: tournament-rounds.php?id=" . $tournament_id);
    exit();
}

// Get tournament days for this tournament
$tournament_days_data = $supabase->select('tournament_days', '*', ['tournament_id' => $tournament_id], 'day_number.asc');
$tournament_days = $tournament_days_data ?: [];

// Get tournament rounds with team counts
$rounds_data = $supabase->select('tournament_rounds', '*', ['tournament_id' => $tournament_id], 'round_number');
$rounds = [];
if ($rounds_data) {
    foreach ($rounds_data as $round) {
        if ($is_solo) {
            // For solo tournaments, get participants from solo_tournament_participants
            $solo_participants_data = $supabase->select('solo_tournament_participants', '*', ['round_id' => $round['id']]);
            $round['round_teams'] = $solo_participants_data ?: [];
            $round['actual_teams_count'] = count($solo_participants_data ?: []);
        } else {
            // For team tournaments, get round teams
            $round_teams_data = $supabase->select('round_teams', '*', ['round_id' => $round['id']]);
            $round['round_teams'] = $round_teams_data ?: [];
            $round['actual_teams_count'] = count($round['round_teams']);
        }
        $rounds[] = $round;
    }
}

include '../../includes/admin-header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include '../../includes/admin-sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <div>
                    <h1>Tournament Rounds</h1>
                    <h5 class="text-muted"><?php echo htmlspecialchars($tournament['name']); ?> (<?php echo $tournament['game_name']; ?>)</h5>
                </div>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRoundModal">
                    <i class="bi bi-plus-circle"></i> Add Round
                </button>
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
                            <th>Start Time</th>
                            <th>Format</th>
                            <th><?php echo ($tournament['mode'] === 'Solo') ? 'Players' : 'Teams'; ?></th>
                            <th>Map</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rounds as $round): ?>
                            <tr>
                                <td><?php echo $round['round_number']; ?></td>
                                <td><?php echo htmlspecialchars($round['name']); ?></td>
                                <td><?php echo date('H:i', strtotime($round['start_time'])); ?></td>
                                <td><?php echo ucfirst($round['round_format']); ?></td>
                                <td>
                                    <?php echo count($round['round_teams']); ?> / <?php echo $round['teams_count']; ?> <?php echo ($tournament['mode'] === 'Solo') ? 'Players' : 'Teams'; ?>
                                    <br>
                                    <small class="text-muted">Qualifying: <?php echo $round['qualifying_teams']; ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($round['map_name']); ?></td>
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
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary me-1" onclick='editRound(<?php echo json_encode($round); ?>)'>
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-sm btn-danger me-1" onclick="deleteRound(<?php echo $round['id']; ?>)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <button class="btn btn-sm btn-info" onclick="manageRoomDetails(<?php echo $round['id']; ?>, '<?php echo htmlspecialchars($round['name']); ?>')">
                                        <i class="fas fa-key"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>

<!-- Add Round Modal -->
<div class="modal fade" id="addRoundModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Round</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="addRoundForm" method="POST">
                    <input type="hidden" name="action" value="add_round">
                    <input type="hidden" name="tournament_id" value="<?php echo $tournament_id; ?>">

                    <div class="mb-3">
                        <label class="form-label">Tournament Day</label>
                        <?php if (empty($tournament_days)): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                                <strong>No tournament days found!</strong> 
                                You need to create tournament days first using the 
                                <a href="tournament-schedule.php?id=<?php echo $tournament_id; ?>" class="alert-link">Tournament Schedule</a> page.
                            </div>
                            <select class="form-select" name="day_id" disabled>
                                <option value="" disabled selected>No days available</option>
                            </select>
                        <?php else: ?>
                            <select class="form-select" name="day_id" required>
                                <option value="" disabled selected>Select a day</option>
                                <?php foreach ($tournament_days as $day): ?>
                                    <option value="<?php echo $day['id']; ?>">
                                        Day <?php echo $day['day_number']; ?> (<?php echo $day['date']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Round Number</label>
                        <input type="number" class="form-control" name="round_number" required min="1">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" class="form-control" name="name" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Start Time</label>
                        <input type="time" class="form-control" name="start_time" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Max <?php echo ($tournament['mode'] === 'Solo') ? 'Players' : 'Teams'; ?></label>
                        <input type="number" class="form-control" name="teams_count" required min="1">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Qualifying <?php echo ($tournament['mode'] === 'Solo') ? 'Players' : 'Teams'; ?></label>
                        <input type="number" class="form-control" name="qualifying_teams" required min="1">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Map</label>
                        <input type="text" class="form-control" name="map_name" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Points per Kill</label>
                        <input type="number" class="form-control" name="kill_points" value="2" required min="1">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Qualification Bonus Points</label>
                        <input type="number" class="form-control" name="qualification_points" value="10" required min="0">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Special Rules</label>
                        <textarea class="form-control" name="special_rules" rows="3"></textarea>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" form="addRoundForm" class="btn btn-primary" <?php echo empty($tournament_days) ? 'disabled' : ''; ?>>
                    Add Round
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Round Modal -->
<div class="modal fade" id="editRoundModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Round</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editRoundForm" method="POST">
                    <input type="hidden" name="action" value="update_round">
                    <input type="hidden" name="id" id="edit_id">
                    <input type="hidden" name="tournament_id" value="<?php echo $tournament_id; ?>">
                    
                    <div class="mb-3">
                        <label class="form-label">Round Number</label>
                        <input type="number" class="form-control" name="round_number" id="edit_round_number" required min="1">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" class="form-control" name="name" id="edit_name" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Start Time</label>
                        <input type="time" class="form-control" name="start_time" id="edit_start_time" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Max <?php echo ($tournament['mode'] === 'Solo') ? 'Players' : 'Teams'; ?></label>
                        <input type="number" class="form-control" name="teams_count" id="edit_teams_count" required min="1">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Qualifying <?php echo ($tournament['mode'] === 'Solo') ? 'Players' : 'Teams'; ?></label>
                        <input type="number" class="form-control" name="qualifying_teams" id="edit_qualifying_teams" required min="1">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Map</label>
                        <input type="text" class="form-control" name="map_name" id="edit_map_name" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Points per Kill</label>
                        <input type="number" class="form-control" name="kill_points" id="edit_kill_points" required min="1">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Qualification Bonus Points</label>
                        <input type="number" class="form-control" name="qualification_points" id="edit_qualification_points" required min="0">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Special Rules</label>
                        <textarea class="form-control" name="special_rules" id="edit_special_rules" rows="3"></textarea>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status" id="edit_status">
                            <option value="upcoming">Upcoming</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" form="editRoundForm" class="btn btn-primary">Update Round</button>
            </div>
        </div>
    </div>
</div>

<!-- Add Room Details Modal -->
<div class="modal fade" id="roomDetailsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Manage Room Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="roomDetailsForm">
                    <input type="hidden" id="round_id" name="round_id">
                    <div class="mb-3">
                        <label for="room_code" class="form-label">Room Code</label>
                        <input type="text" class="form-control" id="room_code" name="room_code" required>
                    </div>
                    <div class="mb-3">
                        <label for="room_password" class="form-label">Room Password</label>
                        <input type="text" class="form-control" id="room_password" name="room_password" required>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="saveRoomDetails()">Save Room Details</button>
            </div>
        </div>
    </div>
</div>

<script>
function editRound(round) {
    document.getElementById('edit_id').value = round.id;
    document.getElementById('edit_round_number').value = round.round_number;
    document.getElementById('edit_name').value = round.name;
    document.getElementById('edit_description').value = round.description;
    document.getElementById('edit_start_time').value = round.start_time.substring(11, 16);
    document.getElementById('edit_teams_count').value = round.teams_count;
    document.getElementById('edit_qualifying_teams').value = round.qualifying_teams;
    document.getElementById('edit_map_name').value = round.map_name;
    document.getElementById('edit_kill_points').value = round.kill_points;
    document.getElementById('edit_qualification_points').value = round.qualification_points;
    document.getElementById('edit_special_rules').value = round.special_rules;
    document.getElementById('edit_status').value = round.status;

    new bootstrap.Modal(document.getElementById('editRoundModal')).show();
}

function deleteRound(roundId) {
    if (confirm('Are you sure you want to delete this round? This will also remove all participants assigned to this round.')) {
        // Create a form to submit the delete request
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'tournament-rounds.php?id=<?php echo $tournament_id; ?>';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'delete_round';
        form.appendChild(actionInput);
        
        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'id';
        idInput.value = roundId;
        form.appendChild(idInput);
        
        document.body.appendChild(form);
        form.submit();
    }
}

function manageRoomDetails(roundId, roundName) {
    const modal = new bootstrap.Modal(document.getElementById('roomDetailsModal'));
    document.querySelector('#roomDetailsModal .modal-title').textContent = `Manage Room Details - ${roundName}`;
    document.getElementById('round_id').value = roundId;
    
    // Fetch existing room details
    fetch(`../common/get_room_details.php?round_id=${roundId}`)
        .then(response => response.json())
        .then(data => {
            document.getElementById('room_code').value = data.room_code || '';
            document.getElementById('room_password').value = data.room_password || '';
        });
    
    modal.show();
}

function saveRoomDetails() {
    const formData = new FormData(document.getElementById('roomDetailsForm'));
    
    fetch('../common/save_room_details.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            bootstrap.Modal.getInstance(document.getElementById('roomDetailsModal')).hide();
            
            // Refresh the page to show updated status if requested
            if (data.refresh) {
                setTimeout(() => {
                    window.location.reload();
                }, 500);
            }
        } else {
            alert('Error saving room details: ' + data.message);
        }
    })
    .catch(error => {
        alert('Error saving room details');
        console.error(error);
    });
}
</script>

<?php include '../../includes/admin-footer.php'; ?>
