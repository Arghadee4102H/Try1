document.addEventListener('DOMContentLoaded', () => {
    // Page elements
    const loadingOverlay = document.getElementById('loading-overlay');
    const registrationPage = document.getElementById('registration-page');
    const loginPage = document.getElementById('login-page');
    const mainApp = document.getElementById('main-app');

    // Auth form elements
    const regUsernameInput = document.getElementById('reg-username');
    const regEmailInput = document.getElementById('reg-email');
    const regPasswordInput = document.getElementById('reg-password');
    const regConfirmPasswordInput = document.getElementById('reg-confirm-password');
    const registerBtn = document.getElementById('register-btn');
    const regMessage = document.getElementById('reg-message');

    const loginEmailInput = document.getElementById('login-email');
    const loginPasswordInput = document.getElementById('login-password');
    const loginBtn = document.getElementById('login-btn');
    const loginMessage = document.getElementById('login-message');

    const showLoginLink = document.getElementById('show-login-link');
    const showRegisterLink = document.getElementById('show-register-link');

    // Nav elements
    const navButtons = document.querySelectorAll('.nav-btn');
    const pageSections = document.querySelectorAll('.page-section');

    // Header elements
    const headerUsername = document.getElementById('header-username');
    const headerPoints = document.getElementById('header-points');

    // Profile elements
    const profileUsername = document.getElementById('profile-username');
    const profileEmail = document.getElementById('profile-email');
    const profilePoints = document.getElementById('profile-points');
    const profileReferralCode = document.getElementById('profile-referral-code');
    const profileTotalReferrals = document.getElementById('profile-total-referrals');
    const profileLastReset = document.getElementById('profile-last-reset');

    // Spin elements
    const spinWheel = document.getElementById('spin-wheel');
    const spinBtn = document.getElementById('spin-btn');
    const getSpinsAdBtn = document.getElementById('get-spins-ad-btn');
    const freeSpinsLeft = document.getElementById('free-spins-left');
    const spinAdsWatchedToday = document.getElementById('spin-ads-watched-today');
    const maxSpinAdsToday = document.getElementById('max-spin-ads-today');
    const spinResult = document.getElementById('spin-result');

    // Task elements
    const taskList = document.getElementById('task-list');
    const taskMessage = document.getElementById('task-message');

    // Ads elements (for points)
    const watchAdBtn = document.getElementById('watch-ad-btn');
    const adsWatchedCount = document.getElementById('ads-watched-count');
    const maxAdsLimit = document.getElementById('max-ads-limit');
    const adMessage = document.getElementById('ad-message');

    // Referral elements
    const userReferralCode = document.getElementById('user-referral-code');
    const submitReferralCodeInput = document.getElementById('submit-referral-code-input');
    const submitReferralBtn = document.getElementById('submit-referral-btn');
    const referralSubmitMessage = document.getElementById('referral-submit-message');

    // Withdraw elements
    const withdrawCurrentPoints = document.getElementById('withdraw-current-points');
    const withdrawalAmountSelect = document.getElementById('withdrawal-amount');
    const withdrawalMethodSelect = document.getElementById('withdrawal-method');
    const withdrawalDetailsInput = document.getElementById('withdrawal-details');
    const withdrawalDetailsLabel = document.getElementById('withdrawal-details-label');
    const requestWithdrawalBtn = document.getElementById('request-withdrawal-btn');
    const withdrawMessage = document.getElementById('withdraw-message');
    const withdrawForm = document.getElementById('withdraw-form');

    let currentUserData = null;
    const API_URL = 'api.php';
    // MONETAG_ZONE_ID is not directly used in the function call 'show_9342950'
    // but it's good to have if you need to reference it elsewhere.
    // The function 'show_9342950' is specific to your zone.
    const MONETAG_ZONE_ID = '9342950';

    // --- Helper Functions ---
    function showLoading() { loadingOverlay.classList.remove('hidden'); }
    function hideLoading() { loadingOverlay.classList.add('hidden'); }

    function showMessage(element, messageText, isSuccess = true) {
        if (!element) {
            console.warn("showMessage: Target element not found for message:", messageText);
            alert((isSuccess ? "Success: " : "Error: ") + messageText);
            return;
        }
        element.textContent = messageText;
        element.className = 'message ' + (isSuccess ? 'success' : 'error');
        if (isSuccess && messageText) {
            setTimeout(() => {
                if (element.textContent === messageText) {
                    element.textContent = '';
                    element.className = 'message';
                }
            }, 4000);
        }
    }

    async function apiCall(action, data = {}) {
        showLoading();
        let rawResponseForDebug = '';
        try {
            const response = await fetch(API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ action, ...data })
            });
            rawResponseForDebug = await response.text();
            const result = JSON.parse(rawResponseForDebug);

            if (!result.success && result.debug_error) console.error("PHP Error from Server:", result.debug_error);
            if (!result.success && result.debug_exception) console.error("PHP Exception from Server:", result.debug_exception);

            if (result.redirectToLogin) {
                showAuthPage('login');
                showMessage(loginMessage, result.message || "Session expired. Please login.", false);
                return null;
            }
            return result;
        } catch (error) {
            console.error('API Call Error for action "' + action + '":', error);
            console.error('Raw response was:', rawResponseForDebug);
            let errorMsg = 'Network error or server issue. Please try again.';
            if (rawResponseForDebug.includes("Database connection failed")) {
                errorMsg = "Database connection error. Please check server configuration.";
            } else if (rawResponseForDebug.toLowerCase().includes("<html")) {
                errorMsg = "Server returned an unexpected response (HTML). Check console for details.";
            }
            
            const activeAuthPage = registrationPage.classList.contains('hidden') ? loginPage : registrationPage;
            const authMessageElement = registrationPage.classList.contains('hidden') ? loginMessage : regMessage;
            if (!activeAuthPage.classList.contains('hidden')) {
                showMessage(authMessageElement, errorMsg, false);
            } else {
                 const currentSection = document.querySelector('.page-section:not(.hidden)');
                 const sectionMsgEl = currentSection ? currentSection.querySelector('.message') : null;
                 if(sectionMsgEl) showMessage(sectionMsgEl, errorMsg, false); else alert("Error: " + errorMsg);
            }
            return { success: false, message: errorMsg, rawError: error.toString() };
        } finally {
            hideLoading();
        }
    }

    function showAuthPage(page) {
        mainApp.classList.add('hidden');
        loginMessage.textContent = ''; regMessage.textContent = '';
        if (page === 'login') {
            registrationPage.classList.add('hidden'); loginPage.classList.remove('hidden');
        } else {
            loginPage.classList.add('hidden'); registrationPage.classList.remove('hidden');
        }
    }

    function showMainApp() {
        registrationPage.classList.add('hidden'); loginPage.classList.add('hidden');
        mainApp.classList.remove('hidden');
        navigateToSection('profile-section');
    }

    function navigateToSection(sectionId) {
        pageSections.forEach(section => section.classList.add('hidden'));
        const targetSection = document.getElementById(sectionId);
        if (targetSection) {
            targetSection.classList.remove('hidden');
            const msgEl = targetSection.querySelector('.message');
            if(msgEl) msgEl.textContent = '';
        } else {
            console.error("NavigateToSection: Section not found:", sectionId, ". Defaulting to profile.");
            document.getElementById('profile-section').classList.remove('hidden');
            sectionId = 'profile-section';
        }
        navButtons.forEach(btn => btn.classList.remove('active'));
        const activeBtn = document.querySelector(`.nav-btn[data-section="${sectionId}"]`);
        if(activeBtn) activeBtn.classList.add('active');

        if (sectionId === 'task-section') loadTasks();
        if (sectionId === 'profile-section' && currentUserData) updateProfileUI(currentUserData);
        if (sectionId === 'withdraw-section' && currentUserData) updateWithdrawUI(currentUserData);
    }

    function updateUIWithUserData(userData) {
        if (!userData || typeof userData !== 'object') {
            console.error("updateUIWithUserData received invalid data:", userData);
            showAuthPage('login');
            showMessage(loginMessage, "Error loading user data. Please login again.", false);
            return;
        }
        currentUserData = userData;
        headerUsername.textContent = userData.username || 'N/A';
        headerPoints.textContent = userData.points !== undefined ? userData.points : '0';
        updateProfileUI(userData);
        freeSpinsLeft.textContent = userData.spins_left_today !== undefined ? userData.spins_left_today : '0';
        spinBtn.disabled = !(userData.spins_left_today > 0);
        spinAdsWatchedToday.textContent = userData.spin_ads_watched_today !== undefined ? userData.spin_ads_watched_today : '0';
        maxSpinAdsToday.textContent = 10;
        getSpinsAdBtn.disabled = !(userData.spin_ads_watched_today < 10);
        adsWatchedCount.textContent = userData.ads_watched_today !== undefined ? userData.ads_watched_today : '0';
        maxAdsLimit.textContent = 38;
        watchAdBtn.disabled = !(userData.ads_watched_today < 38);
        userReferralCode.textContent = userData.referral_code || 'N/A';
        updateWithdrawUI(userData);
    }

    function updateProfileUI(userData) {
        profileUsername.textContent = userData.username  || 'N/A';
        profileEmail.textContent = userData.email || 'N/A';
        profilePoints.textContent = userData.points !== undefined ? userData.points : '0';
        profileReferralCode.textContent = userData.referral_code || 'N/A';
        profileTotalReferrals.textContent = userData.total_referrals_made !== undefined ? userData.total_referrals_made : '0';
        profileLastReset.textContent = userData.last_activity_date || 'N/A (Login to refresh)';
    }
    
    function updateWithdrawUI(userData) {
        withdrawCurrentPoints.textContent = userData.points !== undefined ? userData.points : '0';
        const withdrawalOptionsConfig = [
            { points: 4600, label: '4,600 points ($0.05)' }, { points: 90000, label: '90,000 points ($6)' },
            { points: 170000, label: '170,000 points ($12)' }, { points: 305000, label: '305,000 points ($20)' },
        ];
        withdrawalAmountSelect.innerHTML = ''; 
        let hasAvailableOptions = false;
        withdrawalOptionsConfig.forEach(opt => {
            if (opt.points === 4600 && userData.used_4600_withdrawal) return; 
            const option = document.createElement('option');
            option.value = opt.points; option.textContent = opt.label;
            if (userData.points < opt.points) { option.disabled = true; option.textContent += ' (Insufficient)'; } 
            else { hasAvailableOptions = true; }
            withdrawalAmountSelect.appendChild(option);
        });
        if (!hasAvailableOptions && withdrawalAmountSelect.options.length > 0) withdrawalAmountSelect.selectedIndex = 0;
        else if (withdrawalAmountSelect.options.length === 0) {
            const noOpt = document.createElement('option');
            noOpt.textContent = "No withdrawal options"; noOpt.disabled = true;
            withdrawalAmountSelect.appendChild(noOpt);
        }
        requestWithdrawalBtn.disabled = !hasAvailableOptions;
    }

    // Monetag Ad Function Wrapper
    function showMonetagRewardedAd() { // Removed zoneId param as it's not used in show_9342950
        return new Promise((resolve, reject) => {
            if (typeof window.show_9342950 !== 'function') {
                console.error("Monetag SDK function 'show_9342950' not found on window object.");
                alert("Ad system is not available. Please try again later.");
                return reject(new Error("Ad SDK function 'show_9342950' not available."));
            }
            console.log("Attempting to show Monetag Rewarded Ad (using 'pop' for Rewarded Popup)...");
            
            // --- USING 'pop' FOR REWARDED POPUP ---
            window.show_9342950('pop') 
                .then(() => {
                    console.log("Monetag Ad: Watched/Completed (Popup).");
                    resolve(); 
                })
                .catch((e) => {
                    console.error("Monetag Ad Error (Popup):", e);
                    let userMessage = 'Ad failed or was closed. No reward granted.';
                    // You could add more specific error mapping here if Monetag provides error codes/types in 'e'
                    reject(new Error(userMessage)); 
                });
        });
    }

    // --- Event Listeners ---
    showLoginLink.addEventListener('click', (e) => { e.preventDefault(); showAuthPage('login'); });
    showRegisterLink.addEventListener('click', (e) => { e.preventDefault(); showAuthPage('register'); });

    registerBtn.addEventListener('click', async () => {
        regMessage.textContent = '';
        const username = regUsernameInput.value.trim(); const email = regEmailInput.value.trim();
        const password = regPasswordInput.value; const confirmPassword = regConfirmPasswordInput.value;
        if (!username || !email || !password || !confirmPassword) return showMessage(regMessage, 'All fields are required.', false);
        if (password.length < 6) return showMessage(regMessage, 'Password min 6 characters.', false);
        if (password !== confirmPassword) return showMessage(regMessage, 'Passwords do not match.', false);
        if (!/^\S+@\S+\.\S+$/.test(email)) return showMessage(regMessage, 'Invalid email format.', false);
        const result = await apiCall('register', { username, email, password, confirm_password: confirmPassword });
        if (result) {
            showMessage(regMessage, result.message, result.success);
            if (result.success) {
                regUsernameInput.value = ''; regEmailInput.value = ''; regPasswordInput.value = ''; regConfirmPasswordInput.value = '';
                setTimeout(() => showAuthPage('login'), 1500);
            }
        }
    });

    loginBtn.addEventListener('click', async () => {
        loginMessage.textContent = '';
        const email = loginEmailInput.value.trim(); const password = loginPasswordInput.value;
        if (!email || !password) return showMessage(loginMessage, 'Email and password required.', false);
        const result = await apiCall('login', { email, password });
        if (result) {
            if (result.success && result.userData) { updateUIWithUserData(result.userData); showMainApp(); } 
            else { showMessage(loginMessage, result.message, false); }
        }
    });

    navButtons.forEach(button => button.addEventListener('click', () => navigateToSection(button.dataset.section)));

    spinBtn.addEventListener('click', async () => {
        spinResult.textContent = '';
        if (!currentUserData || currentUserData.spins_left_today <= 0) return showMessage(spinResult, "No spins left! Watch an ad for more.", false);
        const result = await apiCall('spin');
        if (result) {
            showMessage(spinResult, result.message, result.success);
            if (result.success && result.userData) {
                updateUIWithUserData(result.userData);
                spinWheel.style.transition = 'transform 1s cubic-bezier(0.25, 1, 0.5, 1)';
                spinWheel.style.transform = `rotate(${Math.floor(Math.random() * 4 + 3) * 360 + Math.floor(Math.random()*360)}deg)`;
                setTimeout(() => { spinWheel.style.transition = 'transform 0.1s ease-out'; }, 1000);
            }
        }
    });

    getSpinsAdBtn.addEventListener('click', async () => {
        spinResult.textContent = '';
        if (!currentUserData || currentUserData.spin_ads_watched_today >= 10) return showMessage(spinResult, "Max ads for spins watched today.", false);
        showMessage(spinResult, "Loading ad for 2 extra spins...", true);
        try {
            await showMonetagRewardedAd(); 
            showMessage(spinResult, 'Ad watched! Processing extra spins...', true);
            const result = await apiCall('getSpinsFromAd');
            if (result) {
                showMessage(spinResult, result.message, result.success); 
                if (result.success && result.userData) updateUIWithUserData(result.userData);
            }
        } catch (adError) {
            console.error('Ad process error (getSpinsAdBtn):', adError.message);
            showMessage(spinResult, adError.message, false);
        }
    });

    watchAdBtn.addEventListener('click', async () => {
        adMessage.textContent = '';
        if (!currentUserData || currentUserData.ads_watched_today >= 38) return showMessage(adMessage, "Max ads for points watched today.", false);
        showMessage(adMessage, "Loading ad for 20 points...", true);
        try {
            await showMonetagRewardedAd();
            showMessage(adMessage, 'Ad watched! Processing points...', true);
            const result = await apiCall('adWatched');
            if (result) {
                showMessage(adMessage, result.message, result.success);
                if (result.success && result.userData) updateUIWithUserData(result.userData);
            }
        } catch (adError) {
            console.error('Ad process error (watchAdBtn for points):', adError.message);
            showMessage(adMessage, adError.message, false);
        }
    });

    async function loadTasks() {
        taskMessage.textContent = '';
        const result = await apiCall('get_tasks');
        taskList.innerHTML = ''; 
        if (result && result.success && result.tasks) {
            if (result.tasks.length === 0) { taskList.innerHTML = '<p>No tasks available.</p>'; return; }
            result.tasks.forEach(task => {
                const taskItem = document.createElement('div'); taskItem.className = 'task-item';
                const buttonText = task.completed ? 'Completed' : (task.link.includes('x.com') || task.link.includes('twitter.com') ? 'Follow & Verify' : 'Join & Verify');
                taskItem.innerHTML = `<div class="task-item-info"><h4>${task.name}</h4><p>Reward: ${task.points} points</p></div><button data-task-id="${task.id}" data-task-link="${task.link}" class="complete-task-btn ${task.completed ? 'completed' : ''}" ${task.completed ? 'disabled' : ''}>${buttonText}</button>`;
                taskList.appendChild(taskItem);
            });
            document.querySelectorAll('.complete-task-btn:not(.completed)').forEach(button => {
                button.addEventListener('click', (e) => {
                    const targetButton = e.currentTarget; const taskId = targetButton.dataset.taskId; const taskLink = targetButton.dataset.taskLink;
                    window.open(taskLink, '_blank');
                    showMessage(taskMessage, "Complete task in new tab, then confirm.", true);
                    targetButton.disabled = true; targetButton.textContent = "Verifying...";
                    setTimeout(async () => {
                         if (confirm("Have you completed the task?")) {
                            const completeResult = await apiCall('completeTask', { taskId });
                            if (completeResult) {
                                showMessage(taskMessage, completeResult.message, completeResult.success);
                                if (completeResult.success) {
                                    if (completeResult.userData) updateUIWithUserData(completeResult.userData);
                                    loadTasks(); 
                                } else { targetButton.disabled = false; targetButton.textContent = (taskLink.includes('x.com') ? 'Follow & Verify' : 'Join & Verify');}
                            }
                        } else {
                            showMessage(taskMessage, "Task completion not confirmed.", false);
                            targetButton.disabled = false; targetButton.textContent = (taskLink.includes('x.com') ? 'Follow & Verify' : 'Join & Verify');
                        }
                    }, 5000);
                });
            });
        } else if (result) { taskList.innerHTML = `<p class="message error">${result.message || 'Could not load tasks.'}</p>`;}
    }

    submitReferralBtn.addEventListener('click', async () => {
        referralSubmitMessage.textContent = '';
        const code = submitReferralCodeInput.value.trim();
        if (!code) return showMessage(referralSubmitMessage, "Please enter a referral code.", false);
        const result = await apiCall('submitReferralCode', { referralCode: code });
        if (result) {
            showMessage(referralSubmitMessage, result.message, result.success);
            if (result.success) { if(result.userData) updateUIWithUserData(result.userData); submitReferralCodeInput.value = ''; }
        }
    });
    
    withdrawalMethodSelect.addEventListener('change', (e) => {
        const placeholderText = e.target.value === 'TON' ? 'Enter TON wallet address' : 'Enter Binance Pay ID';
        withdrawalDetailsLabel.textContent = e.target.value === 'TON' ? 'TON Wallet Address:' : 'Binance Pay ID:';
        withdrawalDetailsInput.placeholder = placeholderText;
    });
    if(withdrawForm) {
        withdrawForm.addEventListener('submit', async (e) => {
            e.preventDefault(); withdrawMessage.textContent = '';
            const points = parseInt(withdrawalAmountSelect.value); const method = withdrawalMethodSelect.value;
            const details = withdrawalDetailsInput.value.trim();
            if (isNaN(points) || points <= 0) return showMessage(withdrawMessage, 'Select valid amount.', false);
            if (!details) return showMessage(withdrawMessage, `Enter ${method} details.`, false);
            const result = await apiCall('requestWithdrawal', { points, method, details });
            if (result) {
                showMessage(withdrawMessage, result.message, result.success);
                if (result.success && result.userData) { updateUIWithUserData(result.userData); withdrawalDetailsInput.value = '';}
            }
        });
    } else { console.error("Withdraw form element not found!"); }

    async function initializeApp() {
        const sessionResult = await apiCall('check_session');
        if (sessionResult && sessionResult.isLoggedIn && sessionResult.userData) {
            updateUIWithUserData(sessionResult.userData); showMainApp();
        } else {
            showAuthPage('login');
            if (sessionResult && sessionResult.message && !sessionResult.isLoggedIn) {
                showMessage(loginMessage, sessionResult.message, false);
            }
        }
    }
    initializeApp();
});