<?php
/**
 * Weekly Finals Tournament Manager
 * Handles progressive elimination tournaments with weekly phases
 */

require_once dirname(__DIR__, 4) . '/config/SupabaseClient.php';

class WeeklyFinalsManager {
    private $supabase;
    
    public function __construct() {
        $this->supabase = new SupabaseClient(true);
    }
    
    /**
     * Create phases for a weekly finals tournament
     */
    public function createPhases($tournamentId, $config) {
        $totalWeeks = $config['total_weeks'] ?? 4;
        $initialParticipants = $config['initial_participants'] ?? 100;
        $finalsParticipants = $config['finals_participants'] ?? 16;
        
        $phases = [];
        $phaseNames = [
            1 => 'Wildcard Week',
            2 => 'Week 1',
            3 => 'Week 2', 
            4 => 'Grand Finals'
        ];
        
        // Calculate elimination progression
        $participantCounts = $this->calculatePhaseProgression($initialParticipants, $finalsParticipants, $totalWeeks);
        
        for ($i = 1; $i <= $totalWeeks; $i++) {
            $isLastPhase = ($i == $totalWeeks);
            $currentParticipants = $participantCounts[$i - 1];
            $nextParticipants = $participantCounts[$i] ?? $finalsParticipants;
            
            $phaseData = [
                'tournament_id' => $tournamentId,
                'phase_number' => $i,
                'phase_name' => $phaseNames[$i] ?? "Week $i",
                'phase_type' => $isLastPhase ? 'finals' : 'elimination',
                'start_date' => date('Y-m-d', strtotime('+' . (($i - 1) * 7) . ' days')),
                'end_date' => date('Y-m-d', strtotime('+' . ($i * 7 - 1) . ' days')),
                'max_participants' => $currentParticipants,
                'advancement_slots' => $nextParticipants,
                'elimination_slots' => $currentParticipants - $nextParticipants,
                'status' => $i == 1 ? 'upcoming' : 'upcoming',
                'format_config' => json_encode([
                    'matches_per_phase' => $this->calculateMatchesPerPhase($currentParticipants),
                    'groups_per_phase' => $this->calculateGroupsPerPhase($currentParticipants)
                ]),
                'scoring_config' => json_encode([
                    'kill_points' => 1,
                    'placement_points' => [
                        1 => 10, 2 => 6, 3 => 5, 4 => 4, 5 => 3,
                        6 => 2, 7 => 1, 8 => 1
                    ]
                ])
            ];
            
            try {
                $result = $this->supabase->insert('tournament_phases', $phaseData);
                $phases[] = $result;
            } catch (Exception $e) {
                throw new Exception("Failed to create phase: " . $e->getMessage());
            }
        }
        
        return $phases;
    }
    
    /**
     * Calculate participant progression across phases
     */
    private function calculatePhaseProgression($initial, $finals, $phases) {
        $progression = [$initial];
        
        if ($phases <= 1) {
            return $progression;
        }
        
        // Calculate reduction rate
        $reductionRate = pow($finals / $initial, 1 / ($phases - 1));
        
        for ($i = 1; $i < $phases; $i++) {
            $nextCount = (int)($progression[$i - 1] * $reductionRate);
            $nextCount = max($nextCount, $finals); // Ensure we don't go below finals count
            $progression[] = $nextCount;
        }
        
        return $progression;
    }
    
    /**
     * Calculate matches per phase based on participant count
     */
    private function calculateMatchesPerPhase($participants) {
        if ($participants <= 20) return 6;
        if ($participants <= 40) return 5;
        if ($participants <= 80) return 4;
        return 3;
    }
    
    /**
     * Calculate groups per phase
     */
    private function calculateGroupsPerPhase($participants) {
        $maxPerGroup = 20;
        return (int)ceil($participants / $maxPerGroup);
    }
    
