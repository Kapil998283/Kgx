<?php

// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

require_once 'common.php';

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

// Include admin header after authentication
include ADMIN_INCLUDES_PATH . 'admin-header.php';

$supabase = getSupabaseClient(true);

// Get match ID from URL
$match_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch match details using standard Supabase select method
$matches = $supabase->select('matches', '*', ['id' => $match_id]);
$match = !empty($matches) ? $matches[0] : null;

// Enrich match with game, team, and tournament details if match found
if ($match) {
    // Get game details
    if ($match['game_id']) {
        $game = $supabase->select('games', '*', ['id' => $match['game_id']]);
        if (!empty($game)) {
            $match['game_name'] = $game[0]['name'];
            $match['game_image'] = $game[0]['image_url'];
        }
    }
    
    // Get team details
    if ($match['team1_id']) {
        $team1 = $supabase->select('teams', '*', ['id' => $match['team1_id']]);
        if (!empty($team1)) {
            $match['team1_name'] = $team1[0]['name'];
        }
    }
    if ($match['team2_id']) {
        $team2 = $supabase->select('teams', '*', ['id' => $match['team2_id']]);
        if (!empty($team2)) {
            $match['team2_name'] = $team2[0]['name'];
        }
    }
    
    // Get tournament details
    if ($match['tournament_id']) {
        $tournament = $supabase->select('tournaments', '*', ['id' => $match['tournament_id']]);
        if (!empty($tournament)) {
            $match['tournament_name'] = $tournament[0]['name'];
        }
    }
    
    // Format date and time properly
    if ($match['match_date']) {
        $datetime = new DateTime($match['match_date']);
        $match['match_date'] = $datetime->format('Y-m-d');
        $match['match_time'] = $datetime->format('H:i:s');
    }
}

if (!$match) {
    // Determine where to redirect back to
    $redirect_url = 'bgmi.php'; // default fallback
    
    // Check if we have a referrer that's one of our game pages
    if (isset($_SERVER['HTTP_REFERER'])) {
        $referrer = $_SERVER['HTTP_REFERER'];
        if (strpos($referrer, 'bgmi.php') !== false) {
            $redirect_url = 'bgmi.php';
        } elseif (strpos($referrer, 'pubg.php') !== false) {
            $redirect_url = 'pubg.php';
        } elseif (strpos($referrer, 'freefire.php') !== false) {
            $redirect_url = 'freefire.php';
        } elseif (strpos($referrer, 'cod.php') !== false) {
            $redirect_url = 'cod.php';
        }
    }
    
    // Set error message for user feedback
    $_SESSION['error_message'] = "Match not found or you don't have permission to view this match.";
    header("Location: " . $redirect_url);
    exit;
}

// Fetch participants using standard select method
$match_participants = $supabase->select('match_participants', '*', ['match_id' => $match_id]);
$participants = [];

foreach ($match_participants as $mp) {
    if ($mp['user_id']) {
        // Get user details
        $user = $supabase->select('users', '*', ['id' => $mp['user_id']]);
        if (!empty($user)) {
            $participant = array_merge($mp, $user[0]);
            
            // Get user game info - Fix the game name mapping issue
            $participant['game_uid'] = '';
            $participant['game_username'] = '';
            $participant['game_level'] = 1;
            try {
                // Game name mapping for user_games table
                $game_name_mapping = [
                    'Free Fire' => 'FREE FIRE',
                    'Call of Duty Mobile' => 'COD',
                    'Call of Duty' => 'COD'
                ];
                
                // Use mapped name or original name for profile lookup
                $profile_game_name = isset($game_name_mapping[$match['game_name']]) ? 
                                     $game_name_mapping[$match['game_name']] : 
                                     $match['game_name'];
                
                $user_games = $supabase->select('user_games', '*', [
                    'user_id' => $mp['user_id'],
                    'game_name' => $profile_game_name
                ]);
                if (!empty($user_games)) {
                    $participant['game_uid'] = $user_games[0]['game_uid'] ?? '';
                    $participant['game_username'] = $user_games[0]['game_username'] ?? '';
                    $participant['game_level'] = $user_games[0]['game_level'] ?? 1;
                }
            } catch (Exception $e) {
                error_log("Error fetching user game info: " . $e->getMessage());
            }
            
            // Get kills info
            $kills = $supabase->select('user_kills', '*', [
                'match_id' => $match_id,
                'user_id' => $mp['user_id']
            ]);
            $participant['total_kills'] = !empty($kills) ? $kills[0]['kills'] : 0;
            
            // Set winner position
            $participant['winner_position'] = $mp['position'];
            
            // Check if winner
            $participant['is_winner'] = (
                ($match['winner_user_id'] == $mp['user_id']) ||
                ($match['winner_id'] == $mp['team_id'])
            ) ? 1 : 0;
            
            $participants[] = $participant;
        }
    }
}

