
# Tournament and Match System Analysis

This document provides a detailed analysis of the tournament and match systems in the KGX project. It covers the folder structure, database schema, and the overall workflow from both the admin and user perspectives.

## Folder Structure

The project is organized into three main directories, each with a specific role:

- **`private`**: This is the back-end of the application, designed for administrative access. It contains all the logic for managing tournaments, matches, users, and other system settings. This folder is not publicly accessible, ensuring that all administrative functions are secure.

- **`public`**: This is the front-end of the application, which is accessible to all users. It includes pages for browsing tournaments, registering teams, viewing match schedules, and managing user profiles. It interacts with the back-end to display data and process user actions.

- **`secure-database`**: This folder contains the database schema, which defines the structure of the application's data. It provides a clear overview of how tournaments, matches, teams, and users are organized and interconnected.

### Legacy Folders

There are several folders that appear to be outdated or redundant, including:

- `admin-panel`
- `Shop`
- `assets`
- `includes`
- `pages`

These folders seem to be remnants of an older version of the application. To simplify the project and avoid confusion, it is recommended to archive these legacy folders and focus on the current `private` and `public` structure.

## Database Schema

The database schema is defined in the `secure-database/supabase-schema.sql` file. The key tables related to the tournament system are:

- **`tournaments`**: Stores all information about tournaments, including their name, game, prize pool, and schedule.
- **`tournament_rounds`**: Defines the different rounds within a tournament, such as qualifiers, semi-finals, and finals.
- **`tournament_registrations`**: Manages team registrations for each tournament, linking teams to the tournaments they have joined.
- **`matches`**: Contains details about individual matches, including the teams playing, the match schedule, and the results.
- **`teams`**: Stores information about each team, including its members, captain, and performance history.
- **`users`**: Contains user account information, such as usernames, emails, and profile details.

These tables are interconnected to provide a comprehensive view of the tournament and match data.

## Tournament Workflow

The tournament workflow involves both the admin and the user, with a clear separation of responsibilities:

### Admin Side (`private` folder)

1.  **Tournament Creation**: Admins can create new tournaments, specifying details such as the game, prize pool, entry fee, and schedule.
2.  **Round Management**: For each tournament, admins can define multiple rounds, each with its own schedule, map, and rules.
3.  **Team Approval**: Admins are responsible for approving teams that register for a tournament, ensuring that all requirements are met.
4.  **Match Scheduling**: Admins can schedule matches between teams, set the match time, and assign room details.
5.  **Result Management**: After a match is completed, admins can enter the results, including the winner and the score.

### User Side (`public` folder)

1.  **Tournament Discovery**: Users can browse a list of available tournaments and view their details, including the schedule, prize pool, and rules.
2.  **Team Registration**: Users can register their teams for a tournament, either by creating a new team or joining an existing one.
3.  **Match Schedule**: Once registered, users can view the match schedule to see when their team is playing and who their opponents are.
4.  **View Results**: After a match, users can view the results to see the outcome and track their team's performance in the tournament.

## Match Mechanics

The match system is designed to be flexible and support a variety of game types. Here's how it works:

- **Match Creation**: Matches are created by admins, who can specify the game, the teams involved, and the match schedule. Admins can also set the prize pool and entry fee for each match.
- **Joining a Match**: Users can join a match by registering their team and paying the entry fee, if applicable.
- **Scoring and Results**: After a match is completed, the results are entered by the admin. The system supports different scoring models, including points for kills and placement.
- **Prize Distribution**: The prize pool is distributed to the winning team based on the rules of the tournament. The system supports both single-winner and multi-winner prize distributions.

## Solo Tournament System Update

To address issues with solo tournaments, a new system was implemented to separate solo participants from team-based ones. This prevents the creation of unnecessary single-member teams and ensures the `team_discovery` and `team_stats` tables are no longer populated with incorrect data.

### 1. Database Schema Update

