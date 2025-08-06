<?php

// CRITICAL: Suppress ALL error output to prevent corrupting HTML title
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Set error handler to suppress all output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Match Management Error: $errstr in $errfile on line $errline");
    return true; // Don't execute PHP internal error handler
});

// Load admin secure configuration
require_once dirname(__DIR__) . '/admin_secure_config.php';

// Common database interaction function
function getSupabaseClient($useServiceRole = false) {
    global $supabase;
    if (!isset($supabase)) {
        require_once dirname(__DIR__) . '/admin_secure_config.php';
        $supabase = new SupabaseClient($useServiceRole);
    }
    return $supabase;
}

// Common match operations
function fetchMatches($supabase, $gameName) {
    $games = $supabase->select('games', '*', ['name' => $gameName]);
    $gameId = !empty($games) ? $games[0]['id'] : null;
    return $supabase->select('matches', '*', ['game_id' => $gameId], 'match_date.desc');
}

function enrichMatchesWithDetails($supabase, &$matches) {
    foreach ($matches as &$match) {
        if ($match['game_id']) {
            $game = $supabase->select('games', '*', ['id' => $match['game_id']]);
            if (!empty($game)) {
                $match['game_name'] = $game[0]['name'];
                $match['game_image'] = $game[0]['image_url'];
            }
        }

        if ($match['team1_id']) {
            $team1 = $supabase->select('teams', '*', ['id' => $match['team1_id']]);
            if (!empty($team1)) {
                $match['team1_name'] = $team1[0]['name'];
            }
        }

        if ($match['team2_id']) {
            $team2 = $supabase->select('teams', '*', ['id' => $match['team2_id']]);
            if (!empty($team2)) {
                $match['team2_name'] = $team2[0]['name'];
            }
        }

        if ($match['tournament_id']) {
            $tournament = $supabase->select('tournaments', '*', ['id' => $match['tournament_id']]);
            if (!empty($tournament)) {
                $match['tournament_name'] = $tournament[0]['name'];
            }
        }

        $participants = $supabase->select('match_participants', '*', ['match_id' => $match['id']]);
        $match['current_participants'] = count($participants);

        $match['winner_name'] = null;
        if ($match['winner_id']) {
            if ($match['winner_id'] == $match['team1_id'] && isset($match['team1_name'])) {
                $match['winner_name'] = $match['team1_name'];
            } elseif ($match['winner_id'] == $match['team2_id'] && isset($match['team2_name'])) {
                $match['winner_name'] = $match['team2_name'];
            }
        }

        // Keep the original datetime format for consistency with user side
        if ($match['match_date']) {
            // Ensure we have a proper datetime string
            $datetime = new DateTime($match['match_date']);
            // Keep the full datetime in match_date for user side compatibility
            $match['match_date'] = $datetime->format('Y-m-d H:i:s');
            // Also set match_time for backward compatibility
            $match['match_time'] = $datetime->format('H:i:s');
        }
    }
    unset($match);
}

function updateMatchStatus($supabase, $matchId, $status, $additionalFields = []) {
    $fields = array_merge(['status' => $status], $additionalFields);
    $supabase->update('matches', $fields, ['id' => $matchId]);
}

function sendNotification($supabase, $userId, $type, $message, $relatedId) {
    $supabase->insert('notifications', [
        'user_id' => $userId,
        'type' => $type,
        'message' => $message,
        'related_id' => $relatedId,
        'related_type' => 'match'
    ]);
}

function refundParticipants($supabase, $match_id, $entry_fee, $entry_type) {
    $participants = $supabase->select('match_participants', [
        'select' => 'user_id',
        'where' => 'match_id = $1',
        'params' => [$match_id]
    ]);

    foreach ($participants as $participant) {
        $user_id = $participant['user_id'];

        if ($entry_type === 'coins') {
            $current_coins = $supabase->select('user_coins', [
                'select' => 'coins',
                'where' => 'user_id = $1',
                'params' => [$user_id],
                'single' => true
            ]);
            $new_coins = ($current_coins['coins'] ?? 0) + $entry_fee;

            $supabase->update('user_coins', [
                'coins' => $new_coins
            ], ['user_id' => $user_id]);
        } else {
            $current_tickets = $supabase->select('user_tickets', [
                'select' => 'tickets',
                'where' => 'user_id = $1',
                'params' => [$user_id],
                'single' => true
            ]);
            $new_tickets = ($current_tickets['tickets'] ?? 0) + $entry_fee;

            $supabase->update('user_tickets', [
                'tickets' => $new_tickets
            ], ['user_id' => $user_id]);
        }

        $supabase->insert('transactions', [
            'user_id' => $user_id,
            'amount' => $entry_fee,
            'type' => 'refund',
            'description' => "Refund for cancelled match #" . $match_id,
            'currency_type' => $entry_type
        ]);
        
        $notification_message = sprintf(
            "Your match scheduled has been cancelled. Entry fee of %d %s has been refunded to your account.",
            $entry_fee,
            strtoupper($entry_type)
        );

        sendNotification($supabase, $user_id, 'match_cancelled', $notification_message, $match_id);
    }
}

