﻿<?php
define('SECURE_ACCESS', true);
require_once '../secure_config.php';

// Load secure configurations and includes
loadSecureInclude('header.php');
loadSecureConfig('supabase.php');
loadSecureInclude('user-auth.php');

// Initialize SupabaseClient
$supabaseClient = new SupabaseClient();

// Get team ID from URL parameter
$team_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$user_id = $_SESSION['user_id'];

// Verify if user is the captain of this team
$captain_check = $supabaseClient->select('teams', '*', ['id' => $team_id, 'captain_id' => $user_id]);
$team = count($captain_check) > 0 ? $captain_check[0] : null;

if (!$team) {
    header('Location: yourteams.php');
    exit();
}

// Fetch available banners
$banners = $supabaseClient->select('team_banners', '*');
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Team - <?php echo htmlspecialchars($team['name']); ?></title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/teams/captain.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <div class="edit-container">
        <h2>Edit Team Settings</h2>
        <div id="errorMessage" class="error-message"></div>
        
        <form id="editTeamForm">
            <input type="hidden" name="team_id" value="<?php echo $team_id; ?>">
            
            <div class="form-group">
                <label for="teamName">
                    <i class="fas fa-users"></i> Team Name
                </label>
                <input type="text" 
                       id="teamName" 
                       name="name" 
                       value="<?php echo htmlspecialchars($team['name']); ?>" 
                       placeholder="Enter team name"
                       required>
            </div>

            <div class="form-group">
                <label for="teamLogo">
                    <i class="fas fa-image"></i> Team Avatar
                </label>
                <div class="logo-preview-container">
                    <img src="<?php echo htmlspecialchars($team['logo']); ?>" 
                         alt="Current Team Logo" 
                         class="current-logo"
                         onerror="this.src='assets/images/default-avatar.png'">
                    <input type="url" 
                           id="teamLogo" 
                           name="logo" 
                           value="<?php echo htmlspecialchars($team['logo']); ?>" 
                           placeholder="Enter team avatar URL"
                           required>
                </div>
                <div id="logoPreview" class="logo-preview"></div>
            </div>

            <div class="form-group">
                <label>
                    <i class="fas fa-image"></i> Team Banner
                </label>
                <div class="banner-grid">
                    <?php foreach ($banners as $banner): ?>
                    <div class="banner-option <?php echo ($team['banner_id'] == $banner['id']) ? 'selected' : ''; ?>" 
                         onclick="selectBanner(this)">
                        <img src="<?php echo htmlspecialchars($banner['image_path']); ?>" 
                             alt="<?php echo htmlspecialchars($banner['name']); ?>">
                        <input type="radio" 
                               name="banner_id" 
                               value="<?php echo $banner['id']; ?>" 
                               <?php echo ($team['banner_id'] == $banner['id']) ? 'checked' : ''; ?> 
                               required>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label for="teamLanguage">
                    <i class="fas fa-language"></i> Team Language
                </label>
                <select id="teamLanguage" name="language" required>
                    <option value="English" <?php echo ($team['language'] == 'English') ? 'selected' : ''; ?>>English</option>
                    <option value="Hindi" <?php echo ($team['language'] == 'Hindi') ? 'selected' : ''; ?>>Hindi</option>
                    <option value="Spanish" <?php echo ($team['language'] == 'Spanish') ? 'selected' : ''; ?>>Spanish</option>
                    <option value="French" <?php echo ($team['language'] == 'French') ? 'selected' : ''; ?>>French</option>
                    <option value="German" <?php echo ($team['language'] == 'German') ? 'selected' : ''; ?>>German</option>
                    <option value="Urdu" <?php echo ($team['language'] == 'Urdu') ? 'selected' : ''; ?>>Urdu</option>
                </select>
            </div>

            <div class="form-group">
                <label for="maxMembers">
                    <i class="fas fa-users-cog"></i> Maximum Team Members
                </label>
                <input type="number" 
                       id="maxMembers" 
                       name="max_members" 
                       value="<?php echo htmlspecialchars($team['max_members']); ?>" 
                       min="2" 
                       max="7" 
                       required>
                <small class="form-text">Team size must be between 2 and 7 members (including captain)</small>
            </div>

            <div class="form-actions">
                <button type="button" class="delete-btn" onclick="confirmDelete()">
                    <i class="fas fa-trash-alt"></i> Delete Team
                </button>
                <button type="submit" class="save-btn">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </form>
    </div>

    <script>
    function selectBanner(element) {
        document.querySelectorAll('.banner-option').forEach(option => {
            option.classList.remove('selected');
        });
        element.classList.add('selected');
        element.querySelector('input[type="radio"]').checked = true;
    }

    document.getElementById('teamLogo').addEventListener('input', function() {
        const preview = document.getElementById('logoPreview');
        const url = this.value.trim();
        
        if (url) {
            preview.style.display = 'block';
            preview.innerHTML = `<img src="${url}" alt="New Team Logo" onerror="this.src='assets/images/default-avatar.png'">`;
        } else {
            preview.style.display = 'none';
        }
    });

    function confirmDelete() {
        if (confirm('Are you sure you want to delete this team? This action cannot be undone.')) {
            deleteTeam();
        }
    }

    function deleteTeam() {
        const formData = new FormData();
        formData.append('team_id', <?php echo $team_id; ?>);
        
        fetch('teams/delete_team.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.location.href = 'teams/yourteams.php';
            } else {
                showError(data.message || 'Error deleting team');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showError('An error occurred while deleting the team');
        });
    }

    function showError(message) {
        const errorDiv = document.getElementById('errorMessage');
        errorDiv.textContent = message;
        errorDiv.style.display = 'block';
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
            errorDiv.style.display = 'none';
        }, 5000);
    }

    document.getElementById('editTeamForm').addEventListener('submit', function(e) {
        e.preventDefault();
        const formData = new FormData(this);
        
        fetch('teams/update_team.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.location.href = 'teams/yourteams.php';
            } else {
                showError(data.message || 'Error updating team');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            showError('An error occurred while updating the team');
        });
    });
    </script>
</body>
</html>

