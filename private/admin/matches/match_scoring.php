<?php

// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

require_once 'common.php';

// Load admin configuration with error handling
try {
    $adminConfig = loadAdminConfig('admin_config.php');
    if (!$adminConfig || !is_array($adminConfig)) {
        $adminConfig = ['system' => ['name' => 'KGX Admin']];
    }
} catch (Exception $e) {
    error_log("Admin config error: " . $e->getMessage());
    $adminConfig = ['system' => ['name' => 'KGX Admin']];
}

// Use the new admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

// Include admin header after authentication
include ADMIN_INCLUDES_PATH . 'admin-header.php';

$supabase = getSupabaseClient(true);

// Add these headers at the top of the file, after the require statements
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Supabase client already initialized; remove PDO.

// Get match ID from URL
$match_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Fetch match details using Supabase
$match = $supabase->select('matches', '*', ['id' => $match_id]);
if (empty($match)) {
    echo "Match not found!";
    exit;
}
$match = $match[0];

// Fetch additional game details
$game = $supabase->select('games', '*', ['id' => $match['game_id']]);
if (!empty($game)) {
    $match['game_name'] = $game[0]['name'];
    $match['game_image'] = $game[0]['image_url'];
}

// Fetch team details
$team1 = $supabase->select('teams', '*', ['id' => $match['team1_id']]);
$team2 = $supabase->select('teams', '*', ['id' => $match['team2_id']]);
if (!empty($team1)) $match['team1_name'] = $team1[0]['name'];
if (!empty($team2)) $match['team2_name'] = $team2[0]['name'];

// Fetch tournament details
$tournament = $supabase->select('tournaments', '*', ['id' => $match['tournament_id']]);
if (!empty($tournament)) $match['tournament_name'] = $tournament[0]['name'];

$match['match_date'] = date('Y-m-d', strtotime($match['match_date']));
$match['match_time'] = date('H:i:s', strtotime($match['match_date']));

// Fetch participants using Supabase
$participants = $supabase->select('match_participants', '*', ['match_id' => $match_id]);

// Enrich participants with user details
foreach ($participants as &$participant) {
    // Get user details
    $user = $supabase->select('users', '*', ['id' => $participant['user_id']]);
    if (!empty($user)) {
        $participant['username'] = $user[0]['username'];
        $participant['email'] = $user[0]['email'];
        $participant['phone'] = $user[0]['phone'];
    }
    
    // Get user game details - Fix the game name mapping issue
    $game_name_mapping = [
        'Free Fire' => 'FREE FIRE',
        'Call of Duty Mobile' => 'COD',
        'Call of Duty' => 'COD'
    ];
    
    // Use mapped name or original name for profile lookup
    $profile_game_name = isset($game_name_mapping[$match['game_name']]) ? 
                         $game_name_mapping[$match['game_name']] : 
                         $match['game_name'];
    
    $user_game = $supabase->select('user_games', '*', ['user_id' => $participant['user_id'], 'game_name' => $profile_game_name]);
    if (!empty($user_game)) {
        $participant['game_uid'] = $user_game[0]['game_uid'];
        $participant['game_username'] = $user_game[0]['game_username'];
        $participant['game_level'] = $user_game[0]['game_level'] ?? 1;
    } else {
        $participant['game_uid'] = '';
        $participant['game_username'] = '';
        $participant['game_level'] = 1;
    }
    
    // Get kills for this match
    $kills = $supabase->select('user_kills', '*', ['match_id' => $match_id, 'user_id' => $participant['user_id']]);
    $participant['total_kills'] = !empty($kills) ? $kills[0]['kills'] : 0;
}
unset($participant);