function cancelMatch($match_id) {
    try {
        $supabase = getSupabaseClient(true);
        
        // Get match details first (without transaction)
        $match = $supabase->select('matches', '*', ['id' => $match_id, 'status' => 'upcoming']);
        
        if (empty($match)) {
            throw new Exception("Match not found or cannot be cancelled!");
        }
        $match = $match[0]; // Get first result
        
        // Process refunds (without transaction for now)
        refundParticipants($supabase, $match_id, $match['entry_fee'], $match['entry_type']);
        
        // Update match status to cancelled
        $supabase->update('matches', [
            'status' => 'cancelled',
            'cancelled_at' => date('Y-m-d H:i:s'),
            'cancellation_reason' => 'Cancelled by admin'
        ], ['id' => $match_id]);
        
        return "Match cancelled successfully. All participants have been refunded.";
    } catch (Exception $e) {
        // Ensure we don't double-encode error messages
        $errorMessage = $e->getMessage();
        error_log("Cancel match error: " . $errorMessage);
        throw new Exception($errorMessage);
    }
}

function startMatch($match_id, $room_code, $room_password) {
    try {
        $supabase = getSupabaseClient(true);
        
        // Update match status to in_progress
        $supabase->update('matches', [
            'status' => 'in_progress',
            'started_at' => date('Y-m-d H:i:s'),
            'room_code' => $room_code,
            'room_password' => $room_password,
            'room_details_added_at' => date('Y-m-d H:i:s')
        ], ['id' => $match_id]);
        
        // Get match details for notification (simplified query)
        $match = $supabase->select('matches', '*', ['id' => $match_id]);
        if (empty($match)) {
            throw new Exception("Match not found!");
        }
        $matchData = $match[0];
        
        // Get game name
        $game = $supabase->select('games', '*', ['id' => $matchData['game_id']]);
        $gameName = !empty($game) ? $game[0]['name'] : 'Unknown Game';
        
        // Get all participants
        $participants = $supabase->select('match_participants', '*', ['match_id' => $match_id]);
        
        $notificationMessage = "Room details added for {$gameName} {$matchData['match_type']} match";
        
        foreach ($participants as $participant) {
            if ($participant['user_id']) {
                try {
                    sendNotification($supabase, $participant['user_id'], 'room_details', $notificationMessage, $match_id);
                } catch (Exception $notifError) {
                    error_log("Warning: Failed to send notification to user {$participant['user_id']}: " . $notifError->getMessage());
                }
            }
        }
        
        return "Match started successfully!";
    } catch (Exception $e) {
        error_log("Start match error: " . $e->getMessage());
        throw new Exception($e->getMessage());
    }
}

function completeMatch($match_id) {
    try {
        $supabase = getSupabaseClient(true);
        
        // Get match details
        $match = $supabase->select('matches', '*', ['id' => $match_id]);
        if (empty($match)) {
            throw new Exception("Match not found!");
        }
        $matchData = $match[0];
        
        // Determine winner based on scores
        $winner_id = null;
        if ($matchData['score_team1'] > $matchData['score_team2']) {
            $winner_id = $matchData['team1_id'];
        } elseif ($matchData['score_team2'] > $matchData['score_team1']) {
            $winner_id = $matchData['team2_id'];
        }
        
        // Update match status to completed
        $supabase->update('matches', [
            'status' => 'completed',
            'completed_at' => date('Y-m-d H:i:s'),
            'winner_id' => $winner_id
        ], ['id' => $match_id]);
        
        return "Match completed successfully!";
    } catch (Exception $e) {
        error_log("Complete match error: " . $e->getMessage());
        throw new Exception($e->getMessage());
    }
}