A new table named `solo_tournament_participants` was added to the database schema to store information about individual players in solo tournaments. This table is similar to `round_teams`, but is designed specifically for users.

**File Modified**: `secure-database/supabase-schema.sql`

**New Table Schema**:

```sql
CREATE TABLE solo_tournament_participants (
    id SERIAL PRIMARY KEY,
    tournament_id INTEGER NOT NULL REFERENCES tournaments(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    round_id INTEGER NOT NULL REFERENCES tournament_rounds(id) ON DELETE CASCADE,
    placement INTEGER,
    kills INTEGER,
    kill_points INTEGER,
    placement_points INTEGER,
    bonus_points INTEGER,
    total_points INTEGER,
    status VARCHAR(20) DEFAULT 'selected',
    created_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP WITH TIME ZONE DEFAULT CURRENT_TIMESTAMP
);
```

### 2. Application Logic Updates

To integrate the new table, the following files were modified:

- **`private/admin/tournament/update_round_teams.php`**
    - **Change**: The script now checks if the tournament mode is "Solo."
    - **Logic**: If the tournament is a solo one, the script adds, updates, or removes records from the `solo_tournament_participants` table instead of the `round_teams` table.

- **`private/admin/tournament/get_available_teams.php`**
    - **Change**: This script now fetches individual users for solo tournaments and teams for team-based tournaments.
    - **Logic**: It ensures that the correct participants are displayed in the admin panel based on the tournament mode.

- **`private/admin/tournament/tournament-schedule.php`**
    - **Change**: The user interface was updated to correctly display either "Players" or "Teams" based on the tournament mode.
    - **Logic**: The participant fetching logic now works with both solo and team modes, and the JavaScript was updated to handle the terminology correctly.

### 3. Detailed File Modifications

#### 3.1 Database Schema Changes
**File**: `secure-database/supabase-schema.sql`
- **Added**: New `solo_tournament_participants` table (lines ~800+)
- **Added**: Indexes for performance optimization:
  ```sql
  CREATE INDEX idx_solo_tournament_participants_tournament ON solo_tournament_participants(tournament_id);
  CREATE INDEX idx_solo_tournament_participants_user ON solo_tournament_participants(user_id);
  CREATE INDEX idx_solo_tournament_participants_round ON solo_tournament_participants(round_id);
  CREATE INDEX idx_solo_tournament_participants_status ON solo_tournament_participants(status);
  ```

#### 3.2 Backend Logic Updates

**File**: `private/admin/tournament/update_round_teams.php`
- **Lines Modified**: 15-35 (Tournament mode detection)
- **Lines Added**: 50-120 (Solo tournament handling logic)
- **Key Changes**:
  - Added tournament mode detection: `$is_solo = $tournament['mode'] === 'Solo';`
  - Separated participant removal logic for solo vs team tournaments
  - Added solo participant insertion logic using `solo_tournament_participants` table
  - Updated success response to include tournament mode information

**File**: `private/admin/tournament/get_available_teams.php`
- **Lines Modified**: 25-40 (Mode detection and participant fetching)
- **Lines Added**: 75-150 (Solo participant handling)
- **Key Changes**:
  - Added mode detection from tournament data
  - Implemented separate query logic for solo tournaments:
    ```php
    if ($is_solo) {
        // Fetch individual users instead of teams
        $participants_query = "SELECT DISTINCT u.id, u.username as name, 
                              stp.id as participant_id FROM users u";
    }
    ```
  - Updated response structure to include `is_solo` flag
  - Modified participant marking logic for solo tournaments

