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
        1 => 15, // 1st place: 15 points
        2 => 12, // 2nd place: 12 points
        3 => 10, // 3rd place: 10 points
        4 => 8,  // 4th place: 8 points
        5 => 6,  // 5th place: 6 points
        6 => 4,  // 6th place: 4 points
        7 => 2,  // 7th place: 2 points
        8 => 1   // 8th place: 1 point
    ],
    'BGMI' => [
        1 => 15,
        2 => 12,
        3 => 10,
        4 => 8,
        5 => 6,
        6 => 4,
        7 => 2,
        8 => 1
    ],
    'Free Fire' => [
        1 => 12,
        2 => 9,
        3 => 8,
        4 => 7,
        5 => 6,
        6 => 5,
        7 => 4,
        8 => 3,
        9 => 2,
        10 => 1
    ],
    'Call of Duty Mobile' => [
        1 => 15,
        2 => 12,
        3 => 10,
        4 => 8,
        5 => 6
    ]
];

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add_day':
                    // Add new tournament day
                    $day_data = $supabase->insert('tournament_days', [
                        'tournament_id' => $tournament_id,
                        'day_number' => $_POST['day_number'],
                        'date' => $_POST['date']
                    ], ['returning' => 'representation']);
                    $day_id = $day_data[0]['id'];

                    // Add rounds for this day
                    $total_teams = (int)$_POST['total_teams'];
                    $rounds_count = (int)$_POST['rounds_count'];
                    $teams_per_round = ceil($total_teams / $rounds_count);

                    // Handle custom placement points if provided
                    $placement_points = [];
                    
                    // Check if custom placement points were submitted
                    if (isset($_POST['placement_points']) && is_array($_POST['placement_points']) && !empty($_POST['placement_points'])) {
                        // Use custom placement points from the form
                        foreach ($_POST['placement_points'] as $position => $points) {
                            if (!empty($points) && is_numeric($points) && $points > 0) {
                                $placement_points[(int)$position] = (int)$points;
                            }
                        }
                    }
                    
                    // If no custom placement points or tournament has less than 30 participants, use appropriate defaults
                    if (empty($placement_points)) {
                        if ($total_teams < 30) {
                            // For small tournaments, set all placement points to 0 (disabled)
                            $placement_points = [];
                        } else {
                            // Use default placement points for the game
                            $placement_points = isset($default_placement_points[$tournament['game_name']]) 
                                ? $default_placement_points[$tournament['game_name']]
                                : $default_placement_points['PUBG'];
                        }
                    }
                    
                    $placement_points_json = json_encode($placement_points);

                    for ($i = 1; $i <= $rounds_count; $i++) {
                        // Combine date and time to create proper timestamp for Supabase
                        $start_datetime = $_POST['date'] . ' ' . $_POST['start_time_' . $i] . ':00';
                        
                        $supabase->insert('tournament_rounds', [
                            'tournament_id' => $tournament_id,
                            'day_id' => $day_id,
                            'round_number' => $i,
                            'name' => "Round " . $i,
                            'start_time' => $start_datetime,
                            'teams_count' => $teams_per_round,
                            'qualifying_teams' => $_POST['qualifying_teams'],
                            'round_format' => 'points',
                            'map_name' => $_POST['map_name_' . $i],
                            'kill_points' => $_POST['kill_points'],
                            'placement_points' => $placement_points_json,
                            'qualification_points' => $_POST['qualification_points']
                        ]);
                    }
                    
                    // Generate success message with placement points info
                    $success_message = "Tournament day and rounds added successfully!";
                    if ($total_teams < 30) {
                        $success_message .= " (Placement points disabled for tournaments with less than 30 participants)";
                    } elseif (!empty($placement_points)) {
                        $success_message .= " (Custom placement points configured)";
                    }
                    
                    $_SESSION['success'] = $success_message;
                    break;

                case 'update_status':
                    // Handle status update via form submission
                    if (!isset($_POST['round_id']) || !isset($_POST['status'])) {
                        throw new Exception('Missing required fields for status update');
                    }
                    
                    $valid_statuses = ['upcoming', 'in_progress', 'completed'];
                    if (!in_array($_POST['status'], $valid_statuses)) {
                        throw new Exception('Invalid status value');
                    }
                    
                    // Helper function for consistent datetime formatting
                    function formatAdminDateTime() {
                        $dateTime = new DateTime();
                        return $dateTime->format('Y-m-d H:i:s');
                    }
                    
                    // Update round status
                    $supabase->update('tournament_rounds', [
                        'status' => $_POST['status'],
                        'updated_at' => formatAdminDateTime()
                    ], ['id' => $_POST['round_id']]);
                    
                    $_SESSION['success'] = "Round status updated to " . ucfirst($_POST['status']) . " successfully!";
                    break;

            }
        }

    } catch (Exception $e) {
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    header("Location: tournament-schedule.php?id=" . $tournament_id);
    exit();
}

