-- ============================================================================
-- SCREENSHOT MANAGEMENT TABLES FOR MATCH VERIFICATION
-- ============================================================================
-- Run this SQL after your main schema to add screenshot functionality
-- This enables users to upload match result screenshots for admin verification

-- Match screenshots table (for standalone matches)
CREATE TABLE IF NOT EXISTS match_screenshots (
    id SERIAL PRIMARY KEY,
    match_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    image_path TEXT NOT NULL,
    image_url TEXT NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_size INTEGER DEFAULT 0,
    upload_type VARCHAR(20) DEFAULT 'result' CHECK (upload_type IN ('result', 'kills', 'rank', 'final')),
    description TEXT DEFAULT NULL,
    verified BOOLEAN DEFAULT FALSE,
    verified_by INTEGER DEFAULT NULL,
    verified_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    kills_claimed INTEGER DEFAULT 0,
    rank_claimed INTEGER DEFAULT NULL,
    admin_notes TEXT DEFAULT NULL,
    uploaded_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES admin_users(id) ON DELETE SET NULL
);

-- Tournament round screenshots table (for tournament rounds)
CREATE TABLE IF NOT EXISTS tournament_round_screenshots (
    id SERIAL PRIMARY KEY,
    tournament_id INTEGER NOT NULL,
    round_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    team_id INTEGER DEFAULT NULL,
    image_path TEXT NOT NULL,
    image_url TEXT NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_size INTEGER DEFAULT 0,
    upload_type VARCHAR(20) DEFAULT 'result' CHECK (upload_type IN ('result', 'kills', 'placement', 'points', 'final')),
    description TEXT DEFAULT NULL,
    verified BOOLEAN DEFAULT FALSE,
    verified_by INTEGER DEFAULT NULL,
    verified_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    kills_claimed INTEGER DEFAULT 0,
    placement_claimed INTEGER DEFAULT NULL,
    points_claimed INTEGER DEFAULT 0,
    admin_notes TEXT DEFAULT NULL,
    uploaded_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (round_id) REFERENCES tournament_rounds(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
    FOREIGN KEY (verified_by) REFERENCES admin_users(id) ON DELETE SET NULL
);

-- Create indexes for match screenshots
CREATE INDEX IF NOT EXISTS idx_match_screenshots_match ON match_screenshots (match_id);
CREATE INDEX IF NOT EXISTS idx_match_screenshots_user ON match_screenshots (user_id);
CREATE INDEX IF NOT EXISTS idx_match_screenshots_verified ON match_screenshots (verified);
CREATE INDEX IF NOT EXISTS idx_match_screenshots_type ON match_screenshots (upload_type);

-- Create indexes for tournament round screenshots
CREATE INDEX IF NOT EXISTS idx_tournament_screenshots_tournament ON tournament_round_screenshots (tournament_id);
CREATE INDEX IF NOT EXISTS idx_tournament_screenshots_round ON tournament_round_screenshots (round_id);
CREATE INDEX IF NOT EXISTS idx_tournament_screenshots_user ON tournament_round_screenshots (user_id);
CREATE INDEX IF NOT EXISTS idx_tournament_screenshots_team ON tournament_round_screenshots (team_id);
CREATE INDEX IF NOT EXISTS idx_tournament_screenshots_verified ON tournament_round_screenshots (verified);
CREATE INDEX IF NOT EXISTS idx_tournament_screenshots_type ON tournament_round_screenshots (upload_type);

-- Add comments to the tables for documentation
COMMENT ON TABLE match_screenshots IS 'Stores screenshots uploaded by users as proof of match results for standalone matches';
COMMENT ON COLUMN match_screenshots.upload_type IS 'Type of screenshot: result (final result), kills (kill count), rank (final rank), final (overall result)';
COMMENT ON COLUMN match_screenshots.verified IS 'Whether admin has verified this screenshot';
COMMENT ON COLUMN match_screenshots.kills_claimed IS 'Number of kills user claims in this screenshot';
COMMENT ON COLUMN match_screenshots.rank_claimed IS 'Final rank/position user claims in this screenshot';

COMMENT ON TABLE tournament_round_screenshots IS 'Stores screenshots uploaded by users as proof of tournament round results';
COMMENT ON COLUMN tournament_round_screenshots.upload_type IS 'Type of screenshot: result, kills, placement, points, final';
COMMENT ON COLUMN tournament_round_screenshots.verified IS 'Whether admin has verified this screenshot';
COMMENT ON COLUMN tournament_round_screenshots.kills_claimed IS 'Number of kills user claims in this round';
COMMENT ON COLUMN tournament_round_screenshots.placement_claimed IS 'Final placement user claims in this round';
COMMENT ON COLUMN tournament_round_screenshots.points_claimed IS 'Total points user claims for this round';

-- ============================================================================
-- STORAGE CLEANUP FUNCTION (Optional - for managing 1GB storage limit)
-- ============================================================================

-- Function to clean up screenshots after match completion
CREATE OR REPLACE FUNCTION cleanup_match_screenshots(match_id_param INTEGER)
RETURNS INTEGER AS $$
DECLARE
    deleted_count INTEGER := 0;
BEGIN
    -- Get count of screenshots to be deleted
    SELECT COUNT(*) INTO deleted_count
    FROM match_screenshots
    WHERE match_id = match_id_param;
    
    -- Delete screenshot records from database
    -- Note: Actual file deletion from Supabase Storage needs to be handled in PHP
    DELETE FROM match_screenshots
    WHERE match_id = match_id_param;
    
    RETURN deleted_count;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Function to clean up tournament screenshots after tournament completion
CREATE OR REPLACE FUNCTION cleanup_tournament_screenshots(tournament_id_param INTEGER)
RETURNS INTEGER AS $$
DECLARE
    deleted_count INTEGER := 0;
BEGIN
    -- Get count of screenshots to be deleted
    SELECT COUNT(*) INTO deleted_count
    FROM tournament_round_screenshots
    WHERE tournament_id = tournament_id_param;
    
    -- Delete screenshot records from database
    -- Note: Actual file deletion from Supabase Storage needs to be handled in PHP
    DELETE FROM tournament_round_screenshots
    WHERE tournament_id = tournament_id_param;
    
    RETURN deleted_count;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- ============================================================================
-- DEVELOPMENT NOTES
-- ============================================================================
/*
For development setup:
1. Create 'match-screenshots' bucket in Supabase Dashboard (private bucket)
2. No storage policies needed for development
3. Implement cleanup functions in PHP to delete actual files from storage
4. Users cannot delete their own screenshots - only admins can manage cleanup
5. Auto-cleanup after match/tournament completion to manage 1GB storage limit
*/