    /**
     * Initialize first phase participants from tournament registrations
     */
    public function initializeFirstPhase($tournamentId) {
        // Get first phase
        $firstPhase = $this->supabase->select('tournament_phases', '*', [
            'tournament_id' => $tournamentId,
            'phase_number' => 1
        ])[0] ?? null;
        
        if (!$firstPhase) {
            throw new Exception("First phase not found");
        }
        
        // Get registered teams/users
        $registrations = $this->supabase->select('tournament_registrations', '*', [
            'tournament_id' => $tournamentId,
            'status' => 'approved'
        ]);
        
        if (empty($registrations)) {
            throw new Exception("No approved registrations found");
        }
        
        $participants = [];
        foreach ($registrations as $index => $registration) {
            $participantData = [
                'phase_id' => $firstPhase['id'],
                'team_id' => $registration['team_id'],
                'user_id' => $registration['user_id'],
                'qualification_source' => 'registration',
                'seeding_rank' => $index + 1,
                'status' => 'active'
            ];
            
            try {
                $participant = $this->supabase->insert('phase_participants', $participantData);
                $participants[] = $participant;
            } catch (Exception $e) {
                throw new Exception("Failed to add participant to phase: " . $e->getMessage());
            }
        }
        
        // Create groups for the first phase if needed
        $this->createPhaseGroups($firstPhase['id']);
        
        return $participants;
    }
    
    /**
     * Create groups within a phase
     */
    public function createPhaseGroups($phaseId) {
        $phase = $this->supabase->select('tournament_phases', '*', ['id' => $phaseId])[0] ?? null;
        if (!$phase) {
            throw new Exception("Phase not found");
        }
        
        $formatConfig = json_decode($phase['format_config'], true) ?? [];
        $groupsNeeded = $formatConfig['groups_per_phase'] ?? 1;
        
        if ($groupsNeeded <= 1) {
            return []; // Single group phase, no need for separate groups
        }
        
        $groups = [];
        $groupNames = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        
        for ($i = 0; $i < $groupsNeeded; $i++) {
            $groupData = [
                'phase_id' => $phaseId,
                'group_name' => $phase['phase_name'] . ' - Group ' . $groupNames[$i],
                'max_participants' => (int)($phase['max_participants'] / $groupsNeeded),
                'total_matches' => $formatConfig['matches_per_phase'] ?? 4,
                'advancement_slots' => (int)($phase['advancement_slots'] / $groupsNeeded),
                'status' => 'upcoming'
            ];
            
            try {
                $group = $this->supabase->insert('phase_groups', $groupData);
                $groups[] = $group;
            } catch (Exception $e) {
                throw new Exception("Failed to create phase group: " . $e->getMessage());
            }
        }
        
        // Assign participants to groups
        $this->assignParticipantsToPhaseGroups($phaseId, $groups);
        
        return $groups;
    }
    
    /**
     * Assign participants to phase groups
     */
    private function assignParticipantsToPhaseGroups($phaseId, $groups) {
        $participants = $this->supabase->select('phase_participants', '*', [
            'phase_id' => $phaseId,
            'status' => 'active'
        ]);
        
        if (empty($participants) || empty($groups)) {
            return;
        }
        
        // Distribute participants evenly across groups
        foreach ($participants as $index => $participant) {
            $groupIndex = $index % count($groups);
            $targetGroup = $groups[$groupIndex];
            
            // For phase groups, we might need additional logic to track group membership
            // This depends on your specific implementation needs
        }
    }
    