// Add this function after the require statements at the top
function distributePrize($supabase, $match_id, $winner_id, $match) {
    if (!$winner_id) {
        error_log("Error in distributePrize: No winner_id provided");
        return;
    }

    try {
        // Get all participants sorted by position and kills using Supabase
        $participants = $supabase->select('match_participants', '*', ['match_id' => $match_id]);
        
        // Enrich with kill data
        foreach ($participants as &$participant) {
            $kills = $supabase->select('user_kills', '*', ['match_id' => $match_id, 'user_id' => $participant['user_id']]);
            $participant['kills'] = !empty($kills) ? $kills[0]['kills'] : 0;
        }
        unset($participant);
        
        // Sort participants by position (nulls last) then by kills descending
        usort($participants, function($a, $b) {
            if ($a['position'] === null && $b['position'] === null) {
                return $b['kills'] - $a['kills']; // Sort by kills desc if both positions are null
            }
            if ($a['position'] === null) return 1; // a goes after b
            if ($b['position'] === null) return -1; // a goes before b
            if ($a['position'] == $b['position']) {
                return $b['kills'] - $a['kills']; // Sort by kills desc for same position
            }
            return $a['position'] - $b['position']; // Sort by position asc
        });

        if (empty($participants)) {
            error_log("Error in distributePrize: No participants found for match_id: " . $match_id);
            return;
        }

        // Update permanent statistics for each participant
        foreach ($participants as $participant) {
            // Check if user match stats exist
            $existing_stats = $supabase->select('user_match_stats', '*', ['user_id' => $participant['user_id']]);
            
            if (!empty($existing_stats)) {
                // Update existing stats
                $current_matches = $existing_stats[0]['total_matches_played'];
                $current_kills = $existing_stats[0]['total_kills'];
                $supabase->update('user_match_stats', [
                    'total_matches_played' => $current_matches + 1,
                    'total_kills' => $current_kills + $participant['kills']
                ], ['user_id' => $participant['user_id']]);
            } else {
                // Insert new stats
                $supabase->insert('user_match_stats', [
                    'user_id' => $participant['user_id'],
                    'total_matches_played' => 1,
                    'total_kills' => $participant['kills']
                ]);
            }
        }

        // First, award kill rewards to all participants
        if ($match['coins_per_kill'] > 0) {
            foreach ($participants as $participant) {
                if ($participant['kills'] > 0) {
                    $kill_coins = $participant['kills'] * $match['coins_per_kill'];
                    try {
                        // Check if user_coins record exists
                        $existing_coins = $supabase->select('user_coins', '*', ['user_id' => $participant['user_id']]);
                        
                        if (!empty($existing_coins)) {
                            // Update existing coins
                            $current_coins = $existing_coins[0]['coins'];
                            $supabase->update('user_coins', ['coins' => $current_coins + $kill_coins], ['user_id' => $participant['user_id']]);
                        } else {
                            // Insert new coins record
                            $supabase->insert('user_coins', [
                                'user_id' => $participant['user_id'],
                                'coins' => $kill_coins
                            ]);
                        }
                        
                        // Log the kill rewards
                        error_log("Awarded {$kill_coins} coins to user {$participant['user_id']} for {$participant['kills']} kills");
                    } catch (Exception $e) {
                        error_log("Error awarding kill coins: " . $e->getMessage());
                        throw $e;
                    }
                }
            }
        }

        // Then distribute position-based website currency prizes
        if ($match['website_currency_type'] && $match['website_currency_amount'] > 0) {
            $total_prize = $match['website_currency_amount'];
            $currency_type = $match['website_currency_type'];
            
            // Define prize distribution percentages
            $distribution_percentages = [];
            switch($match['prize_distribution']) {
                case 'top3':
                    $distribution_percentages = [60, 30, 10];
                    break;
                case 'top5':
                    $distribution_percentages = [50, 25, 15, 7, 3];
                    break;
                default: // 'single' - winner takes all
                    $distribution_percentages = [100];
                    break;
            }

            // Distribute position-based prizes
            foreach ($participants as $index => $participant) {
                if ($index >= count($distribution_percentages)) break;
                
                $prize_amount = floor($total_prize * $distribution_percentages[$index] / 100);
                if ($prize_amount <= 0) continue;

                try {
                    if ($currency_type === 'coins') {
                        // Check if user_coins record exists
                        $existing_coins = $supabase->select('user_coins', '*', ['user_id' => $participant['user_id']]);
                        
                        if (!empty($existing_coins)) {
                            // Update existing coins
                            $current_coins = $existing_coins[0]['coins'];
                            $supabase->update('user_coins', ['coins' => $current_coins + $prize_amount], ['user_id' => $participant['user_id']]);
                        } else {
                            // Insert new coins record
                            $supabase->insert('user_coins', [
                                'user_id' => $participant['user_id'],
                                'coins' => $prize_amount
                            ]);
                        }
                    } else { // tickets
                        // Check if user_tickets record exists
                        $existing_tickets = $supabase->select('user_tickets', '*', ['user_id' => $participant['user_id']]);
                        
                        if (!empty($existing_tickets)) {
                            // Update existing tickets
                            $current_tickets = $existing_tickets[0]['tickets'];
                            $supabase->update('user_tickets', ['tickets' => $current_tickets + $prize_amount], ['user_id' => $participant['user_id']]);
                        } else {
                            // Insert new tickets record
                            $supabase->insert('user_tickets', [
                                'user_id' => $participant['user_id'],
                                'tickets' => $prize_amount
                            ]);
                        }
                    }
                    
                    // Log the prize distribution
                    error_log("Awarded {$prize_amount} {$currency_type} to user {$participant['user_id']} for position " . ($index + 1));
                } catch (Exception $e) {
                    error_log("Error distributing website currency: " . $e->getMessage());
                    throw $e;
                }
            }
        }

        // Handle real money distribution (for admin reference)
        // Only for team-based matches where participants have team_id
        if ($match['prize_pool'] > 0) {
            $total_prize = $match['prize_pool'];
            $currency_type = $match['prize_type'];
            
            // Define prize distribution percentages
            $distribution_percentages = [];
            switch($match['prize_distribution']) {
                case 'top3':
                    $distribution_percentages = [60, 30, 10];
                    break;
                case 'top5':
                    $distribution_percentages = [50, 25, 15, 7, 3];
                    break;
                default: // 'single'
                    $distribution_percentages = [100];
                    break;
            }

            // Calculate and store prize amounts for each winner
            foreach ($participants as $index => $participant) {
                if ($index >= count($distribution_percentages)) break;
                
                $prize_amount = round($total_prize * $distribution_percentages[$index] / 100, 2);
                if ($prize_amount <= 0) continue;

                // Skip if participant doesn't have a team_id (individual matches)
                if (empty($participant['team_id']) || is_null($participant['team_id'])) {
                    error_log("Skipping match_results insertion for individual participant (user_id: {$participant['user_id']}) - no team_id");
                    continue;
                }

                try {
                    // Check if match result exists
                    $existing_result = $supabase->select('match_results', '*', ['match_id' => $match_id, 'team_id' => $participant['team_id']]);
                    
                    if (!empty($existing_result)) {
                        // Update existing result
                        $supabase->update('match_results', [
                            'prize_amount' => $prize_amount,
                            'prize_currency' => $currency_type
                        ], ['match_id' => $match_id, 'team_id' => $participant['team_id']]);
                    } else {
                        // Insert new result
                        $supabase->insert('match_results', [
                            'match_id' => $match_id,
                            'team_id' => $participant['team_id'],
                            'prize_amount' => $prize_amount,
                            'prize_currency' => $currency_type
                        ]);
                    }
                } catch (Exception $e) {
                    error_log("Error storing real money prize: " . $e->getMessage());
                    throw $e;
                }
            }
        }
    } catch (Exception $e) {
        error_log("Error in distributePrize function: " . $e->getMessage());
        throw $e;
    }
}

