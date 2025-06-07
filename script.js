'use strict';

// --- Configuration ---
const POINTS_PER_INTERVAL = 2;
const INTERVAL_SECONDS = 10;
const REWARDED_AD_POINTS_BONUS = 10; // Points for watching a rewarded ad

// --- Telegram WebApp Initialization ---
const tg = window.Telegram.WebApp;
tg.ready(); // Inform Telegram the app is ready
tg.expand(); // Expand the app to full height

// --- DOM Elements ---
const pointsDisplay = document.getElementById('points');
const rewardedAdButton = document.getElementById('rewardedAdButton');
const countdownDisplay = document.getElementById('countdown');

// --- State ---
let currentPoints = 0;
let countdown = INTERVAL_SECONDS;

// --- Functions ---

function loadPoints() {
    const savedPoints = localStorage.getItem('adAppPoints');
    if (savedPoints !== null) {
        currentPoints = parseInt(savedPoints, 10);
    }
    updatePointsDisplay();
}

function savePoints() {
    localStorage.setItem('adAppPoints', currentPoints.toString());
}

function updatePointsDisplay() {
    pointsDisplay.textContent = currentPoints;
}

function updateCountdownDisplay() {
    countdownDisplay.textContent = countdown;
}

function grantPassivePoints() {
    currentPoints += POINTS_PER_INTERVAL;
    updatePointsDisplay();
    savePoints();
    console.log(`Awarded ${POINTS_PER_INTERVAL} points. Total: ${currentPoints}`);
    tg.HapticFeedback.notificationOccurred('success');
}

function handleRewardedAd() {
    // Check if the Monetag rewarded function exists
    if (typeof show_9342950 === 'function') {
        console.log('Requesting rewarded interstitial ad...');
        tg.HapticFeedback.impactOccurred('light');
        // Disable button to prevent multiple clicks while ad is loading/showing
        rewardedAdButton.disabled = true;
        rewardedAdButton.textContent = 'Loading Ad...';

        show_9342950()
            .then(() => {
                // This block executes after the user watches the ad
                console.log('Rewarded ad watched successfully.');
                currentPoints += REWARDED_AD_POINTS_BONUS;
                updatePointsDisplay();
                savePoints();
                alert(`You earned ${REWARDED_AD_POINTS_BONUS} bonus points for watching the ad!`);
                tg.HapticFeedback.notificationOccurred('success');
            })
            .catch((error) => {
                // This block executes if the ad fails to load or is closed prematurely
                console.error('Rewarded ad error or not completed:', error);
                alert('Ad not completed or an error occurred. No bonus points awarded.');
                tg.HapticFeedback.notificationOccurred('error');
            })
            .finally(() => {
                // Re-enable the button
                rewardedAdButton.disabled = false;
                rewardedAdButton.textContent = 'Watch Rewarded Ad (Bonus!)';
            });
    } else {
        console.error('Monetag rewarded ad function show_9342950 is not defined. Ensure Monetag SDK is loaded correctly.');
        alert('Could not initiate rewarded ad. Monetag SDK might not be loaded.');
    }
}

// --- Main Logic ---

// Load initial points
loadPoints();

// Set up the interval for passive point generation
setInterval(() => {
    countdown--;
    if (countdown <= 0) {
        grantPassivePoints();
        countdown = INTERVAL_SECONDS; // Reset countdown
    }
    updateCountdownDisplay();
}, 1000); // Update countdown every second

// Initial countdown display
updateCountdownDisplay();

// Event listener for the rewarded ad button
if (rewardedAdButton) {
    rewardedAdButton.addEventListener('click', handleRewardedAd);
}

// Optional: Inform user about app backgrounding/closing
// tg.onEvent('viewportChanged', (event) => {
//     if (!event.isStateStable) {
//         // App is likely being closed or backgrounded, maybe save state if needed
//         console.log('Viewport unstable, potentially closing.');
//     }
// });

console.log('Ad Rewards App Initialized.');
// For debugging, you can see the Telegram WebApp object
// console.log(tg);