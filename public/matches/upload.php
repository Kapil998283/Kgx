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
loadSecureInclude('DebugLogger.php');

// Initialize debug logger
$logger = new DebugLogger('upload_debug.log');

// Initialize AuthManager and SupabaseClient
$authManager = new AuthManager();
$supabaseClient = new SupabaseClient();

// Check if user is logged in
if (!$authManager->isLoggedIn()) {
    header('Location: ../register/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$currentUser = $authManager->getCurrentUser();
$user_id = $currentUser['user_id'];
$match_id = isset($_GET['match_id']) ? (int)$_GET['match_id'] : 0;

if (!$match_id) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';
$match = null;
$existingScreenshots = [];

// Fetch match details and verify user participation
try {
    // Get match details
    $matches = $supabaseClient->select('matches', '*', ['id' => $match_id]);
    if (empty($matches)) {
        header('Location: index.php');
        exit;
    }
    $match = $matches[0];
    
    // Verify user is participant in this match
    $participation = $supabaseClient->select('match_participants', '*', [
        'match_id' => $match_id,
        'user_id' => $user_id
    ]);
    
    if (empty($participation)) {
        $error = 'You are not a participant in this match.';
    } elseif ($match['status'] !== 'in_progress') {
        $error = 'You can only upload screenshots for matches that are currently in progress.';
    }
    
    // Get game details
    if (!$error) {
        $games = $supabaseClient->select('games', '*', ['id' => $match['game_id']]);
        $match['game_name'] = !empty($games) ? $games[0]['name'] : 'Unknown Game';
    }
    
    // Get existing screenshots for this user and match
    if (!$error) {
        $existingScreenshots = $supabaseClient->select('match_screenshots', '*', [
            'match_id' => $match_id,
            'user_id' => $user_id
        ]);
        
        // Check if user has reached the upload limit (4 uploads per match)
        if (count($existingScreenshots) >= 4) {
            $error = 'You have reached the maximum limit of 4 uploads per match. Please delete existing uploads if you want to upload new ones.';
        }
    }
    
} catch (Exception $e) {
    $error = 'Error loading match details: ' . $e->getMessage();
}

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $logger->log('POST request detected. Starting upload process.');
    $logger->log('Match ID: ' . $match_id);
    $logger->log('User ID: ' . $user_id);
    $logger->log('POST data: ' . json_encode($_POST));
    $logger->log('FILES data: ' . json_encode($_FILES));
    
    try {
        // Validate form inputs for new simplified structure
        $kills_claimed = isset($_POST['kills_claimed']) ? (int)$_POST['kills_claimed'] : 0;
        $rank_claimed = isset($_POST['rank_claimed']) && $_POST['rank_claimed'] !== '' ? (int)$_POST['rank_claimed'] : null;
        $won_match = isset($_POST['won_match']) && $_POST['won_match'] === 'yes';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';
        
        // Debug logging for form inputs
        $logger->log('Kills claimed: ' . $kills_claimed);
        $logger->log('Rank claimed (raw): ' . (isset($_POST['rank_claimed']) ? $_POST['rank_claimed'] : 'not set'));
        $logger->log('Rank claimed (processed): ' . ($rank_claimed !== null ? $rank_claimed : 'null'));
        $logger->log('Won match: ' . ($won_match ? 'yes' : 'no'));
        $logger->log('Description: ' . $description);
        
        // Validate basic inputs
        if ($kills_claimed < 0 || $kills_claimed > 50) {
            throw new Exception('Kills must be between 0 and 50.');
        }
        
        if ($rank_claimed !== null && ($rank_claimed < 1 || $rank_claimed > 100)) {
            throw new Exception('Rank must be between 1 and 100.');
        }
        
        // Validate required screenshot
        if (!isset($_FILES['result_screenshot']) || $_FILES['result_screenshot']['error'] !== UPLOAD_ERR_OK) {
            $logger->log('Result screenshot error: ' . (isset($_FILES['result_screenshot']) ? $_FILES['result_screenshot']['error'] : 'not set'));
            throw new Exception('Please select a valid match result screenshot.');
        }
        
        $filesToProcess = [
            ['file' => $_FILES['result_screenshot'], 'type' => 'result']
        ];
        
        // No additional screenshots needed for winners - just the toggle confirmation
        
        $uploadedFiles = [];
        $successMessages = [];
        
        // Process each file
        foreach ($filesToProcess as $fileData) {
            $file = $fileData['file'];
            $fileType = $fileData['type'];
            
            $originalFilename = $file['name'];
            $tmpPath = $file['tmp_name'];
            $fileSize = $file['size'];
            
            // Validate file type
            $allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
            
            if (!in_array($mimeType, $allowedTypes)) {
                throw new Exception('Only JPEG, PNG, and WebP images are allowed for ' . $fileType . ' screenshot.');
            }
            
            // Validate file size (5MB limit)
            if ($fileSize > 5 * 1024 * 1024) {
                throw new Exception($fileType . ' screenshot file size must be less than 5MB.');
            }
            
            // Generate unique filename
            $extension = 'jpg'; // default
            switch ($mimeType) {
                case 'image/jpeg':
                    $extension = 'jpg';
                    break;
                case 'image/png':
                    $extension = 'png';
                    break;
                case 'image/webp':
                    $extension = 'webp';
                    break;
            }
            
            $filename = "user_{$user_id}/match_{$match_id}/" . uniqid() . "_{$fileType}.{$extension}";
            $logger->log('Generated filename for ' . $fileType . ': ' . $filename);
            
            // Upload to Supabase Storage
            $logger->log('Starting ' . $fileType . ' file upload to Supabase Storage...');
            $uploadResult = $supabaseClient->uploadFile('match-screenshots', $filename, $tmpPath, $mimeType);
            $logger->log('Upload result for ' . $fileType . ': ' . json_encode($uploadResult));
            
            // Get public URL
            $imageUrl = $supabaseClient->getPublicUrl('match-screenshots', $filename);
            $logger->log('Generated public URL for ' . $fileType . ': ' . $imageUrl);
            
            // Prepare data for database
            $screenshotData = [
                'match_id' => $match_id,
                'user_id' => $user_id,
                'image_path' => $filename,
                'image_url' => $imageUrl,
                'original_filename' => $originalFilename,
                'file_size' => $fileSize,
                'upload_type' => $fileType,
                'description' => $description,
                'kills_claimed' => $kills_claimed,
                'rank_claimed' => $rank_claimed,
                'won_match' => $won_match,
                'verified' => false
            ];
            
            $logger->log('Preparing to insert ' . $fileType . ' data: ' . json_encode($screenshotData));
            $result = $supabaseClient->insert('match_screenshots', $screenshotData);
            $logger->log('Insert result for ' . $fileType . ': ' . json_encode($result));
            
            // Check for success - Supabase can return null, array, or success object
            $insertSuccess = false;
            if ($result) {
                if (is_array($result) && isset($result['success']) && $result['success']) {
                    // New success format
                    $insertSuccess = true;
                } elseif (is_array($result) && !empty($result)) {
                    // Array of inserted data
                    $insertSuccess = true;
                } elseif ($result === true) {
                    // Boolean true
                    $insertSuccess = true;
                }
            }
            
            if ($insertSuccess) {
                $uploadedFiles[] = $originalFilename;
                $successMessages[] = '📄 ' . ucfirst($fileType) . ' Screenshot: ' . htmlspecialchars($originalFilename);
                $logger->log('Successfully processed ' . $fileType . ' screenshot: ' . $originalFilename);
            } else {
                throw new Exception('Failed to save ' . $fileType . ' screenshot to database.');
            }
        }
        
        if (!empty($uploadedFiles)) {
            $filesInfo = implode('<br>• ', $successMessages);
            $winnerText = $won_match ? 'Yes (Winner!)' : 'No';
            $success = '🎉 SUCCESS! Your screenshot(s) have been uploaded successfully! ✅<br><br>📄 <strong>Uploaded Files:</strong><br>• ' . $filesInfo . '<br><br>📊 <strong>Details:</strong><br>• Kills: ' . ($kills_claimed > 0 ? $kills_claimed : 'N/A') . '<br>• Rank: ' . ($rank_claimed ?? 'N/A') . '<br>• Won Match: ' . $winnerText . '<br><br>⏳ <strong>Status:</strong> Pending admin verification<br>🔍 Your screenshots will be reviewed by our admin team shortly.';
            
            // Refresh existing screenshots
            $existingScreenshots = $supabaseClient->select('match_screenshots', '*', [
                'match_id' => $match_id,
                'user_id' => $user_id
            ]);
        }
        
    } catch (Exception $e) {
        $logger->log('Exception caught: ' . $e->getMessage());
        $logger->log('Exception trace: ' . $e->getTraceAsString());
        $error = 'Upload failed: ' . $e->getMessage();
    }
}

loadSecureInclude('header.php');
?>

<link rel="stylesheet" href="../assets/css/matches/upload.css">

<?php
// Render HTML
?>

<div class="upload-container">
    <h1>Upload Match Result for <?= htmlspecialchars($match['game_name']) ?> - Match #<?= htmlspecialchars($match_id) ?></h1>
    <?php if ($error): ?>
        <div class="alert alert-danger"> <?= htmlspecialchars($error) ?> </div>
    <?php elseif ($success): ?>
        <div class="alert alert-success"> <?= $success ?> </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" id="upload-form">
        <!-- Single Upload Card -->
        <div class="form-group">
            <label>Upload Match Result:</label>
            <div class="upload-options">
                <!-- Main Upload Card -->
                <div class="option-card selected" data-option="result">
                    <div class="option-header">
                        <i class="bi bi-controller option-icon"></i>
                        <h3>Match Result Screenshot</h3>
                    </div>
                    <p>Upload your match result screenshot</p>
                    
                    
                    <!-- Embedded Upload Section -->
                    <div class="card-upload-section" id="result-card-content" style="display: block;">
                        <div class="upload-divider"></div>
                        
                        <!-- Result Screenshot Upload -->
                        <div class="embedded-form-group">
                            <label for="result_screenshot">Match Result Screenshot (JPEG, PNG, WebP):</label>
                            <div class="custom-file-upload" id="result-upload-area">
                                <i class="bi bi-controller upload-icon"></i>
                                <span class="upload-text">Click to select result screenshot or drag & drop</span>
                                <input type="file" name="result_screenshot" id="result_screenshot" accept="image/jpeg,image/png,image/webp" style="display: none;" required>
                            </div>
                            <div id="result-file-preview" style="display: none; margin-top: 10px;">
                                <img id="result-preview-image" style="max-width: 200px; border-radius: 8px;">
                                <p id="result-file-name"></p>
                            </div>
                        </div>
                        
                        <!-- Winner Toggle Button -->
                        <div class="embedded-form-group">
                            <div class="winner-toggle-container">
                                <span class="toggle-question">🏆 Did you win this match?</span>
                                <label class="toggle-switch">
                                    <input type="checkbox" name="won_match" value="yes" id="won_match">
                                    <span class="toggle-slider">
                                        <span class="toggle-text toggle-no">NO</span>
                                        <span class="toggle-text toggle-yes">YES</span>
                                    </span>
                                </label>
                            </div>
                        </div>
                        
                        <!-- Winner confirmation note -->
                        <div class="embedded-form-group" id="winner-confirmation-note" style="display: none;">
                            <div class="winner-note">
                                <i class="bi bi-info-circle"></i>
                                <p><strong>Winner Confirmation:</strong> You've marked yourself as the winner. The admin will manually verify this claim based on your result screenshot and match data.</p>
                            </div>
                        </div>
                        
                        <!-- Stats Inputs -->
                        <div class="embedded-form-row">
                            <div class="embedded-form-group half-width">
                                <label for="kills_claimed">Kills Claimed:</label>
                                <input type="number" name="kills_claimed" id="kills_claimed" min="0" max="50" value="0">
                            </div>
                            <div class="embedded-form-group half-width">
                                <label for="rank_claimed">Rank Claimed (Optional):</label>
                                <input type="number" name="rank_claimed" id="rank_claimed" min="1" max="100" placeholder="Enter your final rank">
                            </div>
                        </div>
                        
                        <!-- Description -->
                        <div class="embedded-form-group">
                            <label for="description">Description:</label>
                            <textarea name="description" id="description" placeholder="Optional details about your match performance"></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary" id="upload-submit-btn">Upload Screenshot(s)</button>
    </form>

    <h2>Existing Uploads:</h2>
    <div class="screenshots-list">
        <?php if (empty($existingScreenshots)): ?>
            <p style="text-align: center; color: var(--light-gray); font-style: italic; padding: 40px;">No screenshots uploaded yet.</p>
        <?php else: ?>
            <ul>
        <?php foreach ($existingScreenshots as $screenshot): ?>
                    <li>
                        <img src="<?= htmlspecialchars($screenshot['image_url']) ?>" alt="Screenshot">
                        <div class="screenshot-info">
                            <p><strong>Type:</strong> <?= htmlspecialchars(ucfirst($screenshot['upload_type'])) ?></p>
                            <p><strong>Kills:</strong> <?= htmlspecialchars($screenshot['kills_claimed']) ?></p>
                            <p><strong>Rank:</strong> <?= $screenshot['rank_claimed'] !== null ? htmlspecialchars($screenshot['rank_claimed']) : 'N/A' ?></p>
                            <p><strong>Won Match:</strong> <?= isset($screenshot['won_match']) && $screenshot['won_match'] ? 'Yes' : 'No' ?></p>
                            <p><strong>Status:</strong> 
                                <span class="status-badge <?= $screenshot['verified'] ? 'status-verified' : 'status-pending' ?>">
                                    <?= $screenshot['verified'] ? 'Verified' : 'Pending Verification' ?>
                                </span>
                            </p>
                            <p><strong>Description:</strong> <?= htmlspecialchars($screenshot['description'] ?? 'No description') ?></p>
                            <p><strong>Uploaded:</strong> <?= date('M j, Y g:i A', strtotime($screenshot['uploaded_at'] ?? 'now')) ?></p>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <a href="index.php" class="btn btn-secondary">Back to Matches</a>
</div>

<script>
// Simplified upload functionality
document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM Content Loaded - Starting simplified upload functionality');
    
    const wonMatchCheckbox = document.getElementById('won_match');
    const winnerConfirmationNote = document.getElementById('winner-confirmation-note');
    
    // Handle winner checkbox change
    wonMatchCheckbox.addEventListener('change', function() {
        if (this.checked) {
            winnerConfirmationNote.style.display = 'block';
        } else {
            winnerConfirmationNote.style.display = 'none';
        }
    });
    
    // File upload handler for result screenshot only
    setupFileUpload('result-upload-area', 'result_screenshot', 'result-file-preview', 'result-preview-image', 'result-file-name');
    
    function setupFileUpload(uploadAreaId, fileInputId, previewId, previewImageId, fileNameId) {
        const uploadArea = document.getElementById(uploadAreaId);
        const fileInput = document.getElementById(fileInputId);
        const filePreview = document.getElementById(previewId);
        const previewImage = document.getElementById(previewImageId);
        const fileName = document.getElementById(fileNameId);
        
        if (!uploadArea || !fileInput) {
            console.error('Missing elements for file upload setup:', uploadAreaId);
            return;
        }
        
        // Click to select file
        uploadArea.addEventListener('click', function() {
            fileInput.click();
        });
        
        // File selection handler
        fileInput.addEventListener('change', function(e) {
            handleFileSelect(e.target.files[0], previewImage, fileName, filePreview);
        });
        
        // Drag and drop handlers
        uploadArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        });
        
        uploadArea.addEventListener('dragleave', function(e) {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
        });
        
        uploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                handleFileSelect(files[0], previewImage, fileName, filePreview);
                // Set the file to the input for form submission
                const dt = new DataTransfer();
                dt.items.add(files[0]);
                fileInput.files = dt.files;
            }
        });
    }
    
    function handleFileSelect(file, previewImage, fileName, filePreview) {
        if (file && file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = function(e) {
                if (previewImage) previewImage.src = e.target.result;
                if (fileName) fileName.textContent = file.name;
                if (filePreview) filePreview.style.display = 'block';
            };
            reader.readAsDataURL(file);
        }
    }
    
    // Form validation before submission
    const uploadForm = document.getElementById('upload-form');
    if (uploadForm) {
        uploadForm.addEventListener('submit', function(e) {
            const resultFile = document.getElementById('result_screenshot').files[0];
            
            if (!resultFile) {
                e.preventDefault();
                alert('Please select a match result screenshot.');
                return false;
            }
            
            console.log('Form validation passed, submitting form...');
            return true;
        });
    }
});
</script>

<?php loadSecureInclude('footer.php'); ?>
