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


// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add_match':
                $game_id = $_POST['game_id'];
                $match_type = $_POST['match_type'];
                $match_date = $_POST['match_date'] . ' ' . $_POST['match_time'];
                $entry_type = $_POST['entry_type'];
                $entry_fee = $_POST['entry_fee'] ?: 0;
                $prize_pool = $_POST['prize_pool'] ?: 0;
                $prize_type = $_POST['prize_type'] ?? 'INR';
                $max_participants = $_POST['max_participants'];
                $tournament_id = $_POST['tournament_id'] ?: null;
                $team1_id = $_POST['team1_id'] ?: null;
                $team2_id = $_POST['team2_id'] ?: null;
                $map_name = isset($_POST['map_name']) ? $_POST['map_name'] : 'Erangel';

                $website_currency_type = isset($_POST['website_currency_type']) ? $_POST['website_currency_type'] : null;
                $website_currency_amount = isset($_POST['website_currency_amount']) ? $_POST['website_currency_amount'] : 0;
                $prize_distribution = isset($_POST['prize_distribution']) ? $_POST['prize_distribution'] : 'single';
                $coins_per_kill = isset($_POST['coins_per_kill']) ? $_POST['coins_per_kill'] : 0;

                // Validate match data using common function
                $matchData = validateMatchData([
                    'website_currency_type' => $website_currency_type,
                    'website_currency_amount' => $website_currency_amount,
                    'prize_pool' => $prize_pool
                ]);
                $prize_pool = $matchData['prize_pool'];
                $website_currency_type = $matchData['website_currency_type'];
                $website_currency_amount = $matchData['website_currency_amount'];

                try {
                    $match_id = isset($_POST['match_id']) && !empty($_POST['match_id']) ? $_POST['match_id'] : null;

                    if ($match_id) {
                        // Update existing match
                        $supabase->update('matches', [
                            'game_id' => $game_id,
                            'tournament_id' => $tournament_id,
                            'team1_id' => $team1_id,
                            'team2_id' => $team2_id,
                            'match_type' => $match_type,
                            'match_date' => $match_date,
                            'entry_type' => $entry_type,
                            'entry_fee' => $entry_fee,
                            'prize_pool' => $prize_pool,
                            'prize_type' => $prize_type,
                            'max_participants' => $max_participants,
                            'map_name' => $map_name,
                            'website_currency_type' => $website_currency_type,
                            'website_currency_amount' => $website_currency_amount,
                            'prize_distribution' => $prize_distribution,
                            'coins_per_kill' => $coins_per_kill
                        ], ['id' => $match_id]);

                        // Clean up existing team participants
                        try {
                            $supabase->delete('match_participants', 'match_id = $1 AND user_id IS NULL', [$match_id]);
                        } catch (Exception $deleteError) {
                            error_log("Warning: Failed to delete existing team participants: " . $deleteError->getMessage());
                        }

                        // Add new team participants
                        if ($team1_id) {
                            try {
                                $supabase->insert('match_participants', [
                                    'match_id' => $match_id,
                                    'team_id' => $team1_id
                                ]);
                            } catch (Exception $insertError) {
                                error_log("Warning: Failed to insert team1 participant: " . $insertError->getMessage());
                            }
                        }
                        if ($team2_id) {
                            try {
                                $supabase->insert('match_participants', [
                                    'match_id' => $match_id,
                                    'team_id' => $team2_id
                                ]);
                            } catch (Exception $insertError) {
                                error_log("Warning: Failed to insert team2 participant: " . $insertError->getMessage());
                            }
                        }
                        
                        $_SESSION['success_message'] = "Match updated successfully!";
                    } else {
                        // Create new match
                        $result = $supabase->insert('matches', [
                            'game_id' => $game_id,
                            'tournament_id' => $tournament_id,
                            'team1_id' => $team1_id,
                            'team2_id' => $team2_id,
                            'match_type' => $match_type,
                            'match_date' => $match_date,
                            'entry_type' => $entry_type,
                            'entry_fee' => $entry_fee,
                            'prize_pool' => $prize_pool,
                            'prize_type' => $prize_type,
                            'max_participants' => $max_participants,
                            'map_name' => $map_name,
                            'website_currency_type' => $website_currency_type,
                            'website_currency_amount' => $website_currency_amount,
                            'prize_distribution' => $prize_distribution,
                            'coins_per_kill' => $coins_per_kill,
                            'status' => 'upcoming'
                        ]);

                        $match_id = $result[0]['id'];

                        // Add team participants
                        if ($team1_id) {
                            try {
                                $supabase->insert('match_participants', [
                                    'match_id' => $match_id,
                                    'team_id' => $team1_id
                                ]);
                            } catch (Exception $insertError) {
                                error_log("Warning: Failed to insert team1 participant: " . $insertError->getMessage());
                            }
                        }
                        if ($team2_id) {
                            try {
                                $supabase->insert('match_participants', [
                                    'match_id' => $match_id,
                                    'team_id' => $team2_id
                                ]);
                            } catch (Exception $insertError) {
                                error_log("Warning: Failed to insert team2 participant: " . $insertError->getMessage());
                            }
                        }
                        
                        $_SESSION['success_message'] = "Match created successfully!";
                    }

                    header("Location: bgmi.php");
                    exit;
                } catch (Exception $e) {
                    error_log("Error details: line " . $e->getLine() . " in " . $e->getFile());
                    error_log("Match creation failed with message: " . $e->getMessage());
                    $_SESSION['error_message'] = "Error creating/updating match: " . $e->getMessage();
                    error_log("Error trace: " . $e->getTraceAsString());
                }
                break;

            case 'start_match':
                $match_id = $_POST['match_id'];
                $room_code = $_POST['room_code'];
                $room_password = $_POST['room_password'];
                
                try {
                    startMatch($match_id, $room_code, $room_password);
                    header("Location: bgmi.php");
                    exit;
                } catch (Exception $e) {
                    error_log("Error starting match: " . $e->getMessage());
                }
                break;

            case 'complete_match':
                $match_id = $_POST['match_id'];
                try {
                    completeMatch($match_id);
                } catch (Exception $e) {
                    error_log("Error completing match: " . $e->getMessage());
                }
                break;

            case 'delete_match':
                $match_id = $_POST['match_id'];
                try {
                    $result = deleteMatch($match_id);
                    $_SESSION['success_message'] = $result;
                } catch (Exception $e) {
                    error_log("Error deleting match: " . $e->getMessage());
                    $_SESSION['error_message'] = "Error deleting match: " . $e->getMessage();
                }
                
                header("Location: " . basename($_SERVER['PHP_SELF']));
                exit;
                break;
                
            case 'cancel_match':
                $match_id = $_POST['match_id'];
                try {
                    cancelMatch($match_id);
                    $_SESSION['success_message'] = "Match cancelled successfully. All participants have been refunded.";
                } catch (Exception $e) {
                    error_log("Error cancelling match: " . $e->getMessage());
                    $_SESSION['error_message'] = "Error cancelling match: " . $e->getMessage();
                }
                
                header("Location: " . basename($_SERVER['PHP_SELF']));
                exit;
                break;
                
            case 'clean_screenshots':
                $match_id = $_POST['match_id'];
                try {
                    $result = cleanMatchScreenshots($match_id, $_SESSION['admin_id']);
                    $_SESSION['success_message'] = $result;
                } catch (Exception $e) {
                    error_log("Error cleaning screenshots: " . $e->getMessage());
                    $_SESSION['error_message'] = "Error cleaning screenshots: " . $e->getMessage();
                }
                
                header("Location: " . basename($_SERVER['PHP_SELF']));
                exit;
                break;
        }
    }
}

