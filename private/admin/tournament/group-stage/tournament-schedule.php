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

// Get tournament ID from URL
$tournament_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$group_id = isset($_GET['group_id']) ? (int)$_GET['group_id'] : null;

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

// Default placement points based on game type
$default_placement_points = [
    'PUBG' => [
        1 => 15, 2 => 12, 3 => 10, 4 => 8,
        5 => 6, 6 => 4, 7 => 2, 8 => 1
    ],
    'BGMI' => [
        1 => 15, 2 => 12, 3 => 10, 4 => 8,
        5 => 6, 6 => 4, 7 => 2, 8 => 1
    ],
    'FREE FIRE' => [
        1 => 12, 2 => 9, 3 => 8, 4 => 7, 5 => 6,
        6 => 5, 7 => 4, 8 => 3, 9 => 2, 10 => 1
    ],
    'COD' => [
        1 => 15, 2 => 12, 3 => 10, 4 => 8, 5 => 6
    ]
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create_matches':
                    $group_id = $_POST['group_id'];
                    $match_date = $_POST['match_date'];
                    $match_times = $_POST['match_times'];
                    $maps = $_POST['maps'];
                    $kill_points = $_POST['kill_points'];
                    
                    // Handle custom placement points if provided
                    $placement_points = [];
                    if (isset($_POST['placement_points']) && is_array($_POST['placement_points']) && !empty($_POST['placement_points'])) {
                        foreach ($_POST['placement_points'] as $position => $points) {
                            if (!empty($points) && is_numeric($points) && $points > 0) {
                                $placement_points[(int)$position] = (int)$points;
                            }
                        }
                    }
                    
                    // If no custom placement points, use defaults
                    if (empty($placement_points)) {
                        $placement_points = isset($default_placement_points[$tournament['game_name']]) 
                            ? $default_placement_points[$tournament['game_name']]
                            : $default_placement_points['PUBG'];
                    }
                    
                    $placement_points_json = json_encode($placement_points);
                    
                    // Get group participants
                    if ($is_solo) {
                        $participants_data = $supabase->select('group_participants', 'user_id', [
                            'group_id' => $group_id,
                            'status' => 'active'
                        ]);
                        $participants = array_column($participants_data ?: [], 'user_id');
                    } else {
                        $participants_data = $supabase->select('group_teams', 'team_id', [
                            'group_id' => $group_id,
                            'status' => 'active'
                        ]);
                        $participants = array_column($participants_data ?: [], 'team_id');
                    }
                    
                    if (empty($participants)) {
                        throw new Exception('No participants found in selected group');
                    }
                    
                    // Create matches for each time slot
                    $match_count = 0;
                    foreach ($match_times as $index => $time) {
                        $match_datetime = $match_date . ' ' . $time . ':00';
                        $map = $maps[$index] ?? 'TBD';
                        
                        // Create match
                        $match_data = [
                            'tournament_id' => $tournament_id,
                            'group_id' => $group_id,
                            'match_name' => 'Group Match ' . ($index + 1),
                            'match_date' => $match_datetime,
                            'map_name' => $map,
                            'kill_points' => $kill_points,
                            'placement_points' => $placement_points_json,
                            'max_participants' => count($participants),
                            'status' => 'upcoming',
                            'created_at' => date('Y-m-d H:i:s')
                        ];
                        
                        $match_result = $supabase->insert('group_matches', $match_data, ['returning' => 'representation']);
                        
                        if ($match_result) {
                            $match_id = $match_result[0]['id'];
                            
                            // Add all participants to this match
                            foreach ($participants as $participant_id) {
                                if ($is_solo) {
                                    $supabase->insert('group_match_participants', [
                                        'match_id' => $match_id,
                                        'user_id' => $participant_id,
                                        'status' => 'registered',
                                        'joined_at' => date('Y-m-d H:i:s')
                                    ]);
                                } else {
                                    $supabase->insert('group_match_teams', [
                                        'match_id' => $match_id,
                                        'team_id' => $participant_id,
                                        'status' => 'registered',
                                        'joined_at' => date('Y-m-d H:i:s')
                                    ]);
                                }
                            }
                            $match_count++;
                        }
                    }
                    
                    $_SESSION['success'] = "$match_count matches created successfully!";
                    break;

                case 'update_match_status':
                    if (!isset($_POST['match_id']) || !isset($_POST['status'])) {
                        throw new Exception('Missing required fields for status update');
                    }
                    
                    $valid_statuses = ['upcoming', 'live', 'completed'];
                    if (!in_array($_POST['status'], $valid_statuses)) {
                        throw new Exception('Invalid status value');
                    }
                    
                    $supabase->update('group_matches', [
                        'status' => $_POST['status'],
                        'updated_at' => date('Y-m-d H:i:s')
                    ], ['id' => $_POST['match_id']]);
                    
                    $_SESSION['success'] = "Match status updated to " . ucfirst($_POST['status']) . " successfully!";
                    break;

                case 'delete_match':
                    $match_id = $_POST['match_id'];
                    
                    // Delete participants first
                    if ($is_solo) {
                        $supabase->delete('group_match_participants', ['match_id' => $match_id]);
                    } else {
                        $supabase->delete('group_match_teams', ['match_id' => $match_id]);
                    }
                    
                    // Then delete the match
                    $supabase->delete('group_matches', ['id' => $match_id]);
                    $_SESSION['success'] = "Match deleted successfully!";
                    break;
            }
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    
    $redirect_url = "tournament-schedule.php?id=" . $tournament_id;
    if ($group_id) {
        $redirect_url .= "&group_id=" . $group_id;
    }
    header("Location: " . $redirect_url);
    exit();
}