// Sort participants by position, then kills, then username
usort($participants, function($a, $b) {
    $posA = $a['position'] ?? 999999;
    $posB = $b['position'] ?? 999999;
    if ($posA != $posB) {
        return $posA - $posB;
    }
    if ($a['total_kills'] != $b['total_kills']) {
        return $b['total_kills'] - $a['total_kills'];
    }
    return strcmp($a['username'], $b['username']);
});

// Get the number of winners based on prize distribution
$numWinners = 1;
if ($match['prize_distribution'] === 'top3') {
    $numWinners = 3;
} else if ($match['prize_distribution'] === 'top5') {
    $numWinners = 5;
}

// Calculate prize amounts for each position
$prizeAmounts = [];
if ($match['prize_distribution']) {
    $percentages = [];
    switch($match['prize_distribution']) {
        case 'top3':
            $percentages = [60, 30, 10];
            break;
        case 'top5':
            $percentages = [50, 25, 15, 7, 3];
            break;
        default:
            $percentages = [100];
    }

    foreach ($percentages as $index => $percentage) {
        if ($match['website_currency_type']) {
            $prizeAmounts[$index + 1] = floor($match['website_currency_amount'] * $percentage / 100);
        } else {
            $prizeAmounts[$index + 1] = round($match['prize_pool'] * $percentage / 100, 2);
        }
    }
}

?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="mb-0">Match Participants</h3>
                        <a href="javascript:history.back()" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Back
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Match Info -->
                    <div class="match-info-header mb-4">
                        <div class="row">
                            <div class="col-md-6">
                                <h4><?= htmlspecialchars($match['game_name']) ?> - <?= ucfirst($match['match_type']) ?></h4>
                                <p class="text-muted">
                                    <i class="bi bi-calendar"></i> <?= date('M j, Y', strtotime($match['match_date'])) ?>
                                    <i class="bi bi-clock ms-3"></i> <?= date('g:i A', strtotime($match['match_time'])) ?>
                                </p>
                                <p class="text-muted">
                                    <i class="bi bi-info-circle"></i> Status: <span class="badge bg-<?= $match['status'] === 'completed' ? 'success' : ($match['status'] === 'in_progress' ? 'primary' : 'warning') ?>">
                                        <?= ucfirst($match['status']) ?>
                                    </span>
                                </p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <div class="prize-info">
                                    <?php if ($match['website_currency_type'] && $match['website_currency_amount'] > 0): ?>
                                        <h5>Prize Pool: <?= number_format($match['website_currency_amount']) ?> <?= ucfirst($match['website_currency_type']) ?></h5>
                                    <?php else: ?>
                                        <h5>Prize Pool: <?= $match['prize_type'] === 'USD' ? '$' : '₹' ?><?= number_format($match['prize_pool']) ?></h5>
                                    <?php endif; ?>

                                    <?php if ($match['prize_distribution']): ?>
                                        <div class="prize-distribution">
                                            <p class="text-muted mb-1">Prize Distribution:</p>
                                            <?php
                                                foreach ($prizeAmounts as $position => $amount) {
                                                    $currency = $match['website_currency_type'] 
                                                        ? ucfirst($match['website_currency_type'])
                                                        : ($match['prize_type'] === 'USD' ? '$' : '₹');
                                                    
                                                    echo "<p class='mb-0'>{$position}st Place: " . number_format($amount) . " {$currency}</p>";
                                                }
                                            ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($match['coins_per_kill'] > 0): ?>
                                        <p class="text-success">
                                            <i class="bi bi-star"></i> <?= number_format($match['coins_per_kill']) ?> Coins per Kill
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Add search box -->
                    <div class="mb-4">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="input-group">
                                    <input type="text" id="searchInput" class="form-control" placeholder="Search by username, game UID, or in-game name...">
                                    <button class="btn btn-outline-secondary" type="button">
                                        <i class="bi bi-search"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Participants Table -->
                    <div class="table-responsive">
                        <table class="table table-hover" id="participantsTable">
                            <thead>
<tr>
        <th>#</th>
        <th>Player</th>
        <th>Game UID</th>
        <th>In-Game Name</th>
        <th>Kills</th>
        <th>Coins Earned</th>
        <th>Screenshot</th>
        <th>Position</th>
    </tr>
                            </thead>
                            <tbody>
<?php foreach ($participants as $index => $participant):
    $coinsEarned = $participant['total_kills'] * $match['coins_per_kill'];
