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
loadSecureInclude('header.php');

// Initialize AuthManager and SupabaseClient
$authManager = new AuthManager();
$supabaseClient = new SupabaseClient();

// Get game type from URL parameter and sanitize it
$game_filter = isset($_GET['game']) ? filter_var($_GET['game'], FILTER_SANITIZE_FULL_SPECIAL_CHARS) : '';
$game = $game_filter; // for compatibility with existing template code that uses $game

// Get current user's wallet balance if logged in
$user_balance = 0;
$user_tickets = 0;
$currentUser = null;
if ($authManager->isLoggedIn()) {
    $currentUser = $authManager->getCurrentUser();
    $user_id = $currentUser['user_id'];

    try {
        // Get coins balance
        $coins_data = $supabaseClient->select('user_coins', '*', [
            'user_id' => $user_id
        ]);
        $user_balance = !empty($coins_data) ? $coins_data[0]['coins'] : 0;

        // Get tickets balance
        $tickets_data = $supabaseClient->select('user_tickets', '*', [
            'user_id' => $user_id
        ]);
        $user_tickets = !empty($tickets_data) ? $tickets_data[0]['tickets'] : 0;
    } catch (Exception $e) {
        error_log("Error fetching user balance: " . $e->getMessage());
    }
}

// Fetch all active games for the filter
try {
    $games_data = $supabaseClient->select('games', '*', [
        'status' => 'active'
    ]);
    
    $games = $games_data ?: [];

    // If no games are found, let's insert them (based on schema constraints)
    if (empty($games)) {
        $default_games = [
            ['name' => 'BGMI', 'image_url' => '../assets/images/games/bgmi.png', 'status' => 'active', 'description' => 'Battlegrounds Mobile India'],
            ['name' => 'PUBG', 'image_url' => '../assets/images/games/pubg.png', 'status' => 'active', 'description' => 'PlayerUnknown\'s Battlegrounds'],
            ['name' => 'FREE FIRE', 'image_url' => '../assets/images/games/freefire.png', 'status' => 'active', 'description' => 'Garena Free Fire'],
            ['name' => 'COD', 'image_url' => '../assets/images/games/cod.png', 'status' => 'active', 'description' => 'Call of Duty Mobile']
        ];
        
        foreach ($default_games as $game) {
            try {
                $supabaseClient->insert('games', $game);
            } catch (Exception $insertEx) {
                error_log("Error inserting game {$game['name']}: " . $insertEx->getMessage());
            }
        }
        
        // Fetch games again
        $games_data = $supabaseClient->select('games', '*', [
            'status' => 'active'
        ], 'name');
        $games = $games_data ?: [];
    }
    
    // Order games as per requirement (matching actual database values)
    $order = ['BGMI', 'PUBG', 'Free Fire', 'Call of Duty Mobile'];
    usort($games, function($a, $b) use ($order) {
        $pos_a = array_search($a['name'], $order);
        $pos_b = array_search($b['name'], $order);
        if ($pos_a === false) return 1;
        if ($pos_b === false) return -1;
        return $pos_a - $pos_b;
    });

} catch (Exception $e) {
    error_log("Error fetching/inserting games: " . $e->getMessage());
    $games = [];
}