// Get tournament groups
$groups_data = $supabase->select('tournament_groups', '*', ['tournament_id' => $tournament_id], 'group_name.asc');
$groups = $groups_data ?: [];

// Get matches with participant counts
$matches_query_conditions = ['tournament_id' => $tournament_id];
if ($group_id) {
    $matches_query_conditions['group_id'] = $group_id;
}

$matches_data = $supabase->select('group_matches', '*', $matches_query_conditions, 'match_date.asc');
$matches = [];
if ($matches_data) {
    foreach ($matches_data as $match) {
        // Get group details
        if ($match['group_id']) {
            $group_data = $supabase->select('tournament_groups', 'group_name', ['id' => $match['group_id']], null, 1);
            $match['group_name'] = $group_data ? $group_data[0]['group_name'] : 'Unknown';
        }
        
        // Get participant count
        if ($is_solo) {
            $participants_data = $supabase->select('group_match_participants', 'id', ['match_id' => $match['id']]);
            $match['participant_count'] = count($participants_data ?: []);
        } else {
            $participants_data = $supabase->select('group_match_teams', 'id', ['match_id' => $match['id']]);
            $match['participant_count'] = count($participants_data ?: []);
        }
        
        $matches[] = $match;
    }
}

// Get available maps for this tournament's game
$available_maps = [];
if ($tournament) {
    $maps_data = $supabase->select('game_maps', '*', [
        'game_name' => $tournament['game_name'],
        'is_active' => true
    ], 'map_display_name.asc');
    $available_maps = $maps_data ?: [];
}

// Get selected group details if group_id is specified
$selected_group = null;
if ($group_id) {
    $group_data = $supabase->select('tournament_groups', '*', ['id' => $group_id], null, 1);
    $selected_group = $group_data ? $group_data[0] : null;
}

