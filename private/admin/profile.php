<?php
// Temporary: display errors for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

session_start();

// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once __DIR__ . '/admin_secure_config.php';

// Use the new admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

// Initialize Supabase connection
$supabase = getSupabaseConnection();

$success_message = '';
$error_message = '';

// Initialize table
$table_exists = true;

// Handle setting default image
if (isset($_POST['set_default']) && $table_exists) {
    $image_id = (int)$_POST['image_id'];
    
    try {
        // First, get all profile images to update them individually
        $all_images = $supabase->select('profile_images', 'id', []);
        
        // Set all images to not default
        $supabase->update('profile_images', ['is_default' => false], []);
        
        // Then set the selected image as default
        $supabase->update('profile_images', ['is_default' => true], ['id' => $image_id]);
        
        $success_message = "Default profile image set successfully!";
    } catch (Exception $e) {
        error_log("Error setting default image: " . $e->getMessage());
        $error_message = "Error setting default image: " . $e->getMessage();
    }
}

// Handle URL-based image upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_url_image']) && isset($_POST['image_url'])) {
    if (!$table_exists) {
        $error_message = "Cannot add images: The profile_images table doesn't exist. Please run the setup script first.";
    } else {
        $image_url = trim($_POST['image_url']);
        
        // Validate URL
        if (!filter_var($image_url, FILTER_VALIDATE_URL)) {
            $error_message = "Invalid URL format. Please enter a valid URL.";
        } else {
            try {
                // Check if URL is already in database
                $existing = $supabase->select('profile_images', 'id', ['image_path' => $image_url]);
                
                if (!empty($existing)) {
                    $error_message = "This image URL is already in the database.";
                } else {
                    // Add to database
                    $supabase->insert('profile_images', [
                        'image_path' => $image_url,
                        'is_active' => true
                    ]);
                    
                    $success_message = "Profile image URL added successfully!";
                }
            } catch (Exception $e) {
                error_log("Error adding image URL: " . $e->getMessage());
                $error_message = "Error saving image URL to database.";
            }
        }
    }
}

// File upload functionality removed - only URL-based images are supported

// Handle image deletion
if (isset($_POST['delete_image']) && $table_exists) {
    $image_id = (int)$_POST['image_id'];
    
    try {
        // Get image path before deletion
        $image = $supabase->select('profile_images', 'image_path', ['id' => $image_id]);
        
        if (!empty($image)) {
            // Delete from database
            $supabase->delete('profile_images', ['id' => $image_id]);
            
            $success_message = "Image deleted successfully!";
        } else {
            $error_message = "Image not found.";
        }
    } catch (Exception $e) {
        error_log("Error deleting image: " . $e->getMessage());
        $error_message = "Error deleting image.";
    }
}

// Handle image activation/deactivation
if (isset($_POST['toggle_status']) && $table_exists) {
    $image_id = (int)$_POST['image_id'];
    $new_status = (int)$_POST['new_status'];
    
    try {
        $supabase->update('profile_images', ['is_active' => (bool)$new_status], ['id' => $image_id]);
        $success_message = "Image status updated successfully!";
    } catch (Exception $e) {
        error_log("Error updating image status: " . $e->getMessage());
        $error_message = "Error updating image status.";
    }
}

