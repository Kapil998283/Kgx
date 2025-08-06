-- ============================================================================
-- TOURNAMENT FORMATS UPDATE SCRIPT - FOR EXISTING DATABASES
-- ============================================================================
-- This script adds support for Group Stage and Weekly Finals tournament formats
-- Run this ONLY if your database already exists
-- ============================================================================

-- Update tournaments table to support new formats
ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS format_type VARCHAR(30) DEFAULT 'Standard' CHECK (format_type IN (
    'Standard',      -- Original elimination/round-robin formats
    'Group Stage',   -- Teams divided into groups, then bracket play  
    'Weekly Finals'  -- Weekly qualification rounds leading to finals
));

-- Add configuration columns for new formats
ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS groups_count INTEGER DEFAULT NULL;
ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS teams_per_group INTEGER DEFAULT NULL;
ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS group_stage_rounds INTEGER DEFAULT 1;
ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS qualifying_teams_per_group INTEGER DEFAULT 2;
ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS weekly_rounds_count INTEGER DEFAULT 4;
ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS weekly_qualifying_teams INTEGER DEFAULT 16;
ALTER TABLE tournaments ADD COLUMN IF NOT EXISTS finals_teams_count INTEGER DEFAULT 16;

-- Add indexes for new columns
CREATE INDEX IF NOT EXISTS idx_tournaments_format_type ON tournaments (format_type);

-- ============================================================================
-- TOURNAMENT GROUPS TABLE
-- ============================================================================
-- Manages tournament groups for Group Stage format
CREATE TABLE IF NOT EXISTS tournament_groups (
    id SERIAL PRIMARY KEY,
    tournament_id INTEGER NOT NULL,
    group_name VARCHAR(50) NOT NULL, -- 'Group A', 'Group B', etc.
    group_number INTEGER NOT NULL,
    max_teams INTEGER DEFAULT 4,
    current_teams INTEGER DEFAULT 0,
    qualifying_spots INTEGER DEFAULT 2, -- How many teams advance from this group
    status VARCHAR(20) DEFAULT 'forming' CHECK (status IN ('forming', 'ready', 'in_progress', 'completed')),
    start_date TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    end_date TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    UNIQUE (tournament_id, group_number),
    UNIQUE (tournament_id, group_name)
);

-- Create indexes for tournament groups
CREATE INDEX IF NOT EXISTS idx_tournament_groups_tournament ON tournament_groups (tournament_id);
CREATE INDEX IF NOT EXISTS idx_tournament_groups_status ON tournament_groups (status);
CREATE INDEX IF NOT EXISTS idx_tournament_groups_number ON tournament_groups (tournament_id, group_number);

-- ============================================================================
-- GROUP PARTICIPANTS TABLE  
-- ============================================================================
-- Links teams/players to their assigned groups
CREATE TABLE IF NOT EXISTS group_participants (
    id SERIAL PRIMARY KEY,
    group_id INTEGER NOT NULL,
    tournament_id INTEGER NOT NULL,
    team_id INTEGER DEFAULT NULL,
    user_id INTEGER DEFAULT NULL, -- For solo tournaments
    position INTEGER DEFAULT NULL, -- Final position within the group
    total_points INTEGER DEFAULT 0,
    total_kills INTEGER DEFAULT 0,
    matches_played INTEGER DEFAULT 0,
    wins INTEGER DEFAULT 0,
    qualified BOOLEAN DEFAULT FALSE,
    qualification_position INTEGER DEFAULT NULL,
    status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'eliminated', 'qualified', 'disqualified')),
    assigned_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES tournament_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CHECK ((team_id IS NOT NULL AND user_id IS NULL) OR (team_id IS NULL AND user_id IS NOT NULL)),
    UNIQUE (group_id, team_id),
    UNIQUE (group_id, user_id)
);

-- Create indexes for group participants
CREATE INDEX IF NOT EXISTS idx_group_participants_group ON group_participants (group_id);
CREATE INDEX IF NOT EXISTS idx_group_participants_tournament ON group_participants (tournament_id);
CREATE INDEX IF NOT EXISTS idx_group_participants_team ON group_participants (team_id);
CREATE INDEX IF NOT EXISTS idx_group_participants_user ON group_participants (user_id);
CREATE INDEX IF NOT EXISTS idx_group_participants_status ON group_participants (status);
CREATE INDEX IF NOT EXISTS idx_group_participants_qualified ON group_participants (qualified);

