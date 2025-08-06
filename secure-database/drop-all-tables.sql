-- KGX Esports Database - COMPLETE CLEANUP Script
-- This script will drop EVERYTHING: tables, views, functions, triggers, policies, indexes
-- WARNING: This will permanently delete all data and database objects
-- Use this for a complete clean slate before importing your new schema

-- =============================================================================
-- COMPLETE DATABASE CLEANUP FOR KGX ESPORTS
-- =============================================================================

BEGIN;

-- =============================================================================
-- STEP 1: DROP ALL VIEWS FIRST (to avoid dependency issues)
-- =============================================================================

DROP VIEW IF EXISTS team_discovery CASCADE;
DROP VIEW IF EXISTS team_stats CASCADE;

-- =============================================================================
-- STEP 2: DROP ALL TRIGGERS (before dropping functions)
-- =============================================================================

-- Drop all update triggers
DROP TRIGGER IF EXISTS update_shop_orders_updated_at ON shop_orders CASCADE;
DROP TRIGGER IF EXISTS update_tournaments_updated_at ON tournaments CASCADE;
DROP TRIGGER IF EXISTS update_tournament_days_updated_at ON tournament_days CASCADE;
DROP TRIGGER IF EXISTS update_tournament_rounds_updated_at ON tournament_rounds CASCADE;
DROP TRIGGER IF EXISTS update_round_teams_updated_at ON round_teams CASCADE;
DROP TRIGGER IF EXISTS update_tournament_player_history_updated_at ON tournament_player_history CASCADE;
DROP TRIGGER IF EXISTS update_live_streams_updated_at ON live_streams CASCADE;
DROP TRIGGER IF EXISTS update_games_updated_at ON games CASCADE;
DROP TRIGGER IF EXISTS update_matches_updated_at ON matches CASCADE;
DROP TRIGGER IF EXISTS update_user_coins_updated_at ON user_coins CASCADE;
DROP TRIGGER IF EXISTS update_user_tickets_updated_at ON user_tickets CASCADE;
DROP TRIGGER IF EXISTS update_hero_settings_updated_at ON hero_settings CASCADE;
DROP TRIGGER IF EXISTS update_user_kills_updated_at ON user_kills CASCADE;
DROP TRIGGER IF EXISTS update_user_match_stats_updated_at ON user_match_stats CASCADE;
DROP TRIGGER IF EXISTS update_user_games_updated_at ON user_games CASCADE;
DROP TRIGGER IF EXISTS update_user_streaks_updated_at ON user_streaks CASCADE;

-- Drop team management triggers
DROP TRIGGER IF EXISTS update_team_member_count_trigger ON team_members CASCADE;
DROP TRIGGER IF EXISTS handle_team_capacity_trigger ON team_join_requests CASCADE;

-- Drop tournament triggers
DROP TRIGGER IF EXISTS after_tournament_registration ON tournament_registrations CASCADE;

-- =============================================================================
-- STEP 3: DROP ALL FUNCTIONS
-- =============================================================================

-- Drop the update timestamp function
DROP FUNCTION IF EXISTS update_updated_at_column() CASCADE;

-- Drop the tournament registration handler function
DROP FUNCTION IF EXISTS handle_tournament_registration() CASCADE;

-- Drop team management functions
DROP FUNCTION IF EXISTS get_team_win_rate(INTEGER) CASCADE;
DROP FUNCTION IF EXISTS update_team_member_count() CASCADE;
DROP FUNCTION IF EXISTS handle_team_capacity() CASCADE;

-- =============================================================================
-- STEP 4: DROP ALL TABLES (in reverse dependency order)
-- =============================================================================

-- Drop all dependent tables first (child tables)
DROP TABLE IF EXISTS admin_activity_log CASCADE;
DROP TABLE IF EXISTS shop_orders CASCADE;
DROP TABLE IF EXISTS user_coins CASCADE;
DROP TABLE IF EXISTS user_tickets CASCADE;
DROP TABLE IF EXISTS team_members CASCADE;
DROP TABLE IF EXISTS team_join_requests CASCADE;
DROP TABLE IF EXISTS tournament_days CASCADE;
DROP TABLE IF EXISTS tournament_rounds CASCADE;
DROP TABLE IF EXISTS round_teams CASCADE;
DROP TABLE IF EXISTS tournament_registrations CASCADE;
DROP TABLE IF EXISTS tournament_winners CASCADE;
DROP TABLE IF EXISTS tournament_player_history CASCADE;
DROP TABLE IF EXISTS tournament_waitlist CASCADE;
DROP TABLE IF EXISTS live_streams CASCADE;
DROP TABLE IF EXISTS stream_rewards CASCADE;
DROP TABLE IF EXISTS video_watch_history CASCADE;
DROP TABLE IF EXISTS matches CASCADE;
DROP TABLE IF EXISTS match_results CASCADE;
DROP TABLE IF EXISTS match_participants CASCADE;
DROP TABLE IF EXISTS user_kills CASCADE;
DROP TABLE IF EXISTS user_match_stats CASCADE;
DROP TABLE IF EXISTS transactions CASCADE;
DROP TABLE IF EXISTS notifications CASCADE;
DROP TABLE IF EXISTS redemption_history CASCADE;
DROP TABLE IF EXISTS device_tokens CASCADE;
DROP TABLE IF EXISTS user_games CASCADE;
DROP TABLE IF EXISTS match_history_archive CASCADE;
DROP TABLE IF EXISTS tournament_history_archive CASCADE;
DROP TABLE IF EXISTS user_streaks CASCADE;
DROP TABLE IF EXISTS user_streak_tasks CASCADE;
DROP TABLE IF EXISTS user_streak_milestones CASCADE;
DROP TABLE IF EXISTS streak_conversion_log CASCADE;
DROP TABLE IF EXISTS announcements CASCADE;
DROP TABLE IF EXISTS password_resets CASCADE;