function deleteMatch($match_id) {
    try {
        $supabase = getSupabaseClient(true);
        
        // Verify match exists before deletion
        $match = $supabase->select('matches', '*', ['id' => $match_id]);
        if (empty($match)) {
            throw new Exception("Match not found!");
        }
        
        // Get match data for potential refunds if needed
        $matchData = $match[0];
        
        // If match has participants and entry fees, we should refund them before deleting
        if ($matchData['entry_type'] !== 'free' && $matchData['entry_fee'] > 0) {
            try {
                refundParticipants($supabase, $match_id, $matchData['entry_fee'], $matchData['entry_type']);
                error_log("Refunded participants before deleting match {$match_id}");
            } catch (Exception $refundError) {
                error_log("Warning: Failed to refund participants for match {$match_id}: " . $refundError->getMessage());
                // Continue with deletion even if refunds fail
            }
        }
        
        // Delete participants first using array format for better compatibility
        try {
            // First check if there are any participants
            $existingParticipants = $supabase->select('match_participants', '*', ['match_id' => $match_id]);
            if (!empty($existingParticipants)) {
                error_log("Found " . count($existingParticipants) . " participants to delete for match {$match_id}");
                
                // Use array format for deletion (REST API approach)
                $participantsDeleted = $supabase->delete('match_participants', ['match_id' => $match_id]);
                error_log("Participants deletion result for match {$match_id}: " . json_encode($participantsDeleted));
                
                // Verify participants are actually deleted
                $remainingParticipants = $supabase->select('match_participants', '*', ['match_id' => $match_id]);
                if (!empty($remainingParticipants)) {
                    throw new Exception("Failed to delete all participants. " . count($remainingParticipants) . " participants remain.");
                }
                error_log("Successfully deleted all participants for match {$match_id}");
            } else {
                error_log("No participants found for match {$match_id}, proceeding with match deletion");
            }
        } catch (Exception $participantError) {
            error_log("Failed to delete participants for match {$match_id}: " . $participantError->getMessage());
            throw new Exception("Cannot delete match while participants exist. Error: " . $participantError->getMessage());
        }
        
        // Also delete any related records that might have foreign key references
        try {
            // Delete user kills records
            $killsRecords = $supabase->select('user_kills', '*', ['match_id' => $match_id]);
            if (!empty($killsRecords)) {
                $supabase->delete('user_kills', ['match_id' => $match_id]);
                error_log("Deleted " . count($killsRecords) . " user kills records for match {$match_id}");
            }
            
            // Delete any notifications related to this match
            $notifications = $supabase->select('notifications', '*', ['related_id' => $match_id, 'related_type' => 'match']);
            if (!empty($notifications)) {
                $supabase->delete('notifications', ['related_id' => $match_id, 'related_type' => 'match']);
                error_log("Deleted " . count($notifications) . " notifications for match {$match_id}");
            }
            
            // Delete any transactions related to this match
            try {
                $transactions = $supabase->select('transactions', '*', ['description' => ['like', '%match #' . $match_id . '%']]);
                if (!empty($transactions)) {
                    foreach ($transactions as $transaction) {
                        $supabase->delete('transactions', ['id' => $transaction['id']]);
                    }
                    error_log("Deleted " . count($transactions) . " transactions for match {$match_id}");
                }
            } catch (Exception $txError) {
                error_log("Warning: Could not delete transactions for match {$match_id}: " . $txError->getMessage());
                // Continue as this is not critical for match deletion
            }
        } catch (Exception $relatedError) {
            error_log("Warning: Failed to delete some related records for match {$match_id}: " . $relatedError->getMessage());
            // Continue with match deletion as these are not critical
        }
        
        // Finally, delete the match itself
        try {
            $deleteResult = $supabase->delete('matches', ['id' => $match_id]);
            error_log("Match deletion result for {$match_id}: " . json_encode($deleteResult));
            
            // Verify the match is actually deleted
            $checkMatch = $supabase->select('matches', '*', ['id' => $match_id]);
            if (empty($checkMatch)) {
                return "Match deleted successfully.";
            } else {
                throw new Exception("Failed to delete match - match still exists in database");
            }
        } catch (Exception $matchDeleteError) {
            error_log("Failed to delete match {$match_id}: " . $matchDeleteError->getMessage());
            throw new Exception("Failed to delete match: " . $matchDeleteError->getMessage());
        }
        
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
        error_log("Delete match error: " . $errorMessage);
        
        // Check if it's a specific foreign key constraint error
        if (strpos($errorMessage, 'foreign key constraint') !== false) {
            throw new Exception("Cannot delete match due to related data. Please ensure all participants and related records are removed first.");
        }
        
        // Check if it's a database connection error and provide a more helpful message
        if (strpos($errorMessage, 'could not translate host name') !== false || 
            strpos($errorMessage, 'Database connection failed') !== false) {
            throw new Exception("Database connection issue. Please check your internet connection and try again.");
        }
        
        throw new Exception($errorMessage);
    }
}

