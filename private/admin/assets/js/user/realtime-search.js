/**
 * Real-time User Search for Gaming Admin Panel
 * Provides instant search functionality with smooth animations
 */

class RealtimeUserSearch {
    constructor() {
        this.searchInput = document.querySelector('.search-input');
        this.searchResults = document.querySelector('.users-table-container');
        this.usersTable = document.querySelector('.users-table tbody');
        this.pagination = document.querySelector('.pagination');
        this.statsCards = document.querySelectorAll('.stat-card .stat-number');
        this.noResultsTemplate = this.createNoResultsTemplate();
        
        this.searchTimeout = null;
        this.currentSearchTerm = '';
        this.isSearching = false;
        this.cache = new Map();
        
        this.init();
    }

    init() {
        if (!this.searchInput) {
            console.warn('Search input not found');
            return;
        }

        this.bindEvents();
        this.addSearchLoader();
        this.enhanceSearchInput();
    }

    bindEvents() {
        // Real-time search on input
        this.searchInput.addEventListener('input', (e) => {
            this.handleSearch(e.target.value.trim());
        });

        // Clear search on escape key
        this.searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.clearSearch();
            }
        });

        // Prevent form submission on enter
        this.searchInput.closest('form')?.addEventListener('submit', (e) => {
            e.preventDefault();
            this.handleSearch(this.searchInput.value.trim());
        });

        // Clear button functionality
        const clearBtn = document.querySelector('.search-clear');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => this.clearSearch());
        }
    }

    enhanceSearchInput() {
        const searchBox = this.searchInput.parentElement;
        
        // Add search loader
        const loader = document.createElement('div');
        loader.className = 'search-loader';
        loader.innerHTML = '⚡';
        loader.style.cssText = `
            position: absolute;
            right: 45px;
            top: 50%;
            transform: translateY(-50%);
            display: none;
            animation: spin 1s linear infinite;
            color: var(--primary-purple);
            font-size: 18px;
        `;
        searchBox.appendChild(loader);

        // Add clear button
        const clearBtn = document.createElement('button');
        clearBtn.className = 'search-clear';
        clearBtn.type = 'button';
        clearBtn.innerHTML = '✕';
        clearBtn.style.cssText = `
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            display: none;
            font-size: 16px;
            font-weight: bold;
            transition: color var(--transition-fast);
        `;
        clearBtn.addEventListener('mouseover', () => {
            clearBtn.style.color = 'var(--error)';
        });
        clearBtn.addEventListener('mouseout', () => {
            clearBtn.style.color = 'var(--text-muted)';
        });
        searchBox.appendChild(clearBtn);

        // Update search input padding
        this.searchInput.style.paddingRight = '80px';
    }

    addSearchLoader() {
        const style = document.createElement('style');
        style.textContent = `
            @keyframes spin {
                0% { transform: translateY(-50%) rotate(0deg); }
                100% { transform: translateY(-50%) rotate(360deg); }
            }
            
            .search-pulse {
                animation: searchPulse 0.6s ease-in-out;
            }
            
            @keyframes searchPulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.02); }
                100% { transform: scale(1); }
            }
            
            .fade-in {
                animation: fadeIn 0.3s ease-out;
            }
            
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(10px); }
                to { opacity: 1; transform: translateY(0); }
            }
            
            .search-highlight {
                background: linear-gradient(120deg, var(--primary-purple-200) 0%, var(--primary-green-200) 100%);
                padding: 2px 4px;
                border-radius: 3px;
                font-weight: var(--font-weight-semibold);
            }
        `;
        document.head.appendChild(style);
    }

    handleSearch(searchTerm) {
        // Clear existing timeout
        if (this.searchTimeout) {
            clearTimeout(this.searchTimeout);
        }

        this.currentSearchTerm = searchTerm;
        
        // Show/hide clear button
        const clearBtn = document.querySelector('.search-clear');
        if (clearBtn) {
            clearBtn.style.display = searchTerm ? 'block' : 'none';
        }

        // If search is empty, reload original data
        if (!searchTerm) {
            this.clearSearch();
            return;
        }

        // Debounce search
        this.searchTimeout = setTimeout(() => {
            this.performSearch(searchTerm);
        }, 300);
    }

    async performSearch(searchTerm) {
        if (this.isSearching) return;

        try {
            this.showSearchLoader(true);
            this.isSearching = true;

            // Check cache first
            const cacheKey = searchTerm.toLowerCase();
            if (this.cache.has(cacheKey)) {
                this.displayResults(this.cache.get(cacheKey), searchTerm);
                return;
            }

            // Perform AJAX search
            const response = await fetch('search_users.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    search: searchTerm,
                    action: 'realtime_search'
                })
            });

            if (!response.ok) {
                throw new Error('Search request failed');
            }

            const data = await response.json();
            
            // Cache results
            this.cache.set(cacheKey, data);
            
            // Display results
            this.displayResults(data, searchTerm);

        } catch (error) {
            console.error('Search error:', error);
            this.showError('Search failed. Please try again.');
        } finally {
            this.showSearchLoader(false);
            this.isSearching = false;
        }
    }

    displayResults(data, searchTerm) {
        // Update stats
        this.updateStats(data.stats);
        
        // Update table
        this.updateTable(data.users, searchTerm);
        
        // Hide pagination during search
        if (this.pagination) {
            this.pagination.style.display = 'none';
        }

        // Add animation
        this.searchResults.classList.add('search-pulse');
        setTimeout(() => {
            this.searchResults.classList.remove('search-pulse');
        }, 600);
    }

    updateStats(stats) {
        if (!stats || !this.statsCards.length) return;

        const statValues = [
            stats.total_users || 0,
            stats.total_coins || 0,
            stats.total_tickets || 0,
            stats.new_users || 0
        ];

        this.statsCards.forEach((card, index) => {
            if (statValues[index] !== undefined) {
                this.animateNumber(card, parseInt(card.textContent.replace(/,/g, '')), statValues[index]);
            }
        });
    }

    updateTable(users, searchTerm) {
        if (!users || users.length === 0) {
            this.usersTable.innerHTML = this.noResultsTemplate;
            return;
        }

        let tableHTML = '';
        users.forEach(user => {
            tableHTML += this.createUserRow(user, searchTerm);
        });

        this.usersTable.innerHTML = tableHTML;
        
        // Add fade-in animation to rows
        const rows = this.usersTable.querySelectorAll('tr');
        rows.forEach((row, index) => {
            row.style.animationDelay = `${index * 50}ms`;
            row.classList.add('fade-in');
        });
    }

    createUserRow(user, searchTerm) {
        const highlightText = (text, term) => {
            if (!term) return text;
            const regex = new RegExp(`(${term})`, 'gi');
            return text.replace(regex, '<span class="search-highlight">$1</span>');
        };

        const username = highlightText(user.username || 'N/A', searchTerm);
        const email = highlightText(user.email || 'N/A', searchTerm);
        const avatar = (user.username || 'U').charAt(0).toUpperCase();
        const coins = parseInt(user.coins || 0).toLocaleString();
        const tickets = parseInt(user.tickets || 0).toLocaleString();
        const joinDate = user.created_at ? new Date(user.created_at).toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        }) : 'N/A';

        return `
            <tr>
                <td>
                    <div class="user-info">
                        <div class="user-avatar">
                            ${avatar}
                        </div>
                        <div class="user-details">
                            <h4>${username}</h4>
                            <p>ID: ${user.id || 'N/A'}</p>
                        </div>
                    </div>
                </td>
                <td>${email}</td>
                <td><strong>${coins}</strong></td>
                <td><strong>${tickets}</strong></td>
                <td><span class="status-badge status-active">Active</span></td>
                <td>${joinDate}</td>
                <td>
                    <div class="action-buttons">
                        <button class="btn-action btn-view" onclick="window.location.href='view.php?id=${user.id}'" title="View">👁️</button>
                        <button class="btn-action btn-edit" onclick="window.location.href='edit.php?id=${user.id}'" title="Edit">✏️</button>
                        <button class="btn-action btn-delete" onclick="deleteUser('${user.id}')" title="Delete">🗑️</button>
                    </div>
                </td>
            </tr>
        `;
    }

    createNoResultsTemplate() {
        return `
            <tr>
                <td colspan="7" class="text-center py-4">
                    <div style="padding: 2rem; color: var(--text-muted);">
                        <div style="font-size: 4rem;">🔍</div>
                        <p style="margin-top: 1rem; font-size: 1.2rem;">No players found</p>
                        <p>Try adjusting your search terms</p>
                    </div>
                </td>
            </tr>
        `;
    }

    animateNumber(element, start, end) {
        const duration = 800;
        const startTime = performance.now();
        
        const animate = (currentTime) => {
            const elapsed = currentTime - startTime;
            const progress = Math.min(elapsed / duration, 1);
            
            // Easing function
            const easeOut = 1 - Math.pow(1 - progress, 3);
            const current = Math.round(start + (end - start) * easeOut);
            
            element.textContent = current.toLocaleString();
            
            if (progress < 1) {
                requestAnimationFrame(animate);
            }
        };
        
        requestAnimationFrame(animate);
    }

    showSearchLoader(show) {
        const loader = document.querySelector('.search-loader');
        if (loader) {
            loader.style.display = show ? 'block' : 'none';
        }
    }

    showError(message) {
        // Create error toast
        const toast = document.createElement('div');
        toast.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--error);
            color: var(--text-inverse);
            padding: 12px 20px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            z-index: 9999;
            animation: slideInRight 0.3s ease-out;
        `;
        toast.textContent = message;
        
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.style.animation = 'slideOutRight 0.3s ease-in';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    clearSearch() {
        this.searchInput.value = '';
        this.currentSearchTerm = '';
        
        // Hide clear button
        const clearBtn = document.querySelector('.search-clear');
        if (clearBtn) {
            clearBtn.style.display = 'none';
        }
        
        // Reload original page
        window.location.href = window.location.pathname;
    }

    // Public method to refresh search
    refresh() {
        if (this.currentSearchTerm) {
            this.performSearch(this.currentSearchTerm);
        }
    }
}

// Auto-initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.userSearch = new RealtimeUserSearch();
});

// Export for manual initialization if needed
if (typeof module !== 'undefined' && module.exports) {
    module.exports = RealtimeUserSearch;
}
