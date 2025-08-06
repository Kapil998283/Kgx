-- ============================================================================
-- COMPLETE RLS CLEANUP SCRIPT - USE WITH CAUTION!
-- ============================================================================
-- This script will drop ALL RLS policies from ALL tables
-- Only run this if you want to completely reset your RLS setup

-- ============================================================================
-- STEP 1: DROP ALL EXISTING POLICIES (COMPLETE RESET)
-- ============================================================================

-- Get list of all policies in the database
DO $$
DECLARE
    pol_record RECORD;
BEGIN
    FOR pol_record IN 
        SELECT schemaname, tablename, policyname 
        FROM pg_policies 
        WHERE schemaname = 'public'
    LOOP
        EXECUTE format('DROP POLICY IF EXISTS %I ON %I.%I', 
                      pol_record.policyname, 
                      pol_record.schemaname, 
                      pol_record.tablename);
        RAISE NOTICE 'Dropped policy % on table %', pol_record.policyname, pol_record.tablename;
    END LOOP;
END $$;

-- ============================================================================
-- STEP 2: DISABLE RLS ON ALL TABLES (OPTIONAL - USE WITH EXTREME CAUTION!)
-- ============================================================================
-- Uncomment the following section ONLY if you want to completely disable RLS

/*
DO $$
DECLARE
    table_record RECORD;
BEGIN
    FOR table_record IN 
        SELECT schemaname, tablename 
        FROM pg_tables 
        WHERE schemaname = 'public' AND rowsecurity = true
    LOOP
        EXECUTE format('ALTER TABLE %I.%I DISABLE ROW LEVEL SECURITY', 
                      table_record.schemaname, 
                      table_record.tablename);
        RAISE NOTICE 'Disabled RLS on table %', table_record.tablename;
    END LOOP;
END $$;
*/

-- ============================================================================
-- STEP 3: RE-ENABLE RLS ON REQUIRED TABLES
-- ============================================================================

-- Core user data tables
ALTER TABLE users ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_coins ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_tickets ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_games ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_streaks ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_streak_tasks ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_streak_milestones ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_kills ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_match_stats ENABLE ROW LEVEL SECURITY;

-- Team and gaming tables
ALTER TABLE teams ENABLE ROW LEVEL SECURITY;
ALTER TABLE team_members ENABLE ROW LEVEL SECURITY;
ALTER TABLE team_join_requests ENABLE ROW LEVEL SECURITY;

-- Tournament and match tables
ALTER TABLE tournaments ENABLE ROW LEVEL SECURITY;
ALTER TABLE tournament_registrations ENABLE ROW LEVEL SECURITY;
ALTER TABLE matches ENABLE ROW LEVEL SECURITY;
ALTER TABLE match_participants ENABLE ROW LEVEL SECURITY;
ALTER TABLE match_results ENABLE ROW LEVEL SECURITY;

-- Notification and transaction tables
ALTER TABLE notifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE transactions ENABLE ROW LEVEL SECURITY;
ALTER TABLE shop_orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE redemption_history ENABLE ROW LEVEL SECURITY;

-- Admin tables
ALTER TABLE admin_users ENABLE ROW LEVEL SECURITY;
ALTER TABLE admin_activity_log ENABLE ROW LEVEL SECURITY;

-- Streaming tables
ALTER TABLE stream_rewards ENABLE ROW LEVEL SECURITY;
ALTER TABLE video_watch_history ENABLE ROW LEVEL SECURITY;
ALTER TABLE live_streams ENABLE ROW LEVEL SECURITY;

-- Other tables
ALTER TABLE device_tokens ENABLE ROW LEVEL SECURITY;
ALTER TABLE password_resets ENABLE ROW LEVEL SECURITY;
ALTER TABLE profile_images ENABLE ROW LEVEL SECURITY;
ALTER TABLE team_banners ENABLE ROW LEVEL SECURITY;

-- ============================================================================
-- STEP 4: VERIFY RLS STATUS
-- ============================================================================

-- Check which tables have RLS enabled
SELECT schemaname, tablename, rowsecurity 
FROM pg_tables 
WHERE schemaname = 'public' 
ORDER BY tablename;

-- Check remaining policies (should be empty after cleanup)
SELECT COUNT(*) as remaining_policies 
FROM pg_policies 
WHERE schemaname = 'public';

RAISE NOTICE 'RLS cleanup completed. You can now run your main schema file to recreate all policies.';
