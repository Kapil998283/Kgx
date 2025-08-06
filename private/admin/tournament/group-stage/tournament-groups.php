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

// Ensure this is a Group Stage tournament
if ($tournament['format'] !== 'Group Stage') {
    $_SESSION['error'] = "This tournament is not in Group Stage format!";
    header("Location: ../index.php");
    exit();
}

// Check if this is a solo tournament
$is_solo = $tournament['mode'] === 'Solo';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create_group':
                    // Handle single group creation
                    if (isset($_POST['single_group']) && $_POST['single_group'] === '1') {
                        // Get the next group number for this tournament
                        $existing_groups = $supabase->select('tournament_groups', 'group_number', ['tournament_id' => $tournament_id], 'group_number.desc', 1);
                        $next_group_number = 1;
                        if (!empty($existing_groups)) {
                            $next_group_number = $existing_groups[0]['group_number'] + 1;
                        }
                        
                        $group_data = [
                            'tournament_id' => $tournament_id,
                            'group_name' => $_POST['group_name'],
                            'group_number' => $next_group_number,
                            'max_teams' => $_POST['max_participants'],
                            'status' => 'forming',
                            'created_at' => date('Y-m-d H:i:s')
                        ];
                        
                        $supabase->insert('tournament_groups', $group_data);
                        $_SESSION['success'] = "Group created successfully!";
                    } else {
                        // Handle multiple groups creation
                        $groups_to_create = $_POST['groups'] ?? [];
                        
                        if (empty($groups_to_create)) {
                            throw new Exception('No groups specified for creation');
                        }
                        
                        // Get the next group number for this tournament
                        $existing_groups = $supabase->select('tournament_groups', 'group_number', ['tournament_id' => $tournament_id], 'group_number.desc', 1);
                        $next_group_number = 1;
                        if (!empty($existing_groups)) {
                            $next_group_number = $existing_groups[0]['group_number'] + 1;
                        }
                        
                        $created_count = 0;
                        
                        foreach ($groups_to_create as $group_data) {
                            if (!empty($group_data['name']) && !empty($group_data['max_participants'])) {
                                $insert_data = [
                                    'tournament_id' => $tournament_id,
                                    'group_name' => $group_data['name'],
                                    'group_number' => $next_group_number,
                                    'max_teams' => (int)$group_data['max_participants'],
                                    'status' => 'forming',
                                    'created_at' => date('Y-m-d H:i:s')
                                ];
                                
                                $supabase->insert('tournament_groups', $insert_data);
                                $created_count++;
                                $next_group_number++;
                            }
                        }
                        
                        $_SESSION['success'] = "Successfully created {$created_count} groups!";
                    }
                    break;

                case 'update_group':
                    $supabase->update('tournament_groups', [
                        'group_name' => $_POST['group_name'],
                        'max_teams' => $_POST['max_participants'],
                        'status' => $_POST['status'],
                        'updated_at' => date('Y-m-d H:i:s')
                    ], ['id' => $_POST['group_id']]);
                    $_SESSION['success'] = "Group updated successfully!";
                    break;

                case 'delete_group':
                    $group_id = $_POST['group_id'];
                    
                    // First delete related data (both solo and team participants use group_participants table)
                    $supabase->delete('group_participants', ['group_id' => $group_id]);
                    
                    // Delete group matches
                    $supabase->delete('group_matches', ['group_id' => $group_id]);
                    
                    // Then delete the group
                    $supabase->delete('tournament_groups', ['id' => $group_id]);
                    $_SESSION['success'] = "Group deleted successfully!";
                    break;

                case 'assign_participants':
                    $group_id = $_POST['group_id'];
                    $selected_participants = $_POST['participants'] ?? [];
                    
                    if (empty($selected_participants)) {
                        throw new Exception('No participants selected');
                    }

                    // Get group max capacity for validation
                    $group_data = $supabase->select('tournament_groups', 'max_teams', ['id' => $group_id], null, 1);
                    $max_capacity = $group_data ? $group_data[0]['max_teams'] : 0;
                    
                    // Get current participants in this group
                    $current_participants = $supabase->select('group_participants', '*', [
                        'group_id' => $group_id, 
                        'status' => 'active'
                    ]);
                    $current_count = count($current_participants ?: []);
                    
                    // Check if adding new participants would exceed capacity
                    if ($current_count + count($selected_participants) > $max_capacity) {
                        throw new Exception("Cannot assign participants. This would exceed group capacity of {$max_capacity}. Current: {$current_count}");
                    }

                    foreach ($selected_participants as $participant_id) {
                        if ($is_solo) {
                            // Check if user is already assigned to ANY group in this tournament
                            $existing = $supabase->select('group_participants', 'id', [
                                'tournament_id' => $tournament_id,
                                'user_id' => $participant_id,
                                'status' => 'active'
                            ], null, 1);
                            
                            if (!$existing) {
                                $supabase->insert('group_participants', [
                                    'group_id' => $group_id,
                                    'tournament_id' => $tournament_id,
                                    'user_id' => $participant_id,
                                    'status' => 'active',
                                    'assigned_at' => date('Y-m-d H:i:s')
                                ]);
                            }
                        } else {
                            // Check if team is already assigned to ANY group in this tournament
                            $existing = $supabase->select('group_participants', 'id', [
                                'tournament_id' => $tournament_id,
                                'team_id' => $participant_id,
                                'status' => 'active'
                            ], null, 1);
                            
                            if (!$existing) {
                                $supabase->insert('group_participants', [
                                    'group_id' => $group_id,
                                    'tournament_id' => $tournament_id,
                                    'team_id' => $participant_id,
                                    'status' => 'active',
                                    'assigned_at' => date('Y-m-d H:i:s')
                                ]);
                            }
                        }
                    }
                    
                    $_SESSION['success'] = count($selected_participants) . " participants assigned successfully!";
                    break;

                case 'reset_all_assignments':
                    // Delete all group participants for this tournament
                    $supabase->delete('group_participants', ['tournament_id' => $tournament_id]);
                    $_SESSION['success'] = "All participant assignments have been reset successfully!";
                    break;
            }
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    header("Location: tournament-groups.php?id=" . $tournament_id);
    exit();
}

