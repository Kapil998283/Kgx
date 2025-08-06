<?php

session_start();
define('SECURE_ACCESS', true);
require_once '../secure_config.php';

loadSecureConfig('supabase.php');
loadSecureInclude('SupabaseClient.php');
loadSecureInclude('auth.php');

$authManager = new AuthManager();
$supabaseClient = new SupabaseClient();

if (!$authManager->isLoggedIn()) {
    $redirect_url = BASE_URL . "register/login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']);
    header("Location: " . $redirect_url);
    exit();
}

$match_id = isset($_GET['match_id']) ? intval($_GET['match_id']) : 0;

if (!$match_id) {
    header('Location: my-matches.php');
    exit();
}

// Initialize variables
$winners = [];
$match = null;

// Fetch match details
try {
    $match_data = $supabaseClient->select('matches', '*', ['id' => $match_id], null, 1);
    if (empty($match_data)) {
        throw new Exception('Match not found!');
    }
    $match = $match_data[0];
} catch (Exception $ex) {
    error_log($ex->getMessage());
    header('Location: my-matches.php');
    exit();
}

// Get game details
try {
    $game_data = $supabaseClient->select('games', 'name, image_url', ['id' => $match['game_id']], null, 1);
    if (!empty($game_data)) {
        $match['game_name'] = $game_data[0]['name'];
        $match['game_image'] = $game_data[0]['image_url'];
    } else {
        $match['game_name'] = 'Unknown Game';
        $match['game_image'] = '';
    }
} catch (Exception $ex) {
    error_log($ex->getMessage());
    $match['game_name'] = 'Unknown Game';
    $match['game_image'] = '';
}

// Get winners - only show if match is completed and winners are selected
$winner_data = [];
error_log("Match status: " . $match['status']);
error_log("Match ID: " . $match_id);

if ($match['status'] === 'completed') {
    error_log("Match is completed, fetching winners...");
    try {
        // First, let's check all participants for this match
        $all_participants = $supabaseClient->select(
            'match_participants',
            '*',
            ['match_id' => $match_id]
        );
        error_log("All participants for match: " . json_encode($all_participants));
        
        // Now get only those with positions
        $winner_data = $supabaseClient->select(
            'match_participants',
            'user_id, team_id, position',
            ['match_id' => $match_id, 'position' => ['not.is', null]],
            'position.asc'
        );
        error_log("Winners found: " . json_encode($winner_data));
    } catch (Exception $ex) {
        error_log("Error fetching winner data: " . $ex->getMessage());
        $winner_data = [];
    }
} else {
    error_log("Match is not completed, status is: " . $match['status']);
}

// Process each winner
if (!empty($winner_data)) {
    error_log("Winner data fetched successfully: " . json_encode($winner_data));
    foreach ($winner_data as $winner) {
        try {
            // Get user info
            $user_info = $supabaseClient->select('users', 'username, profile_image', ['id' => $winner['user_id']], null, 1);
            $username = !empty($user_info) ? htmlspecialchars($user_info[0]['username']) : 'Unknown User';
            
            // Get profile image using the same logic as header.php
            $profile_image = '../assets/images/profile/profile3.png'; // Default fallback
            
            // Get admin-defined default profile image
            try {
                $default_image_data = $supabaseClient->select('profile_images', 'image_path', [
                    'is_default' => true,
                    'is_active' => true
                ], null, 1);
                
                if (!empty($default_image_data)) {
                    $profile_image = $default_image_data[0]['image_path'];
                }
            } catch (Exception $img_ex) {
                error_log("Error fetching default profile image: " . $img_ex->getMessage());
                // Keep the fallback image
            }

            // Get team name if applicable
            $team_name = null;
            if (!empty($winner['team_id'])) {
                $team_info = $supabaseClient->select('teams', 'name', ['id' => $winner['team_id']], null, 1);
                $team_name = !empty($team_info) ? htmlspecialchars($team_info[0]['name']) : null;
            }

            // Get kills
            $kills_info = $supabaseClient->select('user_kills', 'kills', ['user_id' => $winner['user_id'], 'match_id' => $match_id], null, 1);
            $kills = !empty($kills_info) ? intval($kills_info[0]['kills']) : 0;
            $kill_coins = $kills * (intval($match['coins_per_kill'] ?? 0));

            // Calculate position-based prize
            $position_prize = 0;
            if ((intval($match['website_currency_amount'] ?? 0)) > 0) {
                $total_prize = intval($match['website_currency_amount']);
                $distribution_percentages = [];
                
                switch($match['prize_distribution'] ?? 'single') {
                    case 'top3':
                        $distribution_percentages = [60, 30, 10];
                        break;
                    case 'top5':
                        $distribution_percentages = [50, 25, 15, 7, 3];
                        break;
                    default: // 'single'
                        $distribution_percentages = [100];
                        break;
                }
                
                if (intval($winner['position']) <= count($distribution_percentages)) {
                    $position_prize = floor($total_prize * $distribution_percentages[intval($winner['position']) - 1] / 100);
                }
            }

            $winners[] = [
                'username' => $username,
                'team_name' => $team_name,
                'position' => intval($winner['position']),
                'kills' => $kills,
                'kill_coins' => $kill_coins,
                'position_prize' => $position_prize,
                'profile_image' => $profile_image
            ];
        } catch (Exception $winner_ex) {
            error_log("Error processing winner data: " . $winner_ex->getMessage());
            // Skip this winner if there's an error processing their data
            continue;
        }
    }
} else {
    error_log("Winner data is empty or match not completed.");
}

