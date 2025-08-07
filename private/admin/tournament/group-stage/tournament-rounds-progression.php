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

// Handle form submissions for round progression
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'create_next_round':
                    $current_round_number = $_POST['current_round_number'];
                    $advancement_per_group = $_POST['advancement_per_group'];
                    $new_groups_count = $_POST['new_groups_count'];
                    $participants_per_new_group = $_POST['participants_per_new_group'];
                    
                    $result = createNextRound($supabase, $tournament_id, $current_round_number, $advancement_per_group, $new_groups_count, $participants_per_new_group, $is_solo);
                    
                    if ($result['success']) {
                        $_SESSION['success'] = $result['message'];
                    } else {
                        $_SESSION['error'] = $result['message'];
                    }
                    break;
                    
                case 'finalize_tournament':
                    $final_round_number = $_POST['final_round_number'];
                    $winners_count = $_POST['winners_count'];
                    
                    $result = finalizeTournament($supabase, $tournament_id, $final_round_number, $winners_count, $is_solo);
                    
                    if ($result['success']) {
                        $_SESSION['success'] = $result['message'];
                    } else {
                        $_SESSION['error'] = $result['message'];
                    }
                    break;
            }
        }
    } catch (Exception $e) {
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    header("Location: tournament-rounds-progression.php?id=" . $tournament_id);
    exit();
}

/**
 * Create next round with advanced participants
 */
