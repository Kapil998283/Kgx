<?php
// Only start session if one isn't already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/AuthManager.php';
require_once __DIR__ . '/SupabaseClient.php';

// Initialize AuthManager instance
if (!isset($GLOBALS['authManager'])) {
    $GLOBALS['authManager'] = new AuthManager();
}

/**
 * Get AuthManager instance
 */
function getAuthManager() {
    return $GLOBALS['authManager'];
}

/**
 * Get Supabase client instance
 */
function getSupabaseClient($useServiceRole = false) {
    return new SupabaseClient($useServiceRole);
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return getAuthManager()->isLoggedIn();
}

/**
 * Require user to be logged in
 */
function requireLogin($redirectUrl = '/register/login.php') {
    if (!isLoggedIn()) {
        $_SESSION['error'] = "Please log in to access this page.";
        
        // Add current page as redirect parameter if not already included
        if (strpos($redirectUrl, 'redirect=') === false && isset($_SERVER['REQUEST_URI'])) {
            $currentPage = $_SERVER['REQUEST_URI'];
            $separator = strpos($redirectUrl, '?') !== false ? '&' : '?';
            $redirectUrl .= $separator . 'redirect=' . urlencode($currentPage);
        }
        
        header("Location: $redirectUrl");
        exit();
    }
}

/**
 * Require admin permissions
 */
function requireAdmin($redirectUrl = '/home.php') {
    requireLogin();
    
    $authManager = getAuthManager();
    if (!$authManager->hasRole('admin')) {
        $_SESSION['error'] = "You don't have permission to access this page.";
        header("Location: $redirectUrl");
        exit();
    }
}

/**
 * Get current user's role
 */
function getUserRole() {
    if (!isLoggedIn()) {
        return 'guest';
    }
    
    $user = getAuthManager()->getCurrentUser();
    return $user['role'] ?? 'user';
}

/**
 * Check if user has specific permission
 */
function checkPermission($requiredRole) {
    $userRole = getUserRole();
    
    switch($requiredRole) {
        case 'admin':
            return $userRole === 'admin';
        case 'user':
            return in_array($userRole, ['user', 'admin']);
        case 'guest':
            return true;
        default:
            return false;
    }
}

/**
 * Get current user ID
 */
function getCurrentUserId() {
    return getAuthManager()->getCurrentUserId();
}

/**
 * Get current user role
 */
function getCurrentUserRole() {
    return getUserRole();
}

/**
 * Get current user data
 */
function getCurrentUser() {
    return getAuthManager()->getCurrentUser();
}

/**
 * Check if user is admin
 */
function isAdmin() {
    return getUserRole() === 'admin';
}

/**
 * Check if user has specific role
 */
function hasRole($role) {
    return getAuthManager()->hasRole($role);
}

/**
 * Get user's financial data (coins, tickets)
 */
function getUserFinancials() {
    return getAuthManager()->getUserFinancials();
}

/**
 * Logout user
 */
function logout() {
    $result = getAuthManager()->logout();
    header('Location: /k/pages/auth/login.php');
    exit();
}

/**
 * Legacy compatibility functions for older code
 */

// Backward compatibility with old session structure
if (isLoggedIn()) {
    $currentUser = getCurrentUser();
    if ($currentUser) {
        // Set legacy session variables for backward compatibility
        $_SESSION['user_id'] = $currentUser['user_id'];
        $_SESSION['username'] = $currentUser['username'];
        $_SESSION['email'] = $currentUser['email'];
        $_SESSION['role'] = $currentUser['role'];
        $_SESSION['is_admin'] = ($currentUser['role'] === 'admin');
    }
}
