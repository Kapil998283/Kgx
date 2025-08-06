<?php
// CRITICAL: Suppress ALL error output to prevent corrupting HTML title
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(0);

// Set error handler to suppress all output
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("Users Index Error: $errstr in $errfile on line $errline");
    return true; // Don't execute PHP internal error handler
});

// Define admin secure access (only if not already defined)
if (!defined('ADMIN_SECURE_ACCESS')) {
    define('ADMIN_SECURE_ACCESS', true);
}

// Load admin secure configuration
require_once dirname(__DIR__) . '/admin_secure_config.php';

// Load admin configuration with error handling
try {
    $adminConfig = loadAdminConfig('admin_config.php');
    if (!$adminConfig || !is_array($adminConfig)) {
        $adminConfig = ['system' => ['name' => 'KGX Admin']];
    }
} catch (Exception $e) {
    error_log("Admin config error: " . $e->getMessage());
    $adminConfig = ['system' => ['name' => 'KGX Admin']];
}

// Use the new admin authentication system
require_once ADMIN_INCLUDES_PATH . 'admin-auth.php';

// Admin is automatically authenticated by admin-auth.php

// Initialize Supabase connection
$supabase = getSupabaseConnection();

try {
    // Handle search
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    
    // Pagination
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $per_page = 10;
    $offset = ($page - 1) * $per_page;
    
    // Build filter conditions for Supabase
    $filters = ['role' => 'user'];
    
    if ($search) {
        // Enhanced search with better deduplication
        $users_data = [];
        $total_users = 0;
        
        try {
            $all_search_results = [];
            $seen_ids = [];
            
            // Search by username
            try {
                $username_results = $supabase->select('users', '*', [
                    'username' => "ilike.%{$search}%",
                    'role' => 'user'
                ]);
                
                if ($username_results && is_array($username_results)) {
                    foreach ($username_results as $user) {
                        if (isset($user['id']) && !in_array($user['id'], $seen_ids)) {
                            $all_search_results[] = $user;
                            $seen_ids[] = $user['id'];
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Username search error: " . $e->getMessage());
            }
            
            // Search by email
            try {
                $email_results = $supabase->select('users', '*', [
                    'email' => "ilike.%{$search}%",
                    'role' => 'user'
                ]);
                
                if ($email_results && is_array($email_results)) {
                    foreach ($email_results as $user) {
                        if (isset($user['id']) && !in_array($user['id'], $seen_ids)) {
                            $all_search_results[] = $user;
                            $seen_ids[] = $user['id'];
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Email search error: " . $e->getMessage());
            }
            
            // Search by ID if search term looks like an ID
            if (is_numeric($search) || preg_match('/^[a-f0-9\-]{8,}$/i', $search)) {
                try {
                    $id_results = $supabase->select('users', '*', [
                        'id' => "ilike.%{$search}%",
                        'role' => 'user'
                    ]);
                    
                    if ($id_results && is_array($id_results)) {
                        foreach ($id_results as $user) {
                            if (isset($user['id']) && !in_array($user['id'], $seen_ids)) {
                                $all_search_results[] = $user;
                                $seen_ids[] = $user['id'];
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log("ID search error: " . $e->getMessage());
                }
            }
            
            // Sort by created_at descending
            usort($all_search_results, function($a, $b) {
                $timeA = strtotime($a['created_at'] ?? '1970-01-01');
                $timeB = strtotime($b['created_at'] ?? '1970-01-01');
                return $timeB - $timeA;
            });
            
            $total_users = count($all_search_results);
            $users_data = array_slice($all_search_results, $offset, $per_page);
            
        } catch (Exception $e) {
            error_log("Search error: " . $e->getMessage());
            $users_data = [];
            $total_users = 0;
        }
    } else {
        // Get all users without search - also fix duplicates here
        try {
            $all_users = $supabase->select('users', '*', $filters, 'created_at.desc');
            
            // Remove duplicates based on ID
            $unique_users = [];
            $seen_ids = [];
            
            if ($all_users && is_array($all_users)) {
                foreach ($all_users as $user) {
                    if (isset($user['id']) && !in_array($user['id'], $seen_ids)) {
                        $unique_users[] = $user;
                        $seen_ids[] = $user['id'];
                    }
                }
            }
            
            $total_users = count($unique_users);
            $users_data = array_slice($unique_users, $offset, $per_page);
            
        } catch (Exception $e) {
            error_log("Users fetch error: " . $e->getMessage());
            $users_data = [];
            $total_users = 0;
        }
    }
    
    $total_pages = ceil($total_users / $per_page);
    
    // Enhance user data with coins and tickets
    foreach ($users_data as &$user) {
        try {
            // Get user coins
            $coins_data = $supabase->select('user_coins', 'coins', ['user_id' => $user['id']]);
            $user['coins'] = !empty($coins_data) ? $coins_data[0]['coins'] : 0;
            
            // Get user tickets  
            $tickets_data = $supabase->select('user_tickets', 'tickets', ['user_id' => $user['id']]);
            $user['tickets'] = !empty($tickets_data) ? $tickets_data[0]['tickets'] : 0;
        } catch (Exception $e) {
            error_log("User enhancement error for user {$user['id']}: " . $e->getMessage());
            $user['coins'] = 0;
            $user['tickets'] = 0;
        }
    }
    
} catch (Exception $e) {
    error_log("Users page error: " . $e->getMessage());
    $users_data = [];
    $total_users = 0;
    $total_pages = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.7.2/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../assets/css/root.css">
    <link rel="stylesheet" href="../assets/css/user/index.css">
</head>
<body>
    <div class="users-index">
        <!-- Header Section -->
        <div class="users-header">
            <div>
                <h1 class="users-title">User Management</h1>
                <p class="users-subtitle">Manage your gaming community</p>
            </div>
            <button class="btn-gaming" onclick="window.location.href='add.php'">⚡ Add New Player</button>
        </div>

        <!-- Stats Cards -->
        <div class="users-stats">
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <h2 class="stat-number"><?php echo number_format($total_users); ?></h2>
                <p class="stat-label">Total Players</p>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🎮</div>
                <h2 class="stat-number"><?php echo number_format(array_sum(array_column($users_data, 'coins'))); ?></h2>
                <p class="stat-label">Total Coins</p>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🎫</div>
                <h2 class="stat-number"><?php echo number_format(array_sum(array_column($users_data, 'tickets'))); ?></h2>
                <p class="stat-label">Total Tickets</p>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⚡</div>
                <h2 class="stat-number"><?php echo count(array_filter($users_data, function($u) { return isset($u['created_at']) && strtotime($u['created_at']) > strtotime('-30 days'); })); ?></h2>
                <p class="stat-label">New This Month</p>
            </div>
        </div>

        <!-- Search and Controls -->
        <div class="users-controls">
            <div class="search-box">
                <form method="GET" style="display: flex; width: 100%; position: relative;" id="search-form">
                    <input type="search" name="search" class="search-input" placeholder="🔍 Search players by name, email, or ID..." value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>" autocomplete="off" id="user-search-input">
                    <div class="search-icon">🔍</div>
                    <button type="button" class="search-clear" id="clear-search" style="display: none;">✕</button>
                </form>
            </div>
            <div class="search-filters">
                <select class="filter-select" onchange="filterUsers(this.value)">
                    <option value="all">All Players</option>
                    <option value="active">Active</option>
                    <option value="new">New Players</option>
                </select>
                <button class="btn-refresh" onclick="refreshUserList()" title="Refresh List">🔄</button>
            </div>
        </div>

        <!-- Users Table -->
        <div class="users-table-container">
            <table class="users-table">
                <thead>
                    <tr>
                        <th>Player</th>
                        <th>Email</th>
                        <th>💰 Coins</th>
                        <th>🎫 Tickets</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($users_data)): ?>
                        <?php foreach($users_data as $user): ?>
                        <tr>
                            <td>
                                <div class="user-info">
                                    <div class="user-avatar">
                                        <?php echo strtoupper(substr($user['username'] ?? 'U', 0, 1)); ?>
                                    </div>
                                    <div class="user-details">
                                        <h4><?php echo htmlspecialchars($user['username'] ?? 'N/A'); ?></h4>
                                        <p>ID: <?php echo htmlspecialchars($user['id'] ?? 'N/A'); ?></p>
                                    </div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></td>
                            <td><strong><?php echo number_format($user['coins'] ?? 0); ?></strong></td>
                            <td><strong><?php echo number_format($user['tickets'] ?? 0); ?></strong></td>
                            <td><span class="status-badge status-active">Active</span></td>
                            <td><?php echo isset($user['created_at']) ? date('M d, Y', strtotime($user['created_at'])) : 'N/A'; ?></td>
                            <td>
                                <div class="action-buttons">
                                    <button class="btn-action btn-view" onclick="window.location.href='view.php?id=<?php echo htmlspecialchars($user['id'] ?? ''); ?>'" title="View">👁️</button>
                                    <button class="btn-action btn-edit" onclick="window.location.href='edit.php?id=<?php echo htmlspecialchars($user['id'] ?? ''); ?>'" title="Edit">✏️</button>
                                    <button class="btn-action btn-delete" onclick="deleteUser('<?php echo htmlspecialchars($user['id'] ?? ''); ?>')" title="Delete">🗑️</button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center py-4">
                                <div style="padding: 2rem; color: var(--text-muted);">
                                    <div style="font-size: 4rem;">🎮</div>
                                    <p style="margin-top: 1rem; font-size: 1.2rem;">No players found</p>
                                    <p>Start building your gaming community!</p>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if($total_pages > 1): ?>
        <div class="pagination">
            <?php if($page > 1): ?>
                <button class="pagination-btn" onclick="window.location.href='?page=<?php echo $page-1; ?><?php echo $search ? '&search=' . urlencode($_GET['search']) : ''; ?>'">&lt;</button>
            <?php endif; ?>
            
            <?php for($i = 1; $i <= $total_pages; $i++): ?>
                <button class="pagination-btn <?php echo $i == $page ? 'active' : ''; ?>" onclick="window.location.href='?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($_GET['search']) : ''; ?>'">
                    <?php echo $i; ?>
                </button>
            <?php endfor; ?>
            
            <?php if($page < $total_pages): ?>
                <button class="pagination-btn" onclick="window.location.href='?page=<?php echo $page+1; ?><?php echo $search ? '&search=' . urlencode($_GET['search']) : ''; ?>'">></button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/user/realtime-search.js"></script>
    <script>
    // Enhanced search functionality
    document.addEventListener('DOMContentLoaded', function() {
        const searchInput = document.getElementById('user-search-input');
        const clearButton = document.getElementById('clear-search');
        const searchForm = document.getElementById('search-form');
        
        // Show/hide clear button based on input
        function toggleClearButton() {
            if (searchInput.value.trim()) {
                clearButton.style.display = 'block';
            } else {
                clearButton.style.display = 'none';
            }
        }
        
        // Clear search functionality
        clearButton.addEventListener('click', function() {
            searchInput.value = '';
            toggleClearButton();
            // Submit form to refresh
            window.location.href = window.location.pathname;
        });
        
        // Monitor input changes
        searchInput.addEventListener('input', toggleClearButton);
        
        // Initial check
        toggleClearButton();
        
        // Enhanced search with Enter key
        searchInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                searchForm.submit();
            }
        });
    });
    
    // Refresh user list function
    function refreshUserList() {
        const refreshBtn = document.querySelector('.btn-refresh');
        const originalText = refreshBtn.innerHTML;
        
        // Show loading state
        refreshBtn.innerHTML = '⚡';
        refreshBtn.disabled = true;
        
        // Add spinning animation
        refreshBtn.style.animation = 'spin 1s linear infinite';
        
        // Refresh after a short delay
        setTimeout(() => {
            window.location.reload();
        }, 500);
    }
    function deleteUser(userId) {
        if(confirm('⚠️ Are you sure you want to delete this player? This action cannot be undone and will remove all their gaming data!')) {
            // Show loading state
            const deleteBtn = event.target;
            const originalText = deleteBtn.innerHTML;
            deleteBtn.innerHTML = '⚡';
            deleteBtn.disabled = true;
            
            // Perform delete action
            fetch('delete.php?id=' + encodeURIComponent(userId), {
                method: 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json'
                }
            })
            .then(response => {
                // Check if response is ok
                if (response.ok) {
                    return response.json().catch(() => ({ success: true, message: 'User deleted successfully' }));
                } else {
                    return response.json().catch(() => ({ success: false, message: 'Delete request failed: ' + response.status }));
                }
            })
            .then(data => {
                if (data.success) {
                    // Remove row with animation
                    const row = deleteBtn.closest('tr');
                    row.style.transition = 'all 0.3s ease-out';
                    row.style.transform = 'translateX(-100%)';
                    row.style.opacity = '0';
                    
                    setTimeout(() => {
                        row.remove();
                        // Update user count if needed
                        updateUserCount();
                        showToast(data.message || 'Player deleted successfully', 'success');
                    }, 300);
                } else {
                    throw new Error(data.message || 'Delete operation failed');
                }
            })
            .catch(error => {
                console.error('Delete error:', error);
                deleteBtn.innerHTML = originalText;
                deleteBtn.disabled = false;
                showToast(error.message || 'Failed to delete player', 'error');
            });
        }
    }
    
    function updateUserCount() {
        // Update the total players count after deletion
        const totalPlayersElement = document.querySelector('.stat-card .stat-number');
        if (totalPlayersElement) {
            const currentCount = parseInt(totalPlayersElement.textContent.replace(/,/g, ''));
            const newCount = Math.max(0, currentCount - 1);
            totalPlayersElement.textContent = newCount.toLocaleString();
        }
    }
    
    function filterUsers(filter) {
        // Enhanced filter functionality
        const table = document.querySelector('.users-table tbody');
        const rows = table.querySelectorAll('tr');
        let visibleCount = 0;
        
        rows.forEach(row => {
            const shouldShow = filterRow(row, filter);
            row.style.display = shouldShow ? '' : 'none';
            if (shouldShow) visibleCount++;
        });
        
        // Update stats based on visible rows
        updateFilterStats(visibleCount);
        
        // Add filter highlight
        const filterSelect = document.querySelector('.filter-select');
        if (filter !== 'all') {
            filterSelect.style.background = 'var(--primary-purple-100)';
            filterSelect.style.borderColor = 'var(--primary-purple)';
        } else {
            filterSelect.style.background = 'var(--input-bg)';
            filterSelect.style.borderColor = 'var(--border-light)';
        }
    }
    
    function filterRow(row, filter) {
        if (filter === 'all') return true;
        
        if (filter === 'active') {
            const statusBadge = row.querySelector('.status-badge');
            return statusBadge && statusBadge.textContent.includes('Active');
        }
        
        if (filter === 'new') {
            const dateCell = row.cells[5]; // Join date column
            if (dateCell) {
                const dateText = dateCell.textContent.trim();
                const joinDate = new Date(dateText);
                const thirtyDaysAgo = new Date();
                thirtyDaysAgo.setDate(thirtyDaysAgo.getDate() - 30);
                return joinDate > thirtyDaysAgo;
            }
        }
        
        return true;
    }
    
    function updateFilterStats(visibleCount) {
        const firstStatCard = document.querySelector('.stat-card .stat-number');
        if (firstStatCard && window.userSearch) {
            window.userSearch.animateNumber(firstStatCard, 
                parseInt(firstStatCard.textContent.replace(/,/g, '')), 
                visibleCount
            );
        }
    }
    
    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        const bgColor = type === 'success' ? 'var(--primary-green)' : 
                       type === 'error' ? 'var(--error)' : 'var(--primary-purple)';
        
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: ${bgColor};
            color: var(--text-inverse);
            padding: 12px 20px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            z-index: 9999;
            animation: slideInRight 0.3s ease-out;
            font-weight: var(--font-weight-medium);
        `;
        toast.textContent = message;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.animation = 'slideOutRight 0.3s ease-in';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
    
    // Add slide animations and enhanced styling
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        /* Enhanced search controls styling */
        .search-clear {
            position: absolute;
            right: 45px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted, #666);
            cursor: pointer;
            font-size: 16px;
            font-weight: bold;
            padding: 4px;
            border-radius: 50%;
            transition: all 0.2s ease;
            z-index: 10;
        }
        
        .search-clear:hover {
            color: var(--error, #dc3545);
            background: var(--error-light, rgba(220, 53, 69, 0.1));
        }
        
        .search-filters {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .btn-refresh {
            background: var(--primary-purple, #7c3aed);
            color: white;
            border: none;
            padding: 8px 12px;
            border-radius: var(--radius-md, 6px);
            cursor: pointer;
            font-size: 16px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .btn-refresh:hover {
            background: var(--primary-purple-dark, #5b21b6);
            transform: translateY(-1px);
        }
        
        .btn-refresh:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }
        
        .users-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        
        .search-box {
            flex: 1;
            min-width: 300px;
        }
        
        @media (max-width: 768px) {
            .users-controls {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-box {
                min-width: auto;
            }
            
            .search-filters {
                justify-content: center;
            }
        }
    `;
    document.head.appendChild(style);
    </script>
</body>
</html> 