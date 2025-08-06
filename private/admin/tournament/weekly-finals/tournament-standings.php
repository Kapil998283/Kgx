<?php
// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once dirname(__DIR__, 2) . '/admin_secure_config.php';

// Use the new admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

// Initialize Supabase connection with admin privileges
$supabase = new SupabaseClient(true);

// Include the Weekly Finals Manager
require_once 'WeeklyFinalsManager.php';
$weeklyManager = new WeeklyFinalsManager();

// Get tournament ID
$tournamentId = $_GET['id'] ?? 0;
$selectedPhase = $_GET['phase'] ?? null;

if (!$tournamentId) {
    header('Location: ../index.php');
    exit();
}

// Fetch tournament details
try {
    $tournament = $supabase->select('tournaments', '*', ['id' => $tournamentId])[0] ?? null;
    if (!$tournament) {
        throw new Exception('Tournament not found');
    }
} catch (Exception $e) {
    $_SESSION['error'] = 'Tournament not found: ' . $e->getMessage();
    header('Location: ../index.php');
    exit();
}

// Fetch tournament phases
try {
    $phases = $supabase->select('tournament_phases', '*', ['tournament_id' => $tournamentId], 'phase_number.asc');
    
    // If specific phase selected, get its standings, otherwise get all
    if ($selectedPhase) {
        $standings = $weeklyManager->getPhaseStandings($selectedPhase);
        $currentPhase = array_filter($phases, fn($p) => $p['id'] == $selectedPhase)[0] ?? null;
    } else {
        $allStandings = $weeklyManager->getAllPhaseStandings($tournamentId);
        $currentPhase = null;
    }
} catch (Exception $e) {
    $phases = [];
    $standings = [];
    $allStandings = [];
}

