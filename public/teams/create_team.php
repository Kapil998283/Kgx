<?php
ob_start();
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

// Function to handle redirects
function redirect($url, $message = '', $type = 'error') {
    if ($message) {
        $_SESSION[$type] = $message;
    }
    if (headers_sent()) {
        echo "<script>window.location.href='$url';</script>";
        echo '<noscript><meta http-equiv="refresh" content="0;url='.$url.'"</noscript>';
        exit();
    } else {
        ob_end_clean();
        header("Location: $url");
        exit();
    }
}

// Check if user is logged in
if (!$authManager->isLoggedIn()) {
    redirect(BASE_URL . 'register/login.php', 'Please login first', 'error_message');
}

$currentUser = $authManager->getCurrentUser();

// Check if user is already a member or captain of any team
require_once 'check_team_status.php';
$user_status = checkTeamStatus($supabaseClient, $currentUser['user_id']);

if ($user_status['is_member']) {
    redirect(BASE_URL . 'teams/yourteams.php', $user_status['message'], 'error_message');
}

// Get available banners using Supabase REST API
try {
    $banners = $supabaseClient->select('team_banners', '*', ['is_active' => true]);
} catch (Exception $e) {
    error_log('Error fetching banners: ' . $e->getMessage());
    $banners = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limiting: Check for recent team creation attempts
    $rate_limit_key = 'team_creation_' . $currentUser['user_id'];
    $last_attempt = $_SESSION[$rate_limit_key] ?? 0;
    $time_diff = time() - $last_attempt;
    
    if ($time_diff < 60) { // 1 minute cooldown
        $_SESSION['error_message'] = 'Please wait ' . (60 - $time_diff) . ' seconds before creating another team.';
        redirect($_SERVER['PHP_SELF']);
    }
    
    $_SESSION[$rate_limit_key] = time();
    
    $name = trim($_POST['name']);
    $logo = trim($_POST['logo']);
    $banner_id = isset($_POST['banner_id']) ? (int)$_POST['banner_id'] : 1;
    $description = trim($_POST['description']);
    $language = trim($_POST['language']);
    $max_members = isset($_POST['max_members']) ? (int)$_POST['max_members'] : 0;

    // Check if user already has a team (as captain) or is a member of another team
    try {
        // Check the number of active teams the user is a captain of
        $captain_teams = $supabaseClient->select('teams', 'id, name', [
            'captain_id' => $currentUser['user_id'],
            'is_active' => true
        ]);
        $captain_count = count($captain_teams);

        // Check the number of teams the user is currently a member of
        $member_teams = $supabaseClient->select('team_members', 'team_id', [
            'user_id' => $currentUser['user_id'],
            'status' => 'active'
        ]);
        $member_count = count($member_teams);
        
        // Get team name if user is already a member
        $existing_team_name = '';
        if ($member_count > 0) {
            try {
                $team_info = $supabaseClient->select('teams', 'name', [
                    'id' => $member_teams[0]['team_id']
                ]);
                $existing_team_name = !empty($team_info) ? $team_info[0]['name'] : 'Unknown Team';
            } catch (Exception $e) {
                $existing_team_name = 'an existing team';
            }
        }
        
    } catch (Exception $e) {
        error_log('Error checking user team status: ' . $e->getMessage());
        $_SESSION['error_message'] = 'Unable to verify your current team status. Please try again later.';
        redirect($_SERVER['PHP_SELF']);
    }

    if ($captain_count >= 1) {
        $captain_team_name = !empty($captain_teams) ? $captain_teams[0]['name'] : 'your existing team';
        $_SESSION['error_message'] = "You are already the captain of '{$captain_team_name}'. You can only be the captain of one team at a time. Please manage your existing team or leave it first.";
    } elseif ($member_count >= 1) {
        $_SESSION['error_message'] = "You are already a member of '{$existing_team_name}'. You must leave your current team before creating a new one. Go to 'My Teams' to manage your memberships.";
    }
    // Validate inputs
    elseif (strlen($name) < 3) {
        $_SESSION['error_message'] = 'Team name must be at least 3 characters long';
    } elseif (strlen($name) > 50) {
        $_SESSION['error_message'] = 'Team name cannot exceed 50 characters';
    } elseif ($max_members < 2 || $max_members > 7) {
        $_SESSION['error_message'] = 'Team size must be between 2 and 7 members';
    } elseif (empty($logo)) {
        $_SESSION['error_message'] = 'Team logo URL is required';
    } elseif (empty($description)) {
        $_SESSION['error_message'] = 'Team description is required';
    } elseif (empty($language)) {
        $_SESSION['error_message'] = 'Team language is required';
    } else {
        try {
            // Check if team name already exists (case-insensitive)
            $existing_team_names = $supabaseClient->select('teams', 'name', ['is_active' => true]);
            $name_exists = false;
            foreach ($existing_team_names as $team) {
                if (strtolower($team['name']) === strtolower($name)) {
                    $name_exists = true;
                    break;
                }
            }
            
            if ($name_exists) {
                throw new Exception('This team name is already taken. Please choose a different name.');
            }

            // Create team using Supabase REST API
            $team_data = [
                'name' => htmlspecialchars($name),
                'logo' => filter_var($logo, FILTER_SANITIZE_URL),
                'banner_id' => $banner_id,
                'description' => htmlspecialchars($description),
                'language' => htmlspecialchars($language),
                'max_members' => $max_members,
                'captain_id' => $currentUser['user_id'],
                'is_active' => true,
                'created_at' => date('c')
            ];
            
            // Log the team data being inserted for debugging
            error_log('Creating team with data: ' . json_encode($team_data));
            
            $result = $supabaseClient->insert('teams', $team_data);
            
            if (empty($result) || !isset($result[0]['id'])) {
                throw new Exception('Team creation failed, please try again.');
            }
            
            $team_id = $result[0]['id'];

            // Automatically add the captain as a team member
            $add_captain_as_member = [
                'team_id' => $team_id,
                'user_id' => $currentUser['user_id'],
                'role' => 'captain',
                'status' => 'active'
            ];
            $supabaseClient->insert('team_members', $add_captain_as_member);

            $_SESSION['success_message'] = "Team '" . htmlspecialchars($name) . "' created successfully!";
            redirect(BASE_URL . 'teams/yourteams.php');

        } catch (Exception $e) {
            $_SESSION['error_message'] = $e->getMessage();
        }
    }
    
    if (isset($_SESSION['error_message'])) {
        redirect($_SERVER['PHP_SELF']);
    }
}
?>