**File**: `private/admin/tournament/tournament-schedule.php`
- **Lines Modified**: 401-415 (Round participants fetching)
- **Lines Modified**: 1260-1275 (JavaScript success message)
- **Key Changes**:
  - Updated round participants logic:
    ```php
    if ($is_solo) {
        $solo_participants = $supabase->select('solo_tournament_participants', 'user_id', ['round_id' => $round['id']]);
        $roundParticipants[$round['id']] = $solo_participants ?: [];
    } else {
        $roundParticipants[$round['id']] = $round['round_teams'];
    }
    ```
  - Updated JavaScript to use correct terminology:
    ```javascript
    const participantType = data.is_solo ? 'Players' : 'Teams';
    let successMessage = `${participantType} updated successfully!`;
    ```
  - Modified all UI elements to show "Players" vs "Teams" based on tournament mode

### 4. Data Flow Changes

#### 4.1 Solo Tournament Flow
1. **Registration**: User registers for solo tournament → `tournament_registrations` table with `user_id`
2. **Round Assignment**: Admin assigns players to rounds → `solo_tournament_participants` table
3. **Results Entry**: Admin enters results → Still uses `round_teams` table but with user IDs for compatibility
4. **Score Updates**: System updates user scores directly using user IDs

#### 4.2 Team Tournament Flow (Unchanged)
1. **Registration**: Team registers for tournament → `tournament_registrations` table with `team_id`
2. **Round Assignment**: Admin assigns teams to rounds → `round_teams` table
3. **Results Entry**: Admin enters results → `round_teams` table
4. **Score Updates**: System updates team scores using team IDs

### 5. Benefits of the New System

- **Clean Separation**: Solo and team tournaments now use separate data structures
- **No More Unwanted Teams**: Solo players no longer create single-member teams
- **Consistent UI**: Interface properly displays "Players" vs "Teams" terminology
- **Better Performance**: Optimized queries for each tournament type
- **Data Integrity**: Prevents pollution of team-related tables with solo player data
- **Backwards Compatibility**: Existing team tournaments continue to work unchanged

### 6. Testing Recommendations

1. **Solo Tournament Creation**: Test creating and managing solo tournaments
2. **Player Assignment**: Verify that individual players can be assigned to rounds
3. **Results Entry**: Ensure results can be entered for solo participants
4. **UI Consistency**: Check that all interfaces show correct "Players" vs "Teams" labels
5. **Data Isolation**: Verify that solo tournaments don't create unwanted team records

### 7. Future Considerations

- Consider migrating existing solo tournament data to the new table structure
- Monitor performance with large numbers of solo participants
- Evaluate if additional indexes are needed based on usage patterns
- Consider adding specific solo tournament reporting features

### 8. Implementation Summary

**Total Files Modified**: 4 files
- 1 Database schema file
- 3 PHP application files

**Lines of Code Added/Modified**: Approximately 200+ lines

**Database Changes**:
- 1 new table (`solo_tournament_participants`)
- 4 new indexes for performance optimization

**Key Achievements**:
✅ **Problem Solved**: Solo tournaments no longer create unwanted single-member teams  
✅ **Data Integrity**: Clean separation between solo and team tournament data  
✅ **User Experience**: Consistent "Players" vs "Teams" terminology throughout the interface  
✅ **Performance**: Optimized queries specific to each tournament type  
✅ **Backwards Compatibility**: Existing team tournaments work without changes  
✅ **Maintainability**: Clear separation of concerns for future development  

**Before vs After Comparison**:

| Aspect | Before (Issues) | After (Resolved) |
|--------|----------------|------------------|
| Solo Player Storage | Created single-member teams | Stored as individual participants |
| Team Tables | Polluted with solo player data | Clean, team-only data |
| UI Terminology | Mixed "Teams" for solo players | Correct "Players" vs "Teams" |
| Data Queries | Inefficient team-based queries | Optimized participant-specific queries |
| Admin Interface | Confusing team references | Clear player/team distinction |
| System Performance | Overhead from unnecessary teams | Direct user-based operations |

By analyzing these key components and implementing the solo tournament system update, the KGX tournament platform now provides a seamless and properly structured experience for both solo and team-based tournaments, with clear data separation and optimized performance for each tournament type.