-- ============================================================================
-- GROUP MATCHES TABLE
-- ============================================================================
-- Stores matches that occur within tournament groups
CREATE TABLE IF NOT EXISTS group_matches (
    id SERIAL PRIMARY KEY,
    tournament_id INTEGER NOT NULL,
    group_id INTEGER NOT NULL,
    round_id INTEGER DEFAULT NULL, -- Links to tournament_rounds if applicable
    match_name VARCHAR(100) NOT NULL,
    match_date TIMESTAMP WITH TIME ZONE NOT NULL,
    status VARCHAR(20) DEFAULT 'scheduled' CHECK (status IN ('scheduled', 'in_progress', 'completed', 'cancelled')),
    
    -- Match settings
    kill_points INTEGER DEFAULT 2,
    placement_points TEXT DEFAULT NULL, -- JSON array: [10,6,5,4,3,2,1,0]
    room_code VARCHAR(50) DEFAULT NULL,
    room_password VARCHAR(50) DEFAULT NULL,
    map_name VARCHAR(100) DEFAULT NULL,
    
    -- Match completion info
    started_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    completed_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES tournament_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (round_id) REFERENCES tournament_rounds(id) ON DELETE SET NULL
);

-- Create indexes for group matches
CREATE INDEX IF NOT EXISTS idx_group_matches_tournament ON group_matches (tournament_id);
CREATE INDEX IF NOT EXISTS idx_group_matches_group ON group_matches (group_id);
CREATE INDEX IF NOT EXISTS idx_group_matches_round ON group_matches (round_id);
CREATE INDEX IF NOT EXISTS idx_group_matches_status ON group_matches (status);
CREATE INDEX IF NOT EXISTS idx_group_matches_date ON group_matches (match_date);