// Get tournament groups with participant counts
$groups_data = $supabase->select('tournament_groups', '*', ['tournament_id' => $tournament_id], 'group_name.asc');
$groups = [];
if ($groups_data) {
    foreach ($groups_data as $group) {
        if ($is_solo) {
            // For solo tournaments, get participants from group_participants
            $participants_data = $supabase->select('group_participants', '*', ['group_id' => $group['id'], 'status' => 'active']);
            $group['participants'] = $participants_data ?: [];
            $group['participant_count'] = count($participants_data ?: []);
        } else {
            // For team tournaments, get teams from group_participants (same table, different column)
            $teams_data = $supabase->select('group_participants', '*', ['group_id' => $group['id'], 'status' => 'active', 'team_id' => ['not.is', null]]);
            $group['participants'] = $teams_data ?: [];
            $group['participant_count'] = count($teams_data ?: []);
        }
        
        // Get group matches count
        $matches_data = $supabase->select('group_matches', 'id', ['group_id' => $group['id']]);
        $group['matches_count'] = count($matches_data ?: []);
        
        $groups[] = $group;
    }
}

// Get available participants for assignment
$available_participants = [];
if ($is_solo) {
    // Solo tournament: get user registrations not already in groups
    $all_registrations = $supabase->select('tournament_registrations', 'user_id', [
        'tournament_id' => $tournament_id, 
        'status' => 'approved',
        'user_id' => ['not.is', null]
    ]);
    
    if ($all_registrations) {
        foreach ($all_registrations as $reg) {
            // Check if user is already assigned to a group
            $existing_assignment = $supabase->select('group_participants', 'id', [
                'user_id' => $reg['user_id'],
                'status' => 'active'
            ], null, 1);
            
            if (!$existing_assignment) {
                $user_data = $supabase->select('users', 'id, username', ['id' => $reg['user_id']], null, 1);
                if ($user_data) {
                    $available_participants[] = $user_data[0];
                }
            }
        }
    }
} else {
    // Team tournament: get team registrations not already in groups
    $all_registrations = $supabase->select('tournament_registrations', 'team_id', [
        'tournament_id' => $tournament_id, 
        'status' => 'approved',
        'team_id' => ['not.is', null]
    ]);
    
    if ($all_registrations) {
        foreach ($all_registrations as $reg) {
            // Check if team is already assigned to a group
            $existing_assignment = $supabase->select('group_participants', 'id', [
                'team_id' => $reg['team_id'],
                'status' => 'active'
            ], null, 1);
            
            if (!$existing_assignment) {
                $team_data = $supabase->select('teams', 'id, name', ['id' => $reg['team_id']], null, 1);
                if ($team_data) {
                    $available_participants[] = $team_data[0];
                }
            }
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
                    <h1>Group Stage Management</h1>
                    <h5 class="text-muted"><?php echo htmlspecialchars($tournament['name']); ?> (<?php echo $tournament['game_name']; ?>)</h5>
                </div>
                <div class="btn-group">
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createGroupModal">
                        <i class="bi bi-plus-circle"></i> Create Group
                    </button>
                    <a href="tournament-schedule.php?id=<?php echo $tournament_id; ?>" class="btn btn-info">
                        <i class="bi bi-calendar"></i> Schedule
                    </a>
                    <?php if (!empty($groups)): ?>
                    <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#resetAllAssignmentsModal">
                        <i class="bi bi-arrow-clockwise"></i> Reset All Assignments
                    </button>
                    <?php endif; ?>
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

            <!-- Groups Overview -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title text-primary"><?php echo count($groups); ?></h5>
                            <p class="card-text">Total Groups</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title text-success"><?php echo array_sum(array_column($groups, 'participant_count')); ?></h5>
                            <p class="card-text">Assigned <?php echo $is_solo ? 'Players' : 'Teams'; ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title text-warning"><?php echo count($available_participants); ?></h5>
                            <p class="card-text">Available <?php echo $is_solo ? 'Players' : 'Teams'; ?></p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h5 class="card-title text-info"><?php echo array_sum(array_column($groups, 'matches_count')); ?></h5>
                            <p class="card-text">Total Matches</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Groups Table -->
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>Group</th>
                            <th><?php echo $is_solo ? 'Players' : 'Teams'; ?></th>
                            <th>Matches</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($groups)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox"></i><br>
                                    No groups created yet. Click "Create Group" to start.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($groups as $group): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($group['group_name']); ?></strong>
                                </td>
                                <td>
                                    <?php echo $group['participant_count']; ?> / <?php echo $group['max_teams']; ?>
                                    <br><small class="text-muted"><?php echo $is_solo ? 'Players' : 'Teams'; ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-info"><?php echo $group['matches_count']; ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo match($group['status']) {
                                            'ready' => 'success',
                                            'in_progress' => 'primary',
                                            'completed' => 'secondary',
                                            'forming' => 'info',
                                            default => 'warning'
                                        };
                                    ?>">
                                        <?php echo ucfirst($group['status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <button class="btn btn-outline-primary" onclick='editGroup(<?php echo json_encode($group); ?>)' title="Edit Group">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button class="btn btn-outline-success" onclick="assignParticipants(<?php echo $group['id']; ?>, '<?php echo htmlspecialchars($group['group_name']); ?>')" title="Assign <?php echo $is_solo ? 'Players' : 'Teams'; ?>">
                                            <i class="bi bi-people"></i>
                                        </button>
                                        <a href="tournament-schedule.php?id=<?php echo $tournament_id; ?>&group_id=<?php echo $group['id']; ?>" class="btn btn-outline-info" title="Manage Matches">
                                            <i class="bi bi-calendar-event"></i>
                                        </a>
                                        <button class="btn btn-outline-danger" onclick="deleteGroup(<?php echo $group['id']; ?>, '<?php echo htmlspecialchars($group['group_name']); ?>')" title="Delete Group">
                                            <i class="bi bi-trash"></i>
                                        </button>
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

<!-- Advanced Create Groups Modal -->
<div class="modal fade" id="createGroupModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create Tournament Groups</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="createGroupsForm">
                <input type="hidden" name="action" value="create_group">
                <input type="hidden" name="single_group" id="single_group_flag" value="0">
                
                <div class="modal-body">
                    <!-- Creation Mode Toggle -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="btn-group w-100" role="group">
                                <input type="radio" class="btn-check" name="creation_mode" id="single_mode" value="single" checked>
                                <label class="btn btn-outline-primary" for="single_mode">
                                    <i class="bi bi-plus-circle"></i> Create Single Group
                                </label>
                                
                                <input type="radio" class="btn-check" name="creation_mode" id="multiple_mode" value="multiple">
                                <label class="btn btn-outline-success" for="multiple_mode">
                                    <i class="bi bi-grid-3x3"></i> Create Multiple Groups
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Single Group Creation -->
                    <div id="single_group_section">
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h6 class="mb-0"><i class="bi bi-plus-circle"></i> Single Group Creation</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-8">
                                        <label class="form-label">Group Name</label>
                                        <input type="text" class="form-control" name="group_name" id="single_group_name"
                                               placeholder="e.g., Group A, Group Alpha">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">Max <?php echo $is_solo ? 'Players' : 'Teams'; ?></label>
                                        <input type="number" class="form-control" name="max_participants" id="single_max_participants"
                                               min="2" max="20" value="6">
                                    </div>
                                </div>
                                <small class="text-muted">Recommended: 4-8 <?php echo $is_solo ? 'players' : 'teams'; ?> for optimal group stage experience</small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Multiple Groups Creation -->
                    <div id="multiple_groups_section" style="display: none;">
                        <div class="card">
                            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                                <h6 class="mb-0"><i class="bi bi-grid-3x3"></i> Multiple Groups Creation</h6>
                                <div class="btn-group btn-group-sm">
                                    <button type="button" class="btn btn-light btn-sm" id="add_group_btn">
                                        <i class="bi bi-plus"></i> Add Group
                                    </button>
                                    <button type="button" class="btn btn-warning btn-sm" id="auto_generate_btn">
                                        <i class="bi bi-magic"></i> Auto Generate
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <label class="form-label">Quick Setup - Number of Groups</label>
                                            <input type="number" class="form-control" id="quick_groups_count" 
                                                   min="2" max="10" value="4" placeholder="How many groups?">
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Default Max <?php echo $is_solo ? 'Players' : 'Teams'; ?></label>
                                            <input type="number" class="form-control" id="default_max_participants" 
                                                   min="2" max="20" value="6">
                                        </div>
                                    </div>
                                </div>
                                
                                <div id="groups_container">
                                    <!-- Dynamic groups will be added here -->
                                </div>
                                
                                <div class="text-center mt-3">
                                    <small class="text-muted">Use "Auto Generate" to quickly create groups A, B, C... or add them manually</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="create_groups_submit">
                        <i class="bi bi-plus-circle"></i> <span id="submit_text">Create Group</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Group Modal -->
<div class="modal fade" id="editGroupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Group</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editGroupForm">
                <input type="hidden" name="action" value="update_group">
                <input type="hidden" name="group_id" id="edit_group_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Group Name</label>
                        <input type="text" class="form-control" name="group_name" id="edit_group_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Max <?php echo $is_solo ? 'Players' : 'Teams'; ?></label>
                        <input type="number" class="form-control" name="max_participants" id="edit_max_participants" 
                               min="2" max="20" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status" id="edit_status">
                            <option value="forming">Forming</option>
                            <option value="ready">Ready</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update Group</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Advanced Assign Participants Modal -->
<div class="modal fade" id="assignParticipantsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="assignModalTitle">Assign <?php echo $is_solo ? 'Players' : 'Teams'; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="assignParticipantsForm">
                <input type="hidden" name="action" value="assign_participants">
                <input type="hidden" name="group_id" id="assign_group_id">
                <div class="modal-body">
                    <?php if (empty($available_participants)): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            No unassigned <?php echo $is_solo ? 'players' : 'teams'; ?> available.
                        </div>
                    <?php else: ?>
                        <!-- Selection Controls -->
                        <div class="row mb-4">
                            <div class="col-md-8">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="badge bg-primary fs-6" id="selectionCounter">
                                        <i class="bi bi-people-fill"></i> Selected: <span id="selectedCount">0</span> / <span id="maxLimit">0</span>
                                    </div>
                                    <button type="button" class="btn btn-outline-warning btn-sm" id="resetSelectionBtn">
                                        <i class="bi bi-arrow-clockwise"></i> Reset All
                                    </button>
                                    <button type="button" class="btn btn-outline-info btn-sm" id="selectAllBtn">
                                        <i class="bi bi-check-all"></i> Select All Available
                                    </button>
                                </div>
                            </div>
                            <div class="col-md-4 text-end">
                                <div class="text-muted small">
                                    Group Capacity: <span id="groupCapacity">0</span> <?php echo $is_solo ? 'players' : 'teams'; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Two Column Layout -->
                        <div class="row">
                            <!-- Available Participants -->
                            <div class="col-md-6">
                                <div class="card h-100">
                                    <div class="card-header bg-light">
                                        <h6 class="card-title mb-0">
                                            <i class="bi bi-people"></i> Available <?php echo $is_solo ? 'Players' : 'Teams'; ?>
                                            <span class="badge bg-secondary ms-2" id="availableCount"><?php echo count($available_participants); ?></span>
                                        </h6>
                                    </div>
                                    <div class="card-body p-2" style="max-height: 400px; overflow-y: auto;">
                                        <div id="availableParticipants">
                                            <?php foreach ($available_participants as $participant): ?>
                                                <div class="participant-item available p-2 mb-2 border rounded" 
                                                     data-participant-id="<?php echo $participant['id']; ?>"
                                                     data-participant-name="<?php echo htmlspecialchars($participant[$is_solo ? 'username' : 'name']); ?>">
                                                    <div class="form-check">
                                                        <input class="form-check-input participant-checkbox" 
                                                               type="checkbox" 
                                                               name="participants[]" 
                                                               value="<?php echo $participant['id']; ?>"
                                                               id="participant_<?php echo $participant['id']; ?>">
                                                        <label class="form-check-label d-flex justify-content-between align-items-center w-100" 
                                                               for="participant_<?php echo $participant['id']; ?>">
                                                            <span class="participant-name"><?php echo htmlspecialchars($participant[$is_solo ? 'username' : 'name']); ?></span>
                                                            <i class="bi bi-plus-circle text-success"></i>
                                                        </label>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Selected Participants -->
                            <div class="col-md-6">
                                <div class="card h-100">
                                    <div class="card-header bg-success text-white">
                                        <h6 class="card-title mb-0">
                                            <i class="bi bi-check-circle"></i> Selected for <span id="selectedGroupName">Group</span>
                                            <span class="badge bg-light text-dark ms-2" id="selectedCountBadge">0</span>
                                        </h6>
                                    </div>
                                    <div class="card-body p-2" style="max-height: 400px; overflow-y: auto;">
                                        <div id="selectedParticipants" class="empty-state">
                                            <div class="text-center text-muted py-4">
                                                <i class="bi bi-inbox display-4"></i>
                                                <p class="mt-2 mb-0">No <?php echo $is_solo ? 'players' : 'teams'; ?> selected</p>
                                                <small>Click on <?php echo $is_solo ? 'players' : 'teams'; ?> from the left to add them</small>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Selection Status Alert -->
                        <div id="selectionAlert" class="alert alert-info mt-3" style="display: none;">
                            <i class="bi bi-info-circle"></i>
                            <span id="selectionMessage"></span>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" id="assignBtn" 
                            <?php echo empty($available_participants) ? 'disabled' : ''; ?>>
                        <i class="bi bi-check-lg"></i> Assign Selected (<span id="assignBtnCount">0</span>)
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reset All Assignments Modal -->
<div class="modal fade" id="resetAllAssignmentsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reset All Assignments</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>Warning!</strong> This action will remove all participant assignments from ALL groups in this tournament.
                </div>
                <p>Are you sure you want to reset all assignments? This will:</p>
                <ul>
                    <li>Remove all <?php echo $is_solo ? 'players' : 'teams'; ?> from all groups</li>
                    <li>Make all registered participants available for reassignment</li>
                    <li>Reset group participant counts to 0</li>
                </ul>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    <strong>Current Status:</strong> <?php echo array_sum(array_column($groups, 'participant_count')); ?> <?php echo $is_solo ? 'players' : 'teams'; ?> assigned across <?php echo count($groups); ?> group(s)
                </div>
            </div>
            <div class="modal-footer">
                <form method="POST">
                    <input type="hidden" name="action" value="reset_all_assignments">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-arrow-clockwise"></i> Reset All Assignments
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Group Modal -->
<div class="modal fade" id="deleteGroupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Group</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to delete <strong id="delete_group_name"></strong>?</p>
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i>
                    This will also delete all matches and participant assignments for this group. This action cannot be undone.
                </div>
            </div>
            <div class="modal-footer">
                <form method="POST" id="deleteGroupForm">
                    <input type="hidden" name="action" value="delete_group">
                    <input type="hidden" name="group_id" id="delete_group_id">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Delete Group</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<style>
.participant-item {
    transition: all 0.3s ease;
    cursor: pointer;
}
.participant-item:hover {
    background-color: #f8f9fa;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.participant-item.selected {
    background-color: #d4edda;
    border-color: #c3e6cb;
}
.selected-participant {
    background-color: #d4edda;
    border: 1px solid #c3e6cb;
    border-radius: 0.375rem;
    padding: 0.5rem;
    margin-bottom: 0.5rem;
    display: flex;
    justify-content: between;
    align-items: center;
    transition: all 0.3s ease;
}
.selected-participant:hover {
    background-color: #c3e6cb;
}
.empty-state {
    min-height: 200px;
    display: flex;
    align-items: center;
    justify-content: center;
}
#selectionCounter {
    font-size: 0.9rem;
}
.over-limit {
    background-color: #f8d7da !important;
    border-color: #f5c6cb !important;
}
.group-input-row {
    background-color: #f8f9fa;
    border: 1px solid #dee2e6;
    transition: all 0.3s ease;
}
.group-input-row:hover {
    background-color: #e9ecef;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.group-input-row h6 {
    color: #0d6efd;
}
.is-invalid {
    border-color: #dc3545 !important;
    box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
}
</style>
<script>
let currentGroupData = null;
let maxParticipants = 0;

function editGroup(group) {
    document.getElementById('edit_group_id').value = group.id;
    document.getElementById('edit_group_name').value = group.group_name;
    document.getElementById('edit_max_participants').value = group.max_teams;
    document.getElementById('edit_status').value = group.status;
    
    new bootstrap.Modal(document.getElementById('editGroupModal')).show();
}

function assignParticipants(groupId, groupName) {
    // Find the group data from the page
    const groupsData = <?php echo json_encode($groups); ?>;
    currentGroupData = groupsData.find(g => g.id == groupId);
    maxParticipants = currentGroupData ? currentGroupData.max_teams : 0;
    
    // Set modal title and group info
    document.getElementById('assign_group_id').value = groupId;
    document.getElementById('assignModalTitle').textContent = 'Assign <?php echo $is_solo ? 'Players' : 'Teams'; ?> to ' + groupName;
    document.getElementById('selectedGroupName').textContent = groupName;
    document.getElementById('groupCapacity').textContent = maxParticipants;
    document.getElementById('maxLimit').textContent = maxParticipants;
    
    // Reset selection
    resetSelection();
    
    // Initialize event listeners
    initializeParticipantSelection();
    
    new bootstrap.Modal(document.getElementById('assignParticipantsModal')).show();
}

function initializeParticipantSelection() {
    const checkboxes = document.querySelectorAll('.participant-checkbox');
    const resetBtn = document.getElementById('resetSelectionBtn');
    const selectAllBtn = document.getElementById('selectAllBtn');
    
    // Add event listeners to checkboxes
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', handleParticipantSelection);
    });
    
    // Reset button
    resetBtn.addEventListener('click', resetSelection);
    
    // Select all button  
    selectAllBtn.addEventListener('click', selectAllAvailable);
    
    // Update initial counts
    updateSelectionDisplay();
}

function handleParticipantSelection(event) {
    const checkbox = event.target;
    const participantItem = checkbox.closest('.participant-item');
    const participantId = participantItem.dataset.participantId;
    const participantName = participantItem.dataset.participantName;
    
    if (checkbox.checked) {
        // Check if we're at the limit
        const currentSelected = document.querySelectorAll('.participant-checkbox:checked').length;
        
        if (currentSelected > maxParticipants) {
            checkbox.checked = false;
            showSelectionAlert('Cannot select more than ' + maxParticipants + ' <?php echo $is_solo ? 'players' : 'teams'; ?> for this group!', 'danger');
            return;
        }
        
        // Add to selected list
        addToSelectedList(participantId, participantName);
        participantItem.classList.add('selected');
    } else {
        // Remove from selected list
        removeFromSelectedList(participantId);
        participantItem.classList.remove('selected');
    }
    
    updateSelectionDisplay();
}

function addToSelectedList(participantId, participantName) {
    const selectedContainer = document.getElementById('selectedParticipants');
    
    // Remove empty state if it exists
    if (selectedContainer.classList.contains('empty-state')) {
        selectedContainer.classList.remove('empty-state');
        selectedContainer.innerHTML = '';
    }
    
    // Create selected participant element
    const selectedElement = document.createElement('div');
    selectedElement.className = 'selected-participant';
    selectedElement.dataset.participantId = participantId;
    selectedElement.innerHTML = `
        <span class="flex-grow-1">${participantName}</span>
        <button type="button" class="btn btn-sm btn-outline-danger ms-2" onclick="removeParticipant('${participantId}')">
            <i class="bi bi-x-circle"></i>
        </button>
    `;
    
    selectedContainer.appendChild(selectedElement);
}

function removeFromSelectedList(participantId) {
    const selectedElement = document.querySelector(`.selected-participant[data-participant-id="${participantId}"]`);
    if (selectedElement) {
        selectedElement.remove();
    }
    
    // Check if list is empty and restore empty state
    const selectedContainer = document.getElementById('selectedParticipants');
    if (selectedContainer.children.length === 0) {
        selectedContainer.classList.add('empty-state');
        selectedContainer.innerHTML = `
            <div class="text-center text-muted py-4">
                <i class="bi bi-inbox display-4"></i>
                <p class="mt-2 mb-0">No <?php echo $is_solo ? 'players' : 'teams'; ?> selected</p>
                <small>Click on <?php echo $is_solo ? 'players' : 'teams'; ?> from the left to add them</small>
            </div>
        `;
    }
}

function removeParticipant(participantId) {
    // Uncheck the checkbox
    const checkbox = document.getElementById(`participant_${participantId}`);
    if (checkbox) {
        checkbox.checked = false;
        const participantItem = checkbox.closest('.participant-item');
        participantItem.classList.remove('selected');
    }
    
    // Remove from selected list
    removeFromSelectedList(participantId);
    
    updateSelectionDisplay();
}

function resetSelection() {
    // Uncheck all checkboxes
    const checkboxes = document.querySelectorAll('.participant-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = false;
        const participantItem = cb.closest('.participant-item');
        participantItem.classList.remove('selected');
    });
    
    // Clear selected list
    const selectedContainer = document.getElementById('selectedParticipants');
    selectedContainer.classList.add('empty-state');
    selectedContainer.innerHTML = `
        <div class="text-center text-muted py-4">
            <i class="bi bi-inbox display-4"></i>
            <p class="mt-2 mb-0">No <?php echo $is_solo ? 'players' : 'teams'; ?> selected</p>
            <small>Click on <?php echo $is_solo ? 'players' : 'teams'; ?> from the left to add them</small>
        </div>
    `;
    
    updateSelectionDisplay();
    hideSelectionAlert();
}

