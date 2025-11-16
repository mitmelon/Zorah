var general = new General;
general.secure_token(BASE+ 'src/Addons/general.php', 'anchorKey');

$(window).on('load', function () {

    const walletHelpBtn = document.getElementById('walletHelpBtn')
    const closeWalletHelp = document.getElementById('closeWalletHelp')
    let _walletHelpController = null;

    if (walletHelpBtn) {
        walletHelpBtn.addEventListener('click', () => {
            window.modalController('walletHelpModal', { bgClose: true, keyboard: true })
                .then(modal => {
                    _walletHelpController = modal;
                    modal.show();
                    modal.modal.querySelector('button')?.focus();
                })
                .catch(err => {
                    console.error('modalController failed to open walletHelpModal:', err);
                });
        })
    }

    if (closeWalletHelp) {
        closeWalletHelp.addEventListener('click', () => {
            if (_walletHelpController && typeof _walletHelpController.hide === 'function') {
                _walletHelpController.hide();
            }
        })
    }

    const securityPatterns = {
        sqlInjection: /(\bSELECT\b.*\bFROM\b|\bUNION\b.*\bSELECT\b|\bDROP\b.*\bTABLE\b|xp_cmdshell|exec\s*\()/i,
        xss: /(<script[^>]*>|<\/script>|javascript:|onerror\s*=|onload\s*=|<iframe|eval\s*\()/i,
        cmdInjection: /(\|\s*rm\s|\|\s*del\s|&&\s*rm\s|;\s*rm\s|`.*`|\$\(.*\))/i,
        pathTraversal: /(\.\.\/\.\.\/|\.\.\\\.\.\\)/,
        nullByte: /%00|\\x00/i
    };

    const validPatterns = {
        name: /^[a-zA-Z\s'-]{2,50}$/,
        username: /^[a-zA-Z0-9._-]{3,30}$/,
        email: /^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/,
        passwordSimple: /^.{8,}$/, // For login
        password: { // For registration with strength requirements
            minLength: 8,
            hasUppercase: /[A-Z]/,
            hasLowercase: /[a-z]/,
            hasNumber: /[0-9]/,
            hasSpecial: /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/
        }
    };

    // Check for malicious patterns
    function containsMaliciousPattern(value) {
        for (const [type, pattern] of Object.entries(securityPatterns)) {
            if (pattern.test(value)) {
                return type;
            }
        }
        return null;
    }

    // Update input styling
    function updateInputStyling(input, errorElement, validation) {
        if (!validation.valid) {
            input.style.border = '2px solid rgb(239, 68, 68)';
            input.style.outline = 'none';

            if (validation.dangerous) {
                input.classList.add('bg-red-900/20');
            } else {
                input.classList.remove('bg-red-900/20');
            }

            errorElement.textContent = validation.message;
            errorElement.classList.remove('hidden');
        } else {
            input.style.border = '0';
            input.style.outline = 'none';
            input.classList.remove('bg-red-900/20');
            errorElement.classList.add('hidden');
        }
    }

    const registerForm = document.getElementById('zorah-register');

    if (registerForm) {
        const togglePassword = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const eyeIcon = document.getElementById('eyeIcon');
        const eyeSlashIcon = document.getElementById('eyeSlashIcon');
        const nameInput = document.getElementById('name');
        const emailInput = document.getElementById('email');
        const submitButton = document.querySelector('button[type="submit"]');
        const termsCheckbox = document.getElementById('termsCheckbox');
        const nameError = document.getElementById('nameError');
        const emailError = document.getElementById('emailError');
        const passwordError = document.getElementById('passwordError');

        // Password strength elements
        const passwordStrengthBar = document.getElementById('passwordStrengthBar');
        const passwordStrengthText = document.getElementById('passwordStrengthText');
        const reqLength = document.getElementById('req-length');
        const reqUppercase = document.getElementById('req-uppercase');
        const reqLowercase = document.getElementById('req-lowercase');
        const reqNumber = document.getElementById('req-number');
        const reqSpecial = document.getElementById('req-special');

        // Password toggle
        if (togglePassword) {
            togglePassword.addEventListener('click', function () {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                eyeIcon.classList.toggle('hidden');
                eyeSlashIcon.classList.toggle('hidden');
            });
        }

        // Disable submit button initially
        submitButton.disabled = true;
        submitButton.style.opacity = '0.5';
        submitButton.style.cursor = 'not-allowed';

        // Validate name
        function validateName(value) {
            if (!value || value.trim() === '') {
                return { valid: false, message: 'Name is required' };
            }

            const malicious = containsMaliciousPattern(value);
            if (malicious) {
                return { valid: false, message: 'Invalid characters detected. Please use only letters and spaces.', dangerous: true };
            }

            if (!validPatterns.name.test(value)) {
                return { valid: false, message: 'Name must be 2-50 characters (letters, spaces, hyphens)' };
            }

            return { valid: true, message: '' };
        }

        // Validate email
        function validateEmail(value) {
            if (!value || value.trim() === '') {
                return { valid: false, message: 'Email is required' };
            }

            const malicious = containsMaliciousPattern(value);
            if (malicious) {
                return { valid: false, message: 'Invalid characters detected. Please use a valid email format.', dangerous: true };
            }

            if (!validPatterns.email.test(value)) {
                return { valid: false, message: 'Please enter a valid email address' };
            }

            return { valid: true, message: '' };
        }

        // Validate password with strength checking
        function validatePasswordStrength(value) {
            if (!value || value.trim() === '') {
                resetPasswordStrength();
                return { valid: false, message: 'Password is required' };
            }

            const malicious = containsMaliciousPattern(value);
            if (malicious) {
                resetPasswordStrength();
                return { valid: false, message: 'Invalid characters detected. Please avoid special code sequences.', dangerous: true };
            }

            const requirements = {
                length: value.length >= validPatterns.password.minLength,
                uppercase: validPatterns.password.hasUppercase.test(value),
                lowercase: validPatterns.password.hasLowercase.test(value),
                number: validPatterns.password.hasNumber.test(value),
                special: validPatterns.password.hasSpecial.test(value)
            };

            updateRequirementIndicator(reqLength, requirements.length);
            updateRequirementIndicator(reqUppercase, requirements.uppercase);
            updateRequirementIndicator(reqLowercase, requirements.lowercase);
            updateRequirementIndicator(reqNumber, requirements.number);
            updateRequirementIndicator(reqSpecial, requirements.special);

            const strength = Object.values(requirements).filter(Boolean).length;
            updatePasswordStrength(strength);

            const allRequirementsMet = Object.values(requirements).every(Boolean);

            if (!allRequirementsMet) {
                return { valid: false, message: 'Password does not meet all security requirements' };
            }

            return { valid: true, message: '' };
        }

        function updateRequirementIndicator(element, isMet) {
            const parent = element.parentElement;
            if (isMet) {
                element.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />';
                element.classList.remove('text-gray-500', 'text-red-400');
                element.classList.add('text-green-400');
                parent.classList.remove('text-gray-500', 'text-red-400');
                parent.classList.add('text-green-400');
            } else {
                element.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />';
                element.classList.remove('text-gray-500', 'text-green-400');
                element.classList.add('text-gray-500');
                parent.classList.remove('text-green-400', 'text-red-400');
                parent.classList.add('text-gray-500');
            }
        }

        function updatePasswordStrength(strength) {
            const colors = ['#ef4444', '#f97316', '#eab308', '#84cc16', '#22c55e'];
            const labels = ['Very Weak', 'Weak', 'Fair', 'Good', 'Strong'];
            const widths = ['20%', '40%', '60%', '80%', '100%'];

            if (strength === 0) {
                passwordStrengthBar.style.width = '0%';
                passwordStrengthText.textContent = '';
                return;
            }

            const index = strength - 1;
            passwordStrengthBar.style.width = widths[index];
            passwordStrengthBar.style.backgroundColor = colors[index];
            passwordStrengthText.textContent = labels[index];
            passwordStrengthText.style.color = colors[index];
        }

        function resetPasswordStrength() {
            passwordStrengthBar.style.width = '0%';
            passwordStrengthText.textContent = '';

            [reqLength, reqUppercase, reqLowercase, reqNumber, reqSpecial].forEach(element => {
                element.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />';
                element.classList.remove('text-green-400', 'text-red-400');
                element.classList.add('text-gray-500');
                element.parentElement.classList.remove('text-green-400', 'text-red-400');
                element.parentElement.classList.add('text-gray-500');
            });
        }

        function checkRegisterFormValidity() {
            const nameValidation = validateName(nameInput.value);
            const emailValidation = validateEmail(emailInput.value);
            const passwordValidation = validatePasswordStrength(passwordInput.value);
            const termsAccepted = termsCheckbox.checked;

            const isValid = nameValidation.valid && emailValidation.valid && passwordValidation.valid && termsAccepted;

            if (isValid) {
                submitButton.disabled = false;
                submitButton.style.opacity = '1';
                submitButton.style.cursor = 'pointer';
                submitButton.classList.remove('opacity-50', 'cursor-not-allowed');
            } else {
                submitButton.disabled = true;
                submitButton.style.opacity = '0.5';
                submitButton.style.cursor = 'not-allowed';
                submitButton.classList.add('opacity-50', 'cursor-not-allowed');
            }

            return isValid;
        }

        // Event listeners for register form
        nameInput.addEventListener('input', function () {
            const validation = validateName(this.value);
            updateInputStyling(this, nameError, validation);
            checkRegisterFormValidity();
        });

        nameInput.addEventListener('blur', function () {
            const validation = validateName(this.value);
            updateInputStyling(this, nameError, validation);
            checkRegisterFormValidity();
        });

        emailInput.addEventListener('input', function () {
            const validation = validateEmail(this.value);
            updateInputStyling(this, emailError, validation);
            checkRegisterFormValidity();
        });

        emailInput.addEventListener('blur', function () {
            const validation = validateEmail(this.value);
            updateInputStyling(this, emailError, validation);
            checkRegisterFormValidity();
        });

        passwordInput.addEventListener('input', function () {
            const validation = validatePasswordStrength(this.value);
            updateInputStyling(this, passwordError, validation);
            checkRegisterFormValidity();
        });

        passwordInput.addEventListener('blur', function () {
            const validation = validatePasswordStrength(this.value);
            updateInputStyling(this, passwordError, validation);
            checkRegisterFormValidity();
        });

        termsCheckbox.addEventListener('change', function () {
            checkRegisterFormValidity();
        });

        // Prevent paste of malicious content
        [nameInput, emailInput, passwordInput].forEach(input => {
            input.addEventListener('paste', function (e) {
                setTimeout(() => {
                    let validation;
                    let errorElement;

                    if (input === nameInput) {
                        validation = validateName(input.value);
                        errorElement = nameError;
                    } else if (input === emailInput) {
                        validation = validateEmail(input.value);
                        errorElement = emailError;
                    } else {
                        validation = validatePasswordStrength(input.value);
                        errorElement = passwordError;
                    }

                    updateInputStyling(input, errorElement, validation);
                    checkRegisterFormValidity();
                }, 0);
            });
        });

        // Monitor for autocomplete
        const autoCompleteCheckRegister = setInterval(() => {
            if (nameInput.value || emailInput.value || passwordInput.value) {
                checkRegisterFormValidity();
            }
        }, 500);
        setTimeout(() => clearInterval(autoCompleteCheckRegister), 5000);
    }

    const loginForm = document.getElementById('zorah-login');

    if (loginForm) {
        const usernameInput = document.getElementById('username');
        const passwordInput = document.getElementById('password');
        const submitButton = document.querySelector('button[type="submit"]');
        const usernameError = document.getElementById('usernameError');
        const passwordError = document.getElementById('passwordError');

        // Disable submit button initially
        submitButton.disabled = true;
        submitButton.style.opacity = '0.5';
        submitButton.style.cursor = 'not-allowed';

        // Validate username/email
        function validateUsername(value) {
            if (!value || value.trim() === '') {
                return { valid: false, message: 'Username or email is required' };
            }

            const malicious = containsMaliciousPattern(value);
            if (malicious) {
                return { valid: false, message: 'Invalid characters detected. Please use only letters, numbers, and basic symbols.', dangerous: true };
            }

            const isEmail = value.includes('@');

            if (isEmail) {
                if (!validPatterns.email.test(value)) {
                    return { valid: false, message: 'Please enter a valid email address' };
                }
            } else {
                if (!validPatterns.username.test(value)) {
                    return { valid: false, message: 'Username must be 3-30 characters (letters, numbers, ., -, _)' };
                }
            }

            return { valid: true, message: '' };
        }

        // Validate password (simple for login)
        function validatePasswordLogin(value) {
            if (!value || value.trim() === '') {
                return { valid: false, message: 'Password is required' };
            }

            const malicious = containsMaliciousPattern(value);
            if (malicious) {
                return { valid: false, message: 'Invalid characters detected. Please avoid special code sequences.', dangerous: true };
            }

            if (!validPatterns.passwordSimple.test(value)) {
                return { valid: false, message: 'Password must be at least 8 characters' };
            }

            return { valid: true, message: '' };
        }

        function checkLoginFormValidity() {
            const usernameValidation = validateUsername(usernameInput.value);
            const passwordValidation = validatePasswordLogin(passwordInput.value);

            const isValid = usernameValidation.valid && passwordValidation.valid;

            if (isValid) {
                submitButton.disabled = false;
                submitButton.style.opacity = '1';
                submitButton.style.cursor = 'pointer';
                submitButton.classList.remove('opacity-50', 'cursor-not-allowed');
            } else {
                submitButton.disabled = true;
                submitButton.style.opacity = '0.5';
                submitButton.style.cursor = 'not-allowed';
                submitButton.classList.add('opacity-50', 'cursor-not-allowed');
            }

            return isValid;
        }

        // Event listeners for login form
        usernameInput.addEventListener('input', function () {
            const validation = validateUsername(this.value);
            updateInputStyling(this, usernameError, validation);
            checkLoginFormValidity();
        });

        usernameInput.addEventListener('blur', function () {
            const validation = validateUsername(this.value);
            updateInputStyling(this, usernameError, validation);
            checkLoginFormValidity();
        });

        passwordInput.addEventListener('input', function () {
            const validation = validatePasswordLogin(this.value);
            updateInputStyling(this, passwordError, validation);
            checkLoginFormValidity();
        });

        passwordInput.addEventListener('blur', function () {
            const validation = validatePasswordLogin(this.value);
            updateInputStyling(this, passwordError, validation);
            checkLoginFormValidity();
        });

        // Prevent paste of malicious content
        [usernameInput, passwordInput].forEach(input => {
            input.addEventListener('paste', function (e) {
                setTimeout(() => {
                    const validation = input === usernameInput ?
                        validateUsername(input.value) :
                        validatePasswordLogin(input.value);

                    const errorElement = input === usernameInput ? usernameError : passwordError;
                    updateInputStyling(input, errorElement, validation);
                    checkLoginFormValidity();
                }, 0);
            });
        });

        // Monitor for autocomplete
        const autoCompleteCheckLogin = setInterval(() => {
            if (usernameInput.value || passwordInput.value) {
                checkLoginFormValidity();
            }
        }, 500);
        setTimeout(() => clearInterval(autoCompleteCheckLogin), 5000);
    }
 

    $(document).on("submit", ".zorah-register", function (e) {
        e.preventDefault();
        var formData = new FormData(this);
        var button = $('#submit').html();
        formData.append('request', 'register');
        formData.append('fingerprint', radar._Tracker().deviceFingerPrint);

        general.ajaxFormData('.zorah-register', 'POST', BASE + 'src/Processor/Auth/auth.php', formData, '#submit', button, function (data) {
            $('#modalScreen').html(general.modalNote('Registration Successful', data.message, 'success'));
            modalController('modalNote', { bgClose: false, keyboard: false })
            .then(modal => {
                modal.show();
                modal.setSize('full');
            })
            $('#submit').attr('disabled', true);
            $('#submit').attr('style', 'opacity: 0.5');
            document.querySelector('#submit').style.pointerEvents = "none";
            $('#submit').html('Redirecting...');
            setTimeout(function () {
                general.redirect(data.base + 'home');
            }, 8000);
        }, 'centerLoader');
    });

    $(document).on("submit", ".zorah-login", function (e) {
        e.preventDefault();
        var formData = new FormData(this);
        var button = $('#submit').html();
        formData.append('fingerprint', radar._Tracker().deviceFingerPrint);
        formData.append('request', 'login');
        formData.append('requestUrl', general.getCurrentUrl());
        general.ajaxFormData('.zorah-login', 'POST', BASE + 'src/Processor/Auth/auth.php', formData, '#submit', button, function (data) {
            $('#submit').removeClass("btn-dim");
            $('#submit').attr('disabled', true);
            $('#submit').attr('style', 'opacity: 0.5');
            document.querySelector('#submit').style.pointerEvents = "none";
            $('#submit').html('Redirecting...');
            setTimeout(() => {
                general.reload();
            }, 3000);
            general.redirect(data.url);
            return;
        }, 'centerLoader');
    });




})

if (document.getElementsByClassName('MAnchors_').length) {
  let clearAnalyticsInterval = setInterval(function () {
    const anchorKey = storage.get('anchorKey');
    if (anchorKey != 'undefined' && anchorKey !== '') {
      clearInterval(clearAnalyticsInterval);

      var tracking = document.getElementsByClassName('MAnchors_');
      var anchor = new Anchor;
      anchor.endpoint = BASE + 'src/Addons/anchor.php';
      anchor.token = JSON.stringify({token: anchorKey, 'fingerprint' : radar._Tracker().deviceFingerPrint});
      for (const tt of tracking) {
        anchor.init({
          clickCount: true,
          clickDetails: true,
          context: true,
          textCopy: true,
          actionItem: {
            processOnAction: true,
            selector: tt
          },
        });
      }
    }
  }, 1000);
}