-- ============================================================================
-- GROUP MATCH RESULTS TABLE
-- ============================================================================
-- Stores individual team/player results for group matches
CREATE TABLE IF NOT EXISTS group_match_results (
    id SERIAL PRIMARY KEY,
    match_id INTEGER NOT NULL,
    group_id INTEGER NOT NULL,
    tournament_id INTEGER NOT NULL,
    team_id INTEGER DEFAULT NULL,
    user_id INTEGER DEFAULT NULL,
    
    -- Performance stats
    placement INTEGER DEFAULT NULL,
    kills INTEGER DEFAULT 0,
    kill_points INTEGER DEFAULT 0,
    placement_points INTEGER DEFAULT 0,
    bonus_points INTEGER DEFAULT 0,
    total_points INTEGER DEFAULT 0,
    
    -- Status
    status VARCHAR(20) DEFAULT 'participated' CHECK (status IN ('participated', 'eliminated', 'disqualified', 'winner')),
    
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (match_id) REFERENCES group_matches(id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES tournament_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CHECK ((team_id IS NOT NULL AND user_id IS NULL) OR (team_id IS NULL AND user_id IS NOT NULL)),
    UNIQUE (match_id, team_id),
    UNIQUE (match_id, user_id)
);

-- Create indexes for group match results
CREATE INDEX IF NOT EXISTS idx_group_match_results_match ON group_match_results (match_id);
CREATE INDEX IF NOT EXISTS idx_group_match_results_group ON group_match_results (group_id);
CREATE INDEX IF NOT EXISTS idx_group_match_results_tournament ON group_match_results (tournament_id);
CREATE INDEX IF NOT EXISTS idx_group_match_results_team ON group_match_results (team_id);
CREATE INDEX IF NOT EXISTS idx_group_match_results_user ON group_match_results (user_id);
CREATE INDEX IF NOT EXISTS idx_group_match_results_status ON group_match_results (status);

-- ============================================================================
-- TOURNAMENT PHASES TABLE
-- ============================================================================
-- Manages different phases of complex tournament formats
CREATE TABLE IF NOT EXISTS tournament_phases (
    id SERIAL PRIMARY KEY,
    tournament_id INTEGER NOT NULL,
    phase_name VARCHAR(100) NOT NULL, -- 'Week 1', 'Week 2', 'Group Stage', 'Finals', etc.
    phase_type VARCHAR(50) NOT NULL CHECK (phase_type IN (
        'weekly_qualifier',  -- Weekly qualification rounds
        'group_stage',       -- Group stage phase  
        'bracket_stage',     -- Bracket/elimination phase
        'finals',            -- Final phase
        'grand_finals'       -- Grand finals
    )),
    phase_number INTEGER NOT NULL,
    
    -- Scheduling
    start_date TIMESTAMP WITH TIME ZONE NOT NULL,
    end_date TIMESTAMP WITH TIME ZONE NOT NULL,
    registration_deadline TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    
    -- Capacity and advancement
    max_participants INTEGER DEFAULT NULL,
    current_participants INTEGER DEFAULT 0,
    advancing_participants INTEGER DEFAULT NULL, -- How many advance to next phase
    
    -- Status
    status VARCHAR(20) DEFAULT 'upcoming' CHECK (status IN ('upcoming', 'registration_open', 'registration_closed', 'in_progress', 'completed')),
    
    -- Configuration
    rules TEXT DEFAULT NULL,
    prize_pool DECIMAL(10,2) DEFAULT 0.00,
    
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    UNIQUE (tournament_id, phase_number)
);

-- Create indexes for tournament phases
CREATE INDEX IF NOT EXISTS idx_tournament_phases_tournament ON tournament_phases (tournament_id);
CREATE INDEX IF NOT EXISTS idx_tournament_phases_type ON tournament_phases (phase_type);
CREATE INDEX IF NOT EXISTS idx_tournament_phases_status ON tournament_phases (status);
CREATE INDEX IF NOT EXISTS idx_tournament_phases_dates ON tournament_phases (start_date, end_date);
CREATE INDEX IF NOT EXISTS idx_tournament_phases_number ON tournament_phases (tournament_id, phase_number);

-- ============================================================================
-- PHASE PARTICIPANTS TABLE
-- ============================================================================
-- Tracks which teams/players are in each tournament phase
CREATE TABLE IF NOT EXISTS phase_participants (
    id SERIAL PRIMARY KEY,
    phase_id INTEGER NOT NULL,
    tournament_id INTEGER NOT NULL,
    team_id INTEGER DEFAULT NULL,
    user_id INTEGER DEFAULT NULL,
    
    -- Qualification info
    qualified_from_phase_id INTEGER DEFAULT NULL, -- Which phase they qualified from
    qualification_position INTEGER DEFAULT NULL,
    qualification_points INTEGER DEFAULT 0,
    
    -- Current phase performance
    current_points INTEGER DEFAULT 0,
    current_kills INTEGER DEFAULT 0,
    matches_played INTEGER DEFAULT 0,
    current_position INTEGER DEFAULT NULL,
    
    -- Status
    status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'eliminated', 'qualified', 'advanced', 'disqualified')),
    qualified_to_next BOOLEAN DEFAULT FALSE,
    
    -- Timestamps
    joined_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (phase_id) REFERENCES tournament_phases(id) ON DELETE CASCADE,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (qualified_from_phase_id) REFERENCES tournament_phases(id) ON DELETE SET NULL,
    CHECK ((team_id IS NOT NULL AND user_id IS NULL) OR (team_id IS NULL AND user_id IS NOT NULL)),
    UNIQUE (phase_id, team_id),
    UNIQUE (phase_id, user_id)
);

-- Create indexes for phase participants
CREATE INDEX IF NOT EXISTS idx_phase_participants_phase ON phase_participants (phase_id);
CREATE INDEX IF NOT EXISTS idx_phase_participants_tournament ON phase_participants (tournament_id);
CREATE INDEX IF NOT EXISTS idx_phase_participants_team ON phase_participants (team_id);
CREATE INDEX IF NOT EXISTS idx_phase_participants_user ON phase_participants (user_id);
CREATE INDEX IF NOT EXISTS idx_phase_participants_status ON phase_participants (status);
CREATE INDEX IF NOT EXISTS idx_phase_participants_qualified ON phase_participants (qualified_to_next);
CREATE INDEX IF NOT EXISTS idx_phase_participants_qualified_from ON phase_participants (qualified_from_phase_id);

-- ============================================================================
-- VIEWS FOR GROUP STAGE AND WEEKLY FINALS
-- ============================================================================