function selectAllAvailable() {
    const availableCheckboxes = document.querySelectorAll('.participant-checkbox');
    const availableCount = availableCheckboxes.length;
    
    if (availableCount > maxParticipants) {
        showSelectionAlert(`Cannot select all ${availableCount} <?php echo $is_solo ? 'players' : 'teams'; ?>. Maximum allowed is ${maxParticipants}.`, 'warning');
        return;
    }
    
    // Select all available
    availableCheckboxes.forEach(checkbox => {
        if (!checkbox.checked) {
            checkbox.checked = true;
            const participantItem = checkbox.closest('.participant-item');
            const participantId = participantItem.dataset.participantId;
            const participantName = participantItem.dataset.participantName;
            
            addToSelectedList(participantId, participantName);
            participantItem.classList.add('selected');
        }
    });
    
    updateSelectionDisplay();
}

function updateSelectionDisplay() {
    const selectedCount = document.querySelectorAll('.participant-checkbox:checked').length;
    const availableCount = document.querySelectorAll('.participant-checkbox').length;
    
    // Update counters
    document.getElementById('selectedCount').textContent = selectedCount;
    document.getElementById('selectedCountBadge').textContent = selectedCount;
    document.getElementById('assignBtnCount').textContent = selectedCount;
    document.getElementById('availableCount').textContent = availableCount;
    
    // Update counter colors
    const counter = document.getElementById('selectionCounter');
    const assignBtn = document.getElementById('assignBtn');
    
    // Reset classes
    counter.className = 'badge fs-6';
    
    if (selectedCount === 0) {
        counter.classList.add('bg-secondary');
        assignBtn.disabled = true;
    } else if (selectedCount === maxParticipants) {
        counter.classList.add('bg-success');
        assignBtn.disabled = false;
        showSelectionAlert(`Perfect! You have selected exactly ${maxParticipants} <?php echo $is_solo ? 'players' : 'teams'; ?> for this group.`, 'success');
    } else if (selectedCount > maxParticipants) {
        counter.classList.add('bg-danger');
        assignBtn.disabled = true;
        showSelectionAlert(`Too many selected! Please remove ${selectedCount - maxParticipants} <?php echo $is_solo ? 'players' : 'teams'; ?>.`, 'danger');
    } else {
        counter.classList.add('bg-primary');
        assignBtn.disabled = false;
        showSelectionAlert(`You can select ${maxParticipants - selectedCount} more <?php echo $is_solo ? 'players' : 'teams'; ?>.`, 'info');
    }
}

