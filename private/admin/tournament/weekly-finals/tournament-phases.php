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

// Handle phase operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($_POST['action'] ?? '') {
            case 'create_phases':
                $config = [
                    'total_weeks' => (int)$_POST['total_weeks'],
                    'initial_participants' => (int)$tournament['max_teams'],
                    'finals_participants' => (int)$_POST['finals_participants']
                ];
                
                $phases = $weeklyManager->createPhases($tournamentId, $config);
                $_SESSION['success'] = 'Tournament phases created successfully!';
                break;
                
            case 'initialize_first_phase':
                $participants = $weeklyManager->initializeFirstPhase($tournamentId);
                $_SESSION['success'] = 'First phase initialized with ' . count($participants) . ' participants!';
                break;
                
            case 'start_next_phase':
                $nextPhase = $weeklyManager->startNextPhase($tournamentId);
                $_SESSION['success'] = 'Started phase: ' . $nextPhase['phase_name'];
                break;
                
            case 'complete_phase':
                $phaseId = (int)$_POST['phase_id'];
                $weeklyManager->completePhase($phaseId);
                $_SESSION['success'] = 'Phase completed successfully!';
                break;
                
            case 'advance_participants':
                $fromPhaseId = (int)$_POST['from_phase_id'];
                $count = $weeklyManager->advanceParticipants($fromPhaseId);
                $_SESSION['success'] = "Advanced $count participants to next phase!";
                break;
        }
    } catch (Exception $e) {
        $_SESSION['error'] = 'Operation failed: ' . $e->getMessage();
    }
    
    header('Location: tournament-phases.php?id=' . $tournamentId);
    exit();
}

