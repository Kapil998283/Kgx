<?php
// Only start session if one isn't already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('SECURE_ACCESS', true);
require_once '../secure_config.php';
loadSecureConfig('supabase.php');
loadSecureInclude('auth.php');

// Initialize AuthManager and SupabaseClient
$authManager = new AuthManager();
$supabaseClient = new SupabaseClient();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teams Fix Tool</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .success { color: #155724; background: #d4edda; padding: 10px; border-radius: 3px; margin: 10px 0; }
        .error { color: #dc3545; background: #f8d7da; padding: 10px; border-radius: 3px; margin: 10px 0; }
        .warning { color: #856404; background: #fff3cd; padding: 10px; border-radius: 3px; margin: 10px 0; }
        .btn { padding: 10px 15px; background: #007bff; color: white; border: none; border-radius: 3px; cursor: pointer; margin: 5px; }
        .btn-danger { background: #dc3545; }
        .btn:hover { opacity: 0.8; }
    </style>
</head>
<body>

<div class="container">
    <h1>🔧 Teams Fix Tool</h1>
    
    <?php
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        try {
            switch ($action) {
                case 'fix_privacy':
                    // Set all teams to public if they're currently private
                    $private_teams = $supabaseClient->select('teams', '*', ['is_private' => true]);
                    if (count($private_teams) > 0) {
                        $updated = $supabaseClient->update('teams', 
                            ['is_private' => false], 
                            ['is_private' => true]
                        );
                        echo "<div class='success'>✅ Fixed privacy settings for " . count($private_teams) . " teams</div>";
                    } else {
                        echo "<div class='warning'>⚠️ No private teams found</div>";
                    }
                    break;
                    
                case 'fix_activity':
                    // Set all teams to active if they're currently inactive
                    $inactive_teams = $supabaseClient->select('teams', '*', ['is_active' => false]);
                    if (count($inactive_teams) > 0) {
                        $updated = $supabaseClient->update('teams', 
                            ['is_active' => true], 
                            ['is_active' => false]
                        );
                        echo "<div class='success'>✅ Activated " . count($inactive_teams) . " inactive teams</div>";
                    } else {
                        echo "<div class='warning'>⚠️ No inactive teams found</div>";
                    }
                    break;
                    
                case 'fix_member_counts':
                    // Fix member counts for all teams
                    $all_teams = $supabaseClient->select('teams', '*');
                    $fixed_count = 0;
                    
                    foreach ($all_teams as $team) {
                        $actual_members = $supabaseClient->select('team_members', 'id', [
                            'team_id' => $team['id'],
                            'status' => 'active'
                        ]);
                        $actual_count = count($actual_members);
                        
                        if ($team['current_members'] != $actual_count) {
                            $supabaseClient->update('teams', 
                                ['current_members' => $actual_count], 
                                ['id' => $team['id']]
                            );
                            $fixed_count++;
                        }
                    }
                    
                    echo "<div class='success'>✅ Fixed member counts for $fixed_count teams</div>";
                    break;
                    
                case 'check_triggers':
                    // Verify if triggers and functions exist
                    echo "<div class='warning'>⚠️ Trigger check requires direct database access</div>";
                    echo "<div class='warning'>Please run the following SQL in your Supabase dashboard:</div>";
                    echo "<pre>SELECT * FROM information_schema.triggers WHERE trigger_name LIKE '%team%';</pre>";
                    break;
                    
                case 'reset_view':
                    // This would recreate the team_discovery view
                    echo "<div class='warning'>⚠️ View reset requires direct database access</div>";
                    echo "<div class='warning'>Please run the team_discovery view creation SQL from your schema file</div>";
                    break;
                    
                default:
                    echo "<div class='error'>❌ Unknown action</div>";
            }
        } catch (Exception $e) {
            echo "<div class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    ?>
    
    <h3>Quick Fixes</h3>
    
    <form method="post" style="margin-bottom: 20px;">
        <input type="hidden" name="action" value="fix_privacy">
        <button type="submit" class="btn">🔓 Make All Teams Public</button>
        <p><small>Sets all private teams to public so they appear in discovery</small></p>
    </form>
    
    <form method="post" style="margin-bottom: 20px;">
        <input type="hidden" name="action" value="fix_activity">
        <button type="submit" class="btn">✅ Activate All Teams</button>
        <p><small>Sets all inactive teams to active</small></p>
    </form>
    
    <form method="post" style="margin-bottom: 20px;">
        <input type="hidden" name="action" value="fix_member_counts">
        <button type="submit" class="btn">👥 Fix Member Counts</button>
        <p><small>Recalculates and updates current_members for all teams</small></p>
    </form>
    
    <form method="post" style="margin-bottom: 20px;">
        <input type="hidden" name="action" value="check_triggers">
        <button type="submit" class="btn">⚙️ Check Triggers</button>
        <p><small>Provides SQL to check if database triggers are working</small></p>
    </form>
    
    <form method="post" style="margin-bottom: 20px;">
        <input type="hidden" name="action" value="reset_view">
        <button type="submit" class="btn">🔄 Reset Discovery View</button>
        <p><small>Instructions to recreate the team_discovery view</small></p>
    </form>
    
    <h3>Manual Checks</h3>
    
    <?php
    try {
        // Show current team status
        $total_teams = count($supabaseClient->select('teams', 'id'));
        $active_teams = count($supabaseClient->select('teams', 'id', ['is_active' => true]));
        $public_teams = count($supabaseClient->select('teams', 'id', ['is_active' => true, 'is_private' => false]));
        
        echo "<div class='success'>📊 Total teams: $total_teams</div>";
        echo "<div class='success'>✅ Active teams: $active_teams</div>";
        echo "<div class='success'>🔓 Public active teams: $public_teams</div>";
        
        if ($total_teams > 0 && $public_teams === 0) {
            echo "<div class='error'>🚨 No public teams found! This is likely the cause of the visibility issue.</div>";
        }
        
    } catch (Exception $e) {
        echo "<div class='error'>❌ Error checking team status: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    ?>
    
    <h3>Navigation</h3>
    <button class="btn" onclick="window.open('debug_advanced.php', '_blank')">🛠️ Open Debug Tool</button>
    <button class="btn" onclick="window.open('index.php', '_blank')">📋 Open Teams Index</button>
    <button class="btn" onclick="window.open('../', '_blank')">🏠 Go to Home</button>
    
</div>

</body>
</html>
