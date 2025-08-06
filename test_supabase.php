<?php
// Test Supabase Connection Script
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'private/includes/SupabaseClient.php';

echo "<h1>Supabase Connection Test</h1>";

try {
    // Test 1: Configuration validation
    echo "<h2>1. Configuration Test</h2>";
    $config = SupabaseConfig::getConfig();
    $errors = SupabaseConfig::validate();
    
    if (empty($errors)) {
        echo "<p style='color: green;'>✓ Configuration is valid</p>";
        echo "<pre>";
        echo "URL: " . $config['url'] . "\n";
        echo "Anon Key: " . substr($config['anon_key'], 0, 20) . "...\n";
        echo "Service Role Key: " . substr($config['service_role_key'], 0, 20) . "...\n";
        echo "DB Host: " . $config['db_host'] . "\n";
        echo "</pre>";
    } else {
        echo "<p style='color: red;'>✗ Configuration errors:</p>";
        echo "<ul>";
        foreach ($errors as $error) {
            echo "<li>$error</li>";
        }
        echo "</ul>";
        exit;
    }
    
    // Test 2: Basic Supabase Client initialization
    echo "<h2>2. Supabase Client Test</h2>";
    $supabase = new SupabaseClient();
    echo "<p style='color: green;'>✓ Supabase client initialized successfully</p>";
    
    // Test 3: Test authentication endpoint
    echo "<h2>3. Authentication Endpoint Test</h2>";
$testEmail = 'test_' . time() . '@mailinator.com';
    $testPassword = 'testpassword123';
    
    try {
        $authResult = $supabase->signUp($testEmail, $testPassword);
        echo "<p style='color: green;'>✓ Auth endpoint is working</p>";
        echo "<pre>" . json_encode($authResult, JSON_PRETTY_PRINT) . "</pre>";
        
        // Clean up test user if needed
        if (isset($authResult['user']['id'])) {
            echo "<p>Test user created with ID: " . $authResult['user']['id'] . "</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>✗ Auth endpoint failed: " . $e->getMessage() . "</p>";
        
        // Let's try to get more details about the error
        echo "<h3>Debugging Auth Request:</h3>";
        
        $url = $config['url'] . '/auth/v1/signup';
        $data = [
            'email' => $testEmail,
            'password' => $testPassword
        ];
        
        $headers = [
            'Content-Type: application/json',
            'apikey: ' . $config['anon_key']
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_VERBOSE => true
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        echo "<pre>";
        echo "URL: $url\n";
        echo "HTTP Code: $httpCode\n";
        echo "CURL Error: $curlError\n";
        echo "Headers: " . implode(', ', $headers) . "\n";
        echo "Request Data: " . json_encode($data) . "\n";
        echo "Response: $response\n";
        echo "</pre>";
    }
    
    // Test 4: Test database connection (if direct connection is available)
    echo "<h2>4. Database Connection Test</h2>";
    try {
        $serviceClient = new SupabaseClient(true);
        echo "<p style='color: green;'>✓ Service role client initialized</p>";
        
        // Try to select from users table
        try {
            $result = $serviceClient->select('users', 'id', [], null, 1);
            echo "<p style='color: green;'>✓ Can query users table</p>";
        } catch (Exception $e) {
            echo "<p style='color: orange;'>⚠ Cannot query users table: " . $e->getMessage() . "</p>";
            echo "<p>This might be normal if the table doesn't exist yet or RLS is enabled.</p>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>✗ Service role client failed: " . $e->getMessage() . "</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ General error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}

echo "<h2>5. Recommendations</h2>";
echo "<ul>";
echo "<li>Check if your Supabase project is active and not paused</li>";
echo "<li>Verify that the API keys are correct and not expired</li>";
echo "<li>Ensure that your Supabase project has authentication enabled</li>";
echo "<li>Check if there are any RLS (Row Level Security) policies blocking the requests</li>";
echo "<li>Make sure your server can make outbound HTTPS requests</li>";
echo "</ul>";

?>
