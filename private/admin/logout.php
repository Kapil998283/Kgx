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

// Include the admin authentication system
require_once ADMIN_INCLUDES_PATH . 'AdminAuthManager.php';

// Initialize admin auth manager
$adminAuth = new AdminAuthManager();

// Log out the admin
$adminAuth->logout();

// Destroy the session completely
session_destroy();

// Redirect to login page
header("Location: login.php");
exit();
?>