function showSelectionAlert(message, type) {
    const alert = document.getElementById('selectionAlert');
    const messageSpan = document.getElementById('selectionMessage');
    
    // Reset classes
    alert.className = 'alert mt-3';
    alert.classList.add('alert-' + type);
    
    messageSpan.textContent = message;
    alert.style.display = 'block';
}

function hideSelectionAlert() {
    const alert = document.getElementById('selectionAlert');
    alert.style.display = 'none';
}

function deleteGroup(groupId, groupName) {
    document.getElementById('delete_group_id').value = groupId;
    document.getElementById('delete_group_name').textContent = groupName;
    
    new bootstrap.Modal(document.getElementById('deleteGroupModal')).show();
}

// Advanced Create Groups Modal JavaScript
let groupCounter = 0;

document.addEventListener('DOMContentLoaded', function() {
    initializeGroupCreationModal();
});

function initializeGroupCreationModal() {
    const singleModeRadio = document.getElementById('single_mode');
    const multipleModeRadio = document.getElementById('multiple_mode');
    const singleSection = document.getElementById('single_group_section');
    const multipleSection = document.getElementById('multiple_groups_section');
    const singleGroupFlag = document.getElementById('single_group_flag');
    const submitText = document.getElementById('submit_text');
    const addGroupBtn = document.getElementById('add_group_btn');
    const autoGenerateBtn = document.getElementById('auto_generate_btn');
    
    // Mode toggle functionality
    singleModeRadio.addEventListener('change', function() {
        if (this.checked) {
            singleSection.style.display = 'block';
            multipleSection.style.display = 'none';
            singleGroupFlag.value = '1';
            submitText.textContent = 'Create Group';
            
            // Make single group fields required
            document.getElementById('single_group_name').required = true;
            document.getElementById('single_max_participants').required = true;
        }
    });
    
    multipleModeRadio.addEventListener('change', function() {
        if (this.checked) {
            singleSection.style.display = 'none';
            multipleSection.style.display = 'block';
            singleGroupFlag.value = '0';
            submitText.textContent = 'Create Groups';
            
            // Remove required from single group fields
            document.getElementById('single_group_name').required = false;
            document.getElementById('single_max_participants').required = false;
        }
    });
    
    // Add group button
    addGroupBtn.addEventListener('click', addGroupInputRow);
    
    // Auto generate button
    autoGenerateBtn.addEventListener('click', autoGenerateGroups);
    
    // Form validation
    document.getElementById('createGroupsForm').addEventListener('submit', validateGroupCreation);
}

