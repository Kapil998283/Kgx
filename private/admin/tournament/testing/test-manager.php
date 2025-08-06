<?php
// Define admin secure access
define('ADMIN_SECURE_ACCESS', true);

// Load admin secure configuration
require_once dirname(__DIR__, 2) . '/admin_secure_config.php';

// Use the new admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

// Initialize Supabase connection with admin privileges
$supabase = new SupabaseClient(true);

// Get tournaments for dropdown
$tournaments = $supabase->select('tournaments', 'id, name, mode, format, status', [], 'created_at.desc');

// Get count of test users
$test_users_count = $supabase->select('users', 'count', ['email' => ['like', '%@testuser.com']]);
$total_test_users = !empty($test_users_count) ? $test_users_count[0]['count'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tournament Testing Manager - KGX Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <style>
        .test-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            background: #f8f9fa;
        }
        .test-card h4 {
            color: #495057;
            margin-bottom: 15px;
        }
        .result-area {
            background: #fff;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            padding: 15px;
            margin-top: 15px;
            min-height: 100px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            white-space: pre-wrap;
        }
        .loading {
            color: #007bff;
        }
        .success {
            color: #28a745;
        }
        .error {
            color: #dc3545;
        }
        .stats-row {
            background: #e9ecef;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <h1 class="mb-4">
                    <i class="bi bi-tools"></i> Tournament Testing Manager
                    <a href="../index.php" class="btn btn-secondary float-end">
                        <i class="bi bi-arrow-left"></i> Back to Tournaments
                    </a>
                </h1>
                
                <!-- Stats Row -->
                <div class="stats-row">
                    <div class="row text-center">
                        <div class="col-md-3">
                            <h5><?php echo $total_test_users; ?></h5>
                            <small class="text-muted">Test Users Created</small>
                        </div>
                        <div class="col-md-3">
                            <h5><?php echo count($tournaments); ?></h5>
                            <small class="text-muted">Total Tournaments</small>
                        </div>
                        <div class="col-md-3">
                            <h5 id="registrations-count">-</h5>
                            <small class="text-muted">Test Registrations</small>
                        </div>
                        <div class="col-md-3">
                            <h5 id="approved-count">-</h5>
                            <small class="text-muted">Approved Registrations</small>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Create Fake Users -->
                    <div class="col-md-6">
                        <div class="test-card">
                            <h4><i class="bi bi-person-plus"></i> Generate Fake Users</h4>
                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-label">Number of Users</label>
                                    <input type="number" id="user-count" class="form-control" value="30" min="1" max="50">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Game</label>
                                    <select id="user-game" class="form-select">
                                        <option value="BGMI">BGMI</option>
                                        <option value="PUBG">PUBG</option>
                                        <option value="FREE FIRE">FREE FIRE</option>
                                        <option value="COD">COD</option>
                                    </select>
                                </div>
                            </div>
                            <button class="btn btn-primary mt-3" onclick="generateFakeUsers()">
                                <i class="bi bi-play-fill"></i> Generate Users
                            </button>
                            <button class="btn btn-danger mt-3" onclick="cleanupTestUsers()">
                                <i class="bi bi-trash"></i> Cleanup Test Users
                            </button>
                            <div id="user-results" class="result-area"></div>
                        </div>
                    </div>
                    
                    <!-- Register Users for Tournament -->
                    <div class="col-md-6">
                        <div class="test-card">
                            <h4><i class="bi bi-trophy"></i> Register Users for Tournament</h4>
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Tournament</label>
                                    <select id="tournament-select" class="form-select">
                                        <option value="">Select Tournament</option>
                                        <?php foreach ($tournaments as $tournament): ?>
                                        <option value="<?php echo $tournament['id']; ?>">
                                            <?php echo htmlspecialchars($tournament['name']); ?> 
                                            (<?php echo $tournament['mode']; ?> - <?php echo $tournament['format']; ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Number of Registrations</label>
                                    <input type="number" id="reg-count" class="form-control" value="20" min="1" max="50">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Initial Status</label>
                                    <select id="reg-status" class="form-select">
                                        <option value="pending">Pending (Default)</option>
                                        <option value="approved">Approved</option>
                                        <option value="rejected">Rejected</option>
                                    </select>
                                </div>
                            </div>
                            <button class="btn btn-success mt-3" onclick="registerUsersForTournament()">
                                <i class="bi bi-plus-circle"></i> Register Users
                            </button>
                            <div id="registration-results" class="result-area"></div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Bulk Registration Management -->
                    <div class="col-md-6">
                        <div class="test-card">
                            <h4><i class="bi bi-list-check"></i> Bulk Registration Management</h4>
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Tournament</label>
                                    <select id="bulk-tournament" class="form-select">
                                        <option value="">Select Tournament</option>
                                        <?php foreach ($tournaments as $tournament): ?>
                                        <option value="<?php echo $tournament['id']; ?>">
                                            <?php echo htmlspecialchars($tournament['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label">Action</label>
                                    <select id="bulk-action" class="form-select">
                                        <option value="approve">Approve All Pending</option>
                                        <option value="reject">Reject All Pending</option>
                                        <option value="reset">Reset All to Pending</option>
                                    </select>
                                </div>
                            </div>
                            <button class="btn btn-warning mt-3" onclick="bulkUpdateRegistrations()">
                                <i class="bi bi-gear"></i> Execute Bulk Action
                            </button>
                            <div id="bulk-results" class="result-area"></div>
                        </div>
                    </div>
                    
                    <!-- Tournament Statistics -->
                    <div class="col-md-6">
                        <div class="test-card">
                            <h4><i class="bi bi-graph-up"></i> Tournament Statistics</h4>
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label class="form-label">Tournament</label>
                                    <select id="stats-tournament" class="form-select">
                                        <option value="">Select Tournament</option>
                                        <?php foreach ($tournaments as $tournament): ?>
                                        <option value="<?php echo $tournament['id']; ?>">
                                            <?php echo htmlspecialchars($tournament['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <button class="btn btn-info mt-3" onclick="getTournamentStats()">
                                <i class="bi bi-bar-chart"></i> Get Statistics
                            </button>
                            <div id="stats-results" class="result-area"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function generateFakeUsers() {
            const count = document.getElementById('user-count').value;
            const game = document.getElementById('user-game').value;
            const resultDiv = document.getElementById('user-results');
            
            resultDiv.innerHTML = '<span class="loading">Generating fake users...</span>';
            
            fetch(`fake-user-generator.php?count=${count}&game=${game}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        resultDiv.innerHTML = `<span class="success">✓ Generated ${data.created_count} users successfully</span>\n`;
                        if (data.failed_count > 0) {
                            resultDiv.innerHTML += `<span class="error">✗ Failed to create ${data.failed_count} users</span>\n`;
                            if (data.failed_users && data.failed_users.length > 0) {
                                resultDiv.innerHTML += '\nFailed Users:\n';
                                data.failed_users.forEach(f => {
                                    resultDiv.innerHTML += `• ${f.username}: ${f.error}\n`;
                                });
                            }
                        }
                        if (data.created_users && data.created_users.length > 0) {
                            resultDiv.innerHTML += `\nCreated Users:\n${data.created_users.map(u => `• ${u.username} (${u.email}) - UID: ${u.game_uid}`).join('\n')}`;
                        }
                        updateStats();
                    } else {
                        resultDiv.innerHTML = `<span class="error">✗ Error: ${data.error}</span>`;
                    }
                })
                .catch(error => {
                    resultDiv.innerHTML = `<span class="error">✗ Network error: ${error.message}</span>`;
                });
        }
        
        
        function registerUsersForTournament() {
            const tournamentId = document.getElementById('tournament-select').value;
            const count = document.getElementById('reg-count').value;
            const status = document.getElementById('reg-status').value;
            const resultDiv = document.getElementById('registration-results');
            
            if (!tournamentId) {
                resultDiv.innerHTML = '<span class="error">✗ Please select a tournament</span>';
                return;
            }
            
            resultDiv.innerHTML = '<span class="loading">Registering users for tournament...</span>';
            
            fetch(`tournament-registrar.php?tournament_id=${tournamentId}&count=${count}&status=${status}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        resultDiv.innerHTML = `<span class="success">✓ Registered ${data.registered_count} users for "${data.tournament_name}"</span>\n`;
                        resultDiv.innerHTML += `Tournament: ${data.tournament_mode} format\n`;
                        resultDiv.innerHTML += `Status set: ${data.status_set}\n`;
                        
                        if (data.failed_count > 0) {
                            resultDiv.innerHTML += `<span class="error">✗ Failed: ${data.failed_count} registrations</span>\n`;
                            resultDiv.innerHTML += `\nFailed:\n${data.failed_registrations.map(f => `• ${f.username}: ${f.error}`).join('\n')}`;
                        }
                        updateStats();
                    } else {
                        resultDiv.innerHTML = `<span class="error">✗ Error: ${data.error}</span>`;
                    }
                })
                .catch(error => {
                    resultDiv.innerHTML = `<span class="error">✗ Network error: ${error.message}</span>`;
                });
        }
        
        function bulkUpdateRegistrations() {
            const tournamentId = document.getElementById('bulk-tournament').value;
            const action = document.getElementById('bulk-action').value;
            const resultDiv = document.getElementById('bulk-results');
            
            if (!tournamentId) {
                resultDiv.innerHTML = '<span class="error">✗ Please select a tournament</span>';
                return;
            }
            
            resultDiv.innerHTML = '<span class="loading">Executing bulk action...</span>';
            
            fetch(`bulk-registration-manager.php?tournament_id=${tournamentId}&action=${action}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        resultDiv.innerHTML = `<span class="success">✓ ${data.message}</span>\n`;
                        resultDiv.innerHTML += `Updated: ${data.updated_count} registrations\n`;
                        updateStats();
                    } else {
                        resultDiv.innerHTML = `<span class="error">✗ Error: ${data.error}</span>`;
                    }
                })
                .catch(error => {
                    resultDiv.innerHTML = `<span class="error">✗ Network error: ${error.message}</span>`;
                });
        }
        
        function getTournamentStats() {
            const tournamentId = document.getElementById('stats-tournament').value;
            const resultDiv = document.getElementById('stats-results');
            
            if (!tournamentId) {
                resultDiv.innerHTML = '<span class="error">✗ Please select a tournament</span>';
                return;
            }
            
            resultDiv.innerHTML = '<span class="loading">Loading tournament statistics...</span>';
            
            fetch(`../common/get_registrations.php?tournament_id=${tournamentId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const registrations = data.registrations;
                        const pending = registrations.filter(r => r.status === 'pending').length;
                        const approved = registrations.filter(r => r.status === 'approved').length;
                        const rejected = registrations.filter(r => r.status === 'rejected').length;
                        
                        resultDiv.innerHTML = `Tournament: ${data.tournament.name}\n`;
                        resultDiv.innerHTML += `Mode: ${data.tournament.mode}\n`;
                        resultDiv.innerHTML += `Game: ${data.tournament.game_name}\n\n`;
                        resultDiv.innerHTML += `<span class="success">Total Registrations: ${registrations.length}</span>\n`;
                        resultDiv.innerHTML += `• Pending: ${pending}\n`;
                        resultDiv.innerHTML += `• Approved: ${approved}\n`;
                        resultDiv.innerHTML += `• Rejected: ${rejected}\n\n`;
                        
                        if (registrations.length > 0) {
                            resultDiv.innerHTML += `Recent Registrations:\n`;
                            registrations.slice(0, 10).forEach(reg => {
                                const name = reg.team_name || reg.username;
                                const statusIcon = reg.status === 'approved' ? '✓' : reg.status === 'rejected' ? '✗' : '⏳';
                                resultDiv.innerHTML += `${statusIcon} ${name} (${reg.status})\n`;
                            });
                        }
                    } else {
                        resultDiv.innerHTML = `<span class="error">✗ Error: ${data.message}</span>`;
                    }
                })
                .catch(error => {
                    resultDiv.innerHTML = `<span class="error">✗ Network error: ${error.message}</span>`;
                });
        }
        
        function cleanupTestUsers() {
            if (!confirm('This will permanently delete ALL test users and their data. Are you sure?')) {
                return;
            }
            
            const resultDiv = document.getElementById('user-results');
            resultDiv.innerHTML = '<span class="loading">Cleaning up test users...</span>';
            
            fetch('cleanup-test-users.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        resultDiv.innerHTML = `<span class="success">✓ Cleanup completed</span>\n`;
                        resultDiv.innerHTML += `Deleted ${data.deleted_users} test users\n`;
                        resultDiv.innerHTML += `Deleted ${data.deleted_registrations} registrations\n`;
                        resultDiv.innerHTML += `Deleted ${data.deleted_tickets} ticket records\n`;
                        resultDiv.innerHTML += `Deleted ${data.deleted_coins} coin records\n`;
                        updateStats();
                    } else {
                        resultDiv.innerHTML = `<span class="error">✗ Error: ${data.error}</span>`;
                    }
                })
                .catch(error => {
                    resultDiv.innerHTML = `<span class="error">✗ Network error: ${error.message}</span>`;
                });
        }
        
        function updateStats() {
            // Update the stats in the header
            fetch('get-test-stats.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('registrations-count').textContent = data.total_registrations;
                        document.getElementById('approved-count').textContent = data.approved_registrations;
                    }
                })
                .catch(error => {
                    console.error('Failed to update stats:', error);
                });
        }
        
        // Load initial stats
        document.addEventListener('DOMContentLoaded', updateStats);
    </script>
</body>
</html>
