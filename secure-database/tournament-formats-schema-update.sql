-- ============================================================================
-- TOURNAMENT FORMATS SCHEMA UPDATE
-- KGX Esports Platform - Group Stage & Weekly Finals Implementation
-- ============================================================================

-- Add new format options to existing tournaments table
ALTER TABLE tournaments 
    DROP CONSTRAINT IF EXISTS tournaments_format_check,
    ALTER COLUMN format TYPE VARCHAR(30),
    ADD CONSTRAINT tournaments_format_check CHECK (format IN (
        'Elimination', 
        'Group Stage', 
        'Weekly Finals', 
        'Custom Lobby'
    ));

-- Add format-specific configuration column
ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS format_config JSONB DEFAULT '{}';

-- Add phase support for weekly finals
ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS current_phase_id INTEGER DEFAULT NULL;

-- ============================================================================
-- GROUP STAGE FORMAT TABLES
-- ============================================================================

-- Tournament Groups Table
CREATE TABLE IF NOT EXISTS tournament_groups (
    id SERIAL PRIMARY KEY,
    tournament_id INTEGER NOT NULL,
    group_name VARCHAR(50) NOT NULL, -- 'Group A', 'Group B', etc.
    group_type VARCHAR(20) DEFAULT 'qualification' CHECK (group_type IN ('qualification', 'finals')),
    max_teams INTEGER DEFAULT 20,
    current_teams INTEGER DEFAULT 0,
    total_matches INTEGER DEFAULT 6,
    completed_matches INTEGER DEFAULT 0,
    advancement_slots INTEGER DEFAULT 0, -- How many teams advance
    status VARCHAR(20) DEFAULT 'upcoming' CHECK (status IN ('upcoming', 'active', 'completed')),
    start_date TIMESTAMP WITH TIME ZONE,
    end_date TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    UNIQUE (tournament_id, group_name)
);

-- Group Teams Assignment
CREATE TABLE IF NOT EXISTS group_teams (
    id SERIAL PRIMARY KEY,
    group_id INTEGER NOT NULL,
    team_id INTEGER DEFAULT NULL,
    user_id INTEGER DEFAULT NULL, -- For solo tournaments
    assignment_date TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    seeding_position INTEGER DEFAULT NULL,
    status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'eliminated', 'qualified')),
    total_points INTEGER DEFAULT 0,
    total_kills INTEGER DEFAULT 0,
    matches_played INTEGER DEFAULT 0,
    best_placement INTEGER DEFAULT NULL,
    chicken_dinners INTEGER DEFAULT 0,
    FOREIGN KEY (group_id) REFERENCES tournament_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CHECK ((team_id IS NOT NULL AND user_id IS NULL) OR (team_id IS NULL AND user_id IS NOT NULL)),
    UNIQUE (group_id, team_id),
    UNIQUE (group_id, user_id)
);

-- Group Matches
CREATE TABLE IF NOT EXISTS group_matches (
    id SERIAL PRIMARY KEY,
    group_id INTEGER NOT NULL,
    match_number INTEGER NOT NULL,
    round_id INTEGER NOT NULL, -- Links to existing tournament_rounds
    match_name VARCHAR(100) DEFAULT NULL, -- 'Group A - Match 1'
    scheduled_time TIMESTAMP WITH TIME ZONE,
    map_name VARCHAR(100) DEFAULT NULL,
    room_code VARCHAR(50) DEFAULT NULL,
    room_password VARCHAR(50) DEFAULT NULL,
    status VARCHAR(20) DEFAULT 'scheduled' CHECK (status IN ('scheduled', 'active', 'completed', 'cancelled')),
    results_submitted BOOLEAN DEFAULT FALSE,
    results_verified BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES tournament_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (round_id) REFERENCES tournament_rounds(id) ON DELETE CASCADE,
    UNIQUE (group_id, match_number)
);

