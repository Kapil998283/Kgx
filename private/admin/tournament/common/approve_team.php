<?php
// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once dirname(__DIR__, 2) . '/admin_secure_config.php';

// Use the new admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';
require_once ADMIN_INCLUDES_PATH . 'notification-helper.php';

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['registration_id'])) {
        throw new Exception('Registration ID is required');
    }

    $supabase = new SupabaseClient(true);

    // Get registration details
    $registration_data = $supabase->select('tournament_registrations', '*, teams(name), tournaments(name)', ['id' => $data['registration_id']], null, 1);
    $registration = !empty($registration_data) ? $registration_data[0] : null;

    if (!$registration) {
        throw new Exception('Registration not found');
    }

    // Update registration status
    $result = $supabase->update('tournament_registrations', ['status' => 'approved'], ['id' => $data['registration_id']]);

    if ($result) {
        // Send notification to team members
        NotificationHelper::sendToTeam(
            $registration['team_id'],
            "Tournament Registration Approved!",
            "Your team {$registration['teams']['name']} has been approved for {$registration['tournaments']['name']}!",
            "/KGX/pages/tournaments/details.php?id={$registration['tournament_id']}"
        );

        echo json_encode(['success' => true, 'message' => 'Team approved successfully']);
    } else {
        throw new Exception('Failed to approve team');
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} 