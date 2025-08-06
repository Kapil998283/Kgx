<?php
/**
 * Admin Authentication Manager Class
 * Standalone authentication system for admin panel
 * 
 * This class handles all admin authentication operations:
 * - Admin login/logout
 * - Session management
 * - Role-based access control  
 * - Permission checking
 * - Activity logging
 * - Profile management
 * - Integration with secure configuration
 */

// Ensure admin secure access is defined
if (!defined('ADMIN_SECURE_ACCESS')) {
    die('Direct access forbidden - Admin area only');
}

// Load shared includes if not already loaded
if (!class_exists('SupabaseClient')) {
    loadSharedInclude('SupabaseClient.php');
}

class AdminAuthManager {
    private $supabase;
    private $sessionKey = 'kgx_admin_session';
    private $config;
    
    public function __construct() {
        // Always use service role for admin operations
        $this->supabase = new SupabaseClient(true);
        
        // Load admin configuration
        $this->config = loadAdminConfig('admin_config.php');
        
        // Start session if not already started
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        // Configure session security
        $this->configureSessionSecurity();
    }
    
    /**
     * Configure session security based on admin config
     */
    private function configureSessionSecurity() {
        if ($this->config && isset($this->config['security']['session_security'])) {
            $sessionConfig = $this->config['security']['session_security'];
            
            // Set session cookie parameters
            session_set_cookie_params([
                'lifetime' => $this->config['system']['session_timeout'] ?? 3600,
                'path' => '/',
                'domain' => '',
                'secure' => $sessionConfig['secure_cookies'] ?? false,
                'httponly' => $sessionConfig['httponly_cookies'] ?? true,
                'samesite' => $sessionConfig['same_site'] ?? 'Strict'
            ]);
        }
    }
    
    /**
     * Check if admin is logged in
     */
    public function isLoggedIn() {
        return isset($_SESSION[$this->sessionKey]) && !empty($_SESSION[$this->sessionKey]);
    }
    
