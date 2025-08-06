<?php
// Advanced Team Debugger with Join Request Analysis

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Define secure access and load necessary files
define('SECURE_ACCESS', true);
require_once '../secure_config.php';
loadSecureConfig('supabase.php');
loadSecureInclude('auth.php');

// Initialize AuthManager and SupabaseClient
$authManager = new AuthManager();
$supabaseClient = new SupabaseClient(); // Use anon key for user side

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teams Debug Tool</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .debug-container { max-width: 1200px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .debug-section { margin-bottom: 30px; padding: 20px; border: 1px solid #ddd; border-radius: 5px; }
        .debug-section h3 { margin-top: 0; color: #333; background: #f8f9fa; padding: 10px; border-radius: 3px; }
        .error { color: #dc3545; background: #f8d7da; padding: 10px; border-radius: 3px; margin: 10px 0; }
        .success { color: #155724; background: #d4edda; padding: 10px; border-radius: 3px; margin: 10px 0; }
        .warning { color: #856404; background: #fff3cd; padding: 10px; border-radius: 3px; margin: 10px 0; }
        .info { color: #0c5460; background: #d1ecf1; padding: 10px; border-radius: 3px; margin: 10px 0; }
        pre { background: #f8f9fa; padding: 15px; border-radius: 3px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .status-active { color: green; font-weight: bold; }
        .status-inactive { color: red; font-weight: bold; }
        .btn { padding: 10px 15px; background: #007bff; color: white; border: none; border-radius: 3px; cursor: pointer; margin: 5px; }
        .btn:hover { background: #0056b3; }
    </style>
</head>
<body>

<div class="debug-container">
    <h1>🛠️ Advanced Teams Debug Tool</h1>
    
    <?php
    // Function to display formatted debug info
    function debugOutput($title, $content, $type = 'info') {
        echo "<div class='debug-section'>";
        echo "<h3>$title</h3>";
        if (is_array($content) || is_object($content)) {
            echo "<pre>" . htmlspecialchars(json_encode($content, JSON_PRETTY_PRINT)) . "</pre>";
        } else {
            echo "<div class='$type'>" . htmlspecialchars($content) . "</div>";
        }
        echo "</div>";
    }

    // Get current user info
    $currentUser = null;
    $isLoggedIn = $authManager->isLoggedIn();
    
    if ($isLoggedIn) {
        $currentUser = $authManager->getCurrentUser();
        debugOutput("🔐 Current User Info", $currentUser, 'success');
    } else {
        debugOutput("🔐 Authentication Status", "User is not logged in", 'error');
    }

    // 1. Test Database Connection
    echo "<div class='debug-section'>";
    echo "<h3>🔗 Database Connection Test</h3>";
    try {
        $test_query = $supabaseClient->select('teams', 'id', [], 'id.asc', 1);
        echo "<div class='success'>✅ Database connection successful</div>";
    } catch (Exception $e) {
        echo "<div class='error'>❌ Database connection failed: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    echo "</div>";

    // 2. Check all teams in database
    echo "<div class='debug-section'>";
    echo "<h3>📊 All Teams in Database</h3>";
    try {
        $all_teams = $supabaseClient->select('teams', '*');
        if (empty($all_teams)) {
            echo "<div class='warning'>⚠️ No teams found in database</div>";
        } else {
            echo "<div class='info'>📈 Found " . count($all_teams) . " total teams</div>";
            echo "<table>";
            echo "<tr><th>ID</th><th>Name</th><th>Captain ID</th><th>Active</th><th>Private</th><th>Current/Max Members</th><th>Created</th></tr>";
            foreach ($all_teams as $team) {
                $status_class = $team['is_active'] ? 'status-active' : 'status-inactive';
                $private_text = $team['is_private'] ? 'Yes' : 'No';
                echo "<tr>";
                echo "<td>{$team['id']}</td>";
                echo "<td>" . htmlspecialchars($team['name']) . "</td>";
                echo "<td>{$team['captain_id']}</td>";
                echo "<td class='$status_class'>" . ($team['is_active'] ? 'Active' : 'Inactive') . "</td>";
                echo "<td>$private_text</td>";
                echo "<td>{$team['current_members']}/{$team['max_members']}</td>";
                echo "<td>{$team['created_at']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    } catch (Exception $e) {
        echo "<div class='error'>❌ Error fetching teams: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    echo "</div>";

    // 3. Check team discovery view
    echo "<div class='debug-section'>";
    echo "<h3>🔍 Team Discovery View Test</h3>";
    try {
        $discovery_teams = $supabaseClient->select('team_discovery', '*');
        if (empty($discovery_teams)) {
            echo "<div class='warning'>⚠️ No teams found in discovery view</div>";
        } else {
            echo "<div class='success'>✅ Found " . count($discovery_teams) . " teams in discovery view</div>";
            echo "<table>";
            echo "<tr><th>ID</th><th>Name</th><th>Captain</th><th>Win Rate</th><th>Available Spots</th><th>Status</th></tr>";
            foreach ($discovery_teams as $team) {
                echo "<tr>";
                echo "<td>{$team['id']}</td>";
                echo "<td>" . htmlspecialchars($team['name']) . "</td>";
                echo "<td>" . htmlspecialchars($team['captain_username']) . "</td>";
                echo "<td>{$team['win_rate']}%</td>";
                echo "<td>{$team['available_spots']}</td>";
                echo "<td>" . htmlspecialchars($team['availability_status']) . "</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    } catch (Exception $e) {
        echo "<div class='error'>❌ Error with discovery view: " . htmlspecialchars($e->getMessage()) . "</div>";
        echo "<div class='info'>💡 Trying fallback query...</div>";
        
        // Fallback query
        try {
            $fallback_teams = $supabaseClient->select('teams', '*', ['is_active' => true, 'is_private' => false]);
            echo "<div class='success'>✅ Fallback query found " . count($fallback_teams) . " teams</div>";
        } catch (Exception $e2) {
            echo "<div class='error'>❌ Fallback query also failed: " . htmlspecialchars($e2->getMessage()) . "</div>";
        }
    }
    echo "</div>";

    // 4. Check user's team memberships
    if ($isLoggedIn && $currentUser) {
        echo "<div class='debug-section'>";
        echo "<h3>👥 User Team Memberships</h3>";
        try {
            $user_memberships = $supabaseClient->select('team_members', '*', ['user_id' => $currentUser['user_id']]);
            if (empty($user_memberships)) {
                echo "<div class='info'>ℹ️ User is not a member of any teams</div>";
            } else {
                echo "<div class='info'>📋 Found " . count($user_memberships) . " memberships</div>";
                echo "<table>";
                echo "<tr><th>Team ID</th><th>Role</th><th>Status</th><th>Joined At</th></tr>";
                foreach ($user_memberships as $membership) {
                    echo "<tr>";
                    echo "<td>{$membership['team_id']}</td>";
                    echo "<td>" . htmlspecialchars($membership['role']) . "</td>";
                    echo "<td>" . htmlspecialchars($membership['status']) . "</td>";
                    echo "<td>{$membership['joined_at']}</td>";
                    echo "</tr>";
                }
                echo "</table>";
                
                // Get team details for each membership
                foreach ($user_memberships as $membership) {
                    if ($membership['status'] === 'active') {
                        try {
                            $team_info = $supabaseClient->select('teams', '*', ['id' => $membership['team_id']]);
                            if (!empty($team_info)) {
                                echo "<div class='success'>✅ Team Details for ID {$membership['team_id']}:</div>";
                                debugOutput("Team Info", $team_info[0]);
                            } else {
                                echo "<div class='error'>❌ Team ID {$membership['team_id']} not found</div>";
                            }
                        } catch (Exception $e) {
                            echo "<div class='error'>❌ Error fetching team {$membership['team_id']}: " . htmlspecialchars($e->getMessage()) . "</div>";
                        }
                    }
                }
            }
        } catch (Exception $e) {
            echo "<div class='error'>❌ Error fetching user memberships: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        echo "</div>";

        // 5. Check user's join requests
        echo "<div class='debug-section'>";
        echo "<h3>📝 User Join Requests</h3>";
        try {
            $user_requests = $supabaseClient->select('team_join_requests', '*', ['user_id' => $currentUser['user_id']]);
            if (empty($user_requests)) {
                echo "<div class='info'>ℹ️ User has no join requests</div>";
            } else {
                echo "<div class='info'>📋 Found " . count($user_requests) . " join requests</div>";
                echo "<table>";
                echo "<tr><th>Team ID</th><th>Status</th><th>Created At</th></tr>";
                foreach ($user_requests as $request) {
                    echo "<tr>";
                    echo "<td>{$request['team_id']}</td>";
                    echo "<td>" . htmlspecialchars($request['status']) . "</td>";
                    echo "<td>{$request['created_at']}</td>";
                    echo "</tr>";
                }
                echo "</table>";
            }
        } catch (Exception $e) {
            echo "<div class='error'>❌ Error fetching join requests: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        echo "</div>";
    }

    // 6. RLS Policy Test
    echo "<div class='debug-section'>";
    echo "<h3>🔒 RLS Policy Test</h3>";
    
    // Test different scenarios
    $rls_tests = [
        'Public teams query' => ['teams', '*', ['is_active' => true, 'is_private' => false]],
        'All teams query' => ['teams', '*', ['is_active' => true]],
        'Team members query' => ['team_members', '*', ['status' => 'active']],
        'Join requests query' => ['team_join_requests', '*', ['status' => 'pending']]
    ];
    
    foreach ($rls_tests as $test_name => $query_params) {
        try {
            $result = $supabaseClient->select($query_params[0], $query_params[1], $query_params[2]);
            echo "<div class='success'>✅ $test_name: " . count($result) . " records</div>";
        } catch (Exception $e) {
            echo "<div class='error'>❌ $test_name failed: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
    echo "</div>";

    // 7. Helper Functions Test
    echo "<div class='debug-section'>";
    echo "<h3>🔧 Helper Functions Test</h3>";
    
    // Test the team discovery view directly
    try {
        $raw_query = "SELECT * FROM team_discovery LIMIT 5";
        echo "<div class='info'>🧪 Testing raw query: $raw_query</div>";
        // Note: You might need to adjust this based on your SupabaseClient implementation
        echo "<div class='warning'>⚠️ Raw query testing requires direct database access</div>";
    } catch (Exception $e) {
        echo "<div class='error'>❌ Raw query failed: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    echo "</div>";

    // 8. Recommendations
    echo "<div class='debug-section'>";
    echo "<h3>💡 Debug Recommendations</h3>";
    
    $recommendations = [];
    
    // Check if teams exist but not showing
    try {
        $total_teams = count($supabaseClient->select('teams', 'id'));
        $active_teams = count($supabaseClient->select('teams', 'id', ['is_active' => true]));
        $public_teams = count($supabaseClient->select('teams', 'id', ['is_active' => true, 'is_private' => false]));
        
        if ($total_teams > 0 && $active_teams === 0) {
            $recommendations[] = "❌ All teams are marked as inactive. Check the 'is_active' field.";
        }
        
        if ($active_teams > 0 && $public_teams === 0) {
            $recommendations[] = "🔒 All active teams are private. Check the 'is_private' field.";
        }
        
        if ($public_teams > 0) {
            $recommendations[] = "✅ Found $public_teams public active teams. Issue might be with RLS policies or view permissions.";
        }
        
    } catch (Exception $e) {
        $recommendations[] = "❌ Could not perform team count analysis: " . $e->getMessage();
    }
    
    // Check for common issues
    if ($isLoggedIn && $currentUser) {
        $recommendations[] = "🔐 User is logged in - authentication is working.";
    } else {
        $recommendations[] = "❌ User not logged in - this might affect team visibility due to RLS policies.";
    }
    
    foreach ($recommendations as $rec) {
        echo "<div class='info'>$rec</div>";
    }
    echo "</div>";
    ?>

    <div class="debug-section">
        <h3>🔄 Quick Actions</h3>
        <button class="btn" onclick="location.reload()">Refresh Debug</button>
        <button class="btn" onclick="window.open('index.php', '_blank')">Open Teams Index</button>
        <button class="btn" onclick="window.open('yourteams.php', '_blank')">Open Your Teams</button>
    </div>

</div>

<script>
// Auto-refresh every 30 seconds if desired
// setTimeout(() => location.reload(), 30000);
</script>

</body>
</html>