function addGroupInputRow(name = '', maxParticipants = 6) {
    groupCounter++;
    const container = document.getElementById('groups_container');
    
    const groupRow = document.createElement('div');
    groupRow.className = 'group-input-row mb-3 p-3 border rounded position-relative';
    groupRow.dataset.groupIndex = groupCounter;
    
    groupRow.innerHTML = `
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0 text-primary">
                <i class="bi bi-diagram-3"></i> Group ${groupCounter}
            </h6>
            <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeGroupRow(${groupCounter})">
                <i class="bi bi-x-circle"></i> Remove
            </button>
        </div>
        <div class="row">
            <div class="col-md-8">
                <label class="form-label">Group Name</label>
                <input type="text" class="form-control group-name-input" 
                       name="groups[${groupCounter}][name]" 
                       value="${name}" 
                       placeholder="e.g., Group ${String.fromCharCode(64 + groupCounter)}" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Max <?php echo $is_solo ? 'Players' : 'Teams'; ?></label>
                <input type="number" class="form-control group-max-input" 
                       name="groups[${groupCounter}][max_participants]" 
                       value="${maxParticipants}" 
                       min="2" max="20" required>
            </div>
        </div>
    `;
    
    container.appendChild(groupRow);
    updateGroupCounter();
    
    // Focus on the name input
    setTimeout(() => {
        groupRow.querySelector('.group-name-input').focus();
    }, 100);
}