    /**
     * Advance participants from one phase to the next
     */
    public function advanceParticipants($fromPhaseId, $toPhaseId = null) {
        $fromPhase = $this->supabase->select('tournament_phases', '*', ['id' => $fromPhaseId])[0] ?? null;
        if (!$fromPhase) {
            throw new Exception("Source phase not found");
        }
        
        if (!$toPhaseId) {
            // Get next phase
            $nextPhase = $this->supabase->select('tournament_phases', '*', [
                'tournament_id' => $fromPhase['tournament_id'],
                'phase_number' => $fromPhase['phase_number'] + 1
            ])[0] ?? null;
            
            if (!$nextPhase) {
                throw new Exception("Next phase not found");
            }
            
            $toPhaseId = $nextPhase['id'];
        }
        
        // Get top performers from current phase
        $topPerformers = $this->supabase->select('phase_standings', '*', [
            'phase_id' => $fromPhaseId
        ], 'current_rank.asc', $fromPhase['advancement_slots']);
        
        $advancedCount = 0;
        foreach ($topPerformers as $performer) {
            // Mark as qualified in current phase
            $this->supabase->update('phase_participants', [
                'status' => 'qualified',
                'qualification_date' => date('Y-m-d H:i:s'),
                'final_rank' => $performer['current_rank']
            ], ['id' => $performer['phase_participant_id']]);
            
            // Add to next phase
            $nextPhaseData = [
                'phase_id' => $toPhaseId,
                'team_id' => $performer['team_id'],
                'user_id' => $performer['user_id'],
                'qualification_source' => 'previous_phase',
                'seeding_rank' => $performer['current_rank'],
                'entry_points' => $performer['total_points'],
                'status' => 'active'
            ];
            
            try {
                $this->supabase->insert('phase_participants', $nextPhaseData);
                $advancedCount++;
            } catch (Exception $e) {
                error_log("Failed to advance participant: " . $e->getMessage());
            }
        }
        
        // Mark remaining participants as eliminated
        $this->eliminateRemainingParticipants($fromPhaseId);
        
        return $advancedCount;
    }
    
    /**
     * Eliminate remaining participants in a phase
     */
    public function eliminateRemainingParticipants($phaseId) {
        $phase = $this->supabase->select('tournament_phases', '*', ['id' => $phaseId])[0] ?? null;
        if (!$phase) {
            return 0;
        }
        
        // Get participants who didn't qualify
        $remainingParticipants = $this->supabase->select('phase_participants', '*', [
            'phase_id' => $phaseId,
            'status' => 'active'
        ]);
        
        $eliminatedCount = 0;
        foreach ($remainingParticipants as $participant) {
            $this->supabase->update('phase_participants', [
                'status' => 'eliminated',
                'elimination_date' => date('Y-m-d H:i:s')
            ], ['id' => $participant['id']]);
            
            $eliminatedCount++;
        }
        
        return $eliminatedCount;
    }
    
    /**
     * Get phase standings
     */
    public function getPhaseStandings($phaseId) {
        return $this->supabase->select('phase_standings', '*', [
            'phase_id' => $phaseId
        ], 'current_rank.asc');
    }
    
    /**
     * Get all phase standings for a tournament
     */
    public function getAllPhaseStandings($tournamentId) {
        $phases = $this->supabase->select('tournament_phases', '*', [
            'tournament_id' => $tournamentId
        ], 'phase_number.asc');
        
        $allStandings = [];
        foreach ($phases as $phase) {
            $allStandings[$phase['phase_name']] = $this->getPhaseStandings($phase['id']);
        }
        
        return $allStandings;
    }
    
    /**
     * Start next phase
     */
    public function startNextPhase($tournamentId) {
        $currentPhase = $this->supabase->select('tournament_phases', '*', [
            'tournament_id' => $tournamentId,
            'status' => 'completed'
        ], 'phase_number.desc', 1)[0] ?? null;
        
        if (!$currentPhase) {
            // No completed phase, start first phase
            $nextPhaseNumber = 1;
        } else {
            $nextPhaseNumber = $currentPhase['phase_number'] + 1;
        }
        
        $nextPhase = $this->supabase->select('tournament_phases', '*', [
            'tournament_id' => $tournamentId,
            'phase_number' => $nextPhaseNumber
        ])[0] ?? null;
        
        if (!$nextPhase) {
            throw new Exception("Next phase not found");
        }
        
        // Update phase status
        $this->supabase->update('tournament_phases', [
            'status' => 'active',
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $nextPhase['id']]);
        
        // Update tournament current phase
        $this->supabase->update('tournaments', [
            'current_phase_id' => $nextPhase['id'],
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $tournamentId]);
        
        return $nextPhase;
    }
    
