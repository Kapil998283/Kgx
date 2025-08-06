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

// Get match ID from URL
$match_id = isset($_GET['match_id']) ? (int)$_GET['match_id'] : 0;
if (!$match_id) {
    $_SESSION['error'] = "Invalid match ID!";
    header("Location: index.php");
    exit();
}

try {
    // Get match details
    $match_data = $supabaseClient->select('matches', '*', ['id' => $match_id]);
    if (empty($match_data)) {
        $_SESSION['error'] = "Match not found!";
        header("Location: index.php");
        exit();
    }
    $match = $match_data[0];

    // Check if match is cancelled or completed
    if ($match['status'] === 'cancelled') {
        $_SESSION['error'] = "This match has been cancelled!";
        header("Location: index.php");
        exit();
    }
    
    if ($match['status'] === 'completed') {
        $_SESSION['error'] = "This match has already been completed!";
        header("Location: index.php");
        exit();
    }

    // Get game details for the match
    $game_data = $supabaseClient->select('games', '*', ['id' => $match['game_id']]);
    if (!empty($game_data)) {
        $match['game_name'] = $game_data[0]['name'] ?? 'Unknown Game';
        $match['game_image'] = $game_data[0]['image_url'] ?? '';
    } else {
        $match['game_name'] = 'Unknown Game';
        $match['game_image'] = '';
    }
    
    // Check if user has already joined
    $already_joined_data = $supabaseClient->select('match_participants', '*', [
        'match_id' => $match_id, 
        'user_id' => $user_id
    ]);
    if (!empty($already_joined_data)) {
        $_SESSION['error'] = "You have already joined this match!";
        header("Location: index.php");
        exit();
    }

    // Check if match is full
    $participants_data = $supabaseClient->select('match_participants', '*', ['match_id' => $match_id]);
    $current_participants = count($participants_data ?? []);
    if ($current_participants >= $match['max_participants']) {
        $_SESSION['error'] = "Match is full!";
        header("Location: index.php");
        exit();
    }

    // Get user's game profile for the specific game with better error handling
    $game_profile = null;
    $game_profile_error = null;
    
    try {
        // Map database game names to game-profile.php format for matching
        $game_name_mapping = [
            'Free Fire' => 'FREE FIRE',
            'Call of Duty Mobile' => 'COD'
        ];
        
        // Use mapped name or original name
        $profile_game_name = isset($game_name_mapping[$match['game_name']]) ? 
                             $game_name_mapping[$match['game_name']] : 
                             $match['game_name'];
        
        $game_profile_data = $supabaseClient->select('user_games', '*', [
            'user_id' => $user_id, 
            'game_name' => $profile_game_name
        ]);
        
        if (!empty($game_profile_data)) {
            $game_profile = $game_profile_data[0];
            
            // Validate required game profile fields
            $missing_fields = [];
            if (empty($game_profile['game_username'])) {
                $missing_fields[] = 'In-Game Name';
            }
            if (empty($game_profile['game_uid'])) {
                $missing_fields[] = 'Game UID';
            }
            
            if (!empty($missing_fields)) {
                $game_profile_error = "Your game profile is incomplete. Missing: " . implode(', ', $missing_fields);
            }
        } else {
            $game_profile_error = "You need to set up your game profile for " . htmlspecialchars($match['game_name']) . " before joining.";
        }
    } catch (Exception $profile_ex) {
        error_log("Error fetching game profile: " . $profile_ex->getMessage());
        $game_profile_error = "Unable to verify your game profile. Please try again later.";
    }

    // If there's a game profile error, set session error to show at top
    // But only if this is a fresh page load (not after profile update)
    if ($game_profile_error && !isset($_SESSION['error']) && !isset($_GET['profile_updated'])) {
        $_SESSION['error'] = $game_profile_error;
    }
    
    // Get user's balance (initialize wallet table variables first)
    $balance = 0;
    $wallet_table = null;
    $wallet_column = null;
    
    if ($match['entry_type'] !== 'free') {
        $wallet_table = $match['entry_type'] === 'coins' ? 'user_coins' : 'user_tickets';
        $wallet_column = $match['entry_type'] === 'coins' ? 'coins' : 'tickets';
        
        try {
            $balance_data = $supabaseClient->select($wallet_table, '*', ['user_id' => $user_id]);
            $balance = !empty($balance_data) ? ($balance_data[0][$wallet_column] ?? 0) : 0;
        } catch (Exception $balance_ex) {
            error_log("Error fetching user balance: " . $balance_ex->getMessage());
            $balance = 0;
        }
    }

} catch (Exception $e) {
    error_log("Error in join.php: " . $e->getMessage());
    $_SESSION['error'] = "Error loading match details. Please try again later.";
    header("Location: index.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Re-validate game profile before joining
        if (!$game_profile || empty($game_profile['game_username']) || empty($game_profile['game_uid'])) {
            throw new Exception("Your game profile is incomplete. Please complete your profile before joining.");
        }
        
        // Check balance again to prevent race conditions
        if ($match['entry_type'] !== 'free' && $balance < $match['entry_fee']) {
            throw new Exception("Insufficient " . htmlspecialchars($match['entry_type']) . ". You need " . number_format($match['entry_fee']) . " " . $match['entry_type'] . " to join this match.");
        }
        
        // Check if user hasn't already joined (race condition check)
        $double_check = $supabaseClient->select('match_participants', '*', [
            'match_id' => $match_id,
            'user_id' => $user_id
        ]);
        if (!empty($double_check)) {
            throw new Exception("You have already joined this match!");
        }
        
        // Check if match is still available
        $current_match = $supabaseClient->select('matches', '*', ['id' => $match_id]);
        if (empty($current_match) || $current_match[0]['status'] !== 'upcoming') {
            throw new Exception("This match is no longer available for joining.");
        }

        // 1. Deduct entry fee (only if not free)
        if ($match['entry_type'] !== 'free') {
            $new_balance = $balance - $match['entry_fee'];
            $update_result = $supabaseClient->update(
                $wallet_table, 
                [$wallet_column => $new_balance], 
                ['user_id' => $user_id]
            );

            if (empty($update_result)) {
                throw new Exception("Failed to deduct entry fee. Please try again.");
            }
        }

        // 2. Add user to match participants
        $participant_data = [
            'match_id' => $match_id,
            'user_id' => $user_id,
            'join_date' => date('Y-m-d H:i:s'),
            'status' => 'joined'
        ];
        
        // Note: game_username and game_uid are stored in user_games table
        // match_participants table doesn't have these columns based on schema
        
        $insert_result = $supabaseClient->insert('match_participants', $participant_data);

        if (empty($insert_result)) {
            // If adding participant fails, refund the entry fee
            if ($match['entry_type'] !== 'free') {
                $supabaseClient->update(
                    $wallet_table, 
                    [$wallet_column => $balance], 
                    ['user_id' => $user_id]
                );
            }
            throw new Exception("Could not join the match. " . ($match['entry_type'] !== 'free' ? "Your entry fee has been refunded." : ""));
        }

        // 3. Create notification
        try {
            $notificationMessage = "You have successfully joined the {$match['game_name']} {$match['match_type']} match scheduled for " . date('M j, Y g:i A', strtotime($match['match_date'])) . ".";
            $notification_data = [
                'user_id' => $user_id,
                'type' => 'match_joined',
                'message' => $notificationMessage,
                'related_id' => $match_id,
                'related_type' => 'match',
                'created_at' => date('Y-m-d H:i:s')
            ];
            $supabaseClient->insert('notifications', $notification_data);
        } catch (Exception $notification_ex) {
            // Don't fail the join if notification fails, just log it
            error_log("Failed to create notification: " . $notification_ex->getMessage());
        }

        $_SESSION['success'] = "Successfully joined the match! Check your dashboard for match details.";
        header("Location: index.php");
        exit();

    } catch (Exception $e) {
        error_log("Error joining match (ID: $match_id, User: $user_id): " . $e->getMessage());
        $_SESSION['error'] = $e->getMessage();
        // Safe redirect back to join page with match ID
        header("Location: join.php?match_id=" . (int)$match_id);
        exit();
    }
}