// Fetch tournament phases
try {
    $phases = $supabase->select('tournament_phases', '*', ['tournament_id' => $tournamentId], 'phase_number.asc');
    $tournamentProgress = $weeklyManager->getTournamentProgress($tournamentId);
} catch (Exception $e) {
    $phases = [];
    $tournamentProgress = ['total_phases' => 0, 'completed_phases' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weekly Finals Phases - <?php echo htmlspecialchars($tournament['name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <style>
        .phase-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 20px;
            transition: box-shadow 0.2s;
        }
        .phase-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .phase-header {
            padding: 15px;
            border-bottom: 1px solid #dee2e6;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
        }
        .phase-body {
            padding: 20px;
        }
        .status-active {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        .status-completed {
            background-color: #d4edda;
            color: #155724;
        }
        .status-upcoming {
            background-color: #fff3cd;
            color: #856404;
        }
        .progress-overview {
            background: linear-gradient(135deg, #007bff, #0056b3);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
        }
        .phase-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 15px;
        }
        .stat-box {
            background: white;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            border: 1px solid #e3e6ea;
        }
        .stat-value {
            font-size: 24px;
            font-weight: bold;
            color: #0d6efd;
        }
        .stat-label {
            font-size: 12px;
            color: #6c757d;
            margin-top: 5px;
        }
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .btn-group-vertical .btn {
            margin-bottom: 5px;
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
                        <h1 class="h2">Weekly Finals Phases</h1>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="../index.php">Tournaments</a></li>
                                <li class="breadcrumb-item active"><?php echo htmlspecialchars($tournament['name']); ?></li>
                            </ol>
                        </nav>
                    </div>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <a href="tournament-schedule.php?id=<?php echo $tournamentId; ?>" class="btn btn-sm btn-primary">
                                <i class="bi bi-calendar"></i> Schedule
                            </a>
                            <a href="tournament-standings.php?id=<?php echo $tournamentId; ?>" class="btn btn-sm btn-info">
                                <i class="bi bi-trophy"></i> Standings
                            </a>
                        </div>
                        <?php if (empty($phases)): ?>
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#createPhasesModal">
                            <i class="bi bi-plus-lg"></i> Create Phases
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tournament Progress Overview -->
                <div class="progress-overview">
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <h3>Tournament Progress</h3>
                            <p class="mb-0">Progressive weekly elimination tournament</p>
                            <?php if ($tournamentProgress['current_phase']): ?>
                            <p class="mb-0"><strong>Current Phase:</strong> <?php echo htmlspecialchars($tournamentProgress['current_phase']['phase_name']); ?></p>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="progress" style="height: 25px;">
                                <div class="progress-bar bg-warning" role="progressbar" 
                                     style="width: <?php echo $tournamentProgress['total_phases'] ? ($tournamentProgress['completed_phases'] / $tournamentProgress['total_phases'] * 100) : 0; ?>%">
                                    <?php echo $tournamentProgress['completed_phases']; ?> / <?php echo $tournamentProgress['total_phases']; ?> Phases
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (empty($phases)): ?>
                <!-- No Phases Created -->
                <div class="alert alert-info text-center">
                    <i class="bi bi-info-circle fs-1 text-primary d-block mb-3"></i>
                    <h4>No Phases Created</h4>
                    <p>Create tournament phases to start managing your weekly finals tournament.</p>
                    <button type="button" class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#createPhasesModal">
                        <i class="bi bi-plus-lg"></i> Create Tournament Phases
                    </button>
                </div>
                <?php else: ?>

                <!-- Phases Grid -->
                <div class="row">
                    <?php foreach ($phases as $phase): ?>
                    <div class="col-lg-6 col-xl-4">
                        <div class="phase-card">
                            <div class="phase-header status-<?php echo $phase['status']; ?>">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <h5 class="mb-1"><?php echo htmlspecialchars($phase['phase_name']); ?></h5>
                                        <small>Phase <?php echo $phase['phase_number']; ?> - <?php echo ucfirst($phase['phase_type']); ?></small>
                                    </div>
                                    <span class="badge bg-secondary"><?php echo ucfirst($phase['status']); ?></span>
                                </div>
                            </div>
                            
                            <div class="phase-body">
                                <div class="phase-stats">
                                    <div class="stat-box">
                                        <div class="stat-value"><?php echo $phase['max_participants']; ?></div>
                                        <div class="stat-label">Max Participants</div>
                                    </div>
                                    <div class="stat-box">
                                        <div class="stat-value"><?php echo $phase['advancement_slots']; ?></div>
                                        <div class="stat-label">Advance</div>
                                    </div>
                                    <div class="stat-box">
                                        <div class="stat-value"><?php echo $phase['elimination_slots']; ?></div>
                                        <div class="stat-label">Eliminated</div>
                                    </div>
                                </div>
                                
                                <div class="mt-3">
                                    <small class="text-muted">
                                        <i class="bi bi-calendar"></i> 
                                        <?php echo date('M j', strtotime($phase['start_date'])); ?> - 
                                        <?php echo date('M j, Y', strtotime($phase['end_date'])); ?>
                                    </small>
                                </div>
                                
                                <div class="action-buttons mt-3">
                                    <?php if ($phase['status'] === 'upcoming' && $phase['phase_number'] === 1): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="initialize_first_phase">
                                        <button type="submit" class="btn btn-sm btn-success">
                                            <i class="bi bi-play"></i> Initialize
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    
                                    <?php if ($phase['status'] === 'upcoming' && $phase['phase_number'] > 1): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="start_next_phase">
                                        <button type="submit" class="btn btn-sm btn-primary">
                                            <i class="bi bi-play-circle"></i> Start
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    
                                    <?php if ($phase['status'] === 'active'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="complete_phase">
                                        <input type="hidden" name="phase_id" value="<?php echo $phase['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-warning">
                                            <i class="bi bi-check-circle"></i> Complete
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    
                                    <a href="tournament-standings.php?id=<?php echo $tournamentId; ?>&phase=<?php echo $phase['id']; ?>" class="btn btn-sm btn-info">
                                        <i class="bi bi-list-ol"></i> Standings
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Create Phases Modal -->
    <div class="modal fade" id="createPhasesModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Create Tournament Phases</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create_phases">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Total Weeks</label>
                            <select class="form-select" name="total_weeks" required>
                                <option value="3">3 Weeks</option>
                                <option value="4" selected>4 Weeks (Standard)</option>
                                <option value="5">5 Weeks</option>
                                <option value="6">6 Weeks</option>
                            </select>
                            <div class="form-text">Number of weekly phases in the tournament</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Finals Participants</label>
                            <select class="form-select" name="finals_participants" required>
                                <option value="12">12 Teams</option>
                                <option value="16" selected>16 Teams</option>
                                <option value="20">20 Teams</option>
                                <option value="24">24 Teams</option>
                            </select>
                            <div class="form-text">Number of teams advancing to grand finals</div>
                        </div>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            <strong>Tournament Structure:</strong> Progressive weekly elimination leading to grand finals with the specified number of top performers.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Phases</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
