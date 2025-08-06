# Development Mode Setup Guide

This guide will help you disable RLS (Row Level Security) completely during development for easier testing and debugging.

## Step 1: Disable All RLS Policies

1. **Open your Supabase Dashboard**
2. **Go to SQL Editor**
3. **Copy and paste the contents of `disable-rls-for-development.sql`**
4. **Run the script**

This will:
- ✅ Drop all existing RLS policies
- ✅ Disable RLS on all tables
- ✅ Verify the changes were successful

## Step 2: Update Your Code (Already Done!)

Your SupabaseClient is already set up correctly:

```php
// For development (no RLS) - use regular client
$supabaseClient = new SupabaseClient();

// For production (with RLS) - you can use service role if needed
$supabaseClient = new SupabaseClient(true);
```

Since RLS is disabled, both will work the same way during development.

## Benefits of This Approach

### ✅ **Development Benefits:**
- No authentication/authorization headaches
- Easy database access for testing
- No UUID vs integer ID conflicts
- Simplified debugging
- Focus on functionality first
- Test with any user data easily

### ✅ **You Can Still:**
- Test user sessions and login/logout
- Work with different user accounts
- Test all your application features
- Use your existing authentication system

### ✅ **Your Application Will:**
- Show tickets and coins correctly
- Display all user data properly
- Work without RLS-related errors
- Allow you to focus on features

## Development Workflow

1. **Build your features** without worrying about database permissions
2. **Test thoroughly** with different user scenarios
3. **When ready for production**, create proper RLS policies
4. **Re-enable RLS** with production-ready security

## Files Created for This Setup

- `disable-rls-for-development.sql` - Script to disable all RLS
- `test-user-data.php` - Debug tool to test user data access
- `debug-and-fix-user-data.sql` - Fix missing user records (may not be needed now)

## When You're Ready for Production

1. **Design proper RLS policies** based on your final application structure
2. **Create a new RLS setup script** with correct policies
3. **Test thoroughly** with the new policies
4. **Never use development mode in production!**

## Current Status

After running the `disable-rls-for-development.sql` script:
- ✅ All RLS policies are removed
- ✅ RLS is disabled on all tables
- ✅ Your application can access all data freely
- ✅ You can focus on building features

This is the recommended approach for development. You can always re-implement proper security later when you're ready for production deployment.
