<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load Supabase configuration and auth
require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/SupabaseClient.php';
require_once __DIR__ . '/auth.php';

// Initialize Supabase client
$supabase = new SupabaseClient();

// Initialize variables
$ticket_count = 0;
$notification_count = 0;
$notifications = [];
$teams_url = 'teams/index.php'; // Updated URL
$header_profile_image = 'assets/images/profile/profile3.png'; // Default fallback image

// Get admin-defined default profile image (for all users)
try {
    $default_image_data = $supabase->select('profile_images', 'image_path', [
        'is_default' => true,
        'is_active' => true
    ], null, 1);
    
    if (!empty($default_image_data)) {
        $header_profile_image = $default_image_data[0]['image_path'];
    }
} catch (Exception $e) {
    error_log("Error fetching default profile image: " . $e->getMessage());
    // If we can't access profile_images, just use the fallback
 }

// Check if user is logged in (support both session formats)
$user_id = null;
if (isset($_SESSION['kgx_user_session']['user_id'])) {
    // New AuthManager session format
    $user_id = $_SESSION['kgx_user_session']['user_id'];
} elseif (isset($_SESSION['user_id'])) {
    // Legacy session format
    $user_id = $_SESSION['user_id'];
}

if ($user_id) {
    
    // Check user's team status
    try {
        $team_data = $supabase->select('team_members', 'id', ['user_id' => $user_id], null, 1);
        
        if (!empty($team_data)) {
            $teams_url = 'teams/yourteams.php'; // Updated URL
        }
    } catch (Exception $e) {
        error_log("Error checking team status: " . $e->getMessage());
    }

    // Get user's ticket count
    try {
        $ticket_data = $supabase->select('user_tickets', 'tickets', ['user_id' => $user_id], null, 1);
        
        $ticket_count = $ticket_data[0]['tickets'] ?? 0;
    } catch (Exception $e) {
        error_log("Error fetching tickets: " . $e->getMessage());
        $ticket_count = 0;
    }

    // Get user's notification count and notifications
    try {
        // Get unread notification count
        $unread_notifs = $supabase->select('notifications', 'id', [
            'user_id' => $user_id,
            'is_read' => false,
            'deleted_at' => 'is.null'
        ]);
        
        $notification_count = count($unread_notifs);
        
        // Get notifications (limit to 7 most recent)
        $notifications = $supabase->select('notifications', '*', [
            'user_id' => $user_id,
            'deleted_at' => 'is.null'
        ], ['created_at' => 'desc'], 7);
        
    } catch (Exception $e) {
        error_log("Error fetching notifications: " . $e->getMessage());
        $notification_count = 0;
        $notifications = [];
    }
}
?>


<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta http-equiv="X-UA-Compatible" content="IE=edge">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="manifest" href="manifest.json">
  <title>Esports Tournament Platform</title>

  <!-- favicon link -->
  <link rel="shortcut icon" href="<?php echo BASE_URL; ?>favicon.svg" type="image/svg+xml">

  <!-- Bootstrap Icons -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css" rel="stylesheet">

  <!-- custom css link -->
  <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/root.css">
  <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/header.css">
  <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/auth.css">

  <!-- google font link -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

  <link
    href="https://fonts.googleapis.com/css2?family=Oswald:wght@300;400;500;600;700&family=Poppins:wght@400;500;700&disparticipate=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css" />
  
  <!-- Ion Icons -->
  <script type="module" src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.esm.js"></script>
  <script nomodule src="https://unpkg.com/ionicons@7.1.0/dist/ionicons/ionicons.js"></script>

  <!-- Navbar JavaScript -->
  <script src="<?php echo BASE_URL; ?>assets/js/navbar.js"></script>

  <!-- Firebase Scripts -->
  <script src="https://www.gstatic.com/firebasejs/9.x.x/firebase-app.js"></script>
  <script src="https://www.gstatic.com/firebasejs/9.x.x/firebase-messaging.js"></script>
  <!-- Firebase config will be handled separately -->
  <script src="<?php echo BASE_URL; ?>assets/js/push-notifications.js"></script>
  
  <!-- Timezone Handler -->
  <script src="<?php echo BASE_URL; ?>assets/js/timezone-handler.js"></script>

</head>

<body id="top">

  <!-- HEADER -->
<header class="header">
  <div class="overlay" data-overlay></div>
  <div class="container">
    <!-- Main Logo (Desktop & Mobile) -->
<a href="<?php echo BASE_URL; ?>home.php" class="logo-main">
  <img src="<?php echo BASE_URL; ?>assets/images/logo.svg" alt="KGX Logo">
</a>