function removeGroupRow(index) {
    const row = document.querySelector(`[data-group-index="${index}"]`);
    if (row) {
        row.remove();
        updateGroupCounter();
        renumberGroupRows();
    }
}

function renumberGroupRows() {
    const rows = document.querySelectorAll('.group-input-row');
    rows.forEach((row, index) => {
        const newIndex = index + 1;
        row.dataset.groupIndex = newIndex;
        
        // Update header text
        const header = row.querySelector('h6');
        header.innerHTML = `<i class="bi bi-diagram-3"></i> Group ${newIndex}`;
        
        // Update input names
        const nameInput = row.querySelector('.group-name-input');
        const maxInput = row.querySelector('.group-max-input');
        
        nameInput.name = `groups[${newIndex}][name]`;
        maxInput.name = `groups[${newIndex}][max_participants]`;
        
        // Update remove button onclick
        const removeBtn = row.querySelector('.btn-outline-danger');
        removeBtn.onclick = () => removeGroupRow(newIndex);
    });
    
    // Reset counter
    groupCounter = rows.length;
}

function updateGroupCounter() {
    const groupCount = document.querySelectorAll('.group-input-row').length;
    const submitText = document.getElementById('submit_text');
    
    if (groupCount === 0) {
        submitText.textContent = 'Create Groups';
    } else if (groupCount === 1) {
        submitText.textContent = 'Create 1 Group';
    } else {
        submitText.textContent = `Create ${groupCount} Groups`;
    }
}