// Now load header after all redirects are handled
loadSecureInclude('header.php');

?>
<!-- Link to external CSS file -->
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/matches/join.css">

<style>
/* Section header styles */
.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 2rem;
    gap: 20px;
    flex-wrap: wrap;
}

.header-left {
    display: flex;
    align-items: center;
    gap: 20px;
    flex: 1;
    justify-content: flex-start;
}

.wallet-balance {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(37, 211, 102, 0.1);
    border: 1px solid rgba(37, 211, 102, 0.3);
    border-radius: 12px;
    padding: 12px 16px;
    font-size: 0.9rem;
    white-space: nowrap;
}

.wallet-balance i {
    color: #25d366;
    font-size: 1.1em;
}

.balance-label {
    color: rgba(255, 255, 255, 0.8);
    font-weight: 500;
}

.balance-amount {
    color: #25d366;
    font-weight: 600;
    font-size: 1rem;
}

/* Mobile responsiveness */
@media (max-width: 768px) {
    .section-header {
        flex-direction: column;
        align-items: stretch;
        gap: 10px;
        margin-bottom: 5px;
    }

    .header-left {
        justify-content: space-between;
        align-items: center;
        width: 100%;
    }
    
    .wallet-balance {
        justify-content: center;
        width: 100%;
    }
    
    .section-title {
        font-size: 1.5rem;
    }
}

