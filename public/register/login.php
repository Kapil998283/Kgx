<?php
// Only start session if one isn't already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
define('SECURE_ACCESS', true);
require_once '../secure_config.php';
loadSecureConfig('supabase.php');
loadSecureInclude('auth.php');

// Initialize AuthManager
$authManager = new AuthManager();

// Get redirect parameter
$redirect = isset($_GET['redirect']) ? $_GET['redirect'] : 'index.php';
// Sanitize redirect to prevent open redirects
$redirect = preg_replace('/[^a-zA-Z0-9\/\._-]/', '', $redirect);
if (!$redirect || strpos($redirect, '..') !== false) {
    $redirect = 'index.php';
}

// Check if user is already logged in
if($authManager->isLoggedIn()) {
    header("Location: ../$redirect");
    exit();
}

$error = '';
$success = '';

// Check for registration success message
if(isset($_GET['success']) && $_GET['success'] == 1) {
    $success = "Registration successful! Please login to continue.";
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = "Please fill in all fields";
    } else {
        try {
            // Attempt to login using AuthManager
            $result = $authManager->login($email, $password);
            
            if ($result['success']) {
                // Get user data for enhanced welcome back notification
                $userData = $authManager->getCurrentUser();
                if ($userData) {
                    $username = $userData['username'];
                    $user_id = $userData['user_id'];
                    
                    // Get current time for contextual greeting
                    $currentHour = date('H');
                    $timeGreeting = '';
                    if ($currentHour < 12) {
                        $timeGreeting = '🌅 Good morning';
                    } elseif ($currentHour < 17) {
                        $timeGreeting = '☀️ Good afternoon';
                    } else {
                        $timeGreeting = '🌙 Good evening';
                    }
                    
                    $welcome_title = "🎮 Welcome Back, Champion!";
                    $welcome_message = "{$timeGreeting}, {$username}! 🎯\n\n" .
                                      "🔥 YOU'RE BACK IN THE GAME:\n" .
                                      "• Check out new tournaments and challenges\n" .
                                      "• See your current rank and achievements\n" .
                                      "• Connect with your squad members\n" .
                                      "• Claim daily rewards if available\n\n" .
                                      "⚡ WHAT'S NEW:\n" .
                                      "• Browse active tournaments\n" .
                                      "• Check leaderboards and standings\n" .
                                      "• Review match history and stats\n" .
                                      "• Update your gaming profile\n\n" .
                                      "🏆 Ready to dominate today's matches?\n" .
                                      "Let's make this session legendary! 🎖️";
                    
                    // Create enhanced welcome back notification
                    try {
                        $authManager->createNotification($user_id, $welcome_title, $welcome_message, 'login');
                        error_log("Sent enhanced welcome back notification to user: $username (ID: $user_id)");
                    } catch (Exception $e) {
                        // Log notification error but don't stop login process
                        error_log("Failed to create welcome back notification: " . $e->getMessage());
                    }
                }
                
                // Redirect to specified page or dashboard
                header("Location: ../$redirect");
                exit();
            } else {
                $error = $result['message'] ?? "Invalid email or password";
            }
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $error = "An error occurred during login. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | KGX Gaming</title>
    
    <!-- Favicon -->
    <link rel="shortcut icon" href="../favicon.svg" type="image/svg+xml">
    
    <!-- CSS -->
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/auth.css">
    <link rel="stylesheet" href="../assets/css/multi-step-auth.css">
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;500;600;700;800;900&family=Rajdhani:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Ion Icons -->
    <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
    <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>
</head>
<body class="auth-body">
    <div class="auth-wrapper">
        <div class="auth-container multi-step">
            <!-- Header -->
            <div class="auth-header">
                <div class="logo">
                    <h1 class="brand-text">KGX</h1>
                    <span class="brand-tagline">GAMING XTREME</span>
                </div>
                <h2 class="auth-title">Welcome Back</h2>
                <p class="auth-subtitle">Sign in to your gaming account</p>
            </div>
        
            <?php if($error): ?>
                <div class="error-message">
                    <ion-icon name="alert-circle-outline"></ion-icon>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if($success): ?>
                <div class="success-message">
                    <ion-icon name="checkmark-circle-outline"></ion-icon>
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <form class="auth-form" method="POST" action="">
                <div class="form-group">
                    <label for="email">
                        <ion-icon name="mail-outline"></ion-icon>
                        Email Address
                    </label>
                    <input type="email" id="email" name="email" required 
                           value="<?php echo htmlspecialchars($email ?? ''); ?>"
                           placeholder="Enter your email address">
                    <div class="input-hint">Use the email you registered with</div>
                </div>
                
                <div class="form-group">
                    <label for="password">
                        <ion-icon name="lock-closed-outline"></ion-icon>
                        Password
                    </label>
                    <div class="password-input">
                        <input type="password" id="password" name="password" required
                               placeholder="Enter your password">
                        <button type="button" class="password-toggle" data-target="password">
                            <ion-icon name="eye-outline"></ion-icon>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="auth-btn primary-btn">
                    <span>Sign In</span>
                    <ion-icon name="log-in-outline"></ion-icon>
                </button>
            </form>
            
            <div class="auth-footer">
                <p>Don't have an account? <a href="multi-step-register.php" class="auth-link">Create Account</a></p>
                <p><a href="forgot-password.php" class="auth-link">Forgot Password?</a></p>
                
                <div class="social-login">
                    <p class="social-title">Or continue with</p>
                    <div class="social-buttons">
                        <button class="social-btn google-btn">
                            <ion-icon name="logo-google"></ion-icon>
                        </button>
                        <button class="social-btn discord-btn">
                            <ion-icon name="logo-discord"></ion-icon>
                        </button>
                        <button class="social-btn steam-btn">
                            <ion-icon name="game-controller-outline"></ion-icon>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="../assets/js/multi-step-auth.js"></script>
</body>
</html> 