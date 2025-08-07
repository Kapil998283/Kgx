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

// Get tournament groups with round information
$groups_data = $supabase->select('tournament_groups', '*', ['tournament_id' => $tournament_id], 'round_number.asc,group_name.asc');
$groups = $groups_data ?: [];

// Group by rounds for multi-round display
$groups_by_round = [];
foreach ($groups as $group) {
    $round_num = $group['round_number'] ?? 1;
    if (!isset($groups_by_round[$round_num])) {
        $groups_by_round[$round_num] = [];
    }
    $groups_by_round[$round_num][] = $group;
}

// Selected group for filtering
$selected_group = null;
if ($group_id) {
    $group_data = $supabase->select('tournament_groups', '*', ['id' => $group_id], null, 1);
    $selected_group = $group_data ? $group_data[0] : null;
}

// Function to get group standings
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
                $avg_placement = $matches_played > 0 ? array_sum(array_column($group_results, 'placement')) / $matches_played : 0;
                
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
                    'best_placement' => $best_placement ?: '-',
                    'avg_placement' => $matches_played > 0 ? round($avg_placement, 1) : '-',
                    'avg_points' => $matches_played > 0 ? round($total_points / $matches_played, 1) : 0
                ];
            }
        }
    } else {
        // Get all teams in this group with their match results
        $teams_data = $supabase->select('group_participants', '*', [
            'group_id' => $group_id,
            'status' => 'active'
        ]);
        
        // Filter for team participants only
        $teams_data = array_filter($teams_data ?: [], function($p) {
            return !empty($p['team_id']);
        });
        
        if ($teams_data) {
            foreach ($teams_data as $team_entry) {
                // Get team details
                $team_data = $supabase->select('teams', 'id, name', ['id' => $team_entry['team_id']], null, 1);
                if (!$team_data) continue;
                
                $team = $team_data[0];
                $team_id = $team['id'];
                $team_name = $team['name'];
                
                // Get all match results for this team in this group
                $match_results = $supabase->select('group_match_teams', '*', [
                    'team_id' => $team_id
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
                $avg_placement = $matches_played > 0 ? array_sum(array_column($group_results, 'placement')) / $matches_played : 0;
                
                foreach ($group_results as $result) {
                    $total_points += $result['total_points'] ?? 0;
                    $total_kills += $result['kills'] ?? 0;
                }
                
                $standings[] = [
                    'id' => $team_id,
                    'name' => $team_name,
                    'total_points' => $total_points,
                    'total_kills' => $total_kills,
                    'matches_played' => $matches_played,
                    'best_placement' => $best_placement ?: '-',
                    'avg_placement' => $matches_played > 0 ? round($avg_placement, 1) : '-',
                    'avg_points' => $matches_played > 0 ? round($total_points / $matches_played, 1) : 0
                ];
            }
        }
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

// Get standings data
$all_group_standings = [];
if ($group_id && $selected_group) {
    // Single group standings
    $all_group_standings[$selected_group['group_name']] = getGroupStandings($supabase, $group_id, $is_solo);
} else {
    // All groups standings
    foreach ($groups as $group) {
        $all_group_standings[$group['group_name']] = getGroupStandings($supabase, $group['id'], $is_solo);
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
                    <h1><i class="bi bi-award me-2"></i>Group Stage Standings</h1>
                    <h5 class="text-muted"><?php echo htmlspecialchars($tournament['name'] ?? ''); ?> (<?php echo htmlspecialchars($tournament['game_name'] ?? ''); ?>)</h5>
                    <?php if ($selected_group): ?>
                        <p class="text-info mb-0">Viewing: <?php echo htmlspecialchars($selected_group['group_name']); ?></p>
                    <?php endif; ?>
                </div>
                <div class="btn-group">
                    <a href="tournament-groups.php?id=<?php echo $tournament_id; ?>" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Back to Groups
                    </a>
                    <a href="tournament-schedule.php?id=<?php echo $tournament_id; ?>" class="btn btn-info">
                        <i class="bi bi-calendar"></i> Schedule
                    </a>
                </div>
            </div>

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

            <!-- Multi-Round Tournament Overview -->
            <?php if (!$group_id && count($groups_by_round) > 1): ?>
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h6 class="mb-0"><i class="bi bi-layers me-2"></i>Multi-Round Tournament Progression</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($groups_by_round as $round_num => $round_groups): ?>
                                <div class="col-md-4 mb-3">
                                    <div class="card border-primary">
                                        <div class="card-header bg-light">
                                            <h6 class="mb-0 text-center">Round <?php echo $round_num; ?></h6>
                                            <small class="text-muted d-block text-center"><?php echo count($round_groups); ?> Groups</small>
                                        </div>
                                        <div class="card-body text-center">
                                            <?php 
                                            $total_participants = 0;
                                            foreach ($round_groups as $group) {
                                                $participants = $supabase->select('group_participants', 'id', [
                                                    'group_id' => $group['id'],
                                                    'status' => 'active'
                                                ]);
                                                $total_participants += count($participants ?: []);
                                            }
                                            ?>
                                            <h5 class="text-primary"><?php echo $total_participants; ?></h5>
                                            <small class="text-muted"><?php echo $is_solo ? 'Players' : 'Teams'; ?></small>
                                            <div class="mt-2">
                                                <?php foreach ($round_groups as $rg): ?>
                                                    <span class="badge bg-outline-primary me-1 mb-1"><?php echo htmlspecialchars($rg['group_name']); ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="text-center mt-3">
                            <a href="tournament-rounds-progression.php?id=<?php echo $tournament_id; ?>" class="btn btn-success">
                                <i class="bi bi-arrow-right-circle"></i> Manage Round Progression
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Group Standings -->
            <?php if (empty($all_group_standings)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    No groups found or no match results available yet.
                    <a href="tournament-groups.php?id=<?php echo $tournament_id; ?>">Create groups and schedule matches first</a>.
                </div>
            <?php else: ?>
                <?php foreach ($all_group_standings as $group_name => $standings): ?>
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h6 class="mb-0">
                                <i class="bi bi-trophy me-2"></i><?php echo htmlspecialchars($group_name); ?> Standings
                                <span class="badge bg-light text-dark ms-2"><?php echo count($standings); ?> <?php echo $is_solo ? 'Players' : 'Teams'; ?></span>
                            </h6>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($standings)): ?>
                                <div class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox"></i><br>
                                    No participants or match results yet.
                                </div>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-striped mb-0">
                                        <thead class="table-dark">
                                            <tr>
                                                <th style="width: 5%">Rank</th>
                                                <th style="width: 25%"><?php echo $is_solo ? 'Player' : 'Team'; ?></th>
                                                <th style="width: 12%" class="text-center">Total Points</th>
                                                <th style="width: 12%" class="text-center">Total Kills</th>
                                                <th style="width: 12%" class="text-center">Matches</th>
                                                <th style="width: 12%" class="text-center">Best Place</th>
                                                <th style="width: 12%" class="text-center">Avg Place</th>
                                                <th style="width: 10%" class="text-center">Avg Points</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($standings as $index => $participant): ?>
                                                <tr class="<?php echo $index < 3 ? 'table-success' : ''; ?>">
                                                    <td class="text-center">
                                                        <?php 
                                                        $rank = $index + 1;
                                                        if ($rank == 1) echo '<i class="bi bi-trophy-fill text-warning"></i>';
                                                        elseif ($rank == 2) echo '<i class="bi bi-award-fill text-secondary"></i>';
                                                        elseif ($rank == 3) echo '<i class="bi bi-award text-warning"></i>';
                                                        else echo $rank;
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($participant['name']); ?></strong>
                                                    </td>
                                                    <td class="text-center">
                                                        <span class="badge bg-primary"><?php echo $participant['total_points']; ?></span>
                                                    </td>
                                                    <td class="text-center"><?php echo $participant['total_kills']; ?></td>
                                                    <td class="text-center"><?php echo $participant['matches_played']; ?></td>
                                                    <td class="text-center">
                                                        <?php if ($participant['best_placement'] !== '-'): ?>
                                                            <span class="badge bg-success"><?php echo $participant['best_placement']; ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="text-center"><?php echo $participant['avg_placement']; ?></td>
                                                    <td class="text-center"><?php echo $participant['avg_points']; ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Overall Statistics -->
            <?php if (!$group_id && count($all_group_standings) > 1): ?>
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0"><i class="bi bi-bar-chart me-2"></i>Tournament Statistics</h6>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-3">
                                <h4 class="text-primary"><?php echo count($groups); ?></h4>
                                <small class="text-muted">Total Groups</small>
                            </div>
                            <div class="col-md-3">
                                <h4 class="text-success"><?php echo array_sum(array_map('count', $all_group_standings)); ?></h4>
                                <small class="text-muted">Total <?php echo $is_solo ? 'Players' : 'Teams'; ?></small>
                            </div>
                            <div class="col-md-3">
                                <?php 
                                $total_points = 0;
                                foreach ($all_group_standings as $standings) {
                                    foreach ($standings as $participant) {
                                        $total_points += $participant['total_points'];
                                    }
                                }
                                ?>
                                <h4 class="text-warning"><?php echo $total_points; ?></h4>
                                <small class="text-muted">Total Points</small>
                            </div>
                            <div class="col-md-3">
                                <?php 
                                $total_kills = 0;
                                foreach ($all_group_standings as $standings) {
                                    foreach ($standings as $participant) {
                                        $total_kills += $participant['total_kills'];
                                    }
                                }
                                ?>
                                <h4 class="text-danger"><?php echo $total_kills; ?></h4>
                                <small class="text-muted">Total Kills</small>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
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
</script>

</body>
</html>
