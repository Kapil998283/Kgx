<?php
// Only start session if one isn't already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
define('SECURE_ACCESS', true);
require_once '../secure_config.php';

// Load secure configurations and includes
loadSecureConfig('supabase.php');
loadSecureInclude('auth.php');
loadSecureInclude('header.php');

// Initialize AuthManager and SupabaseClient
$authManager = new AuthManager();
$supabaseClient = new SupabaseClient();

// Make sure user is logged in
if (!$authManager->isLoggedIn()) {
    header("Location: " . BASE_URL . "register/login.php");
    exit();
}

$currentUser = $authManager->getCurrentUser();
$user_id = $currentUser['user_id'];

// Check if user is in any team using Supabase REST API
$teams = [];
$has_teams = false;
$user_is_captain = false;
$user_is_member = false;

try {
    // Get user's active team memberships
    $active_memberships = $supabaseClient->select('team_members', '*', [
        'user_id' => $user_id,
        'status' => 'active'
    ]);
    
    // Also check if user is captain of any teams
    $captain_teams = $supabaseClient->select('teams', '*', [
        'captain_id' => $user_id,
        'is_active' => true
    ]);
    
    if (!empty($active_memberships) || !empty($captain_teams)) {
        $has_teams = true;
        
        foreach ($active_memberships as $membership) {
            // Get team details
            $team_details = $supabaseClient->select('teams', '*', [
                'id' => $membership['team_id'],
                'is_active' => true
            ]);
            
            if (!empty($team_details)) {
                $team = $team_details[0];
                $team['role'] = $membership['role'];
                
                // Track user role status
                if ($membership['role'] === 'captain') {
                    $user_is_captain = true;
                } else {
                    $user_is_member = true;
                }
                
                // Get member count for this team
                $team_members = $supabaseClient->select('team_members', 'id', [
                    'team_id' => $team['id'],
                    'status' => 'active'
                ]);
                
                $member_count = count($team_members);
                
                $team['member_count'] = $member_count;
                
                // Set default total_score if not present
                if (!isset($team['total_score'])) {
                    $team['total_score'] = 0;
                }
                
                $teams[] = $team;
            }
        }
        
        // Process captain teams (teams where user is captain via teams.captain_id)
        foreach ($captain_teams as $captain_team) {
            $captain_team['role'] = 'captain';
            $user_is_captain = true;
            
            // Get member count for this team
            $team_members = $supabaseClient->select('team_members', 'id', [
                'team_id' => $captain_team['id'],
                'status' => 'active'
            ]);
            
            $member_count = count($team_members);
            
            $captain_team['member_count'] = $member_count;
            
            // Set default total_score if not present
            if (!isset($captain_team['total_score'])) {
                $captain_team['total_score'] = 0;
            }
            
            // Check if this team is already added (avoid duplicates)
            $already_added = false;
            foreach ($teams as $existing_team) {
                if ($existing_team['id'] == $captain_team['id']) {
                    $already_added = true;
                    break;
                }
            }
            
            if (!$already_added) {
                $teams[] = $captain_team;
            }
        }
    }
} catch (Exception $e) {
    error_log("Error fetching teams: " . $e->getMessage());
    $has_teams = false;
}

// Get active tab from URL parameter or default to players
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'players';
$team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;

// Get pending join requests for teams where user is captain
$pending_requests = [];
if (!empty($teams)) {
    foreach ($teams as $team) {
        if ($team['role'] === 'captain') {
            try {
                // Get pending requests for this team
                $team_requests = $supabaseClient->select('team_join_requests', '*', [
                    'team_id' => $team['id'],
                    'status' => 'pending'
                ]);
                
                // For each request, get user details
                foreach ($team_requests as $request) {
                    $user_details = $supabaseClient->select('users', 'username, profile_image', [
                        'id' => $request['user_id']
                    ]);
                    
                    if (!empty($user_details)) {
                        $request['user'] = $user_details[0];
                        $request['team_name'] = $team['name'];
                        $pending_requests[] = $request;
                    }
                }
            } catch (Exception $e) {
                error_log("Error fetching pending requests: " . $e->getMessage());
            }
        }
    }
}

// Check for messages
$success_message = '';
$error_message = '';
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error_message = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Teams</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/teams/team.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>

