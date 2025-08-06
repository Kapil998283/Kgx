<?php
// Define admin secure access
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

require_once 'common.php';

// Use the new admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

// Set JSON response headers
header('Content-Type: application/json');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

$supabase = getSupabaseClient(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Get POST data
    $action = $_POST['action'] ?? '';
    $screenshot_id = $_POST['screenshot_id'] ?? '';
    $notes = $_POST['notes'] ?? '';
    
    // Validate required fields
    if (empty($action) || empty($screenshot_id)) {
        throw new Exception('Missing required fields');
    }
    
    if (!in_array($action, ['approve', 'reject'])) {
        throw new Exception('Invalid action');
    }
    
    $screenshot_id = intval($screenshot_id);
    if ($screenshot_id <= 0) {
        throw new Exception('Invalid screenshot ID');
    }
    
    // Get the screenshot details
    $screenshot = $supabase->select('match_screenshots', '*', ['id' => $screenshot_id]);
    if (empty($screenshot)) {
        throw new Exception('Screenshot not found');
    }
    $screenshot = $screenshot[0];
    
    // Determine verification status
    $verified = ($action === 'approve') ? true : false;
    
    // Update screenshot verification status
    $update_data = [
        'verified' => $verified,
        'admin_notes' => $notes,
        'verified_at' => date('Y-m-d H:i:s')
    ];
    
    // Add verified_by if we have a valid admin user ID
    if (isset($_SESSION['admin_user']['id']) && is_numeric($_SESSION['admin_user']['id'])) {
        $update_data['verified_by'] = intval($_SESSION['admin_user']['id']);
    } elseif (isset($_SESSION['admin_id']) && is_numeric($_SESSION['admin_id'])) {
        $update_data['verified_by'] = intval($_SESSION['admin_id']);
    }
    
    // Debug: Log the update data to see what we're trying to update
    error_log("Updating screenshot {$screenshot_id} with data: " . json_encode($update_data));
    error_log("Admin session data: " . json_encode($_SESSION));
    
    $result = $supabase->update('match_screenshots', $update_data, ['id' => $screenshot_id]);
    
    if (!$result) {
        throw new Exception('Failed to update screenshot status');
    }
    
    // Get user details for notification
    $user = $supabase->select('users', '*', ['id' => $screenshot['user_id']]);
    if (!empty($user)) {
        $user = $user[0];
        
        // Get match details for notification
        $match = $supabase->select('matches', '*', ['id' => $screenshot['match_id']]);
        if (!empty($match)) {
            $match = $match[0];
            
            // Get game details
            $game = $supabase->select('games', '*', ['id' => $match['game_id']]);
            $game_name = !empty($game) ? $game[0]['name'] : 'Unknown Game';
            
            // Create notification message
            if ($verified) {
                $message = "Your screenshot proof for {$game_name} match has been approved by admin.";
                if ($notes) {
                    $message .= " Admin notes: " . $notes;
                }
            } else {
                $message = "Your screenshot proof for {$game_name} match has been rejected by admin.";
                if ($notes) {
                    $message .= " Reason: " . $notes;
                }
            }
            
            // Insert notification
            $supabase->insert('notifications', [
                'user_id' => $screenshot['user_id'],
                'type' => $verified ? 'proof_approved' : 'proof_rejected',
                'message' => $message,
                'related_id' => $screenshot['match_id'],
                'related_type' => 'match',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        }
    }
    
    // Log the action
    error_log("Screenshot {$screenshot_id} {$action}d by admin. Notes: {$notes}");
    
    echo json_encode([
        'success' => true, 
        'message' => 'Screenshot ' . ($verified ? 'approved' : 'rejected') . ' successfully'
    ]);

} catch (Exception $e) {
    error_log("Screenshot verification error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