$matches = fetchMatches($supabase, 'BGMI');
enrichMatchesWithDetails($supabase, $matches);

$data = fetchGamesTeamsTournaments($supabase);
$games = $data['games'];
$teams = $data['teams'];
$tournaments = $data['tournaments'];
?>

<div class="container-fluid">
    <div class="row">
        <?= renderSidebar('bgmi') ?>

        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="container-fluid py-4">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2 class="h3 mb-0">BGMI Match Management</h2>
                    <button type="button" class="btn btn-primary" id="addMatchButton">
                        <i class="bi bi-plus-circle"></i> Add New Match
                    </button>
                </div>

                <?= renderMatchesGrid($matches) ?>
            </div>
        </main>
    </div>
</div>

<!-- Add/Edit Match Modal -->
<div class="modal fade" id="addMatchModal" tabindex="-1" aria-labelledby="addMatchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addMatchModalLabel">Create New Match</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="matchForm" method="POST" class="needs-validation" novalidate>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_match">
                    <input type="hidden" name="match_id" id="match_id">
                    <input type="hidden" name="game_id" value="1"> <!-- BGMI game ID -->

                    <div class="row g-3">
                        <!-- Team Toggle -->
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" id="enableTeams" onchange="toggleTeamSection()">
                                <label class="form-check-label" for="enableTeams">Add Teams to Match</label>
                            </div>
                        </div>

                        <!-- Team Selections (Hidden by default) -->
                        <div id="teamSection" style="display: none;">
                            <div class="col-md-6">
                                <label for="team1_id" class="form-label">Team 1</label>
                                <select class="form-select" id="team1_id" name="team1_id">
                                    <option value="">Select Team 1</option>
                                    <?php foreach ($teams as $team): ?>
                                        <option value="<?= $team['id'] ?>"><?= htmlspecialchars($team['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="team2_id" class="form-label">Team 2</label>
                                <select class="form-select" id="team2_id" name="team2_id">
                                    <option value="">Select Team 2</option>
                                    <?php foreach ($teams as $team): ?>
                                        <option value="<?= $team['id'] ?>"><?= htmlspecialchars($team['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- Match Type -->
                        <div class="col-md-6">
                            <label for="match_type" class="form-label">Match Type</label>
                            <select class="form-select" id="match_type" name="match_type" required>
                                <option value="">Select Match Type</option>
                                <option value="solo">Solo</option>
                                <option value="duo">Duo</option>
                                <option value="squad">Squad</option>
                                <option value="tdm">Team Deathmatch</option>
                            </select>
                            <div class="invalid-feedback">Please select a match type.</div>
                        </div>

                        <!-- Entry Type -->
                        <div class="col-md-6">
                            <label for="entry_type" class="form-label">Entry Type</label>
                            <select class="form-select" id="entry_type" name="entry_type" required onchange="toggleEntryFee()">
                                <option value="">Select Entry Type</option>
                                <option value="free">Free</option>
                                <option value="coins">Coins</option>
                                <option value="tickets">Tickets</option>
                            </select>
                            <div class="invalid-feedback">Please select an entry type.</div>
                        </div>

                        <!-- Entry Fee -->
                        <div class="col-md-6" id="entryFeeContainer" style="display: none;">
                            <label for="entry_fee" class="form-label">Entry Fee</label>
                            <input type="number" class="form-control" id="entry_fee" name="entry_fee" min="0">
                            <div class="invalid-feedback">Please enter a valid entry fee.</div>
                        </div>

                        <!-- Max Participants -->
                        <div class="col-md-6">
                            <label for="max_participants" class="form-label">Max Participants</label>
                            <input type="number" class="form-control" id="max_participants" name="max_participants" required min="2">
                            <div class="invalid-feedback">Please enter the maximum number of participants.</div>
                        </div>

                        <!-- Prize Pool Section -->
                        <div class="col-12">
                            <div class="form-check form-switch mb-3">
                                <input class="form-check-input" type="checkbox" id="useWebsiteCurrency" onchange="togglePrizeCurrency()">
                                <label class="form-check-label" for="useWebsiteCurrency">Use Website Currency for Prize Pool</label>
                            </div>
                        </div>

                        <!-- Real Currency Prize Section -->
                        <div id="realCurrencySection">
                            <div class="col-md-6">
                                <label for="prize_type" class="form-label">Prize Currency</label>
                                <select class="form-select" id="prize_type" name="prize_type">
                                    <option value="INR">₹ (INR)</option>
                                    <option value="USD">$ (USD)</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="prize_pool" class="form-label">Prize Pool Amount</label>
                                <div class="input-group">
                                    <span class="input-group-text" id="prize-currency">₹</span>
                                    <input type="number" class="form-control" id="prize_pool" name="prize_pool" min="0">
                                </div>
                            </div>
                        </div>

                        <!-- Website Currency Prize Section -->
                        <div id="websiteCurrencySection" style="display: none;">
                            <div class="col-md-6">
                                <label for="website_currency_type" class="form-label">Website Currency Type</label>
                                <select class="form-select" id="website_currency_type" name="website_currency_type">
                                    <option value="coins">Coins</option>
                                    <option value="tickets">Tickets</option>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="website_currency_amount" class="form-label">Prize Amount</label>
                                <div class="input-group">
                                    <input type="number" class="form-control" id="website_currency_amount" name="website_currency_amount" min="0">
                                    <span class="input-group-text website-currency-label">Coins</span>
                                </div>
                            </div>
                        </div>

                        <!-- Prize Distribution -->
                        <div class="col-md-6">
                            <label for="prize_distribution" class="form-label">Prize Distribution</label>
                            <select class="form-select" id="prize_distribution" name="prize_distribution" required>
                                <option value="single">Winner Takes All</option>
                                <option value="top3">Top 3 Positions</option>
                                <option value="top5">Top 5 Positions</option>
                            </select>
                            <div class="form-text">Select how the prize pool will be distributed among winners</div>
                        </div>

                        <!-- Coins per Kill -->
                        <div class="col-md-6">
                            <label for="coins_per_kill" class="form-label">Coins per Kill</label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="coins_per_kill" name="coins_per_kill" min="0" value="0">
                                <span class="input-group-text">Coins</span>
                            </div>
                            <div class="form-text">Set how many coins a player earns for each kill (0 to disable)</div>
                        </div>

                        <!-- Map Selection -->
                        <div class="col-md-6">
                            <label for="map_name" class="form-label">Map</label>
                            <select class="form-select" id="map_name" name="map_name" required>
                                <option value="">Select Map</option>
                                <option value="Erangel">Erangel</option>
                                <option value="Miramar">Miramar</option>
                                <option value="Sanhok">Sanhok</option>
                                <option value="Vikendi">Vikendi</option>
                                <option value="Karakin">Karakin</option>
                            </select>
                            <div class="invalid-feedback">Please select a map.</div>
                        </div>

                        <!-- Match Date -->
                        <div class="col-md-6">
                            <label for="match_date" class="form-label">Match Date</label>
                            <input type="date" class="form-control" id="match_date" name="match_date" required>
                            <div class="invalid-feedback">Please select a match date.</div>
                        </div>

                        <!-- Match Time -->
                        <div class="col-md-6">
                            <label for="match_time" class="form-label">Match Time</label>
                            <input type="time" class="form-control" id="match_time" name="match_time" required>
                            <div class="invalid-feedback">Please select a match time.</div>
                        </div>

                        <!-- Rules -->
                        <div class="col-12">
                            <label for="rules" class="form-label">Match Rules</label>
                            <textarea class="form-control" id="rules" name="rules" rows="3" placeholder="Enter match rules and guidelines..."></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Match</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Room Details Modal -->
<div class="modal fade" id="roomDetailsModal" tabindex="-1" aria-labelledby="roomDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="roomDetailsModalLabel">Add Room Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="roomDetailsForm" method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="start_match">
                    <input type="hidden" name="match_id" id="room_match_id">
                    
                    <div class="mb-3">
                        <label for="room_code" class="form-label">Room Code</label>
                        <input type="text" class="form-control" id="room_code" name="room_code" required>
                        <div class="form-text">Enter the room code for participants to join.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="room_password" class="form-label">Room Password</label>
                        <input type="text" class="form-control" id="room_password" name="room_password" required>
                        <div class="form-text">Enter the room password for participants to join.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Start Match</button>
                </div>
            </form>
        </div>
    </div>
</div>

<link rel="stylesheet" href="../assets/css/matches/match-management.css">
<script src="../assets/js/matches/common.js"></script>
<script src="../assets/js/matches/bgmi.js"></script>
<script>
// Handle session messages
document.addEventListener('DOMContentLoaded', function() {
    <?php if (isset($_SESSION['success_message'])): ?>
        alert('<?= htmlspecialchars($_SESSION['success_message']) ?>');
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error_message'])): ?>
        alert('<?= htmlspecialchars($_SESSION['error_message']) ?>');
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>
});
</script>

<?php include '../includes/admin-footer.php'; ?>