    /**
     * Complete current phase
     */
    public function completePhase($phaseId) {
        $phase = $this->supabase->select('tournament_phases', '*', ['id' => $phaseId])[0] ?? null;
        if (!$phase) {
            throw new Exception("Phase not found");
        }
        
        // Mark phase as completed
        $this->supabase->update('tournament_phases', [
            'status' => 'completed',
            'updated_at' => date('Y-m-d H:i:s')
        ], ['id' => $phaseId]);
        
        // Check if this was the final phase
        $isLastPhase = $phase['phase_type'] === 'finals';
        
        if (!$isLastPhase) {
            // Advance participants to next phase
            $nextPhase = $this->supabase->select('tournament_phases', '*', [
                'tournament_id' => $phase['tournament_id'],
                'phase_number' => $phase['phase_number'] + 1
            ])[0] ?? null;
            
            if ($nextPhase) {
                $this->advanceParticipants($phaseId, $nextPhase['id']);
            }
        } else {
            // Tournament completed, determine winners
            $this->determineTournamentWinners($phase['tournament_id']);
        }
        
        return true;
    }
    
    /**
     * Determine tournament winners from final phase
     */
    public function determineTournamentWinners($tournamentId) {
        $finalPhase = $this->supabase->select('tournament_phases', '*', [
            'tournament_id' => $tournamentId,
            'phase_type' => 'finals'
        ])[0] ?? null;
        
        if (!$finalPhase) {
            throw new Exception("Final phase not found");
        }
        
        $finalStandings = $this->getPhaseStandings($finalPhase['id']);
        
        $winners = [];
        foreach ($finalStandings as $index => $standing) {
            if ($index < 3) { // Top 3 winners
                $winnerData = [
                    'tournament_id' => $tournamentId,
                    'team_id' => $standing['team_id'],
                    'position' => $index + 1,
                    'prize_amount' => $this->calculatePrizeAmount($tournamentId, $index + 1),
                    'payment_status' => 'pending'
                ];
                
                try {
                    $winner = $this->supabase->insert('tournament_winners', $winnerData);
                    $winners[] = $winner;
                } catch (Exception $e) {
                    error_log("Failed to record tournament winner: " . $e->getMessage());
                }
            }
        }
        
        return $winners;
    }
    
    /**
     * Calculate prize amount based on position
     */
    private function calculatePrizeAmount($tournamentId, $position) {
        $tournament = $this->supabase->select('tournaments', 'prize_pool', ['id' => $tournamentId])[0] ?? null;
        if (!$tournament) {
            return 0;
        }
        
        $totalPrize = $tournament['prize_pool'];
        $distribution = [
            1 => 0.50, // 50% for 1st place
            2 => 0.30, // 30% for 2nd place
            3 => 0.20  // 20% for 3rd place
        ];
        
        return $totalPrize * ($distribution[$position] ?? 0);
    }
    
    /**
     * Get tournament progress
     */
    public function getTournamentProgress($tournamentId) {
        $phases = $this->supabase->select('tournament_phases', '*', [
            'tournament_id' => $tournamentId
        ], 'phase_number.asc');
        
        $progress = [
            'total_phases' => count($phases),
            'completed_phases' => 0,
            'current_phase' => null,
            'next_phase' => null
        ];
        
        foreach ($phases as $phase) {
            if ($phase['status'] === 'completed') {
                $progress['completed_phases']++;
            } elseif ($phase['status'] === 'active') {
                $progress['current_phase'] = $phase;
            } elseif ($phase['status'] === 'upcoming' && !$progress['next_phase']) {
                $progress['next_phase'] = $phase;
            }
        }
        
        return $progress;
    }
    
    /**
     * Get comprehensive tournament overview
     */
    public function getTournamentOverview($tournamentId) {
        return $this->supabase->select('tournament_format_overview', '*', [
            'id' => $tournamentId
        ])[0] ?? null;
    }
}
?>