?>
                                <tr class="participant-row" data-user-id="<?= $participant['user_id'] ?>">
                                    <td><?= $index + 1 ?></td>
                                    <td>
                                        <div class="d-flex flex-column">
                                            <strong><?= htmlspecialchars($participant['username'] ?? 'N/A') ?></strong>
                                            <small class="text-muted"><?= htmlspecialchars($participant['email'] ?? '') ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="game-uid">
                                            <?php if (!empty($participant['game_uid'])): ?>
                                                <code class="bg-light p-1 rounded"><?= htmlspecialchars($participant['game_uid']) ?></code>
                                            <?php else: ?>
                                                <span class="text-danger">Not Set</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="game-username">
                                            <?php if (!empty($participant['game_username'])): ?>
                                                <span class="fw-bold text-primary"><?= htmlspecialchars($participant['game_username']) ?></span>
                                                <br>
                                                <small class="text-muted">Level: <?= $participant['game_level'] ?? 1 ?></small>
                                            <?php else: ?>
                                                <span class="text-danger">Not Set</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="kills-display">
                                            <span class="badge bg-warning text-dark"><?= $participant['total_kills'] ?></span>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="coins-earned">
                                            <?php if ($coinsEarned > 0): ?>
                                                <span class="badge bg-success"><?= number_format($coinsEarned) ?> Coins</span>
                                            <?php else: ?>
                                                <span class="text-muted">0 Coins</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php
                                            // Get screenshots for this participant
                                            $screenshots = $supabase->select('match_screenshots', '*', ['match_id' => $match_id, 'user_id' => $participant['user_id']]);
                                        ?>
                                        <div class="screenshot-status text-center">
                                            <?php if (!empty($screenshots)): ?>
                                                <?php 
                                                    $verified_count = 0;
                                                    $rejected_count = 0;
                                                    $pending_count = 0;
                                                    
                                                    foreach ($screenshots as $screenshot) {
                                                        if ($screenshot['verified']) {
                                                            $verified_count++;
                                                        } elseif ($screenshot['verified_at']) {
                                                            $rejected_count++;
                                                        } else {
                                                            $pending_count++;
                                                        }
                                                    }
                                                ?>
                                                
                                                <div class="screenshot-badges">
                                                    <?php if ($verified_count > 0): ?>
                                                        <div class="badge bg-success rounded-pill px-3 py-2 mb-1">
                                                            <i class="bi bi-check-circle-fill me-1"></i><?= $verified_count ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($rejected_count > 0): ?>
                                                        <div class="badge bg-danger rounded-pill px-3 py-2 mb-1">
                                                            <i class="bi bi-x-circle-fill me-1"></i><?= $rejected_count ?>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($pending_count > 0): ?>
                                                        <div class="badge bg-warning text-dark rounded-pill px-3 py-2 mb-1">
                                                            <i class="bi bi-clock-fill me-1"></i><?= $pending_count ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?>
                                                <div class="no-screenshots-indicator">
                                                    <div class="badge bg-secondary rounded-circle" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;">
                                                        <i class="bi bi-camera-slash"></i>
                                                    </div>
                                                    <div class="mt-1">
                                                        <small class="text-muted">No proof</small>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="position-display text-center">
                                            <?php if (!empty($participant['position'])): ?>
                                                <?php
                                                    $position = $participant['position'];
                                                    $suffix = 'th';
                                                    if ($position == 1) $suffix = 'st';
                                                    else if ($position == 2) $suffix = 'nd';
                                                    else if ($position == 3) $suffix = 'rd';
                                                    
                                                    // Enhanced styling based on position
                                                    if ($position == 1) {
                                                        $badgeClass = 'bg-warning text-dark';
                                                        $iconClass = 'bi-trophy-fill';
                                                        $iconColor = 'gold';
                                                    } elseif ($position == 2) {
                                                        $badgeClass = 'bg-secondary';
                                                        $iconClass = 'bi-trophy-fill';
                                                        $iconColor = 'silver';
                                                    } elseif ($position == 3) {
                                                        $badgeClass = 'badge-bronze';
                                                        $iconClass = 'bi-trophy-fill';
                                                        $iconColor = '#CD7F32';
                                                    } else {
                                                        $badgeClass = 'bg-primary';
                                                        $iconClass = 'bi-award-fill';
                                                        $iconColor = '';
                                                    }
                                                ?>
                                                <div class="position-badge-container">
                                                    <div class="badge <?= $badgeClass ?> rounded-pill px-3 py-2 position-badge">
                                                        <?php if ($position <= 3): ?>
                                                            <i class="<?= $iconClass ?> me-1" <?= $iconColor ? 'style="color: '.$iconColor.'"' : '' ?>></i>
                                                        <?php else: ?>
                                                            <i class="<?= $iconClass ?> me-1"></i>
                                                        <?php endif; ?>
                                                        <span class="fw-bold"><?= $position ?><?= $suffix ?></span>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <div class="no-position-indicator">
                                                    <div class="badge bg-light text-muted rounded-circle" style="width: 35px; height: 35px; display: flex; align-items: center; justify-content: center;">
                                                        <i class="bi bi-dash"></i>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.match-info-header {
    background: #f8f9fa;
    padding: 1.5rem;
    border-radius: 8px;
    margin-bottom: 2rem;
}

