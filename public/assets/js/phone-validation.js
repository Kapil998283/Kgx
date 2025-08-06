/**
 * Phone Validation JavaScript for KGX Gaming Registration
 * Handles international phone number input, validation, and OTP functionality
 */

class PhoneValidationManager {
    constructor() {
        this.iti = null;
        this.phoneInput = null;
        this.fullPhoneInput = null;
        this.phoneValidationTimeout = null;
        this.debounceDelay = 800;
        this.init();
    }

    init() {
        document.addEventListener('DOMContentLoaded', () => {
            this.setupPhoneInput();
            this.setupOTPHandling();
        });
    }

    // Initialize international telephone input
    setupPhoneInput() {
        this.phoneInput = document.querySelector("#phone");
        this.fullPhoneInput = document.querySelector("#full_phone");
        
        if (!this.phoneInput) return;

        // Initialize intl-tel-input
        this.iti = window.intlTelInput(this.phoneInput, {
            utilsScript: "https://cdnjs.cloudflare.com/ajax/libs/intl-tel-input/17.0.13/js/utils.js",
            separateDialCode: true,
            initialCountry: "auto",
            geoIpLookup: (callback) => {
                fetch("https://ipapi.co/json")
                .then(res => res.json())
                .then(data => callback(data.country_code))
                .catch(() => callback("us"));
            },
            preferredCountries: ["us", "gb", "in", "ca", "au"],
            nationalMode: true,
            formatOnDisplay: true,
            autoPlaceholder: "polite"
        });

        // Setup validation events
        this.setupPhoneValidation();
    }

    // Setup phone validation events
    setupPhoneValidation() {
        if (!this.phoneInput) return;

        // Real-time validation on input
        this.phoneInput.addEventListener('input', () => {
            this.debouncePhoneValidation();
        });

        // Validation on country change
        this.phoneInput.addEventListener('countrychange', () => {
            this.debouncePhoneValidation();
        });

        // Validation on blur
        this.phoneInput.addEventListener('blur', () => {
            if (this.phoneInput.value.trim()) {
                this.validatePhoneNumber();
            }
        });

        // Form submission validation
        this.setupFormValidation();
    }

    // Debounced phone validation
    debouncePhoneValidation() {
        clearTimeout(this.phoneValidationTimeout);
        this.phoneValidationTimeout = setTimeout(() => {
            this.validatePhoneNumber();
        }, this.debounceDelay);
    }

    // Validate phone number
    async validatePhoneNumber() {
        if (!this.phoneInput || !this.iti) return;

        const phoneValue = this.phoneInput.value.replace(/\D/g, '');
        const fullPhone = this.iti.getNumber();
        const isValid = this.iti.isValidNumber();
        const errorElement = document.getElementById('phone-error');
        
        if (!errorElement) return;

        // Clear validation if no input
        if (!phoneValue) {
            errorElement.style.display = 'none';
            this.phoneInput.classList.remove('valid', 'invalid');
            return;
        }

        // Check basic format validation first
        if (!isValid) {
            errorElement.style.display = 'block';
            errorElement.innerHTML = '<ion-icon name="alert-circle-outline"></ion-icon> Please enter a valid phone number';
            errorElement.className = 'error-text';
            this.phoneInput.classList.remove('valid');
            this.phoneInput.classList.add('invalid');
            return;
        }

        // Show checking state
        errorElement.innerHTML = '<ion-icon name="hourglass-outline"></ion-icon> Checking availability...';
        errorElement.style.display = 'block';
        errorElement.className = 'checking-text';
        this.phoneInput.classList.remove('valid', 'invalid');

        try {
            // Check if phone exists in database
            const formData = new FormData();
            formData.append('action', 'check_phone');
            formData.append('phone', fullPhone);

            const response = await fetch('validate.php', {
                method: 'POST',
                body: formData
            });

            if (!response.ok) {
                throw new Error('Network response was not ok');
            }

            const data = await response.json();

            if (data.success) {
                errorElement.innerHTML = '<ion-icon name="checkmark-circle-outline"></ion-icon> ' + data.message;
                errorElement.className = 'success-text';
                this.phoneInput.classList.remove('invalid');
                this.phoneInput.classList.add('valid');
            } else {
                errorElement.innerHTML = '<ion-icon name="alert-circle-outline"></ion-icon> ' + data.message;
                errorElement.className = 'error-text';
                this.phoneInput.classList.remove('valid');
                this.phoneInput.classList.add('invalid');
            }
            errorElement.style.display = 'block';
        } catch (error) {
            console.error('Phone validation error:', error);
            errorElement.innerHTML = '<ion-icon name="alert-circle-outline"></ion-icon> Error checking phone number';
            errorElement.className = 'error-text';
            errorElement.style.display = 'block';
            this.phoneInput.classList.remove('valid');
            this.phoneInput.classList.add('invalid');
        }
    }

