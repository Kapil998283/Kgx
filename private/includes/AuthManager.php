<?php
require_once __DIR__ . '/SupabaseClient.php';

/**
 * Authentication Manager for KGX Esports Platform
 * 
 * Handles user authentication, sessions, and user management
 */
class AuthManager {
    private $supabase;
    private $sessionKey = 'kgx_user_session';
    
    public function __construct() {
        $this->supabase = new SupabaseClient();
        
        // Start session if not already started and headers haven't been sent
        if (session_status() == PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }
    }
    
    /**
     * Register a new user
     */
    public function register($email, $password, $username, $fullName, $phone = null) {
        try {
            // Debug logging
            error_log("AuthManager::register called with email: $email, username: $username");
            
            // Use service role client for all database operations
            $serviceClient = new SupabaseClient(true);
            
            // Check if user already exists in our database
            error_log("Checking if user already exists in database...");
            $existingUser = $serviceClient->select('users', 'id, email, username', ['email' => $email]);
            
            if (!empty($existingUser)) {
                error_log("User already exists in database with email: $email");
                return [
                    'success' => false,
                    'message' => 'User already exists. Please use the login form instead.'
                ];
            }
            
            // Check if username is already taken
            $existingUsername = $serviceClient->select('users', 'id, username', ['username' => $username]);
            if (!empty($existingUsername)) {
                error_log("Username already exists: $username");
                return [
                    'success' => false,
                    'message' => 'Username is already taken. Please choose a different username.'
                ];
            }
            
            error_log("Creating new user directly in database (bypassing Supabase Auth)...");
            
            // Create user profile directly in our users table (no Supabase Auth)
            $userData = [
                'email' => $email,
                'username' => $username,
                'full_name' => $fullName,
                'phone' => $phone,
                'password' => password_hash($password, PASSWORD_DEFAULT), // Store hashed password
                'role' => 'user',
                'ticket_balance' => 0,
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            // Insert user data using service role (bypasses RLS)
            $serviceClient = new SupabaseClient(true);
            
            try {
                $userResult = $serviceClient->insert('users', $userData);
                error_log("User insert result: " . json_encode($userResult));
                
                // Get the auto-generated ID from the database
                $dbUserId = null;
                
                // Handle different possible response formats from Supabase insert
                if (is_array($userResult) && !empty($userResult)) {
                    // Check if it's an array of results
                    if (isset($userResult[0]['id'])) {
                        $dbUserId = $userResult[0]['id'];
                    }
                    // Check if it's a direct result object
                    elseif (isset($userResult['id'])) {
                        $dbUserId = $userResult['id'];
                    }
                }
                
                // If we still don't have the ID, try to query the user by email to get the ID
                if (!$dbUserId) {
                    error_log("Could not extract database user ID from insert result, querying by email...");
                    try {
                        $queryResult = $serviceClient->select('users', 'id', ['email' => $email], null, 1);
                        if (!empty($queryResult)) {
                            $dbUserId = $queryResult[0]['id'];
                            error_log("Found user ID via email query: $dbUserId");
                        }
                    } catch (Exception $queryEx) {
                        error_log("Email query also failed: " . $queryEx->getMessage());
                    }
                }
                
                if (!$dbUserId) {
                    error_log("Could not extract database user ID from result: " . json_encode($userResult));
                    throw new Exception('Failed to get database user ID from insert result');
                }
                
                error_log("Database user ID: $dbUserId");
                
                // Initialize user's financial records with signup bonuses
                $signupCoins = 100; // Welcome bonus coins
                $signupTickets = 1; // Welcome bonus tickets
                
                try {
                    $serviceClient->insert('user_coins', ['user_id' => $dbUserId, 'coins' => $signupCoins]);
                    error_log("Created user_coins record for user $dbUserId with $signupCoins coins");
                } catch (Exception $coinsEx) {
                    error_log("Failed to create user_coins: " . $coinsEx->getMessage());
                }
                
                try {
                    $serviceClient->insert('user_tickets', ['user_id' => $dbUserId, 'tickets' => $signupTickets]);
                    error_log("Created user_tickets record for user $dbUserId with $signupTickets tickets");
                } catch (Exception $ticketsEx) {
                    error_log("Failed to create user_tickets: " . $ticketsEx->getMessage());
                }
                
                try {
                    $serviceClient->insert('user_streaks', [
                        'user_id' => $dbUserId,
                        'current_streak' => 0,
                        'longest_streak' => 0,
                        'streak_points' => 0
                    ]);
                    error_log("Created user_streaks record for user $dbUserId");
                } catch (Exception $streaksEx) {
                    error_log("Failed to create user_streaks: " . $streaksEx->getMessage());
                }
                
                // Send welcome notification
                try {
                    $welcomeMessage = "🎮 Welcome to KGX Gaming Xtreme, {$username}! 🎯\n\n" .
                                    "🎊 WELCOME BONUS UNLOCKED:\n" .
                                    "💰 {$signupCoins} Coins added to your wallet\n" .
                                    "🎫 {$signupTickets} Free tournament ticket\n\n" .
                                    "🚀 GET STARTED:\n" .
                                    "• Join your first tournament and compete for prizes\n" .
                                    "• Complete your gaming profile for better matchmaking\n" .
                                    "• Connect with friends and form your squad\n" .
                                    "• Check out daily challenges for bonus rewards\n\n" .
                                    "💡 PRO TIPS:\n" .
                                    "• Participate in practice matches to improve your rank\n" .
                                    "• Join our Discord community for real-time updates\n" .
                                    "• Enable push notifications to never miss a tournament\n\n" .
                                    "🏆 Ready to dominate? Your gaming journey starts now!\n" .
                                    "Good luck, champion! 🎖️";
                    
                    $serviceClient->insert('notifications', [
                        'user_id' => $dbUserId,
                        'message' => $welcomeMessage,
                        'type' => 'welcome',
                        'is_read' => 0,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                    error_log("Sent enhanced welcome notification to user $dbUserId");
                } catch (Exception $notificationEx) {
                    error_log("Failed to send welcome notification: " . $notificationEx->getMessage());
                }
                
            } catch (Exception $dbEx) {
                error_log("Database operation failed: " . $dbEx->getMessage());
                throw new Exception('Failed to create user profile: ' . $dbEx->getMessage());
            }
            
            return [
                'success' => true,
                'message' => 'Registration successful! You can now login to your account.',
                'user_id' => $dbUserId
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Login user
     */
    public function login($email, $password) {
        try {
            // Debug logging for email
            error_log("AuthManager::login called with email: " . $email);
            
            // Get user data from our users table first using service role to bypass RLS
            $serviceClient = new SupabaseClient(true);
            
            // Use a more specific query approach to avoid filter parsing issues
            try {
                // Try REST API with proper email encoding
                $userData = $serviceClient->select('users', '*', ['email' => $email]);
                error_log("User query successful, found " . count($userData) . " records");
            } catch (Exception $selectEx) {
                error_log("User select query failed: " . $selectEx->getMessage());
                
                // If the REST API filter fails, it's likely a configuration or network issue
                // Return a more user-friendly error message
                throw new Exception('Unable to connect to authentication service. Please try again later.');
            }
            
            if (empty($userData)) {
                throw new Exception('Invalid email or password');
            }
            
            $user = $userData[0];
            
            // Verify password against our stored hash
            if (!password_verify($password, $user['password'])) {
                throw new Exception('Invalid email or password');
            }
            
            // Try to authenticate with Supabase Auth
            $authResult = null;
            $supabaseAuthWorking = false;
            
            try {
                $authResult = $this->supabase->signIn($email, $password);
                if (isset($authResult['access_token'])) {
                    $supabaseAuthWorking = true;
                }
            } catch (Exception $authEx) {
                // Log the Supabase Auth error but don't fail the login
                error_log("Supabase Auth failed (continuing with database-only login): " . $authEx->getMessage());
                
                // Check if it's an email confirmation issue
                if (strpos($authEx->getMessage(), 'email_not_confirmed') !== false || 
                    strpos($authEx->getMessage(), 'Email not confirmed') !== false) {
                    error_log("User login proceeding despite unconfirmed email: $email");
                } else {
                    error_log("Supabase Auth error: " . $authEx->getMessage());
                }
            }
            
            // Create session (with or without Supabase Auth tokens)
            $sessionData = [
                'user_id' => $user['id'],
                'email' => $user['email'],
                'username' => $user['username'],
                'full_name' => $user['full_name'],
                'role' => $user['role'],
                'access_token' => $supabaseAuthWorking ? $authResult['access_token'] : null,
                'refresh_token' => $supabaseAuthWorking ? ($authResult['refresh_token'] ?? null) : null,
                'login_time' => time(),
                'supabase_auth_active' => $supabaseAuthWorking
            ];
            
            $_SESSION[$this->sessionKey] = $sessionData;
            
            // Update last login time (if column exists)
            try {
                $serviceClient = new SupabaseClient(true);
                $serviceClient->update('users', 
                    ['last_login' => date('Y-m-d H:i:s')], 
                    ['id' => $user['id']]
                );
            } catch (Exception $lastLoginEx) {
                // Log but don't fail login if last_login column doesn't exist
                error_log("Could not update last_login (column may not exist): " . $lastLoginEx->getMessage());
            }
            
            return [
                'success' => true,
                'message' => 'Login successful!',
                'user' => $sessionData
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Logout user
     */
    public function logout() {
        unset($_SESSION[$this->sessionKey]);
        session_destroy();
        return ['success' => true, 'message' => 'Logged out successfully'];
    }
    
    /**
     * Check if user is logged in
     */
    public function isLoggedIn() {
        return isset($_SESSION[$this->sessionKey]) && !empty($_SESSION[$this->sessionKey]);
    }
    
    /**
     * Get current user data
     */
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        return $_SESSION[$this->sessionKey];
    }
    
    /**
     * Get current user ID
     */
    public function getCurrentUserId() {
        $user = $this->getCurrentUser();
        return $user ? $user['user_id'] : null;
    }
    
    /**
     * Verify user token
     */
    public function verifyToken($token) {
        return $this->supabase->verifyToken($token);
    }
    
    /**
     * Require authentication (redirect if not logged in)
     */
    public function requireAuth($redirectUrl = '/login.php') {
        if (!$this->isLoggedIn()) {
            header("Location: $redirectUrl");
            exit;
        }
    }
    
    /**
     * Require specific role
     */
    public function requireRole($requiredRole, $redirectUrl = '/unauthorized.php') {
        $user = $this->getCurrentUser();
        
        if (!$user || $user['role'] !== $requiredRole) {
            header("Location: $redirectUrl");
            exit;
        }
    }
    
    /**
     * Check if user has role
     */
    public function hasRole($role) {
        $user = $this->getCurrentUser();
        return $user && $user['role'] === $role;
    }
    
    /**
     * Update user profile
     */
    public function updateProfile($data) {
        if (!$this->isLoggedIn()) {
            return ['success' => false, 'message' => 'Not authenticated'];
        }
        
        try {
            $userId = $this->getCurrentUserId();
            
            // Remove sensitive fields that shouldn't be updated directly
            unset($data['id']);
            unset($data['email']);
            unset($data['password']);
            unset($data['role']);
            
            $serviceClient = new SupabaseClient(true);
            $serviceClient->update('users', $data, ['id' => $userId]);
            
            // Update session data
            foreach ($data as $key => $value) {
                if (isset($_SESSION[$this->sessionKey][$key])) {
                    $_SESSION[$this->sessionKey][$key] = $value;
                }
            }
            
            return ['success' => true, 'message' => 'Profile updated successfully'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Change password
     */
    public function changePassword($currentPassword, $newPassword) {
        if (!$this->isLoggedIn()) {
            return ['success' => false, 'message' => 'Not authenticated'];
        }
        
        try {
            $user = $this->getCurrentUser();
            
            // Verify current password by trying to login
            $loginResult = $this->supabase->signIn($user['email'], $currentPassword);
            
            if (!isset($loginResult['access_token'])) {
                throw new Exception('Current password is incorrect');
            }
            
            // Update password in our database
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            $serviceClient = new SupabaseClient(true);
            $serviceClient->update('users', 
                ['password' => $hashedPassword], 
                ['id' => $user['user_id']]
            );
            
            return ['success' => true, 'message' => 'Password changed successfully'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get user's financial data
     */
    public function getUserFinancials() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        try {
            $userId = $this->getCurrentUserId();
            
            // Get coins
            $coins = $this->supabase->select('user_coins', '*', ['user_id' => $userId]);
            $coinsBalance = !empty($coins) ? $coins[0]['coins'] : 0;
            
            // Get tickets
            $tickets = $this->supabase->select('user_tickets', '*', ['user_id' => $userId]);
            $ticketsBalance = !empty($tickets) ? $tickets[0]['tickets'] : 0;
            
            return [
                'coins' => $coinsBalance,
                'tickets' => $ticketsBalance
            ];
            
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Reset password (send reset email)
     */
    public function resetPassword($email) {
        try {
            // This would typically integrate with Supabase's password reset
            // For now, we'll create a password reset token
            
            $userData = $this->supabase->select('users', '*', ['email' => $email]);
            
            if (empty($userData)) {
                throw new Exception('Email not found');
            }
            
            $token = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            $serviceClient = new SupabaseClient(true);
            $serviceClient->insert('password_resets', [
                'email' => $email,
                'token' => $token,
                'expiry' => $expiry
            ]);
            
            // Here you would send an email with the reset link
            // For demo purposes, we'll just return the token
            
            return [
                'success' => true,
                'message' => 'Password reset email sent',
                'token' => $token // Remove this in production
            ];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Check if email already exists
     */
    public function checkEmailExists($email) {
        try {
            // Fetch user by email
            $result = $this->supabase->select('users', '*', ['email' => $email]);
            return !empty($result); // Return true if email exists
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Check if username already exists
     */
    public function checkUsernameExists($username) {
        try {
            // Fetch user by username
            $result = $this->supabase->select('users', '*', ['username' => $username]);
            return !empty($result); // Return true if username exists
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Check if phone number already exists
     */
    public function checkPhoneExists($phone) {
        try {
            // Fetch user by phone number
            $result = $this->supabase->select('users', '*', ['phone' => $phone]);
            return !empty($result); // Return true if phone exists
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Create a notification for a user
     */
    public function createNotification($userId, $title, $message, $type = 'info') {
        try {
            $serviceClient = new SupabaseClient(true);
            
            // Combine title and message for better display in header
            $fullMessage = !empty($title) ? "<b>{$title}</b>\n{$message}" : $message;
            
            $result = $serviceClient->insert('notifications', [
                'user_id' => $userId,
                'message' => $fullMessage,
                'type' => $type,
                'is_read' => 0, // Use integer instead of boolean
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            error_log("Notification created for user {$userId}: " . json_encode($result));
            return $result !== false;
        } catch (Exception $e) {
            error_log("Failed to create notification: " . $e->getMessage());
            return false;
        }
    }
}
