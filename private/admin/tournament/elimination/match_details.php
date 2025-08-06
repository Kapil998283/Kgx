<?php
session_start();

// Define secure access for admin files
define('SECURE_ACCESS', true);

// Load secure configuration and Supabase client
require_once dirname(__DIR__) . '/../admin_secure_config.php';
require_once dirname(__DIR__) . '/common.php';

$supabase = getSupabaseClient(true);

// Check if admin is logged in
if(!isset($_SESSION['admin_id'])) {
    header("Location: ../index.php");
    exit();
}

// Include admin header after authentication
include '../includes/admin-header.php';

// Get match ID and referring page
$match_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$referrer = isset($_SERVER['HTTP_REFERER']) ? basename($_SERVER['HTTP_REFERER']) : 'matches.php';

// Determine game type from referrer
$game_type = 'BGMI'; // default
if (strpos($referrer, 'pubg.php') !== false) {
    $game_type = 'PUBG';
} elseif (strpos($referrer, 'freefire.php') !== false) {
    $game_type = 'Free Fire';
} elseif (strpos($referrer, 'cod.php') !== false) {
    $game_type = 'Call of Duty';
}

// Fetch match details using Supabase
$match = $supabase->select('matches', '*', ['id' => $match_id]);
if (empty($match)) {
    header("Location: $referrer");
    exit();
}
$match = $match[0];

// Fetch additional game details
$game = $supabase->select('games', '*', ['id' => $match['game_id']]);
if (!empty($game)) {
    $match['game_name'] = $game[0]['name'];
    $match['game_image'] = $game[0]['image_url'];
}

// Fetch team details if applicable
if ($match['team1_id']) {
    $team1 = $supabase->select('teams', '*', ['id' => $match['team1_id']]);
    if (!empty($team1)) {
        $match['team1_name'] = $team1[0]['name'];
        $match['team1_logo'] = $team1[0]['logo'];
    }
}

if ($match['team2_id']) {
    $team2 = $supabase->select('teams', '*', ['id' => $match['team2_id']]);
    if (!empty($team2)) {
        $match['team2_name'] = $team2[0]['name'];
        $match['team2_logo'] = $team2[0]['logo'];
    }
}

// Fetch tournament details if applicable
if ($match['tournament_id']) {
    $tournament = $supabase->select('tournaments', '*', ['id' => $match['tournament_id']]);
    if (!empty($tournament)) {
        $match['tournament_name'] = $tournament[0]['name'];
    }
}

// Count current participants
$participants_count = $supabase->select('match_participants', '*', ['match_id' => $match_id]);
$match['current_participants'] = count($participants_count);

// Fetch participants for this match
$participants_raw = $supabase->select('match_participants', '*', ['match_id' => $match_id]);
$participants = [];
foreach ($participants_raw as $participant) {
    // Fetch user details for each participant
    $user = $supabase->select('users', ['username', 'profile_image', 'email'], ['id' => $participant['user_id']]);
    if (!empty($user)) {
        $participant['username'] = $user[0]['username'];
        $participant['profile_image'] = $user[0]['profile_image'];
        $participant['email'] = $user[0]['email'];
        
        // Fetch user game details - Fix the game name mapping issue
        $game_name_mapping = [
            'Free Fire' => 'FREE FIRE',
            'Call of Duty Mobile' => 'COD',
            'Call of Duty' => 'COD'
        ];
        
        // Use mapped name or original name for profile lookup
        $profile_game_name = isset($game_name_mapping[$match['game_name']]) ? 
                             $game_name_mapping[$match['game_name']] : 
                             $match['game_name'];
        
        $user_game = $supabase->select('user_games', '*', ['user_id' => $participant['user_id'], 'game_name' => $profile_game_name]);
        if (!empty($user_game)) {
            $participant['game_uid'] = $user_game[0]['game_uid'];
            $participant['game_username'] = $user_game[0]['game_username'];
            $participant['game_level'] = $user_game[0]['game_level'] ?? 1;
        } else {
            $participant['game_uid'] = '';
            $participant['game_username'] = '';
            $participant['game_level'] = 1;
        }
        
        // Get kills for this match
        $kills = $supabase->select('user_kills', '*', ['match_id' => $match_id, 'user_id' => $participant['user_id']]);
        $participant['total_kills'] = !empty($kills) ? $kills[0]['kills'] : 0;
        
        $participants[] = $participant;
    }
}
// Sort by join_date
usort($participants, function($a, $b) {
    return strtotime($a['join_date']) - strtotime($b['join_date']);
});