-- Group Match Results
CREATE TABLE IF NOT EXISTS group_match_results (
    id SERIAL PRIMARY KEY,
    group_match_id INTEGER NOT NULL,
    team_id INTEGER DEFAULT NULL,
    user_id INTEGER DEFAULT NULL,
    final_placement INTEGER NOT NULL CHECK (final_placement >= 1), -- 1st, 2nd, 3rd place, etc.
    kills INTEGER DEFAULT 0 CHECK (kills >= 0),
    kill_points INTEGER DEFAULT 0,
    placement_points INTEGER DEFAULT 0,
    bonus_points INTEGER DEFAULT 0,
    total_points INTEGER DEFAULT 0,
    chicken_dinner BOOLEAN DEFAULT FALSE,
    screenshot_url TEXT DEFAULT NULL,
    verified BOOLEAN DEFAULT FALSE,
    verified_by INTEGER DEFAULT NULL,
    verified_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_match_id) REFERENCES group_matches(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (verified_by) REFERENCES admin_users(id) ON DELETE SET NULL,
    CHECK ((team_id IS NOT NULL AND user_id IS NULL) OR (team_id IS NULL AND user_id IS NOT NULL)),
    UNIQUE (group_match_id, team_id),
    UNIQUE (group_match_id, user_id)
);

-- ============================================================================
-- WEEKLY FINALS FORMAT TABLES
-- ============================================================================

-- Tournament Phases Table
CREATE TABLE IF NOT EXISTS tournament_phases (
    id SERIAL PRIMARY KEY,
    tournament_id INTEGER NOT NULL,
    phase_number INTEGER NOT NULL,
    phase_name VARCHAR(100) NOT NULL, -- 'Wildcard Week', 'Week 1', 'Grand Finals'
    phase_type VARCHAR(30) DEFAULT 'elimination' CHECK (phase_type IN ('elimination', 'qualification', 'finals')),
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    max_participants INTEGER NOT NULL,
    current_participants INTEGER DEFAULT 0,
    advancement_slots INTEGER NOT NULL, -- How many advance to next phase
    elimination_slots INTEGER DEFAULT 0, -- How many get eliminated
    status VARCHAR(20) DEFAULT 'upcoming' CHECK (status IN ('upcoming', 'active', 'completed')),
    format_config JSONB DEFAULT '{}', -- Store format-specific configuration
    scoring_config JSONB DEFAULT '{}', -- Store scoring configuration
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    UNIQUE (tournament_id, phase_number)
);

-- Phase Participants
CREATE TABLE IF NOT EXISTS phase_participants (
    id SERIAL PRIMARY KEY,
    phase_id INTEGER NOT NULL,
    team_id INTEGER DEFAULT NULL,
    user_id INTEGER DEFAULT NULL,
    qualification_source VARCHAR(50) DEFAULT NULL, -- 'registration', 'previous_phase', 'wildcard'
    seeding_rank INTEGER DEFAULT NULL,
    entry_points INTEGER DEFAULT 0, -- Points carried from previous phase
    status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'eliminated', 'qualified')),
    final_rank INTEGER DEFAULT NULL,
    total_points INTEGER DEFAULT 0,
    total_kills INTEGER DEFAULT 0,
    matches_played INTEGER DEFAULT 0,
    qualification_date TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    elimination_date TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (phase_id) REFERENCES tournament_phases(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CHECK ((team_id IS NOT NULL AND user_id IS NULL) OR (team_id IS NULL AND user_id IS NOT NULL)),
    UNIQUE (phase_id, team_id),
    UNIQUE (phase_id, user_id)
);

-- Phase Groups (for phases that use group format)
CREATE TABLE IF NOT EXISTS phase_groups (
    id SERIAL PRIMARY KEY,
    phase_id INTEGER NOT NULL,
    group_name VARCHAR(50) NOT NULL, -- 'Phase 1 - Group A'
    max_participants INTEGER DEFAULT 20,
    current_participants INTEGER DEFAULT 0,
    total_matches INTEGER DEFAULT 6,
    completed_matches INTEGER DEFAULT 0,
    advancement_slots INTEGER DEFAULT 5,
    status VARCHAR(20) DEFAULT 'upcoming' CHECK (status IN ('upcoming', 'active', 'completed')),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (phase_id) REFERENCES tournament_phases(id) ON DELETE CASCADE,
    UNIQUE (phase_id, group_name)
);

