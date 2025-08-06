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

// Initialize AuthManager and SupabaseClient
$authManager = new AuthManager();
$supabaseClient = new SupabaseClient();

// Helper function to get position suffix
function getPositionSuffix($position) {
    if ($position >= 11 && $position <= 13) {
        return 'th';
    }
    switch ($position % 10) {
        case 1:
            return 'st';
        case 2:
            return 'nd';
        case 3:
            return 'rd';
        default:
            return 'th';
    }
}

// Check if user is logged in
if (!$authManager->isLoggedIn()) {
    header("Location: " . BASE_URL . "register/login.php");
    exit();
}

$currentUser = $authManager->getCurrentUser();
$user_id = $currentUser['user_id'];

// --- Determine Profile Image ---
$profile_image = BASE_URL . 'assets/images/profile/profile3.png'; // Ultimate fallback path
$user_specific_image = null;

// 1. Check if user has a specific profile image set using SupabaseClient
try {
    $user_data_img = $supabaseClient->select('users', 'profile_image', [
        'id' => $user_id
    ]);
    
    if (!empty($user_data_img) && !empty($user_data_img[0]['profile_image'])) {
        $user_specific_image = $user_data_img[0]['profile_image'];
    }
} catch (Exception $e) {
    error_log("Dashboard: Error fetching user profile image: " . $e->getMessage());
}

if ($user_specific_image) {
    // Use the user's specific image
    $profile_image = $user_specific_image;
} else {
    // 2. If no user-specific image, find the default image from profile_images table
    try {
        $default_image_data = $supabaseClient->select('profile_images', 'image_path', [
            'is_default' => true,
            'is_active' => true
        ], null, 1);
        
        if (!empty($default_image_data)) {
            $profile_image = $default_image_data[0]['image_path'];
        }
    } catch (Exception $e) {
        error_log("Dashboard: Error fetching default profile image: " . $e->getMessage());
    }
    // If no default found, $profile_image remains the ultimate fallback
}

// Adjust path for local assets if needed
if (strpos($profile_image, '/assets/') === 0) {
    $profile_image = BASE_URL . substr($profile_image, 1); // Remove leading slash and add BASE_URL
} elseif (strpos($profile_image, 'assets/') === 0) {
    $profile_image = BASE_URL . $profile_image;
}
// --- End Determine Profile Image ---


// --- Dashboard data using SupabaseClient ---
// Fetch coins
$coins = 0;
try {
    $user_coins_data = $supabaseClient->select('user_coins', 'coins', [
        'user_id' => $user_id
    ]);
    
    if (!empty($user_coins_data)) {
        $coins = $user_coins_data[0]['coins'] ?? 0;
    }
} catch (Exception $e) {
    error_log("Dashboard: Error fetching coins: " . $e->getMessage());
}

// Fetch tickets
$tickets = 0;
try {
    $user_tickets_data = $supabaseClient->select('user_tickets', 'tickets', [
        'user_id' => $user_id
    ]);
    
    if (!empty($user_tickets_data)) {
        $tickets = $user_tickets_data[0]['tickets'] ?? 0;
    }
} catch (Exception $e) {
    error_log("Dashboard: Error fetching tickets: " . $e->getMessage());
}

// Get user's team score if they are in a team
$team_score = 0;
$has_team = false;
$team_id = null;
try {
    // First get user's active team membership
    $team_membership = $supabaseClient->select('team_members', 'team_id', [
        'user_id' => $user_id,
        'status' => 'active'
    ], null, 1);
    
    if (!empty($team_membership)) {
        $team_id = $team_membership[0]['team_id'];
        
        // Get team score
        $team_data = $supabaseClient->select('teams', 'total_score', [
            'id' => $team_id
        ]);
        
        if (!empty($team_data)) {
            $team_score = $team_data[0]['total_score'] ?? 0;
            $has_team = true;
        }
    }
} catch (Exception $e) {
    error_log("Dashboard: Error fetching team data: " . $e->getMessage());
}

// Get tournament count (both solo and team tournaments)
$tournament_count = 0;
try {
    $unique_tournaments = [];
    
    // Count solo tournaments
    $solo_registrations = $supabaseClient->select('tournament_registrations', 'tournament_id', [
        'user_id' => $user_id,
        'team_id' => 'is.null',
        'status' => 'approved'
    ]);
    
    if ($solo_registrations) {
        foreach ($solo_registrations as $reg) {
            $unique_tournaments[$reg['tournament_id']] = true;
        }
    }
    
    // Count team tournaments if user has team
    if ($has_team) {
        $team_registrations = $supabaseClient->select('tournament_registrations', 'tournament_id', [
            'team_id' => $team_id,
            'status' => 'approved'
        ]);
        
        if ($team_registrations) {
            foreach ($team_registrations as $reg) {
                $unique_tournaments[$reg['tournament_id']] = true;
            }
        }
    }
    
    $tournament_count = count($unique_tournaments);
} catch (Exception $e) {
    error_log("Dashboard: Error fetching tournament count: " . $e->getMessage());
}

