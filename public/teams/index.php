<?php
// Only start session if one isn't already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
define('SECURE_ACCESS', true);
require_once '../secure_config.php';
loadSecureConfig('supabase.php');
loadSecureInclude('auth.php');
loadSecureInclude('header.php');

require_once 'check_team_status.php';

// Initialize AuthManager and SupabaseClient
$authManager = new AuthManager();
$supabaseClient = new SupabaseClient();

// Check user's team status if logged in
$user_status = ['is_member' => false];
$currentUser = null;
if ($authManager->isLoggedIn()) {
    $currentUser = $authManager->getCurrentUser();
    // Check if user is already a member of any team
    $user_status = checkTeamStatus($supabaseClient, $currentUser['user_id']);
}

// Get filter parameters
$skill_filter = isset($_GET['skill']) ? $_GET['skill'] : '';
$game_filter = isset($_GET['game']) ? $_GET['game'] : '';
$region_filter = isset($_GET['region']) ? $_GET['region'] : '';
$availability_filter = isset($_GET['availability']) ? $_GET['availability'] : '';

// Use the enhanced team discovery view for better performance and features
try {
    // Build filter conditions for the view
    $filters = ['availability_status.neq' => 'empty']; // Exclude empty teams by default
    
    if ($skill_filter) {
        $filters['skill_level'] = $skill_filter;
    }
    if ($game_filter) {
        $filters['preferred_game'] = $game_filter;
    }
    if ($region_filter) {
        $filters['region'] = $region_filter;
    }
    if ($availability_filter === 'available') {
        $filters['availability_status'] = 'available';
    } elseif ($availability_filter === 'full') {
        $filters['availability_status'] = 'full';
    }
    
    // Query the enhanced team discovery view
    $teams = $supabaseClient->select('team_discovery', '*', $filters, 'win_rate.desc,created_at.desc');
    
} catch (Exception $e) {
    error_log("Error fetching teams from discovery view: " . $e->getMessage());
    // Fallback to direct table query if view fails
    try {
        $teams = $supabaseClient->select('teams', '*', ['is_active' => true, 'is_private' => false], 'created_at.desc');
        
        // For each team, get captain name and member count (fallback)
        foreach ($teams as &$team) {
            $captains = $supabaseClient->select('users', 'username', ['id' => $team['captain_id']]);
            $team['captain_username'] = !empty($captains) ? $captains[0]['username'] : 'Unknown';
            
            $members = $supabaseClient->select('team_members', 'id', ['team_id' => $team['id'], 'status' => 'active']);
            $member_count = count($members);
            
            $team['current_members'] = $member_count;
            $team['available_spots'] = $team['max_members'] - $team['current_members'];
            $team['win_rate'] = 0; // Default for fallback
        }
    } catch (Exception $e2) {
        error_log("Error with fallback team query: " . $e2->getMessage());
        $teams = [];
    }
}

// Get user's pending requests if logged in
$pending_requests = [];
if ($authManager->isLoggedIn() && $currentUser) {
    try {
        $requests = $supabaseClient->select('team_join_requests', 'team_id', [
            'user_id' => $currentUser['user_id'],
            'status' => 'pending'
        ]);
        $pending_requests = array_column($requests, 'team_id');
    } catch (Exception $e) {
        error_log("Error fetching user requests: " . $e->getMessage());
        $pending_requests = [];
    }
}

// Get available games, skills, and regions for filters
try {
    $available_games = $supabaseClient->select('games', 'name', ['status' => 'active']);
    $games_list = array_column($available_games, 'name');
} catch (Exception $e) {
    $games_list = ['BGMI', 'PUBG', 'Free Fire', 'Call of Duty Mobile'];
}

$skills_list = ['beginner', 'intermediate', 'advanced', 'pro'];
$regions_list = ['Asia', 'Europe', 'North America', 'South America', 'Africa', 'Oceania'];

// Check for success message
$success_message = '';
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']); // Clear the message after displaying
}
?>

<!-- Add Teams CSS -->
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/teams/index.css">

