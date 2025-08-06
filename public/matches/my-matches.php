<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
define('SECURE_ACCESS', true);
require_once '../secure_config.php';


// Load secure configurations and Supabase client
loadSecureConfig('supabase.php');
loadSecureInclude('SupabaseClient.php');
loadSecureInclude('auth.php');

// Initialize AuthManager and SupabaseClient
$authManager = new AuthManager();
$supabaseClient = new SupabaseClient();

// Check if user is logged in
if (!$authManager->isLoggedIn()) {
    $redirect_url = BASE_URL . "register/login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']);
    header("Location: " . $redirect_url);
    exit();
}

$currentUser = $authManager->getCurrentUser();
$user_id = $currentUser['user_id'];

// Fetch user's joined matches using Supabase
$matches = [];
try {
    $participant_matches = $supabaseClient->select('match_participants', 'match_id, status, join_date', ['user_id' => $user_id]);

    if (!empty($participant_matches)) {
        $match_ids = array_column($participant_matches, 'match_id');
        
        // Create a map for participation status and join date for easy lookup
        $participation_map = [];
        foreach ($participant_matches as $p_match) {
            $participation_map[$p_match['match_id']] = [
                'status' => $p_match['status'],
                'join_date' => $p_match['join_date']
            ];
        }

        // Fetch each match individually to ensure proper data retrieval
        $fetched_matches = [];
        foreach ($match_ids as $match_id) {
            try {
                $single_match = $supabaseClient->select('matches', '*', ['id' => $match_id]);
                if (!empty($single_match)) {
                    $fetched_matches = array_merge($fetched_matches, $single_match);
                }
            } catch (Exception $e) {
                error_log("Error fetching match ID {$match_id}: " . $e->getMessage());
            }
        }

        if (!empty($fetched_matches)) {
            // Get all games data first for efficient lookup
            $games_data = [];
            try {
                $games_result = $supabaseClient->select('games', '*', ['status' => 'active']);
                if (!empty($games_result)) {
                    foreach ($games_result as $game) {
                        $games_data[$game['id']] = $game;
                    }
                }
            } catch (Exception $e) {
                error_log("Error fetching games: " . $e->getMessage());
            }
            
            foreach ($fetched_matches as $match) {
                // Get game details from cached games data
                if (isset($games_data[$match['game_id']])) {
                    $match['game_name'] = $games_data[$match['game_id']]['name'];
                    $match['game_image'] = $games_data[$match['game_id']]['image_url'];
                } else {
                    $match['game_name'] = 'Unknown Game';
                    $match['game_image'] = '';
                }

                // Add participation details
                $match['participation_status'] = $participation_map[$match['id']]['status'] ?? 'unknown';
                $match['join_date'] = $participation_map[$match['id']]['join_date'] ?? null;
                
                // Get participant count
                try {
                    $participants = $supabaseClient->select('match_participants', '*', [
                        'match_id' => $match['id']
                    ]);
                    $match['current_participants'] = count($participants ?? []);
                } catch (Exception $e) {
                    $match['current_participants'] = 0;
                }
                
                // Ensure proper date and time handling for consistency
                if (isset($match['match_date'])) {
                    try {
                        $datetime = new DateTime($match['match_date']);
                        // Keep the full datetime for status comparison
                        $match['match_date'] = $datetime->format('Y-m-d H:i:s');
                        // Extract time for display if not already set
                        if (!isset($match['match_time'])) {
                            $match['match_time'] = $datetime->format('H:i:s');
                        }
                    } catch (Exception $e) {
                        error_log("Error parsing match date: " . $e->getMessage());
                        // Fallback: if match_time is not set, use the match_date
                        if (!isset($match['match_time'])) {
                            $match['match_time'] = $match['match_date'];
                        }
                    }
                }
                
                $matches[] = $match;
            }
        }
    }
    
    // Sort matches to prioritize "playing" (in_progress) matches first
    if (!empty($matches)) {
        usort($matches, function ($a, $b) {
            $status_priority = [
                'in_progress' => 1,  // Playing - highest priority
                'upcoming' => 2,     // Joined - second priority  
                'completed' => 3     // Finished - lowest priority
            ];
            
            $a_priority = $status_priority[$a['status']] ?? 4;
            $b_priority = $status_priority[$b['status']] ?? 4;
            
            if ($a_priority !== $b_priority) {
                return $a_priority - $b_priority;
            }
            
            // If same status, sort by match date
            return strtotime($a['match_date']) - strtotime($b['match_date']);
        });
    }
} catch (Exception $e) {
    error_log("Error fetching my matches: " . $e->getMessage());
}

loadSecureInclude('header.php');
?>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/matches/matches.css">