@media (max-width: 480px) {
    .header-left {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .wallet-balance {
        font-size: 0.85rem;
        padding: 10px 14px;
    }
    
    .balance-amount {
        font-size: 0.9rem;
    }
}

/* Add this at the end of your existing styles */
.prize-distribution {
    background-color: rgba(255, 255, 255, 0.1);
    border-radius: 8px;
    padding: 10px;
    margin-top: 10px;
}

.prize-tier {
    padding: 4px 0;
    color: #ffd700;
    font-size: 0.9em;
}

.prize-tier:nth-child(2) {
    color: #c0c0c0;
}

.prize-tier:nth-child(3) {
    color: #cd7f32;
}

.prize-tier i {
    margin-right: 5px;
}

.coins-per-kill {
    color: #4caf50;
    background-color: rgba(76, 175, 80, 0.1);
    border-radius: 8px;
    padding: 8px;
    font-size: 0.9em;
}

.coins-per-kill i {
    color: #ffd700;
    margin-right: 5px;
}

/* Add these styles for game profile section */
.game-profile-info {
    background: rgba(37, 211, 102, 0.1);
    border-radius: 12px;
    padding: 20px;
    margin-top: 20px;
    position: relative;
}

.game-profile-info h4 {
    color: #25d366;
    margin-bottom: 15px;
    font-size: 1.1em;
    display: flex;
    align-items: center;
    gap: 10px;
}

.profile-details {
    display: grid;
    gap: 15px;
}

.detail-item {
    display: flex;
    align-items: center;
    gap: 10px;
    color: #fff;
    position: relative;
    padding-right: 40px; /* Make room for edit icon */
}

.detail-item i {
    color: #25d366;
    font-size: 1.1em;
}

.detail-item span {
    font-size: 0.95em;
    flex-grow: 1;
}

.edit-icon {
    position: absolute;
    right: 0;
    color: #25d366;
    opacity: 0.8;
    transition: opacity 0.3s, transform 0.3s;
}

.edit-icon:hover {
    opacity: 1;
    transform: scale(1.1);
    color: #25d366;
}
</style>

<div class="matches-section">
    <div class="matches-container">
        <?php if (isset($_SESSION['error'])): ?>
            <div class="error-message">
                <?= htmlspecialchars($_SESSION['error']) ?>
            </div>
            <?php unset($_SESSION['error']); ?>
        <?php endif; ?>

        <div class="section-header">
            <div class="header-left">
                <h2 class="section-title">Join Match</h2>
                <a href="index.php" class="my-matches-link">
                    <i class="bi bi-arrow-left"></i> Back to Matches
                </a>
            </div>
            <div class="wallet-balance">
                <i class="bi bi-wallet2"></i>
                <span class="balance-label">Your Coins:</span>
                <span class="balance-amount"><?= number_format($balance) ?> <?= ucfirst($match['entry_type']) ?></span>
            </div>
        </div>
        
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
                            <span data-tournament-date="<?= htmlspecialchars($match['match_date']); ?>">
                                <?= date('M j, Y', strtotime($match['match_date'])) ?>
                            </span>
                        </div>
                        <div class="info-item">
                            <i class="bi bi-clock"></i>
                            <span data-tournament-datetime="<?= htmlspecialchars($match['match_date']); ?>">
                                <?= date('g:i A', strtotime($match['match_date'])) ?>
                            </span>
                    </div>
                    <div class="entry-fee">
                        <i class="bi bi-<?= $match['entry_type'] === 'coins' ? 'coin' : 'ticket' ?>"></i>
                        <?php if ($match['entry_type'] === 'free'): ?>
                            Free Entry
                        <?php else: ?>
                            Entry: <?= number_format($match['entry_fee']) ?> <?= ucfirst($match['entry_type']) ?>
                        <?php endif; ?>
                    </div>
                    <div class="prize-pool">
                        <i class="bi bi-trophy"></i>
                        Prize Pool: 
                        <?php if ($match['website_currency_type'] && $match['website_currency_amount'] > 0): ?>
                            <?= number_format($match['website_currency_amount']) ?> <?= ucfirst($match['website_currency_type']) ?>
                        <?php else: ?>
                            <?= $match['prize_type'] === 'USD' ? '$' : '₹' ?><?= number_format($match['prize_pool']) ?>
                        <?php endif; ?>

                        <?php if ($match['prize_distribution']): ?>
                            <div class="prize-distribution mt-2">
                                <?php
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
                                        $position = $index + 1;
                                        $amount = $match['website_currency_type'] 
                                            ? floor($match['website_currency_amount'] * $percentage / 100)
                                            : round($match['prize_pool'] * $percentage / 100, 2);
                                        
                                        $currency = $match['website_currency_type'] 
                                            ? ucfirst($match['website_currency_type'])
                                            : ($match['prize_type'] === 'USD' ? '$' : '₹');
                                        
                                        if ($match['website_currency_type']) {
                                            echo "<div class='prize-tier'><i class='bi bi-award'></i> {$position}st Place: " . number_format($amount) . " {$currency}</div>";
                                        } else {
                                            echo "<div class='prize-tier'><i class='bi bi-award'></i> {$position}st Place: {$currency}" . number_format($amount, 2) . "</div>";
                                        }
                                    }
                                ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($match['coins_per_kill'] > 0): ?>
                            <div class="coins-per-kill mt-2">
                                <i class="bi bi-star"></i> <?= number_format($match['coins_per_kill']) ?> Coins per Kill
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Add Game Profile Info -->
                    <div class="game-profile-info">
                        <h4>Your Game Profile</h4>
                        <div class="profile-details">
                            <div class="detail-item">
                                <i class="bi bi-person-badge"></i>
                                <span>In-Game Name: <?= htmlspecialchars($game_profile['game_username'] ?? 'Not set') ?></span>
                                <a href="../dashboard/game-profile.php?game=<?= urlencode($profile_game_name) ?>&edit=username" class="edit-icon">
                                    <i class="bi bi-pencil-square"></i>
                                </a>
                            </div>
                            <div class="detail-item">
                                <i class="bi bi-fingerprint"></i>
                                <span>Game UID: <?= htmlspecialchars($game_profile['game_uid'] ?? 'Not set') ?></span>
                                <a href="../dashboard/game-profile.php?game=<?= urlencode($profile_game_name) ?>&edit=uid" class="edit-icon">
                                    <i class="bi bi-pencil-square"></i>
                                </a>
                            </div>
                            <div class="detail-item">
                                <i class="bi bi-star-fill"></i>
                                <span>Game Level: <?= htmlspecialchars($game_profile['game_level'] ?? '1') ?></span>
                                <a href="../dashboard/game-profile.php?game=<?= urlencode($profile_game_name) ?>&edit=level" class="edit-icon">
                                    <i class="bi bi-pencil-square"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($match['entry_type'] !== 'free' && $balance < $match['entry_fee']): ?>
                    <div class="error-message">
                        <i class="bi bi-exclamation-circle"></i>
                        Insufficient <?= $match['entry_type'] ?>! You need <?= number_format($match['entry_fee']) ?> <?= $match['entry_type'] ?> to join this match.
                    </div>
                <?php else: ?>
                    <form method="POST" class="match-actions">
                        <input type="hidden" name="game_uid" value="<?= htmlspecialchars($game_profile['game_uid'] ?? '') ?>">
                        <input type="hidden" name="in_game_name" value="<?= htmlspecialchars($game_profile['game_username'] ?? '') ?>">
                        <button type="submit" class="btn-join btn-primary">
                            <i class="bi bi-plus-circle"></i>
                            <?php if ($match['entry_type'] === 'free'): ?>
                                Join Match (Free)
                            <?php else: ?>
                                Join (<?= number_format($match['entry_fee']) ?> <?= ucfirst($match['entry_type']) ?>)
                            <?php endif; ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php loadSecureInclude('footer.php');?>

<script>
// Add this to your existing JavaScript
document.addEventListener('DOMContentLoaded', function() {
    // Store the current URL to return to after editing
    const returnUrl = encodeURIComponent(window.location.href);
    
    // Update all edit links to include the return URL
    document.querySelectorAll('.edit-icon').forEach(link => {
        const currentHref = link.getAttribute('href');
        link.setAttribute('href', `${currentHref}&return=${returnUrl}`);
    });
});
</script>
