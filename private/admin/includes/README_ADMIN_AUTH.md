# Admin Authentication System

## Overview

This is a **standalone admin authentication system** that is completely separate from the user authentication system. It provides secure admin-only session management, role-based access control, and comprehensive activity logging.

## Key Features

- **Complete Separation**: No dependency on user authentication system
- **Secure Sessions**: Admin-specific session management with `kgx_admin_session` key
- **Role-Based Access**: Support for different admin roles and permissions
- **Activity Logging**: Comprehensive logging of all admin activities
- **Password Security**: Secure password hashing and verification
- **Session Management**: Automatic session timeout and activity tracking

## Files Structure

```
private/admin/includes/
├── admin-auth.php          # Main authentication file (include this in admin pages)
├── AdminAuthManager.php    # Core authentication class
└── README_ADMIN_AUTH.md   # This documentation
```

## Quick Start

### 1. Include Authentication in Admin Pages

```php
<?php
// Include at the top of any admin page that requires authentication
require_once __DIR__ . '/includes/admin-auth.php';

// The admin is now authenticated and session data is available
$currentAdmin = getCurrentAdmin();
echo "Welcome, " . $currentAdmin['full_name'];
?>
```

### 2. Using the AdminAuthManager Class Directly

```php
<?php
require_once __DIR__ . '/includes/AdminAuthManager.php';

$adminAuth = new AdminAuthManager();

// Check if logged in
if ($adminAuth->isLoggedIn()) {
    $admin = $adminAuth->getCurrentAdmin();
    echo "Logged in as: " . $admin['username'];
}

// Require authentication (redirects if not logged in)
$adminAuth->requireAuth();

// Check role
if ($adminAuth->hasRole('super_admin')) {
    // Allow super admin actions
}

// Check permission
if ($adminAuth->hasPermission('manage_users')) {
    // Allow user management
}

// Log activity
$adminAuth->logActivity('view_users', 'Admin viewed user list');
?>
```

## Available Functions

### Global Functions (when using admin-auth.php)

```php
// Check if admin is logged in
if (isAdminLoggedIn()) { /* ... */ }

// Get current admin data
$admin = getCurrentAdmin();

// Get current admin ID
$adminId = getCurrentAdminId();

// Require authentication
requireAdminAuth();

// Legacy compatibility functions
checkAdminRole('super_admin');
logAdminAction('action', 'description');
$admin = getAdminUser($adminId);
```

### AdminAuthManager Class Methods

```php
$adminAuth = new AdminAuthManager();

// Authentication
$result = $adminAuth->login($email, $password);
$adminAuth->logout();
$adminAuth->isLoggedIn();

// User data
$admin = $adminAuth->getCurrentAdmin();
$adminId = $adminAuth->getCurrentAdminId();
$admin = $adminAuth->getAdminById($adminId);

// Access control
$adminAuth->requireAuth($redirectUrl);
$adminAuth->requireRole($role, $redirectUrl);
$adminAuth->requirePermission($permission, $redirectUrl);
$adminAuth->hasRole($role);
$adminAuth->hasPermission($permission);

// Profile management
$result = $adminAuth->updateProfile($data);
$result = $adminAuth->changePassword($current, $new);

// Activity and statistics
$adminAuth->logActivity($action, $description, $additionalData);
$stats = $adminAuth->getAdminStats();
$adminAuth->checkSessionValidity($maxInactiveTime);
```

## Admin Session Structure

```php
$adminSession = [
    'admin_id' => 1,
    'email' => 'admin@example.com',
    'username' => 'admin',
    'full_name' => 'Administrator',
    'role' => 'super_admin',
    'permissions' => ['manage_users', 'manage_tournaments', 'system_settings'],
    'login_time' => 1640995200,
    'last_activity' => 1640995800
];
```

## Database Tables

### admin_users
- `id` (Primary Key)
- `email` (Unique)
- `username` (Unique) 
- `password` (Hashed)
- `full_name`
- `role` (e.g., 'super_admin', 'admin', 'moderator')
- `permissions` (JSON array)
- `status` ('active', 'inactive', 'suspended')
- `last_login`
- `created_at`
- `updated_at`

