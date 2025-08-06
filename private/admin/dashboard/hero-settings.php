<?php
// CRITICAL: Suppress ALL error output to prevent corrupting HTML title
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Set error handler to suppress all output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Hero Settings Error: $errstr in $errfile on line $errline");
    return true; // Don't execute PHP internal error handler
});

// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once dirname(__DIR__) . '/admin_secure_config.php';

// Load admin configuration with error handling
try {
    $adminConfig = loadAdminConfig('admin_config.php');
    if (!$adminConfig || !is_array($adminConfig)) {
        $adminConfig = ['system' => ['name' => 'KGX Admin']];
    }
} catch (Exception $e) {
    error_log("Admin config error: " . $e->getMessage());
    $adminConfig = ['system' => ['name' => 'KGX Admin']];
}

// Use the new admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

// Admin is automatically authenticated by admin-auth.php

// Initialize Supabase connection
$supabase = getSupabaseConnection();

// Function to fetch hero settings
function fetchHeroSettings($supabase) {
    try {
        $hero_settings_data = $supabase->select('hero_settings', '*', ['is_active' => 1], null, 1);
        return !empty($hero_settings_data) ? $hero_settings_data[0] : [];
    } catch (Exception $e) {
        error_log("Hero settings fetch error: " . $e->getMessage());
        return [];
    }
}

// Get current hero settings
$hero_settings = fetchHeroSettings($supabase);

// Initialize variables for messages
$success_message = '';
$error_message = '';