-- View for group standings (leaderboards within each group)
CREATE OR REPLACE VIEW group_standings AS
SELECT 
    gp.group_id,
    gp.tournament_id,
    tg.group_name,
    gp.team_id,
    gp.user_id,
    COALESCE(t.name, u.username) as participant_name,
    gp.total_points,
    gp.total_kills,
    gp.matches_played,
    gp.wins,
    gp.position,
    gp.qualified,
    gp.qualification_position,
    gp.status,
    -- Calculate win rate
    CASE 
        WHEN gp.matches_played = 0 THEN 0.00
        ELSE ROUND((gp.wins::DECIMAL / gp.matches_played::DECIMAL) * 100, 2)
    END as win_rate,
    -- Rank within group based on points, then kills
    RANK() OVER (PARTITION BY gp.group_id ORDER BY gp.total_points DESC, gp.total_kills DESC) as group_rank
FROM group_participants gp
JOIN tournament_groups tg ON gp.group_id = tg.id
LEFT JOIN teams t ON gp.team_id = t.id
LEFT JOIN users u ON gp.user_id = u.id
ORDER BY gp.group_id, gp.total_points DESC, gp.total_kills DESC;

-- View for phase standings (leaderboards within each phase)
CREATE OR REPLACE VIEW phase_standings AS
SELECT 
    pp.phase_id,
    pp.tournament_id,
    tp.phase_name,
    tp.phase_type,
    pp.team_id,
    pp.user_id,
    COALESCE(t.name, u.username) as participant_name,
    pp.current_points,
    pp.current_kills,
    pp.matches_played,
    pp.current_position,
    pp.qualified_to_next,
    pp.status,
    -- Rank within phase based on points, then kills
    RANK() OVER (PARTITION BY pp.phase_id ORDER BY pp.current_points DESC, pp.current_kills DESC) as phase_rank
FROM phase_participants pp
JOIN tournament_phases tp ON pp.phase_id = tp.id
LEFT JOIN teams t ON pp.team_id = t.id
LEFT JOIN users u ON pp.user_id = u.id
ORDER BY pp.phase_id, pp.current_points DESC, pp.current_kills DESC;

-- View for tournament format overview
CREATE OR REPLACE VIEW tournament_format_overview AS
SELECT 
    t.id,
    t.name,
    t.game_name,
    t.format_type,
    t.status,
    t.phase,
    
    -- Group Stage specific info
    CASE 
        WHEN t.format_type = 'Group Stage' THEN t.groups_count
        ELSE NULL
    END as groups_count,
    CASE 
        WHEN t.format_type = 'Group Stage' THEN t.teams_per_group
        ELSE NULL
    END as teams_per_group,
    
    -- Weekly Finals specific info
    CASE 
        WHEN t.format_type = 'Weekly Finals' THEN t.weekly_rounds_count
        ELSE NULL
    END as weekly_rounds_count,
    CASE 
        WHEN t.format_type = 'Weekly Finals' THEN t.finals_teams_count
        ELSE NULL
    END as finals_teams_count,
    
    -- Current progress
    COUNT(DISTINCT tg.id) as created_groups,
    COUNT(DISTINCT tp.id) as created_phases,
    
    -- Participant counts
    t.current_teams,
    t.max_teams,
    
    t.created_at,
    t.updated_at
FROM tournaments t
LEFT JOIN tournament_groups tg ON t.id = tg.tournament_id
LEFT JOIN tournament_phases tp ON t.id = tp.tournament_id
GROUP BY t.id;

-- Set views to use SECURITY INVOKER mode
ALTER VIEW group_standings SET (security_invoker = true);
ALTER VIEW phase_standings SET (security_invoker = true);
ALTER VIEW tournament_format_overview SET (security_invoker = true);

-- ============================================================================
-- TRIGGERS AND FUNCTIONS FOR NEW TOURNAMENT FORMATS
-- ============================================================================

-- Function to update group participant count
CREATE OR REPLACE FUNCTION update_group_participant_count()
RETURNS TRIGGER
SET search_path = 'public'
AS $$
BEGIN
    -- Update current_teams count in tournament_groups
    IF TG_OP = 'INSERT' OR TG_OP = 'UPDATE' THEN
        UPDATE tournament_groups 
        SET current_teams = (
            SELECT COUNT(*) 
            FROM group_participants 
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
            FROM group_participants 
            WHERE group_id = OLD.group_id 
            AND status = 'active'
        )
        WHERE id = OLD.group_id;
        RETURN OLD;
    END IF;
    
    RETURN NULL;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Create trigger for group participant count