// Fetch matches using Supabase
try {
    // First, get all non-cancelled matches
    $matches = $supabaseClient->select('matches', '*', [
        'status' => ['neq', 'cancelled']
    ], 'match_date');
    
    if ($matches === null) $matches = [];
    
    // If game filter is specified, apply it manually
    if (!empty($game_filter) && !empty($matches)) {
        // Find the game ID for the filter
        $game_id = null;
        foreach ($games as $game) {
            if (strtoupper($game['name']) === strtoupper($game_filter)) {
                $game_id = $game['id'];
                break;
            }
        }
        
        // Filter matches by game ID if found
        if ($game_id) {
            $matches = array_filter($matches, function($match) use ($game_id) {
                return $match['game_id'] == $game_id;
            });
        } else {
            $matches = []; // No matches if game not found
        }
    }

    // Enrich matches with related data
    foreach ($matches as &$match) {
        // Get game details - Use manual lookup from games cache
        if (!empty($match['game_id'])) {
            $game_found = false;
            foreach ($games as $game) {
                if ($game['id'] == $match['game_id']) {
                    $match['game_name'] = $game['name'];
                    $match['game_image'] = $game['image_url'];
                    $game_found = true;
                    break;
                }
            }
            
            if (!$game_found) {
                $match['game_name'] = 'Unknown Game';
                $match['game_image'] = '';
            }
        } else {
            $match['game_name'] = 'Unknown Game';
            $match['game_image'] = '';
        }

        // Get tournament name
        if (!empty($match['tournament_id'])) {
            try {
                $tournament_info = $supabaseClient->select('tournaments', '*', [
                    'id' => $match['tournament_id']
                ]);
                $match['tournament_name'] = $tournament_info[0]['name'] ?? null;
            } catch (Exception $e) {
                $match['tournament_name'] = null;
            }
        } else {
            $match['tournament_name'] = null;
        }

        // Get participant count
        try {
            $participants = $supabaseClient->select('match_participants', '*', [
                'match_id' => $match['id']
            ]);
            $match['current_participants'] = count($participants ?? []);
        } catch (Exception $e) {
            $match['current_participants'] = 0;
        }

        // Determine match status
        if ($match['status'] === 'completed') {
            $match['match_status'] = 'Completed';
        } elseif ($match['status'] === 'in_progress') {
            $match['match_status'] = 'In Progress';
        } elseif (strtotime($match['match_date']) < time()) {
            $match['match_status'] = 'Started';
        } else {
            $match['match_status'] = 'Upcoming';
        }

        // Check if current user has joined
        $match['has_joined'] = 0;
        if ($currentUser) {
            try {
                $user_joined = $supabaseClient->select('match_participants', '*', [
                    'match_id' => $match['id'],
                    'user_id' => $currentUser['user_id']
                ]);
                if (!empty($user_joined)) {
                    $match['has_joined'] = 1;
                }
            } catch (Exception $e) {
                $match['has_joined'] = 0;
            }
        }
        
        // Ensure proper date and time handling for consistency with admin side
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
    }
    unset($match); 
    
    // Sort matches by status and date - prioritize "In Progress" (playing) matches
    usort($matches, function ($a, $b) {
        $status_order = ['In Progress' => 1, 'Started' => 1, 'Upcoming' => 2, 'Completed' => 3];
        $a_status_val = $status_order[$a['match_status']] ?? 4;
        $b_status_val = $status_order[$b['match_status']] ?? 4;
        
        if ($a_status_val !== $b_status_val) {
            return $a_status_val - $b_status_val;
        }
        
        return strtotime($a['match_date']) - strtotime($b['match_date']);
    });

} catch (Exception $e) {
    error_log("Error fetching matches: " . $e->getMessage());
    $matches = [];
}
?>

<!-- Link to external CSS file -->
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/matches/index.css">