<!-- Additional Logo (Visible Only on Small Devices) -->
<a href="<?php echo BASE_URL; ?>home.php" class="logo-mobile-only">
  <img src="<?php echo BASE_URL; ?>favicon.svg" alt="KGX Mobile Logo">
</a>

    <!-- Desktop Navigation -->
    <nav class="navbar" data-nav>
      <div class="navbar-top">
        <a href="<?php echo BASE_URL; ?>home.php" class="logo">
          <img src="<?php echo BASE_URL; ?>assets/images/logo.svg" alt="KGX logo">
        </a>
        <button class="nav-close-btn" data-nav-close-btn>
          <ion-icon name="close-outline"></ion-icon>
        </button>
      </div>
      <ul class="navbar-list">
        <li><a href="<?php echo BASE_URL; ?>home.php" class="navbar-link">Home</a></li>
        <li><a href="<?php echo BASE_URL; ?>tournaments/index.php" class="navbar-link">Tournaments</a></li>
        <li><a href="<?php echo BASE_URL; ?>matches/index.php" class="navbar-link">Matches</a></li>
        <li><a href="<?php echo BASE_URL . $teams_url; ?>" class="navbar-link">Teams</a></li>
        <li><a href="<?php echo BASE_URL; ?>shop/index.php" class="navbar-link">Shop</a></li>
        <li><a href="<?php echo BASE_URL; ?>pages/community.php" class="navbar-link">Community</a></li>
      </ul>
    </nav>

    <!-- Header Actions (Right Side) -->
    <div class="header-actions">
        <?php if($user_id): ?>
          <!-- User is logged in - show ticket and notification only -->
          <div class="header-icons">
            <!-- ticket Section -->
            <div class="ticket-container">
              <button class="icon-button header-action-btn" id="ticket-btn">
                <ion-icon name="wallet-outline"></ion-icon>
              </button>
              <span class="ticket-text"><?php echo $ticket_count; ?></span>
              <span class="ticket-text" id="ticket-label"> Tickets</span>

              <!-- Dropdown Content -->
              <div class="dropdown-content" id="ticket-dropdown">
                <a href="<?php echo BASE_URL; ?>Shop/index.php" class="ticket-option">+ Add More Tickets</a>
                <button class="ticket-option">Share Tickets</button>
                <a href="<?php echo BASE_URL; ?>earn-coins/" class="ticket-option">You Can Earn Tickets</a>
              </div>
            </div>

            <!-- Notifications -->
            <div class="dropdown notification-container">
              <button class="icon-button header-action-btn" id="notif-btn">
                <ion-icon name="notifications-outline"></ion-icon>
                <?php if ($notification_count > 0): ?>
                <span class="notif-badge" id="notif-count"><?php echo $notification_count; ?></span>
              <?php endif; ?>
            </button>

              <!-- Notification Dropdown Content -->
              <div class="dropdown-content" id="notif-dropdown">
                <?php if (empty($notifications)): ?>
                  <div class="no-notifications">No notifications yet</div>
                <?php else: ?>
                  <?php foreach ($notifications as $notification): ?>
                    <div class="notif-item <?php echo $notification['is_read'] ? 'read' : 'unread'; ?>">
                      <div class="notif-content">
                        <?php 
                        // Safely display notification message with basic HTML formatting
                        $message = $notification['message'];
                        // Allow only safe HTML tags and convert newlines to <br>
                        $message = nl2br($message);
                        // Only allow specific safe HTML tags
                        $allowed_tags = '<b><strong><i><em><br>';
                        echo strip_tags($message, $allowed_tags);
                        ?>
                        <div class="notification-time"><?php echo date('M d, g:i a', strtotime($notification['created_at'])); ?></div>
                      </div>
                      <form method="POST" action="<?php echo BASE_URL; ?>pages/delete_notification.php" class="delete-notif-form">
                        <input type="hidden" name="notification_id" value="<?php echo $notification['id']; ?>">
                        <button type="submit" class="delete-notif-btn" title="Delete notification">
                          <ion-icon name="trash-outline"></ion-icon>
                        </button>
                      </form>
                    </div>
                  <?php endforeach; ?>
                  <div class="mark-read-container">
                    <a href="<?php echo BASE_URL; ?>pages/mark_notifications_read.php" class="mark-read-btn">Mark all as read</a>
                  </div>
                <?php endif; ?>
              </div>
            </div>

            <!-- Profile -->
            <div class="dropdown">
              <button class="profile-button header-action-btn" id="profile-btn">
                <img src="<?php echo htmlspecialchars($header_profile_image); ?>" alt="Profile Pic">
              </button>
              <div class="dropdown-content" id="profile-dropdown">
                <?php if ($user_id):
                ?>
                    <a href="<?php echo BASE_URL; ?>dashboard/index.php">Dashboard</a>
                    <a href="<?php echo BASE_URL; ?>pages/logout.php">Logout</a>
                <?php else:
                ?>
                    <a href="<?php echo BASE_URL; ?>register/login.php">Login</a>
                    <a href="<?php echo BASE_URL; ?>register/register.php">Register</a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php else: ?>
          <!-- User is not logged in - show sign in button -->
          <a href="<?php echo BASE_URL; ?>register/login.php">
            <button id="btn-signup">
              <div class="icon-box">
                <ion-icon name="log-in-outline"></ion-icon>
              </div>
              <span>Sign-in</span>
            </button>
          </a>
        <?php endif; ?>
    </div>

    <!-- Bottom Navigation Bar for Mobile -->
    <nav class="bottom-nav">
      <a href="<?php echo BASE_URL; ?>tournaments/index.php" class="bottom-nav-item" data-page="tournaments">
        <ion-icon name="trophy-outline"></ion-icon>
        <span>Tournaments</span>
      </a>
      <a href="<?php echo BASE_URL; ?>matches/index.php" class="bottom-nav-item" data-page="matches">
        <ion-icon name="game-controller-outline"></ion-icon>
        <span>Matches</span>
      </a>
      <a href="<?php echo BASE_URL; ?>home.php" class="bottom-nav-item" data-page="home">
        <ion-icon name="home"></ion-icon>
        <span>Home</span>
      </a>
      <a href="<?php echo BASE_URL . $teams_url; ?>" class="bottom-nav-item" data-page="teams">
        <ion-icon name="people-outline"></ion-icon>
        <span>Teams</span>
      </a>
      <?php if(isset($_SESSION['user_id'])): ?>
        <a href="<?php echo BASE_URL; ?>earn-coins/" class="bottom-nav-item earn-coins-nav-item" data-page="earn-coins">
          <ion-icon name="wallet-outline"></ion-icon>
          <span>Earn Coins</span>
        </a>
      <?php else: ?>
        <a href="<?php echo BASE_URL; ?>register/login.php" class="bottom-nav-item" data-page="login">
          <ion-icon name="log-in-outline"></ion-icon>
          <span>Login</span>
        </a>
      <?php endif; ?>
    </nav>
  </div>
