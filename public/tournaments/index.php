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

// Get active tab from URL parameter
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'all';

// Fetch all tournaments first (we'll filter in PHP for better control)
$filters = [];

try {
    // Fetch tournaments using Supabase
    $all_tournaments = $supabaseClient->select('tournaments', '*', $filters, 'playing_start_date.asc');
    
    // Sort tournaments by priority (in_progress first, then registration_open, etc.)
    $tournaments = $all_tournaments ?: [];
    
    // Filter out cancelled and draft tournaments from the display
    $tournaments = array_filter($tournaments, function($tournament) {
        return !in_array($tournament['status'], ['cancelled', 'draft']);
    });
    
    // Apply tab-specific filtering
    switch ($active_tab) {
        case 'playing':
            $tournaments = array_filter($tournaments, function($tournament) {
                return $tournament['status'] === 'in_progress';
            });
            break;
        case 'upcoming':
            // Include announced, registration_open, and registration_closed tournaments
            $tournaments = array_filter($tournaments, function($tournament) {
                return in_array($tournament['status'], ['announced', 'registration_open', 'registration_closed']);
            });
            break;
        case 'finished':
            // Include completed and archived tournaments
            $tournaments = array_filter($tournaments, function($tournament) {
                return in_array($tournament['status'], ['completed', 'archived']);
            });
            break;
        default: // 'all'
            // Show all tournaments (already filtered out cancelled and draft above)
            break;
    }
    
    usort($tournaments, function($a, $b) {
        $priority_order = [
            'in_progress' => 1,
            'registration_open' => 2, 
            'registration_closed' => 3,
            'announced' => 4,
            'completed' => 5,
            'archived' => 6,
            'cancelled' => 7
        ];
        
        $a_priority = $priority_order[$a['status']] ?? 7;
        $b_priority = $priority_order[$b['status']] ?? 7;
        
        if ($a_priority !== $b_priority) {
            return $a_priority - $b_priority;
        }
        
        return strtotime($a['playing_start_date']) - strtotime($b['playing_start_date']);
    });
    
} catch (Exception $e) {
    error_log("Error fetching tournaments: " . $e->getMessage());
    $tournaments = [];
}

// Tournament status helper functions with consistent date handling
function getTournamentDisplayStatus($tournament) {
    $status_info = ['class' => '', 'icon' => '', 'status' => '', 'date_label' => '', 'date_value' => ''];
    
    switch ($tournament['status']) {
        case 'announced':
            $status_info = ['class' => 'upcoming', 'icon' => 'calendar-outline', 'status' => 'Upcoming', 
                           'date_label' => 'Registration Opens', 'date_value' => formatTournamentDate($tournament['registration_open_date'])];
            break;
        case 'registration_open':
            $status_info = ['class' => 'registration-open', 'icon' => 'checkmark-circle-outline', 'status' => 'Registration Open',
                           'date_label' => 'Registration Closes', 'date_value' => formatTournamentDate($tournament['registration_close_date'])];
            break;
        case 'registration_closed':
            $status_info = ['class' => 'registration-closed', 'icon' => 'lock-closed-outline', 'status' => 'Registration Closed',
                           'date_label' => 'Tournament Starts', 'date_value' => formatTournamentDate($tournament['playing_start_date'])];
            break;
        case 'in_progress':
            $status_info = ['class' => 'playing', 'icon' => 'play-circle-outline', 'status' => 'Playing',
                           'date_label' => 'Tournament Ends', 'date_value' => formatTournamentDate($tournament['finish_date'])];
            break;
        case 'completed':
            $status_info = ['class' => 'completed', 'icon' => 'trophy-outline', 'status' => 'Completed',
                           'date_label' => 'Finished', 'date_value' => formatTournamentDate($tournament['finish_date'])];
            break;
        case 'cancelled':
            $status_info = ['class' => 'cancelled', 'icon' => 'close-circle-outline', 'status' => 'Cancelled',
                           'date_label' => 'Cancelled', 'date_value' => formatTournamentDate(date('Y-m-d'))];
            break;
        case 'archived':
            $status_info = ['class' => 'archived', 'icon' => 'archive-outline', 'status' => 'Archived',
                           'date_label' => 'Archived', 'date_value' => formatTournamentDate(date('Y-m-d'))];
            break;
    }
    
    return $status_info;
}

// Consistent date formatting for tournaments
function formatTournamentDate($date) {
    if (empty($date)) {
        return null;
    }
    
    try {
        if ($date instanceof DateTime) {
            return $date->format('M d, Y');
        }
        
        $datetime = new DateTime($date);
        return $datetime->format('M d, Y');
    } catch (Exception $e) {
        error_log("Error formatting tournament date: " . $e->getMessage());
        return date('M d, Y', strtotime($date));
    }
}