<div class="matches-section">
    <div class="matches-container">
        <div class="section-header">
            <h2 class="section-title">My Matches</h2>
            <a href="index.php" class="my-matches-link">
                <i class="bi bi-arrow-left"></i> Back to All Matches
            </a>
        </div>

        <div class="matches-grid">
            <?php if (empty($matches)): ?>
                <div class="no-matches">
                    <i class="bi bi-controller"></i>
                    <p>You haven't joined any matches yet.</p>
                    <a href="index.php" class="btn-join btn-primary">Browse Available Matches</a>
                </div>
            <?php else: ?>
                <?php foreach ($matches as $match): ?>
                    <div class="match-card">
                        <div class="match-header">
                            <div class="game-info">
                                <img src="<?= htmlspecialchars($match['game_image']) ?>" 
                                     alt="<?= htmlspecialchars($match['game_name']) ?>" 
                                     class="game-icon">
                                <div>
                                    <h3 class="game-name"><?= htmlspecialchars($match['game_name']) ?></h3>
                                </div>
                            </div>
                            <span class="match-type">
                                <i class="bi bi-people"></i> <?= ucfirst(htmlspecialchars($match['match_type'])) ?>
                            </span>
                        </div>
                        
                        <div class="match-info">
                            <div class="info-grid">
                                <div class="info-item">
                                    <i class="bi bi-calendar"></i>
                                    <span data-tournament-date="<?= htmlspecialchars($match['match_date']); ?>">
                                        <?= date('M j, Y', strtotime($match['match_date'])) ?>
                                    </span>
                                </div>
                                <div class="info-item">
                                    <i class="bi bi-clock"></i>
                                    <span data-tournament-datetime="<?= htmlspecialchars($match['match_date']); ?>" data-format="time">
                                        <?php
                                        try {
                                            // Use match_date for time display since it contains full datetime
                                            echo date('g:i A', strtotime($match['match_date']));
                                        } catch (Exception $e) {
                                            echo 'Time TBD';
                                        }
                                        ?>
                                    </span>
                                </div>
                                <div class="info-item">
                                    <i class="bi bi-map"></i>
                                    <?= htmlspecialchars($match['map_name']) ?>
                                </div>
                                <div class="info-item">
                                    <i class="bi bi-people"></i>
                                    <?= $match['current_participants'] ?>/<?= $match['max_participants'] ?>
                                </div>
                            </div>

                            <div class="entry-fee">
                                <i class="bi bi-ticket"></i>
                                <?php if ($match['entry_type'] === 'free'): ?>
                                    Free Entry
                                <?php else: ?>
                                    Entry: <?= number_format($match['entry_fee']) ?> <?= ucfirst($match['entry_type']) ?>
                                <?php endif; ?>
                            </div>
                            

                            <div class="match-status">
                                <?php 
                                $statusText = 'Joined';
                                $statusClass = 'status-upcoming';
                                $current_status = strtolower($match['status']);
                                switch($current_status) {
                                    case 'upcoming':
                                        $statusText = 'Joined';
                                        $statusClass = 'status-upcoming';
                                        break;
                                    case 'in_progress':
                                        $statusText = 'Playing';
                                        $statusClass = 'status-progress';
                                        break;
                                    case 'completed':
                                        $statusText = 'Finished';
                                        $statusClass = 'status-completed';
                                        break;
                                    default:
                                        $statusText = ucfirst($current_status);
                                        $statusClass = 'status-upcoming';
                                        break;
                                }
                                ?>
                                <span class="status-badge <?= $statusClass ?>">
                                    <i class="bi bi-circle-fill"></i>
                                    <?= $statusText ?>
                                </span>
                            </div>

                            <?php if ($match['status'] === 'in_progress'): ?>
                                <div class="room-details">
                                    <div class="room-info">
                                        <i class="bi bi-door-open"></i>
                                        Room ID: <strong id="room-id-<?= $match['id'] ?>"><?= htmlspecialchars($match['room_code']) ?></strong>
                                        <button class="copy-btn" onclick="copyToClipboard('room-id-<?= $match['id'] ?>')"><i class="bi bi-clipboard"></i></button>
                                    </div>
                                    <div class="room-info">
                                        <i class="bi bi-key"></i>
                                        Password: <strong id="room-pass-<?= $match['id'] ?>"><?= htmlspecialchars($match['room_password']) ?></strong>
                                        <button class="copy-btn" onclick="copyToClipboard('room-pass-<?= $match['id'] ?>')"><i class="bi bi-clipboard"></i></button>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div class="join-info">
                                <i class="bi bi-calendar-check"></i>
                                Joined: <span data-tournament-datetime="<?= htmlspecialchars($match['join_date']); ?>">
                                    <?= date('M j, Y g:i A', strtotime($match['join_date'])) ?>
                                </span>
                            </div>
                        </div>

                        <div class="match-actions">
                            <?php if ($match['status'] === 'upcoming'): ?>
                                <!-- Joined/Upcoming - Show View Participants -->
                                <a href="view-participants.php?match_id=<?= $match['id'] ?>" class="btn-join btn-info">
                                    <i class="bi bi-people"></i> View Participants
                                </a>
                            <?php elseif ($match['status'] === 'in_progress'): ?>
                                <!-- Playing/In Progress - Show Upload Picture -->
                                <a href="upload.php?match_id=<?= $match['id'] ?>" class="btn-join btn-success">
                                    <i class="bi bi-cloud-upload"></i> Upload Picture
                                </a>
                            <?php elseif ($match['status'] === 'completed'): ?>
                                <!-- Finished/Completed - Show View Winner -->
                                <a href="view-winner.php?match_id=<?= $match['id'] ?>" class="btn-join btn-success">
                                    <i class="bi bi-trophy"></i> View Winner
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function copyToClipboard(id) {
    var copyText = document.getElementById(id);
    var textArea = document.createElement("textarea");
    textArea.value = copyText.textContent;
    document.body.appendChild(textArea);
    textArea.select();
    document.execCommand("Copy");
    textArea.remove();
    
    // Show feedback
    var button = event.target.closest('.copy-btn');
    var originalHTML = button.innerHTML;
    button.innerHTML = '<i class="bi bi-check"></i>';
    button.style.background = '#28a745';
    
    setTimeout(function() {
        button.innerHTML = originalHTML;
        button.style.background = '';
    }, 1500);
}
</script>
<?php loadSecureInclude('footer.php');?>
