-- KGX Esports PostgreSQL Schema for Supabase
-- Converted from MySQL to PostgreSQL format

-- Enable necessary extensions
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- Admin users table
CREATE TABLE IF NOT EXISTS admin_users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'admin' CHECK (role IN ('super_admin', 'admin', 'moderator')),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    last_login TIMESTAMP WITH TIME ZONE NULL,
    is_active BOOLEAN DEFAULT TRUE
);

-- Admin activity log table
CREATE TABLE IF NOT EXISTS admin_activity_log (
    id SERIAL PRIMARY KEY,
    admin_id INTEGER NOT NULL,
    action VARCHAR(255) NOT NULL,
    details TEXT,
    ip_address INET,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES admin_users(id)
);

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100),
    profile_image TEXT DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    role VARCHAR(20) DEFAULT 'user' CHECK (role IN ('user', 'admin', 'organizer')),
    ticket_balance INTEGER DEFAULT 0,
    phone VARCHAR(20) DEFAULT NULL
);

-- Create indices for users table
CREATE INDEX IF NOT EXISTS idx_username ON users (username);
CREATE INDEX IF NOT EXISTS idx_email ON users (email);
CREATE INDEX IF NOT EXISTS idx_phone ON users (phone);

-- Shop orders table
CREATE TABLE IF NOT EXISTS shop_orders (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    order_id VARCHAR(50) NOT NULL UNIQUE,
    amount DECIMAL(10,2) NOT NULL,
    coins INTEGER NOT NULL DEFAULT 0,
    tickets INTEGER NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','completed','failed')),
    payment_id VARCHAR(100) DEFAULT NULL,
    payment_signature VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Create indices for shop_orders
CREATE INDEX IF NOT EXISTS idx_shop_orders_user_id ON shop_orders (user_id);
CREATE INDEX IF NOT EXISTS idx_shop_orders_status ON shop_orders (status);

-- Profile images table
CREATE TABLE IF NOT EXISTS profile_images (
    id SERIAL PRIMARY KEY,
    image_path TEXT NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    is_default BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_profile_images_is_default ON profile_images (is_default);

-- Team banners table
CREATE TABLE IF NOT EXISTS team_banners (
    id SERIAL PRIMARY KEY,
    image_path VARCHAR(255) NOT NULL,
    name VARCHAR(50) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_team_banners_is_active ON team_banners (is_active);

-- Teams table
CREATE TABLE IF NOT EXISTS teams (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    logo VARCHAR(255),
    banner_id INTEGER DEFAULT 1,
    description TEXT,
    language VARCHAR(50),
    max_members INTEGER DEFAULT 5,
    current_members INTEGER DEFAULT 1,
    is_active BOOLEAN DEFAULT TRUE,
    is_private BOOLEAN DEFAULT FALSE,
    preferred_game VARCHAR(50),
    skill_level VARCHAR(20) DEFAULT 'beginner' CHECK (skill_level IN ('beginner', 'intermediate', 'advanced', 'pro')),
    region VARCHAR(50),
    wins INTEGER DEFAULT 0,
    losses INTEGER DEFAULT 0,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    captain_id INTEGER,
    total_score BIGINT DEFAULT 0,
    FOREIGN KEY (captain_id) REFERENCES users(id),
    FOREIGN KEY (banner_id) REFERENCES team_banners(id)
);

CREATE INDEX IF NOT EXISTS idx_teams_is_active ON teams (is_active);
CREATE INDEX IF NOT EXISTS idx_teams_captain ON teams (captain_id);
CREATE INDEX IF NOT EXISTS idx_teams_name ON teams (name);

-- Team members table
CREATE TABLE IF NOT EXISTS team_members (
    id SERIAL PRIMARY KEY,
    team_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    role VARCHAR(20) DEFAULT 'member' CHECK (role IN ('captain', 'member')),
    status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'pending', 'rejected')),
    joined_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (team_id, user_id),
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_team_members_status ON team_members (status);
CREATE INDEX IF NOT EXISTS idx_team_members_role ON team_members (role);

-- Team join requests table
CREATE TABLE IF NOT EXISTS team_join_requests (
    id SERIAL PRIMARY KEY,
    team_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (team_id, user_id, status),
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_join_requests_status ON team_join_requests (status);

-- Games table
CREATE TABLE IF NOT EXISTS games (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) NOT NULL CHECK (name IN ('BGMI', 'PUBG', 'FREE FIRE', 'COD')),
    image_url TEXT DEFAULT NULL,
    description TEXT,
    status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'inactive')),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_game_status ON games (status);
CREATE INDEX IF NOT EXISTS idx_game_name ON games (name);

-- Tournaments table
CREATE TABLE IF NOT EXISTS tournaments (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    game_name VARCHAR(50) NOT NULL CHECK (game_name IN ('BGMI', 'PUBG', 'FREE FIRE', 'COD')),
    description TEXT,
    banner_image TEXT DEFAULT NULL,
    prize_pool DECIMAL(10,2) DEFAULT 0.00,
    prize_currency VARCHAR(10) DEFAULT 'USD' CHECK (prize_currency IN ('USD', 'INR')),
    entry_fee INTEGER DEFAULT 0,
    max_teams INTEGER DEFAULT 100,
    current_teams INTEGER DEFAULT 0,
    mode VARCHAR(20) DEFAULT 'Squad' CHECK (mode IN ('Solo', 'Duo', 'Squad', 'Team')),
    format VARCHAR(30) DEFAULT 'Elimination' CHECK (format IN ('Elimination', 'Group Stage', 'Weekly Finals', 'Custom Lobby')),
    match_type VARCHAR(20) DEFAULT 'Single' CHECK (match_type IN ('Single', 'Best of 3', 'Best of 5')),
    registration_open_date DATE NOT NULL,
    registration_close_date DATE NOT NULL,
    playing_start_date DATE NOT NULL,
    finish_date DATE NOT NULL,
    payment_date DATE DEFAULT NULL,
    status VARCHAR(30) DEFAULT 'draft' CHECK (status IN (
        'draft', 'announced', 'registration_open', 'registration_closed',
        'in_progress', 'completed', 'archived', 'cancelled'
    )),
    phase VARCHAR(30) DEFAULT 'pre_registration' CHECK (phase IN (
        'pre_registration', 'registration', 'pre_tournament', 'playing',
        'post_tournament', 'payment', 'finished'
    )),
    rules TEXT,
    created_by INTEGER,
    allow_waitlist BOOLEAN DEFAULT FALSE,
    waitlist_limit INTEGER DEFAULT 10,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Tournament days table
CREATE TABLE IF NOT EXISTS tournament_days (
    id SERIAL PRIMARY KEY,
    tournament_id INTEGER NOT NULL,
    day_number INTEGER NOT NULL,
    date DATE NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'upcoming' CHECK (status IN ('upcoming', 'in_progress', 'completed')),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE
);

-- Tournament rounds table
CREATE TABLE IF NOT EXISTS tournament_rounds (
    id SERIAL PRIMARY KEY,
    tournament_id INTEGER NOT NULL,
    day_id INTEGER DEFAULT NULL,
    round_number INTEGER NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    start_time TIMESTAMP WITH TIME ZONE NOT NULL,
    teams_count INTEGER NOT NULL DEFAULT 0,
    qualifying_teams INTEGER NOT NULL DEFAULT 0,
    round_format VARCHAR(20) NOT NULL DEFAULT 'points' CHECK (round_format IN ('elimination', 'points', 'bracket')),
    map_name VARCHAR(255),
    special_rules TEXT,
    kill_points INTEGER NOT NULL DEFAULT 2,
    placement_points TEXT,
    qualification_points INTEGER NOT NULL DEFAULT 10,
    status VARCHAR(20) NOT NULL DEFAULT 'upcoming' CHECK (status IN ('upcoming', 'in_progress', 'completed')),
    room_code VARCHAR(50) DEFAULT NULL,
    room_password VARCHAR(50) DEFAULT NULL,
    room_details_added_at TIMESTAMP WITH TIME ZONE NULL DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (day_id) REFERENCES tournament_days(id) ON DELETE SET NULL
);

-- Round teams table
CREATE TABLE IF NOT EXISTS round_teams (
    id SERIAL PRIMARY KEY,
    round_id INTEGER NOT NULL,
    team_id INTEGER NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'selected' CHECK (status IN ('selected', 'eliminated', 'qualified')),
    placement INTEGER DEFAULT NULL,
    kills INTEGER DEFAULT 0,
    kill_points INTEGER DEFAULT 0,
    placement_points INTEGER DEFAULT 0,
    bonus_points INTEGER DEFAULT 0,
    total_points INTEGER DEFAULT 0,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (round_id, team_id),
    FOREIGN KEY (round_id) REFERENCES tournament_rounds(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
);

-- Solo tournament participants table (for individual players in solo tournaments)
CREATE TABLE IF NOT EXISTS solo_tournament_participants (
    id SERIAL PRIMARY KEY,
    tournament_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    round_id INTEGER NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'selected' CHECK (status IN ('selected', 'eliminated', 'qualified')),
    placement INTEGER DEFAULT NULL,
    kills INTEGER DEFAULT 0,
    kill_points INTEGER DEFAULT 0,
    placement_points INTEGER DEFAULT 0,
    bonus_points INTEGER DEFAULT 0,
    total_points INTEGER DEFAULT 0,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (round_id, user_id),
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (round_id) REFERENCES tournament_rounds(id) ON DELETE CASCADE
);

-- Create indexes for solo tournament participants
CREATE INDEX IF NOT EXISTS idx_solo_participants_tournament ON solo_tournament_participants (tournament_id);
CREATE INDEX IF NOT EXISTS idx_solo_participants_user ON solo_tournament_participants (user_id);
CREATE INDEX IF NOT EXISTS idx_solo_participants_round ON solo_tournament_participants (round_id);
CREATE INDEX IF NOT EXISTS idx_solo_participants_status ON solo_tournament_participants (status);

-- Tournament registrations table
CREATE TABLE IF NOT EXISTS tournament_registrations (
    id SERIAL PRIMARY KEY,
    tournament_id INTEGER NOT NULL,
    team_id INTEGER NULL,
    user_id INTEGER NULL,
    registration_date TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'approved', 'rejected')),
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CHECK ((team_id IS NOT NULL AND user_id IS NULL) OR (team_id IS NULL AND user_id IS NOT NULL))
);

CREATE INDEX IF NOT EXISTS idx_tournament_team ON tournament_registrations (tournament_id, team_id);
CREATE INDEX IF NOT EXISTS idx_tournament_user ON tournament_registrations (tournament_id, user_id);
CREATE INDEX IF NOT EXISTS idx_tournament_reg_status ON tournament_registrations (status);

-- Tournament winners table
CREATE TABLE IF NOT EXISTS tournament_winners (
    id SERIAL PRIMARY KEY,
    tournament_id INTEGER NOT NULL,
    team_id INTEGER NOT NULL,
    position INTEGER NOT NULL,
    prize_amount DECIMAL(10,2) DEFAULT 0.00,
    payment_status VARCHAR(20) DEFAULT 'pending' CHECK (payment_status IN ('pending', 'paid')),
    UNIQUE (tournament_id, position),
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
);

-- Tournament player history table
CREATE TABLE IF NOT EXISTS tournament_player_history (
    id SERIAL PRIMARY KEY,
    tournament_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    team_id INTEGER DEFAULT NULL, -- Allow NULL for solo players
    registration_date TIMESTAMP WITH TIME ZONE NOT NULL,
    rounds_played INTEGER DEFAULT 0,
    total_kills INTEGER DEFAULT 0,
    total_points INTEGER DEFAULT 0,
    best_placement INTEGER DEFAULT NULL,
    final_position INTEGER DEFAULT NULL,
    prize_amount DECIMAL(10,2) DEFAULT 0.00,
    prize_currency VARCHAR(20) DEFAULT NULL,
    website_currency_earned INTEGER DEFAULT 0,
    website_currency_type VARCHAR(20) DEFAULT NULL,
    status VARCHAR(20) DEFAULT 'registered' CHECK (status IN ('registered', 'playing', 'completed', 'eliminated')),
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
    UNIQUE (tournament_id, user_id)
);

-- Tournament waitlist table
CREATE TABLE IF NOT EXISTS tournament_waitlist (
    id SERIAL PRIMARY KEY,
    tournament_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    join_date TIMESTAMP WITH TIME ZONE NOT NULL,
    status VARCHAR(20) DEFAULT 'waiting' CHECK (status IN ('waiting', 'promoted', 'expired')),
    UNIQUE (tournament_id, user_id),
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Video categories table
CREATE TABLE IF NOT EXISTS video_categories (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_video_categories_active ON video_categories (is_active);

-- Live streams table
CREATE TABLE IF NOT EXISTS live_streams (
    id SERIAL PRIMARY KEY,
    tournament_id INTEGER DEFAULT NULL,
    round_id INTEGER DEFAULT NULL,
    stream_title VARCHAR(255) NOT NULL,
    stream_link TEXT NOT NULL,
    streamer_name VARCHAR(100) NOT NULL,
    start_time TIMESTAMP WITH TIME ZONE NOT NULL,
    end_time TIMESTAMP WITH TIME ZONE NOT NULL,
    status VARCHAR(20) DEFAULT 'scheduled' CHECK (status IN ('scheduled', 'live', 'completed', 'cancelled')),
    video_type VARCHAR(20) DEFAULT 'tournament' CHECK (video_type IN ('tournament', 'earning')),
    category_id INTEGER DEFAULT NULL,
    coin_reward INTEGER DEFAULT 50,
    minimum_watch_duration INTEGER DEFAULT 300,
    thumbnail_url TEXT DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE SET NULL,
    FOREIGN KEY (round_id) REFERENCES tournament_rounds(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_live_streams_video_type ON live_streams (video_type);
CREATE INDEX IF NOT EXISTS idx_live_streams_status ON live_streams (status);

-- Stream rewards table
CREATE TABLE IF NOT EXISTS stream_rewards (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    stream_id INTEGER NOT NULL,
    coins_earned INTEGER NOT NULL,
    watch_duration INTEGER NOT NULL,
    claimed_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, stream_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (stream_id) REFERENCES live_streams(id) ON DELETE CASCADE
);

-- Video watch history table
CREATE TABLE IF NOT EXISTS video_watch_history (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    video_id INTEGER NOT NULL,
    watch_duration INTEGER NOT NULL,
    watched_at TIMESTAMP WITH TIME ZONE NOT NULL,
    coins_earned INTEGER DEFAULT 0,
    UNIQUE (user_id, video_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (video_id) REFERENCES live_streams(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_video_watch_user_watches ON video_watch_history (user_id);
CREATE INDEX IF NOT EXISTS idx_video_watch_video_watches ON video_watch_history (video_id);

-- Matches table
CREATE TABLE IF NOT EXISTS matches (
    id SERIAL PRIMARY KEY,
    game_id INTEGER NOT NULL,
    tournament_id INTEGER,
    team1_id INTEGER,
    team2_id INTEGER,
    match_type VARCHAR(50) NOT NULL,
    match_date TIMESTAMP WITH TIME ZONE NOT NULL,
    entry_type VARCHAR(20) NOT NULL,
    entry_fee INTEGER DEFAULT 0,
    prize_type VARCHAR(20) DEFAULT 'INR',
    prize_pool DECIMAL(10,2) DEFAULT 0,
    prize_distribution VARCHAR(20) DEFAULT 'single',
    website_currency_type VARCHAR(20) DEFAULT NULL,
    website_currency_amount INTEGER DEFAULT 0,
    coins_per_kill INTEGER DEFAULT 0,
    max_participants INTEGER NOT NULL,
    map_name VARCHAR(50),
    room_code VARCHAR(50),
    room_password VARCHAR(50),
    status VARCHAR(20) DEFAULT 'upcoming',
    score_team1 INTEGER DEFAULT 0,
    score_team2 INTEGER DEFAULT 0,
    winner_id INTEGER,
    winner_user_id INTEGER,
    started_at TIMESTAMP WITH TIME ZONE,
    completed_at TIMESTAMP WITH TIME ZONE,
    cancelled_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    cancellation_reason VARCHAR(255) DEFAULT NULL,
    room_details_added_at TIMESTAMP WITH TIME ZONE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (game_id) REFERENCES games(id),
    FOREIGN KEY (tournament_id) REFERENCES tournaments(id),
    FOREIGN KEY (team1_id) REFERENCES teams(id),
    FOREIGN KEY (team2_id) REFERENCES teams(id),
    FOREIGN KEY (winner_id) REFERENCES teams(id),
    FOREIGN KEY (winner_user_id) REFERENCES users(id)
);

-- Match results table
CREATE TABLE IF NOT EXISTS match_results (
    match_id INTEGER,
    team_id INTEGER,
    score INTEGER DEFAULT NULL,
    prize_amount DECIMAL(10,2) DEFAULT NULL,
    prize_currency VARCHAR(20) DEFAULT NULL,
    PRIMARY KEY (match_id, team_id),
    FOREIGN KEY (match_id) REFERENCES matches(id),
    FOREIGN KEY (team_id) REFERENCES teams(id)
);

-- User coins table
CREATE TABLE IF NOT EXISTS user_coins (
    user_id INTEGER PRIMARY KEY,
    coins INTEGER DEFAULT 0,
    last_updated TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- User tickets table
CREATE TABLE IF NOT EXISTS user_tickets (
    user_id INTEGER PRIMARY KEY,
    tickets INTEGER DEFAULT 0,
    last_updated TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Transactions table
CREATE TABLE IF NOT EXISTS transactions (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    type VARCHAR(20) NOT NULL CHECK (type IN ('refund', 'purchase', 'reward', 'withdrawal')),
    description TEXT NOT NULL,
    currency_type VARCHAR(20) NOT NULL CHECK (currency_type IN ('coins', 'tickets', 'INR', 'USD')),
    status VARCHAR(20) NOT NULL DEFAULT 'completed' CHECK (status IN ('pending', 'completed', 'failed')),
    created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_user_transactions ON transactions (user_id);
CREATE INDEX IF NOT EXISTS idx_transaction_type ON transactions (type);
CREATE INDEX IF NOT EXISTS idx_transaction_status ON transactions (status);

-- Notifications table
CREATE TABLE IF NOT EXISTS notifications (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(50) DEFAULT 'general',
    related_id INTEGER DEFAULT NULL,
    related_type VARCHAR(50) DEFAULT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP WITH TIME ZONE NULL DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_notifications_user_id ON notifications (user_id);
CREATE INDEX IF NOT EXISTS idx_notifications_deleted_at ON notifications (deleted_at);
CREATE INDEX IF NOT EXISTS idx_notifications_is_read ON notifications (is_read);
CREATE INDEX IF NOT EXISTS idx_notifications_related ON notifications (related_id, related_type);

-- Hero settings table
CREATE TABLE IF NOT EXISTS hero_settings (
    id SERIAL PRIMARY KEY,
    subtitle VARCHAR(100) NOT NULL,
    title VARCHAR(100) NOT NULL,
    primary_btn_text VARCHAR(50) NOT NULL,
    primary_btn_icon VARCHAR(50) NOT NULL,
    secondary_btn_text VARCHAR(50) NOT NULL,
    secondary_btn_icon VARCHAR(50) NOT NULL,
    secondary_btn_url VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT TRUE,
    updated_by INTEGER DEFAULT NULL,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (updated_by) REFERENCES admin_users(id)
);

-- Announcements table
CREATE TABLE IF NOT EXISTS announcements (
    id SERIAL PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    content TEXT DEFAULT NULL,
    created_by INTEGER DEFAULT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Password resets table
CREATE TABLE IF NOT EXISTS password_resets (
    email VARCHAR(100) NOT NULL,
    token VARCHAR(64) NOT NULL,
    expiry TIMESTAMP WITH TIME ZONE NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (email, token)
);

-- Redeemable items table
CREATE TABLE IF NOT EXISTS redeemable_items (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    coin_cost INTEGER NOT NULL,
    image_url VARCHAR(255),
    stock INTEGER DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    is_unlimited BOOLEAN DEFAULT FALSE,
    requires_approval BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- Redemption history table
CREATE TABLE IF NOT EXISTS redemption_history (
    id SERIAL PRIMARY KEY,
    user_id INTEGER,
    item_id INTEGER,
    coins_spent INTEGER NOT NULL,
    redeemed_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending', 'completed', 'cancelled')),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (item_id) REFERENCES redeemable_items(id)
);

-- Device tokens table
CREATE TABLE IF NOT EXISTS device_tokens (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    token TEXT NOT NULL,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_device_tokens_user_id ON device_tokens (user_id);

-- Match participants table
CREATE TABLE IF NOT EXISTS match_participants (
    id SERIAL PRIMARY KEY,
    match_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    team_id INTEGER,
    join_date TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    status VARCHAR(20) DEFAULT 'joined' CHECK (status IN ('joined', 'disqualified', 'winner')),
    position INTEGER DEFAULT NULL,
    FOREIGN KEY (match_id) REFERENCES matches(id),
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (team_id) REFERENCES teams(id)
);

CREATE INDEX IF NOT EXISTS idx_match_participants_user ON match_participants (user_id);
CREATE INDEX IF NOT EXISTS idx_match_participants_match ON match_participants (match_id);
CREATE INDEX IF NOT EXISTS idx_match_participants_team ON match_participants (team_id);

-- User kills table
CREATE TABLE IF NOT EXISTS user_kills (
    id SERIAL PRIMARY KEY,
    match_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    kills INTEGER DEFAULT 0,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (match_id, user_id),
    FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_user_kills_match_kills ON user_kills (match_id);
CREATE INDEX IF NOT EXISTS idx_user_kills_user_kills ON user_kills (user_id);

-- User match stats table
CREATE TABLE IF NOT EXISTS user_match_stats (
    user_id INTEGER NOT NULL,
    total_matches_played INTEGER DEFAULT 0,
    total_kills INTEGER DEFAULT 0,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- User games table
CREATE TABLE IF NOT EXISTS user_games (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    game_name VARCHAR(20) NOT NULL CHECK (game_name IN ('PUBG', 'BGMI', 'FREE FIRE', 'COD')),
    game_username VARCHAR(50),
    game_uid VARCHAR(20),
    game_level INTEGER DEFAULT 1 CHECK (game_level >= 1 AND game_level <= 100),
    is_primary BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE (user_id, game_name)
);

-- Match screenshots table (for standalone matches)
CREATE TABLE IF NOT EXISTS match_screenshots (
    id SERIAL PRIMARY KEY,
    match_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    image_path TEXT NOT NULL,
    image_url TEXT NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    file_size INTEGER DEFAULT 0,
    upload_type VARCHAR(20) DEFAULT 'result' CHECK (upload_type IN ('result', 'winner')),
    description TEXT DEFAULT NULL,
    verified BOOLEAN DEFAULT FALSE,
    verified_by INTEGER DEFAULT NULL,
    verified_at TIMESTAMP WITH TIME ZONE DEFAULT NULL,
    kills_claimed INTEGER DEFAULT 0,
    rank_claimed INTEGER DEFAULT NULL,
    won_match BOOLEAN DEFAULT FALSE,
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
COMMENT ON COLUMN match_screenshots.upload_type IS 'Type of screenshot: result (standard screenshot), winner (additional winner screenshot if user won)';
COMMENT ON COLUMN match_screenshots.verified IS 'Whether admin has verified this screenshot';
COMMENT ON COLUMN match_screenshots.kills_claimed IS 'Number of kills user claims in this screenshot';
COMMENT ON COLUMN match_screenshots.rank_claimed IS 'Final rank/position user claims in this screenshot';
COMMENT ON COLUMN match_screenshots.won_match IS 'Boolean flag indicating if user claims to have won the match';

COMMENT ON TABLE tournament_round_screenshots IS 'Stores screenshots uploaded by users as proof of tournament round results';
COMMENT ON COLUMN tournament_round_screenshots.upload_type IS 'Type of screenshot: result, kills, placement, points, final';
COMMENT ON COLUMN tournament_round_screenshots.verified IS 'Whether admin has verified this screenshot';
COMMENT ON COLUMN tournament_round_screenshots.kills_claimed IS 'Number of kills user claims in this round';
COMMENT ON COLUMN tournament_round_screenshots.placement_claimed IS 'Final placement user claims in this round';
COMMENT ON COLUMN tournament_round_screenshots.points_claimed IS 'Total points user claims for this round';

-- Match history archive table
CREATE TABLE IF NOT EXISTS match_history_archive (
    id SERIAL PRIMARY KEY,
    original_match_id INTEGER,
    user_id INTEGER NOT NULL,
    game_name VARCHAR(50) NOT NULL CHECK (game_name IN ('BGMI', 'PUBG', 'FREE FIRE', 'COD')),
    match_type VARCHAR(50) NOT NULL,
    match_date TIMESTAMP WITH TIME ZONE NOT NULL,
    entry_type VARCHAR(20),
    entry_fee INTEGER,
    position INTEGER,
    kills INTEGER DEFAULT 0,
    coins_earned INTEGER DEFAULT 0,
    coins_per_kill INTEGER DEFAULT 0,
    prize_amount DECIMAL(10,2) DEFAULT 0,
    prize_type VARCHAR(20),
    participation_status VARCHAR(20),
    match_status VARCHAR(20),
    archived_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_match_history_user_matches ON match_history_archive (user_id, game_name);

-- Tournament history archive table
CREATE TABLE IF NOT EXISTS tournament_history_archive (
    id SERIAL PRIMARY KEY,
    original_tournament_id INTEGER,
    user_id INTEGER NOT NULL,
    tournament_name VARCHAR(255) NOT NULL,
    game_name VARCHAR(50) NOT NULL CHECK (game_name IN ('BGMI', 'PUBG', 'FREE FIRE', 'COD')),
    team_name VARCHAR(100),
    registration_date TIMESTAMP WITH TIME ZONE NOT NULL,
    rounds_played INTEGER DEFAULT 0,
    total_kills INTEGER DEFAULT 0,
    total_points INTEGER DEFAULT 0,
    best_placement INTEGER,
    final_position INTEGER,
    prize_amount DECIMAL(10,2) DEFAULT 0,
    prize_currency VARCHAR(20),
    website_currency_earned INTEGER DEFAULT 0,
    website_currency_type VARCHAR(20),
    participation_status VARCHAR(20),
    archived_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE INDEX IF NOT EXISTS idx_tournament_history_user_tournaments ON tournament_history_archive (user_id, game_name);

-- Streak tasks table
CREATE TABLE IF NOT EXISTS streak_tasks (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    reward_points INTEGER NOT NULL,
    is_daily BOOLEAN DEFAULT TRUE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- User streaks table
CREATE TABLE IF NOT EXISTS user_streaks (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    current_streak INTEGER DEFAULT 0,
    longest_streak INTEGER DEFAULT 0,
    streak_points INTEGER DEFAULT 0,
    total_earned_points INTEGER DEFAULT 0,
    total_tasks_completed INTEGER DEFAULT 0,
    last_activity_date DATE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- User streak tasks table
CREATE TABLE IF NOT EXISTS user_streak_tasks (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    task_id INTEGER NOT NULL,
    completion_date TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    points_earned INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES streak_tasks(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_user_streak_tasks_user_tasks ON user_streak_tasks (user_id, task_id);

-- Streak milestones table
CREATE TABLE IF NOT EXISTS streak_milestones (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    points_required INTEGER NOT NULL,
    reward_points INTEGER NOT NULL DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);

-- User streak milestones table
CREATE TABLE IF NOT EXISTS user_streak_milestones (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    milestone_id INTEGER NOT NULL,
    achieved_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (milestone_id) REFERENCES streak_milestones(id) ON DELETE CASCADE,
    UNIQUE (user_id, milestone_id)
);

-- Streak conversion log table
CREATE TABLE IF NOT EXISTS streak_conversion_log (
    id SERIAL PRIMARY KEY,
    user_id INTEGER NOT NULL,
    points_converted INTEGER NOT NULL,
    coins_received INTEGER NOT NULL,
    conversion_date TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_streak_conversion_user_conversions ON streak_conversion_log (user_id);

-- Functions to handle updated_at timestamps (PostgreSQL equivalent of MySQL's ON UPDATE CURRENT_TIMESTAMP)
CREATE OR REPLACE FUNCTION update_updated_at_column()
RETURNS TRIGGER
SET search_path = 'public'
AS $$
BEGIN
    NEW.updated_at = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Create a separate function for tables that use 'last_updated'
CREATE OR REPLACE FUNCTION update_last_updated_column()
RETURNS TRIGGER
SET search_path = 'public'
AS $$
BEGIN
    NEW.last_updated = CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Create triggers for updated_at columns
CREATE TRIGGER update_shop_orders_updated_at BEFORE UPDATE ON shop_orders FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_tournaments_updated_at BEFORE UPDATE ON tournaments FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_tournament_days_updated_at BEFORE UPDATE ON tournament_days FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_tournament_rounds_updated_at BEFORE UPDATE ON tournament_rounds FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_round_teams_updated_at BEFORE UPDATE ON round_teams FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_tournament_player_history_updated_at BEFORE UPDATE ON tournament_player_history FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_live_streams_updated_at BEFORE UPDATE ON live_streams FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_games_updated_at BEFORE UPDATE ON games FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_matches_updated_at BEFORE UPDATE ON matches FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_hero_settings_updated_at BEFORE UPDATE ON hero_settings FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_user_kills_updated_at BEFORE UPDATE ON user_kills FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_user_match_stats_updated_at BEFORE UPDATE ON user_match_stats FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_user_games_updated_at BEFORE UPDATE ON user_games FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_user_streaks_updated_at BEFORE UPDATE ON user_streaks FOR EACH ROW EXECUTE FUNCTION update_updated_at_column();
CREATE TRIGGER update_user_coins_last_updated BEFORE UPDATE ON user_coins FOR EACH ROW EXECUTE FUNCTION update_last_updated_column();
CREATE TRIGGER update_user_tickets_last_updated BEFORE UPDATE ON user_tickets FOR EACH ROW EXECUTE FUNCTION update_last_updated_column();

-- Insert default data

-- Insert default admin user
INSERT INTO admin_users (username, email, password, full_name, role) 
VALUES ('admin', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Admin', 'super_admin')
ON CONFLICT (username) DO NOTHING;

-- Insert default hero settings
INSERT INTO hero_settings (subtitle, title, primary_btn_text, primary_btn_icon, secondary_btn_text, secondary_btn_icon, secondary_btn_url)
VALUES ('THE SEASON 1', 'TOURNAMENTS', '+TICKET', 'wallet-outline', 'GAMES', 'game-controller-outline', '#games')
ON CONFLICT DO NOTHING;

-- Insert default team banners
INSERT INTO team_banners (image_path, name) VALUES
('/newapp/assets/images/hero-banner1.png', 'Banner 1'),
('/newapp/assets/images/hero-banner.jpg', 'Banner 2'),
('/newapp/assets/images/hero-banner1.png', 'Banner 3'),
('/newapp/assets/images/hero-banner.jpg', 'Banner 4'),
('/assets/images/team-banners/banner1.jpg', 'Banner 5'),
('/assets/images/team-banners/banner2.jpg', 'Banner 6'),
('/assets/images/team-banners/banner3.jpg', 'Banner 7'),
('/assets/images/team-banners/banner4.jpg', 'Banner 8'),
('/assets/images/team-banners/banner5.jpg', 'Banner 9'),
('/assets/images/team-banners/banner6.jpg', 'Banner 10')
ON CONFLICT DO NOTHING;

-- Insert default video categories
INSERT INTO video_categories (name, description) VALUES
('Tournament Highlights', 'Best moments from our tournaments'),
('Tutorial Videos', 'Learn and improve your gaming skills'),
('Gaming Tips', 'Professional tips and tricks'),
('Event Coverage', 'Coverage of gaming events and competitions')
ON CONFLICT DO NOTHING;

-- Insert default profile image
INSERT INTO profile_images (image_path, is_active, is_default) VALUES
('https://t3.ftcdn.net/jpg/09/68/64/82/360_F_968648260_97v6FNQWP3alhvyfLWtQTWGcrWZvAr1C.jpg', TRUE, TRUE)
ON CONFLICT DO NOTHING;

-- Insert default games
INSERT INTO games (name, status) VALUES
('BGMI', 'active'),
('PUBG', 'active'),
('FREE FIRE', 'active'),
('COD', 'active')
ON CONFLICT DO NOTHING;

-- Insert default streak tasks
INSERT INTO streak_tasks (name, description, reward_points, is_daily) VALUES
-- Daily Tasks
('Daily Login', 'Log in to your account', 5, TRUE),
('Join a Match', 'Participate in any match', 10, TRUE),
('Win a Match', 'Win any match you participate in', 20, TRUE),

-- One-Time Achievements
('Account Registration', 'Register an account on KGX', 10, FALSE),
('Game Profile Setup', 'Add at least one game profile', 15, FALSE),
('First Match', 'Play your first match', 20, FALSE),
('Team Membership', 'Join a team or create one as captain', 25, FALSE),
('First Tournament', 'Participate in your first tournament', 30, FALSE),
('Match Veteran', 'Play 50 matches', 50, FALSE),
('Tournament Veteran', 'Play 50 tournaments', 100, FALSE)
ON CONFLICT DO NOTHING;

-- Insert default streak milestones
INSERT INTO streak_milestones (name, description, points_required, reward_points) VALUES
('Bronze Streak', 'Reach 100 streak points', 100, 50),
('Silver Streak', 'Reach 500 streak points', 500, 100),
('Gold Streak', 'Reach 1000 streak points', 1000, 200),
('Diamond Streak', 'Reach 5000 streak points', 5000, 500)
ON CONFLICT DO NOTHING;

-- Create function for tournament registration history
CREATE OR REPLACE FUNCTION handle_tournament_registration()
RETURNS TRIGGER
SET search_path = 'public'
AS $$
BEGIN
    -- Insert history records for all active team members
    INSERT INTO tournament_player_history (
        tournament_id,
        user_id,
        team_id,
        registration_date,
        status
    )
    SELECT 
        NEW.tournament_id,
        tm.user_id,
        NEW.team_id,
        NEW.registration_date,
        CASE 
            WHEN NEW.status = 'approved' THEN 'registered'
            ELSE 'registered'
        END
    FROM team_members tm
    WHERE tm.team_id = NEW.team_id
    AND tm.status = 'active'
    ON CONFLICT (tournament_id, user_id) DO NOTHING;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Create trigger for tournament registration
CREATE TRIGGER after_tournament_registration
    AFTER INSERT ON tournament_registrations
    FOR EACH ROW
    EXECUTE FUNCTION handle_tournament_registration();

-- ============================================================================
-- SCREENSHOT CLEANUP FUNCTIONS (for managing storage)
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

-- Note: PostgreSQL doesn't support MySQL's DELIMITER syntax or stored procedures in the same way
-- The complex triggers and events from MySQL would need to be implemented as PostgreSQL functions
-- if the same functionality is needed.



-- ============================================================================
-- PERFORMANCE OPTIMIZATION
-- ============================================================================

-- =============================================================================
-- ENHANCED TEAM MANAGEMENT HELPER FUNCTIONS
-- ============================================================================= 

-- Function to get team win rate (SECURITY INVOKER for better security)
CREATE OR REPLACE FUNCTION get_team_win_rate(team_id_param INTEGER)
RETURNS DECIMAL(5,2)
SET search_path = 'public'
AS $$
DECLARE
    total_games INTEGER;
    win_rate DECIMAL(5,2);
BEGIN
    SELECT wins + losses INTO total_games
    FROM teams 
    WHERE id = team_id_param;
    
    IF total_games = 0 THEN
        RETURN 0.00;
    END IF;
    
    SELECT ROUND((wins::DECIMAL / total_games::DECIMAL) * 100, 2) INTO win_rate
    FROM teams 
    WHERE id = team_id_param;
    
    RETURN win_rate;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER IMMUTABLE;

-- Function to update team member count
CREATE OR REPLACE FUNCTION update_team_member_count()
RETURNS TRIGGER
SET search_path = 'public'
AS $$
BEGIN
    -- Update current_members count based on active members
    IF TG_OP = 'INSERT' OR TG_OP = 'UPDATE' THEN
        UPDATE teams 
        SET current_members = (
            SELECT COUNT(*) 
            FROM team_members 
            WHERE team_id = NEW.team_id 
            AND status = 'active'
        )
        WHERE id = NEW.team_id;
        RETURN NEW;
    END IF;
    
    IF TG_OP = 'DELETE' THEN
        UPDATE teams 
        SET current_members = (
            SELECT COUNT(*) 
            FROM team_members 
            WHERE team_id = OLD.team_id 
            AND status = 'active'
        )
        WHERE id = OLD.team_id;
        RETURN OLD;
    END IF;
    
    RETURN NULL;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Create trigger for automatic member count updates
DROP TRIGGER IF EXISTS update_team_member_count_trigger ON team_members;
CREATE TRIGGER update_team_member_count_trigger
    AFTER INSERT OR UPDATE OR DELETE ON team_members
    FOR EACH ROW
    EXECUTE FUNCTION update_team_member_count();

-- Function to automatically accept/reject join requests based on team capacity
CREATE OR REPLACE FUNCTION handle_team_capacity()
RETURNS TRIGGER
SET search_path = 'public'
AS $$
BEGIN
    -- If team is at capacity, automatically reject new requests
    IF NEW.status = 'pending' THEN
        IF (SELECT current_members FROM teams WHERE id = NEW.team_id) >= 
           (SELECT max_members FROM teams WHERE id = NEW.team_id) THEN
            NEW.status = 'rejected';
        END IF;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Create trigger for automatic capacity handling
DROP TRIGGER IF EXISTS handle_team_capacity_trigger ON team_join_requests;
CREATE TRIGGER handle_team_capacity_trigger
    BEFORE INSERT ON team_join_requests
    FOR EACH ROW
    EXECUTE FUNCTION handle_team_capacity();

-- =============================================================================
-- ENHANCED TEAM DISCOVERY VIEWS
-- =============================================================================

-- Create view for enhanced team discovery (with inline calculations for security)
CREATE VIEW team_discovery AS
SELECT 
    t.id,
    t.name,
    t.logo,
    t.description,
    t.language,
    t.preferred_game,
    t.skill_level,
    t.region,
    t.current_members,
    t.max_members,
    t.wins,
    t.losses,
    -- Inline win rate calculation instead of function call
    CASE 
        WHEN (t.wins + t.losses) = 0 THEN 0.00
        ELSE ROUND((t.wins::DECIMAL / (t.wins + t.losses)::DECIMAL) * 100, 2)
    END as win_rate,
    t.total_score,
    t.created_at,
    u.username as captain_username,
    u.full_name as captain_name,
    (t.max_members - t.current_members) as available_spots,
    CASE 
        WHEN t.current_members >= t.max_members THEN 'full'
        WHEN t.current_members = 0 THEN 'empty'
        ELSE 'available'
    END as availability_status
FROM teams t
JOIN users u ON t.captain_id = u.id
WHERE t.is_active = true 
AND t.is_private = false;

-- Create view for team statistics (with inline calculations for security)
CREATE VIEW team_stats AS
SELECT 
    t.id,
    t.name,
    t.wins,
    t.losses,
    (t.wins + t.losses) as total_games,
    -- Inline win rate calculation instead of function call
    CASE 
        WHEN (t.wins + t.losses) = 0 THEN 0.00
        ELSE ROUND((t.wins::DECIMAL / (t.wins + t.losses)::DECIMAL) * 100, 2)
    END as win_rate,
    t.total_score,
    t.current_members,
    COUNT(tr.id) as tournament_participations,
    COALESCE(AVG(rt.total_points), 0) as avg_tournament_points
FROM teams t
LEFT JOIN tournament_registrations tr ON t.id = tr.team_id
LEFT JOIN round_teams rt ON t.id = rt.team_id
WHERE t.is_active = true
GROUP BY t.id, t.name, t.wins, t.losses, t.total_score, t.current_members;

-- Set views to use SECURITY INVOKER mode for better security
ALTER VIEW team_discovery SET (security_invoker = true);
ALTER VIEW team_stats SET (security_invoker = true);

-- =============================================================================
-- PERFORMANCE INDEXES FOR ENHANCED FEATURES
-- =============================================================================

-- Create indexes for commonly queried conditions
CREATE INDEX IF NOT EXISTS idx_admin_users_auth_lookup ON admin_users (id, is_active, role) WHERE is_active = true;
CREATE INDEX IF NOT EXISTS idx_users_auth_lookup ON users (id, role);

-- Add composite indexes for better performance
CREATE INDEX IF NOT EXISTS idx_admin_users_lookup ON admin_users (id, is_active, role);
CREATE INDEX IF NOT EXISTS idx_users_role_lookup ON users (id, role);
CREATE INDEX IF NOT EXISTS idx_team_members_team_user ON team_members (team_id, user_id, status);

-- Indexes for better team discovery performance
CREATE INDEX IF NOT EXISTS idx_teams_discovery ON teams (is_active, is_private, current_members, max_members) WHERE is_active = true;
CREATE INDEX IF NOT EXISTS idx_teams_skill_game ON teams (skill_level, preferred_game) WHERE is_active = true;
CREATE INDEX IF NOT EXISTS idx_teams_region ON teams (region) WHERE is_active = true;
CREATE INDEX IF NOT EXISTS idx_team_join_requests_user_status ON team_join_requests (user_id, status);
CREATE INDEX IF NOT EXISTS idx_team_join_requests_team_status ON team_join_requests (team_id, status);

-- =============================================================================
-- TOURNAMENT AND TEAM MANAGEMENT RPC FUNCTIONS
-- =============================================================================

-- Function to increment team score when they register for tournaments
CREATE OR REPLACE FUNCTION increment_team_score(team_id_param INTEGER, increment_by INTEGER)
RETURNS void AS $$
BEGIN
  UPDATE teams
  SET total_score = total_score + increment_by
  WHERE id = team_id_param;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Function to increment tournament team count when new registrations are made
CREATE OR REPLACE FUNCTION increment_tournament_teams(tournament_id_param INTEGER)
RETURNS void AS $$
BEGIN
  UPDATE tournaments
  SET current_teams = current_teams + 1
  WHERE id = tournament_id_param;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Function to increment user's ticket balance (since users table doesn't have total_score)
-- This function updates the ticket_balance column in the users table
CREATE OR REPLACE FUNCTION increment_user_tickets(user_id_param INTEGER, increment_by INTEGER)
RETURNS void AS $$
BEGIN
  UPDATE users
  SET ticket_balance = COALESCE(ticket_balance, 0) + increment_by
  WHERE id = user_id_param;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Function to refund tickets to a user
-- This function works with both user_tickets table and users.ticket_balance
CREATE OR REPLACE FUNCTION refund_tickets(user_id_param INTEGER, amount INTEGER)
RETURNS void AS $$
BEGIN
  -- First, update the user_tickets table
  UPDATE user_tickets
  SET tickets = tickets + amount,
      last_updated = CURRENT_TIMESTAMP
  WHERE user_id = user_id_param;
  
  -- Insert a record if it doesn't exist in user_tickets table
  INSERT INTO user_tickets (user_id, tickets, last_updated)
  SELECT user_id_param, amount, CURRENT_TIMESTAMP
  WHERE NOT EXISTS (SELECT 1 FROM user_tickets WHERE user_id = user_id_param);
  
  -- Also update the ticket_balance in users table for consistency
  UPDATE users
  SET ticket_balance = COALESCE(ticket_balance, 0) + amount
  WHERE id = user_id_param;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Function to deduct tickets from a user (for tournament entry fees)
CREATE OR REPLACE FUNCTION deduct_tickets(user_id_param INTEGER, amount INTEGER)
RETURNS boolean AS $$
DECLARE
  current_balance INTEGER;
BEGIN
  -- Get current ticket balance from user_tickets table
  SELECT tickets INTO current_balance
  FROM user_tickets
  WHERE user_id = user_id_param;
  
  -- If no record exists, check users table ticket_balance
  IF current_balance IS NULL THEN
    SELECT ticket_balance INTO current_balance
    FROM users
    WHERE id = user_id_param;
    
    -- If still null, assume 0
    IF current_balance IS NULL THEN
      current_balance := 0;
    END IF;
  END IF;
  
  -- Check if user has enough tickets
  IF current_balance >= amount THEN
    -- Deduct from user_tickets table
    UPDATE user_tickets
    SET tickets = tickets - amount,
        last_updated = CURRENT_TIMESTAMP
    WHERE user_id = user_id_param;
    
    -- Insert a record if it doesn't exist
    INSERT INTO user_tickets (user_id, tickets, last_updated)
    SELECT user_id_param, -amount, CURRENT_TIMESTAMP
    WHERE NOT EXISTS (SELECT 1 FROM user_tickets WHERE user_id = user_id_param);
    
    -- Also update users table
    UPDATE users
    SET ticket_balance = GREATEST(COALESCE(ticket_balance, 0) - amount, 0)
    WHERE id = user_id_param;
    
    RETURN true;
  ELSE
    RETURN false;
  END IF;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Function to decrement tournament team count when registrations are cancelled
CREATE OR REPLACE FUNCTION decrement_tournament_teams(tournament_id_param INTEGER)
RETURNS void AS $$
BEGIN
  UPDATE tournaments
  SET current_teams = GREATEST(current_teams - 1, 0)
  WHERE id = tournament_id_param;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Function to increment user's score when they participate in tournaments
-- This function updates the user's total_points in tournament_player_history table
CREATE OR REPLACE FUNCTION increment_user_score(user_id_param INTEGER, increment_by INTEGER, tournament_id_param INTEGER)
RETURNS void AS $$
BEGIN
  -- Update the user's total_points in tournament_player_history table
  UPDATE tournament_player_history 
  SET total_points = total_points + increment_by,
      updated_at = CURRENT_TIMESTAMP
  WHERE user_id = user_id_param AND tournament_id = tournament_id_param;
  
  -- If no record exists, we don't create one here as it should already exist from registration
  -- Log if no rows were affected (optional, for debugging)
  IF NOT FOUND THEN
    -- Could log this for debugging, but for now just return silently
    RETURN;
  END IF;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- Function to get user's total ticket balance (combining both sources)
CREATE OR REPLACE FUNCTION get_user_ticket_balance(user_id_param INTEGER)
RETURNS INTEGER AS $$
DECLARE
  tickets_table_balance INTEGER := 0;
  users_table_balance INTEGER := 0;
  total_balance INTEGER := 0;
BEGIN
  -- Get balance from user_tickets table
  SELECT tickets INTO tickets_table_balance
  FROM user_tickets
  WHERE user_id = user_id_param;
  
  -- Get balance from users table
  SELECT ticket_balance INTO users_table_balance
  FROM users
  WHERE id = user_id_param;
  
  -- Use the maximum of both (they should be in sync)
  total_balance := GREATEST(
    COALESCE(tickets_table_balance, 0),
    COALESCE(users_table_balance, 0)
  );
  
  RETURN total_balance;
END;
$$ LANGUAGE plpgsql SECURITY INVOKER;

-- ============================================================================
-- COMMENTS AND DOCUMENTATION
-- ============================================================================

-- Schema documentation
-- KGX Esports Database - Converted from MySQL to PostgreSQL for Supabase

-- ============================================================================
-- TOURNAMENT FORMATS EXTENSION: GROUP STAGE AND WEEKLY FINALS
-- ============================================================================
-- This section adds support for new tournament formats:
-- 1. Group Stage: Teams are divided into groups and compete within groups
-- 2. Weekly Finals: Teams qualify through weekly rounds to reach finals
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
-- END OF TOURNAMENT FORMATS EXTENSION
-- ============================================================================
