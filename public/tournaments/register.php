<?php
ob_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('SECURE_ACCESS', true);
require_once '../secure_config.php';
loadSecureConfig('supabase.php');
loadSecureInclude('SupabaseClient.php');
loadSecureInclude('auth.php');
require_once 'tournament_validator.php';

// Function to handle redirects
function redirect($url, $message = '', $type = 'error') {
    if ($message) {
        $_SESSION[$type] = $message;
    }
    header("Location: $url");
    exit();
}

// Initialize AuthManager and SupabaseClient
$authManager = new AuthManager();
$supabaseClient = new SupabaseClient();

// Check if user is logged in
if (!$authManager->isLoggedIn()) {
    // Create back URL for login redirect
    $currentUrl = $_SERVER['REQUEST_URI'];
    $loginUrl = '../register/login.php?redirect=' . urlencode($currentUrl);
    redirect($loginUrl, 'Please login to register for tournaments.');
}

// Check if tournament ID is provided
if (!isset($_GET['id'])) {
    redirect('index.php', 'Invalid tournament ID.');
}

$currentUser = $authManager->getCurrentUser();
$user_id = $currentUser['user_id'];

try {
    // Validate tournament and user
    $validation = validateTournament($supabaseClient, $_GET['id'], $user_id);
    
    if (!$validation['valid']) {
        redirect('details.php?id=' . $_GET['id'], $validation['error']);
    }

    $tournament = $validation['tournament'];
    $error_message = null;
    $team = null;
    $team_members = [];
    $available_members = [];
    $required_members = ($tournament['mode'] === 'Squad') ? 3 : ($tournament['mode'] === 'Duo' ? 1 : 0);

    // --- GAME PROFILE CHECK ---
    $game_name = $tournament['game_name'];
    $missing_profiles = [];
    if ($tournament['mode'] === 'Solo') {
        $profile = $supabaseClient->select('user_games', '*', ['user_id' => $user_id, 'game_name' => $game_name], null, 1);
        if (empty($profile)) {
            $error_message = "You must add your $game_name game details (username, UID, level) before registering. <a href='../../pages/dashboard/game-profile.php?return=" . urlencode($_SERVER['REQUEST_URI']) . "' class='btn btn-sm btn-primary mt-2'>Add Game Profile</a>";
        }
    } else {
        // For teams, check captain and all available members
        $user_team_data = $supabaseClient->select('team_members', 'team_id', ['user_id' => $user_id, 'status' => 'active'], null, 1);
        if (!empty($user_team_data)) {
            $team_id = $user_team_data[0]['team_id'];
            $team_users = $supabaseClient->select('team_members', 'user_id, role', ['team_id' => $team_id, 'status' => 'active']);
            
            foreach ($team_users as $team_user) {
                $profile = $supabaseClient->select('user_games', '*', ['user_id' => $team_user['user_id'], 'game_name' => $game_name], null, 1);
                if (empty($profile)) {
                    $missing_profiles[] = $team_user['user_id'];
                }
            }
            
            if (!empty($missing_profiles)) {
                // Get usernames for missing profiles
                $users_with_missing_profiles = $supabaseClient->select('users', 'username', ['id.in' => '(' . implode(',', $missing_profiles) . ')']);
                $names = array_column($users_with_missing_profiles, 'username');
                $error_message = "The following team members must add their $game_name game details before registering: <strong>" . htmlspecialchars(implode(', ', $names)) . "</strong>. <br><a href='../../pages/dashboard/game-profile.php?return=" . urlencode($_SERVER['REQUEST_URI']) . "' class='btn btn-sm btn-primary mt-2'>Add Game Profile</a>";
            }
        }
    }

    // For team modes (Duo and Squad), check if user is a team captain or member
    if ($tournament['mode'] !== 'Solo') {
        $team_membership = $supabaseClient->select('team_members', '*, teams(*)', ['user_id' => $user_id, 'status' => 'active'], null, 1);

        if (empty($team_membership)) {
            $error_message = "You need to be part of a team to register for {$tournament['mode']} tournaments. ";
            $error_message .= "<a href='../../pages/teams/create_team.php?redirect=tournament&id={$tournament['id']}' class='create-team-link'>Create or Join a Team</a>";
        } elseif ($team_membership[0]['role'] !== 'captain') {
            $team_id = $team_membership[0]['team_id'];
            $registration = $supabaseClient->select('tournament_registrations', 'status', ['tournament_id' => $tournament['id'], 'team_id' => $team_id, 'status.in' => '("pending","approved")'], null, 1);

            if (!empty($registration)) {
                $status = $registration[0]['status'] === 'approved' ? 'registered' : 'pending approval';
                $error_message = "Your team is already {$status} for this tournament.";
            } else {
                $captain_name = $team_membership[0]['teams']['users']['username'];
                $error_message = "Sorry, only team captains can register for {$tournament['mode']} tournaments.<br><br>";
                $error_message .= "Please contact your team captain <strong>" . htmlspecialchars($captain_name) . "</strong> to register the team.";
                $error_message .= "<br><br><a href='details.php?id=" . $tournament['id'] . "' class='btn btn-secondary'>Go Back</a>";
            }
        } else {
            $team = $team_membership[0]['teams'];
            $team_id = $team['id'];
            
            // Get available team members
            $all_team_members = $supabaseClient->select('team_members', '*, users(id, username, profile_image)', ['team_id' => $team_id, 'status' => 'active', 'role' => 'member', 'user_id.neq' => $user_id]);
            
            $registered_team_members = $supabaseClient->select('tournament_registrations', 'team_id', ['tournament_id' => $tournament['id'], 'status.in' => '("pending","approved")']);
            $registered_team_ids = array_column($registered_team_members, 'team_id');

            $team_members = array_map(function($member) use ($registered_team_ids) {
                $member['is_registered'] = in_array($member['team_id'], $registered_team_ids);
                return $member;
            }, $all_team_members);
            
            $available_members = array_filter($team_members, function($member) {
                return !$member['is_registered'];
            });

            if (empty($team_members)) {
                $error_message = "You need at least {$required_members} team member(s) to register for {$tournament['mode']} mode.";
            } elseif (count($available_members) < $required_members) {
                $error_message = "You need {$required_members} available team member(s). Some of your members are already registered.";
            }
        }
    }

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error_message)) {
        try {
            // Use a transaction if available in your Supabase client, otherwise handle atomicity manually
            $user_tickets_data = $supabaseClient->select('user_tickets', 'tickets', ['user_id' => $user_id], null, 1);
            $available_tickets = !empty($user_tickets_data) ? $user_tickets_data[0]['tickets'] : 0;

            if ($available_tickets < $tournament['entry_fee']) {
                throw new Exception("You don't have enough tickets. Required: {$tournament['entry_fee']}, Available: {$available_tickets}");
            }
            
            $supabaseClient->update('user_tickets', ['tickets' => $available_tickets - $tournament['entry_fee']], ['user_id' => $user_id]);

            if ($tournament['mode'] === 'Solo') {
                $supabaseClient->insert('tournament_registrations', [
                    'tournament_id' => $tournament['id'],
                    'user_id' => $user_id,
                    'status' => 'pending'
                ]);

                $user_team = $supabaseClient->select('team_members', 'teams(id)', ['user_id' => $user_id, 'status' => 'active'], null, 1);
                if (!empty($user_team)) {
                    $team_id = $user_team[0]['teams']['id'];
                    $supabaseClient->rpc('increment_team_score', ['team_id_param' => $team_id, 'increment_by' => 5]);
                }
            } else {
                if (!isset($_POST['teammates']) || !is_array($_POST['teammates'])) {
                    throw new Exception("Please select your teammate(s).");
                }

                $selected_count = count($_POST['teammates']);
                if ($selected_count !== $required_members) {
                    throw new Exception("Please select exactly {$required_members} teammate(s).");
                }

                $selected_teammates_ids = $_POST['teammates'];
                $selected_teammates = array_filter($available_members, function($member) use ($selected_teammates_ids) {
                    return in_array($member['user_id'], $selected_teammates_ids);
                });

                if (count($selected_teammates) !== $required_members) {
                    throw new Exception("Invalid teammate selection or some teammates are already registered.");
                }

                $supabaseClient->insert('tournament_registrations', [
                    'tournament_id' => $tournament['id'],
                    'team_id' => $team['id'],
                    'status' => 'pending'
                ]);

                $supabaseClient->rpc('increment_team_score', ['team_id_param' => $team['id'], 'increment_by' => 5]);
            }

            $supabaseClient->rpc('increment_tournament_teams', ['tournament_id_param' => $tournament['id']]);

            redirect('my-registrations.php', 'Registration submitted successfully and is pending admin approval!', 'success');

        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    }

} catch (Exception $e) {
    error_log("Registration error: " . $e->getMessage());
    redirect('index.php', 'An error occurred. Please try again later.');
}