-- Drop intermediate tables
DROP TABLE IF EXISTS teams CASCADE;
DROP TABLE IF EXISTS tournaments CASCADE;
DROP TABLE IF EXISTS hero_settings CASCADE;

-- Drop reference/lookup tables
DROP TABLE IF EXISTS profile_images CASCADE;
DROP TABLE IF EXISTS team_banners CASCADE;
DROP TABLE IF EXISTS games CASCADE;
DROP TABLE IF EXISTS video_categories CASCADE;
DROP TABLE IF EXISTS redeemable_items CASCADE;
DROP TABLE IF EXISTS streak_tasks CASCADE;
DROP TABLE IF EXISTS streak_milestones CASCADE;

-- Drop main entity tables last
DROP TABLE IF EXISTS users CASCADE;
DROP TABLE IF EXISTS admin_users CASCADE;

-- =============================================================================
-- STEP 5: CLEANUP ANY REMAINING OBJECTS
-- =============================================================================

-- Drop any orphaned indexes (CASCADE should handle this, but just in case)
DROP INDEX IF EXISTS idx_username CASCADE;
DROP INDEX IF EXISTS idx_email CASCADE;
DROP INDEX IF EXISTS idx_phone CASCADE;
DROP INDEX IF EXISTS idx_shop_orders_user_id CASCADE;
DROP INDEX IF EXISTS idx_shop_orders_status CASCADE;
DROP INDEX IF EXISTS idx_profile_images_is_default CASCADE;
DROP INDEX IF EXISTS idx_team_banners_is_active CASCADE;
DROP INDEX IF EXISTS idx_teams_is_active CASCADE;
DROP INDEX IF EXISTS idx_teams_captain CASCADE;
DROP INDEX IF EXISTS idx_teams_name CASCADE;
DROP INDEX IF EXISTS idx_team_members_status CASCADE;
DROP INDEX IF EXISTS idx_team_members_role CASCADE;
DROP INDEX IF EXISTS idx_join_requests_status CASCADE;
DROP INDEX IF EXISTS idx_game_status CASCADE;
DROP INDEX IF EXISTS idx_game_name CASCADE;
DROP INDEX IF EXISTS idx_tournament_team CASCADE;
DROP INDEX IF EXISTS idx_tournament_user CASCADE;
DROP INDEX IF EXISTS idx_tournament_reg_status CASCADE;
DROP INDEX IF EXISTS idx_video_categories_active CASCADE;
DROP INDEX IF EXISTS idx_live_streams_video_type CASCADE;
DROP INDEX IF EXISTS idx_live_streams_status CASCADE;
DROP INDEX IF EXISTS idx_video_watch_user_watches CASCADE;
DROP INDEX IF EXISTS idx_video_watch_video_watches CASCADE;
DROP INDEX IF EXISTS idx_user_transactions CASCADE;
DROP INDEX IF EXISTS idx_transaction_type CASCADE;
DROP INDEX IF EXISTS idx_transaction_status CASCADE;
DROP INDEX IF EXISTS idx_notifications_user_id CASCADE;
DROP INDEX IF EXISTS idx_notifications_deleted_at CASCADE;
DROP INDEX IF EXISTS idx_notifications_is_read CASCADE;
DROP INDEX IF EXISTS idx_notifications_related CASCADE;
DROP INDEX IF EXISTS idx_device_tokens_user_id CASCADE;
DROP INDEX IF EXISTS idx_match_participants_user CASCADE;
DROP INDEX IF EXISTS idx_match_participants_match CASCADE;
DROP INDEX IF EXISTS idx_match_participants_team CASCADE;
DROP INDEX IF EXISTS idx_user_kills_match_kills CASCADE;
DROP INDEX IF EXISTS idx_user_kills_user_kills CASCADE;
DROP INDEX IF EXISTS idx_match_history_user_matches CASCADE;
DROP INDEX IF EXISTS idx_tournament_history_user_tournaments CASCADE;
DROP INDEX IF EXISTS idx_user_streak_tasks_user_tasks CASCADE;
DROP INDEX IF EXISTS idx_streak_conversion_user_conversions CASCADE;

