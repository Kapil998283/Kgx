/**
 * Dashboard Animations
 * Handles animations for stats cards and general UI elements
 */

document.addEventListener('DOMContentLoaded', function() {
    // Animate stats cards on load
    const statsCards = document.querySelectorAll('.stats-card');
    statsCards.forEach((card, index) => {
        setTimeout(() => {
            card.style.transform = 'translateY(0)';
            card.style.opacity = '1';
        }, index * 100);
    });
    
    // Add hover effects to table rows (non-gamer specific)
    const tableRows = document.querySelectorAll('tbody tr:not(.gamer-row)');
    tableRows.forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.02)';
        });
        row.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
        });
    });
    
    // Console message for developers
    console.log('%c🎮 KGX Gaming Admin Dashboard Loaded! 🎮', 'color: #00d4ff; font-size: 16px; font-weight: bold;');
});
