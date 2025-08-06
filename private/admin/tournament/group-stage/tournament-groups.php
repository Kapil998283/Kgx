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
                    $group_data = [
                        'tournament_id' => $tournament_id,
                        'group_name' => $_POST['group_name'],
                        'description' => $_POST['description'],
                        'max_participants' => $_POST['max_participants'],
                        'status' => 'active',
                        'created_at' => date('Y-m-d H:i:s')
                    ];
                    
                    $supabase->insert('tournament_groups', $group_data);
                    $_SESSION['success'] = "Group created successfully!";
                    break;

                case 'update_group':
                    $supabase->update('tournament_groups', [
                        'group_name' => $_POST['group_name'],
                        'description' => $_POST['description'],
                        'max_participants' => $_POST['max_participants'],
                        'status' => $_POST['status'],
                        'updated_at' => date('Y-m-d H:i:s')
                    ], ['id' => $_POST['group_id']]);
                    $_SESSION['success'] = "Group updated successfully!";
                    break;

                case 'delete_group':
                    $group_id = $_POST['group_id'];
                    
                    // First delete related data
                    if ($is_solo) {
                        $supabase->delete('group_participants', ['group_id' => $group_id]);
                    } else {
                        $supabase->delete('group_teams', ['group_id' => $group_id]);
                    }
                    
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

                    foreach ($selected_participants as $participant_id) {
                        if ($is_solo) {
                            // For solo tournaments
                            $supabase->insert('group_participants', [
                                'group_id' => $group_id,
                                'user_id' => $participant_id,
                                'status' => 'active',
                                'joined_at' => date('Y-m-d H:i:s')
                            ]);
                        } else {
                            // For team tournaments
                            $supabase->insert('group_teams', [
                                'group_id' => $group_id,
                                'team_id' => $participant_id,
                                'status' => 'active',
                                'joined_at' => date('Y-m-d H:i:s')
                            ]);
                        }
                    }
                    
                    $_SESSION['success'] = count($selected_participants) . " participants assigned successfully!";
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
            // For team tournaments, get teams from group_teams
            $teams_data = $supabase->select('group_teams', '*', ['group_id' => $group['id'], 'status' => 'active']);
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
            $existing_assignment = $supabase->select('group_teams', 'id', [
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
                            <th>Description</th>
                            <th><?php echo $is_solo ? 'Players' : 'Teams'; ?></th>
                            <th>Matches</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($groups)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
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
                                <td><?php echo htmlspecialchars($group['description']); ?></td>
                                <td>
                                    <?php echo $group['participant_count']; ?> / <?php echo $group['max_participants']; ?>
                                    <br><small class="text-muted"><?php echo $is_solo ? 'Players' : 'Teams'; ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-info"><?php echo $group['matches_count']; ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-<?php 
                                        echo match($group['status']) {
                                            'active' => 'success',
                                            'completed' => 'secondary',
                                            'paused' => 'warning',
                                            default => 'info'
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

<!-- Create Group Modal -->
<div class="modal fade" id="createGroupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create New Group</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_group">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Group Name</label>
                        <input type="text" class="form-control" name="group_name" required 
                               placeholder="e.g., Group A, Group Alpha">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3" 
                                  placeholder="Optional description for this group"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Max <?php echo $is_solo ? 'Players' : 'Teams'; ?></label>
                        <input type="number" class="form-control" name="max_participants" 
                               min="2" max="20" value="6" required>
                        <small class="text-muted">Recommended: 4-8 for optimal group stage experience</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Group</button>
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
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="edit_description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Max <?php echo $is_solo ? 'Players' : 'Teams'; ?></label>
                        <input type="number" class="form-control" name="max_participants" id="edit_max_participants" 
                               min="2" max="20" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status" id="edit_status">
                            <option value="active">Active</option>
                            <option value="completed">Completed</option>
                            <option value="paused">Paused</option>
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

<!-- Assign Participants Modal -->
<div class="modal fade" id="assignParticipantsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="assignModalTitle">Assign <?php echo $is_solo ? 'Players' : 'Teams'; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="assignParticipantsForm">
                <input type="hidden" name="action" value="assign_participants">
                <input type="hidden" name="group_id" id="assign_group_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Available <?php echo $is_solo ? 'Players' : 'Teams'; ?></label>
                        <?php if (empty($available_participants)): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i>
                                No unassigned <?php echo $is_solo ? 'players' : 'teams'; ?> available.
                            </div>
                        <?php else: ?>
                            <div class="form-check-container" style="max-height: 300px; overflow-y: auto;">
                                <?php foreach ($available_participants as $participant): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" 
                                               name="participants[]" value="<?php echo $participant['id']; ?>"
                                               id="participant_<?php echo $participant['id']; ?>">
                                        <label class="form-check-label" for="participant_<?php echo $participant['id']; ?>">
                                            <?php echo htmlspecialchars($participant[$is_solo ? 'username' : 'name']); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" <?php echo empty($available_participants) ? 'disabled' : ''; ?>>
                        Assign Selected
                    </button>
                </div>
            </form>
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
<script>
function editGroup(group) {
    document.getElementById('edit_group_id').value = group.id;
    document.getElementById('edit_group_name').value = group.group_name;
    document.getElementById('edit_description').value = group.description || '';
    document.getElementById('edit_max_participants').value = group.max_participants;
    document.getElementById('edit_status').value = group.status;
    
    new bootstrap.Modal(document.getElementById('editGroupModal')).show();
}

function assignParticipants(groupId, groupName) {
    document.getElementById('assign_group_id').value = groupId;
    document.getElementById('assignModalTitle').textContent = 'Assign <?php echo $is_solo ? 'Players' : 'Teams'; ?> to ' + groupName;
    
    // Clear all checkboxes
    const checkboxes = document.querySelectorAll('input[name="participants[]"]');
    checkboxes.forEach(cb => cb.checked = false);
    
    new bootstrap.Modal(document.getElementById('assignParticipantsModal')).show();
}

function deleteGroup(groupId, groupName) {
    document.getElementById('delete_group_id').value = groupId;
    document.getElementById('delete_group_name').textContent = groupName;
    
    new bootstrap.Modal(document.getElementById('deleteGroupModal')).show();
}
</script>

</body>
</html>
