-- ============================================================================
-- MULTI-ROUND GROUP STAGE PROGRESSION - SIMPLE SCHEMA UPDATES
-- ============================================================================
-- Add only the essential columns and tables for multi-round tournament progression
-- No RPC functions - all logic will be handled in PHP

-- ============================================================================
-- 1. ADD ROUND_NUMBER COLUMN TO TOURNAMENT_GROUPS TABLE
-- ============================================================================
-- Add round_number to track which round each group belongs to
ALTER TABLE tournament_groups 
ADD COLUMN IF NOT EXISTS round_number INTEGER DEFAULT 1;

-- Add index for better query performance
CREATE INDEX IF NOT EXISTS idx_tournament_groups_round 
ON tournament_groups (tournament_id, round_number);

-- Add previous round tracking columns to group_participants
ALTER TABLE group_participants 
ADD COLUMN IF NOT EXISTS previous_round_rank INTEGER DEFAULT NULL,
ADD COLUMN IF NOT EXISTS previous_round_points INTEGER DEFAULT 0;

-- ============================================================================
-- 2. CREATE TOURNAMENT_RESULTS TABLE
-- ============================================================================
-- Stores final tournament results and winner information
CREATE TABLE IF NOT EXISTS tournament_results (
    id SERIAL PRIMARY KEY,
    tournament_id INTEGER NOT NULL,
    participant_id INTEGER NOT NULL, -- Can be user_id or team_id depending on mode
    participant_type VARCHAR(10) NOT NULL CHECK (participant_type IN ('user', 'team')),
    final_position INTEGER NOT NULL,
    total_points INTEGER DEFAULT 0,
    total_kills INTEGER DEFAULT 0,
    total_matches INTEGER DEFAULT 0,
    best_placement INTEGER DEFAULT NULL,
    rounds_survived INTEGER DEFAULT 0,
    prize_amount DECIMAL(10,2) DEFAULT 0.00,
    prize_currency VARCHAR(10) DEFAULT 'USD' CHECK (prize_currency IN ('USD', 'INR')),
    website_currency_awarded INTEGER DEFAULT 0, -- Tickets or coins awarded
    website_currency_type VARCHAR(20) DEFAULT 'tickets' CHECK (website_currency_type IN ('tickets', 'coins')),
    status VARCHAR(20) DEFAULT 'declared' CHECK (status IN ('declared', 'prize_pending', 'prize_paid')),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    UNIQUE (tournament_id, participant_id, participant_type),
    UNIQUE (tournament_id, final_position)
);

-- Create indexes for tournament results
CREATE INDEX IF NOT EXISTS idx_tournament_results_tournament ON tournament_results (tournament_id);
CREATE INDEX IF NOT EXISTS idx_tournament_results_participant ON tournament_results (participant_id, participant_type);
CREATE INDEX IF NOT EXISTS idx_tournament_results_position ON tournament_results (tournament_id, final_position);

-- ============================================================================
-- 3. UPDATE EXISTING TOURNAMENT_GROUPS FOR ROUND 1
-- ============================================================================
-- Update existing groups to be marked as Round 1
UPDATE tournament_groups 
SET round_number = 1 
WHERE round_number IS NULL;

-- ============================================================================
-- 4. ADD TRIGGER FOR UPDATED_AT TIMESTAMPS
-- ============================================================================
-- Add trigger for the tournament_results table
CREATE TRIGGER update_tournament_results_updated_at 
    BEFORE UPDATE ON tournament_results 
    FOR EACH ROW 
    EXECUTE FUNCTION update_updated_at_column();

-- ============================================================================
-- 5. ADD COMMENTS FOR DOCUMENTATION
-- ============================================================================
COMMENT ON COLUMN tournament_groups.round_number IS 'Which round this group belongs to (1, 2, 3, etc.) for multi-round tournaments';
COMMENT ON COLUMN group_participants.previous_round_rank IS 'Rank achieved in the previous round (for tracking progression)';
COMMENT ON COLUMN group_participants.previous_round_points IS 'Points earned in the previous round (for tracking progression)';

COMMENT ON TABLE tournament_results IS 'Final tournament results and prize information for completed tournaments';
COMMENT ON COLUMN tournament_results.participant_type IS 'Indicates whether participant_id refers to a user (solo) or team';
COMMENT ON COLUMN tournament_results.rounds_survived IS 'Number of rounds the participant survived before elimination or completion';
COMMENT ON COLUMN tournament_results.website_currency_awarded IS 'Additional website currency (tickets/coins) awarded to winners';

-- ============================================================================
-- 6. VERIFY SCHEMA UPDATES
-- ============================================================================
-- You can run these queries to verify the changes:
-- SELECT column_name, data_type, is_nullable, column_default 
-- FROM information_schema.columns 
-- WHERE table_name = 'tournament_groups' AND column_name = 'round_number';

-- SELECT table_name FROM information_schema.tables 
-- WHERE table_name = 'tournament_results';

-- ============================================================================
-- COMPLETION NOTES
-- ============================================================================
-- Schema updates completed successfully! 
-- 
-- Now your multi-round progression system can:
-- 
-- 1. TRACK ROUNDS: tournament_groups.round_number shows which round each group belongs to
-- 2. TRACK PROGRESSION: group_participants has previous_round_rank and previous_round_points
-- 3. STORE FINAL RESULTS: tournament_results table stores final rankings and prizes
-- 
-- Your PHP code (tournament-rounds-progression.php) can now:
-- - Create Round 1 groups (round_number = 1)
-- - After Round 1 completes, create Round 2 groups (round_number = 2)
-- - Advance top players from Round 1 to Round 2 groups
-- - Continue until final round with 8-16 players
-- - Store final results in tournament_results table
-- 
-- All existing tables (tournament_groups, group_participants, group_matches, etc.) 
-- work perfectly for this system!