</header>

<script>
document.addEventListener('DOMContentLoaded', function() {
  // Get current page path
  const currentPath = window.location.pathname;
  
  // Get all nav items
  const navItems = document.querySelectorAll('.bottom-nav-item');
  
  // Function to set active tab
  function setActiveTab() {
    navItems.forEach(item => {
      const itemPath = item.getAttribute('href');
      const pageName = item.getAttribute('data-page');
      
      // Remove active class from all items first
      item.classList.remove('active');
      
      // Check if current path matches this nav item's path
      if (currentPath.includes(pageName)) {
        item.classList.add('active');
      }
      
      // Special case for home page
      if (currentPath.endsWith('home.php') && pageName === 'home') {
        item.classList.add('active');
      }
    });
  }
  
  // Set initial active tab
  setActiveTab();
  
  // Add click handlers for smooth transitions
  navItems.forEach(item => {
    item.addEventListener('click', function(e) {
      // Remove active class from all items
      navItems.forEach(nav => nav.classList.remove('active'));
      // Add active class to clicked item
      this.classList.add('active');
    });
  });
});
</script>

<!-- Replace the entire script tag -->
<script>
document.addEventListener("DOMContentLoaded", function() {
  // DIRECT APPROACH: Add click events directly to each button
  const ticketBtn = document.getElementById('ticket-btn');
  const notifBtn = document.getElementById('notif-btn');
  const profileBtn = document.getElementById('profile-btn');

  // Function to toggle a specific dropdown
  function toggleDropdown(button, dropdownId) {
    if (!button) return;
    
    button.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();

      // Get the dropdown content element
      const dropdown = document.getElementById(dropdownId);
      if (!dropdown) return;

      // Get all dropdown contents
      const allDropdowns = document.querySelectorAll('.dropdown-content');
      
      // First hide all dropdowns
      allDropdowns.forEach(d => {
        if (d.id !== dropdownId) {
          d.style.display = 'none';
          d.classList.remove('show');
        }
      });
      
      // Toggle the clicked dropdown
      if (dropdown.style.display === 'block') {
        dropdown.style.display = 'none';
        dropdown.classList.remove('show');
      } else {
        dropdown.style.display = 'block';
        dropdown.classList.add('show');
      }
    });
  }
  
  // Apply to each button
  toggleDropdown(ticketBtn, 'ticket-dropdown');
  toggleDropdown(notifBtn, 'notif-dropdown');
  toggleDropdown(profileBtn, 'profile-dropdown');
  
  // Close all dropdowns when clicking elsewhere
  document.addEventListener('click', function() {
    document.querySelectorAll('.dropdown-content').forEach(dropdown => {
      dropdown.style.display = 'none';
      dropdown.classList.remove('show');
    });
  });
  
  // Prevent clicks inside dropdowns from closing them
  document.querySelectorAll('.dropdown-content').forEach(dropdown => {
    dropdown.addEventListener('click', function(e) {
      e.stopPropagation();
    });
  });
});
</script>