function fetchGamesTeamsTournaments($supabase) {
    $games = $supabase->select('games', [
        'select' => 'id, name',
        'where' => 'status = $1',
        'params' => ['active'],
        'order' => 'name'
    ]);
    
    $teams = $supabase->select('teams', [
        'select' => 'id, name',
        'order' => 'name'
    ]);
    
    $tournaments = $supabase->select('tournaments', [
        'select' => 'id, name',
        'order' => 'name'
    ]);
    
    return [
        'games' => $games,
        'teams' => $teams,
        'tournaments' => $tournaments
    ];
}

// ============================================================================
// SCREENSHOT MANAGEMENT FUNCTIONS FOR ADMIN
// ============================================================================

// Fetch screenshots for a specific match with user details
function fetchMatchScreenshots($supabase, $match_id) {
    try {
        $screenshots = $supabase->select('match_screenshots', '*', ['match_id' => $match_id], 'uploaded_at.desc');
        
        // Enrich with user details
        foreach ($screenshots as &$screenshot) {
            $user = $supabase->select('users', 'id, username, email', ['id' => $screenshot['user_id']]);
            if (!empty($user)) {
                $screenshot['username'] = $user[0]['username'];
                $screenshot['user_email'] = $user[0]['email'];
            }
            
            // Add verified by admin details if verified
            if ($screenshot['verified_by']) {
                $admin = $supabase->select('admin_users', 'id, username, email', ['id' => $screenshot['verified_by']]);
                if (!empty($admin)) {
                    $screenshot['verified_by_username'] = $admin[0]['username'];
                }
            }
        }
        unset($screenshot);
        
        return $screenshots;
    } catch (Exception $e) {
        error_log("Fetch match screenshots error: " . $e->getMessage());
        return [];
    }
}

// Fetch all pending screenshots with optional filters
function fetchPendingScreenshots($supabase, $filters = []) {
    try {
        $conditions = ['verified' => false];
        
        // Apply additional filters
        if (isset($filters['match_id'])) {
            $conditions['match_id'] = $filters['match_id'];
        }
        if (isset($filters['user_id'])) {
            $conditions['user_id'] = $filters['user_id'];
        }
        if (isset($filters['upload_type'])) {
            $conditions['upload_type'] = $filters['upload_type'];
        }
        
        $screenshots = $supabase->select('match_screenshots', '*', $conditions, 'uploaded_at.desc');
        
        // Enrich with user and match details
        foreach ($screenshots as &$screenshot) {
            // Get user details
            $user = $supabase->select('users', 'id, username, email', ['id' => $screenshot['user_id']]);
            if (!empty($user)) {
                $screenshot['username'] = $user[0]['username'];
                $screenshot['user_email'] = $user[0]['email'];
            }
            
            // Get match details
            $match = $supabase->select('matches', '*', ['id' => $screenshot['match_id']]);
            if (!empty($match)) {
                $screenshot['match_date'] = $match[0]['match_date'];
                $screenshot['match_type'] = $match[0]['match_type'];
                
                // Get game name
                $game = $supabase->select('games', 'name', ['id' => $match[0]['game_id']]);
                if (!empty($game)) {
                    $screenshot['game_name'] = $game[0]['name'];
                }
            }
        }
        unset($screenshot);
        
        return $screenshots;
    } catch (Exception $e) {
        error_log("Fetch pending screenshots error: " . $e->getMessage());
        return [];
    }
}

