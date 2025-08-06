<?php
// CRITICAL: Suppress ALL error output to prevent corrupting HTML title
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
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
require_once dirname(__DIR__) . '/admin_secure_config.php';

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
require_once ADMIN_INCLUDES_PATH . 'tournament-status.php';

// Admin is automatically authenticated by admin-auth.php

// Initialize Supabase connection with admin privileges
$supabase = new SupabaseClient(true);

// Update status of all tournaments
adminUpdateTournamentStatus($supabase);

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create':
                try {
                    // Create tournament data array
                    $tournamentData = [
                        'name' => $_POST['name'],
                        'game_name' => $_POST['game_name'],
                        'banner_image' => $_POST['banner_image'],
                        'prize_pool' => (float)$_POST['prize_pool'],
                        'prize_currency' => $_POST['prize_currency'],
                        'entry_fee' => (int)$_POST['entry_fee'],
                        'max_teams' => (int)$_POST['max_teams'],
                        'current_teams' => 0,
                        'mode' => $_POST['mode'],
                        'format' => $_POST['format'],
                        'match_type' => $_POST['match_type'],
                        'registration_open_date' => date('Y-m-d', strtotime($_POST['registration_open_date'])),
                        'registration_close_date' => date('Y-m-d', strtotime($_POST['registration_close_date'])),
                        'playing_start_date' => date('Y-m-d', strtotime($_POST['playing_start_date'])),
                        'finish_date' => date('Y-m-d', strtotime($_POST['finish_date'])),
                        'description' => $_POST['description'],
                        'rules' => $_POST['rules'],
                        'status' => 'announced',
                        'phase' => 'pre_registration',
                        'created_by' => $_SESSION['admin_id'],
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ];
                    
                    // Add payment date if provided
                    if (!empty($_POST['payment_date'])) {
                        $tournamentData['payment_date'] = date('Y-m-d', strtotime($_POST['payment_date']));
                    }
                    
                    // Insert tournament
                    $result = $supabase->insert('tournaments', $tournamentData);
                    
                    $_SESSION['success'] = "Tournament created successfully!";
                    logAdminAction('create_tournament', 'Created tournament: ' . $_POST['name']);
                } catch (Exception $e) {
                    $_SESSION['error'] = "Error creating tournament: " . $e->getMessage();
                }
                header('Location: index.php');
                exit();
                break;

            case 'cancel':
                try {
                    $supabase->update('tournaments', [
                        'status' => 'cancelled',
                        'phase' => 'finished',
                        'updated_at' => date('Y-m-d H:i:s')
                    ], ['id' => $_POST['tournament_id']]);
                    
                    $_SESSION['success'] = "Tournament cancelled successfully!";
                    logAdminAction('cancel_tournament', 'Cancelled tournament ID: ' . $_POST['tournament_id']);
                } catch (Exception $e) {
                    $_SESSION['error'] = "Error cancelling tournament: " . $e->getMessage();
                }
                header('Location: index.php');
                exit();
                break;
                
            case 'update':
                try {
                    // Create update data array
                    $updateData = [
                        'name' => $_POST['name'],
                        'game_name' => $_POST['game_name'],
                        'banner_image' => $_POST['banner_image'],
                        'prize_pool' => (float)$_POST['prize_pool'],
                        'prize_currency' => $_POST['prize_currency'],
                        'entry_fee' => (int)$_POST['entry_fee'],
                        'max_teams' => (int)$_POST['max_teams'],
                        'mode' => $_POST['mode'],
                        'format' => $_POST['format'],
                        'match_type' => $_POST['match_type'],
                        'registration_open_date' => date('Y-m-d', strtotime($_POST['registration_open_date'])),
                        'registration_close_date' => date('Y-m-d', strtotime($_POST['registration_close_date'])),
                        'playing_start_date' => date('Y-m-d', strtotime($_POST['playing_start_date'])),
                        'finish_date' => date('Y-m-d', strtotime($_POST['finish_date'])),
                        'description' => $_POST['description'],
                        'rules' => $_POST['rules'],
                        'updated_at' => date('Y-m-d H:i:s')
                    ];
                    
                    // Add payment date if provided
                    if (!empty($_POST['payment_date'])) {
                        $updateData['payment_date'] = date('Y-m-d', strtotime($_POST['payment_date']));
                    } else {
                        $updateData['payment_date'] = null;
                    }
                    
                    $supabase->update('tournaments', $updateData, ['id' => $_POST['tournament_id']]);
                    
                    $_SESSION['success'] = "Tournament updated successfully!";
                    logAdminAction('update_tournament', 'Updated tournament: ' . $_POST['name']);
                } catch (Exception $e) {
                    $_SESSION['error'] = "Error updating tournament: " . $e->getMessage();
                }
                header('Location: index.php');
                exit();
                break;
                
            case 'delete':
                try {
                    $supabase->delete('tournaments', ['id' => $_POST['tournament_id']]);
                    $_SESSION['success'] = "Tournament deleted successfully!";
                    logAdminAction('delete_tournament', 'Deleted tournament ID: ' . $_POST['tournament_id']);
                } catch (Exception $e) {
                    $_SESSION['error'] = "Error deleting tournament: " . $e->getMessage();
                }
                header('Location: index.php');
                exit();
                break;
        }
    }
}

