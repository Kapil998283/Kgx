<?php
// Suppress error output to prevent corrupting HTML
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

session_start();

// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once dirname(__DIR__) . '/admin_secure_config.php';

// Load admin configuration
$adminConfig = loadAdminConfig('admin_config.php');

// Use the new admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

// Get database connection
$conn = getSupabaseConnection();

// Initialize default values
$total_items = 0;
$total_redemptions = 0;
$low_stock_count = 0;
$pending_requests = 0;

try {
    // Get total items count
    $items_result = $conn->select('redeemable_items', 'COUNT(*) as total');
    $total_items = !empty($items_result) ? $items_result[0]['total'] : 0;
    
    // Get total redemptions
    $redemptions_result = $conn->select('redemption_history', 'COUNT(*) as total');
    $total_redemptions = !empty($redemptions_result) ? $redemptions_result[0]['total'] : 0;
    
    // Get recent redemptions
    $recent_redemptions = $conn->select(
        'redemption_history rh',
        'rh.*, ri.name as item_name, u.username',
        [],
        'rh.redeemed_at DESC',
        5,
        'JOIN redeemable_items ri ON rh.item_id = ri.id JOIN users u ON rh.user_id = u.id'
    );
    
    // Get low stock items
    $low_stock_items = $conn->select(
        'redeemable_items',
        '*',
        ['stock <' => 5, 'is_unlimited' => 0],
        'stock ASC'
    );
    $low_stock_count = count($low_stock_items);
    
    // Get pending redemption requests
    $pending_result = $conn->select(
        'redemption_history rh',
        'COUNT(*) as total',
        ['rh.status' => 'pending', 'ri.requires_approval' => 1],
        null,
        null,
        'JOIN redeemable_items ri ON rh.item_id = ri.id'
    );
    $pending_requests = !empty($pending_result) ? $pending_result[0]['total'] : 0;
    
} catch (Exception $e) {
    error_log("Admin Dashboard Error: " . $e->getMessage());
    // Keep default values of 0
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Items Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 d-md-block bg-dark sidebar collapse">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link active text-white" href="./index.php">
                                <i class="bi bi-speedometer2"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white" href="add_item.php">
                                <i class="bi bi-plus-circle"></i> Add Item
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white" href="manage_items.php">
                                <i class="bi bi-list"></i> Manage Items
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white" href="redemption_request.php">
                                <i class="bi bi-clock-history"></i> Redemption Request
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Redeem Dashboard</h1>
                </div>

                <!-- Statistics Cards -->
                <div class="row">
                    <div class="col-md-4 mb-4">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <h5 class="card-title">Total Items</h5>
                                <h2 class="card-text"><?php echo $total_items; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-4">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <h5 class="card-title">Total Redemptions</h5>
                                <h2 class="card-text"><?php echo $total_redemptions; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-4">
                        <div class="card bg-warning text-dark">
                            <div class="card-body">
                                <h5 class="card-title">Low Stock Items</h5>
                                <h2 class="card-text"><?php echo $low_stock_count; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-4">
                        <div class="card bg-info text-white">
                            <div class="card-body">
                                <h5 class="card-title">Requested Redemptions</h5>
                                <h2 class="card-text"><?php echo $pending_requests; ?></h2>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Redemptions -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Recent Redemptions</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>User</th>
                                        <th>Item</th>
                                        <th>Coins Spent</th>
                                        <th>Status</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($recent_redemptions)): ?>
                                        <?php foreach ($recent_redemptions as $redemption): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($redemption['username']); ?></td>
                                            <td><?php echo htmlspecialchars($redemption['item_name']); ?></td>
                                            <td><?php echo $redemption['coins_spent']; ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $redemption['status'] == 'completed' ? 'success' : ($redemption['status'] == 'pending' ? 'warning' : 'danger'); ?>">
                                                    <?php echo ucfirst($redemption['status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('Y-m-d H:i', strtotime($redemption['redeemed_at'])); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center">No recent redemptions found</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Low Stock Items -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Low Stock Items</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Item Name</th>
                                        <th>Current Stock</th>
                                        <th>Coin Cost</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($low_stock_items)): ?>
                                        <?php foreach ($low_stock_items as $item): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($item['name']); ?></td>
                                            <td>
                                                <span class="badge bg-danger"><?php echo $item['stock']; ?></span>
                                            </td>
                                            <td><?php echo $item['coin_cost']; ?></td>
                                            <td>
                                                <a href="edit_item.php?id=<?php echo $item['id']; ?>" class="btn btn-sm btn-primary">Edit</a>
                                                <a href="update_stock.php?id=<?php echo $item['id']; ?>" class="btn btn-sm btn-success">Update Stock</a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center">No low stock items found</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 