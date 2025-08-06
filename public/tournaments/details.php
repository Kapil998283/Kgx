<?php
session_start();
define('SECURE_ACCESS', true);
require_once '../secure_config.php';

loadSecureInclude('header.php');
loadSecureConfig('supabase.php');


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load secure configurations and includes
loadSecureConfig('supabase.php');
loadSecureInclude('SupabaseClient.php');
loadSecureInclude('auth.php');
loadSecureInclude('header.php');

// Initialize AuthManager and SupabaseClient
$authManager = new AuthManager();
$supabaseClient = new SupabaseClient();

// Check if tournament ID is provided
if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$tournament_id = intval($_GET['id']);
$user_id = $authManager->isLoggedIn() ? $authManager->getCurrentUser()['user_id'] : null;
$tournament = null;
$user_team_info = null;
$is_registered = false;
$user_tickets = 0;
$rounds = [];
$players = [];
$teams = [];

try {
    // Fetch tournament details
    $tournament_data = $supabaseClient->select('tournaments', '*', ['id' => $tournament_id], null, 1);
    if (empty($tournament_data)) {
        header('Location: index.php');
        exit();
    }
    $tournament = $tournament_data[0];
    
    // Check if tournament is cancelled and redirect users
    if ($tournament['status'] === 'cancelled') {
        $_SESSION['error'] = 'This tournament has been cancelled.';
        header('Location: index.php');
        exit();
    }

    // Fetch tournament days and their rounds (similar to admin structure)
    $days_data = $supabaseClient->select('tournament_days', '*, tournament_rounds(*)', ['tournament_id' => $tournament_id], 'day_number.asc');
    
    // Organize rounds by days (similar to match-schedule.php)
    $days = [];
    $rounds = []; // Keep for backward compatibility
    
    if ($days_data) {
        foreach ($days_data as $day) {
            if (!empty($day['tournament_rounds'])) {
                $day_info = [
                    'date' => $day['date'],
                    'day_number' => $day['day_number'],
                    'rounds' => []
                ];
                
                foreach ($day['tournament_rounds'] as $round) {
                    $round_info = [
                        'id' => $round['id'],
                        'round_number' => $round['round_number'],
                        'name' => $round['name'],
                        'start_time' => $round['start_time'],
                        'teams_count' => $round['teams_count'],
                        'qualifying_teams' => $round['qualifying_teams'],
                        'kill_points' => $round['kill_points'],
                        'qualification_points' => $round['qualification_points'],
                        'map_name' => $round['map_name'],
                        'placement_points' => $round['placement_points'],
                        'status' => $round['status'],
                        'room_code' => $round['room_code'],
                        'room_password' => $round['room_password']
                    ];
                    
                    $day_info['rounds'][] = $round_info;
                    $rounds[] = $round_info; // Keep for backward compatibility
                }
                
                $days[$day['day_number']] = $day_info;
            }
        }
    }
    
    // If no days structure exists, fallback to old rounds structure
    if (empty($days)) {
        $rounds = $supabaseClient->select('tournament_rounds', '*', ['tournament_id' => $tournament_id], 'round_number.asc');
    }

    // Fetch players/teams
    if ($tournament['mode'] === 'Solo') {
        $players = $supabaseClient->select('tournament_registrations', 'users(username, profile_image)', ['tournament_id' => $tournament_id, 'status' => 'approved']);
    } else {
        $teams_data = $supabaseClient->select('tournament_registrations', 'teams(*, team_members(*, users(username, profile_image)))', ['tournament_id' => $tournament_id, 'status' => 'approved']);
        if($teams_data) {
            $teams = array_map(function($i) { return $i['teams']; }, $teams_data);
        }
    }

    // Check registration status for the current user
    if ($user_id) {
        // Fetch user tickets
        $user_tickets_data = $supabaseClient->select('user_tickets', 'tickets', ['user_id' => $user_id], null, 1);
        $user_tickets = !empty($user_tickets_data) ? $user_tickets_data[0]['tickets'] : 0;
        
        $user_team_info = $supabaseClient->select('team_members', 'teams(*)', ['user_id' => $user_id, 'status' => 'active'], null, 1);
        if($user_team_info) {
            $user_team_info = $user_team_info[0]['teams'];
            $reg_data = $supabaseClient->select('tournament_registrations', 'status', ['tournament_id' => $tournament_id, 'team_id' => $user_team_info['id']], null, 1);
            if($reg_data) $user_team_info['registration_status'] = $reg_data[0]['status'];
        }

        $solo_reg_data = $supabaseClient->select('tournament_registrations', 'status', ['tournament_id' => $tournament_id, 'user_id' => $user_id], null, 1);
        $is_registered = !empty($reg_data) || !empty($solo_reg_data);
    }

} catch (Exception $e) {
    error_log("Error fetching tournament details: " . $e->getMessage());
    // Handle error appropriately
}

