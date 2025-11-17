var general = new General;
general.secure_token(BASE + 'src/Addons/general.php', 'anchorKey');

window.createServerPoller = function (options) {
    const defaults = {
        id: null,
        endpoint: BASE + 'src/Processor/Manager/zorah.php',
        requestType: 'checkStatus',
        onUpdate: null,
        onError: null,
        initialInterval: 3000,      // Start at 3 seconds
        maxInterval: 30000,         // Cap at 30 seconds
        maxAttempts: 120,           // ~1 hour with increasing intervals
        backoffMultiplier: 1.2,     // Gradually increase interval
        additionalData: {},         // Additional form data to send
        button: null,
        main_selector: null,
        button_selector: null
    };

    const config = Object.assign({}, defaults, options);

    if (!config.id || !config.onUpdate) {
        console.error('Poller requires id and onUpdate callback');
        return null;
    }

    let currentInterval = config.initialInterval;
    let attempts = 0;
    let pollTimer = null;
    let isActive = true;
    let lastStatus = null;

    const stopPolling = function () {
        isActive = false;
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
        console.log(`Polling stopped for ID: ${config.id}`);
    };

    const poll = function () {
        if (!isActive || attempts >= config.maxAttempts) {
            if (attempts >= config.maxAttempts) {
                console.warn(`Max polling attempts reached for ID: ${config.id}`);
                if (config.onError) {
                    config.onError({ error: 'Max attempts reached', attempts });
                }
            }
            stopPolling();
            return;
        }

        attempts++;

        const formData = new FormData();
        formData.append('request', config.requestType);
        formData.append('id', config.id);
        formData.append('fingerprint', radar._Tracker().deviceFingerPrint);
        formData.append('csrf_name', document.querySelector('input[name="csrf_name"]')?.value || '');
        formData.append('csrf_value', document.querySelector('input[name="csrf_value"]')?.value || '');

        // Append any additional data
        if (config.additionalData && typeof config.additionalData === 'object') {
            Object.keys(config.additionalData).forEach(key => {
                formData.append(key, config.additionalData[key]);
            });
        }

        general.ajaxFormData(config.main_selector, 'POST', config.endpoint, formData, config.button_selector, config.button, function (data) {
            if (!isActive) return; // Stop if polling was cancelled

            if (data.isConfirmed) {
                config.onUpdate(data, stopPolling);
                lastStatus = data.isConfirmed;
            }

            // Schedule next poll with exponential backoff
            if (isActive) {
                currentInterval = Math.min(
                    currentInterval * config.backoffMultiplier,
                    config.maxInterval
                );

                pollTimer = setTimeout(poll, currentInterval);

                // Log every 10 attempts to avoid console spam
                if (attempts % 10 === 0) {
                    console.log(`Polling attempt ${attempts} for ID: ${config.id}, interval: ${Math.round(currentInterval / 1000)}s`);
                }
            }
        }, '');
    };

    // Start polling immediately
    poll();

    // Return controller object
    return {
        stop: stopPolling,
        isActive: function () { return isActive; },
        getAttempts: function () { return attempts; },
        getCurrentInterval: function () { return currentInterval; }
    };
};