    /**
     * Get current admin user data
     */
    public function getCurrentAdmin() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        return $_SESSION[$this->sessionKey];
    }
    
    /**
     * Get current admin user ID
     */
    public function getCurrentAdminId() {
        $admin = $this->getCurrentAdmin();
        return $admin ? $admin['admin_id'] : null;
    }
    
    /**
     * Admin login
     */
    public function login($emailOrUsername, $password) {
        try {
            // Try to find admin by email first, then by username
            $adminData = $this->supabase->select('admin_users', '*', ['email' => $emailOrUsername]);
            
            // If not found by email, try by username
            if (empty($adminData)) {
                $adminData = $this->supabase->select('admin_users', '*', ['username' => $emailOrUsername]);
            }
            
            if (empty($adminData)) {
                throw new Exception('Invalid email/username or password');
            }
            
            $admin = $adminData[0];
            
            // Check if admin account is active
            if (isset($admin['status']) && $admin['status'] !== 'active') {
                throw new Exception('Admin account is not active');
            }
            
            // Verify password
            if (!password_verify($password, $admin['password'])) {
                throw new Exception('Invalid email or password');
            }
            
            // Create admin session
            $sessionData = [
                'admin_id' => $admin['id'],
                'email' => $admin['email'],
                'username' => $admin['username'],
                'full_name' => $admin['full_name'],
                'role' => $admin['role'],
                'permissions' => json_decode($admin['permissions'] ?? '[]', true),
                'login_time' => time(),
                'last_activity' => time()
            ];
            
            $_SESSION[$this->sessionKey] = $sessionData;
            
            // Update last login time
            $this->supabase->update('admin_users', 
                ['last_login' => date('Y-m-d H:i:s')], 
                ['id' => $admin['id']]
            );
            
            // Log admin login
            $this->logActivity('login', 'Admin logged in successfully');
            
            return [
                'success' => true,
                'message' => 'Login successful!',
                'admin' => $sessionData
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Admin logout
     */
    public function logout() {
        if ($this->isLoggedIn()) {
            $this->logActivity('logout', 'Admin logged out');
        }
        
        unset($_SESSION[$this->sessionKey]);
        return ['success' => true, 'message' => 'Logged out successfully'];
    }
    
    /**
     * Check if admin has specific role
     */
    public function hasRole($requiredRole) {
        $admin = $this->getCurrentAdmin();
        return $admin && $admin['role'] === $requiredRole;
    }
    
    /**
     * Check if admin has specific permission
     */
    public function hasPermission($permission) {
        $admin = $this->getCurrentAdmin();
        return $admin && in_array($permission, $admin['permissions'] ?? []);
    }
    
    /**
     * Require admin to be logged in
     */
    public function requireAuth($redirectUrl = '../login.php') {
        if (!$this->isLoggedIn()) {
            header("Location: $redirectUrl");
            exit();
        }
        
        // Update last activity
        $_SESSION[$this->sessionKey]['last_activity'] = time();
    }
    
    /**
     * Require specific role
     */
    public function requireRole($requiredRole, $redirectUrl = '../dashboard/index.php') {
        $this->requireAuth();
        
        if (!$this->hasRole($requiredRole)) {
            header("Location: $redirectUrl");
            exit();
        }
    }
    
    /**
     * Require specific permission
     */
    public function requirePermission($permission, $redirectUrl = '../dashboard/index.php') {
        $this->requireAuth();
        
        if (!$this->hasPermission($permission)) {
            header("Location: $redirectUrl");
            exit();
        }
    }
    
    /**
     * Log admin activity
     */
    public function logActivity($action, $description, $additionalData = []) {
        try {
            $admin = $this->getCurrentAdmin();
            if (!$admin) return false;
            
            $activityData = [
                'admin_id' => $admin['admin_id'],
                'action' => $action,
                'description' => $description,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'additional_data' => json_encode($additionalData),
                'created_at' => date('Y-m-d H:i:s')
            ];
            
            return $this->supabase->insert('admin_activity_log', $activityData);
        } catch (Exception $e) {
            error_log("Failed to log admin activity: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get admin user data by ID
     */
    public function getAdminById($adminId) {
        try {
            $result = $this->supabase->select('admin_users', '*', ['id' => $adminId], null, 1);
            return !empty($result) ? $result[0] : null;
        } catch (Exception $e) {
            error_log("Failed to get admin user: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update admin profile
     */
    public function updateProfile($data) {
        try {
            $admin = $this->getCurrentAdmin();
            if (!$admin) {
                throw new Exception('Not authenticated');
            }
            
            // Remove sensitive fields
            unset($data['id'], $data['password'], $data['role'], $data['permissions']);
            
            $this->supabase->update('admin_users', $data, ['id' => $admin['admin_id']]);
            
            // Update session data
            foreach ($data as $key => $value) {
                if (isset($_SESSION[$this->sessionKey][$key])) {
                    $_SESSION[$this->sessionKey][$key] = $value;
                }
            }
            
            $this->logActivity('profile_update', 'Admin profile updated', $data);
            
            return ['success' => true, 'message' => 'Profile updated successfully'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Change admin password
     */
    public function changePassword($currentPassword, $newPassword) {
        try {
            $admin = $this->getCurrentAdmin();
            if (!$admin) {
                throw new Exception('Not authenticated');
            }
            
            // Get current admin data to verify password
            $adminData = $this->getAdminById($admin['admin_id']);
            if (!$adminData || !password_verify($currentPassword, $adminData['password'])) {
                throw new Exception('Current password is incorrect');
            }
            
            // Update password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $this->supabase->update('admin_users', 
                ['password' => $hashedPassword], 
                ['id' => $admin['admin_id']]
            );
            
            $this->logActivity('password_change', 'Admin password changed');
            
            return ['success' => true, 'message' => 'Password changed successfully'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get admin statistics
     */
    public function getAdminStats() {
        try {
            $admin = $this->getCurrentAdmin();
            if (!$admin) return null;
            
            // Get total admin activity count
            $activityCount = $this->supabase->select('admin_activity_log', 'COUNT(*) as count', ['admin_id' => $admin['admin_id']]);
            
            // Get login history (last 10 logins)
            $loginHistory = $this->supabase->select('admin_activity_log', '*', 
                ['admin_id' => $admin['admin_id'], 'action' => 'login'], 
                ['created_at' => 'DESC'], 10
            );
            
            return [
                'total_activities' => $activityCount[0]['count'] ?? 0,
                'login_history' => $loginHistory ?? []
            ];
        } catch (Exception $e) {
            error_log("Failed to get admin stats: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Check session validity and refresh if needed
     */
    public function checkSessionValidity($maxInactiveTime = 3600) { // 1 hour default
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        $admin = $this->getCurrentAdmin();
        $lastActivity = $admin['last_activity'] ?? 0;
        
        if ((time() - $lastActivity) > $maxInactiveTime) {
            // Session expired
            $this->logout();
            return false;
        }
        
        // Update last activity
        $_SESSION[$this->sessionKey]['last_activity'] = time();
        return true;
    }
}
?>