include '../../includes/admin-header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include '../../includes/admin-sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <div>
                    <h1>Group Stage Schedule</h1>
                    <h5 class="text-muted"><?php echo htmlspecialchars($tournament['name'] ?? ''); ?> (<?php echo htmlspecialchars($tournament['game_name'] ?? ''); ?>)</h5>
                    <?php if ($selected_group): ?>
                        <p class="text-info mb-0">Viewing: <?php echo htmlspecialchars($selected_group['group_name']); ?></p>
                    <?php endif; ?>
                </div>
                <div class="btn-group">
                    <?php if (!empty($groups)): ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createMatchesModal">
                            <i class="bi bi-plus-circle"></i> Create Matches
                        </button>
                    <?php endif; ?>
                    <a href="tournament-groups.php?id=<?php echo $tournament_id; ?>" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Groups
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

            <!-- Group Filter -->
            <?php if (!$group_id && !empty($groups)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="bi bi-funnel"></i> Filter by Group</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <select class="form-select" id="groupFilter" onchange="filterByGroup()">
                                    <option value="">All Groups</option>
                                    <?php foreach ($groups as $group): ?>
                                        <option value="<?php echo $group['id']; ?>" <?php echo ($group_id == $group['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($group['group_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <button class="btn btn-outline-secondary" onclick="clearFilter()">
                                    <i class="bi bi-x-circle"></i> Clear Filter
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Matches Overview -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title text-primary"><?php echo count($matches); ?></h5>
                            <p class="card-text">Total Matches</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title text-success"><?php echo count(array_filter($matches, fn($m) => $m['status'] === 'completed')); ?></h5>
                            <p class="card-text">Completed</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title text-warning"><?php echo count(array_filter($matches, fn($m) => $m['status'] === 'live')); ?></h5>
                            <p class="card-text">Live Now</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title text-info"><?php echo count(array_filter($matches, fn($m) => $m['status'] === 'upcoming')); ?></h5>
                            <p class="card-text">Upcoming</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Matches Table -->
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Match</th>
                            <th>Group</th>
                            <th>Date & Time</th>
                            <th>Map</th>
                            <th><?php echo $is_solo ? 'Players' : 'Teams'; ?></th>
                            <th>Points System</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($matches)): ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    <i class="bi bi-calendar-x"></i><br>
                                    No matches scheduled yet.
                                    <?php if (empty($groups)): ?>
                                        <br><a href="tournament-groups.php?id=<?php echo $tournament_id; ?>">Create groups first</a>.
                                    <?php else: ?>
                                        <br>Click "Create Matches" to schedule group matches.
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($matches as $match): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($match['match_name']); ?></strong>
                                </td>
                                <td>
                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($match['group_name'] ?? 'N/A'); ?></span>
                                </td>
                                <td>
                                    <?php echo date('M j, Y', strtotime($match['match_date'])); ?><br>
                                    <small class="text-muted"><?php echo date('H:i', strtotime($match['match_date'])); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($match['map_name']); ?></td>
                                <td>
                                    <span class="badge bg-info"><?php echo $match['participant_count']; ?></span>
                                    <br><small class="text-muted"><?php echo $is_solo ? 'Players' : 'Teams'; ?></small>
                                </td>
                                <td>
                                    <small>
                                        Kills: <?php echo $match['kill_points']; ?> pts<br>
                                        Placement: <?php 
                                            $placement_points = json_decode($match['placement_points'], true);
                                            echo !empty($placement_points) ? 'Yes' : 'No'; 
                                        ?>
                                    </small>
                                </td>
                                <td>
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
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <div class="dropdown">
                                            <button class="btn btn-outline-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown">
                                                <i class="bi bi-gear"></i>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li><h6 class="dropdown-header">Status</h6></li>
                                                <li><a class="dropdown-item" href="#" onclick="updateMatchStatus(<?php echo $match['id']; ?>, 'upcoming')">Mark Upcoming</a></li>
                                                <li><a class="dropdown-item" href="#" onclick="updateMatchStatus(<?php echo $match['id']; ?>, 'live')">Mark Live</a></li>
                                                <li><a class="dropdown-item" href="#" onclick="updateMatchStatus(<?php echo $match['id']; ?>, 'completed')">Mark Completed</a></li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item text-danger" href="#" onclick="deleteMatch(<?php echo $match['id']; ?>, '<?php echo htmlspecialchars($match['match_name']); ?>')">Delete Match</a></li>
                                            </ul>
                                        </div>
                                        <a href="tournament-scoring.php?match_id=<?php echo $match['id']; ?>" class="btn btn-outline-success btn-sm" title="Manage Results">
                                            <i class="bi bi-trophy"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>

<!-- Create Matches Modal -->
<div class="modal fade" id="createMatchesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create Group Matches</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="createMatchesForm">
                <input type="hidden" name="action" value="create_matches">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Select Group</label>
                            <select class="form-select" name="group_id" required onchange="updateGroupInfo(this)">
                                <option value="">Choose a group</option>
                                <?php foreach ($groups as $group): ?>
                                    <option value="<?php echo $group['id']; ?>" <?php echo ($group_id == $group['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($group['group_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Match Date</label>
                            <input type="date" class="form-control" name="match_date" 
                                   min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Kill Points per Kill</label>
                            <input type="number" class="form-control" name="kill_points" value="2" min="0" max="10" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Number of Matches</label>
                            <select class="form-select" id="matchCount" onchange="updateMatchInputs()">
                                <option value="1">1 Match</option>
                                <option value="2" selected>2 Matches</option>
                                <option value="3">3 Matches</option>
                                <option value="4">4 Matches</option>
                                <option value="5">5 Matches</option>
                            </select>
                        </div>
                    </div>

                    <!-- Dynamic Match Time and Map inputs -->
                    <div id="matchInputs">
                        <!-- Will be populated by JavaScript -->
                    </div>

                    <div class="alert alert-info">
                        <strong>Game:</strong> <?php echo $tournament['game_name']; ?> - 
                        <strong>Available Maps:</strong> <?php echo count($available_maps); ?> maps available<br>
                        <strong>Placement Points:</strong> Default points will be applied based on your game type.
                        You can customize them after creating matches if needed.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Matches</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function filterByGroup() {
    const groupId = document.getElementById('groupFilter').value;
    const url = new URL(window.location);
    if (groupId) {
        url.searchParams.set('group_id', groupId);
    } else {
        url.searchParams.delete('group_id');
    }
    window.location.href = url.toString();
}

function clearFilter() {
    const url = new URL(window.location);
    url.searchParams.delete('group_id');
    window.location.href = url.toString();
}

// Available maps data from PHP
const availableMaps = <?php echo json_encode($available_maps); ?>;

function updateMatchInputs() {
    const count = parseInt(document.getElementById('matchCount').value);
    const container = document.getElementById('matchInputs');
    let html = '<div class="mb-3"><label class="form-label">Match Times and Maps</label></div>';
    
    for (let i = 1; i <= count; i++) {
        // Build map options
        let mapOptions = '<option value="">Select Map</option>';
        availableMaps.forEach(function(map) {
            const selected = (i === 1 && map.map_name === 'erangel') ? 'selected' : '';
            mapOptions += `<option value="${map.map_display_name}" ${selected}>${map.map_display_name}</option>`;
        });
        
        html += `
            <div class="row mb-2">
                <div class="col-md-6">
                    <label class="form-label small">Match ${i} Time</label>
                    <input type="time" class="form-control" name="match_times[]" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label small">Match ${i} Map</label>
                    <select class="form-select" name="maps[]" required>
                        ${mapOptions}
                    </select>
                </div>
            </div>
        `;
    }
    
    container.innerHTML = html;
}

function updateMatchStatus(matchId, status) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.style.display = 'none';
    
    form.innerHTML = `
        <input type="hidden" name="action" value="update_match_status">
        <input type="hidden" name="match_id" value="${matchId}">
        <input type="hidden" name="status" value="${status}">
    `;
    
    document.body.appendChild(form);
    form.submit();
}

function deleteMatch(matchId, matchName) {
    if (confirm(`Are you sure you want to delete "${matchName}"? This will also delete all participant data for this match.`)) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';
        
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_match">
            <input type="hidden" name="match_id" value="${matchId}">
        `;
        
        document.body.appendChild(form);
        form.submit();
    }
}

// Initialize match inputs on page load
document.addEventListener('DOMContentLoaded', function() {
    updateMatchInputs();
});
</script>

</body>
</html>
