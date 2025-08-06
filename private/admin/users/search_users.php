<?php
// Set error handling to prevent display errors
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Set error handler to log errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Search Users Error: $errstr in $errfile on line $errline");
    return true;
});

// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once dirname(__DIR__) . '/admin_secure_config.php';

// Use the new admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

// Initialize Supabase connection
$supabase = getSupabaseConnection();

// Set JSON header
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Check if it's an AJAX request
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request method']);
    exit;
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || !isset($data['search']) || !isset($data['action'])) {
        throw new Exception('Invalid request data');
    }
    
    if ($data['action'] !== 'realtime_search') {
        throw new Exception('Invalid action');
    }
    
    $searchTerm = trim($data['search']);
    
    if (empty($searchTerm)) {
        // Return empty results for empty search
        echo json_encode([
            'success' => true,
            'users' => [],
            'stats' => [
                'total_users' => 0,
                'total_coins' => 0,
                'total_tickets' => 0,
                'new_users' => 0
            ],
            'total' => 0,
            'search_term' => ''
        ]);
        exit;
    }
    
    // Sanitize search term
    $searchTerm = filter_var($searchTerm, FILTER_SANITIZE_STRING);
    
    if (strlen($searchTerm) < 1) {
        throw new Exception('Search term too short');
    }
    
    // Perform search
    $result = performUserSearch($supabase, $searchTerm);
    
    // Return results
    echo json_encode([
        'success' => true,
        'users' => $result['users'],
        'stats' => $result['stats'],
        'total' => $result['total'],
        'search_term' => $searchTerm
    ]);
    
} catch (Exception $e) {
    error_log("Search error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Search failed: ' . $e->getMessage()
    ]);
}

function performUserSearch($supabase, $searchTerm) {
    try {
        $users = [];
        $total = 0;
        $stats = [
            'total_users' => 0,
            'total_coins' => 0,
            'total_tickets' => 0,
            'new_users' => 0
        ];
        
        // Search by username
        $username_results = $supabase->select('users', '*', [
            'username' => "ilike.%{$searchTerm}%", 
            'role' => 'user'
        ]);
        
        // Search by email
        $email_results = $supabase->select('users', '*', [
            'email' => "ilike.%{$searchTerm}%", 
            'role' => 'user'
        ]);
        
        // Merge and deduplicate results
        $combined_results = [];
        $seen_ids = [];
        
        foreach (array_merge($username_results ?: [], $email_results ?: []) as $user) {
            if (!in_array($user['id'], $seen_ids)) {
                $combined_results[] = $user;
                $seen_ids[] = $user['id'];
            }
        }
        
        // Limit results for performance (max 50 results)
        $users_data = array_slice($combined_results, 0, 50);
        $total = count($combined_results);
        
        // Enhance user data with coins and tickets
        foreach ($users_data as &$user) {
            try {
                // Get user coins
                $coins_data = $supabase->select('user_coins', 'coins', ['user_id' => $user['id']]);
                $user['coins'] = !empty($coins_data) ? $coins_data[0]['coins'] : 0;
                
                // Get user tickets  
                $tickets_data = $supabase->select('user_tickets', 'tickets', ['user_id' => $user['id']]);
                $user['tickets'] = !empty($tickets_data) ? $tickets_data[0]['tickets'] : 0;
                
                // Add to stats
                $stats['total_coins'] += $user['coins'];
                $stats['total_tickets'] += $user['tickets'];
                
                // Check if new user (last 30 days)
                if (isset($user['created_at']) && strtotime($user['created_at']) > strtotime('-30 days')) {
                    $stats['new_users']++;
                }
                
            } catch (Exception $e) {
                error_log("User enhancement error for user {$user['id']}: " . $e->getMessage());
                $user['coins'] = 0;
                $user['tickets'] = 0;
            }
        }
        
        $stats['total_users'] = count($users_data);
        
        return [
            'users' => $users_data,
            'stats' => $stats,
            'total' => $total
        ];
        
    } catch (Exception $e) {
        error_log("Search query error: " . $e->getMessage());
        throw new Exception("Database search failed");
    }
}

// Helper function to sanitize output
function sanitizeOutput($data) {
    if (is_array($data)) {
        return array_map('sanitizeOutput', $data);
    } elseif (is_string($data)) {
        return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
    return $data;
}
?>
