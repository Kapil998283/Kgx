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

// Handle schedule operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($_POST['action'] ?? '') {
            case 'create_match':
                $matchData = [
                    'tournament_id' => $tournamentId,
                    'phase_id' => (int)$_POST['phase_id'],
                    'match_number' => (int)$_POST['match_number'],
                    'match_type' => $_POST['match_type'] ?? 'Regular',
                    'scheduled_date' => $_POST['scheduled_date'],
                    'scheduled_time' => $_POST['scheduled_time'],
                    'status' => 'scheduled',
                    'room_id' => $_POST['room_id'] ?? null,
                    'room_password' => $_POST['room_password'] ?? null,
                    'map_name' => $_POST['map_name'] ?? null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                $match = $supabase->insert('tournament_matches', $matchData);
                $_SESSION['success'] = 'Match scheduled successfully!';
                break;
                
            case 'update_match':
                $matchId = (int)$_POST['match_id'];
                $updateData = [
                    'scheduled_date' => $_POST['scheduled_date'],
                    'scheduled_time' => $_POST['scheduled_time'],
                    'room_id' => $_POST['room_id'] ?? null,
                    'room_password' => $_POST['room_password'] ?? null,
                    'map_name' => $_POST['map_name'] ?? null,
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                $supabase->update('tournament_matches', $updateData, ['id' => $matchId]);
                $_SESSION['success'] = 'Match updated successfully!';
                break;
                
            case 'delete_match':
                $matchId = (int)$_POST['match_id'];
                $supabase->delete('tournament_matches', ['id' => $matchId]);
                $_SESSION['success'] = 'Match deleted successfully!';
                break;
        }
    } catch (Exception $e) {
        $_SESSION['error'] = 'Operation failed: ' . $e->getMessage();
    }
    
    header('Location: tournament-schedule.php?id=' . $tournamentId);
    exit();
}

