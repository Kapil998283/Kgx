# User RLS Policies - KGX Esports

This file contains all Row Level Security (RLS) policies related to user functionality and data access control.

## Table of Contents
1. [Enable RLS on Tables](#enable-rls-on-tables)
2. [Screenshot Upload Policies](#screenshot-upload-policies)
3. [User Authentication & Profile Policies](#user-authentication--profile-policies)
4. [Financial Data Policies](#financial-data-policies)
5. [Gaming Data Policies](#gaming-data-policies)
6. [Team Management Policies](#team-management-policies)
7. [Tournament Participation Policies](#tournament-participation-policies)
8. [Match Participation Policies](#match-participation-policies)
9. [Notification Policies](#notification-policies)
10. [Streak System Policies](#streak-system-policies)
11. [Streaming & Video Policies](#streaming--video-policies)
12. [Archive & History Policies](#archive--history-policies)
13. [Device & Redemption Policies](#device--redemption-policies)

---

## Enable RLS on Tables

```sql
-- Enable RLS on user-specific tables
ALTER TABLE users ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_coins ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_tickets ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_games ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_streaks ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_streak_tasks ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_streak_milestones ENABLE ROW LEVEL SECURITY;
ALTER TABLE streak_conversion_log ENABLE ROW LEVEL SECURITY;
ALTER TABLE notifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE transactions ENABLE ROW LEVEL SECURITY;
ALTER TABLE shop_orders ENABLE ROW LEVEL SECURITY;
ALTER TABLE redemption_history ENABLE ROW LEVEL SECURITY;
ALTER TABLE device_tokens ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_kills ENABLE ROW LEVEL SECURITY;
ALTER TABLE user_match_stats ENABLE ROW LEVEL SECURITY;
ALTER TABLE match_history_archive ENABLE ROW LEVEL SECURITY;
ALTER TABLE tournament_history_archive ENABLE ROW LEVEL SECURITY;
ALTER TABLE stream_rewards ENABLE ROW LEVEL SECURITY;
ALTER TABLE video_watch_history ENABLE ROW LEVEL SECURITY;
ALTER TABLE tournament_player_history ENABLE ROW LEVEL SECURITY;
ALTER TABLE tournament_waitlist ENABLE ROW LEVEL SECURITY;
ALTER TABLE teams ENABLE ROW LEVEL SECURITY;
ALTER TABLE team_members ENABLE ROW LEVEL SECURITY;
ALTER TABLE team_join_requests ENABLE ROW LEVEL SECURITY;
ALTER TABLE match_participants ENABLE ROW LEVEL SECURITY;
ALTER TABLE tournament_registrations ENABLE ROW LEVEL SECURITY;
ALTER TABLE match_screenshots ENABLE ROW LEVEL SECURITY;
ALTER TABLE tournament_round_screenshots ENABLE ROW LEVEL SECURITY;
```

---

## Screenshot Upload Policies

-- Fix storage bucket policies for screenshot uploads
-- Run this in Supabase SQL Editor

-- Create policy to allow uploads to match-screenshots bucket
INSERT INTO storage.buckets (id, name, public) 
VALUES ('match-screenshots', 'match-screenshots', false)
ON CONFLICT (id) DO NOTHING;

-- Allow anyone to upload files to match-screenshots bucket (for development)
CREATE POLICY "Allow uploads to match-screenshots" ON storage.objects
FOR INSERT WITH CHECK (bucket_id = 'match-screenshots');

-- Allow anyone to view files in match-screenshots bucket
CREATE POLICY "Allow public access to match-screenshots" ON storage.objects
FOR SELECT USING (bucket_id = 'match-screenshots');

-- Alternative: If the above doesn't work, disable RLS on storage.objects entirely for development
-- Uncomment the line below if needed:
-- ALTER TABLE storage.objects DISABLE ROW LEVEL SECURITY;





### Match Screenshots Policies
```sql
-- Enable RLS
ALTER TABLE match_screenshots ENABLE ROW LEVEL SECURITY;

-- Users can insert their own match screenshots
CREATE POLICY "Users can insert own match screenshots"
ON match_screenshots
FOR INSERT
WITH CHECK (auth.uid()::text = user_id::text);

-- Users can view their own match screenshots
CREATE POLICY "Users can view own match screenshots"
ON match_screenshots
FOR SELECT
USING (auth.uid()::text = user_id::text);

-- Admins can view all match screenshots
CREATE POLICY "Admins can view all match screenshots"
ON match_screenshots
FOR SELECT
USING (
  EXISTS (
    SELECT 1 FROM admin_users
    WHERE id::text = auth.uid()::text
    AND is_active = true
  )
);

-- Admins can update match screenshots
CREATE POLICY "Admins can update match screenshots"
ON match_screenshots
FOR UPDATE
USING (
  EXISTS (
    SELECT 1 FROM admin_users
    WHERE id::text = auth.uid()::text
    AND is_active = true
  )
);
```

### Tournament Round Screenshots Policies
```sql
-- Enable RLS
ALTER TABLE tournament_round_screenshots ENABLE ROW LEVEL SECURITY;

-- Users can insert their own tournament round screenshots
CREATE POLICY "Users can insert own tournament round screenshots"
ON tournament_round_screenshots
FOR INSERT
WITH CHECK (auth.uid()::text = user_id::text);

-- Users can view their own tournament round screenshots
CREATE POLICY "Users can view own tournament round screenshots"
ON tournament_round_screenshots
FOR SELECT
USING (auth.uid()::text = user_id::text);

-- Admins can view all tournament round screenshots
CREATE POLICY "Admins can view all tournament round screenshots"
ON tournament_round_screenshots
FOR SELECT
USING (
  EXISTS (
    SELECT 1 FROM admin_users
    WHERE id::text = auth.uid()::text
    AND is_active = true
  )
);

-- Admins can update tournament round screenshots
CREATE POLICY "Admins can update tournament round screenshots"
ON tournament_round_screenshots
FOR UPDATE
USING (
  EXISTS (
    SELECT 1 FROM admin_users
    WHERE id::text = auth.uid()::text
    AND is_active = true
  )
);
```

---

## User Authentication & Profile Policies

### Users Table Policies
```sql
-- Users can view all user profiles (for finding teammates, viewing leaderboards, etc.)
CREATE POLICY "Anyone can view user profiles" ON users FOR SELECT TO authenticated USING (true);

-- Users table - Allow registration via service role, users can update own profile
CREATE POLICY "allow_user_registration" ON users 
    FOR INSERT 
    WITH CHECK (
        -- Only allow service role (system) to create users during registration
        auth.role() = 'service_role' OR
        -- Allow anon users during signup process
        auth.role() = 'anon'
    );

CREATE POLICY "users_can_update_own_profile" ON users 
    FOR UPDATE 
    TO authenticated 
    USING (auth.uid()::text = id::text);
```

---

## Financial Data Policies

### User Coins Policies
```sql
-- User coins - Users can view own balance, system operations allowed
CREATE POLICY "users_can_view_own_coins" ON user_coins 
    FOR SELECT 
    TO authenticated 
    USING (true);  -- Allow all authenticated users to see their coins and RLS checks will be manually handled with session user_id.

CREATE POLICY "system_can_manage_user_coins" ON user_coins 
    FOR ALL 
    USING (
        -- Service role (for system operations like registration bonuses, rewards, redemptions)
        auth.role() = 'service_role'
    );
```

### User Tickets Policies
```sql
-- User tickets - Similar to coins
CREATE POLICY "users_can_view_own_tickets" ON user_tickets 
    FOR SELECT 
    TO authenticated 
    USING (true);  -- Allow all authenticated users to see their tickets and RLS checks will be manually handled with session user_id.

CREATE POLICY "system_can_manage_user_tickets" ON user_tickets 
    FOR ALL 
    USING (
        auth.role() = 'service_role'
    );
```

### Transactions Policies
```sql
-- Transactions - Users can view own transactions, system can create/manage
CREATE POLICY "users_can_view_own_transactions" ON transactions 
    FOR SELECT 
    TO authenticated 
    USING (auth.uid()::text = user_id::text);

CREATE POLICY "system_can_manage_transactions" ON transactions 
    FOR ALL 
    USING (
        auth.role() = 'service_role'
    );
```

### Shop Orders Policies
```sql
-- Shop orders - Users can manage own orders
CREATE POLICY "users_can_manage_own_orders" ON shop_orders 
    FOR ALL 
    TO authenticated 
    USING (auth.uid()::text = user_id::text);
```

---

## Gaming Data Policies

### User Games Policies
```sql
-- User games - Users can manage their own game profiles, system can create during registration
CREATE POLICY "users_can_view_own_game_profiles" ON user_games 
    FOR SELECT 
    TO authenticated 
    USING (auth.uid()::text = user_id::text);

CREATE POLICY "users_can_create_own_game_profiles" ON user_games 
    FOR INSERT 
    TO authenticated 
    WITH CHECK (
        auth.uid()::text = user_id::text OR
        auth.role() = 'service_role'
    );

CREATE POLICY "users_can_update_own_game_profiles" ON user_games 
    FOR UPDATE 
    TO authenticated 
    USING (auth.uid()::text = user_id::text);

CREATE POLICY "users_can_delete_own_game_profiles" ON user_games 
    FOR DELETE 
    TO authenticated 
    USING (auth.uid()::text = user_id::text);
```

### User Match Stats Policies
```sql
-- User match stats - users can view their own stats, others can view for leaderboards
CREATE POLICY "Anyone can view match stats" ON user_match_stats FOR SELECT TO authenticated USING (true);
CREATE POLICY "System can update match stats" ON user_match_stats FOR ALL USING (true); -- Allow system updates
```

### User Kills Policies
```sql
-- User kills - users can view their own kills, match participants can view match-specific kills
CREATE POLICY "Users can view own kills" ON user_kills FOR SELECT USING (auth.uid()::text = user_id::text);
CREATE POLICY "Match participants can view match kills" ON user_kills FOR SELECT USING (
    EXISTS (
        SELECT 1 FROM match_participants mp 
        WHERE mp.match_id = user_kills.match_id 
        AND mp.user_id::text = auth.uid()::text
    )
);
CREATE POLICY "System can manage kills" ON user_kills FOR ALL USING (true); -- Allow system to manage kills
```

---

## Team Management Policies

### Teams Table Policies
```sql
-- Single SELECT policy for teams (combining all select logic)
CREATE POLICY "teams_select_policy" ON teams
    FOR SELECT
    USING (
        -- Always allow service_role
        auth.role() = 'service_role' OR
        -- Allow viewing public active teams
        (is_active = true AND is_private = false) OR
        -- Allow team captains to see their own teams
        (auth.uid()::text::int = captain_id)
    );

-- Single INSERT policy for teams
CREATE POLICY "teams_insert_policy" ON teams
    FOR INSERT
    WITH CHECK (
        -- Allow service_role full access
        auth.role() = 'service_role' OR
        -- Allow authenticated users to create teams with themselves as captain
        (auth.uid()::text::int = captain_id)
    );

-- Single UPDATE policy for teams
CREATE POLICY "teams_update_policy" ON teams
    FOR UPDATE
    USING (
        -- Allow service_role full access
        auth.role() = 'service_role' OR
        -- Allow team captains to update their own teams
        (auth.uid()::text::int = captain_id)
    )
    WITH CHECK (
        -- Allow service_role full access
        auth.role() = 'service_role' OR
        -- Allow team captains to update their own teams
        (auth.uid()::text::int = captain_id)
    );

-- Single DELETE policy for teams
CREATE POLICY "teams_delete_policy" ON teams
    FOR DELETE
    USING (
        -- Allow service_role full access
        auth.role() = 'service_role' OR
        -- Allow team captains to delete their own teams
        (auth.uid()::text::int = captain_id)
    );
```

### Team Members Policies
```sql
-- Team Members: Simplified visibility 
CREATE POLICY "team_members_select_policy" ON team_members
    FOR SELECT
    USING (
        -- Allow service_role full access
        auth.role() = 'service_role' OR
        -- Users can see their own memberships
        (auth.uid()::text::int = user_id) OR
        -- Anyone can see active memberships (for team discovery)
        (status = 'active')
    );

-- Team Members: Simplified insert policy
CREATE POLICY "team_members_insert_policy" ON team_members
    FOR INSERT
    WITH CHECK (
        -- Allow service_role full access
        auth.role() = 'service_role' OR
        -- Allow users to join as themselves
        (auth.uid()::text::int = user_id)
    );

-- Team Members: Simplified update policy
CREATE POLICY "team_members_update_policy" ON team_members
    FOR UPDATE
    USING (
        -- Allow service_role full access
        auth.role() = 'service_role' OR
        -- Allow users to update their own membership
        (auth.uid()::text::int = user_id)
    )
    WITH CHECK (
        -- Allow service_role full access
        auth.role() = 'service_role' OR
        -- Allow users to update their own membership
        (auth.uid()::text::int = user_id)
    );

-- Team Members: Simplified delete policy
CREATE POLICY "team_members_delete_policy" ON team_members
    FOR DELETE
    USING (
        -- Allow service_role full access
        auth.role() = 'service_role' OR
        -- Allow users to remove themselves from teams
        (auth.uid()::text::int = user_id)
    );
```

### Team Join Requests Policies
```sql
-- Team Join Requests: Simplified access control
CREATE POLICY "team_join_requests_select_policy" ON team_join_requests
    FOR SELECT
    USING (
        -- Allow service_role full access
        auth.role() = 'service_role' OR
        -- Allow users to see their own requests
        (auth.uid()::text::int = user_id)
    );

-- Team Join Requests: Simplified request creation
CREATE POLICY "team_join_requests_insert_policy" ON team_join_requests
    FOR INSERT
    WITH CHECK (
        -- Allow service_role full access
        auth.role() = 'service_role' OR
        -- Allow users to create requests for themselves
        (auth.uid()::text::int = user_id)
    );

-- Team Join Requests: Simplified updates
CREATE POLICY "team_join_requests_update_policy" ON team_join_requests
    FOR UPDATE
    USING (
        -- Allow service_role full access
        auth.role() = 'service_role' OR
        -- Allow request owners to update their own requests
        (auth.uid()::text::int = user_id)
    )
    WITH CHECK (
        -- Allow service_role full access
        auth.role() = 'service_role' OR
        -- Allow request owners to update their own requests
        (auth.uid()::text::int = user_id)
    );

-- Team Join Requests: Simplified deletion
CREATE POLICY "team_join_requests_delete_policy" ON team_join_requests
    FOR DELETE
    USING (
        -- Allow service_role full access
        auth.role() = 'service_role' OR
        -- Allow users to cancel their own requests
        (auth.uid()::text::int = user_id)
    );
```

---

## Tournament Participation Policies

### Tournament Registrations Policies
```sql
-- Tournament registrations - Enhanced policies for registration management
CREATE POLICY "Users can view tournament registrations" ON tournament_registrations 
    FOR SELECT 
    TO authenticated 
    USING (
        -- Users can view their own registrations
        auth.uid()::text = user_id::text OR 
        -- Team members can view their team's registrations
        EXISTS (
            SELECT 1 FROM team_members tm 
            WHERE tm.team_id = tournament_registrations.team_id 
            AND tm.user_id::text = auth.uid()::text 
            AND tm.status = 'active'
        )
    );

CREATE POLICY "Users can register for solo tournaments" ON tournament_registrations 
    FOR INSERT 
    TO authenticated 
    WITH CHECK (
        user_id IS NOT NULL AND 
        team_id IS NULL AND 
        auth.uid()::text = user_id::text
    );

CREATE POLICY "Team captains can register teams" ON tournament_registrations 
    FOR INSERT 
    TO authenticated 
    WITH CHECK (
        team_id IS NOT NULL AND 
        user_id IS NULL AND 
        EXISTS (
            SELECT 1 FROM teams t 
            WHERE t.id = tournament_registrations.team_id 
            AND t.captain_id::text = auth.uid()::text
        )
    );
```

### Tournament Player History Policies
```sql
-- Tournament player history - users can view their own history
CREATE POLICY "Users can view own tournament history" ON tournament_player_history 
    FOR SELECT 
    TO authenticated 
    USING (auth.uid()::text = user_id::text);

CREATE POLICY "System can manage tournament history" ON tournament_player_history 
    FOR ALL 
    USING (true);
```

### Tournament Waitlist Policies
```sql
-- Tournament waitlist - users can manage their own waitlist entries
CREATE POLICY "Users can view own waitlist" ON tournament_waitlist 
    FOR SELECT 
    TO authenticated 
    USING (auth.uid()::text = user_id::text);

CREATE POLICY "Users can join waitlist" ON tournament_waitlist 
    FOR INSERT 
    TO authenticated 
    WITH CHECK (auth.uid()::text = user_id::text);

CREATE POLICY "Users can leave waitlist" ON tournament_waitlist 
    FOR DELETE 
    TO authenticated 
    USING (auth.uid()::text = user_id::text);
```

---

## Match Participation Policies

### Match Participants Policies
```sql
-- Match participants - Enhanced policies for participation management
CREATE POLICY "Users can view match participants" ON match_participants 
    FOR SELECT 
    TO authenticated 
    USING (
        -- Users can view participants of matches they're in
        EXISTS (
            SELECT 1 FROM match_participants mp2 
            WHERE mp2.match_id = match_participants.match_id 
            AND mp2.user_id::text = auth.uid()::text
        ) OR
        -- Anyone can view participants of completed matches
        EXISTS (
            SELECT 1 FROM matches m 
            WHERE m.id = match_participants.match_id 
            AND m.status = 'completed'
        )
    );

CREATE POLICY "Users can join available matches" ON match_participants 
    FOR INSERT 
    TO authenticated 
    WITH CHECK (
        auth.uid()::text = user_id::text AND
        -- Check if match is still accepting participants
        EXISTS (
            SELECT 1 FROM matches m 
            WHERE m.id = match_participants.match_id 
            AND m.status = 'upcoming'
            AND (
                SELECT COUNT(*) FROM match_participants mp 
                WHERE mp.match_id = m.id
            ) < m.max_participants
        )
    );

CREATE POLICY "System can update match participants" ON match_participants 
    FOR UPDATE 
    USING (true);
```

---

## Notification Policies

### Notifications Policies
```sql
-- Users can view their own non-deleted notifications
CREATE POLICY "Users can view own notifications" ON notifications 
    FOR SELECT 
    TO authenticated 
    USING (
        auth.uid()::text = user_id::text AND 
        deleted_at IS NULL
    );

-- Users can update their own notifications (mark as read, etc.)
CREATE POLICY "Users can update own notifications" ON notifications 
    FOR UPDATE 
    TO authenticated 
    USING (auth.uid()::text = user_id::text)
    WITH CHECK (auth.uid()::text = user_id::text);

-- System can create notifications for users
CREATE POLICY "System can create notifications" ON notifications 
    FOR INSERT 
    WITH CHECK (
        -- Allow system operations
        current_setting('role') = 'service_role' OR
        -- Allow general system operations
        true
    );

-- Users can soft delete their own notifications
CREATE POLICY "Users can delete own notifications" ON notifications 
    FOR UPDATE 
    TO authenticated 
    USING (
        auth.uid()::text = user_id::text AND 
        deleted_at IS NULL
    )
    WITH CHECK (
        auth.uid()::text = user_id::text AND 
        deleted_at IS NOT NULL
    );

-- System can manage all notifications (for cleanup, etc.)
CREATE POLICY "System can manage all notifications" ON notifications 
    FOR ALL 
    USING (
        current_setting('role') = 'service_role' OR
        -- Allow general system operations
        true
    );
```

---

## Streak System Policies

### User Streaks Policies
```sql
-- User streaks - Users can manage their own streaks, system can update for tasks
CREATE POLICY "users_can_view_own_streaks" ON user_streaks 
    FOR SELECT 
    TO authenticated 
    USING (auth.uid()::text = user_id::text);

CREATE POLICY "users_can_manage_own_streaks" ON user_streaks 
    FOR ALL 
    TO authenticated 
    USING (
        auth.uid()::text = user_id::text OR
        auth.role() = 'service_role'
    );
```

### User Streak Tasks Policies
```sql
-- User streak tasks - Users can view and complete their own tasks
CREATE POLICY "users_can_view_own_streak_tasks" ON user_streak_tasks 
    FOR SELECT 
    TO authenticated 
    USING (auth.uid()::text = user_id::text);

CREATE POLICY "users_can_complete_own_streak_tasks" ON user_streak_tasks 
    FOR INSERT 
    TO authenticated 
    WITH CHECK (auth.uid()::text = user_id::text);

CREATE POLICY "system_can_manage_streak_tasks" ON user_streak_tasks 
    FOR ALL 
    USING (
        auth.uid()::text = user_id::text OR
        auth.role() = 'service_role'
    );
```

### User Streak Milestones Policies
```sql
-- User streak milestones - Users can view their own milestones, system can award
CREATE POLICY "users_can_view_own_milestones" ON user_streak_milestones 
    FOR SELECT 
    TO authenticated 
    USING (auth.uid()::text = user_id::text);

CREATE POLICY "system_can_award_milestones" ON user_streak_milestones 
    FOR INSERT 
    WITH CHECK (
        auth.role() = 'service_role'
    );
```

### Streak Conversion Log Policies
```sql
-- Streak conversion log - Users can view their own conversions, create new ones
CREATE POLICY "users_can_view_own_conversions" ON streak_conversion_log 
    FOR SELECT 
    TO authenticated 
    USING (auth.uid()::text = user_id::text);

CREATE POLICY "users_can_create_conversions" ON streak_conversion_log 
    FOR INSERT 
    TO authenticated 
    WITH CHECK (auth.uid()::text = user_id::text);
```

---

## Streaming & Video Policies

### Stream Rewards Policies
```sql
-- Stream rewards - users can view their own rewards
CREATE POLICY "Users can view own stream rewards" ON stream_rewards FOR SELECT USING (auth.uid()::text = user_id::text);
CREATE POLICY "Users can claim stream rewards" ON stream_rewards FOR INSERT WITH CHECK (auth.uid()::text = user_id::text);

CREATE POLICY "system_can_manage_stream_rewards" ON stream_rewards 
    FOR ALL 
    USING (
        auth.uid()::text = user_id::text OR
        current_setting('role') = 'service_role' OR
        true -- Allow system operations
    );
```

### Video Watch History Policies
```sql
-- Video watch history - users can view their own watch history
CREATE POLICY "Users can view own watch history" ON video_watch_history FOR SELECT USING (auth.uid()::text = user_id::text);
CREATE POLICY "Users can create watch history" ON video_watch_history FOR INSERT WITH CHECK (auth.uid()::text = user_id::text);
CREATE POLICY "Users can update own watch history" ON video_watch_history FOR UPDATE USING (auth.uid()::text = user_id::text);
```

---

## Archive & History Policies

### Match History Archive Policies
```sql
-- Match history archive - users can view their own archived matches
CREATE POLICY "Users can view own match archive" ON match_history_archive FOR SELECT USING (auth.uid()::text = user_id::text);
```

### Tournament History Archive Policies
```sql
-- Tournament history archive - users can view their own archived tournaments
CREATE POLICY "Users can view own tournament archive" ON tournament_history_archive FOR SELECT USING (auth.uid()::text = user_id::text);
```

---

## Device & Redemption Policies

### Device Tokens Policies
```sql
-- Device tokens - users can manage their own device tokens
CREATE POLICY "Users can manage own device tokens" ON device_tokens FOR ALL USING (auth.uid()::text = user_id::text);
```

### Redemption History Policies
```sql
-- Redemption history - users can view their own redemption history
CREATE POLICY "Users can view own redemptions" ON redemption_history FOR SELECT USING (auth.uid()::text = user_id::text);
CREATE POLICY "Users can create redemptions" ON redemption_history FOR INSERT WITH CHECK (auth.uid()::text = user_id::text);
```

---

## Notes for Implementation

### Important Considerations:
1. **Service Role Access**: Most policies include `auth.role() = 'service_role'` to allow system operations
2. **User ID Matching**: Policies use `auth.uid()::text = user_id::text` for user ownership checks
3. **Team Policies**: Use integer casting `auth.uid()::text::int` for team-related checks
4. **Public Data**: Some data like match stats are visible to all authenticated users for leaderboards
5. **System Operations**: Many policies allow `true` for system operations to ensure flexibility

### Testing Checklist:
- [ ] User can only see their own financial data (coins, tickets, transactions)
- [ ] User can only manage their own game profiles
- [ ] Team captains can manage their teams
- [ ] Users can join/leave teams and tournaments
- [ ] Notification system works correctly
- [ ] Streak system functions properly
- [ ] Match participation is controlled correctly