<!-- The following section appears to be duplicate navigation elements. 
     Commented out to fix layout issues. If needed, incorporate these links elsewhere. -->
<!-- 
<ul class="navbar-nav">
    <?php if(isset($_SESSION['user_id'])): ?>
        <li class="nav-item">
            <a class="nav-link" href="pages/profile.php">Profile</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="pages/logout.php">Logout</a>
        </li>
    <?php else: ?>
        <li class="nav-item">
            <a class="nav-link" href="pages/login.php">Login</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="pages/register.php">Register</a>
        </li>
    <?php endif; ?>
</ul>
-->

<!-- Add CSS to ensure dropdowns are properly styled -->
<style>

/* Default Dropdown (Perfect for small & medium screens) */
.dropdown-content {
  position: fixed; /* Keep fixed positioning */
  top: 120px; /* Adjusted to header height */
  right: 20px; /* Default alignment */
  background: rgba(20, 20, 20, 0.95);
  color: white;
  padding: 15px;
  width: 220px;
  border-radius: 10px;
  box-shadow: 0px 4px 10px rgba(0, 0, 0, 0.2);
  opacity: 0;
  visibility: hidden;
  transform: translateY(-10px);
  transition: transform 0.3s ease-out, opacity 0.3s ease-out;
}

/* Show dropdown when active */
.dropdown.active .dropdown-content {
  opacity: 1;
  visibility: visible;
  transform: translateY(0);
}

/* Fix for Larger Screens (Align Dropdown to Clicked Icon) */
@media screen and (min-width: 1200px) {
  .dropdown-content {
    right: auto; /* Remove fixed right positioning */
    left: 50%; /* Center align */
    transform: translateX(-50%) translateY(-10px); /* Center it */
  }

  .dropdown.active .dropdown-content {
    transform: translateX(-50%) translateY(0); /* Slide down animation */
  }
}


/* ticket Options & Notification Items */
.ticket-option, .notif-item, .dropdown-content a {
  display: block;
  padding: 10px;
  background: #222;
  margin-bottom: 5px;
  border-radius: 5px;
  text-align: left;
  font-size: 14px;
  transition: background 0.3s;
  color: white;
  text-decoration: none;
}

.ticket-option:hover, .notif-item:hover, .dropdown-content a:hover {
  background: #00ff55c2;
}

/* ticket Icon Container (Rounded Rectangle) */
.ticket-container {
  display: flex;
  align-items: center;
  background: rgba(20, 20, 20, 0.9); /* Dark transparent bg */
  padding: 8px 14px; /* More padding for text */
  border-radius: 15px; /* Makes it a cylinder shape */
  gap: 8px; /* Space between icon and text */
  transition: all 0.3s ease-in-out;
}

/* ticket Icon */
.ticket-icon {
  font-size: 20px; /* Increase icon size */
  color: white;
}

/* Ticket Amount Text */
.ticket-text {
  font-size: 14px;
  font-weight: bold;
  color: white;
}

/* Hide "Tickets" text + Reduce container size on small screens */
@media (max-width: 768px) {
  #ticket-label { 
    display: none !important; /* Hide "Tickets" text */
  }

  .ticket-container {
    padding: 8px 10px; /* Reduce padding */
    border-radius: 20px; /* Adjust shape */
    gap: 4px; /* Reduce space between icon and number */
  }
}


/* Special styling for notifications */
.notif-item {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  padding: 10px;
  margin-bottom: 5px;
  background: #222;
  border-radius: 5px;
  transition: all 0.3s ease;
}

.notif-content {
  flex: 1;
  margin-right: 10px;
}

.delete-notif-btn {
  background: none;
  border: none;
  color: #666;
  cursor: pointer;
  padding: 5px;
  transition: all 0.3s ease;
}

.delete-notif-btn:hover {
  color: #ff4444;
  transform: scale(1.1);
}

.delete-notif-btn ion-icon {
  font-size: 16px;
}