.prize-info {
    background: #e8f5e9;
    padding: 1rem;
    border-radius: 8px;
    display: inline-block;
}

.table th {
    background: #f8f9fa;
    font-weight: 600;
}

.table td {
    vertical-align: middle;
}

.badge {
    padding: 0.5em 1em;
}

.bi-trophy-fill {
    margin-left: 5px;
    font-size: 1.1em;
}

.table-success {
    background-color: rgba(40, 167, 69, 0.1) !important;
}

.table-success:hover {
    background-color: rgba(40, 167, 69, 0.15) !important;
}

.btn-sm {
    margin: 0.25rem;
}

.modal-content {
    border-radius: 8px;
    border: none;
}

.modal-header {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}

.alert {
    border-left: 4px solid #0dcaf0;
}

.alert ul {
    padding-left: 1.25rem;
}

/* Add styles for form validation */
.was-validated .form-control:invalid {
    border-color: #dc3545;
    padding-right: calc(1.5em + 0.75rem);
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right calc(0.375em + 0.1875rem) center;
    background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
}

.was-validated .form-control:valid {
    border-color: #198754;
    padding-right: calc(1.5em + 0.75rem);
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3e%3cpath fill='%23198754' d='M2.3 6.73L.6 4.53c-.4-1.04.46-1.4 1.1-.8l1.1 1.4 3.4-3.8c.6-.63 1.6-.27 1.2.7l-4 4.6c-.43.5-.8.4-1.1.1z'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right calc(0.375em + 0.1875rem) center;
    background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
}

/* Enhanced Screenshot Column Styling */
.screenshot-status {
    min-height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.screenshot-badges .badge {
    font-size: 0.75rem;
    font-weight: 600;
    margin: 2px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.12);
    transition: all 0.2s ease;
}

.screenshot-badges .badge:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0,0,0,0.15);
}

.no-screenshots-indicator {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 10px;
}

.no-screenshots-indicator .badge {
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    transition: all 0.2s ease;
}

.no-screenshots-indicator:hover .badge {
    transform: scale(1.05);
}

/* Enhanced Position Column Styling */
.position-display {
    min-height: 60px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.position-badge {
    font-size: 0.9rem;
    font-weight: 700;
    min-width: 70px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    transition: all 0.3s ease;
    border: 2px solid rgba(255,255,255,0.2);
}

.position-badge:hover {
    transform: translateY(-2px) scale(1.05);
    box-shadow: 0 4px 12px rgba(0,0,0,0.25);
}

.badge-bronze {
    background: linear-gradient(135deg, #CD7F32, #B8860B) !important;
    color: white !important;
}

.position-badge .bi-trophy-fill {
    font-size: 1rem;
    filter: drop-shadow(0 1px 2px rgba(0,0,0,0.3));
}

.position-badge .bi-award-fill {
    font-size: 1rem;
    filter: drop-shadow(0 1px 2px rgba(0,0,0,0.3));
}

.no-position-indicator {
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 10px;
}

.no-position-indicator .badge {
    border: 2px dashed #dee2e6;
    transition: all 0.2s ease;
}

.no-position-indicator:hover .badge {
    border-color: #adb5bd;
    transform: scale(1.05);
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .screenshot-badges .badge {
        font-size: 0.7rem;
        padding: 4px 8px;
    }
    
    .position-badge {
        font-size: 0.8rem;
        min-width: 60px;
        padding: 6px 10px;
    }
}
</style>

<?php include ADMIN_INCLUDES_PATH . 'admin-footer.php'; ?>

<?php
// Add this helper function at the bottom of the file
function getOrdinalSuffix($number) {
    if (!in_array(($number % 100), array(11,12,13))) {
        switch ($number % 10) {
            case 1:  return 'st';
            case 2:  return 'nd';
            case 3:  return 'rd';
        }
    }
    return 'th';
}
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('searchInput');
    const table = document.getElementById('participantsTable');
    const rows = table.getElementsByTagName('tr');

    searchInput.addEventListener('keyup', function() {
        const searchTerm = searchInput.value.toLowerCase();

        // Start from index 1 to skip the header row
        for (let i = 1; i < rows.length; i++) {
            const row = rows[i];
            const username = row.cells[1].textContent.toLowerCase();
            const gameUid = row.cells[2].textContent.toLowerCase();
            const inGameName = row.cells[3].textContent.toLowerCase();

            if (username.includes(searchTerm) || 
                gameUid.includes(searchTerm) || 
                inGameName.includes(searchTerm)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        }
    });
});
</script> 