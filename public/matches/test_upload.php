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

$success = '';
$error = '';

// Check if user is logged in
if (!$authManager->isLoggedIn()) {
    header('Location: ../register/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$currentUser = $authManager->getCurrentUser();
$user_id = $currentUser['user_id'];

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['test_screenshot'])) {
    try {
        $file = $_FILES['test_screenshot'];
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('File upload error: ' . $file['error']);
        }
        
        $originalFilename = $file['name'];
        $tmpPath = $file['tmp_name'];
        $fileSize = $file['size'];
        
        // Validate file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);
        
        if (!in_array($mimeType, $allowedTypes)) {
            throw new Exception('Only JPEG, PNG, and WebP images are allowed.');
        }
        
        // Validate file size (5MB limit)
        if ($fileSize > 5 * 1024 * 1024) {
            throw new Exception('File size must be less than 5MB.');
        }
        
        // Generate unique filename
        $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
        $filename = "test_user_{$user_id}_" . uniqid() . ".{$extension}";
        
        // Upload to Supabase Storage
        $uploadResult = $supabaseClient->uploadFile('match-screenshots', $filename, $tmpPath, $mimeType);
        
        // Get public URL
        $imageUrl = $supabaseClient->getPublicUrl('match-screenshots', $filename);
        
        $success = "✅ <strong>SUCCESS!</strong> File uploaded successfully!<br>";
        $success .= "📁 <strong>Filename:</strong> {$filename}<br>";
        $success .= "🔗 <strong>URL:</strong> <a href='{$imageUrl}' target='_blank'>{$imageUrl}</a><br>";
        $success .= "📊 <strong>File Size:</strong> " . number_format($fileSize / 1024, 2) . " KB<br>";
        $success .= "🎨 <strong>Type:</strong> {$mimeType}<br>";
        $success .= "<br><img src='{$imageUrl}' style='max-width: 300px; border-radius: 8px; border: 2px solid #4CAF50;'>";
        
    } catch (Exception $e) {
        $error = '❌ Upload failed: ' . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Test Upload</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; max-width: 800px; }
        .success { color: green; padding: 15px; background: #f0f8f0; border-radius: 5px; margin: 10px 0; }
        .error { color: red; padding: 15px; background: #fff0f0; border-radius: 5px; margin: 10px 0; }
        .form-group { margin: 15px 0; }
        label { display: block; margin-bottom: 5px; font-weight: bold; }
        input[type="file"] { padding: 10px; border: 2px dashed #ccc; border-radius: 5px; width: 100%; }
        input[type="submit"] { background: #4CAF50; color: white; padding: 12px 24px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; }
        input[type="submit"]:hover { background: #45a049; }
        .info { background: #f0f8ff; padding: 15px; border-radius: 5px; margin: 10px 0; }
    </style>
</head>
<body>
    <h1>🧪 Simple Upload Test</h1>
    
    <div class="info">
        <strong>👤 User:</strong> <?= htmlspecialchars($currentUser['username']) ?> (ID: <?= $user_id ?>)<br>
        <strong>📝 Purpose:</strong> Test direct upload to Supabase Storage (bypasses database for now)<br>
        <strong>📁 Bucket:</strong> match-screenshots<br>
        <strong>📏 Limits:</strong> 5MB max, JPEG/PNG/WebP only
    </div>

    <?php if ($success): ?>
        <div class="success"><?= $success ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="error"><?= $error ?></div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <div class="form-group">
            <label for="test_screenshot">Select Image File:</label>
            <input type="file" name="test_screenshot" id="test_screenshot" accept="image/*" required>
        </div>
        
        <input type="submit" value="🚀 Upload Test Image">
    </form>

    <br><br>
    <a href="storage_debug.php">🔍 Debug Tool</a> | 
    <a href="make_bucket_public.php">🔓 Make Bucket Public</a> | 
    <a href="index.php">🎮 Back to Matches</a>

</body>
</html>