// Fetch all teams for dropdown
$teams = $supabase->select('teams', ['id', 'name']);
// Sort by name
usort($teams, function($a, $b) {
    return strcmp($a['name'], $b['name']);
});

// Fetch all tournaments for dropdown
$tournaments = $supabase->select('tournaments', ['id', 'name']);
// Sort by name
usort($tournaments, function($a, $b) {
    return strcmp($a['name'], $b['name']);
});

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_match':
                try {
                    $db->beginTransaction();
                    
                    $tournament_id = $_POST['tournament_id'] ?: null;
                    $team1_id = $_POST['team1_id'] ?: null;
                    $team2_id = $_POST['team2_id'] ?: null;
                    $match_type = $_POST['match_type'];
                    $entry_type = $_POST['entry_type'];
                    $entry_fee = $_POST['entry_fee'];
                    $prize_pool = $_POST['prize_pool'];
                    $max_participants = $_POST['max_participants'];
                    $status = $_POST['status'];
                    $map_name = $_POST['map_name'];
                    $match_date = $_POST['match_date'] . ' ' . $_POST['match_time'];
                    
                    $stmt = $db->prepare("UPDATE matches 
                                        SET tournament_id = ?, team1_id = ?, team2_id = ?,
                                            match_type = ?, entry_type = ?, entry_fee = ?,
                                            prize_pool = ?, max_participants = ?, status = ?,
                                            map_name = ?, match_date = ?
                                        WHERE id = ?");
                    $stmt->execute([
                        $tournament_id, $team1_id, $team2_id, $match_type, $entry_type,
                        $entry_fee, $prize_pool, $max_participants, $status, $map_name,
                        $match_date, $match_id
                    ]);
                    
                    $db->commit();
                    header("Location: match_details.php?id=$match_id&updated=1");
                    exit;
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = "Error updating match: " . $e->getMessage();
                }
                break;

            case 'remove_participant':
                try {
                    $db->beginTransaction();
                    
                    $participant_id = $_POST['participant_id'];
                    
                    // Delete participant
                    $stmt = $db->prepare("DELETE FROM match_participants WHERE id = ? AND match_id = ?");
                    $stmt->execute([$participant_id, $match_id]);
                    
                    $db->commit();
                    header("Location: match_details.php?id=$match_id&removed=1");
                    exit;
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = "Error removing participant: " . $e->getMessage();
                }
                break;
        }
    }
}

// Get game-specific match types
function getMatchTypes($game_type) {
    switch ($game_type) {
        case 'PUBG':
            return ['Solo' => 'Solo', 'Duo' => 'Duo', 'Squad' => 'Squad', 'TDM' => 'Team Deathmatch'];
        case 'Free Fire':
            return ['Solo' => 'Solo', 'Duo' => 'Duo', 'Squad' => 'Squad', 'Clash' => 'Clash Squad'];
        case 'Call of Duty':
            return ['MP' => 'Multiplayer', 'BR' => 'Battle Royale', 'ZM' => 'Zombies'];
        default: // BGMI
            return ['Classic' => 'Classic', 'TDM' => 'Team Deathmatch', 'Arena' => 'Arena'];
    }
}

// Get game-specific maps
function getMaps($game_type) {
    switch ($game_type) {
        case 'PUBG':
            return ['Erangel' => 'Erangel', 'Miramar' => 'Miramar', 'Sanhok' => 'Sanhok', 'Vikendi' => 'Vikendi'];
        case 'Free Fire':
            return ['Bermuda' => 'Bermuda', 'Purgatory' => 'Purgatory', 'Kalahari' => 'Kalahari'];
        case 'Call of Duty':
            return ['Isolated' => 'Isolated', 'Blackout' => 'Blackout', 'Alcatraz' => 'Alcatraz'];
        default: // BGMI
            return ['Erangel' => 'Erangel', 'Miramar' => 'Miramar', 'Sanhok' => 'Sanhok', 'Livik' => 'Livik'];
    }
}

