-- ============================================================================
-- GROUP STAGE TOURNAMENT TABLES UPDATE
-- ============================================================================
-- This file adds the missing tables and fixes constraints for Group Stage tournaments
-- Run this in your Supabase SQL Editor after the main schema

-- ============================================================================
-- 1. FIX group_matches STATUS CONSTRAINT
-- ============================================================================
-- Drop the old constraint and add new one with correct status values

ALTER TABLE group_matches 
DROP CONSTRAINT IF EXISTS group_matches_status_check;

ALTER TABLE group_matches 
ADD CONSTRAINT group_matches_status_check 
CHECK (status IN ('scheduled', 'upcoming', 'live', 'in_progress', 'completed', 'cancelled'));

-- ============================================================================
-- 2. CREATE group_match_participants TABLE (for Solo tournaments)
-- ============================================================================
-- Tracks individual participants in group matches for solo tournaments

CREATE TABLE IF NOT EXISTS group_match_participants (
    id SERIAL PRIMARY KEY,
    match_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    status VARCHAR(20) DEFAULT 'registered' CHECK (status IN ('registered', 'participated', 'disqualified', 'no_show')),
    joined_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    
    -- Foreign key constraints
    FOREIGN KEY (match_id) REFERENCES group_matches(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    -- Ensure one user per match
    UNIQUE (match_id, user_id)
);

-- Create indexes for group match participants
CREATE INDEX IF NOT EXISTS idx_group_match_participants_match ON group_match_participants (match_id);
CREATE INDEX IF NOT EXISTS idx_group_match_participants_user ON group_match_participants (user_id);
CREATE INDEX IF NOT EXISTS idx_group_match_participants_status ON group_match_participants (status);

-- ============================================================================
-- 3. CREATE group_match_teams TABLE (for Team tournaments)
-- ============================================================================
-- Tracks team participants in group matches for team tournaments

CREATE TABLE IF NOT EXISTS group_match_teams (
    id SERIAL PRIMARY KEY,
    match_id INTEGER NOT NULL,
    team_id INTEGER NOT NULL,
    status VARCHAR(20) DEFAULT 'registered' CHECK (status IN ('registered', 'participated', 'disqualified', 'no_show')),
    joined_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    
    -- Foreign key constraints
    FOREIGN KEY (match_id) REFERENCES group_matches(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    
    -- Ensure one team per match
    UNIQUE (match_id, team_id)
);

-- Create indexes for group match teams
CREATE INDEX IF NOT EXISTS idx_group_match_teams_match ON group_match_teams (match_id);
CREATE INDEX IF NOT EXISTS idx_group_match_teams_team ON group_match_teams (team_id);
CREATE INDEX IF NOT EXISTS idx_group_match_teams_status ON group_match_teams (status);

-- ============================================================================
-- 4. ADD COMMENTS FOR DOCUMENTATION
-- ============================================================================

-- Add table comments
COMMENT ON TABLE group_matches IS 'Stores group stage matches within tournaments - each record represents one match/game session';
COMMENT ON TABLE group_match_participants IS 'Tracks individual user participation in group matches (for Solo tournaments)';
COMMENT ON TABLE group_match_teams IS 'Tracks team participation in group matches (for Squad/Team tournaments)';

-- Add column comments for clarity
COMMENT ON COLUMN group_matches.status IS 'Match status: scheduled, upcoming, live, in_progress, completed, cancelled';
COMMENT ON COLUMN group_matches.match_name IS 'Human-readable match name like "Group A - Match 1"';
COMMENT ON COLUMN group_matches.kill_points IS 'Points awarded per kill in this match';
COMMENT ON COLUMN group_matches.placement_points IS 'JSON array of placement points: [15,12,10,8,6,4,2,1]';

COMMENT ON COLUMN group_match_participants.status IS 'Participant status: registered, participated, disqualified, no_show';
COMMENT ON COLUMN group_match_teams.status IS 'Team status: registered, participated, disqualified, no_show';

-- ============================================================================
-- 5. VERIFICATION QUERIES
-- ============================================================================
-- Run these queries to verify everything was created correctly

-- Check if constraint was updated
SELECT 
    conname as constraint_name,
    conrelid::regclass as table_name,
    pg_get_constraintdef(oid) as constraint_definition
FROM pg_constraint 
WHERE conname = 'group_matches_status_check';

-- Check if new tables exist
SELECT 
    table_name,
    table_type
FROM information_schema.tables 
WHERE table_schema = 'public' 
AND table_name IN ('group_match_participants', 'group_match_teams')
ORDER BY table_name;

-- Check table relationships
SELECT 
    tc.table_name,
    kcu.column_name,
    ccu.table_name AS foreign_table_name,
    ccu.column_name AS foreign_column_name
FROM information_schema.table_constraints AS tc
JOIN information_schema.key_column_usage AS kcu
    ON tc.constraint_name = kcu.constraint_name
JOIN information_schema.constraint_column_usage AS ccu
    ON ccu.constraint_name = tc.constraint_name
WHERE tc.constraint_type = 'FOREIGN KEY'
AND tc.table_name IN ('group_match_participants', 'group_match_teams')
ORDER BY tc.table_name, kcu.column_name;

-- ============================================================================
-- 6. SUCCESS MESSAGE
-- ============================================================================

DO $$
BEGIN
    RAISE NOTICE 'Group Stage tournament tables have been successfully created!';
    RAISE NOTICE 'Tables added:';
    RAISE NOTICE '  - group_match_participants (for Solo tournaments)';
    RAISE NOTICE '  - group_match_teams (for Team tournaments)'; 
    RAISE NOTICE 'Constraint fixed:';
    RAISE NOTICE '  - group_matches.status now supports: scheduled, upcoming, live, in_progress, completed, cancelled';
    RAISE NOTICE '';
    RAISE NOTICE 'You can now create Group Stage tournament matches successfully!';
END $$;
