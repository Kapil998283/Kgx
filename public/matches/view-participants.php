<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
define('SECURE_ACCESS', true);
require_once '../secure_config.php';

// Load secure configurations and includes
loadSecureConfig('supabase.php');
loadSecureInclude('SupabaseClient.php');
loadSecureInclude('auth.php');

// Initialize AuthManager and SupabaseClient
$authManager = new AuthManager();
$supabaseClient = new SupabaseClient();

// Check if user is logged in (optional for viewing participants)
if (!$authManager->isLoggedIn()) {
    $redirect_url = BASE_URL . "register/login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']);
    header("Location: " . $redirect_url);
    exit();
}

// Get match ID from URL
$match_id = isset($_GET['match_id']) ? intval($_GET['match_id']) : 0;

if (!$match_id) {
    header('Location: my-matches.php');
    exit();
}

try {
    // Get match details using Supabase
    $match_data = $supabaseClient->select('matches', '*', ['id' => $match_id], null, 1);
    if (empty($match_data)) {
        header('Location: my-matches.php');
        exit();
    }
    $match = $match_data[0];

    // Get game details for the match
    $game_data = $supabaseClient->select('games', 'name, image_url', ['id' => $match['game_id']], null, 1);
    $match['game_name'] = $game_data[0]['name'] ?? 'Unknown Game';
    $match['game_image'] = $game_data[0]['image_url'] ?? '';

    // Get participants
    $participants = [];
    $participant_data = $supabaseClient->select('match_participants', 'user_id', ['match_id' => $match_id]);
    
    if (!empty($participant_data)) {
        foreach ($participant_data as $p_data) {
            $user_id = $p_data['user_id'];
            
            // Get user details
            $user_info = $supabaseClient->select('users', 'username', ['id' => $user_id], null, 1);
            $username = $user_info[0]['username'] ?? 'Unknown User';
            
            // Get user's game profile
            $game_profile = $supabaseClient->select('user_games', 'game_username, game_uid', ['user_id' => $user_id, 'game_name' => $match['game_name']], null, 1);
            $game_username = $game_profile[0]['game_username'] ?? 'N/A';
            $game_uid = $game_profile[0]['game_uid'] ?? 'N/A';
            
            // Get kills data if available
            $kills_data = $supabaseClient->select('user_kills', 'kills', ['match_id' => $match_id, 'user_id' => $user_id], null, 1);
            $kills = $kills_data[0]['kills'] ?? 0;
            $coins_earned = $kills * ($match['coins_per_kill'] ?? 0);
            
            $participants[] = [
                'username' => $username,
                'game_username' => $game_username,
                'game_uid' => $game_uid,
                'kills' => $kills,
                'coins_earned' => $coins_earned
            ];
        }
        
        // Sort participants by kills (descending) then by username (ascending)
        usort($participants, function($a, $b) {
            if ($a['kills'] == $b['kills']) {
                return strcmp($a['username'], $b['username']);
            }
            return $b['kills'] - $a['kills'];
        });
    }

} catch (Exception $e) {
    error_log("Error fetching participants: " . $e->getMessage());
    $participants = [];
}

loadSecureInclude('header.php');


?>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/matches/view-participants.css">

<div class="participants-page">
    <div class="container">
        <div class="page-header">
            <a href="my-matches.php" class="back-link">
                <i class="bi bi-arrow-left"></i> Back to My Matches
            </a>
            <h1>Match Participants</h1>
        </div>

        <div class="match-info">
            <div class="game-info">
                <img src="<?= htmlspecialchars($match['game_image']) ?>" 
                     alt="<?= htmlspecialchars($match['game_name']) ?>" 
                     class="game-icon">
                <div>
                    <h2><?= htmlspecialchars($match['game_name']) ?></h2>
                    <p class="match-date" data-tournament-datetime="<?= htmlspecialchars($match['match_date']) ?>">
                        <?= date('F j, Y g:i A', strtotime($match['match_date'])) ?>
                    </p>
                </div>
            </div>
        </div>

        <div class="participants-table">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Player</th>
                        <th>Game UID</th>
                        <th>In-Game Name</th>
                        <th>Kills</th>
                        <th>Coins Earned</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($participants)): ?>
                        <tr>
                            <td colspan="6" class="no-data">No participants found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($participants as $index => $participant): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><?= htmlspecialchars($participant['username']) ?></td>
                                <td><?= htmlspecialchars($participant['game_uid']) ?></td>
                                <td><?= htmlspecialchars($participant['game_username']) ?></td>
                                <td><?= $participant['kills'] ?></td>
                                <td><?= $participant['coins_earned'] ?> Coins</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php loadSecureInclude('footer.php');?>