$(window).on('load', function () {


    (function () {
        const sidebar = document.getElementById('sidebar');
        const menuOverlay = document.getElementById('menuOverlay');
        const hamburgerMenu = document.getElementById('hamburgerMenu');
        const openMobileMenu = document.getElementById('openMobileMenu');
        const closeMobileMenu = document.getElementById('closeMobileMenu');
        const notificationBtn = document.getElementById('notificationBtn');
        const notificationDropdown = document.getElementById('notificationDropdown');
        const userBtn = document.getElementById('userBtn');
        const userDropdown = document.getElementById('userDropdown');

        // Prevent body scroll when menu is open
        function toggleBodyScroll(lock) {
            if (lock) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        }

        // Open mobile menu with smooth animation
        function openMenu() {
            if (!sidebar || !menuOverlay) return;

            // Show overlay with fade in
            menuOverlay.classList.remove('hidden', 'animate__fadeOut');
            menuOverlay.classList.add('animate__animated', 'animate__fadeIn', 'animate__faster');

            // Slide in sidebar from left
            sidebar.classList.remove('animate__slideOutLeft');
            sidebar.classList.add('animate__animated', 'animate__slideInLeft', 'animate__faster');
            sidebar.classList.remove('-translate-x-full');

            toggleBodyScroll(true);

            // Hide menu button
            const openBtn = openMobileMenu || hamburgerMenu;
            if (openBtn) {
                openBtn.classList.add('animate__animated', 'animate__zoomOut', 'animate__faster');
                setTimeout(() => {
                    openBtn.classList.add('hidden');
                }, 300);
            }
        }

        // Close mobile menu with smooth animation
        function closeMenu() {
            if (!sidebar || !menuOverlay) return;

            // Fade out overlay
            menuOverlay.classList.remove('animate__fadeIn');
            menuOverlay.classList.add('animate__animated', 'animate__fadeOut', 'animate__faster');
            setTimeout(() => {
                menuOverlay.classList.add('hidden');
            }, 300);

            // Slide out sidebar to left
            sidebar.classList.remove('animate__slideInLeft');
            sidebar.classList.add('animate__animated', 'animate__slideOutLeft', 'animate__faster');
            setTimeout(() => {
                sidebar.classList.add('-translate-x-full');
            }, 400);

            toggleBodyScroll(false);

            // Show menu button back
            const openBtn = openMobileMenu || hamburgerMenu;
            if (openBtn) {
                openBtn.classList.remove('hidden', 'animate__zoomOut');
                openBtn.classList.add('animate__animated', 'animate__zoomIn', 'animate__faster');
            }
        }

        if (hamburgerMenu) hamburgerMenu.addEventListener('click', openMenu);
        if (openMobileMenu) openMobileMenu.addEventListener('click', openMenu);
        if (closeMobileMenu) closeMobileMenu.addEventListener('click', closeMenu);
        if (menuOverlay) menuOverlay.addEventListener('click', closeMenu);

        // Close menu on escape key
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && sidebar && !sidebar.classList.contains('-translate-x-full')) {
                closeMenu();
            }
        });

        // Account Selector Dropdown
        const accountSelector = document.getElementById('accountSelector');
        const accountDropdownList = document.getElementById('accountDropdownList');
        const accountDropdownIcon = document.getElementById('accountDropdownIcon');
        const selectedAccountName = document.getElementById('selectedAccountName');
        const selectedAccountBalance = document.getElementById('selectedAccountBalance');

        if (accountSelector && accountDropdownList) {
            accountSelector.addEventListener('click', function (e) {
                e.stopPropagation();
                const isHidden = accountDropdownList.classList.contains('hidden');

                if (isHidden) {
                    accountDropdownList.classList.remove('hidden');
                    accountDropdownIcon.style.transform = 'rotate(180deg)';
                } else {
                    accountDropdownList.classList.add('hidden');
                    accountDropdownIcon.style.transform = 'rotate(0deg)';
                }
            });

            // Close dropdown when clicking outside
            document.addEventListener('click', function (e) {
                if (accountSelector && accountDropdownList && !accountSelector.contains(e.target) && !accountDropdownList.contains(e.target)) {
                    accountDropdownList.classList.add('hidden');
                    if (accountDropdownIcon) {
                        accountDropdownIcon.style.transform = 'rotate(0deg)';
                    }
                }
            });
        }

        // Notification dropdown toggle
        if (notificationBtn) notificationBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            if (notificationDropdown) notificationDropdown.classList.toggle('active');
            if (userDropdown) userDropdown.classList.remove('active');
        });

        // User dropdown toggle
        if (userBtn) userBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            if (userDropdown) userDropdown.classList.toggle('active');
            if (notificationDropdown) notificationDropdown.classList.remove('active');
        });

        // Close dropdowns when clicking outside
        document.addEventListener('click', (e) => {
            if (notificationBtn && notificationDropdown) {
                if (!notificationBtn.contains(e.target) && !notificationDropdown.contains(e.target)) {
                    notificationDropdown.classList.remove('active');
                }
            }
            if (userBtn && userDropdown) {
                if (!userBtn.contains(e.target) && !userDropdown.contains(e.target)) {
                    userDropdown.classList.remove('active');
                }
            }
        });

        // Close menu on window resize if above lg breakpoint
        let resizeTimer;
        window.addEventListener('resize', function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                if (window.innerWidth >= 1024) {
                    closeMenu();
                }
            }, 250);
        });


    })();

    function getAllSupportedCurrency(callback) {
        // Check if we have cached currencies
        if (storage.get('supportedCurrencies') && storage.get('supportedCurrencies') != 'undefined') {
            const cached = JSON.parse(storage.get('supportedCurrencies'));
            if (callback) callback(cached);
            return;
        }

        // Fetch from server
        var formData = new FormData();
        formData.append('request', 'getSupportedCurrency');
        formData.append('fingerprint', radar._Tracker().deviceFingerPrint);

        general.ajaxFormData('.virtual-select', 'POST', BASE + 'src/Processor/Manager/zorah.php', formData, '.virtual-select', '', function (data) {
            if (data && data.supportedCurrencies) {
                storage.set('supportedCurrencies', JSON.stringify(data.supportedCurrencies));
                if (callback) callback(data.supportedCurrencies);
            }
        }, 'centerLoader');
    }

    // Function to get exchange rate and update display element
    function getExchangeRate(currency, displaySelector, callback) {
        // Cache key for the exchange rate
        const cacheKey = `exchange_rate_${currency}`;

        // Check cache first (cache for 1 hour)
        if (storage.get(cacheKey) && storage.get(cacheKey) != 'undefined') {
            const cached = JSON.parse(storage.get(cacheKey));
            const displayElement = document.querySelector(displaySelector);
            if (displayElement) {
                if (displayElement.tagName === 'INPUT' || displayElement.tagName === 'TEXTAREA') {
                    displayElement.value = cached.rate;
                } else {
                    displayElement.textContent = cached.rate;
                }
            }
            if (callback) callback(cached.rate);
            return;
        }

        // Fetch from server
        var formData = new FormData();
        formData.append('request', 'getExchangeRate');
        formData.append('currency', currency);
        formData.append('fingerprint', radar._Tracker().deviceFingerPrint);

        general.ajaxFormData(displaySelector, 'POST', BASE + 'src/Processor/Manager/zorah.php', formData, displaySelector, '', function (data) {
            if (data && data.rate) {
                // Cache the rate for 1 hour
                const cacheData = { rate: data.rate, timestamp: Date.now() };
                storage.set(cacheKey, JSON.stringify(cacheData));

                // Update display element
                const displayElement = document.querySelector(displaySelector);
                if (displayElement) {
                    if (displayElement.tagName === 'INPUT' || displayElement.tagName === 'TEXTAREA') {
                        displayElement.value = data.rate;
                    } else {
                        displayElement.textContent = data.rate;
                    }
                }

                if (callback) callback(data.rate);
            }
        }, 'centerLoader');
    }

    // Initialize exchange rate handler for .virtual-select elements
    function initExchangeRateHandler(selectSelector, displaySelector, baseCurrency) {
        $(document).on('change', selectSelector, function () {
            const selectedCurrency = $(this).val();
            if (selectedCurrency && selectedCurrency !== baseCurrency) {
                getExchangeRate(baseCurrency || 'USD', selectedCurrency, displaySelector, function (rate) {
                    console.log(`Exchange rate from ${baseCurrency || 'USD'} to ${selectedCurrency}: ${rate}`);
                });
            }
        });
    }

    function accountSelector() {
        // Handle account selection
        const accountOptions = document.querySelectorAll('.account-option');
        accountOptions.forEach(option => {
            option.addEventListener('click', function (e) {
                e.stopPropagation();
                const accountId = this.getAttribute('data-account'); // or this.dataset.account
                const accountName = this.querySelector('h4').textContent;
                const balanceText = this.querySelector('p.text-xs').innerHTML;
                const accountIcon = this.querySelector('svg').cloneNode(true);

                // Update selected account display
                if (selectedAccountName && selectedAccountBalance) {
                    selectedAccountName.textContent = accountName;
                    selectedAccountBalance.innerHTML = balanceText;
                }

                var formData = new FormData();
                formData.append('request', 'getAccountBundle');
                formData.append('account_id', accountId);
                formData.append('fingerprint', radar._Tracker().deviceFingerPrint);
                var button = $('.main-dashboard').html();

                general.ajaxFormData('.main-dashboard', 'POST', BASE + 'src/Processor/Manager/zorah.php', formData, '.main-dashboard', button, function (data) {
                    $('.main-dashboard').html(data.accountBundle);
                }, 'pageLoader');

                accountDropdownList.classList.add('hidden');
                accountDropdownIcon.style.transform = 'rotate(0deg)';
            });
        });

    }

    if ($('.loadAccounts').length) {
        var formData = new FormData();
        var button = $('.loadAccounts').html();
        formData.append('request', 'loadAccounts');
        formData.append('fingerprint', radar._Tracker().deviceFingerPrint);

        general.ajaxFormData('.loadAccounts', 'POST', BASE + 'src/Processor/Manager/zorah.php', formData, '.loadAccounts', button, function (data) {
            $('.loadAccounts').html(data.accounts);
            accountSelector();


        }, 'centerLoader');
    }

    if ($('.main-dashboard').length) {
        var formData = new FormData();
        var button = $('.main-dashboard').html();
        formData.append('request', 'dashboard');
        formData.append('fingerprint', radar._Tracker().deviceFingerPrint);

        general.ajaxFormData('.main-dashboard', 'POST', BASE + 'src/Processor/Manager/zorah.php', formData, '.main-dashboard', button, function (data) {
            if (data.modalId) {
                $('#modalScreen').html(data.modal);
                getAllSupportedCurrency(function (supportedCurrencies) {
                    // Populate all currency select dropdowns
                    if (supportedCurrencies && Array.isArray(supportedCurrencies)) {
                        const selects = document.querySelectorAll('.virtual-select');
                        selects.forEach(select => {
                            select.innerHTML = '';
                            supportedCurrencies.forEach(currency => {
                                if (!currency || currency.trim() === '') {
                                    return;
                                }
                                const option = document.createElement('option');
                                option.value = currency;
                                option.textContent = currency;
                                select.appendChild(option);
                            });
                            const usdOption = Array.from(select.options).find(opt => opt.value === 'USD');
                            if (usdOption) {
                                select.value = 'USD';
                            }
                        });
                    }
                });

                modalController(data.modalId, { bgClose: false, keyboard: false })
                    .then(modal => {
                        modal.show();
                        modal.setSize('full');
                        try {
                            const modalRoot = document.getElementById(data.modalId) || document;
                            if (window.initAllWizards) {
                                window.initAllWizards(modalRoot);
                            } else if (window.initStepWizard) {
                                modalRoot.querySelectorAll('form[data-step-wizard], form#createAccountForm').forEach(f => window.initStepWizard(f));
                            }
                        } catch (err) {
                            console.error('Wizard initialization failed:', err);
                        }
                    });
                return;
            }
            $('.main-dashboard').html(data.interface);
        }, 'pageLoader');
    }



    $(document).on("click", ".openAccountModal", function (e) {
        e.preventDefault();
        var formData = new FormData();
        var button = $('.openAccountModal').html();
        formData.append('request', 'openAccountModal');
        formData.append('fingerprint', radar._Tracker().deviceFingerPrint);

        general.ajaxFormData('.openAccountModal', 'POST', BASE + 'src/Processor/Manager/zorah.php', formData, '.openAccountModal', button, function (data) {
            if (data.modalId) {
                $('#modalScreen').html(data.modal);
                getAllSupportedCurrency(function (supportedCurrencies) {
                    // Populate all currency select dropdowns
                    if (supportedCurrencies && Array.isArray(supportedCurrencies)) {
                        const selects = document.querySelectorAll('.virtual-select');
                        selects.forEach(select => {
                            select.innerHTML = '';
                            supportedCurrencies.forEach(currency => {
                                if (!currency || currency.trim() === '') {
                                    return;
                                }
                                const option = document.createElement('option');
                                option.value = currency;
                                option.textContent = currency;
                                select.appendChild(option);
                            });
                            const usdOption = Array.from(select.options).find(opt => opt.value === 'USD');
                            if (usdOption) {
                                select.value = 'USD';
                            }
                        });
                    }
                });

                modalController(data.modalId, { bgClose: false, keyboard: false })
                    .then(modal => {
                        modal.show();
                        modal.setSize('full');
                        try {
                            const modalRoot = document.getElementById(data.modalId) || document;
                            if (window.initAllWizards) {
                                window.initAllWizards(modalRoot);
                            } else if (window.initStepWizard) {
                                modalRoot.querySelectorAll('form[data-step-wizard], form#createAccountForm').forEach(f => window.initStepWizard(f));
                            }
                        } catch (err) {
                            console.error('Wizard initialization failed:', err);
                        }
                    });
                return;
            }
            $('.main-dashboard').html(data.interface);
        }, 'centerLoader');
    });

    $(document).on("click", ".receiveFund", function (e) {
        e.preventDefault();
        var formData = new FormData();
        var button = $('.receiveFund').html();
        const activeAccount = $('.currentAccount').attr('account');
        formData.append('request', 'receiveFund');
        formData.append('account_id', activeAccount);
        formData.append('fingerprint', radar._Tracker().deviceFingerPrint);

        general.ajaxFormData('.receiveFund', 'POST', BASE + 'src/Processor/Manager/zorah.php', formData, '.receiveFund', button, function (data) {
            if (data.modalId) {
                $('#modalScreen').html(data.modal);
                initReceiveTabs()
                getAllSupportedCurrency(function (supportedCurrencies) {
                    // Populate all currency select dropdowns
                    if (supportedCurrencies && Array.isArray(supportedCurrencies)) {
                        const selects = document.querySelectorAll('.virtual-select');
                        selects.forEach(select => {
                            select.innerHTML = '';
                            supportedCurrencies.forEach(currency => {
                                if (!currency || currency.trim() === '') {
                                    return;
                                }
                                const option = document.createElement('option');
                                option.value = currency;
                                option.textContent = currency;
                                select.appendChild(option);
                            });
                            const usdOption = Array.from(select.options).find(opt => opt.value === 'USD');
                            if (usdOption) {
                                select.value = 'USD';
                            }
                        });
                    }
                });

                modalController(data.modalId, { bgClose: false, keyboard: false }).then(modal => {
                    modal.show();
                    modal.setSize('full');
                    try {
                        if (window.initReceiveBridge) {
                            window.initReceiveBridge();
                        }
                    } catch (err) {
                        console.error('Bridge init failed:', err);
                    }
                });
            }

        }, 'centerLoader');
    });

    // Reusable step wizard
    (function () {
        function makeWizard(form) {
            if (!form || form.dataset.wizardReady) return;
            form.dataset.wizardReady = '1';
            const steps = Array.from(form.querySelectorAll('.step'));
            const prevBtn = form.querySelector('#prevBtn');
            const nextBtn = form.querySelector('#nextBtn');
            const submitBtn = form.querySelector('#submitBtn');
            const formAccountType = form.querySelector('#formAccountType');
            const depositSavings = form.querySelector('#initialDepositSavings');
            const depositCurrent = form.querySelector('#initialDepositCurrent');
            const detailsSavings = form.querySelector('#detailsSavings');
            const detailsCurrent = form.querySelector('#detailsCurrent');
            const depositError = form.querySelector('#depositError');
            const accountPassword = form.querySelector('#accountPassword');
            const accountPasswordError = form.querySelector('#accountPasswordError');
            const loginPasswordWarning = form.querySelector('#loginPasswordWarning');
            const toggleAccountPassword = form.querySelector('#toggleAccountPassword');
            const reviewType = form.querySelector('#reviewType');
            const reviewDeposit = form.querySelector('#reviewDeposit');
            const reviewAPY = form.querySelector('#reviewAPY');
            const reviewAPYWrap = form.querySelector('#reviewAPYWrap');
            const createMessage = form.querySelector('#createMessage');

            function getType() { return formAccountType?.value || ''; }
            function setType(v) { if (formAccountType) formAccountType.value = v; }
            function setIndex(i) { form.dataset.currentStep = String(i); }
            function getIndex() { return parseInt(form.dataset.currentStep || '0', 10); }
            function showStep(i) {
                const currentIdx = getIndex();
                if (i === currentIdx) {
                    steps.forEach((s, idx) => s.classList.toggle('hidden', idx !== i));
                    updateButtons(i); if (i === 3) updateReview();
                    return;
                }
                const goingForward = i > currentIdx;

                steps.forEach((s, idx) => {
                    if (idx === i) {
                        // Show new step with slide animation
                        s.classList.remove('hidden');
                        s.classList.remove('animate__slideOutLeft', 'animate__slideOutRight', 'animate__fadeOut');
                        s.classList.add(goingForward ? 'animate__slideInRight' : 'animate__slideInLeft');
                    } else {
                        // Hide all other steps
                        s.classList.add('hidden');
                        s.classList.remove('animate__slideInLeft', 'animate__slideInRight', 'animate__fadeIn');
                    }
                });

                setIndex(i);
                updateButtons(i);
                if (i === 3) updateReview();

                // Focus first input after animation
                setTimeout(() => {
                    const firstInput = steps[i].querySelector('input, select, textarea, button');
                    if (firstInput) firstInput.focus({ preventScroll: true });
                }, 100);
            }
            function updateButtons(i) {
                if (!prevBtn || !nextBtn || !submitBtn) return;
                if (i === 0) { prevBtn.classList.add('hidden'); nextBtn.classList.remove('hidden'); submitBtn.classList.add('hidden'); }
                else if (i === 3) { prevBtn.classList.remove('hidden'); nextBtn.classList.add('hidden'); submitBtn.classList.remove('hidden'); }
                else if (i === 4) { prevBtn.classList.add('hidden'); nextBtn.classList.add('hidden'); submitBtn.classList.add('hidden'); }
                else { prevBtn.classList.remove('hidden'); nextBtn.classList.remove('hidden'); submitBtn.classList.add('hidden'); }
            }
            function validate(i) {
                if (i === 0) { return !!getType(); }
                if (i === 1) {
                    if (getType() === 'current') {
                        const val = parseFloat(depositCurrent?.value || '0');
                        if (isNaN(val) || val < 5) {
                            depositError?.classList.remove('hidden');
                            return false;
                        }
                        depositError?.classList.add('hidden');
                    }
                    return true;
                }
                if (i === 2) {
                    // Validate password step
                    const pwd = accountPassword?.value || '';

                    // Check password strength requirements
                    const hasLength = pwd.length >= 8;
                    const hasUppercase = /[A-Z]/.test(pwd);
                    const hasLowercase = /[a-z]/.test(pwd);
                    const hasNumber = /[0-9]/.test(pwd);
                    const hasSpecial = /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(pwd);

                    const isStrong = hasLength && hasUppercase && hasLowercase && hasNumber && hasSpecial;

                    if (!isStrong) {
                        accountPasswordError?.classList.remove('hidden');
                        return false;
                    }
                    accountPasswordError?.classList.add('hidden');

                    // Check if different from login password (stored check - will validate on server too)
                    loginPasswordWarning?.classList.add('hidden');

                    return true;
                }
                return true;
            }
            function updateReview() {
                const type = getType() || 'savings';
                if (reviewType) reviewType.textContent = type.charAt(0).toUpperCase() + type.slice(1);
                let val = 0; if (type === 'current') { val = parseFloat(depositCurrent?.value || '0') || 0; } else { val = parseFloat(depositSavings?.value || '0') || 0; }
                if (reviewDeposit) reviewDeposit.textContent = '$' + val.toFixed(2);
                const apyTxt = (type === 'savings') ? (form.querySelector('.apy-display')?.textContent || '0%') : '0%';
                if (reviewAPY) reviewAPY.textContent = apyTxt; if (type === 'savings') { reviewAPYWrap?.classList.remove('hidden'); } else { reviewAPYWrap?.classList.add('hidden'); }
            }

            // Card clicks
            form.querySelectorAll('.account-card').forEach(card => {
                card.addEventListener('click', function (e) {
                    const type = this.getAttribute('data-type');
                    const apy = this.getAttribute('data-apy') || '0';
                    setType(type);
                    form.querySelectorAll('.account-card').forEach(c => { c.classList.remove('border-purple-500'); c.querySelector('.selection-indicator')?.classList.add('hidden'); });
                    this.classList.add('border-purple-500'); this.querySelector('.selection-indicator')?.classList.remove('hidden');
                    const radio = this.querySelector('input[type="radio"][name="account_type_choice"]'); if (radio) radio.checked = true;
                    if (type === 'savings') { detailsSavings?.classList.remove('hidden'); detailsCurrent?.classList.add('hidden'); if (depositSavings) depositSavings.disabled = false; if (depositCurrent) depositCurrent.disabled = true; form.querySelectorAll('.apy-display').forEach(el => el.textContent = apy + '%'); }
                    else { detailsSavings?.classList.add('hidden'); detailsCurrent?.classList.remove('hidden'); if (depositSavings) depositSavings.disabled = true; if (depositCurrent) depositCurrent.disabled = false; }
                    showStep(1);
                });
            });

            // Nav
            nextBtn?.addEventListener('click', function () { const idx = getIndex(); if (!validate(idx)) return; showStep(Math.min(idx + 1, steps.length - 1)); });
            prevBtn?.addEventListener('click', function () { const idx = getIndex(); showStep(Math.max(idx - 1, 0)); });

            // Password strength indicator for account password
            if (accountPassword) {
                accountPassword.addEventListener('input', function () {
                    const pwd = this.value;
                    const hasLength = pwd.length >= 8;
                    const hasUppercase = /[A-Z]/.test(pwd);
                    const hasLowercase = /[a-z]/.test(pwd);
                    const hasNumber = /[0-9]/.test(pwd);
                    const hasSpecial = /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(pwd);

                    // Check if password is valid
                    const isValid = hasLength && hasUppercase && hasLowercase && hasNumber && hasSpecial;

                    // Update border color based on validity
                    if (isValid) {
                        this.classList.remove('border-red-500');
                        this.classList.add('border-transparent');
                    } else {
                        this.classList.remove('border-transparent');
                        this.classList.add('border-red-500');
                    }

                    // Update requirement indicators
                    const updateReq = (id, met) => {
                        const el = form.querySelector('#' + id);
                        if (!el) return;
                        if (met) {
                            el.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />';
                            el.classList.add('text-green-400');
                            el.classList.remove('text-gray-500');
                        } else {
                            el.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />';
                            el.classList.remove('text-green-400');
                            el.classList.add('text-gray-500');
                        }
                    };

                    updateReq('acc-req-length', hasLength);
                    updateReq('acc-req-uppercase', hasUppercase);
                    updateReq('acc-req-lowercase', hasLowercase);
                    updateReq('acc-req-number', hasNumber);
                    updateReq('acc-req-special', hasSpecial);
                });
            }

            // Eye toggle for account password
            if (toggleAccountPassword) {
                toggleAccountPassword.addEventListener('click', function () {
                    const eyeIcon = form.querySelector('#accountEyeIcon');
                    const eyeSlashIcon = form.querySelector('#accountEyeSlashIcon');
                    if (accountPassword.type === 'password') {
                        accountPassword.type = 'text';
                        eyeIcon?.classList.add('hidden');
                        eyeSlashIcon?.classList.remove('hidden');
                    } else {
                        accountPassword.type = 'password';
                        eyeIcon?.classList.remove('hidden');
                        eyeSlashIcon?.classList.add('hidden');
                    }
                });
            }

            form.addEventListener('submit', function (e) {
                e.preventDefault();
                const idx = getIndex(); if (!validate(idx)) return;
                const fd = new FormData(form);
                fd.set('fingerprint', radar._Tracker().deviceFingerPrint);
                const selType = getType() || 'savings';
                fd.set('account_type', selType);

                // Get the correct currency and deposit based on account type
                const currencyVal = selType === 'current' ?
                    (form.querySelector('#currencyCurrent')?.value || 'USD') :
                    (form.querySelector('#currencySavings')?.value || 'USD');
                const depVal = selType === 'current' ? (depositCurrent?.value || '0') : (depositSavings?.value || '0');

                fd.set('currency', currencyVal);
                fd.set('initial_deposit', depVal);

                // Add account password
                if (accountPassword) {
                    fd.set('account_password', accountPassword.value);
                }

                const buttonHtml = $('#submitBtn').html();
                general.ajaxFormData('#createAccountForm', 'POST', BASE + 'src/Processor/Manager/zorah.php', fd, '#submitBtn', buttonHtml, function (data) {
                    if (data && data.success) {
                        // Populate success screen with account details
                        const accountName = data.account_name || titleInput?.value || 'Your Account';
                        const accountNumber = data.account_number || '-';

                        $('#successAccountName').text(accountName);
                        $('#successAccountNumber').text(accountNumber);

                        // Show step 4 (success screen) - it's the 5th step (index 4)
                        showStep(4);

                        // Setup copy button
                        $('#copyAccountNumber').off('click').on('click', function () {
                            if (navigator.clipboard) {
                                navigator.clipboard.writeText(accountNumber).then(() => {
                                    $(this).html('<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg> Copied!');
                                    setTimeout(() => {
                                        $(this).html('<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg> Copy Number');
                                    }, 2000);
                                });
                            }
                        });

                        // Setup close and reload button
                        $('#closeAndReload').off('click').on('click', function () {
                            $('#newAccountModal').addClass('hidden');
                            location.reload();
                        });

                        // Reload accounts in background
                        if ($('.loadAccounts').length) {
                            var f = new FormData();
                            f.append('request', 'loadAccounts');
                            f.append('fingerprint', radar._Tracker().deviceFingerPrint);
                            general.ajaxFormData('.loadAccounts', 'POST', BASE + 'src/Processor/Manager/zorah.php', f, '.loadAccounts', $('.loadAccounts').html(), function (d) {
                                if (d && d.accounts) $('.loadAccounts').html(d.accounts);
                            }, 'centerLoader');
                        }
                    } else {
                        if (createMessage) createMessage.textContent = (data && data.message) ? data.message : 'Failed to create account.';
                    }
                }, 'centerLoader');
            });

            // init
            setIndex(0); showStep(0);
        }

        // expose initializer
        window.initStepWizard = function (form) { makeWizard(form); };
        // global initializer for any scope
        window.initAllWizards = function (root) {
            const scope = root || document;
            const forms = scope.querySelectorAll('form[data-step-wizard], form#createAccountForm');
            forms.forEach(f => { if (window.initStepWizard) window.initStepWizard(f); });
        };
        // auto-init any present forms now
        window.initAllWizards(document);
    })();

    // Reusable Tab Controller Function
    window.initTabController = function (options) {
        const defaults = {
            tabSelector: '.receive-tab',
            contentSelector: '.receive-tab-content',
            activeClass: 'active',
            inactiveTextClass: 'text-gray-400',
            activeTextClass: 'text-white',
            onTabChange: null // callback function
        };

        const settings = Object.assign({}, defaults, options);
        const tabs = document.querySelectorAll(settings.tabSelector);
        const tabContents = document.querySelectorAll(settings.contentSelector);

        if (tabs.length === 0 || tabContents.length === 0) {
            console.warn('Tab controller: No tabs or content found');
            return;
        }

        tabs.forEach(tab => {
            tab.addEventListener('click', function () {
                const targetTab = this.getAttribute('data-tab');

                // Remove active class from all tabs
                tabs.forEach(t => {
                    t.classList.remove(settings.activeClass);
                    t.classList.add(settings.inactiveTextClass);
                    t.classList.remove(settings.activeTextClass);
                });

                // Add active class to clicked tab
                this.classList.add(settings.activeClass);
                this.classList.remove(settings.inactiveTextClass);
                this.classList.add(settings.activeTextClass);

                // Hide all tab contents
                tabContents.forEach(content => {
                    content.classList.remove(settings.activeClass);
                    content.classList.add('hidden');
                });

                // Show target tab content
                const targetContent = document.querySelector(`${settings.contentSelector}[data-content="${targetTab}"]`);
                if (targetContent) {
                    targetContent.classList.remove('hidden');
                    targetContent.classList.add(settings.activeClass);
                }

                // Execute callback if provided
                if (typeof settings.onTabChange === 'function') {
                    settings.onTabChange(targetTab, targetContent);
                }
            });
        });

        return {
            tabs: tabs,
            contents: tabContents,
            switchTo: function (tabName) {
                const targetTab = document.querySelector(`${settings.tabSelector}[data-tab="${tabName}"]`);
                if (targetTab) {
                    targetTab.click();
                }
            }
        };
    };

    // Zorah Bridge integration for Receive modal (Bridge tab) — rewritten to use AxelarBridge
    (function () {
        let bridgeInstance = null;
        let connectedWallet = null;

        function setEnvForToken(bridge, tokenSymbol) {
            const desired = tokenSymbol === 'aUSDC' ? 'testnet' : 'mainnet';
            bridge.setEnvironment(desired);
        }

        function formatFeeDisplay(fees) {
            try {
                const gas = fees.fees?.total;
                const transfer = fees.transferFee;
                const gasText = gas ? `${gas.ether} ${gas.nativeToken} ($${gas.usd})` : '-';
                const bridgeText = transfer ? `${transfer.amountHuman} ${fees.token}` : '-';
                return { gasText, bridgeText };
            } catch (_) { return { gasText: '-', bridgeText: '-' }; }
        }

        function setActiveStage(stageId) {
            const stages = ['bridgeStepApproval', 'bridgeStepGas', 'bridgeStepBridge'];
            stages.forEach(id => {
                const row = document.getElementById(id);
                if (!row) return;
                if (id === stageId) {
                    row.classList.add('border-indigo-500', 'bg-indigo-900/40');
                    row.classList.remove('opacity-50');
                } else if (row.classList.contains('bg-emerald-900/30')) {
                    // Completed stage
                    row.classList.remove('border-indigo-500', 'bg-indigo-900/40');
                    row.classList.remove('opacity-50');
                } else {
                    // Future stage
                    row.classList.remove('border-indigo-500', 'bg-indigo-900/40');
                    row.classList.add('opacity-50');
                }
            });
        }

        function updateStepStatus(stageId, status, linkHref) {
            const row = document.getElementById(stageId);
            const icon = document.getElementById(stageId + 'Icon');
            const link = document.getElementById(stageId + 'Link');
            if (!row || !icon) return;
            if (status === 'idle') {
                row.className = 'flex items-start gap-3 p-3 rounded-lg bg-black/60 border border-white/10 opacity-50';
                icon.className = 'w-5 h-5 mt-0.5 rounded-full border-2 border-gray-500';
                icon.innerHTML = '';
                if (link) { link.classList.add('hidden'); link.classList.remove('inline-flex'); }
                setActiveStage(null);
                return;
            }
            if (status === 'processing') {
                row.className = 'flex items-start gap-3 p-3 rounded-lg bg-indigo-900/40 border border-indigo-500/60';
                icon.className = 'w-5 h-5 mt-0.5 rounded-full border-2 border-indigo-400 flex items-center justify-center';
                icon.innerHTML = '<svg class="w-3 h-3 text-indigo-300 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';
                if (link) { link.classList.add('hidden'); link.classList.remove('inline-flex'); }
                setActiveStage(stageId);
                return;
            }
            if (status === 'complete') {
                row.className = 'flex items-start gap-3 p-3 rounded-lg bg-emerald-900/30 border border-emerald-500/70';
                icon.className = 'w-5 h-5 mt-0.5 rounded-full border-2 border-emerald-400 flex items-center justify-center bg-emerald-500/20';
                icon.innerHTML = '<svg class="w-3 h-3 text-emerald-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>';
                if (link) {
                    if (linkHref) {
                        link.href = linkHref;
                        link.classList.remove('hidden');
                        link.classList.add('inline-flex');
                    } else {
                        link.classList.add('hidden');
                        link.classList.remove('inline-flex');
                    }
                }
                setActiveStage(null);
            }
        }

        function showStep(step) {
            const stepWallet = document.getElementById('bridgeStepWallet');
            const stepForm = document.getElementById('bridgeStepForm');
            const stepProcessing = document.getElementById('bridgeStepProcessing');
            if (!stepWallet || !stepForm || !stepProcessing) return;
            if (step === 'wallet') {
                stepWallet.classList.remove('hidden');
                stepForm.classList.add('hidden');
                stepProcessing.classList.add('hidden');
            } else if (step === 'form') {
                stepWallet.classList.add('hidden');
                stepForm.classList.remove('hidden');
                stepProcessing.classList.add('hidden');
            } else if (step === 'processing') {
                stepWallet.classList.add('hidden');
                stepForm.classList.add('hidden');
                stepProcessing.classList.remove('hidden');
                const l1 = document.getElementById('bridgeStepApprovalLink');
                const l2 = document.getElementById('bridgeStepGasLink');
                const l3 = document.getElementById('bridgeStepBridgeLink');
                if (l1) { l1.classList.add('hidden'); l1.classList.remove('inline-flex'); }
                if (l2) { l2.classList.add('hidden'); l2.classList.remove('inline-flex'); }
                if (l3) { l3.classList.add('hidden'); l3.classList.remove('inline-flex'); }
                updateStepStatus('bridgeStepApproval', 'idle');
                updateStepStatus('bridgeStepGas', 'idle');
                updateStepStatus('bridgeStepBridge', 'idle');
            }
        }

        async function ensureNetwork(bridge, sourceChain) {
            try { await bridge.switchChain(sourceChain); } catch (e) { throw e; }
        }

        window.initReceiveBridge = function () {
            const walletList = document.getElementById('bridgeWalletList');
            const noWallet = document.getElementById('bridgeNoWallet');
            const walletPanel = document.getElementById('bridgeWalletPanel');
            const walletAddrEl = document.getElementById('bridgeConnectedAddress');
            const disconnectBtn = document.getElementById('bridgeDisconnectWallet');

            // Toast helpers
            const toastError = (msg) => {
                if (window.gToast && typeof window.gToast.error === 'function') { window.gToast.error(msg); } else { try { gToast.error(msg); } catch (_) { console.error(msg); } }
            };
            const toastSuccess = (msg) => {
                if (window.gToast && typeof window.gToast.success === 'function') { window.gToast.success(msg); } else { console.log(msg); }
            };

            const sourceChainSelect = document.getElementById('bridgeSourceChain');
            const assetSelect = document.getElementById('bridgeAsset');
            const amountInput = document.getElementById('bridgeAmount');
            const destinationInput = document.getElementById('bridgeDestination');
            const gasEl = document.getElementById('bridgeGasFee');
            const feeEl = document.getElementById('bridgeFee');
            const form = document.getElementById('bridgeForm');
            const checkBtn = document.getElementById('bridgeCheckStatusBtn');
            const statusBox = document.getElementById('bridgeStatusBox');
            const submitBtn = form ? form.querySelector('button[type="submit"]') : null;
            let feesReady = false;

            const setSubmitEnabled = (enabled) => {
                if (!submitBtn) return;
                submitBtn.disabled = !enabled;
                if (!enabled) {
                    submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                } else {
                    submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                }
            };

            if (!walletList || !sourceChainSelect || !assetSelect || !amountInput || !destinationInput || !form) return;

            showStep('wallet');

            bridgeController().then(async (BridgeClass) => {
                bridgeInstance = new BridgeClass({ environment: 'testnet' });

                // Populate wallet list (MetaMask/injected)
                walletList.innerHTML = '';
                const wallets = await bridgeInstance.detectWallets();
                if (!wallets || wallets.length === 0) {
                    noWallet?.classList.remove('hidden');
                } else {
                    wallets.forEach((w) => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'w-full px-4 py-3 rounded-xl border border-white/10 bg-black/60 hover:border-purple-500/60 hover:bg-purple-500/10 flex items-center gap-3 transition-colors clickable';
                        const name = w.info?.name || 'Wallet';
                        btn.innerHTML = '<div class="w-9 h-9 rounded-full bg-gradient-to-br from-purple-500 to-blue-500 flex items-center justify-center text-white text-sm font-bold">' + name.charAt(0) + '</div>' +
                            '<div class="flex-1 text-left"><div class="text-sm font-semibold text-white">' + name + '</div>' +
                            '<div class="text-[11px] text-gray-400">Browser Extension</div></div>' +
                            '<svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>';
                        btn.addEventListener('click', async () => {
                            try {
                                const wlt = await bridgeInstance.connectWallet(w.provider);
                                connectedWallet = wlt;
                                if (walletAddrEl) walletAddrEl.textContent = wlt.address.slice(0, 6) + '...' + wlt.address.slice(-4);
                                walletPanel?.classList.remove('hidden');
                                showStep('form');
                            } catch (e) {
                                toastError(e?.message || 'Failed to connect wallet');
                            }
                        });
                        walletList.appendChild(btn);
                    });
                }

                // Populate selectors
                const chains = bridgeInstance.getSupportedChains();
                sourceChainSelect.innerHTML = '<option value="">Select source chain</option>';
                chains.forEach((c) => {
                    if (c === 'moonbeam') return; // source only
                    const opt = document.createElement('option');
                    opt.value = c; opt.textContent = c.charAt(0).toUpperCase() + c.slice(1);
                    sourceChainSelect.appendChild(opt);
                });

                const populateTokens = () => {
                    const envTokens = bridgeInstance.getSupportedTokens();
                    assetSelect.innerHTML = '<option value="">Select asset</option>';
                    envTokens.forEach((t) => {
                        const opt = document.createElement('option');
                        opt.value = t; opt.textContent = t;
                        assetSelect.appendChild(opt);
                    });
                };
                populateTokens();
                // Initially disable submit until fees computed
                setSubmitEnabled(false);

                async function recalcFees() {
                    const chain = sourceChainSelect.value;
                    const token = assetSelect.value;
                    const amount = parseFloat(amountInput.value || '0');
                    // Invalidate fees state until successful calc
                    feesReady = false; setSubmitEnabled(false);
                    if (!chain || !token || !amount || amount <= 0) {
                        gasEl.textContent = '-';
                        feeEl.textContent = '-';
                        return;
                    }
                    try {
                        setEnvForToken(bridgeInstance, token);
                        const fees = await bridgeInstance.calculateBridgeFees(chain, amount, token);
                        const { gasText, bridgeText } = formatFeeDisplay(fees);
                        gasEl.textContent = gasText;
                        feeEl.textContent = bridgeText;
                        feesReady = true; setSubmitEnabled(true);
                    } catch (err) {
                        gasEl.textContent = '-';
                        feeEl.textContent = '-';
                        console.error('Fee calc failed:', err);
                        feesReady = false; setSubmitEnabled(false);
                    }
                }

                sourceChainSelect.addEventListener('change', recalcFees);
                assetSelect.addEventListener('change', async () => {
                    const desired = assetSelect.value;
                    setEnvForToken(bridgeInstance, desired);
                    const before = [...assetSelect.options].map(o => o.value).join('|');
                    populateTokens();
                    const after = [...assetSelect.options].map(o => o.value).join('|');
                    if ([...assetSelect.options].some(o => o.value === desired)) {
                        assetSelect.value = desired;
                    }
                    else {
                        assetSelect.value = '';
                        feeEl.textContent = '-';
                        gasEl.textContent = '-';
                        feesReady = false; setSubmitEnabled(false);
                    }
                    // Only recalc if selection remained
                    if (assetSelect.value) recalcFees();
                });
                amountInput.addEventListener('input', recalcFees);

                disconnectBtn?.addEventListener('click', () => {
                    bridgeInstance.disconnect();
                    connectedWallet = null;
                    walletPanel?.classList.add('hidden');
                    showStep('wallet');
                });

                form.addEventListener('submit', async (e) => {
                    e.preventDefault();
                    if (!connectedWallet) { toastError('Connect your wallet first'); return; }
                    const chain = sourceChainSelect.value;
                    const token = assetSelect.value;
                    const amount = parseFloat(amountInput.value || '0');
                    const destination = (destinationInput.value || '').trim();
                    if (!chain || !token || !amount || amount <= 0 || !destination) { toastError('Fill all fields'); return; }
                    if (!(ethers.utils?.isAddress ? ethers.utils.isAddress(destination) : (ethers.isAddress && ethers.isAddress(destination)))) { toastError('Invalid destination'); return; }
                    if (!feesReady) { toastError('Please calculate fees before continuing'); return; }

                    try {
                        setEnvForToken(bridgeInstance, token);
                        await ensureNetwork(bridgeInstance, chain);
                    } catch (e2) {
                        toastError(e2?.message || 'Please switch network in your wallet');
                        return;
                    }

                    showStep('processing');
                    updateStepStatus('bridgeStepApproval', 'processing');
                    updateStepStatus('bridgeStepGas', 'idle');
                    updateStepStatus('bridgeStepBridge', 'idle');

                    try {
                        // Create a custom bridge execution with stage tracking
                        const result = await (async () => {
                            const bridgeExecution = bridgeInstance.executeBridge(chain, destination, amount, token);
                            
                            // Hook into bridge execution to track stages
                            // Since executeBridge returns transactions object, we need to track it differently
                            const originalThen = bridgeExecution.then.bind(bridgeExecution);
                            let stageTracker = null;
                            
                            // Start monitoring console logs for stage completion using element IDs
                            const originalConsoleLog = console.log;
                            const txHashes = { approve: null, gasPayment: null, bridge: null };
                            
                            console.log = function(...args) {
                                const msg = args.join(' ');
                                
                                // Stage 1: Approval TX detected
                                if (msg.includes('Approve TX:')) {
                                    const hash = args[1];
                                    txHashes.approve = hash;
                                    const explorerUrl = bridgeInstance.getExplorerUrl(chain, hash);
                                    setTimeout(() => {
                                        updateStepStatus('bridgeStepApproval', 'processing');
                                    }, 100);
                                }
                                // Stage 1 complete: Token approved
                                else if (msg.includes('✓ Token approved')) {
                                    const explorerUrl = bridgeInstance.getExplorerUrl(chain, txHashes.approve);
                                    setTimeout(() => {
                                        updateStepStatus('bridgeStepApproval', 'complete', explorerUrl);
                                    }, 100);
                                }
                                // Detect which path and move to appropriate next stage
                                else if (msg.includes('Step 2/2:') || msg.includes('Bridging tokens with sendToken')) {
                                    // Simple path - skip stage 2 and move directly to bridge stage
                                    setTimeout(() => {
                                        updateStepStatus('bridgeStepGas', 'idle'); // Hide or mark stage 2 as idle
                                        updateStepStatus('bridgeStepBridge', 'processing');
                                    }, 100);
                                }
                                else if (msg.includes('Step 2/3:') || msg.includes('Paying gas to Axelar')) {
                                    // Complex path - move to gas payment stage
                                    setTimeout(() => {
                                        updateStepStatus('bridgeStepGas', 'processing');
                                    }, 100);
                                }
                                // Stage 2: Gas payment TX detected (only for complex path)
                                else if (msg.includes('Gas payment TX:')) {
                                    const hash = args[1];
                                    txHashes.gasPayment = hash;
                                    const explorerUrl = bridgeInstance.getExplorerUrl(chain, hash);
                                }
                                // Stage 2 complete: Gas paid
                                else if (msg.includes('✓ Gas paid')) {
                                    const explorerUrl = bridgeInstance.getExplorerUrl(chain, txHashes.gasPayment);
                                    setTimeout(() => {
                                        updateStepStatus('bridgeStepGas', 'complete', explorerUrl);
                                    }, 100);
                                }
                                // Move to bridge stage after gas payment
                                else if (msg.includes('Step 3/3:') || msg.includes('Bridging tokens (contract call)')) {
                                    setTimeout(() => {
                                        updateStepStatus('bridgeStepBridge', 'processing');
                                    }, 100);
                                }
                                // Bridge TX detected
                                else if (msg.includes('Bridge TX:')) {
                                    const hash = args[1];
                                    txHashes.bridge = hash;
                                }
                                // Final stage complete: Bridge confirmed
                                else if (msg.includes('✓ Bridge transaction confirmed')) {
                                    const explorerUrl = bridgeInstance.getExplorerUrl(chain, txHashes.bridge);
                                    setTimeout(() => {
                                        updateStepStatus('bridgeStepBridge', 'complete', explorerUrl);
                                    }, 100);
                                }
                                
                                return originalConsoleLog.apply(console, args);
                            };
                            
                            try {
                                const result = await bridgeExecution;
                                console.log = originalConsoleLog;
                                return result;
                            } catch (error) {
                                console.log = originalConsoleLog;
                                throw error;
                            }
                        })();

                        toastSuccess('Bridge transaction submitted');

                        // Show bridge data summary
                        setTimeout(() => {
                            if (statusBox) {
                                statusBox.classList.remove('hidden');
                                const scan = `https://${bridgeInstance.config.environment === 'testnet' ? 'testnet.' : ''}axelarscan.io/transfer/${result.mainTxHash}`;
                                statusBox.innerHTML = `<div class="space-y-2">
                                    <div class="text-emerald-400 font-semibold">Bridge Initiated Successfully</div>
                                    <div class="text-gray-300">From: ${chain.charAt(0).toUpperCase() + chain.slice(1)} → Moonbeam</div>
                                    <div class="text-gray-300">Amount: ${amount} ${token}</div>
                                    <div class="text-gray-400 text-xs">Transaction Hash: ${result.mainTxHash.slice(0, 10)}...${result.mainTxHash.slice(-8)}</div>
                                    <a class="text-purple-300 hover:text-purple-200 text-xs" href="${scan}" target="_blank">View on AxelarScan</a>
                                    <div class="text-gray-400 text-xs">Estimated arrival: 2-5 minutes</div>
                                    <div id="checkStatusBtnContainer"></div>
                                </div>`;
                            }
                        }, 1000);

                        // Enable status checker after all stages complete
                        setTimeout(() => {
                            if (checkBtn && statusBox) {
                                checkBtn.classList.remove('hidden');
                                checkBtn.onclick = async () => {
                                    try {
                                        statusBox.classList.remove('hidden');
                                        statusBox.innerHTML = '<div class="text-gray-300">Checking status...</div>';
                                        const status = await bridgeInstance.getTransactionStatus(result.mainTxHash, result.sourceChain);
                                        const ok = status.executed;
                                        const cls = ok ? 'text-emerald-400' : (status.error ? 'text-red-400' : 'text-yellow-400');
                                        const scan = `https://${bridgeInstance.config.environment === 'testnet' ? 'testnet.' : ''}axelarscan.io/transfer/${result.mainTxHash}`;
                                        statusBox.innerHTML = `<div class="space-y-2">
                                            <div class="${cls} font-semibold">${ok ? 'Completed' : (status.error ? 'Failed' : 'Pending')}</div>
                                            <div class="text-gray-300">From: ${status.sourceChain} → ${status.destinationChain}</div>
                                            <div class="text-gray-300">Amount: ${status.amount} ${status.token}</div>
                                            <a class="text-purple-300 hover:text-purple-200" href="${scan}" target="_blank">View on AxelarScan</a>
                                            ${status.error ? `<div class="text-red-400 text-sm">${status.error}</div>` : ''}
                                            <div id="checkStatusBtnContainer"></div>
                                        </div>`;
                                        // Re-append button below status
                                        const btnContainer2 = document.getElementById('checkStatusBtnContainer');
                                        if (btnContainer2) {
                                            btnContainer2.innerHTML = '';
                                            btnContainer2.appendChild(checkBtn);
                                        }
                                    } catch (er3) {
                                        statusBox.classList.remove('hidden');
                                        statusBox.innerHTML = `<div class="text-red-400">Failed to fetch status: ${er3.message}</div>`;
                                    }
                                };
                            }
                        }, 1500);
                    } catch (err) {
                        updateStepStatus('bridgeStepApproval', 'idle');
                        updateStepStatus('bridgeStepGas', 'idle');
                        updateStepStatus('bridgeStepBridge', 'idle');
                        // User cancellation handling
                        const msg = (err && (err.message || err.reason)) || 'Bridge failed';
                        if (err && (err.code === 4001 || /user rejected|user denied|cancel/i.test(String(err.message || err.reason)))) {
                            toastError('Signing cancelled');
                            showStep('form');
                        } else {
                            toastError(msg);
                            showStep('form');
                        }
                    }
                });
            }).catch((err) => {
                console.error('Bridge load failed:', err);
                noWallet?.classList.remove('hidden');
            });
        };
    })();

    // Initialize Receive Modal Tabs
    function initReceiveTabs() {
        // Initialize tab controller
        const receiveTabController = window.initTabController({
            tabSelector: '.receive-tab',
            contentSelector: '.receive-tab-content',
            onTabChange: function (tabName, content) {
                console.log('Switched to tab:', tabName);
            }
        });

        // Copy Account Details functionality
        const copyAccountBtn = document.getElementById('copyAccountDetails');
        if (copyAccountBtn) {
            copyAccountBtn.addEventListener('click', function () {
                const accountName = document.getElementById('directAccountName').textContent;
                const accountNumber = document.getElementById('directAccountNumber').textContent;
                const accountType = document.getElementById('directAccountType').textContent;

                const details = `Account Name: ${accountName}\nAccount Number: ${accountNumber}\nAccount Type: ${accountType}`;

                navigator.clipboard.writeText(details).then(() => {
                    const originalText = this.innerHTML;
                    this.innerHTML = '<svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg> Copied!';
                    this.classList.add('copy-success', 'text-green-400');

                    setTimeout(() => {
                        this.innerHTML = originalText;
                        this.classList.remove('copy-success', 'text-green-400');
                        this.classList.add('text-purple-400');
                    }, 2000);
                });
            });
        }

        // Helper function to format numbers with thousand separators
        function formatMoney(value, decimals = 2) {
            const num = parseFloat(value);
            if (isNaN(num)) return '$0.00';
            return '$' + num.toFixed(decimals).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        }

        $(document).on("keyup", "#bankAmount", general.delay(function (e) {

            var amount = $('#bankAmount').val();
            var currency = $('#bankCurrency').val();
            var button = $('.transferRateLoader').html();

            var formData = new FormData();
            formData.append('fingerprint', radar._Tracker().deviceFingerPrint);
            formData.append('request', 'getExchangeRate');
            formData.append('currency', currency);

            formData.append('csrf_name', document.querySelector('input[name="csrf_name"]')?.value || '');
            formData.append('csrf_value', document.querySelector('input[name="csrf_value"]')?.value || '');

            general.ajaxFormData('.transferRateLoader', 'POST', BASE + 'src/Processor/Manager/zorah.php', formData, '.transferRateLoader', button, function (data) {
                general.delay(function (e) { }, 3000);
                const totalValue = amount / data.rate;
                document.getElementById('currentRate').textContent = formatMoney(data.rate);
                document.getElementById('bankNetAmount').textContent = formatMoney(totalValue);
            }, 'pageLoader');
        }, 2000));

        // P2P Transfer Handler
        $(document).on("submit", "#bankTransferForm", function (e) {
            e.preventDefault();
            var formData = new FormData(this);
            var button = $('#submitBankTransfer').html();
            formData.append('request', 'generateP2PTransfer');
            formData.append('fingerprint', radar._Tracker().deviceFingerPrint);

            const activeAccount = $('.currentAccount').attr('account');
            formData.append('account_id', activeAccount);
            formData.append('csrf_name', document.querySelector('input[name="csrf_name"]')?.value || '');
            formData.append('csrf_value', document.querySelector('input[name="csrf_value"]')?.value || '');

            general.ajaxFormData('#bankTransferForm', 'POST', BASE + 'src/Processor/Manager/zorah.php', formData, '#submitBankTransfer', button, function (data) {

                $('#bankTransferForm').addClass('hidden');
                $('#bankTransferFrom').append(data.model);

                // Populate P2P card with data
                $('#p2pAmount').text(data.amount);
                $('#p2pCurrency').text(data.currency || 'USD');
                $('#p2pBankName').text(data.bank_name || '-');
                $('#p2pAccountName').text(data.account_name || '-');
                $('#p2pAccountNumber').text(data.account_number || '-');

                // Store transfer ID for later use
                $('#p2pTransferCard').attr('data-transfer-id', data.escrow_id);

                // Start countdown timer (1 hour = 3600 seconds)
                const expiryTime = data.expiry_time || 3600;
                startP2PCountdown(expiryTime, data.escrow_id);

            }, 'centerLoader');
        });

        // P2P Countdown Timer Function
        let p2pCountdownInterval = null;

        function startP2PCountdown(seconds, transferId) {
            // Clear any existing interval
            if (p2pCountdownInterval) {
                clearInterval(p2pCountdownInterval);
            }

            let remainingSeconds = seconds;
            const countdownElement = document.getElementById('p2pCountdown');

            function updateCountdown() {
                const hours = Math.floor(remainingSeconds / 3600);
                const minutes = Math.floor((remainingSeconds % 3600) / 60);
                const secs = remainingSeconds % 60;

                const timeString = `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
                countdownElement.textContent = timeString;

                if (remainingSeconds <= 300) { // 5 minutes
                    countdownElement.classList.add('time-critical');
                    countdownElement.classList.remove('time-warning');
                } else if (remainingSeconds <= 600) { // 10 minutes
                    countdownElement.classList.add('time-warning');
                    countdownElement.classList.remove('time-critical');
                } else {
                    countdownElement.classList.remove('time-warning', 'time-critical');
                }

                if (remainingSeconds <= 0) {
                    clearInterval(p2pCountdownInterval);
                    countdownElement.textContent = '00:00:00';
                    countdownElement.classList.add('time-critical');

                    $('#confirmP2PPayment').prop('disabled', true).addClass('opacity-50 cursor-not-allowed');

                    gToast.warning('Transfer time expired. Cancelling payment...');

                    resetP2PForm();
                    notifyTransferExpired(transferId);

                    return;
                }

                remainingSeconds--;
            }

            // Update immediately
            updateCountdown();

            // Then update every second
            p2pCountdownInterval = setInterval(updateCountdown, 1000);
        }

        // Notify server when transfer expires
        function notifyTransferExpired(transferId) {

            var button = $('#bankTransferForm').html();
            var formData = new FormData();
            formData.append('request', 'p2pTransferExpired');
            formData.append('escrow_id', transferId);
            formData.append('fingerprint', radar._Tracker().deviceFingerPrint);

            const activeAccount = $('.currentAccount').attr('account');
            formData.append('account_id', activeAccount);
            formData.append('csrf_name', document.querySelector('input[name="csrf_name"]')?.value || '');
            formData.append('csrf_value', document.querySelector('input[name="csrf_value"]')?.value || '');

            general.ajaxFormData('#bankTransferForm', 'POST', BASE + 'src/Processor/Manager/zorah.php', formData, '#bankTransferForm', button, function (data) {
                gToast.success('Payment has been cancelled. Please start another session.')
            }, 'pageLoader');
        }

        // Copy functionality for bank details
        $(document).on('click', '.copy-btn', function () {
            const targetId = $(this).data('copy');
            const textToCopy = $('#' + targetId).text();
            const btn = $(this);

            if (navigator.clipboard && textToCopy && textToCopy !== '-') {
                navigator.clipboard.writeText(textToCopy).then(() => {
                    // Visual feedback
                    btn.addClass('copied');
                    const originalSvg = btn.html();
                    btn.html(`<svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>`);

                    setTimeout(() => {
                        btn.removeClass('copied');
                        btn.html(originalSvg);
                    }, 2000);
                });
            }
        });

        // Cancel P2P Transfer
        $(document).on('click', '#cancelP2PTransfer', async function () {
            const confirmed = await confirmController({
                title: 'Cancel Payment?',
                message: 'This action cannot be undone. Are you sure?',
                yesText: 'Yes, Cancel',
                noText: 'No, Keep It'
            });

            if (confirmed) {
                const transferId = $('#p2pTransferCard').attr('data-transfer-id');

                var formData = new FormData();
                formData.append('request', 'cancelP2PTransfer');
                formData.append('escrow_id', transferId);
                formData.append('fingerprint', radar._Tracker().deviceFingerPrint);
                var button = $(this).html();

                const activeAccount = $('.currentAccount').attr('account');
                formData.append('account_id', activeAccount);
                formData.append('csrf_name', document.querySelector('input[name="csrf_name"]')?.value || '');
                formData.append('csrf_value', document.querySelector('input[name="csrf_value"]')?.value || '');

                general.ajaxFormData('#cancelP2PTransfer', 'POST', BASE + 'src/Processor/Manager/zorah.php', formData, '#cancelP2PTransfer', button, function (data) {
                    if (data.success) {
                        // Clear countdown
                        if (p2pCountdownInterval) {
                            clearInterval(p2pCountdownInterval);
                        }
                        resetP2PForm();
                        gToast.success('Transfer cancelled successfully.');
                    } else {
                        gToast.error(data.error || 'Failed to cancel transfer.');
                    }
                }, 'centerLoader');
            }
        });

        // Confirm P2P Payment
        $(document).on('click', '#confirmP2PPayment', function () {
            const transferId = $('#p2pTransferCard').attr('data-transfer-id');

            var formData = new FormData();
            formData.append('request', 'confirmP2PPayment');
            formData.append('escrow_id', transferId);
            formData.append('fingerprint', radar._Tracker().deviceFingerPrint);
            var button = $(this).html();

            const activeAccount = $('.currentAccount').attr('account');
            formData.append('account_id', activeAccount);
            formData.append('csrf_name', document.querySelector('input[name="csrf_name"]')?.value || '');
            formData.append('csrf_value', document.querySelector('input[name="csrf_value"]')?.value || '');

            general.ajaxFormData('#confirmP2PPayment', 'POST', BASE + 'src/Processor/Manager/zorah.php', formData, '#confirmP2PPayment', button, function (data) {
                if (data.success) {
                    // Clear countdown
                    if (p2pCountdownInterval) {
                        clearInterval(p2pCountdownInterval);
                    }
                    $('#p2pTransferCard').addClass('hidden');
                    $('#bankTransferForm').append(data.modal);
                    $('#bankTransferForm')[0].reset();

                    // Start efficient polling for payment confirmation status
                    const escrowId = data.escrowId;
                    const activeAccount = $('.currentAccount').attr('account');

                    const paymentPoller = createServerPoller({
                        id: escrowId,
                        requestType: 'checkEscrowPaymentStatus',
                        initialInterval: 5000,      // Start checking every 5 seconds
                        maxInterval: 20000,         // Max interval of 20 seconds
                        maxAttempts: 180,           // ~30 minutes maximum
                        backoffMultiplier: 1.15,    // Gradual increase
                        additionalData: {
                            account_id: activeAccount
                        },
                        onUpdate: function (statusData, stopPolling) {
                            stopPolling();
                            // Show success notification
                            gToast.success('Payment confirmed! Funds are being processed.');
                            $('.p2pStatusUpdate').html(statusData.modal);
                        },
                        onError: function (errorData) {
                            gToast.error('Unable to check payment status. Please refresh the page.');
                        },
                        button: null,
                        main_selector: null,
                        button_selector: null
                    });

                    // Store poller reference for cleanup if needed
                    window.currentPaymentPoller = paymentPoller;


                } else {
                    gToast.error(data.message || 'Failed to confirm payment.');
                }
            }, 'centerLoader');
        });

        // Reset P2P form
        function resetP2PForm() {
            $('#p2pTransferCard').addClass('hidden');
            $('#bankTransferForm').removeClass('hidden');
            $('#bankTransferForm')[0].reset();
            $('#confirmP2PPayment').prop('disabled', false).removeClass('opacity-50 cursor-not-allowed');
        }

        $(document).on("submit", "#bankTransferForm_OLD", function (e) {
            e.preventDefault();
            var formData = new FormData(this);
            var button = $('#submitBankTransfer').html();
            formData.append('request', 'receiveTransfer');
            formData.append('fingerprint', radar._Tracker().deviceFingerPrint);

            general.ajaxFormData('#bankTransferForm', 'POST', BASE + 'src/Processor/Manager/zorah.php', formData, '#submitBankTransfer', button, function (data) {
                if (data.modalId) {
                    $('#modalScreen').html(data.modal);
                    getAllSupportedCurrency(function (supportedCurrencies) {
                        // Populate all currency select dropdowns
                        if (supportedCurrencies && Array.isArray(supportedCurrencies)) {
                            const selects = document.querySelectorAll('.virtual-select');
                            selects.forEach(select => {
                                select.innerHTML = '';
                                supportedCurrencies.forEach(currency => {
                                    if (!currency || currency.trim() === '') {
                                        return;
                                    }
                                    const option = document.createElement('option');
                                    option.value = currency;
                                    option.textContent = currency;
                                    select.appendChild(option);
                                });
                                const usdOption = Array.from(select.options).find(opt => opt.value === 'USD');
                                if (usdOption) {
                                    select.value = 'USD';
                                }
                            });
                        }
                    });

                    modalController(data.modalId, { bgClose: false, keyboard: false })
                        .then(modal => {
                            modal.show();
                            modal.setSize('full');
                            try {
                                const modalRoot = document.getElementById(data.modalId) || document;
                                if (window.initAllWizards) {
                                    window.initAllWizards(modalRoot);
                                } else if (window.initStepWizard) {
                                    modalRoot.querySelectorAll('form[data-step-wizard], form#createAccountForm').forEach(f => window.initStepWizard(f));
                                }
                            } catch (err) {
                                console.error('Wizard initialization failed:', err);
                            }
                        });
                    return;
                }
                $('.main-dashboard').html(data.interface);
            }, 'centerLoader');
        });

    }



});
if (document.getElementsByClassName('MAnchors_').length) {
    let clearAnalyticsInterval = setInterval(function () {
        const anchorKey = storage.get('anchorKey');
        if (anchorKey != 'undefined' && anchorKey !== '') {
            clearInterval(clearAnalyticsInterval);

            var tracking = document.getElementsByClassName('MAnchors_');
            var anchor = new Anchor;
            anchor.endpoint = BASE + 'src/Addons/anchor.php';
            anchor.token = JSON.stringify({ token: anchorKey, 'fingerprint': radar._Tracker().deviceFingerPrint });
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