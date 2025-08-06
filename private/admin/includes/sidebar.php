<?php
// Get current page name and path
$current_page = basename($_SERVER['PHP_SELF']);
$current_path = $_SERVER['REQUEST_URI'];
?>

<nav id="sidebarMenu" class="col-md-3 col-lg-2 d-md-block sidebar collapse">
    <!-- Brand/Logo Section -->
    <div class="brand">
        <h3>KGX ADMIN</h3>
    </div>
    
    <div class="position-sticky">
        <ul class="nav flex-column">
            <li class="nav-item">
                <a class="nav-link <?php echo (strpos($current_path, '/dashboard/index.php') !== false || $current_path === '/KGX/private/admin/dashboard/' || $current_page === 'index.php') ? 'active' : ''; ?>" href="../dashboard/index.php">
                    <i class="bi bi-speedometer2"></i>
                    Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo strpos($current_path, '/users/') !== false ? 'active' : ''; ?>" href="../users/index.php">
                    <i class="bi bi-people"></i>
                    Users
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo strpos($current_path, '/tournament') !== false ? 'active' : ''; ?>" href="../tournament/index.php">
                    <i class="bi bi-trophy"></i>
                    Tournaments
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo strpos($current_path, '/matches/') !== false ? 'active' : ''; ?>" href="../matches/bgmi.php">
                    <i class="bi bi-controller"></i>
                    Matches
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo strpos($current_path, '/live-streams/') !== false ? 'active' : ''; ?>" href="../live-streams/index.php">
                    <i class="bi bi-broadcast"></i>
                    Live Streams
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo strpos($current_path, 'hero-settings') !== false ? 'active' : ''; ?>" href="../dashboard/hero-settings.php">
                    <i class="bi bi-image"></i>
                    Hero Settings
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo strpos($current_path, 'admin_dashboard') !== false ? 'active' : ''; ?>" href="../dashboard/admin_dashboard.php">
                    <i class="bi bi-gift"></i>
                    Redeemable Items
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo strpos($current_path, 'team-management') !== false ? 'active' : ''; ?>" href="../dashboard/team-management.php">
                    <i class="bi bi-people-fill"></i>
                    Teams Management
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo strpos($current_path, '/profile') !== false ? 'active' : ''; ?>" href="../profile.php">
                    <i class="bi bi-person"></i>
                    Profile
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" href="../logout.php">
                    <i class="bi bi-box-arrow-right"></i>
                    Logout
                </a>
            </li>
        </ul>
    </div>
</nav>

<style>
.sidebar {
    height: 100vh;
    position: fixed;
}

.sidebar .nav-link {
    padding: .5rem 1rem;
    color: #fff;
    opacity: 0.8;
}

.sidebar .nav-link:hover {
    opacity: 1;
}

.sidebar .nav-link.active {
    background-color: rgba(255,255,255,0.1);
    opacity: 1;
}

.sidebar .bi {
    margin-right: 8px;
}
</style> 