CREATE TRIGGER update_group_participant_count_trigger
    AFTER INSERT OR UPDATE OR DELETE ON group_participants
    FOR EACH ROW
    EXECUTE FUNCTION update_group_participant_count();

-- Function to update phase participant count
CREATE OR REPLACE FUNCTION update_phase_participant_count()
RETURNS TRIGGER
SET search_path = 'public'
AS $$
BEGIN
    -- Update current_participants count in tournament_phases
    IF TG_OP = 'INSERT' OR TG_OP = 'UPDATE' THEN
        UPDATE tournament_phases 
        SET current_participants = (
            SELECT COUNT(*) 
            FROM phase_participants 
            WHERE phase_id = NEW.phase_id 
            AND status IN ('active', 'qualified', 'advanced')
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
            AND status IN ('active', 'qualified', 'advanced')
        )
        WHERE id = OLD.phase_id;
        RETURN OLD;
    END IF;
    
    RETURN NULL;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Create trigger for phase participant count
CREATE TRIGGER update_phase_participant_count_trigger
    AFTER INSERT OR UPDATE OR DELETE ON phase_participants
    FOR EACH ROW
    EXECUTE FUNCTION update_phase_participant_count();

-- Function to update group participant stats after match results
CREATE OR REPLACE FUNCTION update_group_participant_stats()
RETURNS TRIGGER
SET search_path = 'public'
AS $$
BEGIN
    -- Update group participant totals when match results change
    IF TG_OP = 'INSERT' OR TG_OP = 'UPDATE' THEN
        -- Update for team participant
        IF NEW.team_id IS NOT NULL THEN
            UPDATE group_participants 
            SET 
                total_points = (
                    SELECT COALESCE(SUM(total_points), 0) 
                    FROM group_match_results 
                    WHERE group_id = NEW.group_id AND team_id = NEW.team_id
                ),
                total_kills = (
                    SELECT COALESCE(SUM(kills), 0) 
                    FROM group_match_results 
                    WHERE group_id = NEW.group_id AND team_id = NEW.team_id
                ),
                matches_played = (
                    SELECT COUNT(*) 
                    FROM group_match_results 
                    WHERE group_id = NEW.group_id AND team_id = NEW.team_id
                ),
                wins = (
                    SELECT COUNT(*) 
                    FROM group_match_results 
                    WHERE group_id = NEW.group_id AND team_id = NEW.team_id 
                    AND status = 'winner'
                ),
                updated_at = CURRENT_TIMESTAMP
            WHERE group_id = NEW.group_id AND team_id = NEW.team_id;
        END IF;
        
        -- Update for user participant  
        IF NEW.user_id IS NOT NULL THEN
            UPDATE group_participants 
            SET 
                total_points = (
                    SELECT COALESCE(SUM(total_points), 0) 
                    FROM group_match_results 
                    WHERE group_id = NEW.group_id AND user_id = NEW.user_id
                ),
                total_kills = (
                    SELECT COALESCE(SUM(kills), 0) 
                    FROM group_match_results 
                    WHERE group_id = NEW.group_id AND user_id = NEW.user_id
                ),
                matches_played = (
                    SELECT COUNT(*) 
                    FROM group_match_results 
                    WHERE group_id = NEW.group_id AND user_id = NEW.user_id
                ),
                wins = (
                    SELECT COUNT(*) 
                    FROM group_match_results 
                    WHERE group_id = NEW.group_id AND user_id = NEW.user_id 
                    AND status = 'winner'
                ),
                updated_at = CURRENT_TIMESTAMP
            WHERE group_id = NEW.group_id AND user_id = NEW.user_id;
        END IF;
        
        RETURN NEW;
    END IF;
    
    -- Handle deletions
    IF TG_OP = 'DELETE' THEN
        IF OLD.team_id IS NOT NULL THEN
            UPDATE group_participants 
            SET 
                total_points = (
                    SELECT COALESCE(SUM(total_points), 0) 
                    FROM group_match_results 
                    WHERE group_id = OLD.group_id AND team_id = OLD.team_id
                ),
                total_kills = (
                    SELECT COALESCE(SUM(kills), 0) 
                    FROM group_match_results 
                    WHERE group_id = OLD.group_id AND team_id = OLD.team_id
                ),
                matches_played = (
                    SELECT COUNT(*) 
                    FROM group_match_results 
                    WHERE group_id = OLD.group_id AND team_id = OLD.team_id
                ),
                wins = (
                    SELECT COUNT(*) 
                    FROM group_match_results 
                    WHERE group_id = OLD.group_id AND team_id = OLD.team_id 
                    AND status = 'winner'
                ),
                updated_at = CURRENT_TIMESTAMP
            WHERE group_id = OLD.group_id AND team_id = OLD.team_id;
        END IF;
        
        IF OLD.user_id IS NOT NULL THEN
            UPDATE group_participants 
            SET 
                total_points = (
                    SELECT COALESCE(SUM(total_points), 0) 
                    FROM group_match_results 
                    WHERE group_id = OLD.group_id AND user_id = OLD.user_id
                ),
                total_kills = (
                    SELECT COALESCE(SUM(kills), 0) 
                    FROM group_match_results 
                    WHERE group_id = OLD.group_id AND user_id = OLD.user_id
                ),
                matches_played = (
                    SELECT COUNT(*) 
                    FROM group_match_results 
                    WHERE group_id = OLD.group_id AND user_id = OLD.user_id
                ),
                wins = (
                    SELECT COUNT(*) 
                    FROM group_match_results 
                    WHERE group_id = OLD.group_id AND user_id = OLD.user_id 
                    AND status = 'winner'
                ),
                updated_at = CURRENT_TIMESTAMP
            WHERE group_id = OLD.group_id AND user_id = OLD.user_id;
        END IF;
        
        RETURN OLD;
    END IF;
    
    RETURN NULL;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Create trigger for group participant stats