// Handle POST requests for updating kills and completing match
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_kills':
                $user_id = $_POST['user_id'];
                $kills = intval($_POST['kills']);
                
                try {
                    // Check if kills record exists
                    $existing_kills = $supabase->select('user_kills', '*', ['match_id' => $match_id, 'user_id' => $user_id]);
                    
                    if (!empty($existing_kills)) {
                        // Update existing kills record
                        $supabase->update('user_kills', ['kills' => $kills], ['match_id' => $match_id, 'user_id' => $user_id]);
                    } else {
                        // Insert new kills record
                        $supabase->insert('user_kills', [
                            'match_id' => $match_id,
                            'user_id' => $user_id,
                            'kills' => $kills
                        ]);
                    }
                    
                    // Calculate and award coins for kills
                    if ($match['coins_per_kill'] > 0) {
                        $coins_earned = $kills * $match['coins_per_kill'];
                        
                        // Check if user_coins record exists
                        $existing_coins = $supabase->select('user_coins', '*', ['user_id' => $user_id]);
                        
                        if (!empty($existing_coins)) {
                            // Update existing coins
                            $current_coins = $existing_coins[0]['coins'];
                            $supabase->update('user_coins', ['coins' => $current_coins + $coins_earned], ['user_id' => $user_id]);
                        } else {
                            // Insert new coins record
                            $supabase->insert('user_coins', [
                                'user_id' => $user_id,
                                'coins' => $coins_earned
                            ]);
                        }
                    }
                    
                    header("Location: match_scoring.php?id=" . $match_id . "&success=1");
                    exit;
                } catch (Exception $e) {
                    error_log("Error updating kills: " . $e->getMessage());
                    header("Location: match_scoring.php?id=" . $match_id . "&error=1");
                    exit;
                }
                break;

            case 'select_winner':
                try {
                    $winner_id = $_POST['winner_id'];
                    
                    // Verify the user is a participant in this match
                    $verification = $supabase->select('match_participants', '*', ['match_id' => $match_id, 'user_id' => $winner_id]);
                    if (empty($verification)) {
                        throw new Exception("Selected user is not a participant in this match");
                    }
                    
                    // Check if all required positions are set based on prize distribution
                    $requiredPositions = ($match['prize_distribution'] === 'top5') ? 5 : 
                                       ($match['prize_distribution'] === 'top3' ? 3 : 1);
                    $positionCount = $supabase->select('match_participants', '*', ['match_id' => $match_id]);
                    $positionCount = array_filter($positionCount, function($p) { return !is_null($p['position']); });
                    if (count($positionCount) < $requiredPositions) {
                        throw new Exception("Please set positions for all winners (top " . $requiredPositions . ") before selecting the winner.");
                    }
                    
                    // Verify the winner has position 1
                    $winnerPosition = $supabase->select('match_participants', 'position', ['match_id' => $match_id, 'user_id' => $winner_id]);
                    if (empty($winnerPosition) || $winnerPosition[0]['position'] != 1) {
                        throw new Exception("The selected winner must be in position 1.");
                    }
                    
                    // Update match status and winner
                    $supabase->update('matches', ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s'), 'winner_user_id' => $winner_id], ['id' => $match_id]);
                    
                    // Award prizes based on distribution type
                    distributePrize($supabase, $match_id, $winner_id, $match);
                    
                    // Get match and winner details for notification
                    $matchInfo = $supabase->select('matches', ['id', 'match_type', 'game_name', 'username as winner_name'], ['id' => $match_id]);
                    
                    // Get all users participating in this match
                    $users = $supabase->select('match_participants', ['distinct' => 'user_id'], ['match_id' => $match_id]);
                    
                    // Create notifications for participating users
                    $notificationMessage = "Match completed! {$matchInfo['winner_name']} won the {$matchInfo['game_name']} {$matchInfo['match_type']} match";
                    foreach ($users as $user) {
                        $supabase->insert('notifications', [
                            'user_id' => $user['user_id'],
                            'type' => 'match_completed',
                            'message' => $notificationMessage,
                            'related_id' => $match_id,
                            'related_type' => 'match',
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                    }
                    
                    header("Location: match_scoring.php?id=" . $match_id . "&success=1");
                    exit;
                } catch (Exception $e) {
                    error_log("Error in select_winner: " . $e->getMessage());
                    header("Location: match_scoring.php?id=" . $match_id . "&error=" . urlencode($e->getMessage()));
                    exit;
                }
                break;

case 'complete_match':
                try {
                    // Check if positions have been set for the required number of winners
                    $requiredPositions = ($match['prize_distribution'] === 'top5') ? 5 :
                                        ($match['prize_distribution'] === 'top3' ? 3 : 1);

                    $positionsSet = $supabase->select('match_participants', '*', ['match_id' => $match_id]);
                    $positionsSet = array_filter($positionsSet, function($p) { return !is_null($p['position']); });
                    if (count($positionsSet) < $requiredPositions) {
                        throw new Exception("Please set positions for all winners (top " . $requiredPositions . ") before completing the match.");
                    }

                    // Check for screenshot verification and users without proof
                    $unverifiedScreenshots = [];
                    $usersWithoutProof = [];
                    
                    foreach ($participants as $participant) {
                        $screenshots = $supabase->select('match_screenshots', '*', ['match_id' => $match_id, 'user_id' => $participant['user_id']]);
                        
                        if (empty($screenshots)) {
                            // User hasn't submitted any proof
                            $usersWithoutProof[] = $participant['username'] ?? 'Unknown User';
                        } else {
                            // Check if all screenshots are verified
                            foreach ($screenshots as $screenshot) {
                                if (!$screenshot['verified'] && !$screenshot['verified_at']) {
                                    // Screenshot is pending verification
                                    $unverifiedScreenshots[] = $participant['username'] ?? 'Unknown User';
                                    break;
                                }
                            }
                        }
                    }
                    
                    // Check if we should proceed or show warnings
                    if (!empty($unverifiedScreenshots) || !empty($usersWithoutProof)) {
                        $warningMessage = "Warning: ";
                        
                        if (!empty($unverifiedScreenshots)) {
                            $warningMessage .= "\n• Users with unverified screenshots: " . implode(', ', $unverifiedScreenshots);
                        }
                        
                        if (!empty($usersWithoutProof)) {
                            $warningMessage .= "\n• Users who haven't submitted proof: " . implode(', ', $usersWithoutProof);
                        }
                        
                        $warningMessage .= "\n\nDo you want to complete the match anyway?";
                        
                        // Check if force_complete parameter is set
                        if (!isset($_POST['force_complete']) || $_POST['force_complete'] !== '1') {
                            throw new Exception($warningMessage);
                        }
                    }

                    // Get the first position player as the main winner
                    $firstPlace = $supabase->select('match_participants', 'user_id', ['match_id' => $match_id, 'position' => 1]);

                    if (empty($firstPlace)) {
                        throw new Exception("First position must be set before completing the match.");
                    }

                    // Update match status and set the first position player as winner
                    $supabase->update('matches',
                        ['status' => 'completed', 'completed_at' => date('Y-m-d H:i:s'), 'winner_user_id' => $firstPlace[0]['user_id']],
                        ['id' => $match_id]);

                    // Distribute prizes
                    distributePrize($supabase, $match_id, $firstPlace[0]['user_id'], $match);

                    // Get match and winner details for notification
                    $matchInfo = $supabase->select('matches', ['id', 'match_type', 'game_name', 'username as winner_name'], ['id' => $match_id]);

                    // Get all users participating in this match
                    $users = $supabase->select('match_participants', ['distinct' => 'user_id'], ['match_id' => $match_id]);

                    // Create notifications for participating users
                    $notificationMessage = "Match completed! {$matchInfo['winner_name']} won the {$matchInfo['game_name']} {$matchInfo['match_type']} match";

                    foreach ($users as $user_id) {
                        $supabase->insert('notifications', [
                            'user_id' => $user_id['user_id'],
                            'type' => 'match_completed',
                            'message' => $notificationMessage,
                            'related_id' => $match_id,
                            'related_type' => 'match',
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                    }

                    header("Location: match_scoring.php?id=" . $match_id . "&success=completed");
                    exit;
                } catch (Exception $e) {
                    header("Location: match_scoring.php?id=" . $match_id . "&error=" . urlencode($e->getMessage()));
                    exit;
                }
                break;

case 'cancel_match':
                try {
                    // Get match details
                    $match_details = $supabase->select('matches', '*', ['id' => $match_id]);
                    if (empty($match_details)) {
                        throw new Exception("Match not found");
                    }
                    $match_info = $match_details[0];
                    
                    // Get participants
                    $participants = $supabase->select('match_participants', '*', ['match_id' => $match_id]);
                    
                    if (empty($participants)) {
                        throw new Exception("No participants found for this match");
                    }
                    
                    // Only process refunds for paid matches
                    if ($match_info['entry_type'] !== 'free' && $match_info['entry_fee'] > 0) {
                        foreach ($participants as $participant) {
                            if ($participant['user_id']) {
                                // Refund entry fee
                                if ($match_info['entry_type'] === 'coins') {
                                    $existing_coins = $supabase->select('user_coins', '*', ['user_id' => $participant['user_id']]);
                                    if (!empty($existing_coins)) {
                                        $current_coins = $existing_coins[0]['coins'];
                                        $supabase->update('user_coins', ['coins' => $current_coins + $match_info['entry_fee']], ['user_id' => $participant['user_id']]);
                                    } else {
                                        $supabase->insert('user_coins', ['user_id' => $participant['user_id'], 'coins' => $match_info['entry_fee']]);
                                    }
                                } else {
                                    $existing_tickets = $supabase->select('user_tickets', '*', ['user_id' => $participant['user_id']]);
                                    if (!empty($existing_tickets)) {
                                        $current_tickets = $existing_tickets[0]['tickets'];
                                        $supabase->update('user_tickets', ['tickets' => $current_tickets + $match_info['entry_fee']], ['user_id' => $participant['user_id']]);
                                    } else {
                                        $supabase->insert('user_tickets', ['user_id' => $participant['user_id'], 'tickets' => $match_info['entry_fee']]);
                                    }
                                }
                                
                                // Send notification about refund
                                $refund_message = "Match cancelled: Your {$match_info['entry_fee']} {$match_info['entry_type']} entry fee has been refunded.";
                                $supabase->insert('notifications', [
                                    'user_id' => $participant['user_id'],
                                    'type' => 'match_cancelled',
                                    'message' => $refund_message,
                                    'related_id' => $match_id,
                                    'related_type' => 'match',
                                    'created_at' => date('Y-m-d H:i:s')
                                ]);
                            }
                        }
                    }
                    
                    // Update match status
                    $supabase->update('matches', ['status' => 'cancelled'], ['id' => $match_id]);
                    
                    $_SESSION['success'] = "Match cancelled successfully and refunds processed.";
                    header("Location: match_details.php?id=" . $match_id);
                    exit;
                    
                } catch (Exception $e) {
                    error_log("Error cancelling match: " . $e->getMessage());
                    $_SESSION['error'] = "Error cancelling match: " . $e->getMessage();
                    header("Location: match_details.php?id=" . $match_id);
                    exit;
                }
                break;

case 'update_position':
                try {
                    $user_id = $_POST['user_id'];
                    $position = intval($_POST['position']);
                    
                    // Validate position is positive
                    if ($position <= 0) {
                        throw new Exception("Position must be a positive number");
                    }
                    
                    // Check if this position is already taken by another player
                    $existing_position = $supabase->select('match_participants', '*', 
                        ['match_id' => $match_id, 'position' => $position, 'user_id!eq' => $user_id]);
                    if (!empty($existing_position)) {
                        throw new Exception("Position " . $position . " is already taken by another player. Please choose a different position.");
                    }
                    
                    // Update the position
                    $supabase->update('match_participants', 
                        ['position' => $position], 
                        ['match_id' => $match_id, 'user_id' => $user_id]);

                    // Get match details for notification
                    $match_details = $supabase->select('matches', '*', ['id' => $match_id]);
                    $game_details = $supabase->select('games', '*', ['id' => $match_details[0]['game_id']]);
                    $user_details = $supabase->select('users', '*', ['id' => $user_id]);

                    // Get position suffix
                    $suffix = 'th';
                    if ($position == 1) $suffix = 'st';
                    else if ($position == 2) $suffix = 'nd';
                    else if ($position == 3) $suffix = 'rd';

                    // Create notification for the player
                    $notificationMessage = "You secured {$position}{$suffix} position in {$game_details[0]['name']} {$match_details[0]['match_type']} match!";
                    
                    // Insert notification
                    $supabase->insert('notifications', [
                        'user_id' => $user_id,
                        'type' => 'position_update',
                        'message' => $notificationMessage,
                        'related_id' => $match_id,
                        'related_type' => 'match',
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                    
                    header("Location: match_scoring.php?id=" . $match_id . "&success=position");
                    exit;
                } catch (Exception $e) {
                    header("Location: match_scoring.php?id=" . $match_id . "&error=" . urlencode($e->getMessage()));
                    exit;
                }
                break;
        }
    }
}


// Add success/error messages
$success_message = '';
$error_message = '';

if (isset($_GET['success'])) {
    switch($_GET['success']) {
        case 'position':
            $success_message = 'Position updated successfully!';
            break;
        case 'completed':
            $success_message = 'Match completed successfully!';
            break;
        default:
            $success_message = 'Operation completed successfully!';
    }
} elseif (isset($_GET['error'])) {
    $error_message = isset($_GET['error']) && $_GET['error'] !== '1' 
        ? urldecode($_GET['error']) 
        : 'An error occurred. Please try again.';
}
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <h3 class="mb-0">Update Match Score</h3>
                        <div>
                            <a href="match_details.php?id=<?= $match_id ?>" class="btn btn-info me-2">
                                <i class="bi bi-people"></i> View Participants
                            </a>
                            <a href="javascript:history.back()" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Back
                            </a>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <!-- Match Info -->
                    <div class="match-info-header mb-4">
                        <div class="row">
                            <div class="col-md-6">
                                <h4><?= htmlspecialchars($match['game_name']) ?> - <?= ucfirst($match['match_type']) ?></h4>
                                <p class="text-muted">
                                    <i class="bi bi-calendar"></i> <?= date('M j, Y', strtotime($match['match_date'])) ?>
                                    <i class="bi bi-clock ms-3"></i> <?= date('g:i A', strtotime($match['match_time'])) ?>
                                </p>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <div class="prize-info">
                                    <?php if ($match['website_currency_type'] && $match['website_currency_amount'] > 0): ?>
                                        <h5>Prize Pool: <?= number_format($match['website_currency_amount']) ?> <?= ucfirst($match['website_currency_type']) ?></h5>
                                    <?php else: ?>
                                        <h5>Prize Pool: <?= $match['prize_type'] === 'USD' ? '$' : '₹' ?><?= number_format($match['prize_pool']) ?></h5>
                                    <?php endif; ?>
                                    <?php if ($match['coins_per_kill'] > 0): ?>
                                        <p class="text-success">
                                            <i class="bi bi-star"></i> <?= number_format($match['coins_per_kill']) ?> Coins per Kill
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Add Complete Match Button -->
                    <?php if ($match['status'] === 'in_progress'): ?>
                    <div class="text-end mb-4">
                        <form method="POST" id="completeMatchForm">
                            <input type="hidden" name="action" value="complete_match">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-check-lg"></i> Complete Match
                            </button>
                        </form>
                    </div>
                    <?php endif; ?>

                    <!-- Add success/error messages -->
                    <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($success_message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>

                    <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?= htmlspecialchars($error_message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>

                    <!-- Add search box -->
                    <div class="mb-4">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="input-group">
                                    <input type="text" id="searchInput" class="form-control" placeholder="Search by username, game UID, or in-game name...">
                                    <button class="btn btn-outline-secondary" type="button">
                                        <i class="bi bi-search"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

<!-- Participants Table -->
                    <div class="table-responsive">
                        <table class="table table-hover" id="participantsTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Player</th>
                                    <th>Game UID</th>
                                    <th>In-Game Name</th>
                                    <th>Kills</th>
                                    <th>Coins Earned</th>
                                    <th>Prove</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($participants)): ?>
                                    <?php $serialNo = 1; ?>
                                    <?php foreach ($participants as $participant): ?>
                                        <tr class="participant-row" data-user-id="<?= $participant['user_id'] ?>">
                                            <td><?= $serialNo++ ?></td>
                                            <td>
                                                <div class="d-flex flex-column">
                                                    <strong><?= htmlspecialchars($participant['username'] ?? 'N/A') ?></strong>
                                                    <small class="text-muted"><?= htmlspecialchars($participant['email'] ?? '') ?></small>
                                                    <?php if (!empty($participant['position'])): ?>
                                                        <span class="badge bg-success mt-1">Position: <?= $participant['position'] ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="game-uid">
                                                    <?php if (!empty($participant['game_uid'])): ?>
                                                        <code class="bg-light p-1 rounded"><?= htmlspecialchars($participant['game_uid']) ?></code>
                                                    <?php else: ?>
                                                        <span class="text-danger">Not Set</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="game-username">
                                                    <?php if (!empty($participant['game_username'])): ?>
                                                        <span class="fw-bold text-primary"><?= htmlspecialchars($participant['game_username']) ?></span>
                                                        <br>
                                                        <small class="text-muted">Level: <?= $participant['game_level'] ?? 1 ?></small>
                                                    <?php else: ?>
                                                        <span class="text-danger">Not Set</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="kills-display">
                                                    <span class="badge bg-warning text-dark"><?= $participant['total_kills'] ?></span>
                                                    <button class="btn btn-sm btn-outline-primary ms-2" 
                                                            onclick="openKillsModal(<?= $participant['user_id'] ?>, '<?= htmlspecialchars($participant['username']) ?>', <?= $participant['total_kills'] ?>)">
                                                        <i class="bi bi-pencil"></i> Update
                                                    </button>
                                                </div>
                                            </td>
                                            <td>
                                                <?php 
                                                    $coinsEarned = $participant['total_kills'] * ($match['coins_per_kill'] ?? 0);
                                                ?>
                                                <div class="coins-earned">
                                                    <?php if ($coinsEarned > 0): ?>
                                                        <span class="badge bg-success"><?= number_format($coinsEarned) ?> Coins</span>
                                                    <?php else: ?>
                                                        <span class="text-muted">0 Coins</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <?php
                                                    // Get screenshots for this participant
                                                    $screenshots = $supabase->select('match_screenshots', '*', ['match_id' => $match_id, 'user_id' => $participant['user_id']]);
                                                ?>
                                                <div class="proof-section">
                                                    <?php if (!empty($screenshots)): ?>
                                                        <?php foreach ($screenshots as $screenshot): ?>
                                                            <div class="screenshot-item d-inline-block me-2 mb-2 position-relative">
                                                                <!-- Use image_url instead of screenshot_url -->
                                                                <img src="<?= htmlspecialchars($screenshot['image_url']) ?>" 
                                                                     alt="Proof" 
                                                                     class="proof-thumbnail" 
                                                                     style="width: 50px; height: 50px; object-fit: cover; border-radius: 8px; cursor: pointer; border: 2px solid <?= $screenshot['verified'] ? '#28a745' : ($screenshot['verified_at'] ? '#dc3545' : '#ffc107') ?>;"
                                                                     onclick="openProofModal(<?= htmlspecialchars(json_encode($screenshot)) ?>, '<?= htmlspecialchars($participant['username']) ?>')">
                                                                
                                                                <!-- Status badge overlay -->
                                                                <div class="screenshot-status-overlay">
                                                                    <?php if ($screenshot['verified']): ?>
                                                                        <span class="badge bg-success position-absolute top-0 start-100 translate-middle">
                                                                            <i class="bi bi-check-circle-fill"></i>
                                                                        </span>
                                                                    <?php elseif ($screenshot['verified_at']): ?>
                                                                        <span class="badge bg-danger position-absolute top-0 start-100 translate-middle">
                                                                            <i class="bi bi-x-circle-fill"></i>
                                                                        </span>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-warning position-absolute top-0 start-100 translate-middle">
                                                                            <i class="bi bi-clock-fill"></i>
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </div>
                                                                
                                                                <!-- Screenshot info tooltip -->
                                                                <div class="screenshot-tooltip">
                                                                    <small>
                                                                        Kills: <?= $screenshot['kills_claimed'] ?? 0 ?> | 
                                                                        Rank: <?= $screenshot['rank_claimed'] ?? 'N/A' ?>
                                                                        <?php if ($screenshot['won_match']): ?>
                                                                            | 🏆 Winner
                                                                        <?php endif; ?>
                                                                    </small>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                        
                                                        <!-- Summary badge -->
                                                        <div class="proof-summary mt-1">
                                                            <small class="text-muted">
                                                                <?= count($screenshots) ?> screenshot(s)
                                                                <?php 
                                                                    $verified_count = array_sum(array_column($screenshots, 'verified'));
                                                                    if ($verified_count > 0) {
                                                                        echo "| {$verified_count} verified";
                                                                    }
                                                                ?>
                                                            </small>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="no-proof-placeholder text-center p-3" style="border: 2px dashed #dc3545; border-radius: 8px; background-color: #fff5f5;">
                                                            <i class="bi bi-exclamation-triangle text-danger" style="font-size: 1.5rem;"></i>
                                                            <br>
                                                            <small class="text-danger fw-bold">Not Sent</small>
                                                            <br>
                                                            <small class="text-muted">Screenshot unavailable</small>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="action-buttons">
                                                    <!-- Position Update Button -->
                                                    <?php if ($match['status'] === 'in_progress'): ?>
                                                        <button class="btn btn-sm btn-info me-1 mb-1" 
                                                                onclick="openPositionModal(<?= $participant['user_id'] ?>, '<?= htmlspecialchars($participant['username']) ?>', <?= $participant['position'] ?? 'null' ?>)">
                                                            <i class="bi bi-trophy"></i> Position
                                                        </button>
                                                    <?php endif; ?>
                                                    
                                                    <!-- View Profile Button -->
                                                    <button class="btn btn-sm btn-secondary me-1 mb-1" 
                                                            onclick="viewUserProfile(<?= $participant['user_id'] ?>)">
                                                        <i class="bi bi-person"></i> Profile
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">
                                            <i class="bi bi-people"></i> No participants found for this match
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                   
                   
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Update Kills Modal -->
<div class="modal fade" id="updateKillsModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_kills">
                <input type="hidden" name="user_id" id="kill_user_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">Update Kills</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Update kills for <strong id="kill_username"></strong></p>
                    <div class="mb-3">
                        <label for="kills" class="form-label">Number of Kills</label>
                        <input type="number" class="form-control" id="kills" name="kills" min="0" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Winner Modal -->
<div class="modal fade" id="selectWinnerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="select_winner">
                <input type="hidden" name="winner_id" id="winner_user_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">Select Winner</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to select <strong id="winner_username"></strong> as the winner?</p>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> This action will:
                        <ul class="mb-0">
                            <li>Mark this player as the match winner</li>
                            <li>Award the prize pool to this player</li>
                            <li>Cannot be undone</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Confirm Winner</button>
                </div>
            </form>
        </div>
    </div>
</div>



<!-- Add this modal for position selection -->
<div class="modal fade" id="updatePositionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="update_position">
                <input type="hidden" name="user_id" id="position_user_id">
                
                <div class="modal-header">
                    <h5 class="modal-title">Update Position</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Set position for <strong id="position_username"></strong></p>
                    <div class="mb-3">
                        <label for="position" class="form-label">Position</label>
                        <select class="form-control" id="position" name="position" required>
                            <?php
                            // Generate options based on prize distribution
                            $maxPositions = ($match['prize_distribution'] === 'top5') ? 5 : 
                                          ($match['prize_distribution'] === 'top3' ? 3 : 1);
                            for($i = 1; $i <= $maxPositions; $i++) {
                                echo "<option value='$i'>$i</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Complete Match Confirmation Modal -->
<div class="modal fade" id="completeMatchModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="finalCompleteMatchForm">
                <input type="hidden" name="action" value="complete_match">
                <input type="hidden" name="force_complete" id="force_complete_input" value="0">
                
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-check-circle"></i> 
                        Complete Match Confirmation
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="verification-warnings" class="mb-4" style="display: none;">
                        <div class="alert alert-warning">
                            <h6><i class="bi bi-exclamation-triangle"></i> Verification Issues Found:</h6>
                            <div id="warning-content"></div>
                        </div>
                    </div>
                    
                    <div class="completion-summary">
                        <h6><i class="bi bi-info-circle"></i> Match Completion Summary:</h6>
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Total Participants
                                <span class="badge bg-primary rounded-pill" id="total-participants"><?= count($participants) ?></span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Positions Set
                                <span class="badge bg-success rounded-pill" id="positions-set">0</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Screenshots Submitted
                                <span class="badge bg-info rounded-pill" id="screenshots-submitted">0</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                Screenshots Verified
                                <span class="badge bg-success rounded-pill" id="screenshots-verified">0</span>
                            </li>
                        </ul>
                    </div>
                    
                    <div class="prize-distribution mt-4">
                        <h6><i class="bi bi-trophy"></i> Prize Distribution:</h6>
                        <div class="alert alert-info">
                            <p class="mb-2"><strong>Prize Pool:</strong> 
                                <?php if ($match['website_currency_type'] && $match['website_currency_amount'] > 0): ?>
                                    <?= number_format($match['website_currency_amount']) ?> <?= ucfirst($match['website_currency_type']) ?>
                                <?php else: ?>
                                    <?= $match['prize_type'] === 'USD' ? '$' : '₹' ?><?= number_format($match['prize_pool']) ?>
                                <?php endif; ?>
                            </p>
                            <p class="mb-2"><strong>Distribution:</strong> <?= ucfirst($match['prize_distribution']) ?></p>
                            <?php if ($match['coins_per_kill'] > 0): ?>
                                <p class="mb-0"><strong>Kill Rewards:</strong> <?= number_format($match['coins_per_kill']) ?> Coins per Kill</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Cancel
                    </button>
                    
                    <button type="submit" class="btn btn-success" id="confirm-complete-btn">
                        <i class="bi bi-check-lg"></i> <span id="complete-btn-text">Complete Match</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Advanced Proof Verification Modal -->
<div class="modal fade" id="proofVerificationModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-image"></i> 
                    Proof Verification for <span id="proof_player_name"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <!-- Image Display -->
                    <div class="col-md-8">
                        <div class="proof-image-container text-center">
                            <img id="proof_full_image" 
                                 src="" 
                                 alt="Match Proof" 
                                 class="img-fluid rounded shadow" 
                                 style="max-height: 500px; cursor: zoom-in;"
                                 onclick="openImageInNewTab()">
                            <div class="mt-2">
                                <small class="text-muted">
                                    <i class="bi bi-info-circle"></i> 
                                    Click image to open in new tab for full size view
                                </small>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Screenshot Details -->
                    <div class="col-md-4">
                        <div class="proof-details">
                            <h6 class="mb-3">
                                <i class="bi bi-info-circle"></i> Screenshot Details
                            </h6>
                            
                            <div class="detail-item">
                                <strong>Type:</strong>
                                <span id="proof_type" class="badge bg-primary"></span>
                            </div>
                            
                            <div class="detail-item">
                                <strong>Kills Claimed:</strong>
                                <span id="proof_kills" class="badge bg-warning text-dark"></span>
                            </div>
                            
                            <div class="detail-item">
                                <strong>Rank Claimed:</strong>
                                <span id="proof_rank"></span>
                            </div>
                            
                            <div class="detail-item">
                                <strong>Winner Claim:</strong>
                                <span id="proof_winner" class="badge"></span>
                            </div>
                            
                            <div class="detail-item">
                                <strong>Upload Date:</strong>
                                <span id="proof_date"></span>
                            </div>
                            
                            <div class="detail-item">
                                <strong>File Size:</strong>
                                <span id="proof_size"></span>
                            </div>
                            
                            <div class="detail-item">
                                <strong>Current Status:</strong>
                                <span id="proof_status" class="badge"></span>
                            </div>
                            
                            <div class="detail-item" id="proof_description_container">
                                <strong>Description:</strong>
                                <div class="mt-1">
                                    <small id="proof_description" class="text-muted"></small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Admin Notes Section -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="admin-notes-section">
                            <label for="admin_notes" class="form-label">
                                <i class="bi bi-pencil-square"></i> Admin Notes (Optional)
                            </label>
                            <textarea id="admin_notes" 
                                      class="form-control" 
                                      rows="3" 
                                      placeholder="Add verification notes, reasons for rejection, or other comments..."></textarea>
                            <small class="form-text text-muted">
                                These notes will be sent to the player along with the verification status.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer d-flex justify-content-between">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x-circle"></i> Close
                </button>
                
                <div class="verification-buttons">
                    <button type="button" 
                            class="btn btn-danger me-2" 
                            onclick="verifyScreenshot(false)"
                            id="reject_btn">
                        <i class="bi bi-x-lg"></i> Reject
                    </button>
                    
                    <button type="button" 
                            class="btn btn-success" 
                            onclick="verifyScreenshot(true)"
                            id="approve_btn">
                        <i class="bi bi-check-lg"></i> Approve
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>



<style>
.match-info-header {
    background: #f8f9fa;
    padding: 1.5rem;
    border-radius: 8px;
    margin-bottom: 2rem;
}

.prize-info {
    background: #e8f5e9;
    padding: 1rem;
    border-radius: 8px;
    display: inline-block;
}

.table th {
    background: #f8f9fa;
    font-weight: 600;
}

.table td {
    vertical-align: middle;
}

.btn-sm {
    margin: 0.25rem;
}

.modal-content {
    border-radius: 8px;
    border: none;
}

.modal-header {
    background: #f8f9fa;
    border-bottom: 2px solid #dee2e6;
}

.alert {
    border-left: 4px solid #0dcaf0;
}

.alert ul {
    padding-left: 1.25rem;
}

/* Add styles for form validation */
.was-validated .form-control:invalid {
    border-color: #dc3545;
    padding-right: calc(1.5em + 0.75rem);
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath stroke-linejoin='round' d='M5.8 3.6h.4L6 6.5z'/%3e%3ccircle cx='6' cy='8.2' r='.6' fill='%23dc3545' stroke='none'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right calc(0.375em + 0.1875rem) center;
    background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
}

.was-validated .form-control:valid {
    border-color: #198754;
    padding-right: calc(1.5em + 0.75rem);
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3e%3cpath fill='%23198754' d='M2.3 6.73L.6 4.53c-.4-1.04.46-1.4 1.1-.8l1.1 1.4 3.4-3.8c.6-.63 1.6-.27 1.2.7l-4 4.6c-.43.5-.8.4-1.1.1z'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right calc(0.375em + 0.1875rem) center;
    background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
}

/* Screenshot Display Styles */
.screenshot-item {
    position: relative;
    transition: transform 0.2s ease-in-out;
}

.screenshot-item:hover {
    transform: scale(1.05);
}

.proof-thumbnail {
    transition: all 0.2s ease-in-out;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.proof-thumbnail:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    transform: translateY(-2px);
}

.screenshot-info .badge {
    font-size: 0.6em;
    padding: 2px 6px;
}

.screenshot-info small {
    color: #6c757d;
    font-weight: 500;
}

/* Advanced Proof Modal Styles */
.proof-details .detail-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px solid #f0f0f0;
}

.proof-details .detail-item:last-child {
    border-bottom: none;
}

.proof-details .detail-item strong {
    flex: 0 0 120px;
    font-size: 0.9rem;
    color: #495057;
}

.proof-image-container {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 20px;
}

.admin-notes-section {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    border-left: 4px solid #007bff;
}

.verification-buttons .btn {
    min-width: 120px;
}

#proof_full_image {
    border: 2px solid #dee2e6;
    transition: all 0.3s ease;
}

#proof_full_image:hover {
    border-color: #007bff;
    transform: scale(1.02);
}

.screenshot-tooltip {
    position: absolute;
    bottom: -30px;
    left: 50%;
    transform: translateX(-50%);
    background: rgba(0,0,0,0.8);
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 0.8rem;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.3s;
    z-index: 1000;
    white-space: nowrap;
}

.screenshot-item:hover .screenshot-tooltip {
    opacity: 1;
}

.no-proof-placeholder {
    min-height: 80px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
}

</style>

<script>
// Store current screenshot data globally for verification
let currentScreenshotData = null;

// JavaScript functions for advanced proof modal
function openProofModal(screenshotData, playerName) {
    // Parse screenshot data if needed
    const data = typeof screenshotData === 'string' ? JSON.parse(screenshotData) : screenshotData;
    
    // Store current screenshot data for verification
    currentScreenshotData = data;

    // Update modal content with screenshot data
    document.getElementById('proof_player_name').textContent = playerName;
    document.getElementById('proof_full_image').src = data.image_url;
    document.getElementById('proof_type').textContent = data.type || 'Standard';
    document.getElementById('proof_kills').textContent = data.kills_claimed || 0;
    document.getElementById('proof_rank').textContent = data.rank_claimed || 'N/A';
    
    // Set winner badge with appropriate styling
    const winnerBadge = document.getElementById('proof_winner');
    if (data.won_match) {
        winnerBadge.textContent = 'Yes';
        winnerBadge.className = 'badge bg-success';
    } else {
        winnerBadge.textContent = 'No';
        winnerBadge.className = 'badge bg-secondary';
    }
    
    document.getElementById('proof_date').textContent = new Date(data.uploaded_at).toLocaleString();
    document.getElementById('proof_size').textContent = formatBytes(data.file_size || 0);
    
    // Set status based on verification state
    if (data.verified) {
        document.getElementById('proof_status').textContent = 'Verified';
        document.getElementById('proof_status').className = 'badge bg-success';
    } else if (data.verified_at) {
        document.getElementById('proof_status').textContent = 'Rejected';
        document.getElementById('proof_status').className = 'badge bg-danger';
    } else {
        document.getElementById('proof_status').textContent = 'Pending';
        document.getElementById('proof_status').className = 'badge bg-warning text-dark';
    }
    document.getElementById('proof_description_container').style.display = data.description ? 'block' : 'none';
    document.getElementById('proof_description').textContent = data.description || '';
    document.getElementById('admin_notes').value = '';

    // Show modal
    const proofModal = new bootstrap.Modal(document.getElementById('proofVerificationModal'));
    proofModal.show();
}

// Function to format bytes to human-readable size
function formatBytes(bytes, decimals = 2) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}

// Function to open image in new tab for full size viewing
function openImageInNewTab() {
    const imageUrl = document.getElementById('proof_full_image').src;
    if (imageUrl) {
        window.open(imageUrl, '_blank');
    }
}

// Approve or reject screenshot
function verifyScreenshot(approved) {
    if (!currentScreenshotData) {
        alert('No screenshot data available. Please try again.');
        return;
    }

    const notes = document.getElementById('admin_notes').value;
    const action = approved ? 'approve' : 'reject';
    const screenshotId = currentScreenshotData.id;

    // Disable buttons during processing
    document.getElementById('approve_btn').disabled = true;
    document.getElementById('reject_btn').disabled = true;

    // Send AJAX request to verify screenshot
    fetch('verify_screenshot.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({
            action: action,
            screenshot_id: screenshotId,
            notes: notes
        })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            const message = approved ? 'Screenshot approved successfully!' : 'Screenshot rejected successfully!';
            alert(message);
            // Close modal
            const modal = bootstrap.Modal.getInstance(document.getElementById('proofVerificationModal'));
            if (modal) {
                modal.hide();
            }
            // Refresh page to reflect changes
            setTimeout(() => {
                location.reload();
            }, 500);
        } else {
            alert('Error: ' + (data.message || 'Unknown error occurred'));
            // Re-enable buttons
            document.getElementById('approve_btn').disabled = false;
            document.getElementById('reject_btn').disabled = false;
        }
    })
    .catch(error => {
        console.error('Error verifying screenshot:', error);
        alert('An error occurred while verifying the screenshot. Please try again.');
        // Re-enable buttons
        document.getElementById('approve_btn').disabled = false;
        document.getElementById('reject_btn').disabled = false;
    });
}

