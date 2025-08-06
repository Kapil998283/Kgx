<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
define('SECURE_ACCESS', true);
require_once '../secure_config.php';

// Load secure configurations and includes
loadSecureConfig('supabase.php');
loadSecureInclude('SupabaseClient.php');
loadSecureInclude('auth.php');

// Initialize SupabaseClient
$supabaseClient = new SupabaseClient();
$authManager = new AuthManager();

// HTML header
echo "<!DOCTYPE html><html><head><title>Storage Debug</title>";
echo "<style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .success { color: green; }
    .error { color: red; }
    .warning { color: orange; }
    .debug-section { margin: 20px 0; padding: 15px; border: 1px solid #ccc; border-radius: 5px; }
    .debug-section h2 { margin-top: 0; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #f2f2f2; }
</style></head><body>";

echo "<h1>🔍 KGX Storage Debug Information</h1>";
echo "<p><strong>Generated:</strong> " . date('Y-m-d H:i:s') . "</p>";

// 1. Authentication Status
echo "<div class='debug-section'>";
echo "<h2>👤 Authentication Status</h2>";
if ($authManager->isLoggedIn()) {
    $user = $authManager->getCurrentUser();
    echo "<p class='success'>✅ User logged in: {$user['username']} (ID: {$user['user_id']})</p>";
} else {
    echo "<p class='error'>❌ User not logged in</p>";
}
echo "</div>";

// 2. PHP Configuration
echo "<div class='debug-section'>";
echo "<h2>⚙️ PHP Upload Configuration</h2>";
echo "<table>";
echo "<tr><th>Setting</th><th>Value</th><th>Status</th></tr>";

$phpSettings = [
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'max_file_uploads' => ini_get('max_file_uploads'),
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'file_uploads' => ini_get('file_uploads') ? 'Enabled' : 'Disabled'
];

foreach ($phpSettings as $setting => $value) {
    $status = '';
    if ($setting === 'file_uploads' && $value === 'Disabled') {
        $status = "<span class='error'>❌ Critical</span>";
    } elseif (in_array($setting, ['upload_max_filesize', 'post_max_size'])) {
        $bytes = convertToBytes($value);
        $status = $bytes >= 5242880 ? "<span class='success'>✅ OK (≥5MB)</span>" : "<span class='warning'>⚠️ Low (‹5MB)</span>";
    } else {
        $status = "<span class='success'>✅ OK</span>";
    }
    echo "<tr><td>{$setting}</td><td>{$value}</td><td>{$status}</td></tr>";
}
echo "</table>";
echo "</div>";

// 3. Supabase Configuration
echo "<div class='debug-section'>";
echo "<h2>🗄️ Supabase Configuration</h2>";
try {
    $config = SupabaseConfig::getConfig();
    echo "<table>";
    echo "<tr><th>Setting</th><th>Status</th></tr>";
    echo "<tr><td>URL</td><td>" . (isset($config['url']) && !empty($config['url']) ? "<span class='success'>✅ Set</span>" : "<span class='error'>❌ Missing</span>") . "</td></tr>";
    echo "<tr><td>Anon Key</td><td>" . (isset($config['anon_key']) && !empty($config['anon_key']) ? "<span class='success'>✅ Set</span>" : "<span class='error'>❌ Missing</span>") . "</td></tr>";
    echo "<tr><td>Service Role Key</td><td>" . (isset($config['service_role_key']) && !empty($config['service_role_key']) ? "<span class='success'>✅ Set</span>" : "<span class='error'>❌ Missing</span>") . "</td></tr>";
    echo "</table>";
} catch (Exception $e) {
    echo "<p class='error'>❌ Configuration Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
echo "</div>";

// 4. Storage Bucket Test
echo "<div class='debug-section'>";
echo "<h2>🪣 Storage Bucket Test</h2>";
try {
    $bucketName = 'match-screenshots';
    
    // Test bucket access by trying to get public URL
    $testFileName = 'test.jpg';
    $publicUrl = $supabaseClient->getPublicUrl($bucketName, $testFileName);
    echo "<p><strong>Bucket Name:</strong> {$bucketName}</p>";
    echo "<p><strong>Test Public URL:</strong> <a href='{$publicUrl}' target='_blank'>{$publicUrl}</a></p>";
    
    // Test if bucket is accessible by making a HEAD request
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $publicUrl,
        CURLOPT_NOBODY => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        echo "<p class='success'>✅ Bucket is PUBLIC and accessible</p>";
    } elseif ($httpCode === 404) {
        echo "<p class='warning'>⚠️ Bucket is PRIVATE or file doesn't exist (this is normal for private buckets)</p>";
    } else {
        echo "<p class='error'>❌ Bucket access test failed (HTTP {$httpCode})</p>";
    }
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Storage test error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
echo "</div>";

// 5. Database Connection Test
echo "<div class='debug-section'>";
echo "<h2>🗃️ Database Connection Test</h2>";
try {
    $pdo = $supabaseClient->getConnection();
    echo "<p class='success'>✅ Database connection successful</p>";
    
    // Test match_screenshots table
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM match_screenshots");
    $stmt->execute();
    $result = $stmt->fetch();
    echo "<p><strong>Screenshots in database:</strong> {$result['count']}</p>";
    
    // Test matches table
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM matches WHERE status = 'in_progress'");
    $stmt->execute();
    $result = $stmt->fetch();
    echo "<p><strong>Active matches:</strong> {$result['count']}</p>";
    
} catch (Exception $e) {
    echo "<p class='error'>❌ Database error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
echo "</div>";

// 6. File Upload Status (if POST request)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<div class='debug-section'>";
    echo "<h2>📤 File Upload Analysis</h2>";
    
    echo "<p><strong>Upload Option:</strong> " . ($_POST['upload_option'] ?? 'Not set') . "</p>";
    
    $fileKeys = ['kill_screenshot', 'position_screenshot', 'both_kill_screenshot', 'both_position_screenshot'];
    $uploadErrors = [
        0 => 'No error, file uploaded successfully',
        1 => 'File exceeds upload_max_filesize directive in php.ini',
        2 => 'File exceeds MAX_FILE_SIZE directive in HTML form',
        3 => 'File was only partially uploaded',
        4 => 'No file was uploaded',
        6 => 'Missing temporary folder',
        7 => 'Failed to write file to disk',
        8 => 'File upload stopped by PHP extension'
    ];
    
    echo "<table>";
    echo "<tr><th>File Input</th><th>Status</th><th>Size</th><th>Type</th><th>Error</th></tr>";
    
    foreach ($fileKeys as $key) {
        if (isset($_FILES[$key])) {
            $file = $_FILES[$key];
            $error = $file['error'];
            $errorMessage = $uploadErrors[$error] ?? 'Unknown error';
            $status = $error === 0 ? "<span class='success'>✅ OK</span>" : "<span class='error'>❌ Error</span>";
            $size = $file['size'] > 0 ? number_format($file['size'] / 1024, 2) . ' KB' : 'N/A';
            $type = $file['type'] ?? 'N/A';
            
            echo "<tr><td>{$key}</td><td>{$status}</td><td>{$size}</td><td>{$type}</td><td>{$errorMessage}</td></tr>";
        } else {
            echo "<tr><td>{$key}</td><td><span class='warning'>⚠️ Not set</span></td><td>N/A</td><td>N/A</td><td>Not uploaded</td></tr>";
        }
    }
    echo "</table>";
    echo "</div>";
}

// 7. Upload Test Form
echo "<div class='debug-section'>";
echo "<h2>🧪 Upload Test Form</h2>";
echo "<form method='POST' enctype='multipart/form-data'>";
echo "<p>Select upload option:</p>";
echo "<input type='radio' name='upload_option' value='kill' id='test_kill'> <label for='test_kill'>Kill Screenshot</label><br>";
echo "<input type='radio' name='upload_option' value='position' id='test_position'> <label for='test_position'>Position Screenshot</label><br>";
echo "<input type='radio' name='upload_option' value='both' id='test_both'> <label for='test_both'>Both Screenshots</label><br><br>";
echo "<p>Select a test image file (max 5MB):</p>";
echo "<input type='file' name='kill_screenshot' accept='image/*'><br><br>";
echo "<input type='submit' value='Test Upload Analysis'>";
echo "</form>";
echo "</div>";

echo "</body></html>";

// Helper function to convert PHP ini values to bytes
function convertToBytes($value) {
    $value = trim($value);
    $last = strtolower($value[strlen($value)-1]);
    $value = (int) $value;
    switch($last) {
        case 'g':
            $value *= 1024;
        case 'm':
            $value *= 1024;
        case 'k':
            $value *= 1024;
    }
    return $value;
}
?>