// Verify a specific screenshot
function verifyScreenshot($supabase, $screenshot_id, $verified, $admin_notes, $admin_id) {
    try {
        $result = $supabase->update('match_screenshots', [
            'verified' => $verified,
            'admin_notes' => $admin_notes,
            'verified_by' => $admin_id,
            'verified_at' => date('Y-m-d H:i:s')
        ], ['id' => $screenshot_id]);
        
        // Send notification to user about verification status
        $screenshot = $supabase->select('match_screenshots', '*', ['id' => $screenshot_id]);
        if (!empty($screenshot)) {
            $status = $verified ? 'approved' : 'rejected';
            $message = "Your screenshot has been {$status}" . ($admin_notes ? ": {$admin_notes}" : '');
            
            sendNotification($supabase, $screenshot[0]['user_id'], 'screenshot_verified', $message, $screenshot[0]['match_id']);
        }
        
        return $result;
    } catch (Exception $e) {
        error_log("Verify screenshot error: " . $e->getMessage());
        throw new Exception($e->getMessage());
    }
}

// Bulk verify screenshots
function bulkVerifyScreenshots($supabase, array $screenshot_ids, $verified, $admin_notes, $admin_id) {
    try {
        $results = [];
        $verification_time = date('Y-m-d H:i:s');
        
        foreach ($screenshot_ids as $screenshot_id) {
            // Update each screenshot
            $result = $supabase->update('match_screenshots', [
                'verified' => $verified,
                'admin_notes' => $admin_notes,
                'verified_by' => $admin_id,
                'verified_at' => $verification_time
            ], ['id' => $screenshot_id]);
            
            $results[] = $result;
            
            // Send notification to user
            $screenshot = $supabase->select('match_screenshots', 'user_id, match_id', ['id' => $screenshot_id]);
            if (!empty($screenshot)) {
                $status = $verified ? 'approved' : 'rejected';
                $message = "Your screenshot has been {$status}" . ($admin_notes ? ": {$admin_notes}" : '');
                
                sendNotification($supabase, $screenshot[0]['user_id'], 'screenshot_verified', $message, $screenshot[0]['match_id']);
            }
        }
        
        return $results;
    } catch (Exception $e) {
        error_log("Bulk verify screenshots error: " . $e->getMessage());
        throw new Exception($e->getMessage());
    }
}

// Get screenshot statistics for admin dashboard
function getScreenshotStats($supabase) {
    try {
        $total = $supabase->select('match_screenshots', 'COUNT(*) as count', []);
        $pending = $supabase->select('match_screenshots', 'COUNT(*) as count', ['verified' => false]);
        $verified = $supabase->select('match_screenshots', 'COUNT(*) as count', ['verified' => true]);
        
        return [
            'total' => $total[0]['count'] ?? 0,
            'pending' => $pending[0]['count'] ?? 0,
            'verified' => $verified[0]['count'] ?? 0
        ];
    } catch (Exception $e) {
        error_log("Get screenshot stats error: " . $e->getMessage());
        return [
            'total' => 0,
            'pending' => 0,
            'verified' => 0
        ];
    }
}

// Delete screenshot and its file from storage
function deleteScreenshot($supabase, $screenshot_id, $admin_id) {
    try {
        // Get screenshot details first
        $screenshot = $supabase->select('match_screenshots', '*', ['id' => $screenshot_id]);
        if (empty($screenshot)) {
            throw new Exception("Screenshot not found!");
        }
        
        $screenshotData = $screenshot[0];
        
        // Delete actual file from Supabase Storage first
        if (!empty($screenshotData['image_path'])) {
            try {
                $deleteResult = $supabase->deleteFile('match-screenshots', $screenshotData['image_path']);
                error_log("File deleted from storage: {$screenshotData['image_path']}");
            } catch (Exception $storageError) {
                error_log("Warning: Failed to delete file from storage {$screenshotData['image_path']}: " . $storageError->getMessage());
                // Continue with database deletion even if storage deletion fails
            }
        }
        
        // Delete from database
        $supabase->delete('match_screenshots', ['id' => $screenshot_id]);
        
        // Log the deletion
        error_log("Screenshot {$screenshot_id} deleted by admin {$admin_id}");
        
        // Notify user about deletion
        sendNotification($supabase, $screenshotData['user_id'], 'screenshot_deleted', 
            'One of your screenshots has been removed by admin', $screenshotData['match_id']);
        
        return "Screenshot deleted successfully";
    } catch (Exception $e) {
        error_log("Delete screenshot error: " . $e->getMessage());
        throw new Exception($e->getMessage());
    }
}

