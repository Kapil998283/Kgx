/**
 * Gamer Search Functionality
 * Handles real-time search and filtering for the gamer table
 */

class GamerSearch {
    constructor() {
        this.searchInput = document.getElementById('gamerSearch');
        this.clearButton = document.getElementById('clearSearch');
        this.tableRows = document.querySelectorAll('tbody tr.gamer-row');
        this.tableBody = document.querySelector('tbody');
        
        this.init();
    }
    
    init() {
        if (!this.searchInput || !this.clearButton) {
            console.warn('Gamer search elements not found');
            return;
        }
        
        this.setupEventListeners();
        this.setupKeyboardShortcuts();
    }
    
    setupEventListeners() {
        // Search input listener
        this.searchInput.addEventListener('input', (e) => {
            this.performSearch(e.target.value.trim());
        });
        
        // Clear button listener
        this.clearButton.addEventListener('click', () => {
            this.clearSearch();
        });
        
        // Focus and blur effects
        this.searchInput.addEventListener('focus', () => {
            this.searchInput.parentElement.style.transform = 'scale(1.02)';
            this.searchInput.parentElement.style.boxShadow = '0 4px 20px rgba(0, 212, 255, 0.4)';
        });
        
        this.searchInput.addEventListener('blur', () => {
            this.searchInput.parentElement.style.transform = 'scale(1)';
            this.searchInput.parentElement.style.boxShadow = '0 2px 10px rgba(0, 255, 255, 0.2)';
        });
    }
    
    setupKeyboardShortcuts() {
        document.addEventListener('keydown', (e) => {
            // Ctrl/Cmd + F to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                e.preventDefault();
                this.searchInput.focus();
                this.searchInput.select();
            }
            
            // Escape to clear search
            if (e.key === 'Escape' && document.activeElement === this.searchInput) {
                this.clearSearch();
            }
        });
    }
    
    performSearch(searchValue) {
        let visibleCount = 0;
        
        // Remove existing highlights
        this.removeHighlights();
        
        this.tableRows.forEach(row => {
            if (!searchValue) {
                // Show all rows if search is empty
                row.style.display = '';
                row.classList.remove('filtered-out');
                row.classList.add('filtered-in');
                visibleCount++;
                return;
            }
            
            // Get search data from data attributes and text content
            const id = row.dataset.id || '';
            const username = row.dataset.username || '';
            const email = row.dataset.email || '';
            const gamerName = row.querySelector('.gamer-name')?.textContent || '';
            const gamerEmail = row.querySelector('.gamer-email')?.textContent || '';
            
            // Check if search value matches any field
            const searchLower = searchValue.toLowerCase();
            const matches = [
                id.toLowerCase().includes(searchLower),
                username.toLowerCase().includes(searchLower),
                email.toLowerCase().includes(searchLower),
                gamerName.toLowerCase().includes(searchLower),
                gamerEmail.toLowerCase().includes(searchLower)
            ].some(match => match);
            
            if (matches) {
                // Show row
                row.style.display = '';
                row.classList.remove('filtered-out');
                row.classList.add('filtered-in');
                visibleCount++;
                
                // Highlight matching terms
                if (id.toLowerCase().includes(searchLower)) {
                    this.highlightText(row.querySelector('.gamer-id'), searchValue);
                }
                if (gamerName.toLowerCase().includes(searchLower)) {
                    this.highlightText(row.querySelector('.gamer-name'), searchValue);
                }
                if (gamerEmail.toLowerCase().includes(searchLower)) {
                    this.highlightText(row.querySelector('.gamer-email'), searchValue);
                }
            } else {
                // Hide row
                row.style.display = 'none';
                row.classList.add('filtered-out');
                row.classList.remove('filtered-in');
            }
        });
        
        // Show/hide no results message
        this.showNoResultsMessage(visibleCount === 0 && searchValue);
        
        // Update clear button visibility
        this.clearButton.style.opacity = searchValue ? '1' : '0.5';
    }
    
    highlightText(element, searchTerm) {
        if (!element) return;
        
        const originalText = element.textContent;
        if (searchTerm && originalText.toLowerCase().includes(searchTerm.toLowerCase())) {
            const regex = new RegExp(`(${this.escapeRegex(searchTerm)})`, 'gi');
            element.innerHTML = originalText.replace(regex, '<span class="search-highlight">$1</span>');
        } else {
            element.textContent = originalText;
        }
    }
    
    removeHighlights() {
        this.tableRows.forEach(row => {
            const elements = row.querySelectorAll('.gamer-id, .gamer-name, .gamer-email');
            elements.forEach(el => {
                const highlighted = el.querySelector('.search-highlight');
                if (highlighted) {
                    el.textContent = el.textContent; // This removes HTML tags
                }
            });
        });
    }
    
    showNoResultsMessage(show) {
        let noResultsRow = this.tableBody.querySelector('.no-results-row');
        
        if (show && !noResultsRow) {
            noResultsRow = document.createElement('tr');
            noResultsRow.className = 'no-results-row';
            noResultsRow.innerHTML = `
                <td colspan="5" class="no-results text-center py-4">
                    <i class="bi bi-search display-4 mb-3 text-muted"></i>
                    <h5>No gamers found</h5>
                    <p class="text-muted">Try adjusting your search terms or clear the search to see all gamers.</p>
                </td>
            `;
            this.tableBody.appendChild(noResultsRow);
        } else if (!show && noResultsRow) {
            noResultsRow.remove();
        }
    }
    
    clearSearch() {
        this.searchInput.value = '';
        this.searchInput.focus();
        this.performSearch('');
    }
    
    escapeRegex(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }
}

// Initialize search when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    new GamerSearch();
});
