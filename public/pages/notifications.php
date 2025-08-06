<?php
// Start session first
session_start();

define('SECURE_ACCESS', true);
require_once '../secure_config.php';
loadSecureConfig('supabase.php');
loadSecureInclude('SupabaseClient.php');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "register/login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

try {
    // Initialize Supabase connection with user-level permissions (anon key)
    $supabase = new SupabaseClient();
    
    // Clean up old notifications (older than 7 days) by soft-deleting them
    $seven_days_ago = date('Y-m-d H:i:s', strtotime('-7 days'));
    $old_notifications = $supabase->select('notifications', 'id', [
        'user_id' => $user_id,
        'deleted_at' => 'is.null',
        'created_at' => 'lt.' . $seven_days_ago
    ]);
    
    if (!empty($old_notifications)) {
        foreach ($old_notifications as $old_notification) {
            $supabase->update('notifications', 
                ['deleted_at' => date('Y-m-d H:i:s')], 
                ['id' => $old_notification['id']]
            );
        }
    }
    
    // Get all notifications for the user (excluding soft-deleted ones)
    $notifications = $supabase->select('notifications', '*', [
        'user_id' => $user_id,
        'deleted_at' => 'is.null'
    ], ['created_at' => 'desc']);
    
    // Mark all unread notifications as read
    if (!empty($notifications)) {
        $unread_ids = [];
        foreach ($notifications as $notification) {
            if (!$notification['is_read']) {
                $unread_ids[] = $notification['id'];
            }
        }
        
        if (!empty($unread_ids)) {
            // Update each unread notification to read status
            foreach ($unread_ids as $id) {
                $supabase->update('notifications', 
                    ['is_read' => true], 
                    ['id' => $id]
                );
            }
        }
    }
} catch (Exception $e) {
    error_log("Error fetching notifications: " . $e->getMessage());
    $notifications = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/notifications.css">
</head>
<body>
    <main>
        <article>
            <section class="notifications-section">
                <div class="container">
                    <h2 class="section-title">Notifications</h2>
                    
                    <?php if (empty($notifications)): ?>
                        <div class="alert alert-info">
                            You have no notifications.
                        </div>
                    <?php else: ?>
                        <div class="notifications-list">
                            <?php foreach ($notifications as $notification): ?>
                                <div class="notification-card <?php echo $notification['is_read'] ? 'read' : 'unread'; ?>">
                                    <div class="notification-content">
                                        <p class="message"><?php echo htmlspecialchars($notification['message']); ?></p>
                                        <span class="time"><?php echo date('M d, Y H:i', strtotime($notification['created_at'])); ?></span>
                                    </div>
                                    <?php if ($notification['type'] === 'join_request'): ?>
                                        <div class="notification-actions">
                                            <a href="pages/teams/yourteams.php" class="btn btn-primary">View Team</a>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </article>
    </main>
</body>
</html>

<?php loadSecureInclude('footer.php'); ?>
