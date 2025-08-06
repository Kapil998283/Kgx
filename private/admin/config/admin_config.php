<?php
/**
 * Admin Configuration File
 * Contains admin-specific settings and configurations
 * 
 * @version 1.0
 * @author KGX Admin System
 */

// Security check
if (!defined('ADMIN_SECURE_ACCESS')) {
    die('Direct access forbidden');
}

// Admin System Configuration
return [
    // System Settings
    'system' => [
        'name' => 'KGX Admin Panel',
        'version' => '1.0.0',
        'timezone' => 'UTC',
        'maintenance_mode' => false,
        'debug_mode' => ADMIN_DEBUG,
        'session_timeout' => 3600, // 1 hour
        'max_login_attempts' => 5,
        'lockout_duration' => 900, // 15 minutes
    ],
    
    // Security Settings
    'security' => [
        'csrf_protection' => true,
        'rate_limiting' => true,
        'ip_whitelist' => [], // Empty = allow all
        'two_factor_auth' => false,
        'password_policy' => [
            'min_length' => 8,
            'require_uppercase' => true,
            'require_lowercase' => true,
            'require_numbers' => true,
            'require_symbols' => false,
        ],
        'session_security' => [
            'regenerate_id' => true,
            'httponly_cookies' => true,
            'secure_cookies' => false, // Set to true for HTTPS
            'same_site' => 'Strict',
        ],
    ],
    
    // UI Settings
    'ui' => [
        'theme' => 'dark',
        'items_per_page' => 25,
        'sidebar_collapsed' => false,
        'show_breadcrumbs' => true,
        'date_format' => 'Y-m-d H:i:s',
        'currency_symbol' => '$',
        'time_format' => '24h',
    ],
    
    // Logging Settings
    'logging' => [
        'enabled' => true,
        'level' => ADMIN_DEBUG ? 'DEBUG' : 'INFO',
        'max_file_size' => '10MB',
        'retention_days' => 30,
        'log_admin_actions' => true,
        'log_user_changes' => true,
        'log_system_events' => true,
    ],
    
    // Email Settings (for admin notifications)
    'email' => [
        'enabled' => false,
        'from_address' => 'admin@kgx.com',
        'from_name' => 'KGX Admin System',
        'smtp_host' => '',
        'smtp_port' => 587,
        'smtp_username' => '',
        'smtp_password' => '',
        'smtp_encryption' => 'tls',
    ],
    
    // File Upload Settings
    'uploads' => [
        'max_file_size' => '5MB',
        'allowed_types' => ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'],
        'upload_path' => ADMIN_UPLOADS_PATH,
        'create_thumbnails' => true,
        'thumbnail_sizes' => [
            'small' => [150, 150],
            'medium' => [300, 300],
            'large' => [800, 600],
        ],
    ],
    
    // Database Settings
    'database' => [
        'backup_enabled' => true,
        'backup_frequency' => 'daily',
        'backup_retention' => 7, // days
        'optimize_tables' => true,
        'query_logging' => ADMIN_DEBUG,
    ],
    
    // Cache Settings
    'cache' => [
        'enabled' => true,
        'driver' => 'file', // file, redis, memcached
        'ttl' => 3600, // 1 hour
        'prefix' => 'admin_',
    ],
    
    // API Settings
    'api' => [
        'enabled' => true,
        'rate_limit' => 100, // requests per hour
        'require_auth' => true,
        'allowed_origins' => ['localhost'],
    ],
    
    // Module Settings
    'modules' => [
        'dashboard' => [
            'enabled' => true,
            'show_stats' => true,
            'refresh_interval' => 30, // seconds
        ],
        'users' => [
            'enabled' => true,
            'allow_user_creation' => true,
            'allow_user_deletion' => false,
            'export_enabled' => true,
        ],
        'tournaments' => [
            'enabled' => true,
            'auto_status_update' => true,
            'notification_enabled' => true,
        ],
        'matches' => [
            'enabled' => true,
            'live_scoring' => true,
            'result_validation' => true,
        ],
        'streams' => [
            'enabled' => true,
            'auto_thumbnail' => true,
            'quality_check' => false,
        ],
    ],
    
    // Notification Settings
    'notifications' => [
        'enabled' => true,
        'channels' => ['email', 'dashboard'],
        'events' => [
            'user_registration' => true,
            'tournament_creation' => true,
            'match_completion' => true,
            'system_errors' => true,
            'security_alerts' => true,
        ],
    ],
    
    // Backup Settings
    'backup' => [
        'enabled' => true,
        'include_uploads' => true,
        'compression' => true,
        'encryption' => false,
        'remote_storage' => false,
    ],
    
    // Development Settings
    'development' => [
        'show_errors' => ADMIN_DEBUG,
        'profiling' => ADMIN_DEBUG,
        'query_debugging' => ADMIN_DEBUG,
        'template_debugging' => ADMIN_DEBUG,
    ],
];
?>