<main>
  <article>
    <!-- Success/Error Message Display -->
    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success" style="margin: 20px auto; max-width: 800px; padding: 15px; background: #d4edda; color: #155724; border: 1px solid #c3e6cb; border-radius: 5px;">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
        </div>
    <?php endif; ?>
    
    <?php if (!empty($error_message)): ?>
        <div class="alert alert-danger" style="margin: 20px auto; max-width: 800px; padding: 15px; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; border-radius: 5px;">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
        </div>
    <?php endif; ?>
    
    <?php if (!$has_teams): ?>
        <div class="no-teams-container">
            <div class="no-teams-content">
                <i class="fas fa-users-slash no-teams-icon"></i>
                <h2>No Teams Found</h2>
                <p>You haven't joined or created any teams yet.</p>
                <div class="no-teams-actions">
                    <a href="index.php" class="action-btn join-btn">
                        <i class="fas fa-user-plus"></i> Join a Team
                    </a>
                    <a href="create_team.php" class="action-btn create-btn">
                        <i class="fas fa-plus-circle"></i> Create New Team
                    </a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($teams as $team): ?>
            <section class="team-banner">
                <div class="banner-container">
                    <?php
                    // Get banner path from team_banners table using Supabase
                    try {
                        $banner_data = $supabaseClient->select('team_banners', 'image_path', [
                            'id' => $team['banner_id']
                        ]);
                        $banner_path = !empty($banner_data) ? $banner_data[0]['image_path'] : 'assets/images/hero-banner1.png';
                    } catch (Exception $e) {
                        $banner_path = 'assets/images/hero-banner1.png';
                    }
                    ?>
                    <img src="<?php echo htmlspecialchars($banner_path); ?>" 
                         alt="Team Background" class="banner-bg" />
                    
                    <div class="team-content">
                        <div class="team-avatar">
                            <img src="<?php echo htmlspecialchars($team['logo']); ?>" 
                                 alt="Team Avatar"
                                 onerror="this.src='assets/images/character-2.png'" />
                        </div>
                
                        <div class="team-details">
                            <h2><?php echo htmlspecialchars($team['name']); ?></h2>
                            <div class="team-meta">
                                <span><i class="fas fa-users"></i> <?php echo $team['member_count']; ?> players</span>
                                <span><i class="fas fa-language"></i> <?php echo htmlspecialchars($team['language']); ?></span>
                                <span><i class="fas fa-id-card"></i> Team ID: <?php echo $team['id']; ?></span>
                                <?php
                                // Check if user is a member or captain of this team
                                $is_team_member = false;
                                foreach ($teams as $user_team) {
                                    if ($user_team['id'] === $team['id']) {
                                        $is_team_member = true;
                                        break;
                                    }
                                }
                                if ($is_team_member):
                                ?>
                                <span><i class="fas fa-trophy"></i> Score: <?php echo number_format($team['total_score']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                
                    <?php if ($team['role'] === 'captain'): ?>
                        <a href="<?php echo BASE_URL; ?>teams/captain.php?id=<?php echo $team['id']; ?>" class="edit-btn">
                            <i class="fas fa-edit"></i> Edit Team
                        </a>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Tournament Section -->
            <section class="tournament-section">
                <div class="tabs">
                    <a href="yourteams.php?tab=players&team_id=<?php echo $team['id']; ?>" 
                       class="tab <?php echo $active_tab === 'players' ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i> Players
                    </a>
                    <a href="yourteams.php?tab=tournament&team_id=<?php echo $team['id']; ?>" 
                       class="tab <?php echo $active_tab === 'tournament' ? 'active' : ''; ?>">
                        <i class="fas fa-trophy"></i> Tournament
                    </a>
                    <?php if ($team['role'] === 'captain'): ?>
                        <a href="yourteams.php?tab=requests&team_id=<?php echo $team['id']; ?>" 
                           class="tab <?php echo $active_tab === 'requests' ? 'active' : ''; ?>">
                            <i class="fas fa-user-plus"></i> Requests 
                            <?php if (!empty($pending_requests)): ?>
                                <span class="badge"><?php echo count($pending_requests); ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Player Content -->
                <div class="tab-content <?php echo $active_tab === 'players' ? 'active' : ''; ?>" id="playersContent">
                    <div class="player-list">
                        <?php
                        // Get team members using SupabaseClient
                        try {
                            $team_memberships = $supabaseClient->select('team_members', '*', [
                                'team_id' => $team['id'],
                                'status' => 'active'
                            ]);
                            
                            $members = [];
                            foreach ($team_memberships as $membership) {
                                // Get user details for each member
                                $user_details = $supabaseClient->select('users', 'id, username, profile_image', [
                                    'id' => $membership['user_id']
                                ]);
                                
                                if (!empty($user_details)) {
                                    $member = [
                                        'user_id' => $user_details[0]['id'],
                                        'username' => $user_details[0]['username'],
                                        'profile_image' => $user_details[0]['profile_image'],
                                        'role' => $membership['role'],
                                        'joined_at' => $membership['joined_at']
                                    ];
                                    $members[] = $member;
                                }
                            }
                            
                            // Sort members: captain first, then by join date
                            usort($members, function($a, $b) {
                                if ($a['role'] === 'captain' && $b['role'] !== 'captain') return -1;
                                if ($a['role'] !== 'captain' && $b['role'] === 'captain') return 1;
                                return strtotime($a['joined_at']) - strtotime($b['joined_at']);
                            });
                            
                        } catch (Exception $e) {
                            error_log("Error fetching team members: " . $e->getMessage());
                            $members = [];
                        }
                        
                        $member_number = 0; // Initialize counter
                        foreach ($members as $member):
                            $member_number++; // Increment counter for each member
                        ?>
                            <div class="player-card <?php echo $member['role'] === 'captain' ? 'captain-card' : ''; ?>">
                                <div class="member-number">#<?php echo $member_number; ?></div>
                                <div class="player-avatar-wrapper">
                                    <?php
                                    $profile_image = $member['profile_image'];
                                    
                                    // If user has no profile image, get the default one from profile_images table
                                    if (empty($profile_image)) {
                                        try {
                                            $default_img_data = $supabaseClient->select('profile_images', 'image_path', [
                                                'is_default' => true,
                                                'is_active' => true
                                            ], null, 1);
                                            $profile_image = !empty($default_img_data) ? $default_img_data[0]['image_path'] : 'assets/images/guest-icon.png';
                                        } catch (Exception $e) {
                                            $profile_image = 'assets/images/guest-icon.png';
                                        }
                                    }
                                    ?>
                                    <img src="<?php echo htmlspecialchars($profile_image); ?>" 
                                         alt="<?php echo htmlspecialchars($member['username']); ?>" 
                                         class="player-avatar"
                                         onerror="this.src='assets/images/guest-icon.png'">
                                    <?php if ($member['role'] === 'captain'): ?>
                                        <div class="captain-badge">
                                            <i class="fas fa-crown"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="player-info">
                                    <h3 class="player-name">
                                        <?php echo htmlspecialchars($member['username']); ?>
                                    </h3>
                                    <p class="role <?php echo $member['role']; ?>">
                                        <?php echo ucfirst($member['role']); ?>
                                    </p>
                                    <p class="join-date">
                                        <i class="fas fa-calendar"></i>
                                        Joined: <?php echo date('M d, Y', strtotime($member['joined_at'])); ?>
                                    </p>
                                </div>
                                <?php if ($team['role'] === 'captain' && $member['role'] !== 'captain'): ?>
                                    <form action="remove_member.php" method="POST" class="remove-member-form">
                                        <input type="hidden" name="member_id" value="<?php echo $member['user_id']; ?>">
                                        <input type="hidden" name="team_id" value="<?php echo $team['id']; ?>">
                                        <button type="submit" class="remove-member-btn" onclick="return confirm('Are you sure you want to remove this member?');">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <button class="details-btn" onclick="showGameProfile(<?php echo $member['user_id']; ?>)">
                                    Details
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Game Profile Modal -->
                <div id="gameProfileModal" class="modal">
                    <div class="modal-content">
                        <span class="close-modal">&times;</span>
                        <div id="gameProfileContent">
                            <!-- Content will be loaded here -->
                        </div>
                    </div>
                </div>

                <!-- Tournament Content -->
                <div class="tab-content <?php echo $active_tab === 'tournament' ? 'active' : ''; ?>" id="tournamentContent">
                    <div class="tournaments-section">
                        <?php
                        // Fetch tournaments using SupabaseClient
                        $all_tournaments = [];
                        
                        try {
                            // Fetch tournaments where the team is registered (duo/squad)
                            $team_registrations = $supabaseClient->select('tournament_registrations', '*', [
                                'team_id' => $team['id']
                            ]);
                            
                            $team_tournaments = [];
                            foreach ($team_registrations as $registration) {
                                // Get tournament details for each registration
                                $tournament_data = $supabaseClient->select('tournaments', '*', [
                                    'id' => $registration['tournament_id']
                                ]);
                                
                                if (!empty($tournament_data)) {
                                    $tournament = $tournament_data[0];
                                    $tournament['registration_date'] = $registration['registration_date'];
                                    $tournament['registration_status'] = $registration['status'];
                                    $tournament['solo_user_id'] = null;
                                    $tournament['solo_username'] = null;
                                    $team_tournaments[] = $tournament;
                                }
                            }
                            
                            // Fetch all team members for solo tournament check
                            $team_memberships = $supabaseClient->select('team_members', 'user_id', [
                                'team_id' => $team['id'],
                                'status' => 'active'
                            ]);
                            
                            $member_ids = array_column($team_memberships, 'user_id');
                            
                            // Fetch solo tournaments for all team members
                            $solo_tournaments = [];
                            if (!empty($member_ids)) {
                                foreach ($member_ids as $member_id) {
                                    $solo_registrations = $supabaseClient->select('tournament_registrations', '*', [
                                        'user_id' => $member_id
                                    ]);
                                    
                                    foreach ($solo_registrations as $solo_reg) {
                                        $solo_tournament_data = $supabaseClient->select('tournaments', '*', [
                                            'id' => $solo_reg['tournament_id'],
                                            'mode' => 'Solo'
                                        ]);
                                        
                                        if (!empty($solo_tournament_data)) {
                                            // Get username for this registration
                                            $user_data = $supabaseClient->select('users', 'username', [
                                                'id' => $member_id
                                            ]);
                                            
                                            $tournament = $solo_tournament_data[0];
                                            $tournament['registration_date'] = $solo_reg['registration_date'];
                                            $tournament['registration_status'] = $solo_reg['status'];
                                            $tournament['solo_user_id'] = $member_id;
                                            $tournament['solo_username'] = !empty($user_data) ? $user_data[0]['username'] : 'Unknown';
                                            $solo_tournaments[] = $tournament;
                                        }
                                    }
                                }
                            }
                            
                            // Merge and deduplicate tournaments (avoid showing the same tournament twice if registered both as team and solo)
                            $all_tournaments = $team_tournaments;
                            foreach ($solo_tournaments as $solo) {
                                // Only add if not already in team_tournaments
                                $already = false;
                                foreach ($team_tournaments as $tt) {
                                    if ($tt['id'] == $solo['id']) {
                                        $already = true;
                                        break;
                                    }
                                }
                                if (!$already) {
                                    $all_tournaments[] = $solo;
                                }
                            }
                            
                            // Sort tournaments by playing start date (most recent first)
                            usort($all_tournaments, function($a, $b) {
                                return strtotime($b['playing_start_date']) - strtotime($a['playing_start_date']);
                            });
                            
                        } catch (Exception $e) {
                            error_log("Error fetching tournaments: " . $e->getMessage());
                            $all_tournaments = [];
                        }
                        ?>

                        <?php if (!empty($all_tournaments)): ?>
                            <div class="row g-4">
                                <?php foreach ($all_tournaments as $tournament): ?>
                                    <div class="col-md-6">
                                        <div class="tournament-card">
                                            <div class="card-banner">
                                                <img src="<?php echo htmlspecialchars($tournament['banner_image']); ?>" 
                                                     alt="<?php echo htmlspecialchars($tournament['name']); ?>" 
                                                     class="tournament-banner">
                                                <div class="tournament-meta">
                                                    <div class="prize-pool">
                                                        <ion-icon name="trophy-outline"></ion-icon>
                                                        <span><?php 
                                                            echo $tournament['prize_currency'] === 'USD' ? '$' : '₹';
                                                            echo number_format($tournament['prize_pool'], 2); 
                                                        ?></span>
                                                    </div>
                                                    <div class="registration-status">
                                                        <ion-icon name="checkmark-circle-outline"></ion-icon>
                                                        <span><?php echo ucfirst($tournament['registration_status']); ?></span>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="card-content">
                                                <h3 class="tournament-title"><?php echo htmlspecialchars($tournament['name']); ?></h3>
                                                <p class="game-name"><?php echo htmlspecialchars($tournament['game_name']); ?></p>
                                                <div class="tournament-info">
                                                    <div class="info-item">
                                                        <ion-icon name="people-outline"></ion-icon>
                                                        <span><?php echo $tournament['current_teams']; ?>/<?php echo $tournament['max_teams']; ?> Teams</span>
                                                    </div>
                                                    <div class="info-item">
                                                        <ion-icon name="game-controller-outline"></ion-icon>
                                                        <span><?php echo htmlspecialchars($tournament['mode']); ?></span>
                                                    </div>
                                                </div>
                                                <div class="tournament-dates">
                                                    <div class="registration-date">
                                                        Registered: <?php echo date('M d, Y', strtotime($tournament['registration_date'])); ?>
                                                    </div>
                                                    <div class="tournament-starts">
                                                        Starts: <?php echo date('M d, Y', strtotime($tournament['playing_start_date'])); ?>
                                                    </div>
                                                </div>
                                                <?php if ($tournament['mode'] === 'Solo' && !empty($tournament['solo_username'])): ?>
                                                    <div class="solo-registered-by">
                                                        <ion-icon name="person-outline"></ion-icon>
                                                        <span>Registered by: <strong><?php echo htmlspecialchars($tournament['solo_username']); ?></strong> (Solo)</span>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="tournament-phase">
                                                    <?php
                                                    $phase_class = '';
                                                    $phase_text = '';
                                                    switch ($tournament['registration_phase'] ?? $tournament['phase']) {
                                                        case 'open':
                                                            $phase_class = 'bg-success';
                                                            $phase_text = 'Registration Open';
                                                            break;
                                                        case 'closed':
                                                            $phase_class = 'bg-warning';
                                                            $phase_text = 'Registration Closed';
                                                            break;
                                                        case 'playing':
                                                            $phase_class = 'bg-primary';
                                                            $phase_text = 'Tournament Active';
                                                            break;
                                                        default:
                                                            $phase_class = 'bg-secondary';
                                                            $phase_text = 'Tournament Ended';
                                                    }
                                                    ?>
                                                    <span class="badge <?php echo $phase_class; ?>"><?php echo $phase_text; ?></span>
                                                </div>
                                                <div class="card-actions">
                                                    <a href="../tournaments/details.php?id=<?php echo $tournament['id']; ?>" 
                                                       class="btn btn-primary">View Details</a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="no-tournaments">
                                <ion-icon name="trophy-outline" class="large-icon"></ion-icon>
                                <h3>No Tournaments Yet</h3>
                                <p>Your team hasn't registered for any tournaments.</p>
                                <a href="../tournaments/index.php" class="btn btn-primary mt-3">Browse Tournaments</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Requests Content -->
                <?php if ($team['role'] === 'captain'): ?>
                    <div class="tab-content <?php echo $active_tab === 'requests' ? 'active' : ''; ?>" id="requestsContent">
                        <div class="requests-list">
                        <?php
                        // Get pending requests for this team with user details using Supabase
                        $team_pending_requests = [];
                        try {
                            $requests = $supabaseClient->select('team_join_requests', '*', [
                                'team_id' => $team['id'],
                                'status' => 'pending'
                            ]);
                            
                            foreach ($requests as $request) {
                                // Get user details for each request
                                $user_data = $supabaseClient->select('users', 'username, profile_image', [
                                    'id' => $request['user_id']
                                ]);
                                
                                if (!empty($user_data)) {
                                    $request['username'] = $user_data[0]['username'];
                                    $request['profile_image'] = $user_data[0]['profile_image'];
                                    $team_pending_requests[] = $request;
                                }
                            }
                            
                            // Sort by created_at DESC
                            usort($team_pending_requests, function($a, $b) {
                                return strtotime($b['created_at']) - strtotime($a['created_at']);
                            });
                            
                        } catch (Exception $e) {
                            error_log("Error fetching team requests: " . $e->getMessage());
                            $team_pending_requests = [];
                        }
                        ?>

                            <?php if (!empty($team_pending_requests)): ?>
                                <?php foreach ($team_pending_requests as $request): ?>
                                    <?php
                                    // Handle profile image
                                    $profile_image = $request['profile_image'];
                                    
                                    // If user has no profile image, get the default one from profile_images table using Supabase
                                    if (empty($profile_image)) {
                                        try {
                                            $default_img_data = $supabaseClient->select('profile_images', 'image_path', [
                                                'is_default' => true,
                                                'is_active' => true
                                            ], null, 1);
                                            $profile_image = !empty($default_img_data) ? $default_img_data[0]['image_path'] : 'assets/images/guest-icon.png';
                                        } catch (Exception $e) {
                                            $profile_image = 'assets/images/guest-icon.png';
                                        }
                                    }
                                    
                                    // If the path is a full URL, use it as is
                                    if (filter_var($profile_image, FILTER_VALIDATE_URL)) {
                                        // URL is already complete, use as is
                                    }
                                    // If it's a local path and doesn't start with /KGX, add it
                                    else if (strpos($profile_image, '/KGX') !== 0) {
                                        $profile_image = '/KGX' . $profile_image;
                                    }
                                    ?>
                                    <div class="request-card">
                                        <img src="<?php echo htmlspecialchars($profile_image); ?>" 
                                             alt="<?php echo htmlspecialchars($request['username']); ?>" 
                                             class="request-avatar"
                                             onerror="this.src='assets/images/guest-icon.png'">
                                        <div class="request-info">
                                            <h3><?php echo htmlspecialchars($request['username']); ?></h3>
                                            <p class="request-date">Requested: <?php echo date('M d, Y', strtotime($request['created_at'])); ?></p>
                                        </div>
                                        <div class="request-actions">
                                            <form action="handle_request.php" method="POST">
                                                <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <input type="hidden" name="team_id" value="<?php echo $team['id']; ?>">
                                                <input type="hidden" name="active_tab" value="requests">
                                                <button type="submit" class="accept-btn">
                                                    <i class="fas fa-check"></i> Accept
                                                </button>
                                            </form>
                                            <form action="handle_request.php" method="POST">
                                                <input type="hidden" name="request_id" value="<?php echo $request['id']; ?>">
                                                <input type="hidden" name="action" value="reject">
                                                <input type="hidden" name="team_id" value="<?php echo $team['id']; ?>">
                                                <input type="hidden" name="active_tab" value="requests">
                                                <button type="submit" class="reject-btn">
                                                    <i class="fas fa-times"></i> Reject
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="no-requests">
                                    <i class="fas fa-user-plus"></i>
                                    <h3>No Pending Requests</h3>
                                    <p>There are no pending join requests for your team at the moment.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
  </article>
</main>

<?php loadSecureInclude('footer.php'); ?>

<script>
function showGameProfile(userId) {
    const modal = document.getElementById('gameProfileModal');
    const content = document.getElementById('gameProfileContent');
    const closeBtn = document.querySelector('.close-modal');

    // Show loading state
    content.innerHTML = '<div style="text-align: center; padding: 20px;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';
    modal.style.display = 'block';

    // Fetch game profile data with improved error handling
    fetch(`get_game_profile.php?user_id=${userId}`)
        .then(response => {
            if (!response.ok) {
                // Log detailed error and throw to be caught by .catch
                console.error(`HTTP error! Status: ${response.status}`, response);
                throw new Error(`Network response was not ok: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.game_profile) {
                const profile = data.game_profile;
                content.innerHTML = `
                    <h3 style="margin-bottom: 20px; color: #fff;">Game Profile</h3>
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #25d366;">Game:</strong>
                        <span style="color: #fff;">${profile.game_name || 'Not specified'}</span>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #25d366;">In-Game Name:</strong>
                        <span style="color: #fff;">${profile.game_username || 'Not specified'}</span>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #25d366;">Game UID:</strong>
                        <span style="color: #fff;">${profile.game_uid || 'Not specified'}</span>
                    </div>
                    <div style="margin-bottom: 15px;">
                        <strong style="color: #25d366;">Game Level:</strong>
                        <span style="color: #fff;">${profile.game_level || 'Not specified'}</span>
                    </div>
                `;
            } else {
                 content.innerHTML = `
                    <div style="text-align: center; padding: 20px; color: #fff;">
                        <i class="fas fa-exclamation-circle" style="color: #ff4655; font-size: 24px; margin-bottom: 10px;"></i>
                        <p>${data.message || 'No game profile found'}</p>
                    </div>
                `;
            }
        })
        .catch(error => {
            content.innerHTML = `
                <div style="text-align: center; padding: 20px; color: #ff4655;">
                    <i class="fas fa-exclamation-triangle"></i>
                    <p>Error loading game profile. Check browser console for more details.</p>
                </div>
            `;
            console.error('Error fetching game profile:', error);
        });

    // Close modal when clicking the close button
    closeBtn.onclick = function() {
        modal.style.display = 'none';
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
        if (event.target == modal) {
            modal.style.display = 'none';
        }
    }
}
</script>
