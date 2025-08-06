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

// Check if user is logged in
if (!$authManager->isLoggedIn()) {
    $redirect_url = BASE_URL . "register/login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']);
    header("Location: " . $redirect_url);
    exit();
}

$currentUser = $authManager->getCurrentUser();
$user_id = $currentUser['user_id'];
$registrations = [];

try {
    // Debug: Log the user_id being searched
    error_log("Searching for registrations for user_id: " . $user_id);
    
    // Fetch solo registrations (try different syntax for NULL check)
    $solo_registrations = $supabaseClient->select(
        'tournament_registrations',
        '*, tournaments(*)',
        ['user_id' => $user_id, 'team_id' => 'is.null']
    );
    
    // Debug: Log solo registrations found
    error_log("Solo registrations found: " . count($solo_registrations ?? []));
    if (!empty($solo_registrations)) {
        error_log("Solo registrations data: " . print_r($solo_registrations, true));
    }

    if ($solo_registrations) {
        foreach ($solo_registrations as $reg) {
            $registration_details = $reg['tournaments'];
            $registration_details['registration_type'] = 'solo';
            $registration_details['registration_status'] = $reg['status'];
            $registration_details['registration_date'] = $reg['registration_date'];
            $registrations[] = $registration_details;
        }
    }

    // Fetch team memberships for the user
    $team_memberships = $supabaseClient->select(
        'team_members',
        'team_id, role',
        ['user_id' => $user_id, 'status' => 'active']
    );

    if ($team_memberships) {
        $team_ids = array_column($team_memberships, 'team_id');
        $team_roles = array_column($team_memberships, 'role', 'team_id');

        // Fetch team registrations
        $team_registrations = $supabaseClient->select(
            'tournament_registrations',
            '*, tournaments(*), teams(id, name)',
            ['team_id.in' => '(' . implode(',', $team_ids) . ')']
        );

        if ($team_registrations) {
            foreach ($team_registrations as $reg) {
                $registration_details = $reg['tournaments'];
                $registration_details['registration_type'] = 'team';
                $registration_details['registration_status'] = $reg['status'];
                $registration_details['registration_date'] = $reg['registration_date'];
                $registration_details['team_name'] = $reg['teams']['name'];
                $registration_details['team_id'] = $reg['team_id']; // Add team_id for team registrations
                $registration_details['is_captain'] = isset($team_roles[$reg['team_id']]) && $team_roles[$reg['team_id']] === 'captain';
                $registrations[] = $registration_details;
            }
        }
    }

    // Sort registrations by date
    usort($registrations, function ($a, $b) {
        return strtotime($b['registration_date']) - strtotime($a['registration_date']);
    });
} catch (Exception $e) {
    error_log("Error fetching registrations: " . $e->getMessage());
    // Handle error appropriately
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

// Function to determine the appropriate status to display for user registrations
function getUserRegistrationDisplayStatus($registration) {
    $tournament_status = $registration['status']; // Tournament status
    $registration_status = $registration['registration_status']; // User's registration status
    
    // If registration is not approved, show registration status
    if ($registration_status !== 'approved') {
        $status_info = [
            'pending' => ['status' => 'Pending Approval', 'class' => 'pending', 'icon' => 'time-outline'],
            'rejected' => ['status' => 'Registration Rejected', 'class' => 'rejected', 'icon' => 'close-circle-outline']
        ];
        
        return $status_info[$registration_status] ?? [
            'status' => ucfirst($registration_status),
            'class' => strtolower($registration_status),
            'icon' => 'help-circle-outline'
        ];
    }
    
    // If registration is approved, show tournament status
    $status_map = [
        'announced' => ['status' => 'Approved', 'class' => 'approved', 'icon' => 'checkmark-circle-outline'],
        'registration_open' => ['status' => 'Approved', 'class' => 'approved', 'icon' => 'checkmark-circle-outline'],
        'registration_closed' => ['status' => 'Starting Soon', 'class' => 'registration-closed', 'icon' => 'hourglass-outline'],
        'in_progress' => ['status' => 'Playing', 'class' => 'playing', 'icon' => 'play-circle-outline'],
        'completed' => ['status' => 'Completed', 'class' => 'completed', 'icon' => 'trophy-outline'],
        'archived' => ['status' => 'Completed', 'class' => 'completed', 'icon' => 'trophy-outline'],
        'cancelled' => ['status' => 'Cancelled', 'class' => 'cancelled', 'icon' => 'close-circle-outline']
    ];
    
    return $status_map[$tournament_status] ?? [
        'status' => 'Unknown Status',
        'class' => 'unknown',
        'icon' => 'help-circle-outline'
    ];
}
?>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/tournament/registrations.css">

<main>
    <section class="registrations-section">
        <div class="container">
            <div class="section-header">
                <h1 class="section-title">My Tournament Registrations</h1>
                <div class="title-underline"></div>
            </div>
            
            <?php if (empty($registrations)): ?>
                <div class="no-registrations">
                    <div class="no-registrations-content">
                        <ion-icon name="trophy-outline" class="large-icon"></ion-icon>
                        <h3>No Tournament Registrations</h3>
                        <p>You haven't registered for any tournaments yet.</p>
                        <a href="index.php" class="browse-btn">
                            <ion-icon name="search-outline"></ion-icon>
                            Browse Tournaments
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="registrations-grid">
                    <?php foreach ($registrations as $reg): ?>
                        <div class="registration-card">
                            <div class="card-banner">
                                <img src="<?php echo htmlspecialchars($reg['banner_image']); ?>" 
                                     alt="<?php echo htmlspecialchars($reg['name']); ?>" 
                                     class="tournament-banner"
                                     onerror="this.src='../../assets/images/default-tournament.jpg'">
                                
                                <?php $display_status = getUserRegistrationDisplayStatus($reg); ?>
                                <div class="registration-status <?php echo $display_status['class']; ?>">
                                    <ion-icon name="<?php echo $display_status['icon']; ?>"></ion-icon>
                                    <?php echo $display_status['status']; ?>
                                </div>
                            </div>

                            <div class="card-content">
                                <h3 class="tournament-title">
                                    <?php echo htmlspecialchars($reg['name']); ?>
                                </h3>
                                
                                <div class="tournament-meta">
                                    <div class="meta-item">
                                        <ion-icon name="game-controller-outline"></ion-icon>
                                        <span><?php echo htmlspecialchars($reg['game_name']); ?></span>
                                    </div>
                                    <div class="meta-item">
                                        <ion-icon name="people-outline"></ion-icon>
                                        <span><?php echo htmlspecialchars($reg['mode']); ?></span>
                                    </div>
                                    <div class="meta-item prize">
                                        <ion-icon name="trophy-outline"></ion-icon>
                                        <span><?php 
                                            echo $reg['prize_currency'] === 'USD' ? '$' : '₹';
                                            echo number_format($reg['prize_pool'], 2); 
                                        ?></span>
                                    </div>
                                </div>

                                <?php if ($reg['registration_type'] === 'solo'): ?>
                                    <div class="team-info">
                                        <div class="team-name">
                                            <ion-icon name="person-outline"></ion-icon>
                                            <span>Solo Player</span>
                                            <span class="badge solo">Individual</span>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="team-info">
                                        <div class="team-name">
                                            <ion-icon name="shield-outline"></ion-icon>
                                            <span><?php echo htmlspecialchars($reg['team_name']); ?></span>
                                            <?php if ($reg['is_captain']): ?>
                                                <span class="badge captain">Team Captain</span>
                                            <?php else: ?>
                                                <span class="badge member">Team Member</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <div class="tournament-dates">
                                    <div class="date-item">
                                        <ion-icon name="calendar-outline"></ion-icon>
                                        <span>Registered: 
                                            <span data-tournament-date="<?php echo htmlspecialchars($reg['registration_date']); ?>">
                                                <?php echo formatTournamentDate($reg['registration_date']); ?>
                                            </span>
                                        </span>
                                    </div>
                                    <div class="date-item">
                                        <?php 
                                        // Determine what date to show based on tournament status
                                        $date_label = '';
                                        $date_value = '';
                                        $icon_name = 'time-outline';
                                        
                                        switch($reg['status']) {
                                            case 'announced':
                                            case 'registration_open':
                                            case 'registration_closed':
                                                $date_label = 'Starts';
                                                $date_value = $reg['playing_start_date'];
                                                $icon_name = 'play-circle-outline';
                                                break;
                                            case 'in_progress':
                                                $date_label = 'Started';
                                                $date_value = $reg['playing_start_date'];
                                                $icon_name = 'play-circle-outline';
                                                break;
                                            case 'completed':
                                            case 'archived':
                                                $date_label = 'Finished';
                                                $date_value = $reg['finish_date'];
                                                $icon_name = 'checkmark-circle-outline';
                                                break;
                                            case 'cancelled':
                                                $date_label = 'Cancelled';
                                                $date_value = $reg['playing_start_date']; // Show original start date
                                                $icon_name = 'close-circle-outline';
                                                break;
                                            default:
                                                $date_label = 'Starts';
                                                $date_value = $reg['playing_start_date'];
                                                $icon_name = 'time-outline';
                                        }
                                        ?>
                                        <ion-icon name="<?php echo $icon_name; ?>"></ion-icon>
                                        <span><?php echo $date_label; ?>: 
                                            <span data-tournament-date="<?php echo htmlspecialchars($date_value); ?>">
                                                <?php echo formatTournamentDate($date_value); ?>
                                            </span>
                                        </span>
                                    </div>
                                </div>

                                <div class="card-actions">
                                    <?php if ($reg['registration_status'] === 'approved'): ?>
                                        <?php if ($reg['registration_type'] === 'solo'): ?>
                                            <?php if (in_array($reg['status'], ['completed', 'archived'])): ?>
                                                <!-- Solo tournament completed - show Results button -->
                                                <a href="details.php?id=<?php echo $reg['id']; ?>" 
                                                   class="action-btn primary">
                                                    <ion-icon name="trophy-outline"></ion-icon>
                                                    View Results
                                                </a>
                                            <?php else: ?>
                                                <!-- Solo tournament not completed - show Schedule button only -->
                                                <a href="match-schedule.php?tournament_id=<?php echo $reg['id']; ?>&user_id=<?php echo $_SESSION['user_id']; ?>" 
                                                   class="action-btn primary">
                                                    <ion-icon name="calendar-outline"></ion-icon>
                                                    Match Schedule
                                                </a>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php if (in_array($reg['status'], ['completed', 'archived'])): ?>
                                                <!-- Team tournament completed - show Results button only -->
                                                <a href="details.php?id=<?php echo $reg['id']; ?>" 
                                                   class="action-btn primary">
                                                    <ion-icon name="trophy-outline"></ion-icon>
                                                    View Results
                                                </a>
                                            <?php else: ?>
                                                <!-- Team tournament not completed - show Schedule and Team buttons -->
                                                <a href="match-schedule.php?tournament_id=<?php echo $reg['id']; ?>&team_id=<?php echo $reg['team_id']; ?>" 
                                                   class="action-btn primary">
                                                    <ion-icon name="calendar-outline"></ion-icon>
                                                    Schedule
                                                </a>
                                                <a href="../teams/yourteams.php?tab=tournament&team_id=<?php echo $reg['team_id']; ?>" 
                                                   class="action-btn secondary">
                                                    <ion-icon name="people-outline"></ion-icon>
                                                    Team
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <!-- Registration not approved - show View Details -->
                                        <a href="details.php?id=<?php echo $reg['id']; ?>" 
                                           class="action-btn primary">
                                            <ion-icon name="information-circle-outline"></ion-icon>
                                            View Details
                                        </a>
                                        <?php if ($reg['registration_status'] === 'pending'): ?>
                                            <button class="action-btn secondary" disabled>
                                                <ion-icon name="hourglass-outline"></ion-icon>
                                                Awaiting Approval
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<?php loadSecureInclude('footer.php'); ?>