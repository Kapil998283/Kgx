# Tournament Testing System

This comprehensive testing system helps you test tournament functionality with realistic fake users and registrations.

## 🚀 Quick Start

1. **Access the Test Manager**: Navigate to `private/admin/tournament/testing/test-manager.php`
2. **Generate Fake Users**: Create 20-50 test users with complete profiles
3. **Register Users**: Register these users for tournaments with different statuses
4. **Test Admin Functions**: Use the admin panel to approve/reject registrations
5. **Test Tournament Formats**: Create tournaments with different formats and test the workflows

## 📁 Files Overview

### Main Interface
- **`test-manager.php`** - Main testing interface with GUI controls

### Core Utilities  
- **`fake-user-generator.php`** - Creates realistic fake users with game profiles (Enhanced v2 with better error handling)
- **`tournament-registrar.php`** - Registers fake users for tournaments
- **`bulk-registration-manager.php`** - Bulk approve/reject/reset registrations
- **`cleanup-test-users.php`** - Removes all test data safely
- **`get-test-stats.php`** - Real-time statistics for the interface

## 🎯 Features

### 1. Fake User Generation (Enhanced)
- Creates realistic usernames (Player001OP, Gamer023YT, etc.)
- Generates valid BGMI/PUBG UIDs and usernames
- Assigns 1000 tickets and 1000 coins to each user
- Creates complete game profiles with levels
- Uses `@testuser.com` domain for easy identification
- **Enhanced Error Handling**: Detailed logging and error reporting
- **Smart Retry Logic**: Handles database connection issues gracefully
- **Flexible User Creation**: Adapts to different table structures
- **Debug Information**: Provides comprehensive creation feedback

### 2. Tournament Registration
- Registers users for any tournament format
- Supports different initial statuses (pending/approved/rejected)
- Handles entry fee deduction automatically
- Respects tournament capacity limits
- Updates tournament participant counts

### 3. Bulk Management
- **Approve All**: Convert all pending registrations to approved
- **Reject All**: Convert all pending registrations to rejected  
- **Reset All**: Convert all registrations back to pending
- Works on specific tournaments

### 4. Testing Scenarios

#### Scenario A: Basic Tournament Flow
1. Generate 30 fake users
2. Create a Solo BGMI tournament (entry fee: 10 tickets)
3. Register 20 users with "pending" status
4. Use admin panel to approve users one by one
5. Test the registration management interface

#### Scenario B: Different Tournament Formats
1. Create tournaments with different formats:
   - **Elimination**: Traditional bracket format
   - **Group Stage**: BMPS-style with groups
   - **Weekly Finals**: Multi-week progression
   - **Custom Lobby**: Points-based system
2. Register users for each format
3. Test format-specific management pages

#### Scenario C: Approval Workflow Testing
1. Register 30 users with "pending" status
2. Use bulk approve for 20 users
3. Use bulk reject for 5 users  
4. Leave 5 users as pending
5. Test the admin approval interface behavior

#### Scenario D: Capacity Testing
1. Create a tournament with max 25 participants
2. Register 30 users (5 should fail due to capacity)
3. Test capacity enforcement logic
4. Test waiting list functionality if implemented

## 🛠️ Usage Examples

### Generate Users via URL
```
GET testing/fake-user-generator.php?count=25&game=BGMI
```

### Register Users for Tournament
```
GET testing/tournament-registrar.php?tournament_id=1&count=20&status=pending
```

### Bulk Update Registrations
```
GET testing/bulk-registration-manager.php?tournament_id=1&action=approve
```

### Get Statistics
```
GET testing/get-test-stats.php
```

## 📊 Test Data Structure

### Generated Users
```php
[
    'id' => 123,
    'username' => 'Elite015King',
    'email' => 'elite015king@testuser.com',
    'game_username' => 'YTElite015King',
    'game_uid' => 5471829364,
    'game_level' => 67,
    'tickets' => 1000,
    'coins' => 1000
]
```

### Tournament Registration
```php
[
    'tournament_id' => 1,
    'user_id' => 123,
    'status' => 'pending', // pending|approved|rejected
    'registration_date' => '2025-01-06 10:30:00'
]
```