<main>
    <article>
        <!-- Team Section -->
        <section class="teams-section">
            <div class="container">
                <h2 class="section-title">Find Teams</h2>

                <!-- Search Bar -->
                <div class="search-bar">
                    <input type="text" id="team-search" placeholder="Search for a team...">
                    <button class="search-btn"><i class="fas fa-search"></i></button>
                </div>

                <div class="team-cards-container">
                    <?php if (!$authManager->isLoggedIn()): ?>
                        <!-- Sign in to Create Team Box for Non-logged-in Users -->
                        <div class="team-card create-box signin-create" onclick="window.location.href='../register/login.php?redirect=teams/create_team.php'">
                            <div class="create-image">
                                <i class="fas fa-user-plus"></i>
                            </div>
                            <h3>Sign in to create your own team</h3>
                            <button class="rc-btn signin-btn">Sign In to Create</button>
                        </div>
                    <?php elseif ($authManager->isLoggedIn() && !$user_status['is_member']): ?>
                        <!-- Create Team Box for Logged-in Users who are NOT in any team -->
                        <div class="team-card create-box" onclick="window.location.href='create_team.php'">
                            <div class="create-image">
                                <i class="fas fa-plus"></i>
                            </div>
                            <h3>Create your team and become the captain</h3>
                            <button class="rc-btn">Create Now</button>
                        </div>
                    <?php endif; ?>
                    <!-- Note: Users who are already members or captains will not see any create team section -->

                    <?php if (empty($teams)): ?>
                        <div class="no-teams">
                            No teams found. Be the first to create one!
                        </div>
                    <?php else: ?>
                        <?php foreach ($teams as $team): ?>
                            <div class="team-card">
                                <div class="team-logo">
                                    <?php if (!empty($team['logo'])): ?>
                                        <img src="<?php echo htmlspecialchars($team['logo']); ?>" 
                                             alt="<?php echo htmlspecialchars($team['name']); ?> Logo"
                                             onerror="this.src='../assets/images/default-team.png'">
                                    <?php else: ?>
                                        <img src="../assets/images/default-team.png" alt="Default Team Logo">
                                    <?php endif; ?>
                                </div>
                                <h3><?php echo htmlspecialchars($team['name']); ?></h3>
                                <p><i class="fas fa-users"></i> <?php echo $team['current_members']; ?>/<?php echo $team['max_members']; ?> players</p>
                                <p><i class="fas fa-globe"></i> <?php echo htmlspecialchars($team['language']); ?></p>
                                
                                <?php if ($authManager->isLoggedIn()): ?>
                                    <?php 
                                    $currentUser = $authManager->getCurrentUser();
                                    if ($team['captain_id'] == $currentUser['user_id']):
                                    ?>
                                        <button class="rc-btn disabled" disabled>Your Team</button>
                                    <?php elseif ($user_status['is_member']): ?>
                                        <button class="rc-btn disabled" disabled>Already in a Team</button>
                                    <?php elseif (in_array($team['id'], $pending_requests)): ?>
                                        <form action="cancel_request.php" method="POST" style="width: 100%;">
                                            <?php
                                            // Get the request ID for this team using Supabase
                                            try {
                                                $request_data = $supabaseClient->select('team_join_requests', 'id', [
                                                    'user_id' => $currentUser['user_id'],
                                                    'team_id' => $team['id'],
                                                    'status' => 'pending'
                                                ]);
                                                $request_id = !empty($request_data) ? $request_data[0]['id'] : '';
                                            } catch (Exception $e) {
                                                $request_id = '';
                                            }
                                            ?>
                                            <input type="hidden" name="request_id" value="<?php echo $request_id; ?>">
                                            <button type="submit" class="rc-btn cancel-btn">Cancel Request</button>
                                        </form>
                                    <?php elseif ($team['current_members'] >= $team['max_members']): ?>
                                        <button class="rc-btn disabled" disabled>Team Full</button>
                                    <?php else: ?>
                                        <form action="send_join_request.php" method="POST" style="width: 100%;">
                                            <input type="hidden" name="team_id" value="<?php echo $team['id']; ?>">
                                            <button type="submit" class="rc-btn">Request to Join</button>
                                        </form>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <a href="../register/login.php" class="rc-btn">Login to Join</a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </article>
</main>

<script>
// Add search functionality
document.getElementById('team-search').addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase().trim();
    const teamCards = document.querySelectorAll('.team-card:not(.create-box)');
    const createTeamCard = document.querySelector('.create-box');
    
    // Hide/show create team card based on search
    if (createTeamCard) {
        createTeamCard.style.display = searchTerm === '' ? '' : 'none';
    }

    let hasResults = false;
    
    teamCards.forEach(card => {
        const teamName = card.querySelector('h3').textContent.toLowerCase();
        const teamLang = card.querySelector('.fa-globe').parentNode.textContent.toLowerCase();
        
        if (teamName.includes(searchTerm) || teamLang.includes(searchTerm)) {
            card.style.display = '';
            hasResults = true;
        } else {
            card.style.display = 'none';
        }
    });

    // Show/hide no results message
    let noResultsMsg = document.querySelector('.no-results-message');
    if (!noResultsMsg) {
        noResultsMsg = document.createElement('div');
        noResultsMsg.className = 'no-results-message';
        noResultsMsg.style.cssText = `
            grid-column: 1 / -1;
            text-align: center;
            padding: 2rem;
            color: var(--light-gray);
            font-size: 1.1rem;
        `;
        document.querySelector('.team-cards-container').appendChild(noResultsMsg);
    }
    
    if (!hasResults && searchTerm !== '') {
        noResultsMsg.textContent = 'No teams found matching your search.';
        noResultsMsg.style.display = '';
    } else {
        noResultsMsg.style.display = 'none';
    }
});
</script>

<?php loadSecureInclude('footer.php'); ?>