CREATE TRIGGER update_group_participant_stats_trigger
    AFTER INSERT OR UPDATE OR DELETE ON group_match_results
    FOR EACH ROW
    EXECUTE FUNCTION update_group_participant_stats();

-- ============================================================================
-- HELPER FUNCTIONS FOR NEW TOURNAMENT FORMATS
-- ============================================================================

-- Function to create groups for a Group Stage tournament
CREATE OR REPLACE FUNCTION create_tournament_groups(
    tournament_id_param INTEGER,
    groups_count_param INTEGER,
    teams_per_group_param INTEGER,
    qualifying_spots_param INTEGER DEFAULT 2
)
RETURNS INTEGER AS $$
DECLARE
    i INTEGER;
    group_id INTEGER;
    groups_created INTEGER := 0;
BEGIN
    -- Create the specified number of groups
    FOR i IN 1..groups_count_param LOOP
        INSERT INTO tournament_groups (
            tournament_id,
            group_name,
            group_number,
            max_teams,
            qualifying_spots
        ) VALUES (
            tournament_id_param,
            'Group ' || chr(64 + i), -- 'Group A', 'Group B', etc.
            i,
            teams_per_group_param,
            qualifying_spots_param
        ) RETURNING id INTO group_id;
        
        groups_created := groups_created + 1;
    END LOOP;
    
    RETURN groups_created;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Function to assign teams to groups (round-robin distribution)
CREATE OR REPLACE FUNCTION assign_teams_to_groups(tournament_id_param INTEGER)
RETURNS INTEGER AS $$
DECLARE
    team_record RECORD;
    group_record RECORD;
    current_group INTEGER := 1;
    total_groups INTEGER;
    assignments_made INTEGER := 0;
BEGIN
    -- Get total number of groups for this tournament
    SELECT COUNT(*) INTO total_groups
    FROM tournament_groups
    WHERE tournament_id = tournament_id_param;
    
    IF total_groups = 0 THEN
        RETURN 0;
    END IF;
    
    -- Get all registered teams for this tournament
    FOR team_record IN (
        SELECT DISTINCT tr.team_id, tr.user_id
        FROM tournament_registrations tr
        WHERE tr.tournament_id = tournament_id_param
        AND tr.status = 'approved'
        ORDER BY tr.registration_date
    ) LOOP
        -- Get the group to assign to (round-robin style)
        SELECT id INTO group_record
        FROM tournament_groups
        WHERE tournament_id = tournament_id_param
        AND group_number = current_group
        LIMIT 1;
        
        -- Assign team/user to this group
        INSERT INTO group_participants (
            group_id,
            tournament_id,
            team_id,
            user_id
        ) VALUES (
            group_record.id,
            tournament_id_param,
            team_record.team_id,
            team_record.user_id
        ) ON CONFLICT DO NOTHING;
        
        assignments_made := assignments_made + 1;
        
        -- Move to next group (round-robin)
        current_group := current_group + 1;
        IF current_group > total_groups THEN
            current_group := 1;
        END IF;
    END LOOP;
    
    RETURN assignments_made;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Function to create weekly phases for Weekly Finals tournament