-- ============================================================================
-- VIEWS FOR STANDINGS AND STATISTICS
-- ============================================================================

-- Group Standings View
CREATE OR REPLACE VIEW group_standings AS
SELECT 
    gt.group_id,
    gt.id as group_team_id,
    gt.team_id,
    gt.user_id,
    COALESCE(t.name, u.username) as participant_name,
    COALESCE(t.logo, u.profile_image) as participant_logo,
    tg.group_name,
    COUNT(gmr.id) as matches_played,
    SUM(gmr.kills) as total_kills,
    SUM(gmr.total_points) as total_points,
    ROUND(AVG(gmr.final_placement::DECIMAL), 2) as avg_placement,
    MIN(gmr.final_placement) as best_placement,
    SUM(CASE WHEN gmr.chicken_dinner = TRUE THEN 1 ELSE 0 END) as chicken_dinners,
    RANK() OVER (PARTITION BY gt.group_id ORDER BY SUM(gmr.total_points) DESC, SUM(gmr.kills) DESC, MIN(gmr.final_placement) ASC) as group_rank,
    tg.advancement_slots,
    CASE 
        WHEN RANK() OVER (PARTITION BY gt.group_id ORDER BY SUM(gmr.total_points) DESC, SUM(gmr.kills) DESC, MIN(gmr.final_placement) ASC) <= tg.advancement_slots 
        THEN 'qualified' 
        ELSE 'eliminated' 
    END as advancement_status
FROM group_teams gt
JOIN tournament_groups tg ON gt.group_id = tg.id
LEFT JOIN group_match_results gmr ON (
    (gt.team_id IS NOT NULL AND gmr.team_id = gt.team_id) OR 
    (gt.user_id IS NOT NULL AND gmr.user_id = gt.user_id)
)
LEFT JOIN group_matches gm ON gmr.group_match_id = gm.id AND gm.group_id = gt.group_id
LEFT JOIN teams t ON gt.team_id = t.id
LEFT JOIN users u ON gt.user_id = u.id
GROUP BY gt.group_id, gt.id, gt.team_id, gt.user_id, t.name, u.username, t.logo, u.profile_image, tg.group_name, tg.advancement_slots;

-- Phase Standings View
CREATE OR REPLACE VIEW phase_standings AS
SELECT 
    pp.phase_id,
    pp.id as phase_participant_id,
    pp.team_id,
    pp.user_id,
    COALESCE(t.name, u.username) as participant_name,
    COALESCE(t.logo, u.profile_image) as participant_logo,
    tp.phase_name,
    pp.total_points,
    pp.total_kills,
    pp.matches_played,
    pp.seeding_rank,
    pp.status,
    pp.final_rank,
    RANK() OVER (PARTITION BY pp.phase_id ORDER BY pp.total_points DESC, pp.total_kills DESC) as current_rank,
    tp.advancement_slots,
    CASE 
        WHEN pp.status = 'qualified' THEN 'qualified'
        WHEN pp.status = 'eliminated' THEN 'eliminated'
        WHEN RANK() OVER (PARTITION BY pp.phase_id ORDER BY pp.total_points DESC, pp.total_kills DESC) <= tp.advancement_slots 
        THEN 'advancing' 
        ELSE 'at_risk' 
    END as advancement_status
FROM phase_participants pp
JOIN tournament_phases tp ON pp.phase_id = tp.id
LEFT JOIN teams t ON pp.team_id = t.id
LEFT JOIN users u ON pp.user_id = u.id;