// Fetch default profile image
$default_profile_image = '../../assets/images/guest-icon.png';
$default_img_data = $supabaseClient->select('profile_images', 'image_path', ['is_default' => true, 'is_active' => true], null, 1);
if (!empty($default_img_data)) {
    $default_profile_image = $default_img_data[0]['image_path'];
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

// Consistent date-time formatting for tournaments
function formatTournamentDateTime($datetime) {
    if (empty($datetime)) {
        return null;
    }
    
    try {
        if ($datetime instanceof DateTime) {
            return $datetime->format('M d, g:i A');
        }
        
        $dt = new DateTime($datetime);
        return $dt->format('M d, g:i A');
    } catch (Exception $e) {
        error_log("Error formatting tournament datetime: " . $e->getMessage());
        return date('M d, g:i A', strtotime($datetime));
    }
}

function getTournamentDisplayStatus($tournament) {
    $status = $tournament['status'];
    $now = new DateTime();
    
    // Default status info
    $status_info = [
        'status' => ucfirst(str_replace('_', ' ', $status)),
        'class' => 'status-' . $status,
        'date_label' => null,
        'date_value' => null
    ];
    
    switch ($status) {
        case 'announced':
            $status_info['status'] = 'Coming Soon';
            $status_info['date_label'] = 'Registration Opens';
            $status_info['date_value'] = formatTournamentDate($tournament['registration_open_date']);
            break;
        case 'registration_open':
            $status_info['status'] = 'Registration Open';
            $status_info['date_label'] = 'Registration Closes';
            $status_info['date_value'] = formatTournamentDate($tournament['registration_close_date']);
            break;
        case 'registration_closed':
            $status_info['status'] = 'Registration Closed';
            $status_info['date_label'] = 'Tournament Starts';
            $status_info['date_value'] = formatTournamentDate($tournament['playing_start_date']);
            break;
        case 'team_full':
            $status_info['status'] = ($tournament['mode'] === 'Solo') ? 'Players Full' : 'Teams Full';
            break;
        case 'playing':
            $status_info['status'] = 'In Progress';
            $status_info['date_label'] = 'Ends On';
            $status_info['date_value'] = formatTournamentDate($tournament['finish_date']);
            break;
        case 'completed':
            $status_info['status'] = 'Completed';
            if (!empty($tournament['payment_date'])) {
                $status_info['date_label'] = 'Prize Payment';
                $status_info['date_value'] = formatTournamentDate($tournament['payment_date']);
            }
            break;
        case 'cancelled':
            $status_info['status'] = 'Cancelled';
            break;
    }
    
    return $status_info;
}

?>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/tournament/details.css">

<main>
    <article>
        <div class="tournament-container">
            <div class="back-title">
                <a href="index.php"><span>&larr;</span> <?php echo htmlspecialchars($tournament['name']); ?></a>
            </div>

            <div class="tournament-card">
                <div class="image-section">
                    <img src="<?php echo htmlspecialchars($tournament['banner_image']); ?>" alt="tournament" />
                </div>
                <div class="info-section">
                    <h2><?php echo htmlspecialchars($tournament['game_name']); ?></h2>
                    <p class="subheading"><?php 
                        if ($tournament['status'] === 'completed') {
                            echo 'Tournament ended';
                        } else {
                            // Show appropriate countdown text based on status
                            switch($tournament['status']) {
                                case 'playing':
                                    echo 'Prize payout in';
                                    break;
                                case 'registration_open':
                                case 'registration_closed':
                                    echo 'Tournament ending in';
                                    break;
                                default:
                                    echo 'Tournament ending in';
                            }
                        }
                    ?></p>
                    
                    <?php if ($tournament['status'] !== 'completed'): ?>
                    <?php 
                    // Use the payment date (final step) for countdown, fallback to finish date
                    $countdown_date = !empty($tournament['payment_date']) ? $tournament['payment_date'] : $tournament['finish_date'];
                    ?>
                    <div class="countdown-grid" data-end-date="<?php echo date('c', strtotime($countdown_date . ' 23:59:59')); ?>">
                        <div class="hex-box"><span id="days">0</span><small>Days</small></div>
                        <div class="hex-box"><span id="hours">0</span><small>Hours</small></div>
                        <div class="hex-box"><span id="minutes">0</span><small>Minutes</small></div>
                        <div class="hex-box"><span id="seconds">0</span><small>Seconds</small></div>
                    </div>
                    <?php endif; ?>

                    <div class="tournament-meta">
                        <?php if ($tournament['status'] === 'registration_open' || $tournament['status'] === 'team_full' || $tournament['status'] === 'playing'): ?>
                            <?php if (!$authManager->isLoggedIn()): ?>
                                <?php 
                                    // Create back URL for login redirect
                                    $currentUrl = $_SERVER['REQUEST_URI'];
                                    $loginUrl = '../register/login.php?redirect=' . urlencode($currentUrl);
                                ?>
                                <button class="view-more" onclick="window.location.href='<?php echo htmlspecialchars($loginUrl); ?>'">
                                    Login to Register
                                </button>
                            <?php elseif ($is_registered): ?>
                                <?php
                                // Fetch the registration status for the current user/team
                                $reg_status = null;
                                if ($tournament['mode'] === 'Solo') {
                                    $reg_data = $supabaseClient->select('tournament_registrations', 'status', ['tournament_id' => $tournament['id'], 'user_id' => $user_id], null, 1);
                                    $reg_status = !empty($reg_data) ? $reg_data[0]['status'] : null;
                                } else if ($user_team_info) {
                                    $reg_data = $supabaseClient->select('tournament_registrations', 'status', ['tournament_id' => $tournament['id'], 'team_id' => $user_team_info['id']], null, 1);
                                    $reg_status = !empty($reg_data) ? $reg_data[0]['status'] : null;
                                }
                                ?>
                                <?php if ($reg_status === 'pending'): ?>
                                    <button class="view-more" disabled>
                                        Wait for Approval
                                    </button>
                                <?php else: ?>
                                    <button class="view-more" disabled>
                                        Already Registered
                                    </button>
                                <?php endif; ?>
                            <?php elseif ($tournament['mode'] === 'Solo'): ?>
                                <?php if ($tournament['status'] === 'team_full'): ?>
                                    <button class="view-more" disabled>
                                        <?php echo ($tournament['mode'] === 'Solo') ? 'Players Full' : 'Teams Full'; ?>
                                    </button>
                                <?php else: ?>
                                    <?php if ($user_tickets < $tournament['entry_fee']): ?>
                                        <button class="view-more" disabled>
                                            Insufficient Tickets (Need <?php echo $tournament['entry_fee']; ?>, Have <?php echo $user_tickets; ?>)
                                        </button>
                                    <?php else: ?>
                                        <button class="view-more" onclick="window.location.href='register.php?id=<?php echo $tournament['id']; ?>'"> 
                                            Register Now
                                        </button>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php elseif (!$user_team_info): ?>
                                <button class="view-more" onclick="window.location.href='../../teams/create_team.php?redirect=tournament&id=<?php echo $tournament['id']; ?>'">
                                    Create/Join Team
                                </button>
                            <?php else:
                                $is_captain = (isset($user_team_info['created_by']) && $user_team_info['created_by'] == $user_id);
                                if ($tournament['status'] === 'team_full') {
                                    $full_text = ($tournament['mode'] === 'Solo') ? 'Players Full' : 'Teams Full';
                                    echo '<button class="view-more" disabled>' . $full_text . '</button>';
                                } elseif ($is_captain) {
                                    if ($user_tickets < $tournament['entry_fee']) {
                                        echo '<button class="view-more" disabled>Insufficient Tickets (Need ' . $tournament['entry_fee'] . ', Have ' . $user_tickets . ')</button>';
                                    } else {
                                        echo '<button class="view-more" onclick="window.location.href=\'register.php?id=' . $tournament['id'] . '\'">Register Now</button>';
                                    }
                                } else {
                                    echo '<button class="view-more" disabled>Only Captain Can Register</button>';
                                }
                            endif; ?>
                            <span class="tournament-time">
                                <span data-tournament-date="<?php echo htmlspecialchars($tournament['playing_start_date']); ?>">
                                    <?php echo formatTournamentDate($tournament['playing_start_date']); ?>
                                </span>
                            </span>
                            <span class="players-count">👥 <?php echo $tournament['current_teams']; ?>/<?php echo $tournament['max_teams']; ?> <?php echo ($tournament['mode'] === 'Solo') ? 'Players' : 'Teams'; ?></span>
                        <?php else: ?>
                            <?php
                                $status_info = getTournamentDisplayStatus($tournament);
                                $status_text = $status_info['status'];
                                if ($tournament['status'] === 'announced') {
                                    $status_text .= ' - Registration opens ' . formatTournamentDate($tournament['registration_open_date']);
                                }
                            ?>
                            <button class="view-more" disabled>
                                <?php echo $status_text; ?>
                            </button>
                            <span class="tournament-time"><?php echo formatTournamentDate($tournament['playing_start_date']); ?></span>
                            <span class="players-count">👥 <?php echo $tournament['current_teams']; ?>/<?php echo $tournament['max_teams']; ?> <?php echo ($tournament['mode'] === 'Solo') ? 'Players' : 'Teams'; ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="tournament-details">
                <div class="info-box">
                    <i class="icon">₿</i>
                    <div>
                        <p>Prize Pool</p>
                        <strong><?php 
                            echo $tournament['prize_currency'] === 'USD' ? '$' : '₹';
                            echo number_format($tournament['prize_pool'], 2); 
                        ?></strong>
                    </div>
                </div>
                <div class="info-box">
                    <i class="icon">💰</i>
                    <div>
                        <p>Entry Fee</p>
                        <strong><?php echo $tournament['entry_fee']; ?> Tickets</strong>
                    </div>
                </div>
                <div class="info-box">
                    <i class="icon">👤</i>
                    <div>
                        <p>Mode</p>
                        <strong><?php echo htmlspecialchars($tournament['mode']); ?></strong>
                    </div>
                </div>
                <div class="info-box">
                    <i class="icon">🎮</i>
                    <div>
                        <p>Format</p>
                        <strong><?php echo htmlspecialchars($tournament['format']); ?></strong>
                    </div>
                </div>
                <div class="info-box">
                    <i class="icon">🏆</i>
                    <div>
                        <p>Match Type</p>
                        <strong><?php echo htmlspecialchars($tournament['match_type']); ?></strong>
                    </div>
                </div>
            </div>

            <div class="tournament-progress">
                <?php
                $steps = [
                    ['label' => 'Registration Open', 'date' => $tournament['registration_open_date'], 'desc' => 'Register now to play in the tournament.'],
                    ['label' => 'Registration Closed', 'date' => $tournament['registration_close_date'], 'desc' => 'Creating the brackets we\'ll start soon'],
                    ['label' => 'Playing', 'date' => $tournament['playing_start_date'], 'desc' => 'Tournament matches in progress'],
                    ['label' => 'Finished', 'date' => $tournament['finish_date'], 'desc' => 'Tournament finished. Prizes are on their way.'],
                    ['label' => 'Paid', 'date' => $tournament['payment_date'], 'desc' => 'Payments sent to the winners. Congrats!']
                ];

                $now = new DateTime();
                $currentStep = 1;
                foreach ($steps as $index => $step) {
                    $stepDate = new DateTime($step['date']);
                    if ($now >= $stepDate) {
                        $currentStep = $index + 1;
                    }
                    ?>
                    <div class="progress-step <?php echo $now >= $stepDate ? 'completed' : ''; ?>">
                        <div class="step-icon"><?php echo $now >= $stepDate ? '✔' : ($index + 1); ?></div>
                        <div class="progress-step-content">
                            <h4><?php echo $step['label']; ?></h4>
                            <p><?php echo $step['desc']; ?></p>
                            <small>
                                <span data-tournament-date="<?php echo htmlspecialchars($step['date']); ?>">
                                    <?php echo formatTournamentDate($step['date']); ?>
                                </span>
                            </small>
                        </div>
                    </div>
                <?php } ?>
            </div>

            <div class="tournament-tabs">
                <button class="tab-btn active" data-tab="brackets-section">Brackets</button>
                <button class="tab-btn" data-tab="players-section"><?php echo ($tournament['mode'] === 'Solo') ? 'Players' : 'Teams'; ?></button>
                <button class="tab-btn" data-tab="winners-section">Winners</button>
                <button class="tab-btn" data-tab="rules-section">Rules</button>
            </div>

            <!-- Brackets Section -->
            <div id="brackets-section" class="tab-content active">
                <?php if (!empty($days)): ?>
                    <!-- Days/Rounds Structure -->
                    <div class="tournament-schedule">
                        <?php foreach ($days as $day_number => $day): ?>
                            <div class="day-section mb-5">
                                <h3 class="day-title">
                                    Day <?php echo $day_number; ?> - <span data-tournament-date="<?php echo htmlspecialchars($day['date']); ?>">
                                        <?php echo date('M d, Y', strtotime($day['date'])); ?>
                                    </span>
                                </h3>
                                
                                <div class="rounds-grid">
                                    <?php foreach ($day['rounds'] as $round): ?>
                                        <div class="round-card">
                                            <h4>Round <?php echo $round['round_number']; ?></h4>
                                            <p>
                                                <span data-tournament-datetime="<?php echo htmlspecialchars($round['start_time']); ?>" data-format="datetime">
                                                    <?php echo formatTournamentDateTime($round['start_time']); ?>
                                                </span>
                                            </p>
                                            <div class="round-details">
                                                <small><?php echo $round['teams_count']; ?> <?php echo ($tournament['mode'] === 'Solo') ? 'Players' : 'Teams'; ?></small>
                                                <?php if (!empty($round['map_name'])): ?>
                                                    <small>Map: <?php echo htmlspecialchars($round['map_name']); ?></small>
                                                <?php endif; ?>
                                                <?php if (!empty($round['status'])): ?>
                                                    <span class="badge badge-<?php echo $round['status']; ?>">
                                                        <?php echo ucfirst($round['status']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif (!empty($rounds)): ?>
                    <!-- Fallback: Old Rounds Structure -->
                    <div class="rounds">
                        <div class="rounds-grid">
                            <?php foreach ($rounds as $round): ?>
                                <div class="round-card">
                                    <h4>Round <?php echo $round['round_number']; ?></h4>
                                    <p>
                                        <span data-tournament-datetime="<?php echo htmlspecialchars($round['start_time']); ?>" data-format="datetime">
                                            <?php echo formatTournamentDateTime($round['start_time']); ?>
                                        </span>
                                    </p>
                                    <div class="round-details">
                                        <small><?php echo $round['teams_count']; ?> <?php echo ($tournament['mode'] === 'Solo') ? 'Players' : 'Teams'; ?></small>
                                        <?php if (!empty($round['map_name'])): ?>
                                            <small>Map: <?php echo htmlspecialchars($round['map_name']); ?></small>
                                        <?php endif; ?>
                                        <?php if (!empty($round['status'])): ?>
                                            <span class="badge badge-<?php echo $round['status']; ?>">
                                                <?php echo ucfirst($round['status']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- No Schedule Available -->
                    <div class="no-schedule">
                        <ion-icon name="calendar-outline" class="large-icon"></ion-icon>
                        <h3>Tournament Schedule Not Available</h3>
                        <p>The tournament schedule will be announced soon.</p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Players Section -->
            <div id="players-section" class="tab-content">
                <div class="players-section">
                    <div class="players-branches">
                        <?php
                        if ($tournament['mode'] === 'Solo') {
                            if (!empty($players)): ?>
                                <div class="branch">
                                    <h3>Registered Players</h3>
                                    <?php foreach ($players as $player_reg): 
                                        $player = $player_reg['users'];
                                        $profile_image = !empty($player['profile_image']) ? $player['profile_image'] : $default_profile_image;
                                    ?>
                                        <div class="player-card">
                                            <img src="<?php echo htmlspecialchars($profile_image); ?>" 
                                                 alt="<?php echo htmlspecialchars($player['username']); ?>"
                                                 onerror="this.src='../../assets/images/guest-icon.png'">
                                            <span><?php echo htmlspecialchars($player['username']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="no-registrations">
                                    <p>No players have registered for this tournament yet.</p>
                                </div>
                            <?php endif;
                        } else {
                            if (!empty($teams)): 
                                // Sort teams by name
                                usort($teams, function($a, $b) {
                                    return strcmp($a['name'], $b['name']);
                                });

                                foreach ($teams as $team): ?>
                                    <div class="branch">
                                        <div class="team-header">
                                            <?php if (!empty($team['logo'])): ?>
                                                <img src="<?php echo htmlspecialchars($team['logo']); ?>" 
                                                     alt="<?php echo htmlspecialchars($team['name']); ?> logo"
                                                     class="team-logo"
                                                     onerror="this.src='../../assets/images/default-team-logo.png'">
                                            <?php endif; ?>
                                            <h3><?php echo htmlspecialchars($team['name']); ?></h3>
                                        </div>
                                        <?php 
                                        // Sort members to show captain first
                                        usort($team['team_members'], function($a, $b) {
                                            if ($a['role'] === 'captain') return -1;
                                            if ($b['role'] === 'captain') return 1;
                                            return 0;
                                        });

                                        foreach ($team['team_members'] as $member_data): 
                                            $member = $member_data['users'];
                                            $profile_image = !empty($member['profile_image']) ? $member['profile_image'] : $default_profile_image;
                                        ?>
                                            <div class="player-card <?php echo $member_data['role']; ?>">
                                                <img src="<?php echo htmlspecialchars($profile_image); ?>" 
                                                     alt="<?php echo htmlspecialchars($member['username']); ?>"
                                                     onerror="this.src='../../assets/images/guest-icon.png'">
                                                <span>
                                                    <?php echo htmlspecialchars($member['username']); ?>
                                                    <?php if ($member_data['role'] === 'captain'): ?>
                                                        <span class="captain-badge" title="Team Captain">👑</span>
                                                    <?php endif; ?>
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach;
                            else: ?>
                                <div class="no-registrations">
                                    <p>No <?php echo ($tournament['mode'] === 'Solo') ? 'players' : 'teams'; ?> have registered for this tournament yet.</p>
                                </div>
                            <?php endif;
                        } ?>
                    </div>
                </div>
            </div>

            <!-- Winners Section -->
            <div id="winners-section" class="tab-content">
                <div class="winners-section">
                    <?php if ($tournament['status'] === 'completed'): ?>
                        <!-- Add winner display logic here -->
                        <img src="/assets/images/winner-trophy.png" alt="Winner Trophy" class="winner-trophy" />
                        <div class="winner-details">
                            <!-- Add winner details here -->
                        </div>
                    <?php else: ?>
                        <img src="/assets/images/winner-trophy.png" alt="Winner Trophy" class="winner-trophy" />
                        <p class="winner-msg">Once the tournament is over, the data takes<br>a few minutes to appear.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Rules Section -->
            <div id="rules-section" class="tab-content">
                <div class="ruler">
                    <div class="rules-container">
                        <?php
                        $rules = explode("\n", $tournament['rules']);
                        foreach ($rules as $index => $rule):
                            if (trim($rule)):
                        ?>
                            <div class="rule-item">
                                <button class="rule-header" onclick="toggleAccordion('rule<?php echo $index; ?>')">
                                    <span class="icon">❯</span> Rule <?php echo $index + 1; ?>
                                </button>
                                <div id="rule<?php echo $index; ?>" class="rule-body">
                                    <?php echo htmlspecialchars($rule); ?>
                                </div>
                            </div>
                        <?php 
                            endif;
                        endforeach; 
                        ?>
                    </div>
                </div>
            </div>
        </div>
    </article>
</main>

<script>
// Countdown Timer
function updateCountdown() {
    const countdownElement = document.querySelector('.countdown-grid');
    if (!countdownElement) return;

    const endDateStr = countdownElement.dataset.endDate;
    const endDate = new Date(endDateStr).getTime();
    
    function update() {
        const now = new Date().getTime();
        const distance = endDate - now;

        if (distance < 0) {
            document.getElementById('days').textContent = '0';
            document.getElementById('hours').textContent = '0';
            document.getElementById('minutes').textContent = '0';
            document.getElementById('seconds').textContent = '0';
            return;
        }

        const days = Math.floor(distance / (1000 * 60 * 60 * 24));
        const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        const seconds = Math.floor((distance % (1000 * 60)) / 1000);

        document.getElementById('days').textContent = days;
        document.getElementById('hours').textContent = hours;
        document.getElementById('minutes').textContent = minutes;
        document.getElementById('seconds').textContent = seconds;
    }

    update();
    setInterval(update, 1000);
}

// Tab Switching
const tabButtons = document.querySelectorAll('.tab-btn');
const tabContents = document.querySelectorAll('.tab-content');

tabButtons.forEach(button => {
    button.addEventListener('click', () => {
        tabButtons.forEach(btn => btn.classList.remove('active'));
        tabContents.forEach(tab => tab.classList.remove('active'));

        button.classList.add('active');
        const tabId = button.getAttribute('data-tab');
        document.getElementById(tabId).classList.add('active');
    });
});

// Rules Accordion
function toggleAccordion(id) {
    const body = document.getElementById(id);
    const item = body.parentElement;

    if (body.style.display === "block") {
        body.style.display = "none";
        item.classList.remove("open");
    } else {
        body.style.display = "block";
        item.classList.add("open");
    }
}

// Initialize countdown
document.addEventListener('DOMContentLoaded', updateCountdown);
</script>

<?php loadSecureInclude('footer.php'); ?>