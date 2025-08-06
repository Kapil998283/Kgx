<?php
session_start();

// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once __DIR__ . '/admin_secure_config.php';

// Load admin configuration
$adminConfig = loadAdminConfig('admin_config.php');

// Include the admin authentication manager
require_once ADMIN_INCLUDES_PATH . 'AdminAuthManager.php';

// Initialize admin auth manager
$adminAuth = new AdminAuthManager();

// If admin is already logged in, redirect to dashboard  
if ($adminAuth->isLoggedIn()) {
    header('Location: ' . adminUrl('dashboard/'));
    exit;
}

// Handle login attempts with rate limiting
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check CSRF token if CSRF protection is enabled
    if ($adminConfig['security']['csrf_protection']) {
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!validateAdminCSRF($csrfToken)) {
            $error = "Invalid security token. Please try again.";
            logAdminSecurity('CSRF token validation failed', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        }
    }
    
    if (!isset($error)) {
        // Check rate limiting
        if (!checkAdminRateLimit('login', $adminConfig['system']['max_login_attempts'], 300)) {
            $error = "Too many login attempts. Please try again later.";
            logAdminSecurity('Login rate limit exceeded', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        } else {
            $email = adminSanitize($_POST['username'] ?? '', 'string'); // Clean input
            $password = $_POST['password'] ?? '';
            
            if (!empty($email) && !empty($password)) {
                $loginResult = $adminAuth->login($email, $password);
                
                if ($loginResult['success']) {
                    // Regenerate session ID for security
                    if ($adminConfig['security']['session_security']['regenerate_id']) {
                        session_regenerate_id(true);
                    }
                    
                    // Log successful login
                    logAdminSecurity('Successful login', "Admin: $email", 'INFO');
                    
                    // Redirect to dashboard
                    header('Location: ' . adminUrl('dashboard/'));
                    exit;
                } else {
                    $error = $loginResult['message'];
                    logAdminSecurity('Failed login attempt', "Email: $email, IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
                }
            } else {
                $error = "Please enter both email and password.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - KGX</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
    <style>
        body {
            background: #1a1a1a;
            color: #fff;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-container {
            background: rgba(255, 255, 255, 0.05);
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.3);
            width: 100%;
            max-width: 400px;
        }
        .login-header {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-header h1 {
            font-size: 1.8rem;
            color: #00c896;
            margin-bottom: 0.5rem;
        }
        .form-control {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #fff;
        }
        .form-control:focus {
            background: rgba(255, 255, 255, 0.15);
            border-color: #00c896;
            color: #fff;
            box-shadow: 0 0 0 0.25rem rgba(0, 200, 150, 0.25);
        }
        .btn-primary {
            background: #00c896;
            border: none;
            width: 100%;
            padding: 0.75rem;
        }
        .btn-primary:hover {
            background: #00b085;
        }
        .alert {
            background: rgba(220, 53, 69, 0.1);
            border: 1px solid rgba(220, 53, 69, 0.2);
            color: #dc3545;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1><?php echo htmlspecialchars($adminConfig['system']['name']); ?></h1>
            <p>Enter your credentials to access the admin panel</p>
            <?php if (ADMIN_DEBUG): ?>
                <small class="text-muted">Environment: <?php echo ADMIN_ENVIRONMENT; ?></small>
            <?php endif; ?>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger" role="alert">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="<?php echo adminUrl('login.php'); ?>">
            <?php if ($adminConfig['security']['csrf_protection']): ?>
                <input type="hidden" name="csrf_token" value="<?php echo generateAdminCSRF(); ?>">
            <?php endif; ?>
            
            <div class="mb-3">
                <label for="username" class="form-label">Email/Username</label>
                <input type="text" class="form-control" id="username" name="username" 
                       value="<?php echo htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES); ?>"
                       required autofocus>
            </div>
            
            <div class="mb-4">
                <label for="password" class="form-label">Password</label>
                <input type="password" class="form-control" id="password" name="password" required>
                <small class="form-text text-muted">
                    Min <?php echo $adminConfig['security']['password_policy']['min_length']; ?> characters
                </small>
            </div>
            
            <button type="submit" class="btn btn-primary">Login</button>
        </form>
        
        <?php if (ADMIN_DEBUG): ?>
            <div class="mt-3 text-center">
                <small class="text-muted">
                    <a href="<?php echo adminUrl('example_usage.php'); ?>" class="text-info">View Config Demo</a>
                </small>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/js/bootstrap.bundle.min.js"></script>
</body>
</html> 