-- Tournament Overview View
CREATE OR REPLACE VIEW tournament_format_overview AS
SELECT 
    t.id,
    t.name,
    t.format,
    t.status,
    t.phase,
    t.current_teams,
    t.max_teams,
    CASE 
        WHEN t.format = 'Group Stage' THEN (
            SELECT COUNT(*) FROM tournament_groups WHERE tournament_id = t.id
        )
        WHEN t.format = 'Weekly Finals' THEN (
            SELECT COUNT(*) FROM tournament_phases WHERE tournament_id = t.id
        )
        ELSE 0
    END as format_stages,
    CASE 
        WHEN t.format = 'Group Stage' THEN (
            SELECT COUNT(*) FROM tournament_groups 
            WHERE tournament_id = t.id AND status = 'completed'
        )
        WHEN t.format = 'Weekly Finals' THEN (
            SELECT COUNT(*) FROM tournament_phases 
            WHERE tournament_id = t.id AND status = 'completed'
        )
        ELSE 0
    END as completed_stages,
    t.format_config
FROM tournaments t;

-- ============================================================================
-- INDEXES FOR PERFORMANCE
-- ============================================================================

-- Group Stage Indexes
CREATE INDEX IF NOT EXISTS idx_tournament_groups_tournament ON tournament_groups (tournament_id);
CREATE INDEX IF NOT EXISTS idx_tournament_groups_status ON tournament_groups (status);
CREATE INDEX IF NOT EXISTS idx_group_teams_group ON group_teams (group_id);
CREATE INDEX IF NOT EXISTS idx_group_teams_team ON group_teams (team_id) WHERE team_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_group_teams_user ON group_teams (user_id) WHERE user_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_group_teams_status ON group_teams (status);
CREATE INDEX IF NOT EXISTS idx_group_matches_group ON group_matches (group_id);
CREATE INDEX IF NOT EXISTS idx_group_matches_round ON group_matches (round_id);
CREATE INDEX IF NOT EXISTS idx_group_matches_status ON group_matches (status);
CREATE INDEX IF NOT EXISTS idx_group_match_results_match ON group_match_results (group_match_id);
CREATE INDEX IF NOT EXISTS idx_group_match_results_team ON group_match_results (team_id) WHERE team_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_group_match_results_user ON group_match_results (user_id) WHERE user_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_group_match_results_verified ON group_match_results (verified);

-- Weekly Finals Indexes
CREATE INDEX IF NOT EXISTS idx_tournament_phases_tournament ON tournament_phases (tournament_id);
CREATE INDEX IF NOT EXISTS idx_tournament_phases_status ON tournament_phases (status);
CREATE INDEX IF NOT EXISTS idx_tournament_phases_number ON tournament_phases (tournament_id, phase_number);
CREATE INDEX IF NOT EXISTS idx_phase_participants_phase ON phase_participants (phase_id);
CREATE INDEX IF NOT EXISTS idx_phase_participants_team ON phase_participants (team_id) WHERE team_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_phase_participants_user ON phase_participants (user_id) WHERE user_id IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_phase_participants_status ON phase_participants (status);
CREATE INDEX IF NOT EXISTS idx_phase_groups_phase ON phase_groups (phase_id);

-- ============================================================================
-- TRIGGERS FOR AUTO-UPDATES
-- ============================================================================

-- Update group team counts
CREATE OR REPLACE FUNCTION update_group_team_count()
RETURNS TRIGGER
SET search_path = 'public'
AS $$
BEGIN
    IF TG_OP = 'INSERT' OR TG_OP = 'UPDATE' THEN
        UPDATE tournament_groups 
        SET current_teams = (
            SELECT COUNT(*) 
            FROM group_teams 
            WHERE group_id = NEW.group_id 
            AND status = 'active'
        )
        WHERE id = NEW.group_id;
        RETURN NEW;
    END IF;
    
    IF TG_OP = 'DELETE' THEN
        UPDATE tournament_groups 
        SET current_teams = (
            SELECT COUNT(*) 
            FROM group_teams 
            WHERE group_id = OLD.group_id 
            AND status = 'active'
        )
        WHERE id = OLD.group_id;
        RETURN OLD;
    END IF;
    
    RETURN NULL;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Create trigger for group team count
