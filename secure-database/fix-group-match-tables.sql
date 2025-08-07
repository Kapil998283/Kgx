-- ============================================================================
-- FIX GROUP MATCH PARTICIPANTS AND TEAMS TABLES
-- Add missing scoring columns that the PHP tournament scoring system expects
-- ============================================================================

-- Add scoring columns to group_match_participants table
ALTER TABLE group_match_participants 
ADD COLUMN IF NOT EXISTS placement INTEGER DEFAULT NULL,
ADD COLUMN IF NOT EXISTS kills INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS kill_points INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS placement_points INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS bonus_points INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS total_points INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP;

-- Add scoring columns to group_match_teams table  
ALTER TABLE group_match_teams
ADD COLUMN IF NOT EXISTS placement INTEGER DEFAULT NULL,
ADD COLUMN IF NOT EXISTS kills INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS kill_points INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS placement_points INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS bonus_points INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS total_points INTEGER DEFAULT 0,
ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP;

-- Create indexes for the new scoring columns
CREATE INDEX IF NOT EXISTS idx_group_match_participants_placement ON group_match_participants (placement);
CREATE INDEX IF NOT EXISTS idx_group_match_participants_total_points ON group_match_participants (total_points);
CREATE INDEX IF NOT EXISTS idx_group_match_teams_placement ON group_match_teams (placement);
CREATE INDEX IF NOT EXISTS idx_group_match_teams_total_points ON group_match_teams (total_points);

-- Update the updated_at timestamp when records are modified
CREATE OR REPLACE FUNCTION update_group_match_participant_timestamp()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- Add triggers to automatically update the updated_at timestamp
DROP TRIGGER IF EXISTS update_group_match_participants_timestamp ON group_match_participants;
CREATE TRIGGER update_group_match_participants_timestamp
    BEFORE UPDATE ON group_match_participants
    FOR EACH ROW
    EXECUTE FUNCTION update_group_match_participant_timestamp();

DROP TRIGGER IF EXISTS update_group_match_teams_timestamp ON group_match_teams;
CREATE TRIGGER update_group_match_teams_timestamp
    BEFORE UPDATE ON group_match_teams
    FOR EACH ROW
    EXECUTE FUNCTION update_group_match_participant_timestamp();

-- Comments for documentation
COMMENT ON COLUMN group_match_participants.placement IS 'Final placement in the match (1st, 2nd, 3rd, etc.)';
COMMENT ON COLUMN group_match_participants.kills IS 'Total kills achieved in the match';
COMMENT ON COLUMN group_match_participants.kill_points IS 'Points earned from kills (kills * kill_points_per_kill)';
COMMENT ON COLUMN group_match_participants.placement_points IS 'Points earned from final placement';
COMMENT ON COLUMN group_match_participants.bonus_points IS 'Any additional bonus points awarded';
COMMENT ON COLUMN group_match_participants.total_points IS 'Total points for this match (kill_points + placement_points + bonus_points)';

COMMENT ON COLUMN group_match_teams.placement IS 'Final placement in the match (1st, 2nd, 3rd, etc.)';
COMMENT ON COLUMN group_match_teams.kills IS 'Total kills achieved in the match';
COMMENT ON COLUMN group_match_teams.kill_points IS 'Points earned from kills (kills * kill_points_per_kill)';
COMMENT ON COLUMN group_match_teams.placement_points IS 'Points earned from final placement';
COMMENT ON COLUMN group_match_teams.bonus_points IS 'Any additional bonus points awarded';
COMMENT ON COLUMN group_match_teams.total_points IS 'Total points for this match (kill_points + placement_points + bonus_points)';