// Clean all screenshots for a completed match
function cleanMatchScreenshots($match_id, $admin_id) {
    try {
        $supabase = getSupabaseClient(true);
        
        // Verify match exists and is completed
        $match = $supabase->select('matches', '*', ['id' => $match_id]);
        if (empty($match)) {
            throw new Exception("Match not found!");
        }
        
        $matchData = $match[0];
        if ($matchData['status'] !== 'completed') {
            throw new Exception("Can only clean screenshots from completed matches!");
        }
        
        // Get all screenshots for this match
        $screenshots = $supabase->select('match_screenshots', '*', ['match_id' => $match_id]);
        
        if (empty($screenshots)) {
            return "No screenshots found for this match.";
        }
        
        $deletedCount = 0;
        $userNotifications = [];
        
        // Delete each screenshot
        foreach ($screenshots as $screenshot) {
            try {
                // Delete actual file from Supabase Storage first
                if (!empty($screenshot['image_path'])) {
                    try {
                        $deleteResult = $supabase->deleteFile('match-screenshots', $screenshot['image_path']);
                        error_log("File deleted from storage: {$screenshot['image_path']}");
                    } catch (Exception $storageError) {
                        error_log("Warning: Failed to delete file from storage {$screenshot['image_path']}: " . $storageError->getMessage());
                        // Continue with database deletion even if storage deletion fails
                    }
                }
                
                // Delete from database
                $supabase->delete('match_screenshots', ['id' => $screenshot['id']]);
                
                $deletedCount++;
                
                // Collect unique users for notifications
                if (!in_array($screenshot['user_id'], $userNotifications)) {
                    $userNotifications[] = $screenshot['user_id'];
                }
                
                error_log("Screenshot {$screenshot['id']} cleaned by admin {$admin_id} from match {$match_id}");
            } catch (Exception $deleteError) {
                error_log("Failed to delete screenshot {$screenshot['id']}: " . $deleteError->getMessage());
                // Continue with other screenshots even if one fails
            }
        }
        
        // Send notifications to users
        foreach ($userNotifications as $user_id) {
            try {
                sendNotification($supabase, $user_id, 'screenshots_cleaned', 
                    "All screenshots for completed match have been cleaned by admin for storage management", $match_id);
            } catch (Exception $notifError) {
                error_log("Failed to send cleanup notification to user {$user_id}: " . $notifError->getMessage());
            }
        }
        
        return "Successfully cleaned {$deletedCount} screenshot(s) from completed match.";
    } catch (Exception $e) {
        error_log("Clean match screenshots error: " . $e->getMessage());
        throw new Exception($e->getMessage());
    }
}

function validateMatchData($data) {
    $useWebsiteCurrency = !empty($data['website_currency_type']) && $data['website_currency_amount'] > 0;
    
    if ($useWebsiteCurrency) {
        if (empty($data['website_currency_type']) || $data['website_currency_amount'] <= 0) {
            throw new Exception("Please enter a valid website currency amount!");
        }
        $data['prize_pool'] = 0;
    } else {
        if ($data['prize_pool'] <= 0) {
            throw new Exception("Please enter a valid prize pool amount!");
        }
        $data['website_currency_type'] = null;
        $data['website_currency_amount'] = 0;
    }
    
    return $data;
}