DROP TRIGGER IF EXISTS update_group_team_count_trigger ON group_teams;
CREATE TRIGGER update_group_team_count_trigger
    AFTER INSERT OR UPDATE OR DELETE ON group_teams
    FOR EACH ROW
    EXECUTE FUNCTION update_group_team_count();

-- Update phase participant counts
CREATE OR REPLACE FUNCTION update_phase_participant_count()
RETURNS TRIGGER
SET search_path = 'public'
AS $$
BEGIN
    IF TG_OP = 'INSERT' OR TG_OP = 'UPDATE' THEN
        UPDATE tournament_phases 
        SET current_participants = (
            SELECT COUNT(*) 
            FROM phase_participants 
            WHERE phase_id = NEW.phase_id 
            AND status = 'active'
        )
        WHERE id = NEW.phase_id;
        RETURN NEW;
    END IF;
    
    IF TG_OP = 'DELETE' THEN
        UPDATE tournament_phases 
        SET current_participants = (
            SELECT COUNT(*) 
            FROM phase_participants 
            WHERE phase_id = OLD.phase_id 
            AND status = 'active'
        )
        WHERE id = OLD.phase_id;
        RETURN OLD;
    END IF;
    
    RETURN NULL;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Create trigger for phase participant count
DROP TRIGGER IF EXISTS update_phase_participant_count_trigger ON phase_participants;
CREATE TRIGGER update_phase_participant_count_trigger
    AFTER INSERT OR UPDATE OR DELETE ON phase_participants
    FOR EACH ROW
    EXECUTE FUNCTION update_phase_participant_count();

-- Update group match completion
CREATE OR REPLACE FUNCTION update_group_match_completion()
RETURNS TRIGGER
SET search_path = 'public'
AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.status = 'completed' AND OLD.status != 'completed' THEN
        UPDATE tournament_groups 
        SET completed_matches = (
            SELECT COUNT(*) 
            FROM group_matches 
            WHERE group_id = NEW.group_id 
            AND status = 'completed'
        )
        WHERE id = NEW.group_id;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Create trigger for group match completion
DROP TRIGGER IF EXISTS update_group_match_completion_trigger ON group_matches;
CREATE TRIGGER update_group_match_completion_trigger
    AFTER UPDATE ON group_matches
    FOR EACH ROW
    EXECUTE FUNCTION update_group_match_completion();

-- ============================================================================
-- HELPER FUNCTIONS
-- ============================================================================