// JavaScript functions for modal handling and search
document.addEventListener('DOMContentLoaded', function() {
    // Initialize modals
    const killsModal = new bootstrap.Modal(document.getElementById('updateKillsModal'));
    const positionModal = new bootstrap.Modal(document.getElementById('updatePositionModal'));
    const winnerModal = new bootstrap.Modal(document.getElementById('selectWinnerModal'));
    
    // Search functionality
    const searchInput = document.getElementById('searchInput');
    const participantsTable = document.getElementById('participantsTable');
    const tableRows = participantsTable.querySelectorAll('tbody tr');
    
    searchInput.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase().trim();
        
        tableRows.forEach(row => {
            if (row.cells.length === 1) return; // Skip "no participants" row
            
            const username = row.cells[1].textContent.toLowerCase();
            const gameUID = row.cells[2].textContent.toLowerCase();
            const inGameName = row.cells[3].textContent.toLowerCase();
            
            const matches = username.includes(searchTerm) || 
                          gameUID.includes(searchTerm) || 
                          inGameName.includes(searchTerm);
            
            row.style.display = matches ? '' : 'none';
        });
    });
});

// Open kills update modal
function openKillsModal(userId, username, currentKills) {
    document.getElementById('kill_user_id').value = userId;
    document.getElementById('kill_username').textContent = username;
    document.getElementById('kills').value = currentKills;
    
    const modal = new bootstrap.Modal(document.getElementById('updateKillsModal'));
    modal.show();
}

