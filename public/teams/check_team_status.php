<?php
function checkTeamStatus($supabaseClient, $user_id) {
    try {
        // First check if user is a captain of any team (via teams.captain_id)
        $captain_teams = $supabaseClient->select('teams', '*', [
            'captain_id' => $user_id,
            'is_active' => true
        ]);
        
        if (!empty($captain_teams)) {
            $team = $captain_teams[0];
            return [
                'is_member' => true,
                'team_name' => $team['name'],
                'role' => 'captain',
                'message' => 'You are already a captain of team "' . $team['name'] . '"'
            ];
        }
        
        // Then check if user is a member of any team (via team_members table)
        $team_memberships = $supabaseClient->select('team_members', '*', [
            'user_id' => $user_id,
            'status' => 'active'
        ]);
        
        if (!empty($team_memberships)) {
            $membership = $team_memberships[0];
            
            // Get team details
            $teams = $supabaseClient->select('teams', '*', [
                'id' => $membership['team_id'],
                'is_active' => true
            ]);
            
            if (!empty($teams)) {
                $team = $teams[0];
                return [
                    'is_member' => true,
                    'team_name' => $team['name'],
                    'role' => $membership['role'],
                    'message' => $membership['role'] === 'captain' 
                        ? 'You are already a captain of team "' . $team['name'] . '"' 
                        : 'You are already a member of team "' . $team['name'] . '". Leave that team first to create or join another one.'
                ];
            }
        }
    } catch (Exception $e) {
        error_log("Error checking team status: " . $e->getMessage());
    }

    return ['is_member' => false];
}