// Get tournament days and rounds with all necessary information
$days_data = $supabase->select('tournament_days', '*', ['tournament_id' => $tournament_id], 'day_number.asc');
$tournament_days = [];
if ($days_data) {
    foreach ($days_data as $day) {
        // Get rounds for this day
        $rounds_data = $supabase->select('tournament_rounds', '*', ['day_id' => $day['id']], 'round_number.asc');
        $day['rounds'] = [];
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
                $day['rounds'][] = $round;
            }
        }
        $tournament_days[] = $day;
    }
}

// Also get all rounds for backward compatibility
$rounds_data = $supabase->select('tournament_rounds', '*', ['tournament_id' => $tournament_id], 'round_number.asc');
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

// Get the latest day number
$last_day_data = $supabase->select('tournament_days', 'day_number', ['tournament_id' => $tournament_id], 'day_number.desc', 1);
$lastDay = !empty($last_day_data) ? $last_day_data[0] : null;
$nextDayNumber = ($lastDay['day_number'] ?? 0) + 1;


// ALWAYS get total registered participants for placement points determination (Option A)
// This ensures consistent placement point system throughout the tournament
if ($is_solo) {
    $registered_teams_data = $supabase->select('tournament_registrations', 'id', ['tournament_id' => $tournament_id, 'status' => 'approved', 'user_id' => ['not.is', null]]);
    $totalRegisteredParticipants = count($registered_teams_data);
} else {
    $registered_teams_data = $supabase->select('tournament_registrations', 'id', ['tournament_id' => $tournament_id, 'status' => 'approved', 'team_id' => ['not.is', null]]);
    $totalRegisteredParticipants = count($registered_teams_data);
}

// Get qualified teams from previous day for day planning (separate from placement points logic)
$qualified_teams_query = "
    SELECT rt.* 
    FROM round_teams rt 
    JOIN tournament_rounds tr ON rt.round_id = tr.id 
    WHERE tr.tournament_id = {$tournament_id} AND rt.status = 'qualified'
";
try {
    $qualified_teams_data = $supabase->query($qualified_teams_query);
    $qualifiedTeams = count($qualified_teams_data);
} catch (Exception $e) {
    $qualifiedTeams = 0;
}

// Determine current available participants for day planning
if ($nextDayNumber === 1) {
    // Day 1: Use all registered participants
    $totalTeams = $totalRegisteredParticipants;
} else {
    // Day 2+: Use qualified participants from previous rounds
    $totalTeams = $qualifiedTeams ?? 0;
}