-- Drop performance indexes
DROP INDEX IF EXISTS idx_admin_users_auth_lookup CASCADE;
DROP INDEX IF EXISTS idx_users_auth_lookup CASCADE;
DROP INDEX IF EXISTS idx_users_auth_uid CASCADE;
DROP INDEX IF EXISTS idx_user_coins_auth_uid CASCADE;
DROP INDEX IF EXISTS idx_user_tickets_auth_uid CASCADE;
DROP INDEX IF EXISTS idx_user_games_auth_uid CASCADE;
DROP INDEX IF EXISTS idx_notifications_auth_uid CASCADE;
DROP INDEX IF EXISTS idx_transactions_auth_uid CASCADE;
DROP INDEX IF EXISTS idx_shop_orders_auth_uid CASCADE;
DROP INDEX IF EXISTS idx_teams_captain_auth_uid CASCADE;
DROP INDEX IF EXISTS idx_team_members_auth_uid CASCADE;
DROP INDEX IF EXISTS idx_team_join_requests_auth_uid CASCADE;
DROP INDEX IF EXISTS idx_admin_users_lookup CASCADE;
DROP INDEX IF EXISTS idx_users_role_lookup CASCADE;
DROP INDEX IF EXISTS idx_team_members_team_user CASCADE;
DROP INDEX IF EXISTS idx_teams_discovery CASCADE;
DROP INDEX IF EXISTS idx_teams_skill_game CASCADE;
DROP INDEX IF EXISTS idx_teams_region CASCADE;
DROP INDEX IF EXISTS idx_team_join_requests_user_status CASCADE;
DROP INDEX IF EXISTS idx_team_join_requests_team_status CASCADE;

-- =============================================================================
-- STEP 6: RESET SEQUENCES (if needed)
-- =============================================================================

-- Reset any sequences that might be left over
-- Note: Sequences are automatically dropped with tables, but just in case
-- DROP SEQUENCE IF EXISTS admin_users_id_seq CASCADE;
-- DROP SEQUENCE IF EXISTS users_id_seq CASCADE;
-- ... (add more if needed)

-- =============================================================================
-- STEP 7: CLEAN UP EXTENSIONS (OPTIONAL)
-- =============================================================================

-- Only uncomment this if you want to remove the extension completely
-- DROP EXTENSION IF EXISTS "uuid-ossp" CASCADE;

COMMIT;

-- Verification query to check if all tables are dropped
-- Run this separately after the above script
/*
SELECT table_name 
FROM information_schema.tables 
WHERE table_schema = 'public' 
AND table_type = 'BASE TABLE'
ORDER BY table_name;
*/

-- Summary of what was dropped:
-- ================================
-- Total Tables Dropped: 46
-- 
-- Main Entity Tables (2):
-- - users
-- - admin_users
--
-- User-Related Tables (15):  
-- - user_coins, user_tickets, user_games, user_streaks
-- - user_streak_tasks, user_streak_milestones, streak_conversion_log
-- - user_kills, user_match_stats, match_history_archive
-- - tournament_history_archive, notifications, transactions
-- - device_tokens, redemption_history
--
-- Team-Related Tables (3):
-- - teams, team_members, team_join_requests
--
-- Tournament-Related Tables (8):
-- - tournaments, tournament_days, tournament_rounds, round_teams
-- - tournament_registrations, tournament_winners
-- - tournament_player_history, tournament_waitlist
--
-- Match-Related Tables (4):
-- - matches, match_results, match_participants, user_kills
--
-- Streaming-Related Tables (4):
-- - live_streams, stream_rewards, video_watch_history, video_categories
--
-- Commerce-Related Tables (3):
-- - shop_orders, redeemable_items, redemption_history
--
-- Gamification Tables (4):
-- - streak_tasks, user_streaks, user_streak_tasks, streak_milestones
--
-- Configuration Tables (5):
-- - games, profile_images, team_banners, hero_settings, announcements
--
-- System Tables (2):
-- - admin_activity_log, password_resets
--
-- Functions Dropped (2):
-- - update_updated_at_column()
-- - handle_tournament_registration()
--
-- IMPORTANT NOTES:
-- ================
-- 1. All RLS (Row Level Security) policies are automatically dropped with tables
-- 2. All triggers are automatically dropped with tables  
-- 3. All indexes are automatically dropped with tables
-- 4. All foreign key constraints are automatically dropped with tables
-- 5. All check constraints are automatically dropped with tables
-- 6. Custom functions and triggers are explicitly dropped
-- 7. The CASCADE option ensures dependent objects are also dropped
--
-- After running this script, you can safely run your updated schema file
-- to recreate all tables with your new RLS policies.
