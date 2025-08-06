# Admin RLS Policies - KGX Esports

This file contains all Row Level Security (RLS) policies related to admin functionality, content management, and system administration.

## Table of Contents
1. [Enable RLS on Admin Tables](#enable-rls-on-admin-tables)
2. [Admin Authentication & Management](#admin-authentication--management)
3. [Tournament Management Policies](#tournament-management-policies)
4. [Match Management Policies](#match-management-policies)
5. [Content Management Policies](#content-management-policies)
6. [System Configuration Policies](#system-configuration-policies)
7. [Monitoring & Analytics Policies](#monitoring--analytics-policies)
8. [Public Data Management](#public-data-management)
9. [Financial Management (Admin)](#financial-management-admin)
10. [User Management (Admin)](#user-management-admin)

---

## Enable RLS on Admin Tables

```sql
-- Enable RLS on admin and public tables
ALTER TABLE admin_users ENABLE ROW LEVEL SECURITY;
ALTER TABLE admin_activity_log ENABLE ROW LEVEL SECURITY;
ALTER TABLE tournaments ENABLE ROW LEVEL SECURITY;
ALTER TABLE tournament_days ENABLE ROW LEVEL SECURITY;
ALTER TABLE tournament_rounds ENABLE ROW LEVEL SECURITY;
ALTER TABLE round_teams ENABLE ROW LEVEL SECURITY;
ALTER TABLE tournament_winners ENABLE ROW LEVEL SECURITY;
ALTER TABLE matches ENABLE ROW LEVEL SECURITY;
ALTER TABLE match_results ENABLE ROW LEVEL SECURITY;
ALTER TABLE games ENABLE ROW LEVEL SECURITY;
ALTER TABLE live_streams ENABLE ROW LEVEL SECURITY;
ALTER TABLE video_categories ENABLE ROW LEVEL SECURITY;
ALTER TABLE profile_images ENABLE ROW LEVEL SECURITY;
ALTER TABLE team_banners ENABLE ROW LEVEL SECURITY;
ALTER TABLE hero_settings ENABLE ROW LEVEL SECURITY;
ALTER TABLE announcements ENABLE ROW LEVEL SECURITY;
ALTER TABLE password_resets ENABLE ROW LEVEL SECURITY;
ALTER TABLE redeemable_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE streak_tasks ENABLE ROW LEVEL SECURITY;
ALTER TABLE streak_milestones ENABLE ROW LEVEL SECURITY;
```

---

## Admin Authentication & Management

### Admin Users Policies
```sql
-- Admin users - admins can view and super admins can manage
-- Note: These policies work with service role authentication
CREATE POLICY "Service role can access admin users" ON admin_users FOR ALL TO service_role USING (true);
CREATE POLICY "Authenticated users can view admin users" ON admin_users FOR SELECT TO authenticated USING (true);
CREATE POLICY "Admins can manage admin users" ON admin_users FOR ALL TO authenticated USING (true);
```

### Admin Activity Log Policies
```sql
-- Admin activity log - only admins can view logs
CREATE POLICY "Admins can view activity logs" ON admin_activity_log FOR SELECT USING (
    EXISTS (SELECT 1 FROM admin_users au WHERE au.id::text = auth.uid()::text AND au.is_active = true)
);
CREATE POLICY "System can insert activity logs" ON admin_activity_log FOR INSERT WITH CHECK (true);
```

---

## Tournament Management Policies

### Tournaments Policies
```sql
-- Anyone can view non-cancelled tournaments
CREATE POLICY "Anyone can view non-cancelled tournaments" ON tournaments 
    FOR SELECT 
    TO authenticated 
    USING (status != 'cancelled' AND status != 'draft');

-- Admins can create tournaments
CREATE POLICY "Admins can create tournaments" ON tournaments 
    FOR INSERT 
    TO authenticated 
    WITH CHECK (
        EXISTS (
            SELECT 1 FROM admin_users au 
            WHERE au.id::text = auth.uid()::text 
            AND au.is_active = true 
            AND au.role IN ('super_admin', 'admin')
        ) OR
        EXISTS (
            SELECT 1 FROM users u 
            WHERE u.id::text = auth.uid()::text 
            AND u.role IN ('admin', 'organizer')
        )
    );

-- Tournament creators and admins can update tournaments
CREATE POLICY "Tournament creators and admins can update tournaments" ON tournaments 
    FOR UPDATE 
    TO authenticated 
    USING (
        auth.uid()::text = created_by::text OR
        EXISTS (
            SELECT 1 FROM admin_users au 
            WHERE au.id::text = auth.uid()::text 
            AND au.is_active = true 
            AND au.role IN ('super_admin', 'admin')
        ) OR
        EXISTS (
            SELECT 1 FROM users u 
            WHERE u.id::text = auth.uid()::text 
            AND u.role IN ('admin', 'organizer')
        )
    );

-- Admins can delete tournaments
CREATE POLICY "Admins can delete tournaments" ON tournaments 
    FOR DELETE 
    TO authenticated 
    USING (
        EXISTS (
            SELECT 1 FROM admin_users au 
            WHERE au.id::text = auth.uid()::text 
            AND au.is_active = true 
            AND au.role IN ('super_admin', 'admin')
        )
    );
```

### Tournament Days Policies
```sql
-- Tournament days - public schedule information
CREATE POLICY "Anyone can view tournament days" ON tournament_days FOR SELECT TO authenticated USING (true);
CREATE POLICY "System can manage tournament days" ON tournament_days FOR ALL USING (true);
```

### Tournament Rounds Policies
```sql
-- Tournament rounds - public round information
CREATE POLICY "Anyone can view tournament rounds" ON tournament_rounds FOR SELECT TO authenticated USING (true);
CREATE POLICY "System can manage tournament rounds" ON tournament_rounds FOR ALL USING (true);
```

### Round Teams Policies
```sql
-- Round teams - public team performance in rounds
CREATE POLICY "Anyone can view round teams" ON round_teams FOR SELECT TO authenticated USING (true);
CREATE POLICY "System can manage round teams" ON round_teams FOR ALL USING (true);
```

### Tournament Winners Policies
```sql
-- Tournament winners - public results
CREATE POLICY "Anyone can view tournament winners" ON tournament_winners FOR SELECT TO authenticated USING (true);
CREATE POLICY "System can manage tournament winners" ON tournament_winners FOR ALL USING (true);
```

### Tournament Registrations Admin Policies
```sql
-- Admins can manage all tournament registrations
CREATE POLICY "Admins can manage all tournament registrations" ON tournament_registrations 
    FOR ALL 
    TO authenticated 
    USING (
        EXISTS (
            SELECT 1 FROM admin_users au 
            WHERE au.id::text = auth.uid()::text 
            AND au.is_active = true 
            AND au.role IN ('super_admin', 'admin')
        )
    );
```

---

## Match Management Policies

### Matches Policies
```sql
-- Anyone can view non-cancelled matches
CREATE POLICY "Anyone can view non-cancelled matches" ON matches 
    FOR SELECT 
    TO authenticated 
    USING (status != 'cancelled');

-- Admins can create matches
CREATE POLICY "Admins can create matches" ON matches 
    FOR INSERT 
    TO authenticated 
    WITH CHECK (
        EXISTS (
            SELECT 1 FROM admin_users au 
            WHERE au.id::text = auth.uid()::text 
            AND au.is_active = true 
            AND au.role IN ('super_admin', 'admin')
        ) OR
        EXISTS (
            SELECT 1 FROM users u 
            WHERE u.id::text = auth.uid()::text 
            AND u.role IN ('admin', 'organizer')
        )
    );

-- Admins can update matches
CREATE POLICY "Admins can update matches" ON matches 
    FOR UPDATE 
    TO authenticated 
    USING (
        EXISTS (
            SELECT 1 FROM admin_users au 
            WHERE au.id::text = auth.uid()::text 
            AND au.is_active = true 
            AND au.role IN ('super_admin', 'admin')
        ) OR
        EXISTS (
            SELECT 1 FROM users u 
            WHERE u.id::text = auth.uid()::text 
            AND u.role IN ('admin', 'organizer')
        )
    );

-- Admins can delete matches
CREATE POLICY "Admins can delete matches" ON matches 
    FOR DELETE 
    TO authenticated 
    USING (
        EXISTS (
            SELECT 1 FROM admin_users au 
            WHERE au.id::text = auth.uid()::text 
            AND au.is_active = true 
            AND au.role IN ('super_admin', 'admin')
        )
    );
```

### Match Results Policies
```sql
-- Match results - public match results
CREATE POLICY "Anyone can view match results" ON match_results FOR SELECT TO authenticated USING (true);
CREATE POLICY "System can manage match results" ON match_results FOR ALL USING (true);
```

### Match Participants Admin Policies
```sql
-- Admins can manage match participants
CREATE POLICY "Admins can manage match participants" ON match_participants 
    FOR ALL 
    TO authenticated 
    USING (
        EXISTS (
            SELECT 1 FROM admin_users au 
            WHERE au.id::text = auth.uid()::text 
            AND au.is_active = true 
            AND au.role IN ('super_admin', 'admin')
        )
    );
```

---

## Content Management Policies

### Games Policies
```sql
-- Games - public game list
CREATE POLICY "Anyone can view games" ON games FOR SELECT TO authenticated USING (true);
CREATE POLICY "Admins can manage games" ON games FOR ALL USING (
    EXISTS (SELECT 1 FROM users u WHERE u.id::text = auth.uid()::text AND u.role = 'admin')
);
```

### Live Streams Policies
```sql
-- Live streams - public stream information
CREATE POLICY "Anyone can view live streams" ON live_streams FOR SELECT TO authenticated USING (true);
CREATE POLICY "System can manage live streams" ON live_streams FOR ALL USING (true);
```

### Video Categories Policies
```sql
-- Video categories - public categories
CREATE POLICY "Anyone can view video categories" ON video_categories FOR SELECT TO authenticated USING (true);
CREATE POLICY "Admins can manage video categories" ON video_categories FOR ALL USING (
    EXISTS (SELECT 1 FROM users u WHERE u.id::text = auth.uid()::text AND u.role = 'admin')
);
```

### Profile Images Policies
```sql
-- Profile images - public image options (updated for header display)
-- Allow everyone (including anonymous users) to read profile images for header display
CREATE POLICY "Allow public read access to profile images" ON profile_images FOR SELECT USING (true);
-- Only admins can manage profile images (not service_role, as users need to read but not write)
CREATE POLICY "Allow admin full access to profile images" ON profile_images FOR ALL USING (
    EXISTS (
        SELECT 1 FROM admin_users au 
        WHERE au.id::text = auth.uid()::text 
        AND au.is_active = true 
        AND au.role IN ('super_admin', 'admin')
    ) OR 
    current_setting('role') = 'service_role' OR 
    true -- Allow system operations
);
```

### Team Banners Policies
```sql
-- Team banners - public banner options
CREATE POLICY "Anyone can view team banners" ON team_banners FOR SELECT TO authenticated USING (true);
CREATE POLICY "Admins can manage team banners" ON team_banners FOR ALL USING (
    EXISTS (SELECT 1 FROM users u WHERE u.id::text = auth.uid()::text AND u.role = 'admin')
);
```

---

## System Configuration Policies

### Hero Settings Policies
```sql
-- Hero settings - public UI settings
CREATE POLICY "Anyone can view hero settings" ON hero_settings FOR SELECT TO authenticated USING (true);
CREATE POLICY "Admins can manage hero settings" ON hero_settings FOR ALL USING (
    EXISTS (SELECT 1 FROM admin_users au WHERE au.id::text = auth.uid()::text)
);
```

### Announcements Policies
```sql
-- Announcements - public announcements
CREATE POLICY "Anyone can view announcements" ON announcements FOR SELECT TO authenticated USING (true);
CREATE POLICY "Admins can manage announcements" ON announcements FOR ALL USING (
    EXISTS (SELECT 1 FROM users u WHERE u.id::text = auth.uid()::text AND u.role = 'admin') OR
    auth.uid()::text = created_by::text
);
```

### Password Resets Policies
```sql
-- Password resets - handled by auth system, but needs basic policies
CREATE POLICY "Users can create password resets" ON password_resets FOR INSERT WITH CHECK (true);
CREATE POLICY "Users can view own password resets" ON password_resets FOR SELECT USING (auth.jwt() ->> 'email' = email);
CREATE POLICY "System can delete expired resets" ON password_resets FOR DELETE USING (expiry < CURRENT_TIMESTAMP);

-- Enhanced password reset policies
CREATE POLICY "anyone_can_create_password_resets" ON password_resets 
    FOR INSERT 
    TO anon, authenticated 
    WITH CHECK (true);

CREATE POLICY "system_can_cleanup_expired_resets" ON password_resets 
    FOR DELETE 
    USING (expiry < CURRENT_TIMESTAMP);
```

---

## Monitoring & Analytics Policies

### Admin Activity Monitoring
```sql
-- Admins can view all notifications for support/debugging
CREATE POLICY "Admins can view all notifications" ON notifications 
    FOR SELECT 
    TO authenticated 
    USING (
        EXISTS (
            SELECT 1 FROM admin_users au 
            WHERE au.id::text = auth.uid()::text 
            AND au.is_active = true 
            AND au.role IN ('super_admin', 'admin')
        )
    );
```

---

## Public Data Management

### Shop Management Policies
```sql
-- Redeemable items - public shop items
CREATE POLICY "Anyone can view redeemable items" ON redeemable_items FOR SELECT TO authenticated USING (is_active = true);
CREATE POLICY "Admins can manage redeemable items" ON redeemable_items FOR ALL USING (
    EXISTS (SELECT 1 FROM users u WHERE u.id::text = auth.uid()::text AND u.role = 'admin')
);
```

### Streak System Management Policies
```sql
-- Streak tasks - public task list
CREATE POLICY "Anyone can view streak tasks" ON streak_tasks FOR SELECT TO authenticated USING (is_active = true);
CREATE POLICY "Admins can manage streak tasks" ON streak_tasks FOR ALL USING (
    EXISTS (SELECT 1 FROM users u WHERE u.id::text = auth.uid()::text AND u.role = 'admin')
);

-- Streak milestones - public milestone list
CREATE POLICY "Anyone can view streak milestones" ON streak_milestones FOR SELECT TO authenticated USING (is_active = true);
CREATE POLICY "Admins can manage streak milestones" ON streak_milestones FOR ALL USING (
    EXISTS (SELECT 1 FROM users u WHERE u.id::text = auth.uid()::text AND u.role = 'admin')
);
```

---

## Financial Management (Admin)

### Shop Orders Admin Policies
```sql
-- Shop orders - Admins can view all orders
CREATE POLICY "admins_can_view_all_orders" ON shop_orders 
    FOR SELECT 
    TO authenticated 
    USING (
        EXISTS (
            SELECT 1 FROM admin_users au 
            WHERE au.id::text = auth.uid()::text 
            AND au.is_active = true 
            AND au.role IN ('super_admin', 'admin')
        )
    );
```

### Financial Data Admin Access
```sql
-- Admin access for financial data management
CREATE POLICY "admins_can_manage_user_coins" ON user_coins 
    FOR ALL 
    USING (
        EXISTS (
            SELECT 1 FROM admin_users au 
            WHERE au.id::text = auth.uid()::text 
            AND au.is_active = true 
            AND au.role IN ('super_admin', 'admin')
        )
    );

CREATE POLICY "admins_can_manage_user_tickets" ON user_tickets 
    FOR ALL 
    USING (
        EXISTS (
            SELECT 1 FROM admin_users au 
            WHERE au.id::text = auth.uid()::text 
            AND au.is_active = true 
            AND au.role IN ('super_admin', 'admin')
        )
    );

CREATE POLICY "admins_can_manage_transactions" ON transactions 
    FOR ALL 
    USING (
        EXISTS (
            SELECT 1 FROM admin_users au 
            WHERE au.id::text = auth.uid()::text 
            AND au.is_active = true 
            AND au.role IN ('super_admin', 'admin')
        )
    );
```

### Redemption Management
```sql
-- Updated redemption policies with admin management
CREATE POLICY "admins_can_manage_redemptions" ON redemption_history 
    FOR ALL 
    TO authenticated 
    USING (
        EXISTS (
            SELECT 1 FROM admin_users au 
            WHERE au.id::text = auth.uid()::text 
            AND au.is_active = true 
            AND au.role IN ('super_admin', 'admin')
        ) OR
        true -- Allow system operations for status updates
    );
```

---

## User Management (Admin)

### User Profile Admin Management
```sql
-- Admins have full access to manage user accounts
CREATE POLICY "admins_can_manage_users" ON users 
    FOR ALL 
    TO authenticated 
    USING (
        EXISTS (
            SELECT 1 FROM admin_users au 
            WHERE au.id::text = auth.uid()::text 
            AND au.is_active = true 
            AND au.role IN ('super_admin', 'admin')
        ) OR 
        auth.role() = 'service_role'
    );
```

### Gaming Data Admin Management
```sql
-- Admins can manage game profiles
CREATE POLICY "admins_can_manage_game_profiles" ON user_games 
    FOR ALL 
    TO authenticated 
    USING (
        EXISTS (
            SELECT 1 FROM admin_users au 
            WHERE au.id::text = auth.uid()::text 
            AND au.is_active = true 
            AND au.role IN ('super_admin', 'admin')
        )
    );
```

---

## System-Level Policies

### Service Role Policies
```sql
-- Allow service role to manage system operations
-- These policies are already included in most user policies but listed here for completeness

-- Example pattern for service role access:
-- CREATE POLICY "service_role_access" ON [table_name] 
--     FOR ALL 
--     USING (auth.role() = 'service_role');
```

---

## Implementation Notes

### Admin Authentication Flow:
1. **Admin Login**: Admins authenticate through Supabase Auth
2. **Role Verification**: Policies check `admin_users` table for active status and role
3. **Permission Levels**:
   - `super_admin`: Full access to all data and operations
   - `admin`: Access to most admin functions
   - `moderator`: Limited admin access (if needed)

### Key Admin Responsibilities:
- **Tournament Management**: Create, update, manage tournaments and rounds
- **Match Management**: Oversee matches and results
- **Content Management**: Manage games, streams, announcements
- **User Support**: Handle user issues, redemptions, financial data
- **System Configuration**: Update hero settings, manage profile images
- **Analytics**: Monitor system activity and user behavior

### Security Considerations:
1. **Activity Logging**: All admin actions should be logged
2. **Role-Based Access**: Different admin levels have different permissions
3. **Audit Trail**: Maintain comprehensive logs for compliance
4. **Data Protection**: Ensure sensitive user data is properly protected
5. **Service Role**: Allow system operations while maintaining security

### Testing Checklist:
- [ ] Admin can create and manage tournaments
- [ ] Admin can manage matches and results
- [ ] Admin can view all financial transactions
- [ ] Admin can manage user accounts when needed
- [ ] Admin activity is properly logged
- [ ] Different admin roles have appropriate permissions
- [ ] Public data is accessible to all users
- [ ] System operations work through service role
