<?php
require_once 'private/config/supabase.php';

// Enable detailed error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Test credentials
$test_email = 'test' . time() . '@mailinator.com';
$test_password = 'TestPassword123!';

echo "<h2>Supabase Authentication Debug Test</h2>\n";
echo "<p><strong>Test Email:</strong> " . htmlspecialchars($test_email) . "</p>\n";
echo "<p><strong>Test Password:</strong> " . htmlspecialchars($test_password) . "</p>\n";

// Initialize Supabase client
$supabase = new SupabaseClient($supabaseUrl, $supabaseKey);

echo "<h3>Step 1: Testing Supabase Connection</h3>\n";

// Test basic connection
$testUrl = $supabaseUrl . '/rest/v1/';
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $testUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'apikey: ' . $supabaseKey,
    'Authorization: Bearer ' . $supabaseKey
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    echo "<p style='color: red;'>❌ Connection Error: " . htmlspecialchars($curlError) . "</p>\n";
} else {
    echo "<p style='color: green;'>✅ Connection successful (HTTP " . $httpCode . ")</p>\n";
}

echo "<h3>Step 2: Testing Supabase Auth Endpoint</h3>\n";

// Test auth endpoint specifically
$authUrl = $supabaseUrl . '/auth/v1/signup';
$authData = [
    'email' => $test_email,
    'password' => $test_password
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $authUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($authData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'apikey: ' . $supabaseKey,
    'Authorization: Bearer ' . $supabaseKey,
    'Content-Type: application/json'
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 30);
curl_setopt($ch, CURLOPT_VERBOSE, true);

// Capture verbose output
$verboseOutput = fopen('php://temp', 'w+');
curl_setopt($ch, CURLOPT_STDERR, $verboseOutput);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

// Get verbose output
rewind($verboseOutput);
$verboseInfo = stream_get_contents($verboseOutput);
fclose($verboseOutput);

curl_close($ch);

echo "<p><strong>Auth Endpoint:</strong> " . htmlspecialchars($authUrl) . "</p>\n";
echo "<p><strong>HTTP Response Code:</strong> " . $httpCode . "</p>\n";

if ($curlError) {
    echo "<p style='color: red;'><strong>cURL Error:</strong> " . htmlspecialchars($curlError) . "</p>\n";
}

if ($response) {
    echo "<p><strong>Response Body:</strong></p>\n";
    echo "<pre style='background: #f0f0f0; padding: 10px; border-radius: 5px;'>" . htmlspecialchars($response) . "</pre>\n";
    
    $responseData = json_decode($response, true);
    if ($responseData) {
        echo "<p><strong>Parsed Response:</strong></p>\n";
        echo "<pre style='background: #f0f0f0; padding: 10px; border-radius: 5px;'>" . print_r($responseData, true) . "</pre>\n";
    }
} else {
    echo "<p style='color: red;'>No response received</p>\n";
}

echo "<h3>Step 3: Testing with SupabaseClient Class</h3>\n";

try {
    $result = $supabase->signUp($test_email, $test_password);
    
    if ($result) {
        echo "<p style='color: green;'>✅ SupabaseClient signUp successful</p>\n";
        echo "<pre style='background: #e8f5e8; padding: 10px; border-radius: 5px;'>" . print_r($result, true) . "</pre>\n";
    } else {
        echo "<p style='color: red;'>❌ SupabaseClient signUp returned false/null</p>\n";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ SupabaseClient Exception: " . htmlspecialchars($e->getMessage()) . "</p>\n";
}

echo "<h3>Step 4: Checking Supabase Configuration</h3>\n";
echo "<p><strong>Supabase URL:</strong> " . htmlspecialchars($supabaseUrl) . "</p>\n";
echo "<p><strong>API Key (first 20 chars):</strong> " . htmlspecialchars(substr($supabaseKey, 0, 20)) . "...</p>\n";

// Check if it's anon key or service role key
if (strpos($supabaseKey, 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9') === 0) {
    $payload = json_decode(base64_decode(str_replace('_', '/', str_replace('-', '+', explode('.', $supabaseKey)[1]))), true);
    if ($payload && isset($payload['role'])) {
        echo "<p><strong>Key Type:</strong> " . htmlspecialchars($payload['role']) . "</p>\n";
    }
}

echo "<h3>Step 5: Environment Check</h3>\n";
echo "<p><strong>PHP Version:</strong> " . PHP_VERSION . "</p>\n";
echo "<p><strong>cURL Version:</strong> " . curl_version()['version'] . "</p>\n";
echo "<p><strong>OpenSSL Support:</strong> " . (function_exists('openssl_get_cert_locations') ? 'Yes' : 'No') . "</p>\n";

// Check if .env file exists and contains expected variables
if (file_exists('.env')) {
    echo "<p><strong>.env file:</strong> Found</p>\n";
    $envContent = file_get_contents('.env');
    $hasSupabaseUrl = strpos($envContent, 'SUPABASE_URL') !== false;
    $hasSupabaseKey = strpos($envContent, 'SUPABASE_ANON_KEY') !== false;
    echo "<p><strong>Contains SUPABASE_URL:</strong> " . ($hasSupabaseUrl ? 'Yes' : 'No') . "</p>\n";
    echo "<p><strong>Contains SUPABASE_ANON_KEY:</strong> " . ($hasSupabaseKey ? 'Yes' : 'No') . "</p>\n";
} else {
    echo "<p><strong>.env file:</strong> Not found</p>\n";
}

echo "<hr>\n";
echo "<p><em>Check the response details above to identify the exact cause of authentication failures.</em></p>\n";
?>