function autoGenerateGroups() {
    const groupsCount = parseInt(document.getElementById('quick_groups_count').value) || 4;
    const defaultMax = parseInt(document.getElementById('default_max_participants').value) || 6;
    
    // Clear existing groups
    document.getElementById('groups_container').innerHTML = '';
    groupCounter = 0;
    
    // Generate groups A, B, C, etc.
    for (let i = 1; i <= groupsCount; i++) {
        const groupName = `Group ${String.fromCharCode(64 + i)}`; // A, B, C, etc.
        addGroupInputRow(groupName, defaultMax);
    }
}

function validateGroupCreation(event) {
    const mode = document.querySelector('input[name="creation_mode"]:checked').value;
    
    if (mode === 'single') {
        // Validate single group
        const groupName = document.getElementById('single_group_name').value.trim();
        const maxParticipants = document.getElementById('single_max_participants').value;
        
        if (!groupName || !maxParticipants) {
            event.preventDefault();
            alert('Please fill in all required fields for the group.');
            return false;
        }
    } else {
        // Validate multiple groups
        const groupRows = document.querySelectorAll('.group-input-row');
        
        if (groupRows.length === 0) {
            event.preventDefault();
            alert('Please add at least one group or switch to single group mode.');
            return false;
        }
        
        let hasError = false;
        const groupNames = new Set();
        
        groupRows.forEach((row, index) => {
            const nameInput = row.querySelector('.group-name-input');
            const maxInput = row.querySelector('.group-max-input');
            
            const name = nameInput.value.trim();
            const max = maxInput.value;
            
            // Check if name is empty
            if (!name) {
                nameInput.classList.add('is-invalid');
                hasError = true;
            } else {
                nameInput.classList.remove('is-invalid');
                
                // Check for duplicate names
                if (groupNames.has(name.toLowerCase())) {
                    nameInput.classList.add('is-invalid');
                    hasError = true;
                } else {
                    groupNames.add(name.toLowerCase());
                }
            }
            
            // Check if max participants is valid
            if (!max || max < 2 || max > 20) {
                maxInput.classList.add('is-invalid');
                hasError = true;
            } else {
                maxInput.classList.remove('is-invalid');
            }
        });
        
        if (hasError) {
            event.preventDefault();
            alert('Please fix the errors in the form:\n\n- All group names must be unique and not empty\n- Max participants must be between 2 and 20');
            return false;
        }
    }
    
    return true;
}

// Reset modal when closed
document.getElementById('createGroupModal').addEventListener('hidden.bs.modal', function() {
    // Reset to single mode
    document.getElementById('single_mode').checked = true;
    document.getElementById('single_group_section').style.display = 'block';
    document.getElementById('multiple_groups_section').style.display = 'none';
    document.getElementById('single_group_flag').value = '1';
    document.getElementById('submit_text').textContent = 'Create Group';
    
    // Clear single group inputs
    document.getElementById('single_group_name').value = '';
    document.getElementById('single_max_participants').value = '6';
    
    // Clear multiple groups
    document.getElementById('groups_container').innerHTML = '';
    groupCounter = 0;
    
    // Reset validation states
    document.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
});
</script>

</body>
</html>
