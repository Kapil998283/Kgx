<?php
// Only start session if one isn't already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../../private/includes/auth.php';

// Set JSON content type
header('Content-Type: application/json');

// Initialize AuthManager
$authManager = new AuthManager();

$response = ['success' => false, 'message' => ''];

try {
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'check_username':
                $username = trim($_POST['username'] ?? '');
                
                if (empty($username)) {
                    $response['message'] = 'Username is required';
                } elseif (strlen($username) < 3) {
                    $response['message'] = 'Username must be at least 3 characters long';
                } elseif (strlen($username) > 20) {
                    $response['message'] = 'Username must be less than 20 characters';
                } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
                    $response['message'] = 'Username can only contain letters, numbers, and underscores';
                } else {
                    $exists = $authManager->checkUsernameExists($username);
                    if ($exists) {
                        $response['message'] = 'Username is already taken';
                    } else {
                        $response['success'] = true;
                        $response['message'] = 'Username is available';
                    }
                }
                break;
                
            case 'check_email':
                $email = trim($_POST['email'] ?? '');
                
                if (empty($email)) {
                    $response['message'] = 'Email is required';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $response['message'] = 'Please enter a valid email address';
                } else {
                    $exists = $authManager->checkEmailExists($email);
                    if ($exists) {
                        $response['message'] = 'Email is already registered';
                    } else {
                        $response['success'] = true;
                        $response['message'] = 'Email is available';
                    }
                }
                break;
                
            case 'check_phone':
                $phone = trim($_POST['phone'] ?? '');
                
                if (empty($phone)) {
                    $response['message'] = 'Phone number is required';
                } elseif (!preg_match("/^\+[1-9]\d{6,14}$/", $phone)) {
                    $response['message'] = 'Please enter a valid phone number with country code';
                } else {
                    $exists = $authManager->checkPhoneExists($phone);
                    if ($exists) {
                        $response['message'] = 'Phone number is already registered';
                    } else {
                        $response['success'] = true;
                        $response['message'] = 'Phone number is available';
                    }
                }
                break;
                
            default:
                $response['message'] = 'Invalid action';
        }
    } else {
        $response['message'] = 'Invalid request method';
    }
} catch (Exception $e) {
    error_log("Validation error: " . $e->getMessage());
    $response['message'] = 'An error occurred during validation. Please try again.';
}

echo json_encode($response);
?>
