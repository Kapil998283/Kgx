<?php
/**
 * Secure Admin Access Point
 * This file redirects to the appropriate admin page
 */

// Start session
session_start();

// Check if user is already logged in as admin
if (isset($_SESSION['admin_id']) && $_SESSION['admin_id']) {
    // User is logged in, redirect to dashboard
    header('Location: /KGX/private/admin/dashboard/index.php');
    exit();
} else {
    // User is not logged in, redirect to login
    header('Location: /KGX/private/admin/index.php');
    exit();
}
?>