loadSecureInclude('header.php');

?>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/matches/view-winner.css">

<div class="winner-page">
    <div class="container">
        <div class="page-header">
            <a href="my-matches.php" class="back-link">
                <i class="bi bi-arrow-left"></i> Back to My Matches
            </a>
            <div class="match-info">
                <div class="game-info">
                    <?php if ($match['game_image']): ?>
                    <img src="<?= htmlspecialchars($match['game_image']) ?>" 
                         alt="<?= htmlspecialchars($match['game_name']) ?>" 
                         class="game-icon">
                    <?php endif; ?>
                    <div>
                        <h2><?= htmlspecialchars($match['game_name']) ?></h2>
                        <p class="match-date">
                            <i class="bi bi-calendar-event"></i>
                            <?= date('F j, Y g:i A', strtotime($match['match_date'])) ?>
                        </p>
                        <p class="match-status">
                            <i class="bi bi-circle-fill status-<?= strtolower($match['status']) ?>"></i>
                            <?= htmlspecialchars(ucfirst($match['status'])) ?>
                        </p>
                    </div>
                </div>
                <div class="prize-pool">
                    <div class="prize-main">
                        <i class="bi bi-trophy-fill"></i>
                        <?php if ($match['website_currency_type'] && $match['website_currency_amount'] > 0): ?>
                            <span>Prize Pool: <?= number_format($match['website_currency_amount']) ?> <?= ucfirst($match['website_currency_type']) ?></span>
                        <?php else: ?>
                            <span>Prize Pool: <?= $match['prize_type'] === 'USD' ? '$' : '₹' ?><?= number_format($match['prize_pool']) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($match['coins_per_kill'] > 0): ?>
                    <div class="prize-kill">
                        <i class="bi bi-star-fill"></i>
                        <small>+<?= number_format($match['coins_per_kill']) ?> <?= ucfirst($match['website_currency_type'] ?? 'Coins') ?> per Kill</small>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!empty($winners)): ?>
            <?php 
            // Add conditional CSS class for single winner to center align
            $podiumClass = count($winners) === 1 ? 'winners-podium single-winner' : 'winners-podium';
            ?>
            <div class="<?= $podiumClass ?>">
                <?php
                $podiumOrder = [1, 2, 3];
                $displayedPositions = [];

                foreach ($podiumOrder as $position):
                    $winner = array_filter($winners, fn($w) => $w['position'] == $position);
                    $winner = reset($winner);
                    if ($winner):
                        $displayedPositions[] = $position;
                        
                        // Calculate real money prize based on prize distribution
                        $real_money_prize = 0;
                        if ($match['prize_pool'] > 0) {
                            $total_prize = $match['prize_pool'];
                            $distribution_percentages = [];
                            
                            switch($match['prize_distribution'] ?? 'single') {
                                case 'top3':
                                    $distribution_percentages = [60, 30, 10];
                                    break;
                                case 'top5':
                                    $distribution_percentages = [50, 25, 15, 7, 3];
                                    break;
                                default: // 'single'
                                    $distribution_percentages = [100];
                                    break;
                            }
                            
                            if ($position <= count($distribution_percentages)) {
                                $real_money_prize = round($total_prize * $distribution_percentages[$position - 1] / 100, 2);
                            }
                        }
                ?>
                    <div class="podium-spot position-<?= $position ?>" data-position="<?= $position ?>">
                        <div class="winner-avatar">
                            <div class="crown <?= $position === 1 ? 'show' : '' ?>">👑</div>
                            <img src="<?= htmlspecialchars($winner['profile_image']); ?>" alt="<?= htmlspecialchars($winner['username']); ?>" class="profile-image">
                        </div>
                        <div class="winner-details">
                            <div class="position-badge">
                                <?= $position ?><sup><?= $position === 1 ? 'st' : ($position === 2 ? 'nd' : 'rd') ?></sup>
                            </div>
                            <h3><?= htmlspecialchars($winner['username']) ?></h3>
                            <?php if ($winner['team_name']): ?>
                                <p class="team-name"><i class="bi bi-people-fill"></i> <?= htmlspecialchars($winner['team_name']) ?></p>
                            <?php endif; ?>
                            <div class="stats">
                                <div class="stat-item">
                                    <i class="bi bi-star-fill"></i>
                                    <span><?= $winner['kills'] ?> Kills</span>
                                </div>
                                <div class="stat-item">
                                    <i class="bi bi-coin"></i>
                                    <span><?= number_format($winner['kill_coins']) ?> Coins</span>
                                </div>
                                <?php if ($winner['position_prize'] > 0): ?>
                                <div class="stat-item">
                                    <i class="bi bi-trophy-fill"></i>
                                    <?php if ($match['website_currency_type'] && $match['website_currency_amount'] > 0): ?>
                                        <span><?= number_format($winner['position_prize']) ?> <?= ucfirst($match['website_currency_type']) ?></span>
                                    <?php else: ?>
                                        <span><?= $match['prize_type'] === 'USD' ? '$' : '₹' ?><?= number_format($winner['position_prize']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($real_money_prize > 0): ?>
                                <div class="stat-item">
                                    <i class="bi bi-cash-stack"></i>
                                    <span><?= $match['prize_type'] === 'USD' ? '$' : '₹' ?><?= number_format($real_money_prize, 2) ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php
                    endif;
                endforeach;
            
            if (count($winners) > 3):
                ?>
                    <div class="additional-winners">
                        <h3>Additional Winners</h3>
                        <div class="winners-grid">
                            <?php foreach ($winners as $winner):
                                if (!in_array($winner['position'], $displayedPositions)):
                            ?>
                                <div class="winner-card">
                                    <div class="position-badge">
                                        <?= $winner['position'] ?><sup>th</sup>
                                    </div>
                                    <h4><?= htmlspecialchars($winner['username']) ?></h4>
                                    <?php if ($winner['team_name']): ?>
                                        <p class="team-name"><i class="bi bi-people-fill"></i> <?= htmlspecialchars($winner['team_name']) ?></p>
                                    <?php endif; ?>
                                    <div class="stats">
                                        <div class="stat-item">
                                            <i class="bi bi-star-fill"></i>
                                            <span><?= $winner['kills'] ?> Kills</span>
                                        </div>
                                        <div class="stat-item">
                                            <i class="bi bi-coin"></i>
                                            <span><?= number_format($winner['kill_coins']) ?> Coins</span>
                                        </div>
                                        <?php if ($winner['position_prize'] > 0): ?>
                                        <div class="stat-item">
                                            <i class="bi bi-trophy-fill"></i>
                                            <?php if ($match['website_currency_type'] && $match['website_currency_amount'] > 0): ?>
                                                <span><?= number_format($winner['position_prize']) ?> <?= ucfirst($match['website_currency_type']) ?></span>
                                            <?php else: ?>
                                                <span><?= $match['prize_type'] === 'USD' ? '$' : '₹' ?><?= number_format($winner['position_prize']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php
                                endif;
                            endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="no-winner">
                <?php if ($match['status'] === 'completed'): ?>
                    <i class="bi bi-emoji-frown"></i>
                    <p>No winners declared yet</p>
                <?php else: ?>
                    <i class="bi bi-clock-fill"></i>
                    <p>Match is still <?= strtolower($match['status']) ?>. Winners will be displayed once the match is completed.</p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php loadSecureInclude('footer.php'); ?>