// Open position update modal
function openPositionModal(userId, username, currentPosition) {
    document.getElementById('position_user_id').value = userId;
    document.getElementById('position_username').textContent = username;
    
    const positionSelect = document.getElementById('position');
    if (currentPosition && currentPosition !== 'null') {
        positionSelect.value = currentPosition;
    } else {
        positionSelect.selectedIndex = 0;
    }
    
    const modal = new bootstrap.Modal(document.getElementById('updatePositionModal'));
    modal.show();
}

// Open winner selection modal
function openWinnerModal(userId, username) {
    document.getElementById('winner_user_id').value = userId;
    document.getElementById('winner_username').textContent = username;
    
    const modal = new bootstrap.Modal(document.getElementById('selectWinnerModal'));
    modal.show();
}

// View user profile (placeholder function)
function viewUserProfile(userId) {
    // Redirect to the user's profile page
    window.open('../users/view.php?id=' + userId, '_blank');
}

// Auto-refresh functionality (optional)
function refreshParticipants() {
    location.reload();
}

// Add confirmation for complete match
document.getElementById('completeMatchForm')?.addEventListener('submit', function(e) {
    e.preventDefault(); // Always prevent default submission
    
    // Show the complete match modal with verification checks
    showCompleteMatchModal();
});

// Function to show complete match modal with verification checks
function showCompleteMatchModal() {
    // Calculate verification statistics
    const participants = <?= json_encode($participants) ?>;
    const matchId = <?= $match_id ?>;
    
    let unverifiedUsers = [];
    let usersWithoutProof = [];
    let positionsSet = 0;
    let screenshotsSubmitted = 0;
    let screenshotsVerified = 0;
    
    // Count positions set
    participants.forEach(participant => {
        if (participant.position) {
            positionsSet++;
        }
    });
    
    // Get screenshot data for verification checks
    fetch('get_match_screenshots.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams({
            match_id: matchId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const screenshots = data.screenshots;
            
            // Check each participant for proof and verification status
            participants.forEach(participant => {
                const userScreenshots = screenshots.filter(s => s.user_id == participant.user_id);
                
                if (userScreenshots.length === 0) {
                    // User hasn't submitted any proof
                    usersWithoutProof.push(participant.username || 'Unknown User');
                } else {
                    screenshotsSubmitted++;
                    
                    // Check if all screenshots are verified
                    const hasUnverified = userScreenshots.some(s => !s.verified && !s.verified_at);
                    if (hasUnverified) {
                        unverifiedUsers.push(participant.username || 'Unknown User');
                    } else {
                        const hasVerified = userScreenshots.some(s => s.verified);
                        if (hasVerified) {
                            screenshotsVerified++;
                        }
                    }
                }
            });
            
            // Update modal statistics
            document.getElementById('positions-set').textContent = positionsSet;
            document.getElementById('screenshots-submitted').textContent = screenshotsSubmitted;
            document.getElementById('screenshots-verified').textContent = screenshotsVerified;
            
            // Show warnings if there are issues
            const warningsDiv = document.getElementById('verification-warnings');
            const warningContent = document.getElementById('warning-content');
            const completeBtn = document.getElementById('confirm-complete-btn');
            const completeBtnText = document.getElementById('complete-btn-text');
            const forceCompleteInput = document.getElementById('force_complete_input');
            
            if (unverifiedUsers.length > 0 || usersWithoutProof.length > 0) {
                let warningHtml = '';
                
                if (unverifiedUsers.length > 0) {
                    warningHtml += '<div class="mb-2"><strong>Users with unverified screenshots:</strong><br>';
                    warningHtml += '<span class="text-danger">' + unverifiedUsers.join(', ') + '</span></div>';
                }
                
                if (usersWithoutProof.length > 0) {
                    warningHtml += '<div class="mb-2"><strong>Users who haven\'t submitted proof:</strong><br>';
                    warningHtml += '<span class="text-warning">' + usersWithoutProof.join(', ') + '</span></div>';
                }
                
                warningContent.innerHTML = warningHtml;
                warningsDiv.style.display = 'block';
                
                // Change button text to indicate force completion
                completeBtnText.textContent = 'Complete Anyway';
                completeBtn.className = 'btn btn-warning';
                forceCompleteInput.value = '1';
            } else {
                warningsDiv.style.display = 'none';
                completeBtnText.textContent = 'Complete Match';
                completeBtn.className = 'btn btn-success';
                forceCompleteInput.value = '0';
            }
            
            // Show the modal
            const modal = new bootstrap.Modal(document.getElementById('completeMatchModal'));
            modal.show();
        } else {
            // If we can't get screenshot data, just show a basic confirmation
            if (confirm('Are you sure you want to complete this match? This action cannot be undone and will distribute prizes to winners.')) {
                document.getElementById('completeMatchForm').submit();
            }
        }
    })
    .catch(error => {
        console.error('Error fetching screenshot data:', error);
        // Fallback to basic confirmation
        if (confirm('Are you sure you want to complete this match? This action cannot be undone and will distribute prizes to winners.')) {
            document.getElementById('completeMatchForm').submit();
        }
    });
}

// Highlight rows based on position
document.addEventListener('DOMContentLoaded', function() {
    const rows = document.querySelectorAll('.participant-row');
    rows.forEach(row => {
        const positionBadge = row.querySelector('.badge.bg-success');
        if (positionBadge) {
            const positionText = positionBadge.textContent;
            if (positionText.includes('Position: 1')) {
                row.classList.add('table-warning'); // Gold for 1st place
            } else if (positionText.includes('Position: 2')) {
                row.classList.add('table-info'); // Silver for 2nd place
            } else if (positionText.includes('Position: 3')) {
                row.classList.add('table-secondary'); // Bronze for 3rd place
            }
        }
    });
});
</script>

<?php include '../includes/admin-footer.php'; ?>
