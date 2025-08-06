<?php
require_once __DIR__ . '/SupabaseClient.php';
require_once __DIR__ . '/AuthManager.php';

/**
 * Database Helper for KGX Esports Platform
 * 
 * Contains common database operations for the esports platform
 */
class DatabaseHelper {
    private $supabase;
    private $serviceClient;
    private $auth;
    
    public function __construct() {
        $this->supabase = new SupabaseClient();
        $this->serviceClient = new SupabaseClient(true); // Service role for admin operations
        $this->auth = new AuthManager();
    }
    
    // ============================================================================
    // TOURNAMENT OPERATIONS
    // ============================================================================
    
    /**
     * Get all active tournaments
     */
    public function getTournaments($limit = null, $status = null) {
        $conditions = ['is_active' => true];
        if ($status) {
            $conditions['status'] = $status;
        }
        
        return $this->supabase->select('tournaments', '*', $conditions, 'created_at.desc', $limit);
    }
    
    /**
     * Get tournament by ID
     */
    public function getTournament($id) {
        $tournaments = $this->supabase->select('tournaments', '*', ['id' => $id]);
        return !empty($tournaments) ? $tournaments[0] : null;
    }
    
    /**
     * Register team for tournament
     */
    public function registerForTournament($tournamentId, $teamId = null, $userId = null) {
        if (!$teamId && !$userId) {
            return ['success' => false, 'message' => 'Either team ID or user ID is required'];
        }
        
        try {
            $data = [
                'tournament_id' => $tournamentId,
                'team_id' => $teamId,
                'user_id' => $userId,
                'status' => 'pending'
            ];
            
            $result = $this->supabase->insert('tournament_registrations', $data);
            
            return ['success' => true, 'message' => 'Registration successful', 'data' => $result];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get tournament registrations for a user
     */
    public function getUserTournamentRegistrations($userId) {
        return $this->supabase->select('tournament_registrations', '*', ['user_id' => $userId]);
    }
    
    // ============================================================================
    // TEAM OPERATIONS
    // ============================================================================
    
    /**
     * Create a new team
     */
    public function createTeam($name, $description, $language, $maxMembers = 5) {
        if (!$this->auth->isLoggedIn()) {
            return ['success' => false, 'message' => 'Authentication required'];
        }
        
        try {
            $userId = $this->auth->getCurrentUserId();
            
            $teamData = [
                'name' => $name,
                'description' => $description,
                'language' => $language,
                'max_members' => $maxMembers,
                'current_members' => 1,
                'captain_id' => $userId,
                'is_active' => true
            ];
            
            $result = $this->supabase->insert('teams', $teamData);
            
            if (!empty($result)) {
                $teamId = $result[0]['id'];
                
                // Add captain as team member
                $this->supabase->insert('team_members', [
                    'team_id' => $teamId,
                    'user_id' => $userId,
                    'role' => 'captain',
                    'status' => 'active'
                ]);
                
                return ['success' => true, 'message' => 'Team created successfully', 'team_id' => $teamId];
            }
            
            throw new Exception('Failed to create team');
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get user's teams
     */
    public function getUserTeams($userId) {
        $sql = "
            SELECT t.*, tm.role as user_role
            FROM teams t
            INNER JOIN team_members tm ON t.id = tm.team_id
            WHERE tm.user_id = ? AND tm.status = 'active' AND t.is_active = true
        ";
        
        return $this->supabase->query($sql, [$userId]);
    }
    
    /**
     * Get team members
     */
    public function getTeamMembers($teamId) {
        $sql = "
            SELECT u.id, u.username, u.full_name, u.profile_image, tm.role, tm.joined_at
            FROM team_members tm
            INNER JOIN users u ON tm.user_id = u.id
            WHERE tm.team_id = ? AND tm.status = 'active'
            ORDER BY tm.role DESC, tm.joined_at ASC
        ";
        
        return $this->supabase->query($sql, [$teamId]);
    }
    
    /**
     * Join team request
     */
    public function requestToJoinTeam($teamId) {
        if (!$this->auth->isLoggedIn()) {
            return ['success' => false, 'message' => 'Authentication required'];
        }
        
        try {
            $userId = $this->auth->getCurrentUserId();
            
            // Check if already member or has pending request
            $existing = $this->supabase->select('team_members', '*', [
                'team_id' => $teamId,
                'user_id' => $userId
            ]);
            
            if (!empty($existing)) {
                return ['success' => false, 'message' => 'Already a member or request pending'];
            }
            
            $this->supabase->insert('team_join_requests', [
                'team_id' => $teamId,
                'user_id' => $userId,
                'status' => 'pending'
            ]);
            
            return ['success' => true, 'message' => 'Join request sent successfully'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    // ============================================================================
    // MATCH OPERATIONS
    // ============================================================================
    
    /**
     * Get available matches
     */
    public function getMatches($gameId = null, $status = 'upcoming', $limit = 20) {
        $conditions = ['status' => $status];
        if ($gameId) {
            $conditions['game_id'] = $gameId;
        }
        
        return $this->supabase->select('matches', '*', $conditions, 'match_date.asc', $limit);
    }
    
    /**
     * Join a match
     */
    public function joinMatch($matchId) {
        if (!$this->auth->isLoggedIn()) {
            return ['success' => false, 'message' => 'Authentication required'];
        }
        
        try {
            $userId = $this->auth->getCurrentUserId();
            
            // Check if already joined
            $existing = $this->supabase->select('match_participants', '*', [
                'match_id' => $matchId,
                'user_id' => $userId
            ]);
            
            if (!empty($existing)) {
                return ['success' => false, 'message' => 'Already joined this match'];
            }
            
            $this->supabase->insert('match_participants', [
                'match_id' => $matchId,
                'user_id' => $userId,
                'status' => 'joined'
            ]);
            
            return ['success' => true, 'message' => 'Successfully joined the match'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get user's match history
     */
    public function getUserMatchHistory($userId, $limit = 50) {
        $sql = "
            SELECT m.*, mp.status as participation_status, mp.position,
                   g.name as game_name, uk.kills
            FROM match_participants mp
            INNER JOIN matches m ON mp.match_id = m.id
            INNER JOIN games g ON m.game_id = g.id
            LEFT JOIN user_kills uk ON uk.match_id = m.id AND uk.user_id = mp.user_id
            WHERE mp.user_id = ?
            ORDER BY m.match_date DESC
            LIMIT ?
        ";
        
        return $this->supabase->query($sql, [$userId, $limit]);
    }
    
    // ============================================================================
    // USER FINANCIAL OPERATIONS
    // ============================================================================
    
    /**
     * Add coins to user
     */
    public function addCoins($userId, $amount, $description = 'Coins added') {
        try {
            return $this->supabase->transaction(function($pdo) use ($userId, $amount, $description) {
                // Update coins
                $sql = "
                    INSERT INTO user_coins (user_id, coins) 
                    VALUES (?, ?) 
                    ON CONFLICT (user_id) 
                    DO UPDATE SET coins = user_coins.coins + ?
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$userId, $amount, $amount]);
                
                // Add transaction record
                $sql = "
                    INSERT INTO transactions (user_id, amount, type, description, currency_type) 
                    VALUES (?, ?, 'reward', ?, 'coins')
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$userId, $amount, $description]);
                
                return true;
            });
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Add tickets to user
     */
    public function addTickets($userId, $amount, $description = 'Tickets added') {
        try {
            return $this->supabase->transaction(function($pdo) use ($userId, $amount, $description) {
                // Update tickets
                $sql = "
                    INSERT INTO user_tickets (user_id, tickets) 
                    VALUES (?, ?) 
                    ON CONFLICT (user_id) 
                    DO UPDATE SET tickets = user_tickets.tickets + ?
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$userId, $amount, $amount]);
                
                // Add transaction record
                $sql = "
                    INSERT INTO transactions (user_id, amount, type, description, currency_type) 
                    VALUES (?, ?, 'reward', ?, 'tickets')
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$userId, $amount, $description]);
                
                return true;
            });
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get user's transaction history
     */
    public function getUserTransactions($userId, $limit = 50) {
        return $this->supabase->select('transactions', '*', 
            ['user_id' => $userId], 
            'created_at.desc', 
            $limit
        );
    }
    
    // ============================================================================
    // LEADERBOARD OPERATIONS
    // ============================================================================
    
    /**
     * Get top players by total kills
     */
    public function getKillsLeaderboard($limit = 10) {
        $sql = "
            SELECT u.id, u.username, u.full_name, u.profile_image, 
                   ums.total_kills, ums.total_matches_played,
                   CASE 
                       WHEN ums.total_matches_played > 0 
                       THEN ROUND(ums.total_kills::numeric / ums.total_matches_played, 2)
                       ELSE 0 
                   END as avg_kills_per_match
            FROM user_match_stats ums
            INNER JOIN users u ON ums.user_id = u.id
            WHERE ums.total_kills > 0
            ORDER BY ums.total_kills DESC
            LIMIT ?
        ";
        
        return $this->supabase->query($sql, [$limit]);
    }
    
    /**
     * Get top teams by total score
     */
    public function getTeamsLeaderboard($limit = 10) {
        return $this->supabase->select('teams', 
            'id,name,total_score,current_members', 
            ['is_active' => true], 
            'total_score.desc', 
            $limit
        );
    }
    
    // ============================================================================
    // STREAK OPERATIONS
    // ============================================================================
    
    /**
     * Update user streak
     */
    public function updateUserStreak($userId, $taskId, $points) {
        try {
            return $this->supabase->transaction(function($pdo) use ($userId, $taskId, $points) {
                // Add completed task
                $sql = "
                    INSERT INTO user_streak_tasks (user_id, task_id, points_earned) 
                    VALUES (?, ?, ?)
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$userId, $taskId, $points]);
                
                // Update user streak
                $sql = "
                    INSERT INTO user_streaks (user_id, streak_points, total_earned_points, total_tasks_completed, current_streak, last_activity_date) 
                    VALUES (?, ?, ?, 1, 1, CURRENT_DATE)
                    ON CONFLICT (user_id) 
                    DO UPDATE SET 
                        streak_points = user_streaks.streak_points + ?,
                        total_earned_points = user_streaks.total_earned_points + ?,
                        total_tasks_completed = user_streaks.total_tasks_completed + 1,
                        current_streak = CASE 
                            WHEN user_streaks.last_activity_date = CURRENT_DATE THEN user_streaks.current_streak
                            WHEN user_streaks.last_activity_date = CURRENT_DATE - INTERVAL '1 day' THEN user_streaks.current_streak + 1
                            ELSE 1
                        END,
                        longest_streak = GREATEST(user_streaks.longest_streak, 
                            CASE 
                                WHEN user_streaks.last_activity_date = CURRENT_DATE THEN user_streaks.current_streak
                                WHEN user_streaks.last_activity_date = CURRENT_DATE - INTERVAL '1 day' THEN user_streaks.current_streak + 1
                                ELSE 1
                            END
                        ),
                        last_activity_date = CURRENT_DATE
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$points, $points, $points, $points]);
                
                return true;
            });
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get available streak tasks
     */
    public function getStreakTasks() {
        return $this->supabase->select('streak_tasks', '*', ['is_active' => true]);
    }
    
    /**
     * Get user's streak data
     */
    public function getUserStreak($userId) {
        $streaks = $this->supabase->select('user_streaks', '*', ['user_id' => $userId]);
        return !empty($streaks) ? $streaks[0] : null;
    }
    
    // ============================================================================
    // GENERAL UTILITY FUNCTIONS
    // ============================================================================
    
    /**
     * Get all games
     */
    public function getGames() {
        return $this->supabase->select('games', '*', ['status' => 'active']);
    }
    
    /**
     * Search users by username
     */
    public function searchUsers($query, $limit = 10) {
        $sql = "
            SELECT id, username, full_name, profile_image
            FROM users 
            WHERE username ILIKE ? OR full_name ILIKE ?
            LIMIT ?
        ";
        
        $searchTerm = "%{$query}%";
        return $this->supabase->query($sql, [$searchTerm, $searchTerm, $limit]);
    }
    
    /**
     * Get notifications for user
     */
    public function getUserNotifications($userId, $limit = 20) {
        return $this->supabase->select('notifications', '*', 
            ['user_id' => $userId], 
            'created_at.desc', 
            $limit
        );
    }
    
    /**
     * Mark notification as read
     */
    public function markNotificationRead($notificationId, $userId) {
        return $this->supabase->update('notifications', 
            ['is_read' => true], 
            ['id' => $notificationId, 'user_id' => $userId]
        );
    }
    
    /**
     * Create notification
     */
    public function createNotification($userId, $message, $type = 'general', $relatedId = null, $relatedType = null) {
        try {
            $this->serviceClient->insert('notifications', [
                'user_id' => $userId,
                'message' => $message,
                'type' => $type,
                'related_id' => $relatedId,
                'related_type' => $relatedType
            ]);
            
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}