// Fetch all tournaments
try {
    $tournaments = $supabase->select('tournaments', '*', [], 'registration_open_date.desc');
    if (!$tournaments) {
        $tournaments = [];
    }
} catch (Exception $e) {
    error_log("Error fetching tournaments: " . $e->getMessage());
    $tournaments = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tournament Management - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <style>
        .tournament-image-preview {
            max-width: 200px;
            max-height: 200px;
            object-fit: cover;
        }
        .date-input-group {
            display: flex;
            gap: 10px;
        }
        .date-input-group input {
            flex: 1;
        }
        .tournament-table img {
            width: 80px;
            height: 50px;
            object-fit: cover;
            border-radius: 4px;
        }
        .tournament-table {
            font-size: 14px;
        }
        .tournament-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            white-space: nowrap;
            vertical-align: middle;
        }
        .tournament-table td {
            vertical-align: middle;
        }
        .tournament-name {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .tournament-name span {
            font-weight: 500;
        }
        .status-badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            display: inline-block;
            text-align: center;
            min-width: 100px;
        }
        .status-upcoming {
            background-color: #e3f2fd;
            color: #1976d2;
        }
        .status-registration {
            background-color: #fff3e0;
            color: #e65100;
        }
        .status-ongoing {
            background-color: #e8f5e9;
            color: #2e7d32;
        }
        .status-completed {
            background-color: #f5f5f5;
            color: #616161;
        }
        .status-cancelled {
            background-color: #ffebee;
            color: #c62828;
        }
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
            justify-content: flex-end;
            min-width: 200px;
        }
        .action-buttons .btn {
            padding: 4px 8px;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
        }
        .date-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
            font-size: 12px;
            margin-top: 4px;
        }
        .date-info small {
            color: #666;
        }
        .status-column {
            min-width: 140px;
            text-align: center;
        }
        .tournament-table td.actions-column {
            text-align: right;
            padding-right: 20px;
        }
        .teams-column {
            text-align: center;
            white-space: nowrap;
        }
        .prize-column {
            white-space: nowrap;
            text-align: right;
        }
        .entry-column {
            white-space: nowrap;
            text-align: center;
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/../includes/admin-header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php require_once __DIR__ . '/../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Tournament Management</h1>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTournamentModal">
                        <i class="bi bi-plus-lg"></i> Add Tournament
                    </button>
                </div>

                <!-- Tournaments Table -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover tournament-table">
                        <thead>
                            <tr>
                                <th style="min-width: 300px;">Tournament</th>
                                <th>Game</th>
                                <th class="prize-column">Prize Pool</th>
                                <th class="entry-column">Entry Fee</th>
                                <th class="teams-column">Teams/Players</th>
                                <th class="status-column">Status</th>
                                <th class="actions-column">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($tournaments as $tournament): ?>
                            <tr>
                                <td>
                                    <div class="tournament-name">
                                        <img src="<?php echo htmlspecialchars($tournament['banner_image']); ?>" alt="Tournament banner" onerror="this.src='assets/images/default-tournament.jpg'">
                                        <span><?php echo htmlspecialchars($tournament['name']); ?></span>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($tournament['game_name']); ?></td>
                                <td class="prize-column">
                                    <?php 
                                        echo $tournament['prize_currency'] === 'USD' ? '$' : '₹';
                                        echo number_format($tournament['prize_pool'], 2); 
                                    ?>
                                </td>
                                <td class="entry-column"><?php echo $tournament['entry_fee']; ?> Tickets</td>
                                <td class="teams-column"><?php echo $tournament['current_teams'] . '/' . $tournament['max_teams']; ?> <?php echo ($tournament['mode'] === 'Solo') ? 'Players' : 'Teams'; ?></td>
                                <td class="status-column">
                                    <?php
                                        $status_info = adminGetTournamentDisplayStatus($tournament);
                                    ?>
                                    <span class="status-badge <?php echo $status_info['class']; ?>">
                                        <?php echo $status_info['status']; ?>
                                    </span>
                                    <?php if ($status_info['date_label'] && $status_info['date_value']): ?>
                                    <div class="date-info">
                                        <small><?php echo $status_info['date_label']; ?>: <span><?php echo $status_info['date_value']; ?></span></small>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="actions-column">
                                    <div class="action-buttons">
                                        <?php if (adminCanEditTournament($tournament)): ?>
                                        <button class="btn btn-sm btn-primary" onclick="editTournament(<?php echo $tournament['id']; ?>)" title="Edit">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <?php endif; ?>
                                        <button class="btn btn-sm btn-danger" onclick="deleteTournament(<?php echo $tournament['id']; ?>)" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                        <?php 
                                        // Smart routing based on tournament format
                                        switch($tournament['format']) {
                                            case 'Group Stage':
                                                $managementUrl = "group-stage/tournament-groups.php?id=" . $tournament['id'];
                                                $scheduleUrl = "group-stage/tournament-schedule.php?id=" . $tournament['id'];
                                                $buttonTitle = "Groups";
                                                $buttonIcon = "bi-grid-3x3";
                                                break;
                                            case 'Weekly Finals':
                                                $managementUrl = "weekly-finals/tournament-phases.php?id=" . $tournament['id'];
                                                $scheduleUrl = "weekly-finals/tournament-schedule.php?id=" . $tournament['id'];
                                                $buttonTitle = "Phases";
                                                $buttonIcon = "bi-layers";
                                                break;
                                            case 'Elimination':
                                            case 'Custom Lobby':
                                            default:
                                                $managementUrl = "elimination/tournament-rounds.php?id=" . $tournament['id'];
                                                $scheduleUrl = "elimination/tournament-schedule.php?id=" . $tournament['id'];
                                                $buttonTitle = "Rounds";
                                                $buttonIcon = "bi-list-ol";
                                                break;
                                        }
                                        ?>
                                        <button class="btn btn-sm btn-success" onclick="window.location.href='<?php echo $managementUrl; ?>'" title="<?php echo $buttonTitle; ?>">
                                            <i class="<?php echo $buttonIcon; ?>"></i>
                                        </button>
                                        <button class="btn btn-sm btn-primary" onclick="window.location.href='<?php echo $scheduleUrl; ?>'" title="Schedule">
                                            <i class="bi bi-calendar"></i>
                                        </button>
                                        <button class="btn btn-sm btn-info" onclick="viewRegistrations(<?php echo $tournament['id']; ?>)" title="Teams">
                                            <i class="bi bi-people"></i>
                                        </button>
                                        <?php if (adminCanCancelTournament($tournament)): ?>
                                        <button class="btn btn-sm btn-warning" onclick="cancelTournament(<?php echo $tournament['id']; ?>)" title="Cancel Tournament">
                                            <i class="bi bi-x-circle"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </main>
        </div>
    </div>

    <!-- Add Tournament Modal -->
    <div class="modal fade" id="addTournamentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Tournament</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="create">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tournament Name</label>
                                <input type="text" class="form-control" name="name" required maxlength="255">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Game</label>
                                <select class="form-select" name="game_name" required>
                                    <option value="">Select Game</option>
                                    <option value="BGMI">BGMI</option>
                                    <option value="PUBG">PUBG</option>
                                    <option value="FREE FIRE">FREE FIRE</option>
                                    <option value="COD">COD</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Banner Image URL</label>
                            <input type="url" class="form-control" name="banner_image" required 
                                   placeholder="Enter image URL (e.g., https://example.com/image.jpg)"
                                   onchange="previewImage(this)" maxlength="2083">
                            <div class="mt-2">
                                <img src="" alt="Preview" class="tournament-image-preview d-none">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Prize Pool</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="prize_pool" step="0.01" min="0" required>
                                    <select class="form-select" name="prize_currency" style="max-width: 100px;">
                                        <option value="USD">USD ($)</option>
                                        <option value="INR">INR (₹)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Entry Fee (Tickets)</label>
                                <input type="number" class="form-control" name="entry_fee" required min="0" value="0">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="add_max_teams">Max Teams/Players</label>
                                <input type="number" class="form-control" name="max_teams" id="add_max_teams" required min="1" value="100">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Mode</label>
                                <select class="form-select" name="mode" required>
                                    <option value="Solo">Solo</option>
                                    <option value="Duo">Duo</option>
                                    <option value="Squad">Squad</option>
                                    <option value="Team">Team</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Format</label>
                                <select class="form-select" name="format" id="tournament_format" required onchange="showFormatDescription(this.value)">
                                    <option value="Elimination">Elimination</option>
                                    <option value="Group Stage">Group Stage</option>
                                    <option value="Weekly Finals">Weekly Finals</option>
                                    <option value="Custom Lobby">Custom Lobby</option>
                                </select>
                                <small class="text-muted" id="format_description">Traditional single-elimination bracket format</small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Match Type</label>
                                <select class="form-select" name="match_type" required>
                                    <option value="Single">Single</option>
                                    <option value="Best of 3">Best of 3</option>
                                    <option value="Best of 5">Best of 5</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Registration Period</label>
                                <div class="input-group">
                                    <input type="date" class="form-control" name="registration_open_date" required>
                                    <span class="input-group-text">to</span>
                                    <input type="date" class="form-control" name="registration_close_date" required>
                                </div>
                                <small class="text-muted">When players can register for the tournament</small>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tournament Period</label>
                                <div class="input-group">
                                    <input type="date" class="form-control" name="playing_start_date" required>
                                    <span class="input-group-text">to</span>
                                    <input type="date" class="form-control" name="finish_date" required>
                                </div>
                                <small class="text-muted">When the tournament matches will be played</small>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Prize Payment Date</label>
                            <input type="date" class="form-control" name="payment_date">
                            <small class="text-muted">When the prize money will be distributed to winners</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3" required></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Rules</label>
                            <textarea class="form-control" name="rules" rows="5" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Tournament</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Tournament Modal -->
    <div class="modal fade" id="editTournamentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Tournament</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="tournament_id" id="edit_tournament_id">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tournament Name</label>
                                <input type="text" class="form-control" name="name" required maxlength="255">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Game</label>
                                <select class="form-select" name="game_name" required>
                                    <option value="BGMI">BGMI</option>
                                    <option value="PUBG">PUBG</option>
                                    <option value="FREE FIRE">FREE FIRE</option>
                                    <option value="COD">COD</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Banner Image URL</label>
                            <input type="url" class="form-control" name="banner_image" required 
                                   placeholder="Enter image URL (e.g., https://example.com/image.jpg)"
                                   onchange="previewImage(this)" maxlength="2083">
                            <div class="mt-2">
                                <img src="" alt="Preview" class="tournament-image-preview d-none">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Prize Pool</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" name="prize_pool" step="0.01" min="0" required>
                                    <select class="form-select" name="prize_currency" style="max-width: 100px;">
                                        <option value="USD">USD ($)</option>
                                        <option value="INR">INR (₹)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Entry Fee (Tickets)</label>
                                <input type="number" class="form-control" name="entry_fee" required min="0">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label" for="edit_max_teams">Max Teams/Players</label>
                                <input type="number" class="form-control" name="max_teams" id="edit_max_teams" required min="1">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Mode</label>
                                <select class="form-select" name="mode" required>
                                    <option value="Solo">Solo</option>
                                    <option value="Duo">Duo</option>
                                    <option value="Squad">Squad</option>
                                    <option value="Team">Team</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Format</label>
                                <select class="form-select" name="format" id="edit_tournament_format" required onchange="showFormatDescription(this.value, 'edit')">
                                    <option value="Elimination">Elimination</option>
                                    <option value="Group Stage">Group Stage</option>
                                    <option value="Weekly Finals">Weekly Finals</option>
                                    <option value="Custom Lobby">Custom Lobby</option>
                                </select>
                                <small class="text-muted" id="edit_format_description">Traditional single-elimination bracket format</small>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Match Type</label>
                                <select class="form-select" name="match_type" required>
                                    <option value="Single">Single</option>
                                    <option value="Best of 3">Best of 3</option>
                                    <option value="Best of 5">Best of 5</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Registration Period</label>
                                <div class="input-group">
                                    <input type="date" class="form-control" name="registration_open_date" required>
                                    <span class="input-group-text">to</span>
                                    <input type="date" class="form-control" name="registration_close_date" required>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tournament Period</label>
                                <div class="input-group">
                                    <input type="date" class="form-control" name="playing_start_date" required>
                                    <span class="input-group-text">to</span>
                                    <input type="date" class="form-control" name="finish_date" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Prize Payment Date</label>
                            <input type="date" class="form-control" name="payment_date">
                            <small class="text-muted">When the prize money will be distributed to winners</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3" required></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Rules</label>
                            <textarea class="form-control" name="rules" rows="5" required></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Tournament</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteTournamentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Delete</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    Are you sure you want to delete this tournament? This action cannot be undone.
                </div>
                <div class="modal-footer">
                    <form method="POST">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="tournament_id" id="delete_tournament_id">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Delete</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- View Registrations Modal -->
    <div class="modal fade" id="viewRegistrationsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="registrationsModalTitle">Registered Teams</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="registrationsContent"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Cancel Tournament Modal -->
    <div class="modal fade" id="cancelTournamentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Cancel Tournament</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-danger">Warning: Cancelling a tournament will:</p>
                    <ul>
                        <li>Stop new registrations</li>
                        <li>Mark the tournament as cancelled</li>
                        <li>This action cannot be undone</li>
                    </ul>
                    <p>Are you sure you want to cancel this tournament?</p>
                </div>
                <div class="modal-footer">
                    <form method="POST">
                        <input type="hidden" name="action" value="cancel">
                        <input type="hidden" name="tournament_id" id="cancel_tournament_id">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">No, Keep Active</button>
                        <button type="submit" class="btn btn-warning">Yes, Cancel Tournament</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/tournament-operations.js"></script>
</body>
</html>