// Get matches count and total kills from permanent stats
$matches_count = 0;
$total_kills = 0;
try {
    $user_stats_data = $supabaseClient->select('user_match_stats', 'total_matches_played, total_kills', [
        'user_id' => $user_id
    ]);
    
    if (!empty($user_stats_data)) {
        $matches_count = $user_stats_data[0]['total_matches_played'] ?? 0;
        $total_kills = $user_stats_data[0]['total_kills'] ?? 0;
    }
} catch (Exception $e) {
    error_log("Dashboard: Error fetching user stats: " . $e->getMessage());
}

// Get user's streak points
$streak_points = 0;
$current_streak = 0;
try {
    $streak_data = $supabaseClient->select('user_streaks', 'streak_points, current_streak', [
        'user_id' => $user_id
    ]);
    
    if (!empty($streak_data)) {
        $streak_points = $streak_data[0]['streak_points'] ?? 0;
        $current_streak = $streak_data[0]['current_streak'] ?? 0;
    }
} catch (Exception $e) {
    error_log("Dashboard: Error fetching streak data: " . $e->getMessage());
}

// Get count of videos watched
$videos_watched = 0;
try {
    $video_history = $supabaseClient->select('video_watch_history', 'video_id', [
        'user_id' => $user_id
    ]);
    
    // Count unique video IDs
    $unique_videos = [];
    foreach ($video_history as $history) {
        $unique_videos[$history['video_id']] = true;
    }
    $videos_watched = count($unique_videos);
} catch (Exception $e) {
    error_log("Dashboard: Error fetching videos watched: " . $e->getMessage());
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KGX Gaming - Dashboard</title>
    <!-- ======= Styles ====== -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>dashboard/dashboard.css">
</head>

<body>
   <!-- =============== Navigation ================ -->
   <div class="container">
        <div class="navigation">
            <ul>
                <li>
                    <a href="#">
                        <span class="icon">
                            <ion-icon name="game-controller-outline"></ion-icon>
                        </span>
                        <span class="title">KGX </span>
                    </a>
                </li>

                <li>
                    <a href="<?php echo BASE_URL; ?>home.php">
                        <span class="icon">
                            <ion-icon name="home-outline"></ion-icon>
                        </span>
                        <span class="title">Home</span>
                    </a>
                </li>

                <li>
                    <a href="./redeem.php">
                        <span class="icon">
                            <ion-icon name="gift-outline"></ion-icon>
                        </span>
                        <span class="title">Redeem</span>
                    </a>
                </li>

                
                <li>
                    <a href="./streak.php">
                        <span class="icon">
                            <ion-icon name="flame-outline"></ion-icon>
                        </span>
                        <span class="title">Streak</span>
                    </a>
                </li>

                <li>
                    <a href="./game-profile.php">
                        <span class="icon">
                            <ion-icon name="game-controller-outline"></ion-icon>
                        </span>
                        <span class="title">Game Profile</span>
                    </a>
                </li>

                <li>
                    <a href="./help-contact.php">
                        <span class="icon">
                            <ion-icon name="help-outline"></ion-icon>
                        </span>
                        <span class="title">Help</span>
                    </a>
                </li>

                <li>
                    <a href="./setting.php">
                        <span class="icon">
                            <ion-icon name="settings-outline"></ion-icon>
                        </span>
                        <span class="title">Settings</span>
                    </a>
                </li>

                <li>
                    <a href="<?php echo BASE_URL; ?>register/forgot-password.php">
                        <span class="icon">
                            <ion-icon name="lock-closed-outline"></ion-icon>
                        </span>
                        <span class="title">Password Reset</span>
                    </a>
                </li>

            </ul>
        </div>

        <!-- ========================= Main ==================== -->
        <div class="main">
            <div class="topbar">
                <div class="toggle">
                    <ion-icon name="menu-outline"></ion-icon>
                </div>

                <div class="search">
                    <label>
                        <input type="text" placeholder="Search here">
                        <ion-icon name="search-outline"></ion-icon>
                    </label>
                </div>

                <div class="user">
                    <img src="<?php echo htmlspecialchars($profile_image); ?>" alt="User Profile">
                </div>
            </div>

            <!-- ======================= Dashboard================== -->
        <section class="dashboard">
            <div class="cardBox">
                <div class="card">
                    <div>
                        <div class="numbers"><?php echo $coins; ?></div>
                        <div class="cardName">Coins</div>
                    </div>

                    <div class="iconBx">
                        <ion-icon name="wallet-outline"></ion-icon>
                    </div>
                </div>

                <div class="card">
                    <div>
                        <div class="numbers"><?php echo $tickets; ?></div>
                        <div class="cardName">Tickets</div>
                    </div>

                    <div class="iconBx">
                        <ion-icon name="cash-outline"></ion-icon>
                    </div>
                </div>

                <div class="card">
                    <div>
                        <div class="numbers"><?php echo $streak_points; ?></div>
                        <div class="cardName">Total Streak Points</div>
                        
                    </div>

                    <div class="iconBx">
                        <ion-icon name="flame-outline"></ion-icon>
                    </div>
                </div>

                <?php if ($has_team): ?>
                <div class="card">
                    <div>
                        <div class="numbers"><?php echo number_format($team_score); ?></div>
                        <div class="cardName">Team Score</div>
                    </div>

                    <div class="iconBx">
                        <ion-icon name="people-outline"></ion-icon>
                    </div>
                </div>
                <?php endif; ?>

                <div class="card">
                    <div>
                        <div class="numbers"><?php echo $matches_count; ?></div>
                        <div class="cardName">Matches Played</div>
                    </div>

                    <div class="iconBx">
                        <ion-icon name="game-controller-outline"></ion-icon>
                    </div>
                </div>

                <div class="card">
                    <div>
                        <div class="numbers"><?php echo $total_kills; ?></div>
                        <div class="cardName">Total Kills</div>
                    </div>

                    <div class="iconBx">
                        <ion-icon name="skull-outline"></ion-icon>
                    </div>
                </div>

                <div class="card">
                    <div>
                        <div class="numbers"><?php echo $tournament_count; ?></div>
                        <div class="cardName">Played Tournaments</div>
                    </div>

                    <div class="iconBx">
                        <ion-icon name="trophy-outline"></ion-icon>
                    </div>
                </div>

                <div class="card">
                    <div>
                        <div class="numbers"><?php echo $videos_watched; ?></div>
                        <div class="cardName">Videos Watched</div>
                    </div>

                    <div class="iconBx">
                        <ion-icon name="play-circle-outline"></ion-icon>
                    </div>
                </div>

                
            </div>
        </section>
            <!-- ================ Labels & Content Sections ================= -->
            <div class="labels-container">
                <div class="labels-nav">
                    <button class="label-btn active" data-section="top10">Top 10</button>
                    <button class="label-btn" data-section="matches">Matches</button>
                    <button class="label-btn" data-section="tournaments">Tournaments</button>
                </div>

                <!-- Top 10 Section -->
                <div class="content-section active" id="top10-section">
                    <div class="section-header">
                        <h2>Top 10 Teams</h2>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Team</th>
                                <th>Score</th>
                                <th>Members</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Get top 8 teams by score using SupabaseClient
                            $top_teams = [];
                            try {
                                // Get active teams ordered by total_score
                                $teams_data = $supabaseClient->select('teams', '*', [
                                    'is_active' => true
                                ], 'total_score.desc', 8);
                                
                                // For each team, get member count
                                foreach ($teams_data as $team) {
                                    $member_count_data = $supabaseClient->select('team_members', 'id', [
                                        'team_id' => $team['id']
                                    ]);
                                    
                                    $team['member_count'] = count($member_count_data);
                                    $top_teams[] = $team;
                                }
                            } catch (Exception $e) {
                                error_log("Dashboard: Error fetching top teams: " . $e->getMessage());
                                $top_teams = [];
                            }

                            if (count($top_teams) > 0):
                                foreach ($top_teams as $team):
                            ?>
                                <tr>
                                    <td>
                                        <div class="team-info">
                                            <?php if (!empty($team['logo'])): ?>
                                                <img src="<?php echo htmlspecialchars($team['logo']); ?>" 
                                                     alt="<?php echo htmlspecialchars($team['name']); ?>"
                                                     class="team-logo">
                                            <?php endif; ?>
                                            <span><?php echo htmlspecialchars($team['name']); ?></span>
                                        </div>
                                    </td>
                                    <td><?php echo number_format($team['total_score']); ?></td>
                                    <td><?php echo $team['member_count']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="text-center">No teams found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Matches Section -->
                <div class="content-section" id="matches-section">
                    <div class="section-header">
                        <h2>Recent Matches</h2>
                        <a href="<?php echo BASE_URL; ?>matches/my-matches.php" class="btn">View All</a>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Game</th>
                                <th>Type</th>
                                <th>Performance</th>
                                <th>Rewards</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td colspan="5" class="text-center">Match history temporarily unavailable during system upgrade</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Tournaments Section -->
                <div class="content-section" id="tournaments-section">
                    <div class="section-header">
                        <h2>Tournament History</h2>
                        <a href="<?php echo BASE_URL; ?>tournaments/my-registrations.php" class="btn">View All</a>
                    </div>
                    <table>
                        <thead>
                            <tr>
                                <th>Tournament</th>
                                <th>Game</th>
                                <th>Team</th>
                                <th>Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // Get recent tournament history (both solo and team)
                            $recent_tournaments = [];
                            try {
                                // Get solo tournament registrations
                                $solo_regs = $supabaseClient->select(
                                    'tournament_registrations',
                                    '*, tournaments(name, game_name, status)',
                                    [
                                        'user_id' => $user_id,
                                        'team_id' => 'is.null'
                                    ],
                                    'registration_date.desc',
                                    3
                                );
                                
                                if ($solo_regs) {
                                    foreach ($solo_regs as $reg) {
                                        $tournament_info = $reg['tournaments'];
                                        $tournament_info['registration_type'] = 'Solo';
                                        $tournament_info['registration_status'] = $reg['status'];
                                        $tournament_info['registration_date'] = $reg['registration_date'];
                                        $tournament_info['team_name'] = 'Solo Player';
                                        $recent_tournaments[] = $tournament_info;
                                    }
                                }
                                
                                // Get team tournament registrations if user has team
                                if ($has_team) {
                                    $team_regs = $supabaseClient->select(
                                        'tournament_registrations',
                                        '*, tournaments(name, game_name, status), teams(name)',
                                        [
                                            'team_id' => $team_id
                                        ],
                                        'registration_date.desc',
                                        3
                                    );
                                    
                                    if ($team_regs) {
                                        foreach ($team_regs as $reg) {
                                            $tournament_info = $reg['tournaments'];
                                            $tournament_info['registration_type'] = 'Team';
                                            $tournament_info['registration_status'] = $reg['status'];
                                            $tournament_info['registration_date'] = $reg['registration_date'];
                                            $tournament_info['team_name'] = $reg['teams']['name'];
                                            $recent_tournaments[] = $tournament_info;
                                        }
                                    }
                                }
                                
                                // Sort by registration date
                                usort($recent_tournaments, function($a, $b) {
                                    return strtotime($b['registration_date']) - strtotime($a['registration_date']);
                                });
                                
                                // Keep only the 5 most recent
                                $recent_tournaments = array_slice($recent_tournaments, 0, 5);
                                
                            } catch (Exception $e) {
                                error_log("Dashboard: Error fetching tournament history: " . $e->getMessage());
                                $recent_tournaments = [];
                            }
                            
                            if (!empty($recent_tournaments)):
                                foreach ($recent_tournaments as $tournament):
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($tournament['name']); ?></td>
                                    <td><?php echo htmlspecialchars($tournament['game_name']); ?></td>
                                    <td>
                                        <span class="<?php echo strtolower($tournament['registration_type']); ?>-badge">
                                            <?php echo htmlspecialchars($tournament['team_name']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="status-badge <?php echo strtolower($tournament['registration_status']); ?>">
                                            <?php echo ucfirst($tournament['registration_status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('M d, Y', strtotime($tournament['registration_date'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center">No tournament history found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- ====== ionicons ======= -->
    <script type="module" src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@5.5.2/dist/ionicons/ionicons.js"></script>
    
    <!-- Dashboard JavaScript -->
    <script src="<?php echo BASE_URL; ?>dashboard/dashboard.js"></script>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const labelBtns = document.querySelectorAll('.label-btn');
            const contentSections = document.querySelectorAll('.content-section');

            labelBtns.forEach(btn => {
                btn.addEventListener('click', () => {
                    // Remove active class from all buttons and sections
                    labelBtns.forEach(b => b.classList.remove('active'));
                    contentSections.forEach(s => s.classList.remove('active'));

                    // Add active class to clicked button and corresponding section
                    btn.classList.add('active');
                    const sectionId = btn.getAttribute('data-section') + '-section';
                    document.getElementById(sectionId).classList.add('active');
                });
            });
        });
    </script>
</body>

</html> 