// Handle GET parameters for messages
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $success_message = 'Hero settings updated successfully!';
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Sanitize and validate input data
    $subtitle = trim($_POST['subtitle'] ?? '');
    $title = trim($_POST['title'] ?? '');
    $primary_btn_text = trim($_POST['primary_btn_text'] ?? '');
    $primary_btn_icon = trim($_POST['primary_btn_icon'] ?? '');
    $secondary_btn_text = trim($_POST['secondary_btn_text'] ?? '');
    $secondary_btn_icon = trim($_POST['secondary_btn_icon'] ?? '');
    $secondary_btn_url = trim($_POST['secondary_btn_url'] ?? '');
    
    // Validate required fields
    if (empty($subtitle) || empty($title) || empty($primary_btn_text)) {
        $error_message = "Please fill in all required fields (Subtitle, Title, Primary Button Text).";
    } else {
        try {
            // Get current admin
            $currentAdmin = getCurrentAdmin();
            $admin_id = $currentAdmin ? $currentAdmin['admin_id'] : null; // Fixed: use admin_id instead of id
            
            if (!$admin_id) {
                throw new Exception('Unable to identify current admin user');
            }
            
            // Prepare update data
            $update_data = [
                'subtitle' => $subtitle,
                'title' => $title,
                'primary_btn_text' => $primary_btn_text,
                'primary_btn_icon' => $primary_btn_icon,
                'secondary_btn_text' => $secondary_btn_text,
                'secondary_btn_icon' => $secondary_btn_icon,
                'secondary_btn_url' => $secondary_btn_url,
                'updated_by' => $admin_id,
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            // Update hero settings using Supabase
            $result = $supabase->update('hero_settings', $update_data, ['is_active' => 1]);
            
            // Check if update was successful (Supabase update returns the updated data or throws exception)
            if ($result !== false) {
                try {
                    // Log the action
                    logAdminAction('update_hero_settings', 'Updated hero section settings');
                } catch (Exception $logError) {
                    // Don't fail the update if logging fails, just log the error
                    error_log("Failed to log admin action: " . $logError->getMessage());
                }
                
                // Redirect to prevent form resubmission
                header('Location: hero-settings.php?success=1');
                exit();
            } else {
                $error_message = "Failed to update hero settings. Please try again.";
            }
        } catch (Exception $e) {
            error_log("Hero settings update error: " . $e->getMessage());
            $error_message = "Error updating hero settings: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Hero Section - Admin Dashboard</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="hero-settings.css">
</head>
<body>
    <!-- Header with Back Button -->
    <header class="admin-header">
        <div class="header-content">
            <h1 class="admin-title">Edit Hero Section</h1>
            <a href="index.php" class="back-button" title="Back to Dashboard">
                <i class="bi bi-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </header>
    
    <!-- Main Container -->
    <div class="main-container">
        <h1 class="page-title">Hero Section Settings</h1>
        <p class="page-subtitle">Customize the hero section content displayed on your website</p>
                
        <!-- Success Message -->
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle-fill"></i>
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>
        
        <!-- Error Message -->
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-error">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>
        
        <div class="form-container">
            <form method="POST" action="">
                <div class="form-group">
                    <label for="subtitle" class="form-label">Subtitle</label>
                    <input type="text" class="form-input" id="subtitle" name="subtitle" 
                           value="<?php echo htmlspecialchars($hero_settings['subtitle'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="title" class="form-label">Title</label>
                    <input type="text" class="form-input" id="title" name="title" 
                           value="<?php echo htmlspecialchars($hero_settings['title'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="primary_btn_text" class="form-label">Primary Button Text</label>
                    <input type="text" class="form-input" id="primary_btn_text" name="primary_btn_text" 
                           value="<?php echo htmlspecialchars($hero_settings['primary_btn_text'] ?? ''); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="primary_btn_icon" class="form-label">Primary Button Icon</label>
                    <input type="text" class="form-input" id="primary_btn_icon" name="primary_btn_icon" 
                           value="<?php echo htmlspecialchars($hero_settings['primary_btn_icon'] ?? ''); ?>" placeholder="e.g., bi-play-fill">
                </div>
                
                <div class="form-group">
                    <label for="secondary_btn_text" class="form-label">Secondary Button Text</label>
                    <input type="text" class="form-input" id="secondary_btn_text" name="secondary_btn_text" 
                           value="<?php echo htmlspecialchars($hero_settings['secondary_btn_text'] ?? ''); ?>" placeholder="Optional">
                </div>
                
                <div class="form-group">
                    <label for="secondary_btn_icon" class="form-label">Secondary Button Icon</label>
                    <input type="text" class="form-input" id="secondary_btn_icon" name="secondary_btn_icon" 
                           value="<?php echo htmlspecialchars($hero_settings['secondary_btn_icon'] ?? ''); ?>" placeholder="e.g., bi-info-circle">
                </div>
                
                <div class="form-group">
                    <label for="secondary_btn_url" class="form-label">Secondary Button URL</label>
                    <input type="text" class="form-input" id="secondary_btn_url" name="secondary_btn_url" 
                           value="<?php echo htmlspecialchars($hero_settings['secondary_btn_url'] ?? ''); ?>" placeholder="https://example.com">
                </div>
                
                <div class="button-group">
                    <button type="submit" class="btn btn-primary" id="saveBtn">
                        <i class="bi bi-check-lg"></i>
                        <span class="btn-text">Save Changes</span>
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="resetForm()">
                        <i class="bi bi-arrow-clockwise"></i>
                        Reset
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Store original form values for reset functionality
        const originalValues = {
            subtitle: '<?php echo addslashes($hero_settings['subtitle'] ?? ''); ?>',
            title: '<?php echo addslashes($hero_settings['title'] ?? ''); ?>',
            primary_btn_text: '<?php echo addslashes($hero_settings['primary_btn_text'] ?? ''); ?>',
            primary_btn_icon: '<?php echo addslashes($hero_settings['primary_btn_icon'] ?? ''); ?>',
            secondary_btn_text: '<?php echo addslashes($hero_settings['secondary_btn_text'] ?? ''); ?>',
            secondary_btn_icon: '<?php echo addslashes($hero_settings['secondary_btn_icon'] ?? ''); ?>',
            secondary_btn_url: '<?php echo addslashes($hero_settings['secondary_btn_url'] ?? ''); ?>'
        };
        
        // Form submission with loading state
        document.querySelector('form').addEventListener('submit', function(e) {
            const saveBtn = document.getElementById('saveBtn');
            const btnText = saveBtn.querySelector('.btn-text');
            
            // Show loading state
            if (btnText) {
                btnText.textContent = 'Saving...';
            }
            saveBtn.classList.add('loading');
            saveBtn.disabled = true;
        });
        
        // Reset form to original values
        function resetForm() {
            if (confirm('Are you sure you want to reset all changes?')) {
                for (const [field, value] of Object.entries(originalValues)) {
                    const input = document.querySelector(`[name="${field}"]`);
                    if (input) {
                        input.value = value;
                    }
                }
            }
        }
        
        // Auto-dismiss alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                alert.style.opacity = '0';
                alert.style.transform = 'translateY(-10px)';
                setTimeout(function() {
                    alert.remove();
                }, 300);
            });
        }, 5000);
        
        // Form validation
        document.querySelector('form').addEventListener('input', function(e) {
            const form = e.target.closest('form');
            const saveBtn = document.getElementById('saveBtn');
            const requiredFields = form.querySelectorAll('input[required]');
            let allValid = true;
            
            requiredFields.forEach(function(field) {
                if (!field.value.trim()) {
                    allValid = false;
                }
            });
            
            saveBtn.disabled = !allValid;
        });
        
        // Initialize form validation on page load
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            if (form) {
                // Trigger initial validation
                form.dispatchEvent(new Event('input'));
            }
        });
    </script>
</body>
</html>
