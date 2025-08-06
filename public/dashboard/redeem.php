<?php
define('SECURE_ACCESS', true);
require_once '../secure_config.php';
loadSecureConfig('supabase.php');
loadSecureInclude('auth.php');

// Initialize AuthManager and SupabaseClient
$authManager = new AuthManager();
$supabaseClient = new SupabaseClient();

// Check if user is logged in
if (!$authManager->isLoggedIn()) {
    header("Location: " . BASE_URL . "register/login.php");
    exit();
}

$currentUser = $authManager->getCurrentUser();

$user_id = $currentUser['user_id'];
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']); // Clear messages after displaying

// Get user's coin balance
try {
    $user_coins_data = $supabaseClient->select('user_coins', 'coins', [
        'user_id' => $user_id
    ]);
    $coin_balance = !empty($user_coins_data) ? $user_coins_data[0]['coins'] : 0;
} catch (Exception $e) {
    error_log("Error fetching user coins: " . $e->getMessage());
    $coin_balance = 0;
}

// Fetch items
try {
    // Using custom SQL for complex conditions
    $redeemable_items = $supabaseClient->query(
        "SELECT * FROM redeemable_items WHERE (stock > 0 OR is_unlimited = true) AND is_active = true ORDER BY id"
    );
    if (empty($redeemable_items)) {
        $redeemable_items = [];
    }
} catch (Exception $e) {
    error_log("Error fetching redeemable items: " . $e->getMessage());
    $redeemable_items = [];
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Redeem Center</title>
    <link rel="stylesheet" href="../assets/css/root.css">
    <link rel="stylesheet" href="../assets/css/dashboard/redeem.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>
    <div class="page-header">
        <a href="./index.php" class="back-button">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        <button class="history-toggle" onclick="toggleHistory()">
            <i class="fas fa-history"></i> View History
        </button>
    </div>

    <div class="main-content" id="mainContent">
        <h2 class="page-title">Redeem Center</h2>

        <?php if($success_message): ?>
            <div class="alert alert-success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        
        <?php if($error_message): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <div class="coin-balance">
            Your Balance: <strong><?php echo number_format($coin_balance); ?> Coins</strong>
        </div>

        <div class="redeem-container">
            <!-- Coin to Ticket Conversion Card -->
            <div class="redeem-card conversion-card">
                <img src="../assets/images/ticket-icon.png" alt="Ticket">
                <h3>Convert Coins to Ticket</h3>
                <p>Exchange 200 Coins for 1 Ticket</p>
                <p>Use tickets for special entries!</p>
                <div class="item-details">
                    <div class="detail">
                        <div class="detail-label">Cost</div>
                        <div class="detail-value">200 Coins</div>
                    </div>
                    <div class="detail">
                        <div class="detail-label">Get</div>
                        <div class="detail-value">1 Ticket</div>
                    </div>
                </div>
                <form method="POST" onsubmit="return confirm('Convert 200 coins to 1 ticket?');">
                    <button type="submit" name="convert_ticket" <?php echo ($coin_balance < 200) ? 'disabled' : ''; ?>>
                        Convert Now
                    </button>
                </form>
            </div>

            <?php foreach($redeemable_items as $item): ?>
            <div class="redeem-card">
                <img src="<?php echo htmlspecialchars($item['image_url']); ?>" 
                     alt="<?php echo htmlspecialchars($item['name']); ?>">
                <h3><?php echo htmlspecialchars($item['name']); ?></h3>
                <p><?php echo htmlspecialchars($item['description']); ?></p>
                <div class="item-details">
                    <div class="detail">
                        <div class="detail-label">Cost</div>
                        <div class="detail-value"><?php echo number_format($item['coin_cost']); ?> Coins</div>
                    </div>
                    <div class="detail">
                        <div class="detail-label">Stock</div>
                        <div class="detail-value"><?php echo $item['is_unlimited'] ? 'Unlimited' : number_format($item['stock']) . ' left'; ?></div>
                    </div>
                </div>
                <form method="POST" onsubmit="return confirm('Are you sure you want to redeem this item?');">
                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                    <input type="hidden" name="coin_cost" value="<?php echo $item['coin_cost']; ?>">
                    <button type="submit" name="redeem" <?php echo ($coin_balance < $item['coin_cost']) ? 'disabled' : ''; ?>>
                        Redeem Now
                    </button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- History Section -->
    <div class="redemption-history" id="historySection">
        <div class="cardHeader">
            <h2>Redemption History</h2>
        </div>
        <table>
            <thead>
                <tr>
                    <td>Name</td>
                    <td>Price</td>
                    <td>Status</td>
                    <td>Date</td>
                </tr>
            </thead>
            <tbody>
                <?php 
                // Fetch redeemed items using SupabaseClient
                try {
                    $redemption_items = $supabaseClient->query(
                        "SELECT ri.name, rh.coins_spent, rh.status, rh.redeemed_at
                         FROM redemption_history rh
                         JOIN redeemable_items ri ON rh.item_id = ri.id
                         WHERE rh.user_id = $1
                         ORDER BY rh.redeemed_at DESC",
                        [$user_id]
                    );
                    if (empty($redemption_items)) {
                        $redemption_items = [];
                    }
                } catch (Exception $e) {
                    error_log("Error fetching redemption history: " . $e->getMessage());
                    $redemption_items = [];
                }

                if (count($redemption_items) > 0):
                    foreach ($redemption_items as $row): 
                ?>
                    <tr>
                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                        <td><?php echo $row['coins_spent']; ?> Coins</td>
                        <td><span class="status <?php echo strtolower($row['status']); ?>"><?php echo $row['status']; ?></span></td>
                        <td><?php echo date('M d, Y', strtotime($row['redeemed_at'])); ?></td>
                    </tr>
                <?php 
                    endforeach; 
                else:
                ?>
                    <tr>
                        <td colspan="4" class="text-center">No redemption history found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <script>
        function toggleHistory() {
            const historySection = document.getElementById('historySection');
            const mainContent = document.getElementById('mainContent');
            const toggleButton = document.querySelector('.history-toggle');
            
            historySection.classList.toggle('active');
            mainContent.classList.toggle('hidden');
            
            // Update button text based on state
            if (historySection.classList.contains('active')) {
                toggleButton.innerHTML = '<i class="fas fa-times"></i> Back to Redeem';
            } else {
                toggleButton.innerHTML = '<i class="fas fa-history"></i> View History';
            }
        }

        // Show success/error messages temporarily
        const alerts = document.querySelectorAll('.alert');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.opacity = '0';
                setTimeout(() => alert.remove(), 300);
            }, 3000);
        });
    </script>
</body>
</html>