loadSecureInclude('header.php');
?>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/tournament/registration.css">

<main>
    <section class="registration-section">
        <div class="container">
            <div class="row justify-content-center">
                <div class="col-md-8">
<?php if (isset($error_message)): ?>
                        <div class="card registration-error-container">
                             <div class="card-body text-center">
                                <ion-icon name="alert-circle-outline" class="error-icon"></ion-icon>
                                <h2 class="error-heading">Registration Blocked</h2>
                                <p class="error-message"><?php echo $error_message; ?></p>

                                <?php if ($tournament['mode'] !== 'Solo' && (!isset($team_info) || ($team_info['role'] === 'captain' && isset($team_members)))): ?>
                                    <hr class="my-4">
                                    <div class="guidance text-start">
                                        <h5 class="guidance-heading">What to do next:</h5>
                                        <ul class="guidance-list">
                                            <?php if (!isset($team_info)): ?>
                                                <li>Create a team or join an existing one.</li>
                                                <li>Ensure you are the team captain to register.</li>
                                            <?php elseif (empty($team_members)): ?>
                                                <li>Add at least <?php echo $required_members; ?> member(s) to your team.</li>
                                                <li>Make sure your members have accepted their invitations.</li>
                                            <?php else: ?>
                                                <li>Ensure you have <?php echo $required_members; ?> available member(s).</li>
                                                <li>Check if members are already registered in another team.</li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                                 <a href="details.php?id=<?php echo $tournament['id']; ?>" class="btn btn-secondary mt-4">Back to Tournament</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="card">
                            <div class="card-header">
                                <h2><?php echo $tournament['mode']; ?> Registration</h2>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="registrationForm">
                                    <div class="tournament-info">
                                        <h4><?php echo htmlspecialchars($tournament['name']); ?></h4>
                                        <p class="text-muted"><?php echo htmlspecialchars($tournament['game_name']); ?></p>
                                        <div class="details">
                                            <div class="entry-fee">
                                                <ion-icon name="ticket-outline"></ion-icon>
                                                <span><?php echo $tournament['entry_fee']; ?> Tickets</span>
                                            </div>
                                            <div class="prize-pool">
                                                <ion-icon name="trophy-outline"></ion-icon>
                                                <span><?php 
                                                    echo $tournament['prize_currency'] === 'USD' ? '$' : '₹';
                                                    echo number_format($tournament['prize_pool'], 2); 
                                                ?></span>
                                            </div>
                                        </div>
                                    </div>

                                    <?php if ($tournament['mode'] !== 'Solo'): ?>
                                        <div class="mb-4">
                                            <label class="form-label">Your Team</label>
                                            <div class="team-info">
                                                <h4><?php echo htmlspecialchars($team['name']); ?></h4>
                                                <span class="badge bg-primary">Captain</span>
                                            </div>
                                        </div>

                                        <div class="mb-4">
                                            <label class="form-label">Select <?php echo $required_members; ?> Teammate<?php echo $required_members > 1 ? 's' : ''; ?></label>
                                            <div class="team-members-grid">
                                                <?php foreach ($team_members as $member): ?>
                                                    <div class="member-card <?php echo $member['is_registered'] ? 'unavailable' : ''; ?>">
                                                        <input type="<?php echo $tournament['mode'] === 'Squad' ? 'checkbox' : 'radio'; ?>" 
                                                               name="teammates<?php echo $tournament['mode'] === 'Squad' ? '[]' : ''; ?>" 
                                                               value="<?php echo $member['user_id']; ?>"
                                                               class="member-checkbox"
                                                               <?php echo $member['is_registered'] ? 'disabled' : ''; ?>>
                                                        <?php
                                                        $profile_image = $member['users']['profile_image'] ?? null;
                                                        if (empty($profile_image)) {
                                                            $default_img = $supabaseClient->select('profile_images', 'image_path', ['is_default' => true, 'is_active' => true], null, 1);
                                                            $profile_image = !empty($default_img) ? $default_img[0]['image_path'] : '../../assets/images/guest-icon.png';
                                                        }
                                                        if (!filter_var($profile_image, FILTER_VALIDATE_URL) && strpos($profile_image, '/KGX') !== 0) {
                                                            $profile_image = '/KGX' . $profile_image;
                                                        }
                                                        ?>
                                                        <img src="<?php echo htmlspecialchars($profile_image); ?>" 
                                                             alt="<?php echo htmlspecialchars($member['users']['username']); ?>"
                                                             class="member-avatar"
                                                             onerror="this.src='../../assets/images/guest-icon.png'">
                                                        <span class="member-name">
                                                            <?php echo htmlspecialchars($member['users']['username']); ?>
                                                        </span>
                                                        <?php if ($member['is_registered']): ?>
                                                            <span class="badge bg-warning">Registered</span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="confirmation-message text-center mb-4">
                                            <p>You are registering as:</p>
                                            <div class="user-info">
                                                <strong><?php echo htmlspecialchars($currentUser['username']); ?></strong>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="d-grid gap-2 mt-4">
                                        <button type="submit" class="btn btn-primary">Confirm Registration</button>
                                        <a href="details.php?id=<?php echo $tournament['id']; ?>" class="btn btn-secondary">Cancel</a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </section>