function createNextRound($supabase, $tournament_id, $current_round_number, $advancement_per_group, $new_groups_count, $participants_per_new_group, $is_solo) {
    try {
        // Get current round groups
        $current_groups = $supabase->select('tournament_groups', '*', [
            'tournament_id' => $tournament_id,
            'round_number' => $current_round_number
        ]);
        
        if (empty($current_groups)) {
            return ['success' => false, 'message' => 'No groups found for current round'];
        }
        
        // Get top performers from each current group
        $advancing_participants = [];
        
        foreach ($current_groups as $group) {
            $group_standings = getGroupStandings($supabase, $group['id'], $is_solo);
            
            // Take top N participants from this group
            $top_participants = array_slice($group_standings, 0, $advancement_per_group);
            
            foreach ($top_participants as $participant) {
                $advancing_participants[] = [
                    'id' => $participant['id'],
                    'name' => $participant['name'],
                    'points' => $participant['total_points'],
                    'kills' => $participant['total_kills'],
                    'previous_group' => $group['group_name'],
                    'previous_rank' => array_search($participant, $group_standings) + 1
                ];
            }
        }
        
        // Sort advancing participants by points (for balanced redistribution)
        usort($advancing_participants, function($a, $b) {
            return $b['points'] - $a['points'];
        });
        
        // Create new round groups
        $new_round_number = $current_round_number + 1;
        $group_names = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];
        
        for ($i = 0; $i < $new_groups_count; $i++) {
            $group_data = [
                'tournament_id' => $tournament_id,
                'group_name' => "Round $new_round_number - Group " . $group_names[$i],
                'group_number' => $i + 1,
                'round_number' => $new_round_number,
                'max_teams' => $participants_per_new_group,
                'status' => 'forming',
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            $supabase->insert('tournament_groups', $group_data);
        }
        
        // Get the newly created groups
        $new_groups = $supabase->select('tournament_groups', '*', [
            'tournament_id' => $tournament_id,
            'round_number' => $new_round_number
        ], 'id.asc');
        
        // Distribute participants using snake draft for balance
        $group_index = 0;
        $direction = 1; // 1 for forward, -1 for backward
        
        foreach ($advancing_participants as $index => $participant) {
            $current_group = $new_groups[$group_index];
            
            // Insert participant into new group
            $participant_data = [
                'group_id' => $current_group['id'],
                'tournament_id' => $tournament_id,
                'status' => 'active',
                'assigned_at' => date('Y-m-d H:i:s'),
                'previous_round_rank' => $participant['previous_rank'],
                'previous_round_points' => $participant['points']
            ];
            
            if ($is_solo) {
                $participant_data['user_id'] = $participant['id'];
            } else {
                $participant_data['team_id'] = $participant['id'];
            }
            
            $supabase->insert('group_participants', $participant_data);
            
            // Snake draft movement
            if ($direction == 1) {
                $group_index++;
                if ($group_index >= count($new_groups)) {
                    $group_index = count($new_groups) - 1;
                    $direction = -1;
                }
            } else {
                $group_index--;
                if ($group_index < 0) {
                    $group_index = 0;
                    $direction = 1;
                }
            }
        }
        
        // Update group participant counts
        foreach ($new_groups as $group) {
            $participant_count = $supabase->select('group_participants', 'id', [
                'group_id' => $group['id'],
                'status' => 'active'
            ]);
            
            $supabase->update('tournament_groups', [
                'current_teams' => count($participant_count ?: []),
                'status' => 'ready'
            ], ['id' => $group['id']]);
        }
        
        return [
            'success' => true,
            'message' => "Round $new_round_number created successfully! " . count($advancing_participants) . " participants advanced to $new_groups_count new groups."
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error creating next round: ' . $e->getMessage()];
    }
}

/**
 * Get group standings sorted by points
 */
function getGroupStandings($supabase, $group_id, $is_solo) {
    $standings = [];
    
    if ($is_solo) {
        // Get all participants in this group with their match results
        $participants_data = $supabase->select('group_participants', '*', [
            'group_id' => $group_id,
            'status' => 'active'
        ]);
        
        if ($participants_data) {
            foreach ($participants_data as $participant) {
                // Get user details
                $user_data = $supabase->select('users', 'id, username', ['id' => $participant['user_id']], null, 1);
                if (!$user_data) continue;
                
                $user = $user_data[0];
                $participant_id = $user['id'];
                $participant_name = $user['username'];
                
                // Get all match results for this participant in this group
                $match_results = $supabase->select('group_match_participants', '*', [
                    'user_id' => $participant_id
                ]);
                
                // Filter results to only matches from this group
                $group_results = [];
                if ($match_results) {
                    foreach ($match_results as $result) {
                        $match_data = $supabase->select('group_matches', 'group_id', ['id' => $result['match_id']], null, 1);
                        if ($match_data && $match_data[0]['group_id'] == $group_id) {
                            $group_results[] = $result;
                        }
                    }
                }
                
                // Calculate statistics
                $total_points = 0;
                $total_kills = 0;
                $matches_played = count($group_results);
                $best_placement = $matches_played > 0 ? min(array_column($group_results, 'placement')) : 0;
                
                foreach ($group_results as $result) {
                    $total_points += $result['total_points'] ?? 0;
                    $total_kills += $result['kills'] ?? 0;
                }
                
                $standings[] = [
                    'id' => $participant_id,
                    'name' => $participant_name,
                    'total_points' => $total_points,
                    'total_kills' => $total_kills,
                    'matches_played' => $matches_played,
                    'best_placement' => $best_placement ?: '-'
                ];
            }
        }
    } else {
        // Similar logic for teams - implement if needed
        // ... team logic here
    }
    
    // Sort by total points (descending), then by total kills (descending)
    usort($standings, function($a, $b) {
        if ($a['total_points'] != $b['total_points']) {
            return $b['total_points'] - $a['total_points'];
        }
        return $b['total_kills'] - $a['total_kills'];
    });
    
    return $standings;
}

/**
 * Finalize tournament by declaring winners
 */
function finalizeTournament($supabase, $tournament_id, $final_round_number, $winners_count, $is_solo) {
    try {
        // Get final round groups
        $final_groups = $supabase->select('tournament_groups', '*', [
            'tournament_id' => $tournament_id,
            'round_number' => $final_round_number
        ]);
        
        // Collect all participants from final round
        $all_finalists = [];
        foreach ($final_groups as $group) {
            $group_standings = getGroupStandings($supabase, $group['id'], $is_solo);
            $all_finalists = array_merge($all_finalists, $group_standings);
        }
        
        // Sort all finalists by points
        usort($all_finalists, function($a, $b) {
            if ($a['total_points'] != $b['total_points']) {
                return $b['total_points'] - $a['total_points'];
            }
            return $b['total_kills'] - $a['total_kills'];
        });
        
        // Create winners list
        $winners = array_slice($all_finalists, 0, $winners_count);
        
        // Update tournament status
        $supabase->update('tournaments', [
            'status' => 'completed',
            'phase' => 'finished',
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $tournament_id]);
        
        // Store tournament results (you might want to create a results table)
        foreach ($winners as $index => $winner) {
            $supabase->insert('tournament_results', [
                'tournament_id' => $tournament_id,
                'participant_id' => $winner['id'],
                'participant_type' => $is_solo ? 'user' : 'team',
                'final_position' => $index + 1,
                'total_points' => $winner['total_points'],
                'total_kills' => $winner['total_kills'],
                'prize_amount' => calculatePrizeAmount($tournament, $index + 1),
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
        
        return [
            'success' => true,
            'message' => "Tournament finalized! Top $winners_count winners have been declared."
        ];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Error finalizing tournament: ' . $e->getMessage()];
    }
}

function calculatePrizeAmount($tournament, $position) {
    $prize_pool = $tournament['prize_pool'];
    
    // Example prize distribution (customize as needed)
    $distribution = [
        1 => 0.50, // 50% for 1st place
        2 => 0.25, // 25% for 2nd place
        3 => 0.15, // 15% for 3rd place
        4 => 0.05, // 5% for 4th place
        5 => 0.03, // 3% for 5th place
    ];
    
    return isset($distribution[$position]) ? ($prize_pool * $distribution[$position]) : 0;
}

// Get current tournament structure
$tournament_structure = getTournamentStructure($supabase, $tournament_id);

function getTournamentStructure($supabase, $tournament_id) {
    // Get all rounds for this tournament
    $all_groups = $supabase->select('tournament_groups', '*', [
        'tournament_id' => $tournament_id
    ], 'round_number.asc,group_number.asc');
    
    $structure = [];
    foreach ($all_groups as $group) {
        $round_num = $group['round_number'] ?? 1;
        if (!isset($structure[$round_num])) {
            $structure[$round_num] = [];
        }
        
        // Get participant count for this group
        $participants = $supabase->select('group_participants', 'id', [
            'group_id' => $group['id'],
            'status' => 'active'
        ]);
        
        $group['participant_count'] = count($participants ?: []);
        $structure[$round_num][] = $group;
    }
    
    return $structure;
}

include '../../includes/admin-header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include '../../includes/admin-sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <div>
                    <h1><i class="bi bi-layers me-2"></i>Multi-Round Group Stage</h1>
                    <h5 class="text-muted"><?php echo htmlspecialchars($tournament['name']); ?> (<?php echo $tournament['game_name']; ?>)</h5>
                    <p class="text-info mb-0">BMPS-Style Progressive Tournament</p>
                </div>
                <div class="btn-group">
                    <a href="tournament-groups.php?id=<?php echo $tournament_id; ?>" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Groups
                    </a>
                    <a href="group-standings.php?id=<?php echo $tournament_id; ?>" class="btn btn-info">
                        <i class="bi bi-award"></i> Standings
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

            <!-- Tournament Structure Overview -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0"><i class="bi bi-diagram-3"></i> Tournament Structure</h6>
                </div>
                <div class="card-body">
                    <?php if (empty($tournament_structure)): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            No tournament structure found. Please create groups first.
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($tournament_structure as $round_num => $groups): ?>
                                <div class="col-md-6 col-lg-4 mb-3">
                                    <div class="card h-100 <?php echo count($tournament_structure) == $round_num ? 'border-success' : ''; ?>">
                                        <div class="card-header bg-<?php echo count($tournament_structure) == $round_num ? 'success' : 'light'; ?> <?php echo count($tournament_structure) == $round_num ? 'text-white' : ''; ?>">
                                            <h6 class="mb-0">Round <?php echo $round_num; ?></h6>
                                            <small><?php echo count($groups); ?> Groups</small>
                                        </div>
                                        <div class="card-body">
                                            <?php 
                                            $total_participants = array_sum(array_column($groups, 'participant_count')); 
                                            $completed_groups = count(array_filter($groups, function($g) { return $g['status'] === 'completed'; }));
                                            ?>
                                            <p class="card-text mb-2">
                                                <strong><?php echo $total_participants; ?></strong> <?php echo $is_solo ? 'Players' : 'Teams'; ?>
                                                <br>
                                                <small class="text-muted"><?php echo $completed_groups; ?>/<?php echo count($groups); ?> groups completed</small>
                                            </p>
                                            
                                            <?php if (count($tournament_structure) == $round_num): ?>
                                                <div class="d-grid gap-2">
                                                    <?php if ($completed_groups == count($groups) && $total_participants > 8): ?>
                                                        <button class="btn btn-success btn-sm" onclick="showCreateNextRoundModal(<?php echo $round_num; ?>, <?php echo $total_participants; ?>)">
                                                            <i class="bi bi-arrow-right-circle"></i> Create Next Round
                                                        </button>
                                                    <?php elseif ($completed_groups == count($groups) && $total_participants <= 8): ?>
                                                        <button class="btn btn-warning btn-sm" onclick="showFinalizeModal(<?php echo $round_num; ?>, <?php echo $total_participants; ?>)">
                                                            <i class="bi bi-trophy"></i> Finalize Tournament
                                                        </button>
                                                    <?php else: ?>
                                                        <small class="text-muted">Complete all matches to proceed</small>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Detailed Round Information -->
            <?php foreach ($tournament_structure as $round_num => $groups): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h6 class="mb-0">Round <?php echo $round_num; ?> - Detailed View</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($groups as $group): ?>
                                <div class="col-md-4 mb-3">
                                    <div class="card">
                                        <div class="card-body">
                                            <h6 class="card-title"><?php echo htmlspecialchars($group['group_name']); ?></h6>
                                            <p class="card-text">
                                                <span class="badge bg-primary"><?php echo $group['participant_count']; ?>/<?php echo $group['max_teams']; ?></span>
                                                <?php echo $is_solo ? 'Players' : 'Teams'; ?>
                                                <br>
                                                <span class="badge bg-<?php 
                                                    echo match($group['status']) {
                                                        'ready' => 'success',
                                                        'in_progress' => 'primary', 
                                                        'completed' => 'secondary',
                                                        'forming' => 'info',
                                                        default => 'warning'
                                                    };
                                                ?>"><?php echo ucfirst($group['status']); ?></span>
                                            </p>
                                            <div class="btn-group btn-group-sm w-100">
                                                <a href="tournament-schedule.php?id=<?php echo $tournament_id; ?>&group_id=<?php echo $group['id']; ?>" class="btn btn-outline-info">
                                                    <i class="bi bi-calendar"></i> Matches
                                                </a>
                                                <a href="group-standings.php?id=<?php echo $tournament_id; ?>&group_id=<?php echo $group['id']; ?>" class="btn btn-outline-primary">
                                                    <i class="bi bi-list-ol"></i> Standings
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </main>
    </div>
</div>

<!-- Create Next Round Modal -->
<div class="modal fade" id="createNextRoundModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create Next Round</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_next_round">
                <input type="hidden" name="current_round_number" id="current_round_number">
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        <strong>Progressive Tournament System:</strong> Select top performers from each current group to advance to the next round with new group distribution.
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Advance Per Group</label>
                            <select class="form-select" name="advancement_per_group" id="advancement_per_group" required>
                                <option value="2">Top 2 per group</option>
                                <option value="3" selected>Top 3 per group</option>
                                <option value="4">Top 4 per group</option>
                                <option value="5">Top 5 per group</option>
                            </select>
                            <small class="text-muted">How many participants advance from each current group</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">New Groups Count</label>
                            <input type="number" class="form-select" name="new_groups_count" id="new_groups_count" min="2" max="8" value="4" required>
                            <small class="text-muted">How many groups in the next round</small>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Participants per New Group</label>
                            <input type="number" class="form-control" name="participants_per_new_group" id="participants_per_new_group" min="4" max="20" value="6" required>
                            <small class="text-muted">Maximum participants in each new group</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Expected Total</label>
                            <input type="text" class="form-control" id="expected_total" readonly>
                            <small class="text-muted">Total participants advancing</small>
                        </div>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Note:</strong> This will create new groups and redistribute participants using balanced algorithm (strongest players spread across groups).
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-arrow-right-circle"></i> Create Next Round
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Finalize Tournament Modal -->
<div class="modal fade" id="finalizeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Finalize Tournament</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="finalize_tournament">
                <input type="hidden" name="final_round_number" id="final_round_number">
                <div class="modal-body">
                    <div class="alert alert-success">
                        <i class="bi bi-trophy"></i>
                        <strong>Tournament Complete!</strong> All rounds are finished. Time to declare the winners.
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Number of Winners</label>
                        <select class="form-select" name="winners_count" required>
                            <option value="1">Winner only (1st place)</option>
                            <option value="3" selected>Top 3 (Podium finishers)</option>
                            <option value="5">Top 5</option>
                            <option value="8">Top 8</option>
                        </select>
                        <small class="text-muted">How many winners to officially declare</small>
                    </div>
                    
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        <strong>Important:</strong> This will mark the tournament as completed and calculate final prize distribution.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-trophy"></i> Finalize Tournament
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function showCreateNextRoundModal(currentRound, totalParticipants) {
    document.getElementById('current_round_number').value = currentRound;
    
    // Calculate suggested values
    const advancementSelect = document.getElementById('advancement_per_group');
    const newGroupsInput = document.getElementById('new_groups_count');
    const participantsPerGroupInput = document.getElementById('participants_per_new_group');
    
    // Update calculations when values change
    function updateCalculations() {
        const advancement = parseInt(advancementSelect.value);
        const currentGroups = <?php echo count($tournament_structure[max(array_keys($tournament_structure))]); ?>;
        const totalAdvancing = currentGroups * advancement;
        const newGroups = parseInt(newGroupsInput.value);
        const perGroup = Math.ceil(totalAdvancing / newGroups);
        
        participantsPerGroupInput.value = perGroup;
        document.getElementById('expected_total').value = totalAdvancing + ' participants advancing';
    }
    
    advancementSelect.addEventListener('change', updateCalculations);
    newGroupsInput.addEventListener('input', updateCalculations);
    
    updateCalculations(); // Initial calculation
    
    new bootstrap.Modal(document.getElementById('createNextRoundModal')).show();
}

function showFinalizeModal(finalRound, totalParticipants) {
    document.getElementById('final_round_number').value = finalRound;
    new bootstrap.Modal(document.getElementById('finalizeModal')).show();
}
</script>

</body>
</html>