<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/teams/create-team.css">

<main>
    <article>
        <section class="teams-section">
            <div class="container">
                <h2 class="section-title">Create Your Team</h2>
                
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger" id="errorAlert">
                        <div class="alert-content">
                            <i class="fas fa-exclamation-circle alert-icon"></i>
                            <div class="alert-message">
                                <?php 
                                echo htmlspecialchars($_SESSION['error_message']);
                                unset($_SESSION['error_message']);
                                ?>
                            </div>
                        </div>
                        <button type="button" class="alert-close" onclick="closeAlert('errorAlert')">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success" id="successAlert">
                        <div class="alert-content">
                            <i class="fas fa-check-circle alert-icon"></i>
                            <div class="alert-message">
                                <?php 
                                echo htmlspecialchars($_SESSION['success_message']);
                                unset($_SESSION['success_message']);
                                ?>
                            </div>
                        </div>
                        <button type="button" class="alert-close" onclick="closeAlert('successAlert')">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                <?php endif; ?>

                <form method="POST" class="team-form" onsubmit="return validateForm()">
                    <div class="form-group">
                        <label for="name">Team Name</label>
                        <input type="text" id="name" name="name" required minlength="3" maxlength="100" 
                               oninput="checkTeamName(this.value)">
                        <div id="nameStatus" class="validation-message"></div>
                    </div>

                    <div class="form-group">
                        <label for="logo">Team Logo URL</label>
                        <input type="url" id="logo" name="logo" required>
                    </div>

                    <div class="form-group">
                        <label>Team Banner</label>
                        <div class="banner-selection-grid">
                            <?php foreach ($banners as $banner): ?>
                                <div class="banner-radio-option">
                                    <input type="radio" 
                                           name="banner_id" 
                                           id="banner_<?php echo $banner['id']; ?>" 
                                           value="<?php echo $banner['id']; ?>" 
                                           <?php echo ($banner === reset($banners)) ? 'checked' : ''; ?>>
                                    <label for="banner_<?php echo $banner['id']; ?>" class="banner-label">
                                        <img src="<?php echo htmlspecialchars($banner['image_path']); ?>" 
                                             alt="<?php echo htmlspecialchars($banner['name']); ?>">
                                        <div class="banner-select-indicator">
                                            <i class="bi bi-check-circle-fill"></i>
                                        </div>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div id="bannerError" class="validation-message"></div>
                    </div>

                    <div class="form-group">
                        <label for="description">Team Description</label>
                        <textarea id="description" name="description" rows="4" required></textarea>
                    </div>

                    <div class="form-group">
                        <label for="language">Team Language</label>
                        <select id="language" name="language" required>
                            <option value="">Select Language</option>
                            <option value="English">English</option>
                            <option value="Hindi">Hindi</option>
                            <option value="Arabic">Arabic</option>
                            <option value="Urdu">Urdu</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="max_members">Maximum Team Members</label>
                        <select id="max_members" name="max_members" required>
                            <option value="">Select Members</option>
                            <option value="2">2</option>
                            <option value="3">3</option>
                            <option value="4">4</option>
                            <option value="5">5</option>
                            <option value="6">6</option>
                            <option value="7">7</option>
                        </select>
                        <small class="form-text text-muted">Maximum 7 members (8 total including captain)</small>
                    </div>

                    <button type="submit" class="btn btn-primary">Create Team</button>
                </form>
            </div>
        </section>
    </article>