CREATE OR REPLACE FUNCTION create_weekly_phases(
    tournament_id_param INTEGER,
    weeks_count INTEGER,
    start_date TIMESTAMP WITH TIME ZONE,
    qualifying_teams_per_week INTEGER
)
RETURNS INTEGER AS $$
DECLARE
    i INTEGER;
    phase_start TIMESTAMP WITH TIME ZONE;
    phase_end TIMESTAMP WITH TIME ZONE;
    phases_created INTEGER := 0;
BEGIN
    -- Create weekly qualification phases
    FOR i IN 1..weeks_count LOOP
        phase_start := start_date + ((i - 1) * INTERVAL '7 days');
        phase_end := phase_start + INTERVAL '6 days 23 hours 59 minutes';
        
        INSERT INTO tournament_phases (
            tournament_id,
            phase_name,
            phase_type,
            phase_number,
            start_date,
            end_date,
            advancing_participants
        ) VALUES (
            tournament_id_param,
            'Week ' || i,
            'weekly_qualifier',
            i,
            phase_start,
            phase_end,
            qualifying_teams_per_week
        );
        
        phases_created := phases_created + 1;
    END LOOP;
    
    -- Create finals phase
    INSERT INTO tournament_phases (
        tournament_id,
        phase_name,
        phase_type,
        phase_number,
        start_date,
        end_date,
        max_participants
    ) VALUES (
        tournament_id_param,
        'Finals',
        'finals',
        weeks_count + 1,
        start_date + (weeks_count * INTERVAL '7 days'),
        start_date + (weeks_count * INTERVAL '7 days') + INTERVAL '2 days',
        qualifying_teams_per_week * weeks_count
    );
    
    phases_created := phases_created + 1;
    
    RETURN phases_created;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Function to advance qualified teams from group stage to next phase
CREATE OR REPLACE FUNCTION advance_qualified_teams_from_groups(tournament_id_param INTEGER)
RETURNS INTEGER AS $$
DECLARE
    qualified_teams INTEGER := 0;
    group_record RECORD;
    qualified_record RECORD;
BEGIN
    -- For each group, mark top teams as qualified
    FOR group_record IN (
        SELECT id, qualifying_spots
        FROM tournament_groups
        WHERE tournament_id = tournament_id_param
    ) LOOP
        -- Get top teams from this group based on total points, then kills
        FOR qualified_record IN (
            SELECT team_id, user_id
            FROM group_participants
            WHERE group_id = group_record.id
            AND status = 'active'
            ORDER BY total_points DESC, total_kills DESC
            LIMIT group_record.qualifying_spots
        ) LOOP
            -- Mark as qualified
            UPDATE group_participants
            SET 
                qualified = true,
                qualification_position = (
                    SELECT COUNT(*) + 1
                    FROM group_participants gp2
                    WHERE gp2.group_id = group_record.id
                    AND gp2.qualified = true
                ),
                status = 'qualified'
            WHERE group_id = group_record.id
            AND ((team_id = qualified_record.team_id AND qualified_record.team_id IS NOT NULL)
                OR (user_id = qualified_record.user_id AND qualified_record.user_id IS NOT NULL));
            
            qualified_teams := qualified_teams + 1;
        END LOOP;
    END LOOP;
    
    RETURN qualified_teams;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Function to advance top teams from weekly phases to finals
CREATE OR REPLACE FUNCTION advance_teams_from_weekly_phases(tournament_id_param INTEGER)
RETURNS INTEGER AS $$
DECLARE
    advanced_teams INTEGER := 0;
    phase_record RECORD;
    qualified_record RECORD;
    finals_phase_id INTEGER;
