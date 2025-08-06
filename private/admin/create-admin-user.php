<?php
/**
 * Admin User Creation Script
 * This script creates the first admin user for the KGX Admin Panel
 * Run this script once to set up the initial admin account
 */

// Prevent direct access in production
if (!isset($_GET['create_admin']) || $_GET['create_admin'] !== 'true') {
    die('Access denied. This script is for initial setup only.');
}

// Define admin secure access
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once __DIR__ . '/admin_secure_config.php';

// Load admin authentication manager
require_once ADMIN_INCLUDES_PATH . 'AdminAuthManager.php';

// Initialize Supabase connection
$supabase = getSupabaseConnection();

if (!$supabase) {
    die('Error: Could not connect to database. Please check your Supabase configuration.');
}

$message = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $full_name = trim($_POST['full_name'] ?? '');
    $role = $_POST['role'] ?? 'admin';

    // Validation
    if (empty($username) || empty($email) || empty($password) || empty($full_name)) {
        $error = 'All fields are required.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            // Check if admin already exists
            $existingAdmin = $supabase->select('admin_users', '*', ['email' => $email]);
            if (!empty($existingAdmin)) {
                $error = 'An admin user with this email already exists.';
            } else {
                // Check if username already exists
                $existingUsername = $supabase->select('admin_users', '*', ['username' => $username]);
                if (!empty($existingUsername)) {
                    $error = 'An admin user with this username already exists.';
                } else {
                    // Create admin user
                    $adminData = [
                        'username' => $username,
                        'email' => $email,
                        'password' => password_hash($password, PASSWORD_DEFAULT),
                        'full_name' => $full_name,
                        'role' => $role,
                        'created_at' => date('Y-m-d H:i:s'),
                        'is_active' => true
                    ];

                    $result = $supabase->insert('admin_users', $adminData);
                    
                    if ($result !== false) {
                        $message = "Admin user created successfully! You can now login with email: $email";
                        
                        // Log the creation
                        try {
                            $supabase->insert('admin_activity_log', [
                                'admin_id' => 1, // System
                                'action' => 'admin_user_created',
                                'details' => "New admin user created: $username ($email)",
                                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                                'created_at' => date('Y-m-d H:i:s')
                            ]);
                        } catch (Exception $logError) {
                            // Don't fail if logging fails
                            error_log('Failed to log admin creation: ' . $logError->getMessage());
                        }
                    } else {
                        $error = 'Failed to create admin user. Please try again.';
                    }
                }
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
            error_log('Admin creation error: ' . $e->getMessage());
        }
    }
}

// Check if there are existing admin users
$existingAdmins = [];
try {
    $existingAdmins = $supabase->select('admin_users', 'id, username, email, full_name, role, created_at, is_active', []);
} catch (Exception $e) {
    error_log('Error checking existing admins: ' . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Admin User - KGX Admin Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .setup-container {
            max-width: 800px;
            margin: 2rem auto;
            padding: 2rem;
        }
        .setup-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }
        .setup-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        .setup-body {
            padding: 2rem;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 0.75rem 2rem;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%);
        }
        .existing-admins {
            background: #f8f9fa;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 2rem;
        }
        .admin-card {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 0.5rem;
        }
        .badge-role {
            font-size: 0.75rem;
        }
        .alert {
            border-radius: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-card">
            <div class="setup-header">
                <h1><i class="bi bi-shield-check"></i> KGX Admin Setup</h1>
                <p class="mb-0">Create your first admin user to access the admin panel</p>
            </div>
            
            <div class="setup-body">
                <?php if (!empty($existingAdmins)): ?>
                    <div class="existing-admins">
                        <h5><i class="bi bi-people"></i> Existing Admin Users</h5>
                        <?php foreach ($existingAdmins as $admin): ?>
                            <div class="admin-card">
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo htmlspecialchars($admin['full_name']); ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            @<?php echo htmlspecialchars($admin['username']); ?> | 
                                            <?php echo htmlspecialchars($admin['email']); ?>
                                        </small>
                                    </div>
                                    <div>
                                        <span class="badge badge-role <?php echo $admin['role'] === 'super_admin' ? 'bg-danger' : 'bg-primary'; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $admin['role'])); ?>
                                        </span>
                                        <br>
                                        <span class="badge <?php echo $admin['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo $admin['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <div class="text-center mt-3">
                            <a href="login.php" class="btn btn-outline-primary">
                                <i class="bi bi-box-arrow-in-right"></i> Go to Login
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($message)): ?>
                    <div class="alert alert-success" role="alert">
                        <i class="bi bi-check-circle-fill"></i>
                        <?php echo htmlspecialchars($message); ?>
                    </div>
                    <div class="text-center">
                        <a href="login.php" class="btn btn-primary">
                            <i class="bi bi-box-arrow-in-right"></i> Go to Login Page
                        </a>
                    </div>
                <?php else: ?>
                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger" role="alert">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="username" name="username" 
                                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" 
                                           required minlength="3" maxlength="50">
                                    <div class="form-text">3-50 characters, letters, numbers, and underscores only</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="email" class="form-label">Email Address <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" id="email" name="email" 
                                           value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
                                           required>
                                    <div class="form-text">Used for login and notifications</div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="full_name" class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="full_name" name="full_name" 
                                   value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" 
                                   required maxlength="100">
                            <div class="form-text">Display name for the admin panel</div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                                    <input type="password" class="form-control" id="password" name="password" 
                                           required minlength="8">
                                    <div class="form-text">Minimum 8 characters</div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="confirm_password" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                           required minlength="8">
                                    <div class="form-text">Must match the password above</div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="role" class="form-label">Admin Role</label>
                            <select class="form-select" id="role" name="role">
                                <option value="admin" <?php echo ($_POST['role'] ?? '') === 'admin' ? 'selected' : ''; ?>>
                                    Admin - Standard admin access
                                </option>
                                <option value="super_admin" <?php echo ($_POST['role'] ?? '') === 'super_admin' ? 'selected' : ''; ?>>
                                    Super Admin - Full system access
                                </option>
                                <option value="moderator" <?php echo ($_POST['role'] ?? '') === 'moderator' ? 'selected' : ''; ?>>
                                    Moderator - Limited admin access
                                </option>
                            </select>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-person-plus"></i> Create Admin User
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="text-center mt-3">
            <small class="text-white-50">
                <i class="bi bi-shield-lock"></i> 
                This setup page should be removed or secured after creating your admin user.
            </small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const password = document.getElementById('password').value;
            const confirmPassword = this.value;
            
            if (password && confirmPassword && password !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });

        // Username validation
        document.getElementById('username').addEventListener('input', function() {
            const username = this.value;
            const regex = /^[a-zA-Z0-9_]+$/;
            
            if (username && !regex.test(username)) {
                this.setCustomValidity('Username can only contain letters, numbers, and underscores');
            } else {
                this.setCustomValidity('');
            }
        });
    </script>
</body>
</html>
