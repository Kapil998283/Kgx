<?php
/**
 * Example Usage of Admin Secure Configuration System
 * 
 * This file demonstrates how to use the new admin configuration system
 * similar to the public secure_config.php system
 */

// Define admin secure access (required for all admin files)
define('ADMIN_SECURE_ACCESS', true);

// Load admin secure configuration
require_once __DIR__ . '/admin_secure_config.php';

// Load admin configuration
$adminConfig = loadAdminConfig('admin_config.php');

// Include the admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $adminConfig['system']['name']; ?> - Configuration Demo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #1a1a1a; color: #fff; }
        .card { background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); }
        .text-success { color: #00c896 !important; }
        .text-info { color: #17a2b8 !important; }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row">
            <div class="col-12">
                <h1 class="text-success mb-4">🎯 Admin Secure Configuration Demo</h1>
                <p class="lead">This page demonstrates the new admin secure configuration system.</p>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5>📁 Admin Path Constants</h5>
                    </div>
                    <div class="card-body">
                        <small>
                            <strong>ADMIN_BASE_URL:</strong> <?php echo ADMIN_BASE_URL; ?><br>
                            <strong>ADMIN_DASHBOARD_URL:</strong> <?php echo ADMIN_DASHBOARD_URL; ?><br>
                            <strong>ADMIN_ASSETS_URL:</strong> <?php echo ADMIN_ASSETS_URL; ?><br>
                            <strong>ADMIN_CSS_URL:</strong> <?php echo ADMIN_CSS_URL; ?><br>
                            <strong>ADMIN_JS_URL:</strong> <?php echo ADMIN_JS_URL; ?><br>
                            <strong>ADMIN_UPLOADS_URL:</strong> <?php echo ADMIN_UPLOADS_URL; ?>
                        </small>
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5>🔧 System Configuration</h5>
                    </div>
                    <div class="card-body">
                        <small>
                            <strong>System Name:</strong> <?php echo $adminConfig['system']['name']; ?><br>
                            <strong>Version:</strong> <?php echo $adminConfig['system']['version']; ?><br>
                            <strong>Environment:</strong> <?php echo ADMIN_ENVIRONMENT; ?><br>
                            <strong>Debug Mode:</strong> <?php echo ADMIN_DEBUG ? 'Enabled' : 'Disabled'; ?><br>
                            <strong>Session Timeout:</strong> <?php echo $adminConfig['system']['session_timeout']; ?>s<br>
                            <strong>Items Per Page:</strong> <?php echo $adminConfig['ui']['items_per_page']; ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5>🔒 Security Settings</h5>
                    </div>
                    <div class="card-body">
                        <small>
                            <strong>CSRF Protection:</strong> <?php echo $adminConfig['security']['csrf_protection'] ? 'Enabled' : 'Disabled'; ?><br>
                            <strong>Rate Limiting:</strong> <?php echo $adminConfig['security']['rate_limiting'] ? 'Enabled' : 'Disabled'; ?><br>
                            <strong>Password Min Length:</strong> <?php echo $adminConfig['security']['password_policy']['min_length']; ?><br>
                            <strong>2FA:</strong> <?php echo $adminConfig['security']['two_factor_auth'] ? 'Enabled' : 'Disabled'; ?>
                        </small>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5>📊 Logging Settings</h5>
                    </div>
                    <div class="card-body">
                        <small>
                            <strong>Logging:</strong> <?php echo $adminConfig['logging']['enabled'] ? 'Enabled' : 'Disabled'; ?><br>
                            <strong>Level:</strong> <?php echo $adminConfig['logging']['level']; ?><br>
                            <strong>Admin Actions:</strong> <?php echo $adminConfig['logging']['log_admin_actions'] ? 'Yes' : 'No'; ?><br>
                            <strong>Retention:</strong> <?php echo $adminConfig['logging']['retention_days']; ?> days
                        </small>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5>🎨 UI Settings</h5>
                    </div>
                    <div class="card-body">
                        <small>
                            <strong>Theme:</strong> <?php echo $adminConfig['ui']['theme']; ?><br>
                            <strong>Date Format:</strong> <?php echo $adminConfig['ui']['date_format']; ?><br>
                            <strong>Currency:</strong> <?php echo $adminConfig['ui']['currency_symbol']; ?><br>
                            <strong>Breadcrumbs:</strong> <?php echo $adminConfig['ui']['show_breadcrumbs'] ? 'Shown' : 'Hidden'; ?>
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5>🚀 Helper Functions Demo</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6>URL Generation Functions:</h6>
                                <small>
                                    <strong>adminUrl('dashboard/'):</strong><br>
                                    <?php echo adminUrl('dashboard/'); ?><br><br>
                                    
                                    <strong>adminCssUrl('admin.css'):</strong><br>
                                    <?php echo adminCssUrl('admin.css'); ?><br><br>
                                    
                                    <strong>adminJsUrl('admin.js'):</strong><br>
                                    <?php echo adminJsUrl('admin.js'); ?>
                                </small>
                            </div>
                            <div class="col-md-6">
                                <h6>Security Functions:</h6>
                                <small>
                                    <strong>generateAdminCSRF():</strong><br>
                                    <?php echo substr(generateAdminCSRF(), 0, 20) . '...'; ?><br><br>
                                    
                                    <strong>adminSanitize('&lt;script&gt;test&lt;/script&gt;'):</strong><br>
                                    <?php echo adminSanitize('<script>test</script>'); ?><br><br>
                                    
                                    <strong>Current Admin:</strong><br>
                                    <?php 
                                    $currentAdmin = getCurrentAdmin();
                                    echo $currentAdmin['full_name'] . ' (' . $currentAdmin['role'] . ')'; 
                                    ?>
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12 mb-4">
                <div class="card">
                    <div class="card-header">
                        <h5>📋 Usage Instructions</h5>
                    </div>
                    <div class="card-body">
                        <h6>1. Include in your admin files:</h6>
                        <pre class="bg-dark p-3 rounded"><code>// Define admin secure access
define('ADMIN_SECURE_ACCESS', true);

// Load admin secure configuration
require_once __DIR__ . '/admin_secure_config.php';

// Load admin configuration
$adminConfig = loadAdminConfig('admin_config.php');

// Include authentication (automatically loads config)
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';</code></pre>

                        <h6 class="mt-4">2. Use helper functions:</h6>
                        <pre class="bg-dark p-3 rounded"><code>// Generate URLs
$dashboardUrl = adminUrl('dashboard/');
$cssUrl = adminCssUrl('admin.css');

// Security functions
$csrfToken = generateAdminCSRF();
$cleanInput = adminSanitize($userInput);

// Admin info
$currentAdmin = getCurrentAdmin();
$isLoggedIn = isAdminLoggedIn();</code></pre>

                        <h6 class="mt-4">3. Access configuration:</h6>
                        <pre class="bg-dark p-3 rounded"><code>// Access config values
$itemsPerPage = $adminConfig['ui']['items_per_page'];
$debugMode = $adminConfig['system']['debug_mode'];
$maxFileSize = $adminConfig['uploads']['max_file_size'];</code></pre>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">
                <p class="text-center">
                    <a href="<?php echo adminUrl('dashboard/'); ?>" class="btn btn-success">← Back to Dashboard</a>
                </p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
