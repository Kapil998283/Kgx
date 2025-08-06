/**
 * Sidebar Functionality
 * Handles mobile sidebar toggle and responsive behavior
 */

class Sidebar {
    constructor() {
        this.sidebarToggle = document.getElementById('sidebarToggle');
        this.sidebar = document.querySelector('.sidebar');
        this.sidebarOverlay = document.getElementById('sidebarOverlay');
        this.mainContent = document.querySelector('.main-content');
        
        this.init();
    }
    
    init() {
        if (!this.sidebarToggle || !this.sidebar || !this.sidebarOverlay) {
            console.warn('Sidebar elements not found');
            return;
        }
        
        this.setupEventListeners();
    }
    
    setupEventListeners() {
        // Toggle sidebar on button click
        this.sidebarToggle.addEventListener('click', () => {
            this.openSidebar();
        });
        
        // Close sidebar when clicking overlay
        this.sidebarOverlay.addEventListener('click', () => {
            this.closeSidebar();
        });
        
        // Close sidebar when window is resized to desktop size
        window.addEventListener('resize', () => {
            if (window.innerWidth >= 992) {
                this.closeSidebar();
            }
        });
        
        // Handle escape key to close sidebar
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.sidebar.classList.contains('show')) {
                this.closeSidebar();
            }
        });
    }
    
    openSidebar() {
        this.sidebar.classList.add('show');
        this.sidebarOverlay.classList.add('show');
        document.body.style.overflow = 'hidden';
    }
    
    closeSidebar() {
        this.sidebar.classList.remove('show');
        this.sidebarOverlay.classList.remove('show');
        document.body.style.overflow = '';
    }
}

// Initialize sidebar when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    new Sidebar();
});