BEGIN
    -- Get the finals phase
    SELECT id INTO finals_phase_id
    FROM tournament_phases
    WHERE tournament_id = tournament_id_param
    AND phase_type = 'finals'
    LIMIT 1;
    
    IF finals_phase_id IS NULL THEN
        RETURN 0;
    END IF;
    
    -- For each weekly qualifier phase, advance top teams
    FOR phase_record IN (
        SELECT id, advancing_participants
        FROM tournament_phases
        WHERE tournament_id = tournament_id_param
        AND phase_type = 'weekly_qualifier'
        AND status = 'completed'
    ) LOOP
        -- Get top teams from this phase
        FOR qualified_record IN (
            SELECT team_id, user_id, current_points, current_kills
            FROM phase_participants
            WHERE phase_id = phase_record.id
            AND status = 'active'
            ORDER BY current_points DESC, current_kills DESC
            LIMIT phase_record.advancing_participants
        ) LOOP
            -- Add to finals phase
            INSERT INTO phase_participants (
                phase_id,
                tournament_id,
                team_id,
                user_id,
                qualified_from_phase_id,
                qualification_points
            ) VALUES (
                finals_phase_id,
                tournament_id_param,
                qualified_record.team_id,
                qualified_record.user_id,
                phase_record.id,
                qualified_record.current_points
            ) ON CONFLICT DO NOTHING;
            
            -- Mark as advanced in original phase
            UPDATE phase_participants
            SET 
                qualified_to_next = true,
                status = 'advanced'
            WHERE phase_id = phase_record.id
            AND ((team_id = qualified_record.team_id AND qualified_record.team_id IS NOT NULL)
                OR (user_id = qualified_record.user_id AND qualified_record.user_id IS NOT NULL));
            
            advanced_teams := advanced_teams + 1;
        END LOOP;
    END LOOP;
    
    RETURN advanced_teams;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Create triggers for updated_at columns on new tables
CREATE TRIGGER update_tournament_groups_updated_at 
    BEFORE UPDATE ON tournament_groups 
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
    
CREATE TRIGGER update_group_participants_updated_at 
    BEFORE UPDATE ON group_participants 
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
    
CREATE TRIGGER update_group_matches_updated_at 
    BEFORE UPDATE ON group_matches 
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
    
CREATE TRIGGER update_group_match_results_updated_at 
    BEFORE UPDATE ON group_match_results 
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
    
CREATE TRIGGER update_tournament_phases_updated_at 
    BEFORE UPDATE ON tournament_phases 
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
    
CREATE TRIGGER update_phase_participants_updated_at 
    BEFORE UPDATE ON phase_participants 
    FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();

-- ============================================================================
-- COMMENTS AND DOCUMENTATION FOR NEW TABLES
-- ============================================================================

-- Add comments to new tables for documentation
COMMENT ON TABLE tournament_groups IS 'Stores tournament groups for Group Stage format tournaments';
COMMENT ON COLUMN tournament_groups.group_name IS 'Human readable group name like "Group A", "Group B"';
COMMENT ON COLUMN tournament_groups.qualifying_spots IS 'Number of teams that advance from this group to next stage';

COMMENT ON TABLE group_participants IS 'Links teams/players to tournament groups and tracks their group-specific performance';
COMMENT ON COLUMN group_participants.qualified IS 'Whether this participant qualified to advance from the group';
COMMENT ON COLUMN group_participants.qualification_position IS 'Final ranking position within the group';

COMMENT ON TABLE group_matches IS 'Stores matches that occur within tournament groups';
COMMENT ON COLUMN group_matches.placement_points IS 'JSON array defining points for each placement: [10,6,5,4,3,2,1,0]';

COMMENT ON TABLE group_match_results IS 'Individual team/player results for group matches';

COMMENT ON TABLE tournament_phases IS 'Manages different phases of complex tournament formats like Weekly Finals';
COMMENT ON COLUMN tournament_phases.phase_type IS 'Type of phase: weekly_qualifier, group_stage, bracket_stage, finals, grand_finals';
COMMENT ON COLUMN tournament_phases.advancing_participants IS 'How many participants advance from this phase to the next';

COMMENT ON TABLE phase_participants IS 'Tracks which teams/players participate in each tournament phase';
COMMENT ON COLUMN phase_participants.qualified_from_phase_id IS 'Which phase this participant qualified from (for tracking progression)';

-- ============================================================================
-- UPDATE COMPLETE
-- ============================================================================

-- Success message
SELECT 'Tournament Formats Extension Applied Successfully!' as status,
       'Your database now supports Group Stage and Weekly Finals tournaments' as message;