// Get all registered teams/players for selection
            $allParticipants = [];
            if ($is_solo) {
                // Solo tournament: get user registrations
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
                // Team tournament: get team registrations
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

// Get participants for each round
$roundParticipants = [];
if ($rounds) {
    foreach ($rounds as $round) {
        if ($is_solo) {
            // For solo tournaments, get participants from solo_tournament_participants
            $solo_participants = $supabase->select('solo_tournament_participants', 'user_id', ['round_id' => $round['id']]);
            $roundParticipants[$round['id']] = $solo_participants ?: [];
        } else {
            // For team tournaments, use existing round_teams
            $roundParticipants[$round['id']] = $round['round_teams'];
        }
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
                    <h1>Tournament Schedule</h1>
                    <h5 class="text-muted"><?php echo htmlspecialchars($tournament['name'] ?? ''); ?> (<?php echo htmlspecialchars($tournament['game_name'] ?? ''); ?>)</h5>
                </div>
                <div class="btn-group">
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDayModal">
                        <i class="bi bi-plus-circle"></i> Add Tournament Day
                    </button>
                    <button class="btn btn-warning" onclick="fixRoundTeamsCounts()" title="Fix rounds with incorrect team limits">
                        <i class="bi bi-wrench"></i> Fix Team Limits
                    </button>
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
                        <?php 
                        $hasContent = false;
                        if ($tournament_days): 
                            foreach ($tournament_days as $day): 
                                if ($day['rounds']): 
                                    $hasContent = true;
                        ?>
                            <tr>
                                <td colspan="8" class="fw-bold bg-light">Day <?php echo htmlspecialchars($day['day_number'] ?? ''); ?> - <?php echo htmlspecialchars($day['date'] ?? ''); ?></td>
                            </tr>
                            <?php foreach ($day['rounds'] as $round): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($round['round_number'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($round['name'] ?? ''); ?></td>
                                    <td><?php 
                                        if ($round['start_time']) {
                                            try {
                                                $dt = new DateTime($round['start_time']);
                                                echo $dt->format('H:i');
                                            } catch (Exception $e) {
                                                echo date('H:i', strtotime($round['start_time']));
                                            }
                                        }
                                    ?></td>
                                    <td><?php echo htmlspecialchars($round['map_name'] ?? ''); ?></td>
                                    <td>
                                        <?php if (isset($round['teams_count'])): ?>
                                            <?php echo count($round['round_teams']); ?> / <?php echo (int)$round['teams_count']; ?>
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
                                        <?php if (isset($round['status'])): ?>
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
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (isset($round['id'])): ?>
                                            <button class="btn btn-sm btn-primary" onclick="assignTeams(<?php echo htmlspecialchars(json_encode($round)); ?>)">
                                                <i class="bi bi-people"></i>
                                            </button>
                                            <a href="tournament-scoring.php?round_id=<?php echo $round['id']; ?>" class="btn btn-sm btn-success" title="Update Scores">
                                                <i class="bi bi-trophy"></i>
                                            </a>
                                            <button class="btn btn-sm btn-info" onclick="updateStatus(<?php echo $round['id']; ?>, '<?php echo htmlspecialchars($round['status']); ?>')">
                                                <i class="bi bi-arrow-clockwise"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php 
                                endif;
                            endforeach; 
                        endif;
                        
                        // Fallback: show standalone rounds if no days with rounds exist
                        if (!$hasContent && $rounds):
                            foreach ($rounds as $round): 
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($round['round_number'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($round['name'] ?? ''); ?></td>
                                <td><?php 
                                    if ($round['start_time']) {
                                        try {
                                            $dt = new DateTime($round['start_time']);
                                            echo $dt->format('H:i');
                                        } catch (Exception $e) {
                                            echo date('H:i', strtotime($round['start_time']));
                                        }
                                    }
                                ?></td>
                                <td><?php echo htmlspecialchars($round['map_name'] ?? ''); ?></td>
                                <td>
                                    <?php if (isset($round['teams_count'])): ?>
                                        <?php echo count($round['round_teams']); ?> / <?php echo (int)$round['teams_count']; ?>
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
                                    <?php if (isset($round['status'])): ?>
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
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (isset($round['id'])): ?>
                                        <button class="btn btn-sm btn-primary" onclick="assignTeams(<?php echo htmlspecialchars(json_encode($round)); ?>)">
                                            <i class="bi bi-people"></i>
                                        </button>
                                        <a href="tournament-scoring.php?round_id=<?php echo $round['id']; ?>" class="btn btn-sm btn-success" title="Update Scores">
                                            <i class="bi bi-trophy"></i>
                                        </a>
                                        <button class="btn btn-sm btn-info" onclick="updateStatus(<?php echo $round['id']; ?>, '<?php echo htmlspecialchars($round['status']); ?>')">
                                            <i class="bi bi-arrow-clockwise"></i>
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php 
                            endforeach;
                        endif;
                        
                        // Show message if no content
                        if (!$hasContent && !$rounds): 
                        ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    No tournament days or rounds scheduled yet. Click "Add Tournament Day" to get started.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>

<!-- Add Day Modal -->
<div class="modal fade" id="addDayModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add Tournament Day <?php echo $nextDayNumber; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="addDayForm" method="POST">
                    <input type="hidden" name="action" value="add_day">
                    <input type="hidden" name="day_number" value="<?php echo $nextDayNumber; ?>">
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Date</label>
                            <input type="date" class="form-control" name="date" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Total <?php echo $is_solo ? 'Players' : 'Teams'; ?></label>
                            <input type="number" class="form-control" name="total_teams" value="<?php echo $totalTeams; ?>" readonly>
                            <small class="text-muted">
                                <?php if ($nextDayNumber === 1): ?>
                                    Total registered <?php echo $is_solo ? 'players' : 'teams'; ?>
                                <?php else: ?>
                                    <?php echo $is_solo ? 'Players' : 'Teams'; ?> qualified from Day <?php echo $nextDayNumber - 1; ?>
                                <?php endif; ?>
                            </small>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Number of Rounds</label>
                            <input type="number" class="form-control" name="rounds_count" required min="1" onchange="generateRoundInputs(this.value, <?php echo $totalTeams; ?>)">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Qualifying <?php echo $is_solo ? 'Players' : 'Teams'; ?> per Round</label>
                            <input type="number" class="form-control" name="qualifying_teams" required min="1">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Points per Kill</label>
                            <input type="number" class="form-control" name="kill_points" value="2" required min="1">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Qualification Bonus Points</label>
                            <input type="number" class="form-control" name="qualification_points" value="10" required min="0">
                        </div>

                        <!-- Dynamic Placement Points System -->
                        <div class="col-12" id="placementPointsSection">
                            <div class="card border-info">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0"><i class="bi bi-trophy me-2"></i>Placement Points System</h6>
                                </div>
                                <div class="card-body">
                                    <div id="placementSystemMessage" class="alert alert-info">
                                        <i class="bi bi-info-circle"></i> 
                                        <span id="participantCountMessage">
                                            Total Participants: <strong><?php echo $totalTeams; ?></strong> <?php echo $is_solo ? 'players' : 'teams'; ?>
                                        </span>
                                    </div>
                                    
                                    <div id="placementPointsContainer">
                                        <?php if ($totalTeams < 30): ?>
                                            <div class="alert alert-warning">
                                                <i class="bi bi-exclamation-triangle"></i>
                                                <strong>No Placement Points:</strong> With less than 30 <?php echo $is_solo ? 'players' : 'teams'; ?>, only kill points and qualification bonuses will be used.
                                            </div>
                                        <?php else: ?>
                                            <div class="mb-3">
                                                <label class="form-label">Placement Points Configuration</label>
                                                <small class="text-muted d-block">Set points for each placement position (leave 0 for no points)</small>
                                            </div>
                                            
                                            <div class="row g-2" id="placementInputsContainer">
                                                <!-- Placement inputs will be generated based on participant count -->
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="roundsContainer" class="col-12">
                            <!-- Round inputs will be generated here -->
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" form="addDayForm" class="btn btn-primary">Add Day</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Round Teams Modal -->
<div class="modal fade" id="editRoundTeamsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Round <?php echo $is_solo ? 'Players' : 'Teams'; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="editRoundTeamsForm" method="POST" action="update_round_teams.php">
                    <input type="hidden" name="round_id" id="edit_round_id">
                    <input type="hidden" name="tournament_id" value="<?php echo $tournament_id; ?>">

                    <div class="mb-3">
                        <label class="form-label">Select <?php echo $is_solo ? 'Players' : 'Teams'; ?> for this Round</label>
                        <div class="row" id="participantSelectionContainer">
                            <!-- Participants will be loaded here -->
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" form="editRoundTeamsForm" class="btn btn-primary">Save <?php echo $is_solo ? 'Players' : 'Teams'; ?></button>
            </div>
        </div>
    </div>
</div>


<!-- Status Update Modal -->
<div class="modal fade" id="updateStatusModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Update Round Status</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="updateStatusForm">
                    <input type="hidden" name="round_id" id="status_round_id">
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status" id="status_select">
                            <option value="upcoming">Upcoming</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveStatus()">Save Status</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function generateRoundInputs(count, totalTeams) {
    const container = document.getElementById('roundsContainer');
    container.innerHTML = '';
    const teamsPerRound = Math.ceil(totalTeams / count);

    for (let i = 1; i <= count; i++) {
        container.innerHTML += `
            <div class="row g-3 mb-3">
                <div class="col-12">
                    <h5>Round ${i}</h5>
                    <small class="text-muted">Recommended teams: ${teamsPerRound}</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Start Time</label>
                    <input type="time" class="form-control" name="start_time_${i}" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Map</label>
                    <input type="text" class="form-control" name="map_name_${i}" required>
                </div>
            </div>
        `;
    }
    
    // Generate placement points inputs if enough participants
    generatePlacementPointsInputs(totalTeams);
}

// Function to generate placement points inputs based on participant count
function generatePlacementPointsInputs(totalParticipants) {
    const container = document.getElementById('placementInputsContainer');
    
    if (totalParticipants < 30) {
        // Hide placement points section for small tournaments
        document.getElementById('placementPointsContainer').innerHTML = `
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                <strong>No Placement Points:</strong> With less than 30 participants, only kill points and qualification bonuses will be used.
            </div>
        `;
        return;
    }
    
    // Show placement points configuration
    document.getElementById('placementPointsContainer').innerHTML = `
        <div class="mb-3">
            <label class="form-label">Placement Points Configuration</label>
            <small class="text-muted d-block">Set points for each placement position (leave 0 for no points)</small>
        </div>
        
        <div class="row g-2" id="placementInputsContainer">
            <!-- Placement inputs will be generated here -->
        </div>
        
        <div class="mt-3">
            <button type="button" class="btn btn-sm btn-secondary" onclick="setDefaultPlacementPoints()">Load Default Points</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearAllPlacementPoints()">Clear All</button>
        </div>
    `;
    
    const placementContainer = document.getElementById('placementInputsContainer');
    
    // Determine how many placement positions to show based on participant count
    let placementPositions;
    if (totalParticipants >= 200) {
        placementPositions = 20; // Top 20 for very large tournaments
    } else if (totalParticipants >= 100) {
        placementPositions = 15; // Top 15 for large tournaments
    } else if (totalParticipants >= 50) {
        placementPositions = 10; // Top 10 for medium tournaments
    } else {
        placementPositions = 8; // Top 8 for smaller tournaments
    }
    
    // Generate placement inputs
    let placementHtml = '';
    for (let pos = 1; pos <= placementPositions; pos++) {
        const suffix = getOrdinalSuffix(pos);
        placementHtml += `
            <div class="col-md-3 col-sm-4 col-6">
                <label class="form-label">${pos}${suffix} Place</label>
                <input type="number" class="form-control placement-input" 
                       name="placement_points[${pos}]" 
                       id="placement_${pos}" 
                       min="0" 
                       placeholder="0">
            </div>
        `;
    }
    
    placementContainer.innerHTML = placementHtml;
}

// Helper function to get ordinal suffix (1st, 2nd, 3rd, etc.)
function getOrdinalSuffix(num) {
    const j = num % 10;
    const k = num % 100;
    if (j == 1 && k != 11) {
        return 'st';
    }
    if (j == 2 && k != 12) {
        return 'nd';
    }
    if (j == 3 && k != 13) {
        return 'rd';
    }
    return 'th';
}

// Function to set default placement points
function setDefaultPlacementPoints() {
    const defaultPoints = {
        1: 15, 2: 12, 3: 10, 4: 8, 5: 6, 6: 4, 7: 2, 8: 1,
        9: 1, 10: 1, 11: 0, 12: 0, 13: 0, 14: 0, 15: 0,
        16: 0, 17: 0, 18: 0, 19: 0, 20: 0
    };
    
    Object.keys(defaultPoints).forEach(position => {
        const input = document.getElementById(`placement_${position}`);
        if (input) {
            input.value = defaultPoints[position];
        }
    });
}

// Function to clear all placement points
function clearAllPlacementPoints() {
    const inputs = document.querySelectorAll('.placement-input');
    inputs.forEach(input => {
        input.value = '';
    });
}

// Initialize placement points when page loads
document.addEventListener('DOMContentLoaded', function() {
    const totalTeams = <?php echo $totalTeams; ?>;
    generatePlacementPointsInputs(totalTeams);
});

function assignTeams(round) {
    if (!round) return;
    
    document.getElementById('edit_round_id').value = round.id;

    // Load teams for selection
    fetch(`../common/get_available_teams.php?round_id=${round.id}&tournament_id=<?php echo $tournament_id; ?>`)
        .then(response => response.json())
        .then(data => {
            console.log('Response data:', data); // Debug log
            
            if (data.error || !data.success) {
                console.error('API Error:', data.error || 'Unknown error');
                alert('Error: ' + (data.error || 'Failed to load participants'));
                return;
            }
            
            const container = document.getElementById('participantSelectionContainer');
            
            if (!data.teams || data.teams.length === 0) {
                container.innerHTML = '<div class="col-12"><p class="text-muted">No participants available for this round.</p></div>';
                return;
            }
            
            // Separate selected and available participants
            const selectedParticipants = data.teams.filter(team => team.is_selected);
            const availableParticipants = data.teams.filter(team => !team.is_selected);
            
            // Add team selection limit info
            const maxTeams = round.teams_count || 100; // Fallback to 100 if not set
            const infoHtml = `
                <div class="col-12 mb-3">
                    <div class="alert alert-info">
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Selection Limit:</strong> <span id="maxTeamsLimit">${maxTeams}</span> ${data.is_solo ? 'players' : 'teams'}
                            </div>
                            <div class="col-md-6">
                                <strong>Currently Selected:</strong> <span id="selectedCount">${selectedParticipants.length}</span> / <span id="maxTeamsLimit2">${maxTeams}</span>
                            </div>
                        </div>
                        <div class="progress mt-2" style="height: 8px;">
                            <div class="progress-bar" role="progressbar" style="width: ${(selectedParticipants.length / maxTeams) * 100}%" id="selectionProgress"></div>
                        </div>
                    </div>
                </div>
            `;
            
            // Build selected participants section
            let selectedHtml = '';
            if (selectedParticipants.length > 0) {
        selectedHtml = `
                    <div class="col-12 mb-3">
                        <div class="card border-success">
                            <div class="card-header bg-success text-white">
                                <h6 class="mb-0"><i class="bi bi-check-circle-fill me-2"></i>Currently Selected ${data.is_solo ? 'Players' : 'Teams'} (${selectedParticipants.length})</h6>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="50"><input type="checkbox" id="selectAllSelected" ${selectedParticipants.length > 0 ? 'checked' : ''}></th>
                                                <th>${data.is_solo ? 'Player Name' : 'Team Name'}</th>
                                                <th width="100">Status</th>
                                                <th width="80">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${selectedParticipants.map(team => {
                                                const teamIdForDeletion = data.is_solo ? (team.team_id || team.id) : team.id;
                                                return `
                                                <tr class="table-success">
                                                    <td>
                                                        <input class="form-check-input team-checkbox" type="checkbox" 
                                                            name="selected_teams[]" 
                                                            value="${team.id}" 
                                                            id="team_${team.id}"
                                                            data-max-teams="${maxTeams}"
                                                            checked>
                                                    </td>
                                                    <td>
                                                        <strong class="text-success">
                                                            <i class="bi bi-person-check-fill me-1"></i>${team.name}
                                                        </strong>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-success">Selected</span>
                                                    </td>
                                                    <td>
                                                        <button type="button" class="btn btn-sm btn-outline-danger" 
                                                                onclick="removeTeamFromRound(${round.id}, ${teamIdForDeletion}, '${team.name}')" 
                                                                title="Remove from round">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </td>
                                                </tr>
                                            `}).join('')}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }
            
            // Build available participants section
            let availableHtml = '';
            if (availableParticipants.length > 0) {
                availableHtml = `
                    <div class="col-12 mb-3">
                        <div class="card border-primary">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="bi bi-person-plus-fill me-2"></i>Available ${data.is_solo ? 'Players' : 'Teams'} (${availableParticipants.length})</h6>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-sm mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th width="50"><input type="checkbox" id="selectAllAvailable"></th>
                                                <th>${data.is_solo ? 'Player Name' : 'Team Name'}</th>
                                                <th width="100">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${availableParticipants.map(team => `
                                                <tr>
                                                    <td>
                                                        <input class="form-check-input team-checkbox" type="checkbox" 
                                                            name="selected_teams[]" 
                                                            value="${team.id}" 
                                                            id="team_${team.id}"
                                                            data-max-teams="${maxTeams}">
                                                    </td>
                                                    <td>
                                                        <i class="bi bi-person me-1"></i>${team.name}
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-secondary">Available</span>
                                                    </td>
                                                </tr>
                                            `).join('')}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            } else if (selectedParticipants.length === 0) {
                availableHtml = `
                    <div class="col-12 mb-3">
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i> No ${data.is_solo ? 'players' : 'teams'} available for selection.
                        </div>
                    </div>
                `;
            }
            
            // Add no available participants message if all are selected
            if (availableParticipants.length === 0 && selectedParticipants.length > 0) {
                availableHtml = `
                    <div class="col-12 mb-3">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> All available ${data.is_solo ? 'players' : 'teams'} have been selected for this round.
                        </div>
                    </div>
                `;
            }
            
            container.innerHTML = infoHtml + selectedHtml + availableHtml;
            
            // Add event listeners for checkbox validation
            const checkboxes = container.querySelectorAll('.team-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const selectedCount = container.querySelectorAll('.team-checkbox:checked').length;
                    const selectedCountElement = document.getElementById('selectedCount');
                    if (selectedCountElement) {
                        selectedCountElement.textContent = selectedCount;
                    }
                    
                    // Update progress bar
                    const progressBar = document.getElementById('selectionProgress');
                    if (progressBar) {
                        const progressPercent = (selectedCount / maxTeams) * 100;
                        progressBar.style.width = progressPercent + '%';
                        
                        // Change color based on progress
                        progressBar.className = 'progress-bar';
                        if (progressPercent >= 100) {
                            progressBar.classList.add('bg-success');
                        } else if (progressPercent >= 75) {
                            progressBar.classList.add('bg-warning');
                        } else {
                            progressBar.classList.add('bg-primary');
                        }
                    }
                    
                    // If trying to select more than allowed, prevent it
                    if (this.checked && selectedCount > maxTeams) {
                        this.checked = false;
                        alert(`Maximum ${maxTeams} ${data.is_solo ? 'players' : 'teams'} can be selected for this round.`);
                        const selectedCountEl = document.getElementById('selectedCount');
                        if (selectedCountEl) {
                            selectedCountEl.textContent = selectedCount - 1;
                        }
                        
                        // Reset progress bar
                        if (progressBar) {
                            const correctedPercent = ((selectedCount - 1) / maxTeams) * 100;
                            progressBar.style.width = correctedPercent + '%';
                        }
                        return;
                    }
                    
                    // Update submit button state
                    const submitBtn = document.querySelector('#editRoundTeamsForm button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.disabled = selectedCount > maxTeams;
                    }
                });
            });
            
            // Update initial selected count
            const initialSelected = container.querySelectorAll('.team-checkbox:checked').length;
            const selectedCountElement = document.getElementById('selectedCount');
            if (selectedCountElement) {
                selectedCountElement.textContent = initialSelected;
            }
        })
        .catch(error => {
            console.error('Fetch Error:', error);
            alert('Failed to load participants. Please check the console for details.');
        });

    // Show the modal
    new bootstrap.Modal(document.getElementById('editRoundTeamsModal')).show();
}

// Add form submission handler with debugging
console.log('Attempting to attach form submission handler...');
const editFormElement = document.getElementById('editRoundTeamsForm');
console.log('Form element found:', editFormElement);

if (editFormElement) {
    editFormElement.addEventListener('submit', function(e) {
        console.log('FORM SUBMIT EVENT TRIGGERED!');
        e.preventDefault();
        
        const formData = new FormData(this);
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn ? submitBtn.textContent : 'Save';
        
        // Debug: log form data
        console.log('Form submission started');
        console.log('Submit button found:', submitBtn);
        for (let [key, value] of formData.entries()) {
            console.log('FormData:', key, value);
        }
        
        // Show loading state
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Saving...';
        }
        
        fetch('../common/update_round_teams.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            // Restore button state
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }
            
            if (data.error) {
                // Show styled error message
                showNotification('error', 'Error updating teams', data.error);
                return;
            }
            
            // Close the modal
            bootstrap.Modal.getInstance(document.getElementById('editRoundTeamsModal')).hide();
            
            // Show detailed success message
            const participantType = data.is_solo ? 'Players' : 'Teams';
            let successMessage = `${participantType} updated successfully!`;
            if (data.details) {
                const details = [];
                if (data.details.teams_added > 0) {
                    const participantLabel = data.is_solo ? 'player(s)' : 'team(s)';
                    details.push(`${data.details.teams_added} ${participantLabel} added`);
                }
                if (data.details.teams_removed > 0) {
                    const participantLabel = data.is_solo ? 'player(s)' : 'team(s)';
                    details.push(`${data.details.teams_removed} ${participantLabel} removed`);
                }
                if (details.length > 0) {
                    successMessage += '\n\n' + details.join(', ');
                }
            }
            
            showNotification('success', 'Success', successMessage);
            
            // Reload after a short delay to show the notification
            setTimeout(() => {
                window.location.reload();
            }, 1500);
        })
        .catch(error => {
            // Restore button state
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }
            
            console.error('Error:', error);
            showNotification('error', 'Network Error', 'Failed to update teams. Please check your connection and try again.');
        });
    });
} else {
    console.log('Form element not found! Cannot attach event listener.');
}