function isTournamentRegistrationOpen($tournament) {
    return $tournament['status'] === 'registration_open';
}

?>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/tournament/index.css">

<section class="tournaments-section">
    <div class="container">
        <h1 class="tournaments-title">Tournaments</h1>
        
        <div class="tournament-tabs">
            <div class="tabs-group">
                <a href="?tab=all" class="tab-btn <?php echo $active_tab === 'all' ? 'active' : ''; ?>">All</a>
                <a href="?tab=upcoming" class="tab-btn <?php echo $active_tab === 'upcoming' ? 'active' : ''; ?>">Upcoming</a>
                <a href="?tab=playing" class="tab-btn <?php echo $active_tab === 'playing' ? 'active' : ''; ?>">Playing</a>
                <a href="?tab=finished" class="tab-btn <?php echo $active_tab === 'finished' ? 'active' : ''; ?>">Finished</a>
            </div>
            
            <?php if ($authManager->isLoggedIn()): ?>
                <a href="my-registrations.php" class="register-btn">
                    <ion-icon name="trophy-outline"></ion-icon>
                    Registered
                </a>
            <?php endif; ?>
        </div>
        
        <div class="tournaments-grid">
            <?php if (empty($tournaments)): ?>
                <div class="no-tournaments">
                    <ion-icon name="calendar-outline" class="large-icon"></ion-icon>
                    <h3>No Tournaments Found</h3>
                    <p>Check back later for upcoming tournaments!</p>
                </div>
            <?php else: ?>
                <?php foreach ($tournaments as $tournament): ?>
                    <div class="tournament-card">
                        <div class="card-banner">
                            <img src="<?php echo htmlspecialchars($tournament['banner_image']); ?>" 
                                 alt="<?php echo htmlspecialchars($tournament['name']); ?>" 
                                 class="tournament-banner">
                            
                            <?php 
                                $status_info = getTournamentDisplayStatus($tournament);
                                echo '<div class="tournament-status ' . $status_info['class'] . '">';
                                echo '<ion-icon name="' . $status_info['icon'] . '"></ion-icon>';
                                echo $status_info['status'];
                                echo '</div>';

                                if ($status_info['date_label'] && $status_info['date_value']) {
                                    echo '<div class="date-info">';
                                    echo '<small>' . $status_info['date_label'] . ': ' . $status_info['date_value'] . '</small>';
                                    echo '</div>';
                                }
                            ?>
                        </div>

                        <div class="card-content">
                            <h2 class="tournament-name"><?php echo htmlspecialchars($tournament['name']); ?></h2>
                            <h3 class="game-name"><?php echo htmlspecialchars($tournament['game_name']); ?></h3>
                            
                            <div class="tournament-meta">
                                <div class="meta-item prize-pool">
                                    <ion-icon name="trophy-outline"></ion-icon>
                                    <?php 
                                        if (!empty($tournament['website_currency_type']) && $tournament['website_currency_amount'] > 0) {
                                            echo number_format($tournament['website_currency_amount']) . ' ' . ucfirst($tournament['website_currency_type']);
                                        } else {
                                            echo $tournament['prize_currency'] === 'USD' ? '$' : '₹';
                                            echo number_format($tournament['prize_pool']); 
                                        }
                                    ?>
                                </div>
                                <div class="meta-item entry-fee">
                                    <ion-icon name="ticket-outline"></ion-icon>
                                    <?php echo $tournament['entry_fee']; ?> Tickets
                                </div>
                                <div class="meta-item start-date">
                                    <ion-icon name="calendar-outline"></ion-icon>
                                    <span data-tournament-date="<?php echo htmlspecialchars($tournament['playing_start_date']); ?>">
                                        <?php echo formatTournamentDate($tournament['playing_start_date']); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="tournament-info">
                                <div class="team-count">
                                    <ion-icon name="people-outline"></ion-icon>
                                    <?php echo $tournament['current_teams']; ?>/<?php echo $tournament['max_teams']; ?> <?php echo ($tournament['mode'] === 'Solo') ? 'Players' : 'Teams'; ?>
                                </div>
                                
                                <a href="details.php?id=<?php echo $tournament['id']; ?>" class="details-btn">
                                    <?php if (isTournamentRegistrationOpen($tournament)): ?>
                                        <ion-icon name="arrow-forward-outline"></ion-icon>
                                    <?php else: ?>
                                        <ion-icon name="arrow-forward-outline"></ion-icon>
                                    <?php endif; ?>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php loadSecureInclude('footer.php'); ?>
