-- ============================================================================
-- GAME MAPS TABLE
-- ============================================================================
-- Table to store maps for different games
CREATE TABLE IF NOT EXISTS game_maps (
    id SERIAL PRIMARY KEY,
    game_name VARCHAR(50) NOT NULL CHECK (game_name IN ('BGMI', 'PUBG', 'FREE FIRE', 'COD')),
    map_name VARCHAR(100) NOT NULL,
    map_display_name VARCHAR(100) NOT NULL,
    map_size VARCHAR(20) DEFAULT 'Large' CHECK (map_size IN ('Small', 'Medium', 'Large')),
    map_type VARCHAR(30) DEFAULT 'Battle Royale' CHECK (map_type IN ('Battle Royale', 'Team Deathmatch', 'Domination', 'Search and Destroy', 'Hardpoint')),
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(game_name, map_name)
);

-- Create indexes for game maps
CREATE INDEX IF NOT EXISTS idx_game_maps_game ON game_maps (game_name);
CREATE INDEX IF NOT EXISTS idx_game_maps_active ON game_maps (is_active);
CREATE INDEX IF NOT EXISTS idx_game_maps_type ON game_maps (map_type);

-- Insert default maps for each game
-- PUBG Maps
INSERT INTO game_maps (game_name, map_name, map_display_name, map_size, map_type) VALUES
('PUBG', 'erangel', 'Erangel', 'Large', 'Battle Royale'),
('PUBG', 'miramar', 'Miramar', 'Large', 'Battle Royale'),
('PUBG', 'sanhok', 'Sanhok', 'Medium', 'Battle Royale'),
('PUBG', 'vikendi', 'Vikendi', 'Medium', 'Battle Royale'),
('PUBG', 'karakin', 'Karakin', 'Small', 'Battle Royale'),
('PUBG', 'paramo', 'Paramo', 'Small', 'Battle Royale'),
('PUBG', 'haven', 'Haven', 'Small', 'Battle Royale'),
('PUBG', 'taego', 'Taego', 'Large', 'Battle Royale'),
('PUBG', 'deston', 'Deston', 'Large', 'Battle Royale'),
('PUBG', 'rondo', 'Rondo', 'Large', 'Battle Royale'),

-- BGMI Maps
('BGMI', 'erangel', 'Erangel', 'Large', 'Battle Royale'),
('BGMI', 'miramar', 'Miramar', 'Large', 'Battle Royale'),
('BGMI', 'sanhok', 'Sanhok', 'Medium', 'Battle Royale'),
('BGMI', 'vikendi', 'Vikendi', 'Medium', 'Battle Royale'),
('BGMI', 'karakin', 'Karakin', 'Small', 'Battle Royale'),
('BGMI', 'livik', 'Livik', 'Small', 'Battle Royale'),
('BGMI', 'nusa', 'Nusa', 'Small', 'Battle Royale'),

-- FREE FIRE Maps
('FREE FIRE', 'bermuda', 'Bermuda', 'Large', 'Battle Royale'),
('FREE FIRE', 'purgatory', 'Purgatory', 'Large', 'Battle Royale'),
('FREE FIRE', 'kalahari', 'Kalahari', 'Large', 'Battle Royale'),
('FREE FIRE', 'alpine', 'Alpine', 'Large', 'Battle Royale'),
('FREE FIRE', 'nextera', 'Nextera', 'Large', 'Battle Royale'),
('FREE FIRE', 'clash_squad', 'Clash Squad', 'Small', 'Team Deathmatch'),
('FREE FIRE', 'peak', 'Peak', 'Small', 'Team Deathmatch'),
('FREE FIRE', 'mars_electric', 'Mars Electric', 'Small', 'Team Deathmatch'),

-- COD Maps
('COD', 'blackout', 'Blackout', 'Large', 'Battle Royale'),
('COD', 'alcatraz', 'Alcatraz', 'Small', 'Battle Royale'),
('COD', 'nuketown', 'Nuketown', 'Small', 'Team Deathmatch'),
('COD', 'crash', 'Crash', 'Small', 'Team Deathmatch'),
('COD', 'crossfire', 'Crossfire', 'Medium', 'Team Deathmatch'),
('COD', 'firing_range', 'Firing Range', 'Small', 'Team Deathmatch'),
('COD', 'summit', 'Summit', 'Small', 'Team Deathmatch'),
('COD', 'standoff', 'Standoff', 'Medium', 'Team Deathmatch'),
('COD', 'raid', 'Raid', 'Medium', 'Search and Destroy'),
('COD', 'terminal', 'Terminal', 'Medium', 'Domination')
ON CONFLICT (game_name, map_name) DO NOTHING;