    // Setup form validation
    setupFormValidation() {
        const phoneForm = document.querySelector("#phoneForm");
        if (!phoneForm) return;

        phoneForm.addEventListener("submit", (e) => {
            if (!this.validateFormSubmission()) {
                e.preventDefault();
                return false;
            }
            
            // Set the full phone number in hidden field
            if (this.fullPhoneInput && this.iti) {
                this.fullPhoneInput.value = this.iti.getNumber();
            }
            return true;
        });
    }

    // Validate form on submission
    validateFormSubmission() {
        if (!this.phoneInput || !this.iti) return false;

        const phoneValue = this.phoneInput.value.replace(/\D/g, '');
        const isValid = phoneValue.length >= 10 && this.iti.isValidNumber();
        const hasErrors = document.querySelector('.error-text:not([style*="display: none"])');
        const isChecking = document.querySelector('.checking-text:not([style*="display: none"])');
        
        if (isChecking) {
            this.showError('Please wait for phone validation to complete.');
            return false;
        }

        if (!isValid || hasErrors) {
            if (!isValid) {
                this.showError('Please enter a valid phone number');
            }
            return false;
        }

        return true;
    }

    // Show error message
    showError(message) {
        const errorElement = document.getElementById('phone-error');
        if (errorElement) {
            errorElement.style.display = 'block';
            errorElement.innerHTML = '<ion-icon name="alert-circle-outline"></ion-icon> ' + message;
            errorElement.className = 'error-text';
        }
    }

    // Setup OTP handling
    setupOTPHandling() {
        const otpDigits = document.querySelectorAll('.otp-digit');
        const otpHidden = document.getElementById('otp');
        
        if (otpDigits.length === 0) return;

        // OTP digit input handling
        otpDigits.forEach((digit, index) => {
            digit.addEventListener('input', (e) => {
                const value = e.target.value;
                
                // Only allow numbers
                if (value && !/^\d$/.test(value)) {
                    e.target.value = '';
                    return;
                }

                if (value.length === 1 && /^\d$/.test(value)) {
                    // Move to next digit
                    if (index < otpDigits.length - 1) {
                        otpDigits[index + 1].focus();
                    }
                }
                
                this.updateOTPValue();
            });
            
            // Handle backspace
            digit.addEventListener('keydown', (e) => {
                if (e.key === 'Backspace' && !e.target.value && index > 0) {
                    otpDigits[index - 1].focus();
                }
            });
            
            // Handle paste
            digit.addEventListener('paste', (e) => {
                e.preventDefault();
                const pastedData = e.clipboardData.getData('text');
                const digits = pastedData.replace(/\D/g, '').slice(0, 6);
                
                digits.split('').forEach((digit, i) => {
                    if (otpDigits[i]) {
                        otpDigits[i].value = digit;
                    }
                });
                
                this.updateOTPValue();
                
                // Focus verify button if all digits are filled
                if (digits.length === 6) {
                    const verifyBtn = document.getElementById('verifyBtn');
                    if (verifyBtn) {
                        verifyBtn.focus();
                    }
                }
            });
        });

        // Setup countdown timer
        this.setupCountdownTimer();

        // Setup resend functionality
        this.setupResendFunctionality();
    }

    // Update OTP hidden value and button state
    updateOTPValue() {
        const otpDigits = document.querySelectorAll('.otp-digit');
        const otpHidden = document.getElementById('otp');
        const verifyBtn = document.getElementById('verifyBtn');
        
        if (!otpHidden) return;

        const otp = Array.from(otpDigits).map(digit => digit.value).join('');
        otpHidden.value = otp;
        
        // Enable/disable verify button
        if (verifyBtn) {
            verifyBtn.disabled = otp.length !== 6;
        }
    }

    // Setup countdown timer for resend
    setupCountdownTimer() {
        const countdownElement = document.getElementById('countdown');
        const timerElement = document.getElementById('timer');
        const resendBtn = document.getElementById('resendBtn');
        
        if (!countdownElement || !timerElement || !resendBtn) return;

        let countdown = 60;
        
        const timer = setInterval(() => {
            countdown--;
            countdownElement.textContent = countdown;
            
            if (countdown <= 0) {
                clearInterval(timer);
                timerElement.style.display = 'none';
                resendBtn.style.display = 'block';
            }
        }, 1000);
    }

    // Setup resend functionality
    setupResendFunctionality() {
        const resendBtn = document.getElementById('resendBtn');
        if (resendBtn) {
            resendBtn.addEventListener('click', () => {
                window.location.href = '?change_phone=1';
            });
        }
    }

    // Public method to get phone number
    getPhoneNumber() {
        return this.iti ? this.iti.getNumber() : '';
    }

    // Public method to check if phone is valid
    isPhoneValid() {
        return this.iti ? this.iti.isValidNumber() : false;
    }

    // Public method to reset phone validation
    resetValidation() {
        const errorElement = document.getElementById('phone-error');
        if (errorElement) {
            errorElement.style.display = 'none';
        }
        
        if (this.phoneInput) {
            this.phoneInput.classList.remove('valid', 'invalid');
        }
    }
}

// Initialize phone validation manager
const phoneValidationManager = new PhoneValidationManager();
