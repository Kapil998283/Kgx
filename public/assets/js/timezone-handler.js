/**
 * KGX Tournament Platform - Timezone Handler
 * Automatically converts server timestamps to user's local timezone
 */

class TimezoneHandler {
    constructor() {
        this.serverTimezone = 'Asia/Kolkata'; // Your server timezone
        this.userTimezone = this.getUserTimezone();
        this.init();
    }

    /**
     * Get user's timezone automatically
     */
    getUserTimezone() {
        return Intl.DateTimeFormat().resolvedOptions().timeZone;
    }

    /**
     * Initialize timezone conversion for all datetime elements
     */
    init() {
        // Convert all elements with data-timestamp attribute
        document.querySelectorAll('[data-timestamp]').forEach(element => {
            this.convertTimestamp(element);
        });

        // Convert all elements with data-tournament-date attribute
        document.querySelectorAll('[data-tournament-date]').forEach(element => {
            this.convertTournamentDate(element);
        });

        // Convert all elements with data-tournament-datetime attribute
        document.querySelectorAll('[data-tournament-datetime]').forEach(element => {
            this.convertTournamentDateTime(element);
        });

        // Note: Timezone indicator display is disabled
        // this.displayTimezoneInfo();
    }

    /**
     * Convert timestamp to user's local timezone
     */
    convertTimestamp(element) {
        const timestamp = element.getAttribute('data-timestamp');
        if (!timestamp) return;

        try {
            const serverDate = new Date(timestamp + ' UTC'); // Treat as UTC first
            const userDate = new Date(serverDate.toLocaleString('en-US', {timeZone: this.userTimezone}));
            
            const format = element.getAttribute('data-format') || 'full';
            const convertedTime = this.formatDateTime(userDate, format);
            
            element.textContent = convertedTime;
            element.setAttribute('title', `Your timezone: ${this.userTimezone}`);
        } catch (error) {
            console.error('Error converting timestamp:', error);
        }
    }

    /**
     * Convert tournament date
     */
    convertTournamentDate(element) {
        const dateString = element.getAttribute('data-tournament-date');
        if (!dateString) return;

        try {
            // Parse the server date (assuming it's in server timezone)
            const serverDate = new Date(dateString);
            
            // Format for user's timezone
            const options = {
                timeZone: this.userTimezone,
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            };
            
            const convertedDate = serverDate.toLocaleDateString('en-US', options);
            element.textContent = convertedDate;
            // Don't show timezone tooltip to keep it clean
            // element.setAttribute('title', `Your timezone: ${this.userTimezone}`);
        } catch (error) {
            console.error('Error converting tournament date:', error);
        }
    }

    /**
     * Convert tournament datetime
     */
    convertTournamentDateTime(element) {
        const datetimeString = element.getAttribute('data-tournament-datetime');
        if (!datetimeString) return;

        try {
            // Parse the server datetime
            const serverDate = new Date(datetimeString);
            
            // Check if this element should show only time
            const format = element.getAttribute('data-format');
            
            let options;
            if (format === 'time') {
                // Show only time
                options = {
                    timeZone: this.userTimezone,
                    hour: 'numeric',
                    minute: '2-digit',
                    hour12: true
                };
            } else {
                // Show full datetime
                options = {
                    timeZone: this.userTimezone,
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                    hour: 'numeric',
                    minute: '2-digit',
                    hour12: true
                };
            }
            
            let convertedDateTime;
            if (format === 'time') {
                // Use toLocaleTimeString for time-only display
                convertedDateTime = serverDate.toLocaleTimeString('en-US', options);
            } else {
                // Use toLocaleDateString for full datetime
                convertedDateTime = serverDate.toLocaleDateString('en-US', options);
            }
            element.textContent = convertedDateTime;
            // Don't show timezone tooltip to keep it clean
            // element.setAttribute('title', `Your timezone: ${this.userTimezone}`);
        } catch (error) {
            console.error('Error converting tournament datetime:', error);
        }
    }

    /**
     * Format datetime based on specified format
     */
    formatDateTime(date, format) {
        const options = {
            timeZone: this.userTimezone
        };

        switch (format) {
            case 'date':
                options.year = 'numeric';
                options.month = 'short';
                options.day = 'numeric';
                break;
            case 'time':
                options.hour = 'numeric';
                options.minute = '2-digit';
                options.hour12 = true;
                break;
            case 'datetime':
                options.year = 'numeric';
                options.month = 'short';
                options.day = 'numeric';
                options.hour = 'numeric';
                options.minute = '2-digit';
                options.hour12 = true;
                break;
            case 'full':
            default:
                options.weekday = 'short';
                options.year = 'numeric';
                options.month = 'short';
                options.day = 'numeric';
                options.hour = 'numeric';
                options.minute = '2-digit';
                options.hour12 = true;
                break;
        }

        return date.toLocaleDateString('en-US', options);
    }

    /**
     * Display timezone information to user
     */
    displayTimezoneInfo() {
        // Create timezone indicator
        const timezoneIndicator = document.createElement('div');
        timezoneIndicator.className = 'timezone-indicator';
        timezoneIndicator.innerHTML = `
            <small class="text-muted">
                <i class="bi bi-globe"></i> 
                Times shown in your timezone: ${this.userTimezone}
                ${this.userTimezone !== this.serverTimezone ? 
                    `<span class="badge bg-info ms-1">Auto-converted</span>` : 
                    `<span class="badge bg-success ms-1">Local time</span>`
                }
            </small>
        `;

        // Add to tournament pages
        const tournamentContainer = document.querySelector('.tournament-section, .registrations-section, .tournament-details');
        if (tournamentContainer) {
            tournamentContainer.insertBefore(timezoneIndicator, tournamentContainer.firstChild);
        }
    }

    /**
     * Get timezone offset information
     */
    getTimezoneOffset() {
        const now = new Date();
        const userOffset = now.getTimezoneOffset();
        const serverDate = new Date(now.toLocaleString('en-US', {timeZone: this.serverTimezone}));
        const serverOffset = (now.getTime() - serverDate.getTime()) / (1000 * 60);
        
        return {
            user: userOffset,
            server: serverOffset,
            difference: serverOffset - userOffset
        };
    }
}

// Auto-initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    window.timezoneHandler = new TimezoneHandler();
});

// Export for use in other scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = TimezoneHandler;
}
