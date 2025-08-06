<?php
// Advanced Debugging for Teams Section

// Import necessary configurations and initialize clients
require_once '../secure_config.php';
loadSecureConfig('supabase.php');
$authManager = new AuthManager();
$supabaseClient = new SupabaseClient();

// Function to log debug messages
function debug_log($message) {
    file_put_contents('debug_log.txt', date('Y-m-d H:i:s') . " - " . $message . PHP_EOL, FILE_APPEND);
}

// Debugging function for checking team visibility and membership
function debugTeamIssues($userId) {
    global $supabaseClient;

    debug_log("Starting team debug for user: $userId");

    try {
        // Fetch all active teams
        $activeTeams = $supabaseClient->select('teams', '*', ['is_active' => true]);
        if (empty($activeTeams)) {
            debug_log("No active teams found.");
        } else {
            debug_log("Active teams found: " . count($activeTeams));
        }

        // Check for user's membership
        $userTeams = $supabaseClient->select('team_members', '*', ['user_id' => $userId, 'status' => 'active']);
        if (empty($userTeams)) {
            debug_log("User is not a member of any active team.");
        } else {
            foreach ($userTeams as $membership) {
                $teamDetails = $supabaseClient->select('teams', '*', ['id' => $membership['team_id'], 'is_active' => true]);
                if (!empty($teamDetails)) {
                    debug_log("User is a member or captain of team: " . $teamDetails[0]['name']);
                } else {
                    debug_log("User's team is not active or not found: Team ID " . $membership['team_id']);
                }
            }
        }

        // Check pending join requests
        $pendingRequests = $supabaseClient->select('team_join_requests', '*', ['user_id' => $userId, 'status' => 'pending']);
        if (!empty($pendingRequests)) {
            debug_log("User has pending requests for teams.");
            foreach ($pendingRequests as $request) {
                $teamName = $supabaseClient->select('teams', 'name', ['id' => $request['team_id']]);
                debug_log("Pending request for team: " . ($teamName[0]['name'] ?? "Unknown"));
            }
        }

    } catch (Exception $e) {
        debug_log("Exception encountered: " . $e->getMessage());
    }

    debug_log("Team debug completed for user: $userId");
}

// Perform debugging for current user
if ($authManager->isLoggedIn()) {
    $currentUser = $authManager->getCurrentUser();
    debugTeamIssues($currentUser['user_id']);
} else {
    debug_log("User not logged in. Debugging could not be performed.");
}

?>
