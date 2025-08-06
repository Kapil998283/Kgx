<?php
/**
 * Group Stage Tournament Manager
 * Handles BMPS-style group stage tournaments with multiple groups
 * and advancement to finals
 */

require_once dirname(__DIR__, 4) . '/config/SupabaseClient.php';

class GroupStageManager {
    private $supabase;
    
    public function __construct() {
        $this->supabase = new SupabaseClient(true);
    }
    
    /**
     * Create groups for a tournament
     */
    public function createGroups($tournamentId, $config) {
        $numGroups = $config['num_groups'] ?? 4;
        $teamsPerGroup = $config['teams_per_group'] ?? 20;
        $matchesPerGroup = $config['matches_per_group'] ?? 6;
        $qualificationSlots = $config['qualification_slots'] ?? 5;
        
        $groups = [];
        $groupNames = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        
        for ($i = 0; $i < $numGroups; $i++) {
            $groupData = [
                'tournament_id' => $tournamentId,
                'group_name' => 'Group ' . $groupNames[$i],
                'group_type' => 'qualification',
                'max_teams' => $teamsPerGroup,
                'total_matches' => $matchesPerGroup,
                'advancement_slots' => $qualificationSlots,
                'status' => 'upcoming',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            try {
                $result = $this->supabase->insert('tournament_groups', $groupData);
                $groups[] = $result;
            } catch (Exception $e) {
                throw new Exception("Failed to create group: " . $e->getMessage());
            }
        }
        
        return $groups;
    }
    
    /**
     * Assign teams to groups using balanced distribution
     */
    public function assignTeamsToGroups($tournamentId, $registeredTeams) {
        // Get tournament groups
        $groups = $this->supabase->select('tournament_groups', '*', [
            'tournament_id' => $tournamentId,
            'group_type' => 'qualification'
        ]);
        
        if (empty($groups)) {
            throw new Exception("No groups found for tournament");
        }
        
        // Sort teams by skill level if available, otherwise random
        $this->shuffleArray($registeredTeams);
        
        $assignments = [];
        $groupIndex = 0;
        $teamsPerGroup = [];
        
        // Initialize team counters
        foreach ($groups as $group) {
            $teamsPerGroup[$group['id']] = 0;
        }
        
        // Snake draft assignment for balance
        foreach ($registeredTeams as $index => $team) {
            $currentGroup = $groups[$groupIndex];
            
            // Check if group is full
            if ($teamsPerGroup[$currentGroup['id']] >= $currentGroup['max_teams']) {
                $groupIndex = ($groupIndex + 1) % count($groups);
                $currentGroup = $groups[$groupIndex];
            }
            
            $assignmentData = [
                'group_id' => $currentGroup['id'],
                'team_id' => $team['team_id'] ?? null,
                'user_id' => $team['user_id'] ?? null,
                'seeding_position' => $index + 1,
                'status' => 'active',
                'assignment_date' => date('Y-m-d H:i:s')
            ];
            
            try {
                $result = $this->supabase->insert('group_teams', $assignmentData);
                $assignments[] = $result;
                $teamsPerGroup[$currentGroup['id']]++;
                
                // Snake draft: reverse direction after each row
                if (($index + 1) % count($groups) == 0) {
                    $groups = array_reverse($groups);
                    $groupIndex = 0;
                } else {
                    $groupIndex = ($groupIndex + 1) % count($groups);
                }
            } catch (Exception $e) {
                throw new Exception("Failed to assign team to group: " . $e->getMessage());
            }
        }
        
        return $assignments;
    }
    
    /**
     * Generate matches for all groups
     */
    public function generateAllGroupMatches($tournamentId) {
        $groups = $this->supabase->select('tournament_groups', '*', [
            'tournament_id' => $tournamentId
        ]);
        
        $allMatches = [];
        foreach ($groups as $group) {
            $matches = $this->generateGroupMatches($group['id']);
            $allMatches = array_merge($allMatches, $matches);
        }
        
        return $allMatches;
    }
    
    /**
     * Generate matches for a specific group
     */
    public function generateGroupMatches($groupId) {
        $group = $this->supabase->select('tournament_groups', '*', ['id' => $groupId])[0] ?? null;
        if (!$group) {
            throw new Exception("Group not found");
        }
        
        $matches = [];
        $totalMatches = $group['total_matches'];
        
        for ($i = 1; $i <= $totalMatches; $i++) {
            // Create tournament round first
            $roundData = [
                'tournament_id' => $group['tournament_id'],
                'round_number' => $i,
                'name' => $group['group_name'] . ' - Match ' . $i,
                'description' => 'Group stage match ' . $i . ' for ' . $group['group_name'],
                'start_time' => date('Y-m-d H:i:s', strtotime('+' . ($i * 2) . ' hours')),
                'teams_count' => $group['current_teams'],
                'qualifying_teams' => $group['current_teams'],
                'round_format' => 'points',
                'kill_points' => 1,
                'placement_points' => json_encode([
                    1 => 10, 2 => 6, 3 => 5, 4 => 4, 5 => 3,
                    6 => 2, 7 => 1, 8 => 1
                ]),
                'status' => 'upcoming'
            ];
            
            $round = $this->supabase->insert('tournament_rounds', $roundData);
            
            // Create group match
            $matchData = [
                'group_id' => $groupId,
                'match_number' => $i,
                'round_id' => $round['id'],
                'match_name' => $group['group_name'] . ' - Match ' . $i,
                'scheduled_time' => $roundData['start_time'],
                'status' => 'scheduled'
            ];
            
            $match = $this->supabase->insert('group_matches', $matchData);
            $matches[] = $match;
        }
        
        return $matches;
    }
    
    /**
     * Submit match results for a group match
     */
    public function submitMatchResults($groupMatchId, $results) {
        // Validate match exists
        $match = $this->supabase->select('group_matches', '*', ['id' => $groupMatchId])[0] ?? null;
        if (!$match) {
            throw new Exception("Match not found");
        }
        
        $submittedResults = [];
        
        foreach ($results as $result) {
            // Calculate points
            $killPoints = ($result['kills'] ?? 0) * 1; // 1 point per kill
            $placementPoints = $this->getPlacementPoints($result['final_placement']);
            $totalPoints = $killPoints + $placementPoints + ($result['bonus_points'] ?? 0);
            
            $resultData = [
                'group_match_id' => $groupMatchId,
                'team_id' => $result['team_id'] ?? null,
                'user_id' => $result['user_id'] ?? null,
                'final_placement' => $result['final_placement'],
                'kills' => $result['kills'] ?? 0,
                'kill_points' => $killPoints,
                'placement_points' => $placementPoints,
                'bonus_points' => $result['bonus_points'] ?? 0,
                'total_points' => $totalPoints,
                'chicken_dinner' => $result['final_placement'] == 1,
                'screenshot_url' => $result['screenshot_url'] ?? null,
                'verified' => false
            ];
            
            $submittedResult = $this->supabase->insert('group_match_results', $resultData);
            $submittedResults[] = $submittedResult;
        }
        
        // Update match status
        $this->supabase->update('group_matches', [
            'status' => 'completed',
            'results_submitted' => true,
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $groupMatchId]);
        
        // Update group team statistics
        $this->updateGroupTeamStatistics($match['group_id']);
        
        return $submittedResults;
    }
    
    /**
     * Get placement points based on final placement
     */
    private function getPlacementPoints($placement) {
        $placementPoints = [
            1 => 10, 2 => 6, 3 => 5, 4 => 4, 5 => 3,
            6 => 2, 7 => 1, 8 => 1, 9 => 0, 10 => 0
        ];
        
        return $placementPoints[$placement] ?? 0;
    }
    
    /**
     * Update group team statistics after match completion
     */
    private function updateGroupTeamStatistics($groupId) {
        try {
            // Use the database function to update statistics
            $this->supabase->rpc('update_group_team_stats', ['group_id_param' => $groupId]);
        } catch (Exception $e) {
            error_log("Failed to update group team statistics: " . $e->getMessage());
        }
    }
    
    /**
     * Get group standings
     */
    public function getGroupStandings($groupId) {
        try {
            return $this->supabase->rpc('get_group_standings', ['group_id_param' => $groupId]);
        } catch (Exception $e) {
            // Fallback to view query if RPC fails
            return $this->supabase->select('group_standings', '*', ['group_id' => $groupId], 'group_rank.asc');
        }
    }
    
    /**
     * Get all group standings for a tournament
     */
    public function getAllGroupStandings($tournamentId) {
        $groups = $this->supabase->select('tournament_groups', '*', [
            'tournament_id' => $tournamentId
        ]);
        
        $allStandings = [];
        foreach ($groups as $group) {
            $allStandings[$group['group_name']] = $this->getGroupStandings($group['id']);
        }
        
        return $allStandings;
    }
    
    /**
     * Advance qualified teams to finals
     */
    public function advanceQualifiedTeams($tournamentId) {
        try {
            return $this->supabase->rpc('advance_qualified_teams', [
                'tournament_id_param' => $tournamentId
            ]);
        } catch (Exception $e) {
            throw new Exception("Failed to advance qualified teams: " . $e->getMessage());
        }
    }
    
    /**
     * Create finals group from qualified teams
     */
    public function createFinalsGroup($tournamentId) {
        // Get all qualified teams
        $qualifiedTeams = $this->supabase->query("
            SELECT gt.team_id, gt.user_id, gs.total_points, gs.participant_name
            FROM group_teams gt
            JOIN group_standings gs ON (
                (gt.team_id IS NOT NULL AND gs.team_id = gt.team_id) OR
                (gt.user_id IS NOT NULL AND gs.user_id = gt.user_id)
            )
            JOIN tournament_groups tg ON gt.group_id = tg.id
            WHERE tg.tournament_id = ? AND gt.status = 'qualified'
            ORDER BY gs.total_points DESC
        ", [$tournamentId]);
        
        if (empty($qualifiedTeams)) {
            throw new Exception("No qualified teams found");
        }
        
        // Create finals group
        $finalsGroup = $this->supabase->insert('tournament_groups', [
            'tournament_id' => $tournamentId,
            'group_name' => 'Finals',
            'group_type' => 'finals',
            'max_teams' => count($qualifiedTeams),
            'current_teams' => 0,
            'total_matches' => 6,
            'advancement_slots' => 3, // Top 3 winners
            'status' => 'upcoming'
        ]);
        
        // Add qualified teams to finals
        foreach ($qualifiedTeams as $index => $team) {
            $this->supabase->insert('group_teams', [
                'group_id' => $finalsGroup['id'],
                'team_id' => $team['team_id'],
                'user_id' => $team['user_id'],
                'seeding_position' => $index + 1,
                'status' => 'active'
            ]);
        }
        
        // Generate finals matches
        $this->generateGroupMatches($finalsGroup['id']);
        
        return $finalsGroup;
    }
    
    /**
     * Get tournament overview
     */
    public function getTournamentOverview($tournamentId) {
        return $this->supabase->select('tournament_format_overview', '*', [
            'id' => $tournamentId
        ])[0] ?? null;
    }
    
    /**
     * Helper function to shuffle array (for team randomization)
     */
    private function shuffleArray(&$array) {
        for ($i = count($array) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            $temp = $array[$i];
            $array[$i] = $array[$j];
            $array[$j] = $temp;
        }
    }
    
    /**
     * Check if all groups are completed
     */
    public function areAllGroupsCompleted($tournamentId) {
        $groups = $this->supabase->select('tournament_groups', '*', [
            'tournament_id' => $tournamentId,
            'group_type' => 'qualification'
        ]);
        
        foreach ($groups as $group) {
            if ($group['status'] !== 'completed') {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Mark group as completed when all matches are done
     */
    public function checkAndMarkGroupCompleted($groupId) {
        $group = $this->supabase->select('tournament_groups', '*', ['id' => $groupId])[0] ?? null;
        if (!$group) {
            return false;
        }
        
        // Check if all matches are completed
        $completedMatches = $this->supabase->select('group_matches', 'COUNT(*) as count', [
            'group_id' => $groupId,
            'status' => 'completed'
        ])[0]['count'] ?? 0;
        
        if ($completedMatches >= $group['total_matches']) {
            $this->supabase->update('tournament_groups', [
                'status' => 'completed',
                'end_date' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ], ['id' => $groupId]);
            
            return true;
        }
        
        return false;
    }
}
?>