// Get tournament progress
$tournamentProgress = $weeklyManager->getTournamentProgress($tournamentId);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weekly Finals Standings - <?php echo htmlspecialchars($tournament['name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <style>
        .standings-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        .standings-header {
            background: linear-gradient(135deg, #6f42c1, #5a2d8f);
            color: white;
            padding: 20px;
            border-radius: 10px 10px 0 0;
        }
        .standings-table {
            margin: 0;
        }
        .standings-table th {
            background: #f8f9fa;
            border: none;
            font-weight: 600;
            padding: 15px 10px;
            font-size: 12px;
            text-transform: uppercase;
            color: #6c757d;
        }
        .standings-table td {
            padding: 12px 10px;
            border: none;
            border-bottom: 1px solid #f1f3f4;
            vertical-align: middle;
        }
        .rank-cell {
            width: 60px;
            text-align: center;
        }
        .rank-badge {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
        }
        .rank-1 { background: linear-gradient(135deg, #ffd700, #ffed4a); color: #333; }
        .rank-2 { background: linear-gradient(135deg, #c0c0c0, #e2e8f0); color: #333; }
        .rank-3 { background: linear-gradient(135deg, #cd7f32, #d69e2e); color: white; }
        .rank-default { background: #6c757d; color: white; }
        
        .qualification-status {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 500;
            text-transform: uppercase;
        }
        .status-qualified { background: #d4edda; color: #155724; }
        .status-eliminated { background: #f8d7da; color: #721c24; }
        .status-active { background: #cff4fc; color: #055160; }
        
        .team-info {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .team-avatar {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 14px;
        }
        .team-details h6 {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
        }
        .team-details small {
            color: #6c757d;
            font-size: 12px;
        }
        
        .points-breakdown {
            text-align: right;
        }
        .total-points {
            font-size: 18px;
            font-weight: bold;
            color: #0d6efd;
        }
        .points-detail {
            font-size: 11px;
            color: #6c757d;
        }
        
        .phase-tabs {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .progress-overview {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .empty-standings {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .phase-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .summary-card {
            background: rgba(255,255,255,0.1);
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }
        
        .summary-value {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .summary-label {
            font-size: 12px;
            opacity: 0.8;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../../includes/admin-header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <div>
                        <h1 class="h2">Weekly Finals Standings</h1>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="../index.php">Tournaments</a></li>
                                <li class="breadcrumb-item"><a href="tournament-phases.php?id=<?php echo $tournamentId; ?>"><?php echo htmlspecialchars($tournament['name']); ?></a></li>
                                <li class="breadcrumb-item active">Standings</li>
                            </ol>
                        </nav>
                    </div>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <a href="tournament-phases.php?id=<?php echo $tournamentId; ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-layers"></i> Phases
                            </a>
                            <a href="tournament-schedule.php?id=<?php echo $tournamentId; ?>" class="btn btn-sm btn-outline-success">
                                <i class="bi bi-calendar"></i> Schedule
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Tournament Progress Overview -->
                <?php if ($tournamentProgress['total_phases'] > 0): ?>
                <div class="progress-overview">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h3>Tournament Progress</h3>
                            <p class="mb-0">Progressive weekly elimination tournament</p>
                            <?php if ($tournamentProgress['current_phase']): ?>
                            <p class="mb-0"><strong>Current Phase:</strong> <?php echo htmlspecialchars($tournamentProgress['current_phase']['phase_name']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <div class="phase-summary">
                                <div class="summary-card">
                                    <div class="summary-value"><?php echo $tournamentProgress['completed_phases']; ?></div>
                                    <div class="summary-label">Completed</div>
                                </div>
                                <div class="summary-card">
                                    <div class="summary-value"><?php echo $tournamentProgress['total_phases']; ?></div>
                                    <div class="summary-label">Total Phases</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Phase Selection Tabs -->
                <?php if (!empty($phases)): ?>
                <div class="phase-tabs">
                    <ul class="nav nav-pills nav-fill">
                        <li class="nav-item">
                            <a class="nav-link <?php echo !$selectedPhase ? 'active' : ''; ?>" href="tournament-standings.php?id=<?php echo $tournamentId; ?>">
                                <i class="bi bi-list"></i> All Phases
                            </a>
                        </li>
                        <?php foreach ($phases as $phase): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php echo $selectedPhase == $phase['id'] ? 'active' : ''; ?>" 
                               href="tournament-standings.php?id=<?php echo $tournamentId; ?>&phase=<?php echo $phase['id']; ?>">
                                <i class="bi bi-trophy"></i> <?php echo htmlspecialchars($phase['phase_name']); ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if ($selectedPhase && $currentPhase): ?>
                <!-- Single Phase Standings -->
                <div class="standings-card">
                    <div class="standings-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h3 class="mb-1"><?php echo htmlspecialchars($currentPhase['phase_name']); ?></h3>
                                <p class="mb-0">Phase <?php echo $currentPhase['phase_number']; ?> - <?php echo ucfirst($currentPhase['status']); ?></p>
                            </div>
                            <div class="text-end">
                                <div class="summary-card">
                                    <div class="summary-value"><?php echo $currentPhase['advancement_slots']; ?></div>
                                    <div class="summary-label">Advance to Next</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($standings)): ?>
                    <div class="table-responsive">
                        <table class="table standings-table">
                            <thead>
                                <tr>
                                    <th class="rank-cell">Rank</th>
                                    <th>Team</th>
                                    <th class="text-center">Matches</th>
                                    <th class="text-center">Kills</th>
                                    <th class="text-center">Avg Place</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-end">Points</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($standings as $index => $standing): ?>
                                <tr>
                                    <td class="rank-cell">
                                        <span class="rank-badge rank-<?php echo $index < 3 ? $index + 1 : 'default'; ?>">
                                            <?php echo $standing['current_rank'] ?? $index + 1; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="team-info">
                                            <div class="team-avatar">
                                                <?php echo substr($standing['team_name'] ?? 'T', 0, 1); ?>
                                            </div>
                                            <div class="team-details">
                                                <h6><?php echo htmlspecialchars($standing['team_name'] ?? 'Team ' . ($index + 1)); ?></h6>
                                                <small><?php echo htmlspecialchars($standing['user_name'] ?? ''); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-center"><?php echo $standing['matches_played'] ?? 0; ?></td>
                                    <td class="text-center"><?php echo $standing['total_kills'] ?? 0; ?></td>
                                    <td class="text-center"><?php echo number_format($standing['average_placement'] ?? 0, 1); ?></td>
                                    <td class="text-center">
                                        <?php
                                        $status = $standing['status'] ?? 'active';
                                        $statusClass = 'status-' . $status;
                                        ?>
                                        <span class="qualification-status <?php echo $statusClass; ?>">
                                            <?php echo ucfirst($status); ?>
                                        </span>
                                    </td>
                                    <td class="points-breakdown">
                                        <div class="total-points"><?php echo number_format($standing['total_points'] ?? 0); ?></div>
                                        <div class="points-detail">
                                            K: <?php echo $standing['kill_points'] ?? 0; ?> | 
                                            P: <?php echo $standing['placement_points'] ?? 0; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="empty-standings">
                        <i class="bi bi-trophy fs-1 text-muted d-block mb-3"></i>
                        <h4>No Standings Available</h4>
                        <p>Standings will appear once matches are played and scored.</p>
                    </div>
                    <?php endif; ?>
                </div>

                <?php elseif (!empty($allStandings)): ?>
                <!-- All Phases Standings -->
                <?php foreach ($allStandings as $phaseName => $phaseStandings): ?>
                <div class="standings-card">
                    <div class="standings-header">
                        <h4 class="mb-0"><?php echo htmlspecialchars($phaseName); ?></h4>
                    </div>
                    
                    <?php if (!empty($phaseStandings)): ?>
                    <div class="table-responsive">
                        <table class="table standings-table">
                            <thead>
                                <tr>
                                    <th class="rank-cell">Rank</th>
                                    <th>Team</th>
                                    <th class="text-center">Matches</th>
                                    <th class="text-center">Kills</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-end">Points</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_slice($phaseStandings, 0, 10) as $index => $standing): ?>
                                <tr>
                                    <td class="rank-cell">
                                        <span class="rank-badge rank-<?php echo $index < 3 ? $index + 1 : 'default'; ?>">
                                            <?php echo $standing['current_rank'] ?? $index + 1; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="team-info">
                                            <div class="team-avatar">
                                                <?php echo substr($standing['team_name'] ?? 'T', 0, 1); ?>
                                            </div>
                                            <div class="team-details">
                                                <h6><?php echo htmlspecialchars($standing['team_name'] ?? 'Team ' . ($index + 1)); ?></h6>
                                                <small><?php echo htmlspecialchars($standing['user_name'] ?? ''); ?></small>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-center"><?php echo $standing['matches_played'] ?? 0; ?></td>
                                    <td class="text-center"><?php echo $standing['total_kills'] ?? 0; ?></td>
                                    <td class="text-center">
                                        <?php
                                        $status = $standing['status'] ?? 'active';
                                        $statusClass = 'status-' . $status;
                                        ?>
                                        <span class="qualification-status <?php echo $statusClass; ?>">
                                            <?php echo ucfirst($status); ?>
                                        </span>
                                    </td>
                                    <td class="points-breakdown">
                                        <div class="total-points"><?php echo number_format($standing['total_points'] ?? 0); ?></div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if (count($phaseStandings) > 10): ?>
                    <div class="text-center p-3">
                        <a href="tournament-standings.php?id=<?php echo $tournamentId; ?>&phase=<?php echo array_search($phaseName, array_keys($allStandings)); ?>" class="btn btn-outline-primary btn-sm">
                            View Full Standings
                        </a>
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="empty-standings">
                        <p class="text-muted mb-0">No standings available for this phase yet.</p>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <?php else: ?>
                <!-- No Standings -->
                <div class="empty-standings">
                    <i class="bi bi-trophy fs-1 text-muted d-block mb-3"></i>
                    <h4>No Standings Available</h4>
                    <p class="text-muted">Tournament phases need to be created and matches played before standings appear.</p>
                    <a href="tournament-phases.php?id=<?php echo $tournamentId; ?>" class="btn btn-primary">
                        <i class="bi bi-layers"></i> Manage Phases
                    </a>
                </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
