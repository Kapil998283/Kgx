<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
define('SECURE_ACCESS', true);
require_once '../secure_config.php';

// Load secure configurations and includes
loadSecureConfig('supabase.php');

echo "<!DOCTYPE html><html><head><title>Setup Storage</title></head><body>";
echo "<h1>🚀 KGX Storage Setup</h1>";

try {
    $config = SupabaseConfig::getConfig();
    
    // Create bucket using Supabase REST API
    $bucketName = 'match-screenshots';
    $url = $config['url'] . '/storage/v1/bucket';
    
    $bucketData = [
        'id' => $bucketName,
        'name' => $bucketName,
        'public' => false, // Make it private for security
        'fileSizeLimit' => 5242880, // 5MB
        'allowedMimeTypes' => ['image/jpeg', 'image/png', 'image/webp']
    ];
    
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . $config['anon_key'],
        'Authorization: Bearer ' . $config['service_role_key']
    ];
    
    echo "<h2>📦 Creating Storage Bucket</h2>";
    echo "<p><strong>Bucket Name:</strong> {$bucketName}</p>";
    echo "<p><strong>Privacy:</strong> Private (secure)</p>";
    echo "<p><strong>File Size Limit:</strong> 5MB</p>";
    echo "<p><strong>Allowed Types:</strong> JPEG, PNG, WebP</p>";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($bucketData),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        throw new Exception('CURL Error: ' . $curlError);
    }
    
    $decoded = json_decode($response, true);
    
    if ($httpCode >= 200 && $httpCode < 300) {
        echo "<p style='color: green;'>✅ <strong>SUCCESS!</strong> Storage bucket '{$bucketName}' created successfully!</p>";
        echo "<pre>" . json_encode($decoded, JSON_PRETTY_PRINT) . "</pre>";
    } elseif ($httpCode === 409) {
        echo "<p style='color: orange;'>⚠️ <strong>ALREADY EXISTS:</strong> Storage bucket '{$bucketName}' already exists.</p>";
    } else {
        echo "<p style='color: red;'>❌ <strong>ERROR:</strong> Failed to create bucket (HTTP {$httpCode})</p>";
        echo "<pre>" . htmlspecialchars($response) . "</pre>";
    }
    
    // Test bucket access
    echo "<h2>🧪 Testing Bucket Access</h2>";
    $testUrl = $config['url'] . "/storage/v1/bucket/{$bucketName}";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $testUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        echo "<p style='color: green;'>✅ Bucket is accessible and working!</p>";
        $bucketInfo = json_decode($response, true);
        echo "<p><strong>Bucket ID:</strong> " . ($bucketInfo['id'] ?? 'N/A') . "</p>";
        echo "<p><strong>Public:</strong> " . (($bucketInfo['public'] ?? false) ? 'Yes' : 'No (Private)') . "</p>";
    } else {
        echo "<p style='color: red;'>❌ Bucket access test failed (HTTP {$httpCode})</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ <strong>ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<br><br>";
echo "<a href='storage_debug.php'>🔍 Run Debug Tool Again</a> | ";
echo "<a href='index.php'>🎮 Go to Matches</a>";
echo "</body></html>";
?>
