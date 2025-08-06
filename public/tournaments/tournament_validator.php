<?php

function validateTournament($supabaseClient, $tournament_id, $user_id) {
    try {
        // Get tournament details
        $tournament_data = $supabaseClient->select('tournaments', '*', ['id' => $tournament_id], null, 1);
        
        if (empty($tournament_data)) {
            return ['valid' => false, 'error' => 'Tournament not found.'];
        }
        
        $tournament = $tournament_data[0];

        // Check registration status
        if (!in_array($tournament['status'], ['registration_open', 'playing'])) {
            if ($tournament['status'] === 'team_full') {
                return ['valid' => false, 'error' => 'This tournament is full.'];
            } elseif ($tournament['status'] === 'announced') {
                return ['valid' => false, 'error' => 'Registration has not started yet.'];
            } elseif ($tournament['status'] === 'registration_closed' || $tournament['status'] === 'in_progress') {
                return ['valid' => false, 'error' => 'Registration period has ended.'];
            } elseif ($tournament['status'] === 'completed' || $tournament['status'] === 'archived') {
                return ['valid' => false, 'error' => 'This tournament has ended.'];
            } elseif ($tournament['status'] === 'cancelled') {
                return ['valid' => false, 'error' => 'This tournament has been cancelled.'];
            } else {
                return ['valid' => false, 'error' => 'Tournament registration is not available.'];
            }
        }

        // Check if tournament is full
        if ($tournament['current_teams'] >= $tournament['max_teams']) {
            return ['valid' => false, 'error' => 'This tournament is full.'];
        }

        // Check if user has enough tickets
        $user_tickets_data = $supabaseClient->select('user_tickets', 'tickets', ['user_id' => $user_id], null, 1);
        
        if (empty($user_tickets_data) || $user_tickets_data[0]['tickets'] < $tournament['entry_fee']) {
            return [
                'valid' => false, 
                'error' => "You need {$tournament['entry_fee']} tickets to register."
            ];
        }

        // Check if user is already registered (solo) - simplified query
        $solo_registration = $supabaseClient->select(
            'tournament_registrations', 
            '*', 
            [
                'tournament_id' => $tournament_id,
                'user_id' => $user_id
            ]
        );
        
        if (!empty($solo_registration)) {
            // Check if any active registrations exist
            foreach ($solo_registration as $reg) {
                if (in_array($reg['status'], ['pending', 'approved'])) {
                    return ['valid' => false, 'error' => 'You are already registered for this tournament.'];
                }
            }
        }

        // Check if user is already registered through a team - simplified
        $user_teams = $supabaseClient->select(
            'team_members', 
            'team_id', 
            ['user_id' => $user_id, 'status' => 'active']
        );
        
        if (!empty($user_teams)) {
            foreach ($user_teams as $team_member) {
                $team_registration = $supabaseClient->select(
                    'tournament_registrations',
                    '*',
                    [
                        'tournament_id' => $tournament_id,
                        'team_id' => $team_member['team_id']
                    ]
                );
                
                if (!empty($team_registration)) {
                    foreach ($team_registration as $reg) {
                        if (in_array($reg['status'], ['pending', 'approved'])) {
                            return ['valid' => false, 'error' => 'You are already registered for this tournament.'];
                        }
                    }
                }
            }
        }

        return ['valid' => true, 'tournament' => $tournament];
    } catch (Exception $e) {
        error_log("Tournament validation error: " . $e->getMessage());
        return ['valid' => false, 'error' => 'An error occurred while validating tournament.'];
    }
}