// Fetch tournament phases and matches
try {
    $phases = $supabase->select('tournament_phases', '*', ['tournament_id' => $tournamentId], 'phase_number.asc');
    $matches = $supabase->select('tournament_matches', '*', ['tournament_id' => $tournamentId], 'scheduled_date.asc,scheduled_time.asc');
} catch (Exception $e) {
    $phases = [];
    $matches = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Weekly Finals Schedule - <?php echo htmlspecialchars($tournament['name']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <style>
        .match-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            margin-bottom: 15px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .match-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .match-header {
            padding: 15px;
            border-bottom: 1px solid #dee2e6;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .match-body {
            padding: 20px;
        }
        .status-scheduled {
            background-color: #fff3cd;
            color: #856404;
        }
        .status-live {
            background-color: #f8d7da;
            color: #721c24;
        }
        .status-completed {
            background-color: #d4edda;
            color: #155724;
        }
        .phase-section {
            margin-bottom: 40px;
        }
        .phase-title {
            background: linear-gradient(135deg, #6f42c1, #5a2d8f);
            color: white;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .match-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }
        .info-item {
            padding: 10px;
            background: #f8f9fa;
            border-radius: 6px;
            border-left: 4px solid #0d6efd;
        }
        .info-label {
            font-size: 12px;
            color: #6c757d;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .info-value {
            font-weight: 500;
            font-size: 14px;
        }
        .empty-state {
            text-align: center;
            padding: 40px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 2px dashed #dee2e6;
        }
        .filter-bar {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 30px;
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
                        <h1 class="h2">Weekly Finals Schedule</h1>
                        <nav aria-label="breadcrumb">
                            <ol class="breadcrumb">
                                <li class="breadcrumb-item"><a href="../index.php">Tournaments</a></li>
                                <li class="breadcrumb-item"><a href="tournament-phases.php?id=<?php echo $tournamentId; ?>"><?php echo htmlspecialchars($tournament['name']); ?></a></li>
                                <li class="breadcrumb-item active">Schedule</li>
                            </ol>
                        </nav>
                    </div>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <a href="tournament-phases.php?id=<?php echo $tournamentId; ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-layers"></i> Phases
                            </a>
                            <a href="tournament-standings.php?id=<?php echo $tournamentId; ?>" class="btn btn-sm btn-outline-info">
                                <i class="bi bi-trophy"></i> Standings
                            </a>
                        </div>
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addMatchModal">
                            <i class="bi bi-plus-lg"></i> Schedule Match
                        </button>
                    </div>
                </div>

                <!-- Filter Bar -->
                <div class="filter-bar">
                    <div class="row align-items-center">
                        <div class="col-md-4">
                            <select class="form-select" id="phaseFilter" onchange="filterMatches()">
                                <option value="">All Phases</option>
                                <?php foreach ($phases as $phase): ?>
                                <option value="<?php echo $phase['id']; ?>"><?php echo htmlspecialchars($phase['phase_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <select class="form-select" id="statusFilter" onchange="filterMatches()">
                                <option value="">All Status</option>
                                <option value="scheduled">Scheduled</option>
                                <option value="live">Live</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <input type="date" class="form-control" id="dateFilter" onchange="filterMatches()" placeholder="Filter by date">
                        </div>
                    </div>
                </div>

                <?php if (empty($matches)): ?>
                <!-- No Matches -->
                <div class="empty-state">
                    <i class="bi bi-calendar-x fs-1 text-muted d-block mb-3"></i>
                    <h4>No Matches Scheduled</h4>
                    <p class="text-muted">Create tournament phases first, then schedule matches for each phase.</p>
                    <?php if (!empty($phases)): ?>
                    <button type="button" class="btn btn-primary btn-lg" data-bs-toggle="modal" data-bs-target="#addMatchModal">
                        <i class="bi bi-plus-lg"></i> Schedule First Match
                    </button>
                    <?php else: ?>
                    <a href="tournament-phases.php?id=<?php echo $tournamentId; ?>" class="btn btn-outline-primary btn-lg">
                        <i class="bi bi-layers"></i> Create Phases First
                    </a>
                    <?php endif; ?>
                </div>
                <?php else: ?>

                <!-- Matches by Phase -->
                <?php 
                $matchesByPhase = [];
                foreach ($matches as $match) {
                    $phaseId = $match['phase_id'];
                    if (!isset($matchesByPhase[$phaseId])) {
                        $matchesByPhase[$phaseId] = [];
                    }
                    $matchesByPhase[$phaseId][] = $match;
                }

                foreach ($phases as $phase): 
                    $phaseMatches = $matchesByPhase[$phase['id']] ?? [];
                    if (empty($phaseMatches)) continue;
                ?>
                <div class="phase-section" data-phase="<?php echo $phase['id']; ?>">
                    <div class="phase-title">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h4 class="mb-0"><?php echo htmlspecialchars($phase['phase_name']); ?></h4>
                                <small>Phase <?php echo $phase['phase_number']; ?> - <?php echo count($phaseMatches); ?> matches</small>
                            </div>
                            <span class="badge bg-light text-dark"><?php echo ucfirst($phase['status']); ?></span>
                        </div>
                    </div>
                    
                    <div class="row">
                        <?php foreach ($phaseMatches as $match): ?>
                        <div class="col-lg-6 col-xl-4" data-match-status="<?php echo $match['status']; ?>" 
                             data-match-date="<?php echo $match['scheduled_date']; ?>">
                            <div class="match-card">
                                <div class="match-header status-<?php echo $match['status']; ?>">
                                    <div>
                                        <h6 class="mb-0">Match <?php echo $match['match_number']; ?></h6>
                                        <small><?php echo $match['match_type'] ?? 'Regular'; ?></small>
                                    </div>
                                    <div class="dropdown">
                                        <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                            <i class="bi bi-three-dots"></i>
                                        </button>
                                        <ul class="dropdown-menu">
                                            <li><a class="dropdown-item" href="#" onclick="editMatch(<?php echo $match['id']; ?>)">
                                                <i class="bi bi-pencil"></i> Edit
                                            </a></li>
                                            <li><a class="dropdown-item text-danger" href="#" onclick="deleteMatch(<?php echo $match['id']; ?>)">
                                                <i class="bi bi-trash"></i> Delete
                                            </a></li>
                                        </ul>
                                    </div>
                                </div>
                                
                                <div class="match-body">
                                    <div class="match-info">
                                        <div class="info-item">
                                            <div class="info-label">Date & Time</div>
                                            <div class="info-value">
                                                <?php echo date('M j, Y', strtotime($match['scheduled_date'])); ?><br>
                                                <small><?php echo date('g:i A', strtotime($match['scheduled_time'])); ?></small>
                                            </div>
                                        </div>
                                        
                                        <?php if ($match['room_id']): ?>
                                        <div class="info-item">
                                            <div class="info-label">Room Details</div>
                                            <div class="info-value">
                                                ID: <?php echo htmlspecialchars($match['room_id']); ?><br>
                                                <small>Pass: <?php echo htmlspecialchars($match['room_password'] ?? 'N/A'); ?></small>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($match['map_name']): ?>
                                        <div class="info-item">
                                            <div class="info-label">Map</div>
                                            <div class="info-value"><?php echo htmlspecialchars($match['map_name']); ?></div>
                                        </div>
                                        <?php endif; ?>
                                        
                                        <div class="info-item">
                                            <div class="info-label">Status</div>
                                            <div class="info-value">
                                                <span class="badge bg-secondary"><?php echo ucfirst($match['status']); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <!-- Add Match Modal -->
    <div class="modal fade" id="addMatchModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Schedule New Match</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create_match">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Phase</label>
                                <select class="form-select" name="phase_id" required>
                                    <option value="">Select Phase</option>
                                    <?php foreach ($phases as $phase): ?>
                                    <option value="<?php echo $phase['id']; ?>"><?php echo htmlspecialchars($phase['phase_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Match Number</label>
                                <input type="number" class="form-control" name="match_number" required min="1" value="1">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Match Type</label>
                                <select class="form-select" name="match_type">
                                    <option value="Regular">Regular Match</option>
                                    <option value="Qualification">Qualification Match</option>
                                    <option value="Finals">Finals Match</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Scheduled Date</label>
                                <input type="date" class="form-control" name="scheduled_date" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Scheduled Time</label>
                                <input type="time" class="form-control" name="scheduled_time" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Room ID</label>
                                <input type="text" class="form-control" name="room_id" placeholder="e.g., 123456789">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Room Password</label>
                                <input type="text" class="form-control" name="room_password" placeholder="Optional">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Map Name</label>
                            <input type="text" class="form-control" name="map_name" placeholder="e.g., Erangel, Sanhok">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Schedule Match</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Match Modal -->
    <div class="modal fade" id="editMatchModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Match</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="editMatchForm">
                    <input type="hidden" name="action" value="update_match">
                    <input type="hidden" name="match_id" id="edit_match_id">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Scheduled Date</label>
                                <input type="date" class="form-control" name="scheduled_date" id="edit_scheduled_date" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Scheduled Time</label>
                                <input type="time" class="form-control" name="scheduled_time" id="edit_scheduled_time" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Room ID</label>
                                <input type="text" class="form-control" name="room_id" id="edit_room_id">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Room Password</label>
                                <input type="text" class="form-control" name="room_password" id="edit_room_password">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Map Name</label>
                            <input type="text" class="form-control" name="map_name" id="edit_map_name">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Match</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
    // Filter matches functionality
    function filterMatches() {
        const phaseFilter = document.getElementById('phaseFilter').value;
        const statusFilter = document.getElementById('statusFilter').value;
        const dateFilter = document.getElementById('dateFilter').value;
        
        // Show/hide phase sections
        document.querySelectorAll('.phase-section').forEach(section => {
            const phaseId = section.getAttribute('data-phase');
            const shouldShowPhase = !phaseFilter || phaseId === phaseFilter;
            section.style.display = shouldShowPhase ? 'block' : 'none';
        });
        
        // Show/hide individual matches
        document.querySelectorAll('[data-match-status]').forEach(match => {
            const matchStatus = match.getAttribute('data-match-status');
            const matchDate = match.getAttribute('data-match-date');
            
            const statusMatch = !statusFilter || matchStatus === statusFilter;
            const dateMatch = !dateFilter || matchDate === dateFilter;
            
            match.style.display = (statusMatch && dateMatch) ? 'block' : 'none';
        });
    }
    
    // Edit match functionality
    function editMatch(matchId) {
        // Find the match data from the rendered matches
        const matches = <?php echo json_encode($matches); ?>;
        const match = matches.find(m => m.id == matchId);
        
        if (match) {
            document.getElementById('edit_match_id').value = matchId;
            document.getElementById('edit_scheduled_date').value = match.scheduled_date;
            document.getElementById('edit_scheduled_time').value = match.scheduled_time;
            document.getElementById('edit_room_id').value = match.room_id || '';
            document.getElementById('edit_room_password').value = match.room_password || '';
            document.getElementById('edit_map_name').value = match.map_name || '';
            
            const editModal = new bootstrap.Modal(document.getElementById('editMatchModal'));
            editModal.show();
        }
    }
    
    // Delete match functionality
    function deleteMatch(matchId) {
        if (confirm('Are you sure you want to delete this match?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="delete_match">
                <input type="hidden" name="match_id" value="${matchId}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    </script>
</body>
</html>