function renderMatchCard($match) {
    ob_start();
    ?>
    <div class="match-card">
        <div class="match-header">
            <div class="d-flex align-items-center gap-2">
                <?php if ($match['game_image']): ?>
                    <img src="../<?= htmlspecialchars($match['game_image']) ?>" alt="<?= htmlspecialchars($match['game_name']) ?>" class="game-icon">
                <?php endif; ?>
                <div>
                    <h3><?= htmlspecialchars($match['game_name']) ?></h3>
                    <div class="match-subtitle">
                        <span class="map-badge">
                            <i class="bi bi-map"></i> <?= htmlspecialchars($match['map_name']) ?>
                        </span>
                        <span class="match-type-badge">
                            <i class="bi bi-controller"></i> <?= htmlspecialchars(ucfirst($match['match_type'])) ?>
                        </span>
                    </div>
                </div>
            </div>
            <?php if (isset($match['tournament_name']) && $match['tournament_name']): ?>
                <div class="tournament-name">
                    <i class="bi bi-trophy"></i> <?= htmlspecialchars($match['tournament_name']) ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="match-info">
            <div class="info-group">
                <div class="info-item">
                    <i class="bi bi-calendar"></i>
                    <span>
                        <?= date('M j, Y', strtotime($match['match_date'])) ?>
                    </span>
                </div>
                <div class="info-item">
                    <i class="bi bi-clock"></i>
                    <span>
                        <?= date('g:i A', strtotime($match['match_time'])) ?>
                    </span>
                </div>
            </div>
            <div class="info-group">
                <div class="info-item">
                    <i class="bi bi-people"></i>
                    <?= $match['current_participants'] ?>/<?= $match['max_participants'] ?>
                </div>
                <div class="info-item prize-pool">
                    <i class="bi bi-trophy-fill"></i> 
                    <?php 
                        if ($match['website_currency_type'] && $match['website_currency_amount'] > 0) {
                            echo number_format($match['website_currency_amount']) . ' ' . ucfirst($match['website_currency_type']);
                        } else {
                            $currency_symbol = isset($match['prize_type']) && $match['prize_type'] === 'USD' ? '$' : '₹';
                            echo $currency_symbol . ' ' . number_format($match['prize_pool']); 
                        }
                    ?>
                </div>
            </div>
            <div class="info-item entry-fee">
                <i class="bi bi-ticket"></i> 
                <?php if ($match['entry_type'] === 'free'): ?>
                    Free Entry
                <?php else: ?>
                    <?= number_format($match['entry_fee']) ?> <?= ucfirst($match['entry_type']) ?>
                <?php endif; ?>
            </div>
            
            <?php if ($match['prize_distribution'] || $match['coins_per_kill'] > 0): ?>
            <div class="prize-details">
                <?php if ($match['prize_distribution']): ?>
                    <div class="info-item distribution-info">
                        <i class="bi bi-diagram-3"></i>
                        Distribution: <?= ucfirst(str_replace('_', ' ', $match['prize_distribution'])) ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($match['coins_per_kill'] > 0): ?>
                    <div class="info-item kill-reward">
                        <i class="bi bi-star"></i>
                        <?= number_format($match['coins_per_kill']) ?> Coins per Kill
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($match['team1_id'] && $match['team2_id']): ?>
        <div class="teams-container">
            <div class="team team1">
                <div class="team-info">
                    <?php if (isset($match['team1_logo'])): ?>
                        <img src="<?= htmlspecialchars($match['team1_logo']) ?>" alt="<?= htmlspecialchars($match['team1_name']) ?>" class="team-logo">
                    <?php endif; ?>
                    <span class="team-name"><?= htmlspecialchars($match['team1_name']) ?></span>
                </div>
                <span class="team-score"><?= $match['score_team1'] ?? '0' ?></span>
            </div>
            <div class="vs">VS</div>
            <div class="team team2">
                <span class="team-score"><?= $match['score_team2'] ?? '0' ?></span>
                <div class="team-info">
                    <?php if (isset($match['team2_logo'])): ?>
                        <img src="<?= htmlspecialchars($match['team2_logo']) ?>" alt="<?= htmlspecialchars($match['team2_name']) ?>" class="team-logo">
                    <?php endif; ?>
                    <span class="team-name"><?= htmlspecialchars($match['team2_name']) ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($match['status'] === 'in_progress' && $match['room_code'] && $match['room_password']): ?>
        <div class="room-details">
            <div class="info-item room-info">
                <i class="bi bi-door-open"></i>
                <span>Room Code: <strong><?= htmlspecialchars($match['room_code']) ?></strong></span>
            </div>
            <div class="info-item room-info">
                <i class="bi bi-key"></i>
                <span>Password: <strong><?= htmlspecialchars($match['room_password']) ?></strong></span>
            </div>
        </div>
        <?php endif; ?>

        <div class="match-status">
            <div class="status-badge <?= $match['status'] ?>">
                <i class="bi bi-circle-fill"></i>
                <?= ucfirst(str_replace('_', ' ', $match['status'])) ?>
            </div>
            <?php if ($match['winner_name']): ?>
                <div class="winner-badge">
                    <i class="bi bi-trophy-fill"></i> Winner: <?= htmlspecialchars($match['winner_name']) ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="match-actions">
            <a href="match_details.php?id=<?= $match['id'] ?>" class="btn btn-sm btn-info">
                <i class="bi bi-people"></i> View Participants
            </a>
            <?php if ($match['status'] === 'upcoming'): ?>
                <button class="btn btn-sm btn-primary" onclick="editMatch(<?= $match['id'] ?>)">
                    <i class="bi bi-pencil"></i> Edit
                </button>
                <button class="btn btn-sm btn-success" onclick="startMatch(<?= $match['id'] ?>)">
                    <i class="bi bi-play-fill"></i> Start
                </button>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to cancel this match? This will refund all participants and cannot be undone.')">
                    <input type="hidden" name="action" value="cancel_match">
                    <input type="hidden" name="match_id" value="<?= $match['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-warning">
                        <i class="bi bi-x-circle"></i> Cancel Match
                    </button>
                </form>
            <?php endif; ?>
            
            <?php if ($match['status'] === 'in_progress'): ?>
                <a href="match_scoring.php?id=<?= $match['id'] ?>" class="btn btn-sm btn-warning">
                    <i class="bi bi-pencil"></i> Update Score
                </a>
            <?php endif; ?>
            
            <?php if ($match['status'] === 'completed'): ?>
                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to clean all screenshots for this completed match? This will permanently delete all screenshots to free up storage space.')">
                    <input type="hidden" name="action" value="clean_screenshots">
                    <input type="hidden" name="match_id" value="<?= $match['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-secondary">
                        <i class="bi bi-image"></i> Clean Screenshots
                    </button>
                </form>
            <?php endif; ?>
            
            <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this match? This action cannot be undone.')">
                <input type="hidden" name="action" value="delete_match">
                <input type="hidden" name="match_id" value="<?= $match['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger">
                    <i class="bi bi-trash"></i> Delete
                </button>
            </form>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function renderMatchesGrid($matches) {
    ob_start();
    ?>
    <div class="matches-grid">
        <?php foreach ($matches as $match): ?>
            <?= renderMatchCard($match) ?>
        <?php endforeach; ?>
    </div>
    <?php
    return ob_get_clean();
}

function getMatchData($match_id) {
    try {
        $supabase = getSupabaseClient(true);
        
        // Fetch match data with enriched details
        $match = $supabase->select('matches', '*', ['id' => $match_id]);
        
        if (empty($match)) {
            throw new Exception("Match not found!");
        }
        
        $match = $match[0];
        
        // Enrich with additional details
        if ($match['game_id']) {
            $game = $supabase->select('games', '*', ['id' => $match['game_id']]);
            if (!empty($game)) {
                $match['game_name'] = $game[0]['name'];
            }
        }
        
        // Format date and time for form inputs and display
        if ($match['match_date']) {
            $datetime = new DateTime($match['match_date']);
            // For form editing
            $match['match_date_formatted'] = $datetime->format('Y-m-d');
            $match['match_time_formatted'] = $datetime->format('H:i');
            // Keep the full datetime for consistency
            $match['match_date'] = $datetime->format('Y-m-d H:i:s');
            // Set match_time for backward compatibility
            $match['match_time'] = $datetime->format('H:i:s');
        }
        
        return $match;
    } catch (Exception $e) {
        error_log("Get match data error: " . $e->getMessage());
        throw new Exception($e->getMessage());
    }
}

function renderSidebar($currentGame) {
    ob_start();
    ?>
    <nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block bg-light sidebar collapse">
        <div class="position-sticky pt-3">
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link" href="../index.php">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentGame === 'bgmi' ? 'active' : '' ?>" href="bgmi.php">
                        <i class="bi bi-controller"></i> BGMI Matches
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentGame === 'pubg' ? 'active' : '' ?>" href="pubg.php">
                        <i class="bi bi-trophy"></i> PUBG Matches
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentGame === 'freefire' ? 'active' : '' ?>" href="freefire.php">
                        <i class="bi bi-people"></i> Free Fire Matches
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= $currentGame === 'cod' ? 'active' : '' ?>" href="cod.php">
                        <i class="bi bi-joystick"></i> COD Matches
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="../users.php">
                        <i class="bi bi-person"></i> Users
                    </a>
                </li>
            </ul>
        </div>
    </nav>
    <?php
    return ob_get_clean();
}

?>