/* Notification badge */
.notification {
  position: relative;
  cursor: pointer;
  color: var(--white);
  font-size: 20px;
}

.notification .badge {
  position: absolute;
  top: -5px;
  right: -5px;
  background: var(--orange);
  color: var(--raisin-black-1);
  font-size: 12px;
  padding: 2px 5px;
  border-radius: 50%;
}


/* Notification Container */
.notification-container {
  position: relative;
}

/* Notification Button */
.icon-button {
  position: relative;
  font-size: 22px;
  color: white;
}

/* Notification Badge (Red Circle) */
.notif-badge {
  position: absolute;
  top: -3px; /* Adjust based on your icon */
  right: -3px;
  background: red;
  color: white;
  font-size: 12px;
  font-weight: bold;
  padding: 3px 6px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  min-height: 18px;
}

/* Notification Dropdown */
#notif-dropdown {
  max-height: 250px; /* Limit dropdown height (adjust if needed) */
  overflow-y: auto; /* Enable scrolling */
}

/* Custom Scrollbar (Optional) */
#notif-dropdown::-webkit-scrollbar {
  width: 6px;
}

#notif-dropdown::-webkit-scrollbar-thumb {
  background: rgba(255, 255, 255, 0.5); /* Light scrollbar */
  border-radius: 10px;
}

#notif-dropdown::-webkit-scrollbar-track {
  background: rgba(255, 255, 255, 0.1);
}


/* ticket & Bell Icon Styles */
.icon-button {
  background: rgba(255, 255, 255, 0.1); /* Semi-transparent circle */
  border-radius: 50%; /* Makes it circular */
  width: 45px; /* Increased size */
  height: 45px; /* Ensures perfect circle */
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 28px; /* Increased icon size */
  color: white;
  transition: all 0.3s ease-in-out;
}

/* Hover effect - Green */
.icon-button:hover {
  background: rgba(221, 230, 224, 0.248); /* Green hover effect */
  color: #00ff55;
}

/* Profile Icon */
.profile-button img {
  width: 55px; /* Increased size */
  height: 55px; /* Larger for balance */
  border-radius: 50%;
  object-fit: cover;
  border: 2px solid white;
  transition: transform 0.3s ease-in-out;
}

/* Profile Hover Effect */
.profile-button:hover img {
  transform: scale(1.1); /* Slight zoom on hover */
}

/* Responsive Adjustments */
@media (max-width: 768px) {
  .dropdown-content {
    width: 180px;
  }
}

/* Hover & Click Effect - Change color to Green */
.icon-button:hover,
.icon-button:focus,
.profile-button:hover,
.profile-button:focus {
  color: #28a745; /* Green color */
}

/* Make sure icons remain white initially */
.icon-button,
.profile-button {
  color: var(--white);
  transition: color 0.3s ease-in-out;
}

/* Force dropdown to align with the right side of the screen */
.dropdown-content {
  right: 0;
  left: auto; /* Prevent it from moving left */
  min-width: 220px;
  max-width: 280px;
}

/* Ensure dropdown does not overflow screen on small screens */
@media (max-width: 500px) {
  .dropdown-content {
    width: 180px;
    right: 5px; /* Adjust to avoid overflow */
  }
}

/* Notification styles */
.notif-item.unread {
  background: rgba(0, 255, 85, 0.1); /* Green highlight for unread */
  border-left: 3px solid #00ff55;
}

.notif-item.read {
  opacity: 0.7;
}

.notification-time {
  font-size: 11px;
  color: #999;
  margin-top: 5px;
  text-align: right;
}

.no-notifications {
  padding: 15px;
  text-align: center;
  color: #aaa;
  font-style: italic;
}

.mark-read-container {
  padding: 10px;
  text-align: center;
  border-top: 1px solid #333;
  margin-top: 10px;
}

.mark-read-btn {
  background: #111;
  color: #00ff55;
  border: 1px solid #00ff55;
  padding: 5px 10px;
  border-radius: 4px;
  text-decoration: none;
  font-size: 12px;
  transition: all 0.3s;
}

.mark-read-btn:hover {
  background: #00ff55;
  color: #111;
}

/* Show dropdown through direct style (backup to JS approach) */
#ticket-dropdown.show, 
#notif-dropdown.show, 
#profile-dropdown.show {
  display: block;
  opacity: 1;
  visibility: visible;
}

/* Ensure dropdown does not overflow screen on small screens */
@media (max-width: 500px) {
  .dropdown-content {
    width: 180px;
    right: 5px; /* Adjust to avoid overflow */
  }
}

</style>

<?php // End of header include - main content goes after this ?>