## 🔧 Testing Checklist

### Pre-Testing Setup
- [ ] Admin authentication is working
- [ ] Supabase connection is active
- [ ] Tournament system is functional
- [ ] No existing test data conflicts

### Basic Testing
- [ ] Generate 30 fake users successfully
- [ ] Create a test tournament
- [ ] Register 20 users for the tournament
- [ ] View registrations in admin panel
- [ ] Approve 10 users individually
- [ ] Reject 5 users individually
- [ ] Leave 5 users as pending

### Advanced Testing
- [ ] Test different tournament formats
- [ ] Test bulk operations (approve/reject/reset)
- [ ] Test capacity limits
- [ ] Test entry fee deduction
- [ ] Test tournament statistics
- [ ] Test user cleanup

### Error Handling
- [ ] Register users with insufficient tickets
- [ ] Register for non-existent tournament
- [ ] Bulk operations on empty tournament
- [ ] Invalid tournament format handling

## 🚨 Important Notes

### Safety Features
- **Test Users Only**: All operations target users with `@testuser.com` emails
- **Admin Access Required**: All utilities require admin authentication
- **Safe Cleanup**: Cleanup process respects foreign key constraints
- **Transaction Safety**: Operations are atomic where possible

### Performance Considerations
- **Batch Limits**: Maximum 50 users per generation/registration
- **Rate Limiting**: No built-in rate limiting (add if needed)
- **Memory Usage**: Large operations use iteration to conserve memory

### Database Impact
- **Foreign Keys**: Cleanup respects all database constraints  
- **Indexes**: Operations may impact database performance temporarily
- **Logging**: All errors are logged for debugging

## 🔍 Troubleshooting

### Common Issues

1. **"No tournament found"**
   - Verify tournament ID exists
   - Check tournament status
   - Ensure admin access

2. **"Insufficient tickets"**
   - Run cleanup and regenerate users
   - Verify ticket allocation in fake user generator
   - Check entry fee requirements

3. **"Registration failed"**
   - Check tournament capacity
   - Verify user game profile exists
   - Check tournament status (must be 'registration_open')

4. **"Permission denied"**
   - Ensure admin authentication
   - Check file permissions
   - Verify secure access configuration

### Debug Mode
Enable debug mode by setting error reporting in individual files:
```php
ini_set('display_errors', 1);
error_reporting(E_ALL);
```

## 🎮 Tournament Format Testing

### Elimination Format
- Test bracket generation
- Test advancement logic  
- Test winner determination

### Group Stage Format
- Test group assignments
- Test qualification criteria
- Test finals advancement

### Weekly Finals Format
- Test weekly progression
- Test elimination rounds
- Test cumulative scoring

### Custom Lobby Format
- Test points accumulation
- Test leaderboard updates
- Test lobby management

## 🧹 Cleanup Process

The cleanup utility follows this safe order:
1. Delete tournament registrations
2. Delete user tickets  
3. Delete user coins
4. Delete user games
5. Delete user accounts

This order respects foreign key constraints and prevents orphaned data.

## 📈 Monitoring

Use the real-time statistics to monitor:
- Total test users created
- Total registrations made
- Approved registrations
- Test coverage across tournaments

## 🤝 Contributing

When adding new test scenarios:
1. Follow the existing error handling patterns
2. Use the admin authentication system
3. Include proper JSON responses
4. Add appropriate logging
5. Test edge cases thoroughly

## 📝 Change Log

- **v1.0**: Initial testing system with full CRUD operations
- Added comprehensive user generation with game profiles
- Added bulk registration management
- Added tournament format testing support
- Added safe cleanup procedures
- **v1.1**: Enhanced fake user generator with improved error handling and debugging
- Cleaned up testing folder structure (removed redundant files)
- Improved documentation with enhanced features section
- **v1.2**: Fixed database schema compatibility issues
- Corrected field names to match PostgreSQL Supabase schema
- Fixed password field name ('password' not 'password_hash')
- Added proper phone number generation and ticket_balance initialization
- Now generates users that perfectly match the actual database structure
