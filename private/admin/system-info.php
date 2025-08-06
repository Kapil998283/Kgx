<?php
/**
 * System Information Display
 * Shows paths and permissions for debugging cross-platform issues
 */

// Define admin secure access
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once __DIR__ . '/admin_secure_config.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Information - KGX Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.0/css/bootstrap.min.css">
    <style>
        body { background: #1a1a1a; color: #fff; }
        .container { margin-top: 2rem; }
        .card { background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); }
        .card-header { background: rgba(0, 200, 150, 0.1); border-bottom: 1px solid rgba(0, 200, 150, 0.2); }
        .table-dark { --bs-table-bg: rgba(255, 255, 255, 0.02); }
        .badge.bg-success { background-color: #28a745 !important; }
        .badge.bg-danger { background-color: #dc3545 !important; }
        .badge.bg-warning { background-color: #ffc107 !important; color: #000; }
    </style>
</head>
<body>
    <div class="container">
        <div class="row">
            <div class="col-12">
                <h1 class="text-center mb-4" style="color: #00c896;">KGX Admin System Information</h1>
            </div>
        </div>

        <!-- System Information -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">System Environment</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-dark table-sm">
                            <tr>
                                <td>Operating System</td>
                                <td><?php echo PHP_OS; ?></td>
                            </tr>
                            <tr>
                                <td>PHP Version</td>
                                <td><?php echo PHP_VERSION; ?></td>
                            </tr>
                            <tr>
                                <td>Web Server</td>
                                <td><?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?></td>
                            </tr>
                            <tr>
                                <td>Environment</td>
                                <td>
                                    <span class="badge <?php echo ADMIN_ENVIRONMENT === 'development' ? 'bg-warning' : 'bg-success'; ?>">
                                        <?php echo strtoupper(ADMIN_ENVIRONMENT); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td>Debug Mode</td>
                                <td>
                                    <span class="badge <?php echo ADMIN_DEBUG ? 'bg-warning' : 'bg-success'; ?>">
                                        <?php echo ADMIN_DEBUG ? 'ENABLED' : 'DISABLED'; ?>
                                    </span>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Directory Permissions</h5>
                    </div>
                    <div class="card-body">
                        <?php
                        $dirs = [
                            'Admin Logs' => ADMIN_LOGS_PATH,
                            'Admin Temp' => ADMIN_TEMP_PATH,
                            'Admin Uploads' => ADMIN_UPLOADS_PATH,
                            'Private Path' => ADMIN_PRIVATE_PATH,
                            'Admin Path' => ADMIN_PATH
                        ];
                        ?>
                        <table class="table table-dark table-sm">
                            <?php foreach ($dirs as $name => $path): ?>
                            <tr>
                                <td><?php echo $name; ?></td>
                                <td>
                                    <?php if (is_dir($path)): ?>
                                        <?php if (is_writable($path)): ?>
                                            <span class="badge bg-success">WRITABLE</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">READ-ONLY</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="badge bg-danger">NOT EXISTS</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Path Information -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Admin Paths Configuration</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-dark table-sm">
                            <thead>
                                <tr>
                                    <th>Constant</th>
                                    <th>Path</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $constants = [
                                    'ADMIN_ROOT_PATH',
                                    'ADMIN_PRIVATE_PATH', 
                                    'ADMIN_PUBLIC_PATH',
                                    'ADMIN_PATH',
                                    'ADMIN_LOGS_PATH',
                                    'ADMIN_TEMP_PATH',
                                    'ADMIN_UPLOADS_PATH',
                                    'ADMIN_INCLUDES_PATH',
                                    'ADMIN_CONFIG_PATH'
                                ];
                                
                                foreach ($constants as $constant):
                                    if (defined($constant)):
                                        $path = constant($constant);
                                        $exists = is_dir($path);
                                        $writable = $exists && is_writable($path);
                                ?>
                                <tr>
                                    <td><code><?php echo $constant; ?></code></td>
                                    <td><small><?php echo htmlspecialchars($path); ?></small></td>
                                    <td>
                                        <?php if ($exists): ?>
                                            <span class="badge bg-success">EXISTS</span>
                                            <?php if ($writable): ?>
                                                <span class="badge bg-success">WRITABLE</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">READ-ONLY</span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge bg-danger">NOT FOUND</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- URL Information -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Admin URLs Configuration</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-dark table-sm">
                            <thead>
                                <tr>
                                    <th>Constant</th>
                                    <th>URL</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $urlConstants = [
                                    'ADMIN_BASE_URL',
                                    'ADMIN_PUBLIC_URL',
                                    'ADMIN_ASSETS_URL',
                                    'ADMIN_DASHBOARD_URL',
                                    'ADMIN_USERS_URL'
                                ];
                                
                                foreach ($urlConstants as $constant):
                                    if (defined($constant)):
                                ?>
                                <tr>
                                    <td><code><?php echo $constant; ?></code></td>
                                    <td><small><?php echo htmlspecialchars(constant($constant)); ?></small></td>
                                </tr>
                                <?php 
                                    endif;
                                endforeach; 
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Server Information -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">Server Variables</h5>
                    </div>
                    <div class="card-body">
                        <table class="table table-dark table-sm">
                            <?php
                            $serverVars = [
                                'DOCUMENT_ROOT' => $_SERVER['DOCUMENT_ROOT'] ?? 'Not set',
                                'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? 'Not set',
                                'HTTP_HOST' => $_SERVER['HTTP_HOST'] ?? 'Not set',
                                'SERVER_NAME' => $_SERVER['SERVER_NAME'] ?? 'Not set',
                                'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? 'Not set'
                            ];
                            
                            foreach ($serverVars as $var => $value):
                            ?>
                            <tr>
                                <td><code>$_SERVER['<?php echo $var; ?>']</code></td>
                                <td><small><?php echo htmlspecialchars($value); ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-center mb-4">
            <a href="login.php" class="btn btn-success">Back to Admin Login</a>
        </div>
    </div>
</body>
</html>