-- Function to get group standings for a specific group
CREATE OR REPLACE FUNCTION get_group_standings(group_id_param INTEGER)
RETURNS TABLE (
    participant_name TEXT,
    participant_logo TEXT,
    matches_played BIGINT,
    total_kills BIGINT,
    total_points BIGINT,
    avg_placement NUMERIC,
    best_placement INTEGER,
    chicken_dinners BIGINT,
    group_rank BIGINT,
    advancement_status TEXT
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        gs.participant_name,
        gs.participant_logo,
        gs.matches_played,
        gs.total_kills,
        gs.total_points,
        gs.avg_placement,
        gs.best_placement,
        gs.chicken_dinners,
        gs.group_rank,
        gs.advancement_status
    FROM group_standings gs
    WHERE gs.group_id = group_id_param
    ORDER BY gs.group_rank;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Function to advance qualified teams from group to finals
CREATE OR REPLACE FUNCTION advance_qualified_teams(tournament_id_param INTEGER)
RETURNS INTEGER AS $$
DECLARE
    advanced_count INTEGER := 0;
    group_record RECORD;
    qualified_record RECORD;
BEGIN
    -- Loop through all groups in the tournament
    FOR group_record IN 
        SELECT id FROM tournament_groups 
        WHERE tournament_id = tournament_id_param 
        AND group_type = 'qualification'
        AND status = 'completed'
    LOOP
        -- Get qualified teams from this group
        FOR qualified_record IN
            SELECT team_id, user_id, total_points, total_kills
            FROM group_standings
            WHERE group_id = group_record.id 
            AND advancement_status = 'qualified'
        LOOP
            -- Update group_teams status
            UPDATE group_teams 
            SET status = 'qualified'
            WHERE group_id = group_record.id 
            AND (
                (qualified_record.team_id IS NOT NULL AND team_id = qualified_record.team_id) OR
                (qualified_record.user_id IS NOT NULL AND user_id = qualified_record.user_id)
            );
            
            advanced_count := advanced_count + 1;
        END LOOP;
    END LOOP;
    
    RETURN advanced_count;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Function to calculate and update group team statistics
CREATE OR REPLACE FUNCTION update_group_team_stats(group_id_param INTEGER)
RETURNS INTEGER AS $$
DECLARE
    updated_count INTEGER := 0;
    team_record RECORD;
BEGIN
    FOR team_record IN 
        SELECT gt.id, gt.team_id, gt.user_id,
               COALESCE(SUM(gmr.total_points), 0) as points,
               COALESCE(SUM(gmr.kills), 0) as kills,
               COUNT(gmr.id) as matches,
               MIN(gmr.final_placement) as best_place,
               SUM(CASE WHEN gmr.chicken_dinner THEN 1 ELSE 0 END) as dinners
        FROM group_teams gt
        LEFT JOIN group_match_results gmr ON (
            (gt.team_id IS NOT NULL AND gmr.team_id = gt.team_id) OR
            (gt.user_id IS NOT NULL AND gmr.user_id = gt.user_id)
        )
        LEFT JOIN group_matches gm ON gmr.group_match_id = gm.id
        WHERE gt.group_id = group_id_param
        AND gm.group_id = group_id_param
        GROUP BY gt.id, gt.team_id, gt.user_id
    LOOP
        UPDATE group_teams 
        SET 
            total_points = team_record.points,
            total_kills = team_record.kills,
            matches_played = team_record.matches,
            best_placement = team_record.best_place,
            chicken_dinners = team_record.dinners
        WHERE id = team_record.id;
        
        updated_count := updated_count + 1;
    END LOOP;
    
    RETURN updated_count;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Add foreign key constraint for current_phase_id after creating tournament_phases
ALTER TABLE tournaments ADD CONSTRAINT fk_current_phase 
    FOREIGN KEY (current_phase_id) REFERENCES tournament_phases(id) ON DELETE SET NULL;

-- Add triggers for updated_at columns on new tables
CREATE TRIGGER update_tournament_groups_updated_at 
    BEFORE UPDATE ON tournament_groups 
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_group_teams_updated_at 
    BEFORE UPDATE ON group_teams 
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_group_matches_updated_at 
    BEFORE UPDATE ON group_matches 
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_tournament_phases_updated_at 
    BEFORE UPDATE ON tournament_phases 
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_phase_participants_updated_at 
    BEFORE UPDATE ON phase_participants 
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

CREATE TRIGGER update_phase_groups_updated_at 
    BEFORE UPDATE ON phase_groups 
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- ============================================================================
-- COMMENTS FOR DOCUMENTATION
-- ============================================================================

COMMENT ON TABLE tournament_groups IS 'Groups within tournaments for group stage format';
COMMENT ON TABLE group_teams IS 'Assignment of teams/players to tournament groups';
COMMENT ON TABLE group_matches IS 'Individual matches within tournament groups';
COMMENT ON TABLE group_match_results IS 'Results of team/player performance in group matches';
COMMENT ON TABLE tournament_phases IS 'Phases for weekly finals format tournaments';
COMMENT ON TABLE phase_participants IS 'Participants in each phase of weekly finals';
COMMENT ON TABLE phase_groups IS 'Groups within phases for complex phase formats';

COMMENT ON VIEW group_standings IS 'Real-time standings for tournament groups with advancement status';
COMMENT ON VIEW phase_standings IS 'Real-time standings for tournament phases with qualification status';
COMMENT ON VIEW tournament_format_overview IS 'Overview of tournament progress across all formats';