<article class="matches">
    <div class="section-header">
        <h2 class="section-title">Available Matches</h2>
        <?php if ($authManager->isLoggedIn()): ?>
            <a href="my-matches.php" class="my-matches-link">
                <i class="bi bi-trophy"></i> My Matches
            </a>
        <?php endif; ?>
    </div>

    <!-- Games Filter -->
    <div class="games-filter">
        <a href="?game=" class="game-filter-btn <?= empty($game_filter) ? 'active' : '' ?>">
            <i class="bi bi-grid-3x3-gap"></i> All
        </a>
        <?php
        // Ensure $game is a string, not an array
        $current_game_filter = is_string($game) ? $game : '';
        
        // Display name mapping for shorter tab names
        $display_names = [
            'Call of Duty Mobile' => 'COD',
            'Free Fire' => 'FREE FIRE'
        ];
        
        // Manually create game filter tabs based on the fetched games
        if (!empty($games) && is_array($games)) {
            foreach ($games as $game_item) {
                if (isset($game_item['name']) && !empty($game_item['name'])) {
                    $game_name = $game_item['name'];
                    $display_name = isset($display_names[$game_name]) ? $display_names[$game_name] : $game_name;
                    // Fix active state comparison using game_filter instead of current_game_filter
                    $is_active = (strtoupper($game_filter) === strtoupper($game_item['name'])) ? 'active' : '';
                    echo '<a href="?game=' . urlencode($game_item['name']) . '" class="game-filter-btn ' . $is_active . '">';
                    echo '<i class="bi bi-controller"></i> ' . htmlspecialchars($display_name);
                    echo '</a>';
                }
            }
        } else {
            // Fallback: create hardcoded tabs if games array is not working
            $hardcoded_games = [
                'BGMI' => 'BGMI',
                'PUBG' => 'PUBG', 
                'Free Fire' => 'FREE FIRE',
                'Call of Duty Mobile' => 'COD'
            ];
            foreach ($hardcoded_games as $game_name => $display_name) {
                // Fix active state comparison using game_filter instead of current_game_filter
                $is_active = (strtoupper($game_filter) === strtoupper($game_name)) ? 'active' : '';
                echo '<a href="?game=' . urlencode($game_name) . '" class="game-filter-btn ' . $is_active . '">';
                echo '<i class="bi bi-controller"></i> ' . htmlspecialchars($display_name);
                echo '</a>';
            }
        }
        ?>
    </div>

    <!-- Matches Grid -->
    <div class="matches-grid">
        <?php if (empty($matches)): ?>
        <div class="no-matches">
            <i class="bi bi-controller"></i>
            <p>No matches available at the moment.</p>
            <a href="?game=" class="btn-join btn-primary">View All Games</a>
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
                            <?php if ($match['tournament_name']): ?>
                            <div class="tournament-name">
                                <i class="bi bi-trophy"></i> <?= htmlspecialchars($match['tournament_name']) ?>
                            </div>
                            <?php endif; ?>
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
                                <?= date('M j, Y', strtotime($match['match_date'])) ?></span>
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
                        <span class="status-badge status-<?= strtolower($match['match_status']) ?>">
                            <i class="bi bi-circle-fill"></i>
                            <?= $match['match_status'] ?>
                        </span>
                    </div>
                </div>

                <div class="match-actions">
                    <?php
                    // Determine user state
                    $is_logged_in = $authManager->isLoggedIn();
                    $user_joined = $match['has_joined'];
                    $match_status = $match['match_status'];
                    $match_id = $match['id'];
                    
                    // Check if user can join (for upcoming matches)
                    $can_join = true;
                    $join_error = '';
                    
                    if ($match_status === 'Upcoming' && $is_logged_in && !$user_joined) {
                        if ($match['current_participants'] >= $match['max_participants']) {
                            $can_join = false;
                            $join_error = 'Full';
                        } elseif ($match['entry_type'] === 'coins' && $user_balance < $match['entry_fee']) {
                            $can_join = false;
                            $join_error = 'No Coins';
                        } elseif ($match['entry_type'] === 'tickets' && $user_tickets < $match['entry_fee']) {
                            $can_join = false;
                            $join_error = 'No Tickets';
                        }
                    }
                    
                    // Generate action buttons based on state
                    if (!$is_logged_in) {
                        // Not logged in - show login or view options
                        if ($match_status === 'Upcoming') {
                            echo '<a href="../register/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']) . '" class="match-btn btn-login">';
                            echo '<i class="bi bi-box-arrow-in-right"></i><span>Login to Join</span></a>';
                        } else {
                            echo '<a href="view-participants.php?match_id=' . $match_id . '" class="match-btn btn-players">';
                            echo '<i class="bi bi-people"></i><span>Players</span></a>';
                            echo '<a href="view-winner.php?match_id=' . $match_id . '" class="match-btn btn-winner">';
                            echo '<i class="bi bi-trophy"></i><span>Winner</span></a>';
                        }
                    } else {
                        // User is logged in
                        switch ($match_status) {
                            case 'Upcoming':
                                if ($user_joined) {
                                    // Already joined
                                    echo '<button class="match-btn btn-joined" disabled>';
                                    echo '<i class="bi bi-check-circle"></i><span>Joined</span></button>';
                                } else {
                                    // Can join or show error
                                    if ($can_join) {
                                        echo '<a href="join.php?match_id=' . $match_id . '" class="match-btn btn-join">';
                                        echo '<i class="bi bi-plus-circle"></i><span>Join</span></a>';
                                    } else {
                                        echo '<button class="match-btn btn-disabled" disabled>';
                                        echo '<i class="bi bi-x-circle"></i><span>' . $join_error . '</span></button>';
                                    }
                                }
                                // Always show players button for upcoming matches
                                echo '<a href="view-participants.php?match_id=' . $match_id . '" class="match-btn btn-players">';
                                echo '<i class="bi bi-people"></i><span>Players</span></a>';
                                break;
                                
                            case 'In Progress':
                            case 'Started':
                                if ($user_joined) {
                                    // User can upload results and view players
                                    echo '<a href="upload.php?match_id=' . $match_id . '" class="match-btn btn-upload">';
                                    echo '<i class="bi bi-cloud-upload"></i><span>Upload</span></a>';
                                    echo '<a href="view-participants.php?match_id=' . $match_id . '" class="match-btn btn-players">';
                                    echo '<i class="bi bi-people"></i><span>Players</span></a>';
                                } else {
                                    // Non-participants can view participants and winner
                                    echo '<a href="view-participants.php?match_id=' . $match_id . '" class="match-btn btn-players">';
                                    echo '<i class="bi bi-people"></i><span>Players</span></a>';
                                    echo '<a href="view-winner.php?match_id=' . $match_id . '" class="match-btn btn-winner">';
                                    echo '<i class="bi bi-trophy"></i><span>Winner</span></a>';
                                }
                                break;
                                
                            case 'Completed':
                            default:
                                // Match is completed - only show Players and Winner
                                echo '<a href="view-participants.php?match_id=' . $match_id . '" class="match-btn btn-players">';
                                echo '<i class="bi bi-people"></i><span>Players</span></a>';
                                echo '<a href="view-winner.php?match_id=' . $match_id . '" class="match-btn btn-winner">';
                                echo '<i class="bi bi-trophy"></i><span>Winner</span></a>';
                                break;
                        }
                    }
                    ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</article>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Handle success message fadeout
    const successMessage = document.querySelector('.success-message');
    if (successMessage) {
        setTimeout(() => {
            successMessage.style.opacity = '0';
            setTimeout(() => successMessage.remove(), 300);
        }, 3000);
    }
});
</script>


<?php loadSecureInclude('footer.php'); ?>
