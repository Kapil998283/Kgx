/**
 * Real-time Validation JavaScript for KGX Gaming Registration
 * Handles username, email, and phone number validation
 */

class ValidationManager {
    constructor() {
        this.debounceDelay = 500;
        this.timeouts = {};
        this.init();
    }

    init() {
        document.addEventListener('DOMContentLoaded', () => {
            this.setupValidation();
        });
    }

    // Debounce function to avoid too many API calls
    debounce(func, wait, key) {
        return (...args) => {
            const later = () => {
                clearTimeout(this.timeouts[key]);
                func(...args);
            };
            clearTimeout(this.timeouts[key]);
            this.timeouts[key] = setTimeout(later, wait);
        };
    }

    // Generic validation function for any field
    async validateField(field, action) {
        const value = field.value.trim();
        const feedbackElement = document.getElementById(field.id + '-feedback');
        
        if (!feedbackElement) return;

        if (!value) {
            feedbackElement.innerHTML = '';
            feedbackElement.className = 'validation-feedback';
            field.classList.remove('success', 'error');
            return;
        }

        // Show loading state
        feedbackElement.innerHTML = '<ion-icon name="hourglass-outline"></ion-icon> Checking...';
        feedbackElement.className = 'validation-feedback checking';
        field.classList.remove('success', 'error');

        try {
            // Make AJAX request
            const formData = new FormData();
            formData.append('action', action);
            formData.append(field.id, value);

            const response = await fetch('validate.php', {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error('Network response was not ok');
            }

            const data = await response.json();

            if (data.success) {
                feedbackElement.innerHTML = '<ion-icon name="checkmark-circle-outline"></ion-icon> ' + data.message;
                feedbackElement.className = 'validation-feedback success';
                field.classList.remove('error');
                field.classList.add('success');
            } else {
                feedbackElement.innerHTML = '<ion-icon name="alert-circle-outline"></ion-icon> ' + data.message;
                feedbackElement.className = 'validation-feedback error';
                field.classList.remove('success');
                field.classList.add('error');
            }
        } catch (error) {
            console.error('Validation error:', error);
            feedbackElement.innerHTML = '<ion-icon name="alert-circle-outline"></ion-icon> Error checking availability';
            feedbackElement.className = 'validation-feedback error';
            field.classList.remove('success');
            field.classList.add('error');
        }
    }

    // Setup validation for all fields
    setupValidation() {
        // Username validation
        const usernameField = document.getElementById('username');
        if (usernameField) {
            const debouncedValidateUsername = this.debounce(
                (field) => this.validateField(field, 'check_username'),
                this.debounceDelay,
                'username'
            );

            usernameField.addEventListener('input', () => {
                debouncedValidateUsername(usernameField);
            });

            usernameField.addEventListener('blur', () => {
                if (usernameField.value.trim()) {
                    this.validateField(usernameField, 'check_username');
                }
            });
        }

        // Email validation
        const emailField = document.getElementById('email');
        if (emailField) {
            const debouncedValidateEmail = this.debounce(
                (field) => this.validateField(field, 'check_email'),
                this.debounceDelay,
                'email'
            );

            emailField.addEventListener('input', () => {
                debouncedValidateEmail(emailField);
            });

            emailField.addEventListener('blur', () => {
                if (emailField.value.trim()) {
                    this.validateField(emailField, 'check_email');
                }
            });
        }

        // Form submission validation
        this.setupFormValidation();
    }

    // Setup form validation to prevent submission with errors
    setupFormValidation() {
        const forms = document.querySelectorAll('.auth-form');
        forms.forEach(form => {
            form.addEventListener('submit', (e) => {
                const errorFields = document.querySelectorAll('.validation-feedback.error');
                const checkingFields = document.querySelectorAll('.validation-feedback.checking');
                
                if (errorFields.length > 0) {
                    e.preventDefault();
                    this.showValidationAlert('Please fix the validation errors before continuing.');
                    return false;
                }
                
                if (checkingFields.length > 0) {
                    e.preventDefault();
                    this.showValidationAlert('Please wait for validation to complete.');
                    return false;
                }
            });
        });
    }

    // Show validation alert
    showValidationAlert(message) {
        // Create a custom alert that matches the design
        const alertDiv = document.createElement('div');
        alertDiv.className = 'validation-alert error-message';
        alertDiv.innerHTML = `
            <ion-icon name="alert-circle-outline"></ion-icon>
            ${message}
        `;
        
        // Insert at the top of the form container
        const container = document.querySelector('.auth-container');
        if (container) {
            const header = container.querySelector('.auth-header');
            if (header) {
                header.insertAdjacentElement('afterend', alertDiv);
                
                // Remove after 5 seconds
                setTimeout(() => {
                    if (alertDiv.parentNode) {
                        alertDiv.remove();
                    }
                }, 5000);
            }
        }
    }

    // Client-side validation helpers
    static validateUsername(username) {
        if (!username || username.length < 3) {
            return { valid: false, message: 'Username must be at least 3 characters long' };
        }
        if (username.length > 20) {
            return { valid: false, message: 'Username must be less than 20 characters' };
        }
        if (!/^[a-zA-Z0-9_]+$/.test(username)) {
            return { valid: false, message: 'Username can only contain letters, numbers, and underscores' };
        }
        return { valid: true, message: 'Username format is valid' };
    }

    static validateEmail(email) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!email) {
            return { valid: false, message: 'Email is required' };
        }
        if (!emailRegex.test(email)) {
            return { valid: false, message: 'Please enter a valid email address' };
        }
        return { valid: true, message: 'Email format is valid' };
    }

    static validatePassword(password) {
        if (!password) {
            return { valid: false, message: 'Password is required' };
        }
        if (password.length < 8) {
            return { valid: false, message: 'Password must be at least 8 characters long' };
        }
        
        let strength = 0;
        let strengthText = 'Weak';
        
        // Check for different character types
        if (password.length >= 8) strength++;
        if (/[a-z]/.test(password)) strength++;
        if (/[A-Z]/.test(password)) strength++;
        if (/[0-9]/.test(password)) strength++;
        if (/[^A-Za-z0-9]/.test(password)) strength++;
        
        if (strength >= 4) strengthText = 'Strong';
        else if (strength >= 3) strengthText = 'Medium';
        
        return { 
            valid: strength >= 2, 
            message: `Password strength: ${strengthText}`,
            strength: strength 
        };
    }
}

// Initialize validation manager
const validationManager = new ValidationManager();