### admin_activity_log
- `id` (Primary Key)
- `admin_id` (Foreign Key)
- `action` (e.g., 'login', 'logout', 'create_tournament')
- `description`
- `ip_address`
- `user_agent`
- `additional_data` (JSON)
- `created_at`

## Usage Examples

### Protecting Admin Pages

```php
<?php
// At the top of any admin page
require_once __DIR__ . '/includes/admin-auth.php';

// Page is now protected - only authenticated admins can access
?>
```

### Role-Based Access Control

```php
<?php
require_once __DIR__ . '/includes/admin-auth.php';

// Only super admins can access this page
checkAdminRole('super_admin');

// Or using the class directly
$adminAuth = getAdminAuthManager();
$adminAuth->requireRole('super_admin');
?>
```

### Permission-Based Access Control

```php
<?php
require_once __DIR__ . '/includes/admin-auth.php';

$adminAuth = getAdminAuthManager();

// Check if admin has specific permission
if ($adminAuth->hasPermission('manage_tournaments')) {
    // Show tournament management interface
} else {
    echo "You don't have permission to manage tournaments.";
}
?>
```

### Activity Logging

```php
<?php
require_once __DIR__ . '/includes/admin-auth.php';

// Log admin activities
logAdminAction('create_tournament', 'Created new BGMI tournament');

// Or with additional data
$adminAuth = getAdminAuthManager();
$adminAuth->logActivity('update_user', 'Updated user profile', [
    'user_id' => 123,
    'fields_changed' => ['email', 'role']
]);
?>
```

### Custom Login Implementation

```php
<?php
require_once __DIR__ . '/includes/AdminAuthManager.php';

if ($_POST) {
    $adminAuth = new AdminAuthManager();
    $result = $adminAuth->login($_POST['email'], $_POST['password']);
    
    if ($result['success']) {
        header('Location: dashboard.php');
        exit;
    } else {
        $error = $result['message'];
    }
}
?>
```

## Security Features

1. **Secure Password Hashing**: Uses PHP's `password_hash()` with `PASSWORD_DEFAULT`
2. **SQL Injection Protection**: All database queries use parameterized statements
3. **Session Security**: Admin sessions are completely separate from user sessions
4. **Activity Logging**: All admin actions are logged with IP address and timestamp
5. **Role-Based Access**: Granular permission system for different admin levels
6. **Session Timeout**: Automatic session expiration after inactivity

## Migration from Old System

If you're migrating from the old system that used the common `auth.php` file:

1. **Replace includes**: Change `require_once '../includes/auth.php'` to `require_once 'includes/admin-auth.php'`
2. **Update session checks**: Replace `$_SESSION['admin_id']` with `getCurrentAdminId()` or `getCurrentAdmin()`
3. **Update role checks**: Use `checkAdminRole()` or `hasRole()` methods
4. **Update logging**: Replace manual logging with `logAdminAction()` or `logActivity()`

## Backward Compatibility

The system maintains backward compatibility with existing code by:
- Setting legacy session variables (`$_SESSION['admin_id']`, `$_SESSION['admin_role']`, etc.)
- Providing legacy function names (`checkAdminRole()`, `logAdminAction()`, etc.)
- Maintaining the same redirect behavior

## Best Practices

1. **Always include authentication**: Include `admin-auth.php` at the top of every admin page
2. **Use role/permission checks**: Don't rely only on login status - check specific roles/permissions
3. **Log important actions**: Use activity logging for audit trails
4. **Handle errors gracefully**: Always check return values from authentication methods
5. **Keep sessions secure**: The system handles session security automatically

## Troubleshooting

### Common Issues

1. **"Headers already sent" error**: Make sure `admin-auth.php` is included before any HTML output
2. **Login not working**: Check that the `admin_users` table exists and has the correct schema
3. **Permissions not working**: Ensure permissions are stored as valid JSON array in the database
4. **Session timeout**: Adjust the `checkSessionValidity()` timeout parameter if needed

### Debug Mode

Enable error logging to troubleshoot issues:

```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
```

Check your PHP error log for detailed error messages.