$match_types = getMatchTypes($game_type);
$maps = getMaps($game_type);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Match Details - <?= htmlspecialchars($match['game_name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">
</head>
<body>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <!-- Success Messages -->
            <?php if (isset($_GET['updated'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    Match details have been successfully updated.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['removed'])): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    Participant has been successfully removed.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Error Messages -->
            <?php if (isset($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Back Button and Title -->
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2 class="h3 mb-0">
                    <img src="../<?= htmlspecialchars($match['game_image']) ?>" alt="<?= htmlspecialchars($match['game_name']) ?>" class="game-icon me-2">
                    <?= htmlspecialchars($match['game_name']) ?> Match Details
                </h2>
                <a href="<?= htmlspecialchars($referrer) ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Back to Matches
                </a>
            </div>

            <!-- Match Details Card -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="card-title mb-0">Match Information</h5>
                </div>
                <div class="card-body">
                    <form method="POST" class="needs-validation" novalidate>
                        <input type="hidden" name="action" value="update_match">
                        
                        <div class="row g-3">
                            <!-- Tournament Selection -->
                            <div class="col-md-6">
                                <label for="tournament_id" class="form-label">Tournament</label>
                                <select class="form-select" id="tournament_id" name="tournament_id">
                                    <option value="">No Tournament</option>
                                    <?php foreach ($tournaments as $tournament): ?>
                                        <option value="<?= $tournament['id'] ?>" <?= $match['tournament_id'] == $tournament['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($tournament['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Match Type -->
                            <div class="col-md-6">
                                <label for="match_type" class="form-label">Match Type</label>
                                <select class="form-select" id="match_type" name="match_type" required>
                                    <?php foreach ($match_types as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $match['match_type'] === $value ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Team Selections -->
                            <div class="col-md-6">
                                <label for="team1_id" class="form-label">Team 1</label>
                                <select class="form-select" id="team1_id" name="team1_id">
                                    <option value="">No Team</option>
                                    <?php foreach ($teams as $team): ?>
                                        <option value="<?= $team['id'] ?>" <?= $match['team1_id'] == $team['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($team['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label for="team2_id" class="form-label">Team 2</label>
                                <select class="form-select" id="team2_id" name="team2_id">
                                    <option value="">No Team</option>
                                    <?php foreach ($teams as $team): ?>
                                        <option value="<?= $team['id'] ?>" <?= $match['team2_id'] == $team['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($team['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Map Selection -->
                            <div class="col-md-6">
                                <label for="map_name" class="form-label">Map</label>
                                <select class="form-select" id="map_name" name="map_name" required>
                                    <?php foreach ($maps as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $match['map_name'] === $value ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($label) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Entry Type and Fee -->
                            <div class="col-md-6">
                                <label for="entry_type" class="form-label">Entry Type</label>
                                <select class="form-select" id="entry_type" name="entry_type" required onchange="toggleEntryFee()">
                                    <option value="free" <?= $match['entry_type'] === 'free' ? 'selected' : '' ?>>Free</option>
                                    <option value="coins" <?= $match['entry_type'] === 'coins' ? 'selected' : '' ?>>Coins</option>
                                    <option value="diamonds" <?= $match['entry_type'] === 'diamonds' ? 'selected' : '' ?>>Diamonds</option>
                                </select>
                            </div>

                            <div class="col-md-6" id="entryFeeContainer">
                                <label for="entry_fee" class="form-label">Entry Fee</label>
                                <input type="number" class="form-control" id="entry_fee" name="entry_fee" 
                                       value="<?= htmlspecialchars($match['entry_fee']) ?>" min="0">
                            </div>

                            <!-- Prize Pool -->
                            <div class="col-md-6">
                                <label for="prize_pool" class="form-label">Prize Pool</label>
                                <input type="number" class="form-control" id="prize_pool" name="prize_pool" 
                                       value="<?= htmlspecialchars($match['prize_pool']) ?>" required min="0">
                            </div>

                            <!-- Max Participants -->
                            <div class="col-md-6">
                                <label for="max_participants" class="form-label">Max Participants</label>
                                <input type="number" class="form-control" id="max_participants" name="max_participants" 
                                       value="<?= htmlspecialchars($match['max_participants']) ?>" required min="2">
                            </div>

                            <!-- Date and Time -->
                            <div class="col-md-6">
                                <label for="match_date" class="form-label">Match Date</label>
                                <input type="date" class="form-control" id="match_date" name="match_date" 
                                       value="<?= date('Y-m-d', strtotime($match['match_date'])) ?>" required>
                            </div>

                            <div class="col-md-6">
                                <label for="match_time" class="form-label">Match Time</label>
                                <input type="time" class="form-control" id="match_time" name="match_time" 
                                       value="<?= date('H:i', strtotime($match['match_date'])) ?>" required>
                            </div>

                            <!-- Status -->
                            <div class="col-md-6">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status" required>
                                    <option value="upcoming" <?= $match['status'] === 'upcoming' ? 'selected' : '' ?>>Upcoming</option>
                                    <option value="in_progress" <?= $match['status'] === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                    <option value="completed" <?= $match['status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                                    <option value="cancelled" <?= $match['status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                </select>
                            </div>
                        </div>

                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Update Match
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Participants Card -->
            <div class="card">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Participants</h5>
                    <span class="badge bg-light text-dark">
                        <?= count($participants) ?> / <?= $match['max_participants'] ?>
                    </span>
                </div>
                <div class="card-body">
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

                    <?php if (empty($participants)): ?>
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-people" style="font-size: 2rem;"></i>
                            <p class="mt-2">No participants have joined this match yet.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover" id="participantsTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Player</th>
                                        <th>Game UID</th>
                                        <th>In-Game Name</th>
                                        <th>Kills</th>
                                        <th>Contact</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($participants)): ?>
                                        <?php $serialNo = 1; ?>
                                        <?php foreach ($participants as $participant): ?>
                                            <tr class="participant-row" data-user-id="<?= $participant['user_id'] ?>">
                                                <td><?= $serialNo++ ?></td>
                                                <td>
                                                    <div class="d-flex flex-column">
                                                        <strong><?= htmlspecialchars($participant['username'] ?? 'N/A') ?></strong>
                                                        <small class="text-muted"><?= htmlspecialchars($participant['email'] ?? '') ?></small>
                                                        <?php if (!empty($participant['position'])): ?>
                                                            <span class="badge bg-success mt-1">Position: <?= $participant['position'] ?></span>
                                                        <?php endif; ?>
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
                                                    <div class="contact-info">
                                                        <small class="text-muted"><?= htmlspecialchars($participant['email']) ?></small>
                                                        <?php if (!empty($participant['phone'])): ?>
                                                            <br><small class="text-muted"><?= htmlspecialchars($participant['phone']) ?></small>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <?php
                                                        // Get screenshots to check status
                                                        $screenshots = $supabase->select('match_screenshots', '*', ['match_id' => $match_id, 'user_id' => $participant['user_id']]);
                                                        $hasScreenshots = !empty($screenshots);
                                                        $isVerified = false;
                                                        if ($hasScreenshots) {
                                                            foreach ($screenshots as $screenshot) {
                                                                if ($screenshot['verified']) {
                                                                    $isVerified = true;
                                                                    break;
                                                                }
                                                            }
                                                        }
                                                    ?>
                                                    <div class="status-badges">
                                                        <span class="badge bg-<?= $participant['status'] === 'joined' ? 'success' : 
                                                            ($participant['status'] === 'disqualified' ? 'danger' : 'warning') ?>">
                                                            <?= ucfirst($participant['status']) ?>
                                                        </span>
                                                        <?php if ($hasScreenshots && $isVerified): ?>
                                                            <span class="badge bg-success ms-1">Verified</span>
                                                        <?php elseif ($hasScreenshots): ?>
                                                            <span class="badge bg-warning ms-1">Pending</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary ms-1">No Proof</span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div class="action-buttons">
                                                        <!-- View Profile Button -->
                                                        <button class="btn btn-sm btn-secondary me-1 mb-1" 
                                                                onclick="viewUserProfile(<?= $participant['user_id'] ?>)">
                                                            <i class="bi bi-person"></i> Profile
                                                        </button>
                                                        
                                                        <!-- Remove Participant -->
                                                        <form method="POST" class="d-inline" 
                                                              onsubmit="return confirm('Are you sure you want to remove this participant?');">
                                                            <input type="hidden" name="action" value="remove_participant">
                                                            <input type="hidden" name="participant_id" value="<?= $participant['id'] ?>">
                                                            <button type="submit" class="btn btn-sm btn-danger">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-4">
                                                <i class="bi bi-people"></i> No participants found for this match
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.game-icon {
    width: 40px;
    height: 40px;
    object-fit: cover;
    border-radius: 8px;
}

.participant-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    object-fit: cover;
}

.card {
    box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
    margin-bottom: 1.5rem;
}

.card-header {
    background-color: #f8f9fa;
    border-bottom: 1px solid rgba(0, 0, 0, 0.125);
}

.form-label {
    font-weight: 500;
    margin-bottom: 0.5rem;
}

.badge {
    font-weight: 500;
    padding: 0.5em 0.75em;
}

.table > :not(caption) > * > * {
    padding: 0.75rem;
}

.btn-sm {
    padding: 0.25rem 0.5rem;
    margin: 0.25rem;
}

.alert {
    margin-bottom: 1rem;
}

/* Enhanced table styling to match match_scoring.php */
.table th {
    background: #f8f9fa;
    font-weight: 600;
}

.table td {
    vertical-align: middle;
}

/* Game UID and username styling */
.game-uid code {
    font-size: 0.9rem;
}

.game-username .fw-bold {
    font-size: 1rem;
}

.game-username small {
    font-size: 0.8rem;
}

/* Kills display styling */
.kills-display .badge {
    font-size: 1rem;
    padding: 0.5em 0.75em;
}

/* Status badges styling */
.status-badges .badge {
    font-size: 0.8rem;
    margin-bottom: 0.25rem;
}

/* Action buttons styling */
.action-buttons {
    white-space: nowrap;
}

.action-buttons .btn {
    margin: 0.125rem;
}

/* Contact info styling */
.contact-info small {
    display: block;
    line-height: 1.3;
}

@media (max-width: 768px) {
    .container-fluid {
        padding-left: 1rem;
        padding-right: 1rem;
    }
    
    .table-responsive {
        margin-bottom: 1rem;
    }
    
    .action-buttons {
        white-space: normal;
    }
    
    .action-buttons .btn {
        margin: 0.125rem 0;
        display: block;
        width: 100%;
    }
}
</style>

<script>
function toggleEntryFee() {
    const entryType = document.getElementById('entry_type').value;
    const entryFeeContainer = document.getElementById('entryFeeContainer');
    const entryFeeInput = document.getElementById('entry_fee');
    
    if (entryType === 'free') {
        entryFeeContainer.style.display = 'none';
        entryFeeInput.value = '0';
        entryFeeInput.required = false;
    } else {
        entryFeeContainer.style.display = 'block';
        entryFeeInput.required = true;
    }
}

// Initialize entry fee visibility
document.addEventListener('DOMContentLoaded', function() {
    toggleEntryFee();
    
    // Search functionality
    const searchInput = document.getElementById('searchInput');
    const participantsTable = document.getElementById('participantsTable');
    
    if (searchInput && participantsTable) {
        const tableRows = participantsTable.querySelectorAll('tbody .participant-row');
        
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            
            tableRows.forEach(row => {
                const username = row.cells[1].textContent.toLowerCase();
                const gameUID = row.cells[2].textContent.toLowerCase();
                const inGameName = row.cells[3].textContent.toLowerCase();
                
                const matches = username.includes(searchTerm) || 
                              gameUID.includes(searchTerm) || 
                              inGameName.includes(searchTerm);
                
                row.style.display = matches ? '' : 'none';
            });
        });
    }
});

// View user profile (placeholder function)
function viewUserProfile(userId) {
    // This can be extended to show user profile details
    // For now, just redirect to a user profile page or show an alert
    alert('User profile functionality can be implemented here. User ID: ' + userId);
    // Example: window.open('../users/profile.php?id=' + userId, '_blank');
}

// Form validation
(function () {
    'use strict'
    const forms = document.querySelectorAll('.needs-validation')
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault()
                event.stopPropagation()
            }
            form.classList.add('was-validated')
        }, false)
    })
})()
</script>

<?php include __DIR__ . '/../includes/admin-footer.php'; ?>