</main>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('registrationForm');
    if (!form) return;

    <?php if ($tournament['mode'] === 'Squad'): ?>
    const checkboxes = form.querySelectorAll('.member-checkbox:not([disabled])');
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const selectedCount = form.querySelectorAll('.member-checkbox:checked').length;
            if (selectedCount > <?php echo $required_members; ?>) {
                this.checked = false;
                alert('You can only select <?php echo $required_members; ?> teammates for squad mode.');
            }
            this.closest('.member-card').classList.toggle('selected', this.checked);
        });
    });

    form.addEventListener('submit', function(e) {
        const selectedCount = form.querySelectorAll('.member-checkbox:checked').length;
        if (selectedCount !== <?php echo $required_members; ?>) {
            e.preventDefault();
            alert('Please select exactly <?php echo $required_members; ?> teammates for squad mode.');
        }
    });
    <?php elseif ($tournament['mode'] === 'Duo'): ?>
    const radioButtons = form.querySelectorAll('.member-checkbox:not([disabled])');
    radioButtons.forEach(radio => {
        radio.addEventListener('change', function() {
            document.querySelectorAll('.member-card').forEach(card => {
                card.classList.remove('selected');
            });
            if (this.checked) {
                this.closest('.member-card').classList.add('selected');
            }
        });
    });

    form.addEventListener('submit', function(e) {
        const selectedTeammate = form.querySelector('.member-checkbox:checked');
        if (!selectedTeammate) {
            e.preventDefault();
            alert('Please select one teammate for duo mode.');
        }
    });
    <?php endif; ?>
});
</script>

<?php 
loadSecureInclude('footer.php');
ob_end_flush();
?> 
