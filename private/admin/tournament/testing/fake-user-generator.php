<?php
// CRITICAL: Suppress ALL error output to prevent corrupting JSON response
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Set error handler to suppress all output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Fake User Generator Error: $errstr in $errfile on line $errline");
    return true; // Don't execute PHP internal error handler
});

// Define admin secure access
define('ADMIN_SECURE_ACCESS', true);

// Load admin secure configuration
require_once dirname(__DIR__, 2) . '/admin_secure_config.php';

// Use the new admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

// Initialize Supabase connection with admin privileges
$supabase = new SupabaseClient(true);

header('Content-Type: application/json');

try {
    $count = isset($_GET['count']) ? min(50, max(1, (int)$_GET['count'])) : 30; // Default 30, max 50
    $game = isset($_GET['game']) ? $_GET['game'] : 'BGMI';
    
    $created_users = [];
    $failed_users = [];
    
    for ($i = 1; $i <= $count; $i++) {
        $username = generateUsername($i);
        $email = generateEmail($username);
        $password_hash = password_hash('testpass123', PASSWORD_DEFAULT);
        
        try {
            // Create user record
            $user_data = [
                'username' => $username,
                'email' => $email,
                'password_hash' => $password_hash,
                'full_name' => generateFullName($username),
                'phone' => generatePhone(),
                'role' => 'user',
                'is_active' => true,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'email_verified' => true,
                'phone_verified' => true
            ];
            
            $result = $supabase->insert('users', $user_data);
            
            if ($result) {
                // Get the created user ID
                $user_record = $supabase->select('users', 'id', ['email' => $email], null, 1);
                if (!empty($user_record)) {
                    $user_id = $user_record[0]['id'];
                    
                    // Create user game profile for BGMI
                    $game_data = [
                        'user_id' => $user_id,
                        'game_name' => $game,
                        'game_username' => generateGameUsername($username),
                        'game_uid' => generateGameUID(),
                        'game_level' => rand(20, 99),
                        'is_primary' => 1
                    ];
                    $supabase->insert('user_games', $game_data);
                    
                    // Give user 1000 tickets
                    $supabase->insert('user_tickets', [
                        'user_id' => $user_id,
                        'tickets' => 1000
                    ]);
                    
                    // Give user 1000 coins
                    $supabase->insert('user_coins', [
                        'user_id' => $user_id,
                        'coins' => 1000
                    ]);
                    
                    $created_users[] = [
                        'id' => $user_id,
                        'username' => $username,
                        'email' => $email,
                        'game_username' => $game_data['game_username'],
                        'game_uid' => $game_data['game_uid'],
                        'game_level' => $game_data['game_level']
                    ];
                }
            }
            
        } catch (Exception $e) {
            $failed_users[] = [
                'username' => $username,
                'email' => $email,
                'error' => $e->getMessage()
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Fake users generated successfully',
        'created_count' => count($created_users),
        'failed_count' => count($failed_users),
        'created_users' => $created_users,
        'failed_users' => $failed_users
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function generateUsername($i) {
    $prefixes = ['Player', 'Gamer', 'Pro', 'Elite', 'Master', 'Killer', 'Sniper', 'Legend', 'Shadow', 'Ghost'];
    $suffixes = ['YT', 'OP', 'Pro', 'King', 'Boss', 'God', 'X', 'Gaming', '07', '99'];
    
    return $prefixes[array_rand($prefixes)] . str_pad($i, 3, '0', STR_PAD_LEFT) . $suffixes[array_rand($suffixes)];
}

function generateEmail($username) {
    return strtolower($username) . '@testuser.com';
}

function generateFullName($username) {
    $first_names = ['Arjun', 'Rohit', 'Virat', 'Amit', 'Sanjay', 'Raj', 'Anil', 'Sunil', 'Rahul', 'Deepak'];
    $last_names = ['Sharma', 'Kumar', 'Singh', 'Patel', 'Gupta', 'Verma', 'Yadav', 'Mishra', 'Jain', 'Shah'];
    
    return $first_names[array_rand($first_names)] . ' ' . $last_names[array_rand($last_names)];
}

function generatePhone() {
    return '91' . rand(7000000000, 9999999999);
}

function generateGameUsername($username) {
    $gaming_prefixes = ['OP', 'YT', 'TTV', 'YTB', 'FB'];
    $gaming_suffixes = ['OP', 'Pro', 'Gaming', 'YT', 'Live', 'God', 'King'];
    
    // 70% chance to add prefix or suffix
    if (rand(1, 100) <= 70) {
        if (rand(1, 2) == 1) {
            return $gaming_prefixes[array_rand($gaming_prefixes)] . $username;
        } else {
            return $username . $gaming_suffixes[array_rand($gaming_suffixes)];
        }
    }
    
    return $username;
}

function generateGameUID() {
    // BGMI UIDs are typically 9-10 digits
    return rand(100000000, 9999999999);
}
?>
