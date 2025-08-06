<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
define('SECURE_ACCESS', true);
require_once '../secure_config.php';

// Load secure configurations and includes
loadSecureConfig('supabase.php');

echo "<!DOCTYPE html><html><head><title>Make Bucket Public</title></head><body>";
echo "<h1>🔓 Make KGX Storage Bucket Public</h1>";

try {
    $config = SupabaseConfig::getConfig();
    
    // Update bucket to be public using Supabase REST API
    $bucketName = 'match-screenshots';
    $url = $config['url'] . "/storage/v1/bucket/{$bucketName}";
    
    $bucketData = [
        'public' => true, // Make it public for easier uploads
        'fileSizeLimit' => 5242880, // 5MB
        'allowedMimeTypes' => ['image/jpeg', 'image/png', 'image/webp']
    ];
    
    $headers = [
        'Content-Type: application/json',
        'apikey: ' . $config['anon_key'],
        'Authorization: Bearer ' . $config['service_role_key']
    ];
    
    echo "<h2>🔓 Making Storage Bucket Public</h2>";
    echo "<p><strong>Bucket Name:</strong> {$bucketName}</p>";
    echo "<p><strong>New Setting:</strong> Public (easier for testing)</p>";
    echo "<p><strong>File Size Limit:</strong> 5MB</p>";
    echo "<p><strong>Allowed Types:</strong> JPEG, PNG, WebP</p>";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => 'PUT',
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
        echo "<p style='color: green;'>✅ <strong>SUCCESS!</strong> Storage bucket '{$bucketName}' is now PUBLIC!</p>";
        echo "<p>📝 <strong>Note:</strong> You can now upload images without authentication issues.</p>";
        echo "<pre>" . json_encode($decoded, JSON_PRETTY_PRINT) . "</pre>";
    } else {
        echo "<p style='color: red;'>❌ <strong>ERROR:</strong> Failed to update bucket (HTTP {$httpCode})</p>";
        echo "<pre>" . htmlspecialchars($response) . "</pre>";
    }
    
    // Test bucket access
    echo "<h2>🧪 Testing Updated Bucket Access</h2>";
    $testUrl = $config['url'] . "/storage/v1/object/public/{$bucketName}/test.jpg";
    
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $testUrl,
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 404) {
        echo "<p style='color: green;'>✅ Bucket is now PUBLIC and accessible! (404 is normal - file doesn't exist yet)</p>";
    } elseif ($httpCode === 400) {
        echo "<p style='color: orange;'>⚠️ Bucket might still be private or there's an issue.</p>";
    } else {
        echo "<p style='color: blue;'>ℹ️ Bucket status test returned HTTP {$httpCode}</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ <strong>ERROR:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<br><br>";
echo "<a href='storage_debug.php'>🔍 Run Debug Tool Again</a> | ";
echo "<a href='upload.php?match_id=1'>🎮 Test Upload Page</a>";
echo "</body></html>";
?>