function updateStatus(roundId, currentStatus) {
    document.getElementById('status_round_id').value = roundId;
    document.getElementById('status_select').value = currentStatus;
    new bootstrap.Modal(document.getElementById('updateStatusModal')).show();
}

function saveStatus() {
    const roundId = document.getElementById('status_round_id').value;
    const status = document.getElementById('status_select').value;

    fetch('../common/update_round_status.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            round_id: roundId,
            status: status
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.reload(); // Reload to show updated status
        } else {
            alert('Error updating status: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Failed to update status. Please try again.');
    });
}

// Function to fix round team counts
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

// Function to remove individual team from round
function removeTeamFromRound(roundId, teamId, teamName) {
    if (!confirm(`Are you sure you want to remove "${teamName}" from this round?`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('round_id', roundId);
    formData.append('team_id', teamId);
    formData.append('tournament_id', <?php echo $tournament_id; ?>);
    
    fetch('remove_team_from_round.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('success', 'Team Removed', data.message);
            
            // Refresh the modal content
            const maxTeamsElement = document.getElementById('maxTeamsLimit');
            const roundData = {
                id: roundId,
                teams_count: maxTeamsElement && maxTeamsElement.textContent ? maxTeamsElement.textContent : '100'
            };
            
            // Close and reopen modal to refresh data
            setTimeout(() => {
                bootstrap.Modal.getInstance(document.getElementById('editRoundTeamsModal')).hide();
                setTimeout(() => {
                    assignTeams(roundData);
                }, 300);
            }, 1000);
        } else {
            showNotification('error', 'Removal Failed', data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('error', 'Network Error', 'Failed to remove team. Please try again.');
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

function fixRoundTeamsCounts() {
    if (!confirm('This will fix rounds that have incorrect team count limits (showing 0). Do you want to continue?')) {
        return;
    }
    
    const tournamentId = <?php echo $tournament_id; ?>;
    
    fetch(`fix_round_teams_count.php?tournament_id=${tournamentId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let message = `Fix completed successfully!\n\n`;
                message += `Total rounds checked: ${data.total_rounds_checked}\n`;
                message += `Rounds fixed: ${data.rounds_fixed}\n\n`;
                
                if (data.fixed_rounds.length > 0) {
                    message += 'Fixed rounds:\n';
                    data.fixed_rounds.forEach(round => {
                        message += `- ${round.round_name}: ${round.old_teams_count} → ${round.new_teams_count}\n`;
                    });
                }
                
                if (data.errors.length > 0) {
                    message += '\nErrors encountered:\n';
                    data.errors.forEach(error => {
                        message += `- ${error}\n`;
                    });
                }
                
                alert(message);
                
                if (data.rounds_fixed > 0) {
                    window.location.reload(); // Reload to show updated values
                }
            } else {
                alert('Error fixing round team counts: ' + data.error);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Failed to fix round team counts. Please try again.');
        });
}
</script>

<?php include '../../includes/admin-footer.php'; ?>