// Get all profile images
$profile_images = [];
if ($table_exists) {
    try {
        $profile_images = $supabase->select(
            'profile_images', 
            '*', 
            [], 
            null
        );
    } catch (Exception $e) {
        error_log("Error fetching profile images: " . $e->getMessage());
        $profile_images = [];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile Images Management - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <style>
        .sidebar {
            min-height: 100vh;
            background-color: #343a40;
        }
        
        .nav-link {
            color: #fff;
            padding: 0.5rem 1rem;
            margin: 0.2rem 0;
            border-radius: 0.25rem;
        }
        
        .nav-link:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .nav-link.active {
            background-color: #0d6efd;
        }
        
        .profile-image {
            width: 100px;
            height: 100px;
            object-fit: cover;
            border-radius: 50%;
        }
        
        .image-card {
            transition: transform 0.2s;
            position: relative;
        }
        
        .image-card:hover {
            transform: translateY(-5px);
        }
        
        .upload-area {
            border: 2px dashed #ccc;
            border-radius: 10px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .upload-area:hover {
            border-color: #0d6efd;
            background-color: #f8f9fa;
        }
        
        .upload-area i {
            font-size: 2rem;
            color: #6c757d;
            margin-bottom: 10px;
        }
        
        .setup-alert {
            background-color: #fff3cd;
            border-color: #ffecb5;
            color: #664d03;
        }
        
        .url-image {
            max-width: 100%;
            height: auto;
            border-radius: 10px;
        }
        
        .default-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: #28a745;
            color: white;
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <nav class="col-md-3 col-lg-2 d-md-block sidebar collapse">
                <div class="position-sticky pt-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link text-white" href="dashboard/index.php">
                                <i class="bi bi-speedometer2"></i> Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white" href="users/index.php">
                                <i class="bi bi-people"></i> Users
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link active text-white" href="profile.php">
                                <i class="bi bi-person-circle"></i> Profile Images
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link text-white" href="logout.php">
                                <i class="bi bi-box-arrow-right"></i> Logout
                            </a>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- Main content -->
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4 py-4">
                <h1 class="h2 mb-4">Profile Images Management</h1>
                
                
                <?php if ($success_message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <?php echo $success_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <?php echo $error_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($table_exists): ?>
                    <!-- Add Profile Images Section -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Add Profile Image from URL</h5>
                        </div>
                        <div class="card-body">
                            <form action="" method="post">
                                <div class="mb-3">
                                    <label for="image_url" class="form-label">Image URL</label>
                                    <input type="url" class="form-control" id="image_url" name="image_url" placeholder="https://example.com/image.jpg" required>
                                    <div class="form-text">Enter the direct URL to an image (jpg, jpeg, png, gif). Only URL-based images are supported.</div>
                                </div>
                                <button type="submit" name="add_url_image" class="btn btn-primary">Add Image</button>
                            </form>
                        </div>
                    </div>
                    
                    <!-- Images Grid -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="card-title mb-0">Available Profile Images</h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($profile_images)): ?>
                                <div class="alert alert-info">
                                    No profile images available. Upload some images to get started.
                                </div>
                            <?php else: ?>
                                <div class="row row-cols-1 row-cols-md-3 row-cols-lg-4 g-4">
                                    <?php foreach ($profile_images as $image): ?>
                                        <div class="col">
                                            <div class="card h-100 image-card">
                                                <img src="<?php echo htmlspecialchars($image['image_path']); ?>" class="card-img-top profile-image mx-auto mt-3" alt="Profile Image">
                                                
                                                <?php if ($image['is_default']): ?>
                                                    <div class="default-badge">Default</div>
                                                <?php endif; ?>
                                                
                                                <div class="card-body text-center">
                                                    <p class="card-text">
                                                        <small class="text-muted">Added: <?php echo date('M d, Y', strtotime($image['created_at'])); ?></small>
                                                    </p>
                                                    <div class="btn-group" role="group">
                                                        <form action="" method="post" class="d-inline">
                                                            <input type="hidden" name="image_id" value="<?php echo $image['id']; ?>">
                                                            <input type="hidden" name="new_status" value="<?php echo $image['is_active'] ? '0' : '1'; ?>">
                                                            <button type="submit" name="toggle_status" class="btn btn-sm <?php echo $image['is_active'] ? 'btn-success' : 'btn-warning'; ?>">
                                                                <?php echo $image['is_active'] ? 'Active' : 'Inactive'; ?>
                                                            </button>
                                                        </form>
                                                        <?php if (!$image['is_default']): ?>
                                                            <form action="" method="post" class="d-inline">
                                                                <input type="hidden" name="image_id" value="<?php echo $image['id']; ?>">
                                                                <button type="submit" name="set_default" class="btn btn-sm btn-info">Set Default</button>
                                                            </form>
                                                        <?php endif; ?>
                                                        <form action="" method="post" class="d-inline">
                                                            <input type="hidden" name="image_id" value="<?php echo $image['id']; ?>">
                                                            <button type="submit" name="delete_image" class="btn btn-sm btn-danger">Delete</button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html> 