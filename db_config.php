<?php
// db_config.php

define('DEBUG_MODE', 1); // 1 for development (shows detailed errors), 0 for production

if (DEBUG_MODE) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0); // Report no errors to screen in production
}

// --- IMPORTANT: REPLACE WITH YOUR ACTUAL INFINITYFREE CREDENTIALS ---
define('DB_SERVER', 'sqlXXX.infinityfree.com');   // Example: sql101.infinityfree.com
define('DB_USERNAME', 'if0_YOUR_USERNAME');      // Example: if0_12345678
define('DB_PASSWORD', 'YOUR_IF_PASSWORD');      // Your InfinityFree account password
define('DB_NAME', 'if0_YOUR_USERNAME_watchclickearn_db'); // Example: if0_12345678_watchclickearn_db
// --- END OF CRITICAL CREDENTIALS ---

$pdo = null; // Initialize $pdo

try {
    $pdo = new PDO("mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // $pdo->exec("SET NAMES 'utf8mb4'"); // charset in DSN is generally preferred
} catch(PDOException $e) {
    error_log("CRITICAL DB CONNECTION FAILED in db_config.php: " . $e->getMessage() . " (Host: " . DB_SERVER . ")");
    // This will be caught by api.php's shutdown function
    trigger_error("Database connection failed using provided credentials. Please check configuration. DB Error: " . $e->getMessage(), E_USER_ERROR);
    exit; // Stop script execution here if DB connection fails.
}


// Constants for the app
define('MAX_FREE_SPINS_PER_DAY', 20);
define('MAX_SPIN_ADS_PER_DAY', 10);       // Max ads to watch for spins (10 ads * 2 spins/ad = 20 extra spins)
define('SPINS_GAINED_PER_AD', 2);         // Spins gained for watching one ad for spins
define('MAX_ADS_PER_DAY', 38);            // For earning points directly
define('POINTS_PER_AD', 20);
define('POINTS_PER_REFERRAL_FOR_REFERRER', 20);
define('POINTS_PER_REFERRAL_FOR_REFERRED', 5);

$TASKS_CONFIG = [ // Using numeric keys directly as per usage
    1 => ["id" => 1, "name" => "Telegram Channel Join 1", "link" => "https://t.me/WatchSpinEarn", "points" => 48],
    2 => ["id" => 2, "name" => "Telegram Group Join", "link" => "https://t.me/WatchSpinEarnchat", "points" => 48],
    3 => ["id" => 3, "name" => "Telegram Channel Join 2", "link" => "https://t.me/ShopEarnHub4102h", "points" => 48],
    4 => ["id" => 4, "name" => "Telegram Channel Join 3", "link" => "https://t.me/earningsceret", "points" => 48],
    5 => ["id" => 5, "name" => "Twitter Follow", "link" => "https://x.com/watchspin4102h", "points" => 48],
];

if (!function_exists('getCurrentUtcDate')) {
    function getCurrentUtcDate() {
        return gmdate('Y-m-d');
    }
}

if (!function_exists('checkAndResetDailyLimits')) {
    function checkAndResetDailyLimits($db_conn_obj, $userId_param) { // Renamed params
        global $TASKS_CONFIG; // Make sure $TASKS_CONFIG is available

        if (!$db_conn_obj instanceof PDO) {
            error_log("checkAndResetDailyLimits: Invalid PDO object passed.");
            return false; // Or throw an exception
        }

        $todayUtc = getCurrentUtcDate();
        try {
            $stmt = $db_conn_obj->prepare("SELECT last_activity_date, daily_tasks_status FROM users WHERE user_id = :user_id");
            $stmt->execute([':user_id' => $userId_param]);
            $userActivity = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("checkAndResetDailyLimits DB Error fetching user activity: " . $e->getMessage());
            return false;
        }


        if (!$userActivity) {
             error_log("checkAndResetDailyLimits: User not found with ID " . htmlspecialchars($userId_param));
             return false; // User not found, cannot reset
        }

        if ($userActivity['last_activity_date'] === null || $userActivity['last_activity_date'] < $todayUtc) {
            $initial_tasks_status_array = [];
            if (is_array($TASKS_CONFIG)) { // Check if $TASKS_CONFIG is an array
                foreach ($TASKS_CONFIG as $task_id_key => $task_details) {
                    // Ensure keys are strings for JSON consistency if they are numeric
                    $initial_tasks_status_array[(string)$task_id_key] = false;
                }
            }
            $initial_tasks_status_json = json_encode($initial_tasks_status_array);

            try {
                $updateStmt = $db_conn_obj->prepare("
                    UPDATE users
                    SET spins_left_today = :max_spins,
                        ads_watched_today = 0,          -- For earning points
                        spin_ads_watched_today = 0,     -- For earning extra spins
                        daily_tasks_status = :tasks_status,
                        last_activity_date = :today_utc
                    WHERE user_id = :user_id
                ");
                $updateStmt->execute([
                    ':max_spins' => MAX_FREE_SPINS_PER_DAY,
                    ':tasks_status' => $initial_tasks_status_json,
                    ':today_utc' => $todayUtc,
                    ':user_id' => $userId_param
                ]);
                return true;
            } catch (PDOException $e) {
                error_log("checkAndResetDailyLimits DB Error updating user limits: " . $e->getMessage());
                return false;
            }
        }
        return false; // No reset needed
    }
}
?>