</main>

<script>
let checkTimeout;
let isValidName = false;

function checkTeamName(name) {
    const nameStatus = document.getElementById('nameStatus');
    
    // Clear previous timeout and status
    clearTimeout(checkTimeout);
    nameStatus.textContent = '';
    nameStatus.className = 'validation-message';
    isValidName = false;
    
    if (name.length < 3) {
        nameStatus.textContent = '✕ Team name must be at least 3 characters long';
        nameStatus.classList.add('error');
        return;
    }
    
    // Show checking message
    nameStatus.textContent = 'Checking availability...';
    
    // Wait for user to stop typing before checking
    checkTimeout = setTimeout(() => {
        fetch('<?php echo BASE_URL; ?>teams/check_team_name.php?name=' + encodeURIComponent(name))
            .then(response => response.text())
            .then(result => {
                result = result.trim();
                if (result === 'available') {
                    nameStatus.textContent = '✓ Team name is available';
                    nameStatus.classList.add('success');
                    isValidName = true;
                } else if (result === 'taken') {
                    nameStatus.textContent = '✕ This team name is already taken';
                    nameStatus.classList.add('error');
                    isValidName = false;
                } else {
                    nameStatus.textContent = '✕ ' + result;
                    nameStatus.classList.add('error');
                    isValidName = false;
                }
            })
            .catch(error => {
                nameStatus.textContent = '✕ Error checking team name';
                nameStatus.classList.add('error');
                isValidName = false;
            });
    }, 500);
}

function validateForm() {
    const nameStatus = document.getElementById('nameStatus');
    let isValid = true;

    // Check team name
    if (!isValidName || nameStatus.classList.contains('error')) {
        alert('Please choose a valid team name');
        return false;
    }

    // Check other required fields
    const requiredFields = ['logo', 'description', 'language', 'max_members'];
    for (const field of requiredFields) {
        const element = document.getElementById(field);
        if (!element.value.trim()) {
            alert(`Please fill in the ${field.replace('_', ' ')}`);
            element.focus();
            return false;
        }
    }

    return true;
}

// Alert management functions
function closeAlert(alertId) {
    const alert = document.getElementById(alertId);
    if (alert) {
        alert.classList.add('fade-out');
        setTimeout(() => {
            alert.remove();
        }, 300);
    }
}

// Auto-hide alerts after 7 seconds
function autoHideAlerts() {
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach(alert => {
        setTimeout(() => {
            if (alert && !alert.classList.contains('fade-out')) {
                alert.classList.add('fade-out');
                setTimeout(() => {
                    if (alert.parentNode) {
                        alert.remove();
                    }
                }, 300);
            }
        }, 7000); // 7 seconds
    });
}

// Initialize alert functionality when page loads
document.addEventListener('DOMContentLoaded', function() {
    autoHideAlerts();
});
</script>

<script src="<?php echo BASE_URL; ?>assets/js/teams.js"></script>

<?php loadSecureInclude('footer.php'); ?>
