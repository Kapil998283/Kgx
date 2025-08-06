<?php
/**
 * Admin Authentication System
 * Standalone authentication for admin panel - completely separate from user auth
 * 
 * This file provides:
 * - Admin-only session management
 * - Admin role-based access control
 * - Admin activity logging
 * - Admin-specific database operations
 * - Secure configuration integration
 */

// Only start session if one isn't already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once dirname(__DIR__) . '/admin_secure_config.php';

// Load admin configuration
$adminConfig = loadAdminConfig('admin_config.php');

// Include the SupabaseClient
loadSharedInclude('SupabaseClient.php');

/**
 * Admin Authentication Manager Class
 */
class AdminAuthManager {
    private $supabase;
    private $sessionKey = 'kgx_admin_session';
    
    public function __construct() {
        $this->supabase = new SupabaseClient(true); // Always use service role for admin
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
    public function login($email, $password) {
        try {
            // Get admin data from admin_users table
            $adminData = $this->supabase->select('admin_users', '*', ['email' => $email]);
            
            if (empty($adminData)) {
                throw new Exception('Invalid email or password');
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
}

// Initialize global admin auth manager
if (!isset($GLOBALS['adminAuthManager'])) {
    $GLOBALS['adminAuthManager'] = new AdminAuthManager();
}

/**
 * Global admin authentication functions
 */

// Get AdminAuthManager instance
function getAdminAuthManager() {
    return $GLOBALS['adminAuthManager'];
}

// Initialize Supabase connection for admin operations
function getSupabaseConnection() {
    static $supabase = null;
    if ($supabase === null) {
        $supabase = new SupabaseClient(true); // Always use service role for admin
    }
    return $supabase;
}

// Check if admin is logged in
function isAdminLoggedIn() {
    return getAdminAuthManager()->isLoggedIn();
}

// Get current admin user
function getCurrentAdmin() {
    return getAdminAuthManager()->getCurrentAdmin();
}

// Get current admin ID
function getCurrentAdminId() {
    return getAdminAuthManager()->getCurrentAdminId();
}

// Require admin authentication
function requireAdminAuth($redirectUrl = '../login.php') {
    getAdminAuthManager()->requireAuth($redirectUrl);
}

// Check if user is logged in as admin (legacy compatibility)
if (!isAdminLoggedIn()) {
    // Redirect to login page if not logged in
    header('Location: ../login.php');
    exit();
}

// Set legacy session variables for backward compatibility
$currentAdmin = getCurrentAdmin();
if ($currentAdmin) {
    $_SESSION['admin_id'] = $currentAdmin['admin_id'];
    $_SESSION['admin_role'] = $currentAdmin['role'];
    $_SESSION['admin_email'] = $currentAdmin['email'];
    $_SESSION['admin_username'] = $currentAdmin['username'];
}

// Function to check if user has required role (legacy compatibility)
function checkAdminRole($required_role) {
    getAdminAuthManager()->requireRole($required_role);
}

// Function to log admin activity (legacy compatibility)
function logAdminAction($action, $description) {
    getAdminAuthManager()->logActivity($action, $description);
}

// Function to get admin user data (legacy compatibility)
function getAdminUser($admin_id) {
    return getAdminAuthManager()->getAdminById($admin_id);
}

/**
 * Updates tournament status automatically based on dates
 */
function updateTournamentStatus($tournamentId = null) {
    try {
        $supabase = getSupabaseConnection();
        $now = date('Y-m-d');
        
        // Get tournaments to update
        $conditions = [];
        if ($tournamentId) {
            $conditions['id'] = $tournamentId;
        }
        
        $tournaments = $supabase->select('tournaments', '*', $conditions);
        
        foreach ($tournaments as $tournament) {
            $newStatus = $tournament['status'];
            $newPhase = $tournament['registration_phase'];
            
            // Don't update cancelled tournaments
            if ($tournament['status'] === 'cancelled') {
                continue;
            }
            
            // Update status based on dates
            if ($now < $tournament['registration_open_date']) {
                $newStatus = 'upcoming';
                $newPhase = 'closed';
            } elseif ($now >= $tournament['registration_open_date'] && $now <= $tournament['registration_close_date']) {
                $newStatus = 'registration';
                $newPhase = 'open';
            } elseif ($now >= $tournament['playing_start_date'] && $now <= $tournament['finish_date']) {
                $newStatus = 'ongoing';
                $newPhase = 'closed';
            } elseif ($now > $tournament['finish_date']) {
                $newStatus = 'completed';
                $newPhase = 'closed';
            }
            
            // Update if status changed
            if ($newStatus !== $tournament['status'] || $newPhase !== $tournament['registration_phase']) {
                $supabase->update('tournaments', [
                    'status' => $newStatus,
                    'registration_phase' => $newPhase
                ], ['id' => $tournament['id']]);
            }
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Error updating tournament status: " . $e->getMessage());
        return false;
    }
}

/**
 * Cancel a tournament
 */
function cancelTournament($tournamentId) {
    try {
        $supabase = getSupabaseConnection();
        $supabase->update('tournaments', [
            'status' => 'cancelled',
            'registration_phase' => 'closed'
        ], ['id' => $tournamentId]);
        
        // Log the cancellation
        logAdminAction('cancel_tournament', "Cancelled tournament ID: $tournamentId");
        
        return true;
    } catch (Exception $e) {
        error_log("Error cancelling tournament: " . $e->getMessage());
        return false;
    }
}
?> 