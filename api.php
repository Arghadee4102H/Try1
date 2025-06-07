<?php
// api.php

// --- Global Error and Exception Handling ---
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        if (ob_get_length()) @ob_end_clean(); // Suppress errors from ob_end_clean if buffer already handled

        $response = [
            'success' => false,
            'message' => 'A critical server error occurred. Please inform the administrator or check server logs.',
            'error_type' => 'shutdown_handler'
        ];
        if (defined('DEBUG_MODE') && DEBUG_MODE === 1) {
            $response['debug_error'] = ['type' => $error['type'], 'message' => $error['message'], 'file' => basename($error['file']), 'line' => $error['line']];
        }
        error_log(sprintf("PHP Fatal Error in api.php: Type %d, Message: %s, File: %s, Line: %d", $error['type'], $error['message'], $error['file'], $error['line']));
        echo json_encode($response);
        exit;
    }
});

set_exception_handler(function ($exception) {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    if (ob_get_length()) @ob_end_clean();

    $response = ['success' => false, 'message' => 'An unexpected application error occurred.', 'error_type' => 'exception_handler'];
    if (defined('DEBUG_MODE') && DEBUG_MODE === 1) {
        $response['debug_exception'] = ['message' => $exception->getMessage(), 'file' => basename($exception->getFile()), 'line' => $exception->getLine(), 'trace_short' => substr($exception->getTraceAsString(), 0, 500)];
    }
    error_log("PHP Exception in api.php: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
    if ($exception instanceof PDOException && $exception->getPrevious()) { // Log previous exception if it's a PDO one
        error_log("Previous Exception (PDO): " . $exception->getPrevious()->getMessage());
    }
    echo json_encode($response);
    exit;
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (!(error_reporting() & $errno)) return false;
    error_log("PHP Error (non-fatal) in api.php: [$errno] $errstr in " . basename($errfile) . " on line $errline");
    if (defined('DEBUG_MODE') && DEBUG_MODE === 1 && in_array($errno, [E_USER_ERROR, E_RECOVERABLE_ERROR, E_WARNING, E_USER_WARNING])) { // Show warnings too in debug
        // This part is tricky because a simple warning shouldn't break the JSON flow
        // For now, we just log. Fatal errors are handled by shutdown.
        // If you want warnings to break flow and return JSON, uncomment below carefully.
        /*
        if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
        if (ob_get_length()) @ob_end_clean();
        echo json_encode(['success' => false, 'message' => 'A server warning occurred.', 'debug_warning_details' => ['errno' => $errno, 'errstr' => $errstr, 'errfile' => basename($errfile), 'errline' => $errline]]);
        exit;
        */
    }
    return true;
}, E_ALL);
// --- End of Global Error Handling ---

ob_start(); // Start output buffering AFTER error handlers are set

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!headers_sent()) { // Attempt to set header if not already set by error handlers
    header('Content-Type: application/json; charset=utf-8');
}

$response = ['success' => false, 'message' => 'API request initiated but not processed.'];

require_once 'db_config.php'; // $pdo defined here or script exits via trigger_error

if (!$pdo instanceof PDO) {
    $response['message'] = "Database service unavailable. PDO object invalid.";
    if (defined('DEBUG_MODE') && DEBUG_MODE === 1) { $response['debug_info'] = "PDO object failed initialization or was overwritten post db_config.php inclusion."; }
    echo json_encode($response);
    if (ob_get_length()) @ob_end_flush();
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE && !empty(file_get_contents('php://input'))) {
    $response['message'] = 'Invalid JSON payload received: ' . json_last_error_msg();
    echo json_encode($response);
    if (ob_get_length()) @ob_end_flush();
    exit;
}
$action = $input['action'] ?? $_POST['action'] ?? $_GET['action'] ?? null;

if (!$action) {
    $response['message'] = 'No action specified for API request.';
    echo json_encode($response);
    if (ob_get_length()) @ob_end_flush();
    exit;
}

// Helper function (should be defined before use)
if (!function_exists('getCurrentUserDataFromApi')) {
    function getCurrentUserDataFromApi($pdo_param, $userId) {
        checkAndResetDailyLimits($pdo_param, $userId);
        $stmt = $pdo_param->prepare("SELECT user_id, username, email, points, spins_left_today, ads_watched_today, spin_ads_watched_today, referral_code, total_referrals_made, daily_tasks_status, used_4600_withdrawal, last_activity_date FROM users WHERE user_id = :user_id");
        $stmt->execute([':user_id' => $userId]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($userData && !empty($userData['daily_tasks_status']) && is_string($userData['daily_tasks_status'])) {
            $decoded_status = json_decode($userData['daily_tasks_status'], true);
            $userData['daily_tasks_status'] = (json_last_error() === JSON_ERROR_NONE) ? $decoded_status : [];
            if (json_last_error() !== JSON_ERROR_NONE) error_log("Corrupt JSON in daily_tasks_status for user ID $userId: " . $userData['daily_tasks_status']);
        } elseif ($userData) { // Ensure it's an array even if null/empty from DB
            $userData['daily_tasks_status'] = $userData['daily_tasks_status'] ? json_decode($userData['daily_tasks_status'], true) : [];
             if (json_last_error() !== JSON_ERROR_NONE && !empty($userData['daily_tasks_status'])) { // Check if it was non-empty but failed to decode
                $userData['daily_tasks_status'] = []; // Default to empty array on decode error
                error_log("Corrupt JSON (empty check) in daily_tasks_status for user ID $userId: " . $userData['daily_tasks_status']);
            }
        }
        return $userData;
    }
}

// ---- REGISTRATION ----
if ($action === 'register') {
    $username = $input['username'] ?? '';
    $email = $input['email'] ?? '';
    $password_input = $input['password'] ?? '';
    $confirmPassword = $input['confirm_password'] ?? '';

    if (empty($username) || empty($email) || empty($password_input) || empty($confirmPassword)) {
        $response['message'] = 'All fields are required for registration.';
    } elseif ($password_input !== $confirmPassword) {
        $response['message'] = 'Passwords do not match.';
    } elseif (strlen($password_input) < 6) {
        $response['message'] = 'Password must be at least 6 characters.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $response['message'] = 'Invalid email format.';
    } else {
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = :username OR email = :email");
        $stmt->execute([':username' => $username, ':email' => $email]);
        if ($stmt->fetch()) {
            $response['message'] = 'Username or email already taken.';
        } else {
            $hashedPassword = password_hash($password_input, PASSWORD_DEFAULT);
            $referralCode = "AST" . preg_replace("/[^a-zA-Z0-9]/", "", $username);
            global $TASKS_CONFIG;
            $initial_tasks_status_array = [];
            if (is_array($TASKS_CONFIG)) { foreach ($TASKS_CONFIG as $task_id_key => $val) { $initial_tasks_status_array[(string)$task_id_key] = false; } }
            $initial_tasks_status_json = json_encode($initial_tasks_status_array);
            $todayUtc = getCurrentUtcDate();
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password, referral_code, daily_tasks_status, last_activity_date) VALUES (:username, :email, :password, :referral_code, :tasks_status, :last_activity)");
            if ($stmt->execute([':username' => $username, ':email' => $email, ':password' => $hashedPassword, ':referral_code' => $referralCode, ':tasks_status' => $initial_tasks_status_json, ':last_activity' => $todayUtc])) {
                $response['success'] = true;
                $response['message'] = 'Registration successful! Please login.';
            } else {
                $response['message'] = 'Registration failed. Please try again.';
                if (defined('DEBUG_MODE') && DEBUG_MODE === 1) { $response['debug_sql_error'] = $stmt->errorInfo(); }
            }
        }
    }
}
// ---- LOGIN ----
elseif ($action === 'login') {
    $email = $input['email'] ?? '';
    $password_input = $input['password'] ?? '';
    if (empty($email) || empty($password_input)) {
        $response['message'] = 'Email and password are required.';
    } else {
        $stmt = $pdo->prepare("SELECT user_id, username, password FROM users WHERE email = :email");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && password_verify($password_input, $user['password'])) {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            checkAndResetDailyLimits($pdo, $user['user_id']);
            $response['success'] = true;
            $response['message'] = 'Login successful.';
            $response['userData'] = getCurrentUserDataFromApi($pdo, $user['user_id']);
        } else {
            $response['message'] = 'Invalid email or password.';
        }
    }
}
// ---- CHECK SESSION ----
elseif ($action === 'check_session') {
    if (isset($_SESSION['user_id'])) {
        checkAndResetDailyLimits($pdo, $_SESSION['user_id']);
        $userData = getCurrentUserDataFromApi($pdo, $_SESSION['user_id']);
        if ($userData) {
            $response['success'] = true; $response['isLoggedIn'] = true; $response['userData'] = $userData;
        } else {
            unset($_SESSION['user_id']); unset($_SESSION['username']);
            $response = ['success' => true, 'isLoggedIn' => false, 'message' => "Session invalid. Please login."];
        }
    } else {
        $response = ['success' => true, 'isLoggedIn' => false];
    }
}
// --- Authentication check for protected actions below ---
elseif (!isset($_SESSION['user_id']) && !in_array($action, ['register', 'login', 'check_session'])) {
    $response = ['success' => false, 'message' => 'Authentication required. Please login.', 'redirectToLogin' => true];
}

// ---- GET SPINS FROM AD ----
elseif ($action === 'getSpinsFromAd') {
    $userId = $_SESSION['user_id'];
    checkAndResetDailyLimits($pdo, $userId);
    $userData = getCurrentUserDataFromApi($pdo, $userId);
    if ($userData['spin_ads_watched_today'] < MAX_SPIN_ADS_PER_DAY) {
        $newSpinsCount = $userData['spins_left_today'] + SPINS_GAINED_PER_AD;
        $stmt = $pdo->prepare("UPDATE users SET spins_left_today = :new_spins, spin_ads_watched_today = spin_ads_watched_today + 1 WHERE user_id = :user_id");
        $stmt->execute([':new_spins' => $newSpinsCount, ':user_id' => $userId]);
        $response = ['success' => true, 'message' => SPINS_GAINED_PER_AD . " extra spins added!", 'userData' => getCurrentUserDataFromApi($pdo, $userId)];
    } else {
        $response['message'] = "Max ads for spins watched today (" . MAX_SPIN_ADS_PER_DAY . ").";
    }
}
// ---- SPIN WHEEL ----
elseif ($action === 'spin') {
    $userId = $_SESSION['user_id'];
    checkAndResetDailyLimits($pdo, $userId);
    $userData = getCurrentUserDataFromApi($pdo, $userId);
    if ($userData['spins_left_today'] > 0) {
        $pointsEarned = (mt_rand(1, 100) <= 80) ? mt_rand(2, 10) : mt_rand(11, 15);
        $stmt = $pdo->prepare("UPDATE users SET points = points + :points, spins_left_today = spins_left_today - 1 WHERE user_id = :user_id");
        $stmt->execute([':points' => $pointsEarned, ':user_id' => $userId]);
        $response = ['success' => true, 'message' => "You won {$pointsEarned} points!", 'pointsEarned' => $pointsEarned, 'userData' => getCurrentUserDataFromApi($pdo, $userId)];
    } else {
        $response['message'] = "No spins left. Watch an ad for more.";
    }
}
// ---- WATCH AD FOR POINTS ----
elseif ($action === 'adWatched') {
    $userId = $_SESSION['user_id'];
    checkAndResetDailyLimits($pdo, $userId);
    $userData = getCurrentUserDataFromApi($pdo, $userId);
    if ($userData['ads_watched_today'] < MAX_ADS_PER_DAY) {
        $stmt = $pdo->prepare("UPDATE users SET points = points + :points, ads_watched_today = ads_watched_today + 1 WHERE user_id = :user_id");
        $stmt->execute([':points' => POINTS_PER_AD, ':user_id' => $userId]);
        $response = ['success' => true, 'message' => POINTS_PER_AD . " points added for ad.", 'userData' => getCurrentUserDataFromApi($pdo, $userId)];
    } else {
        $response['message'] = "Max ads for points watched today.";
    }
}
// ---- GET TASKS ----
elseif ($action === 'get_tasks') {
    $userId = $_SESSION['user_id'];
    checkAndResetDailyLimits($pdo, $userId);
    $userData = getCurrentUserDataFromApi($pdo, $userId);
    $userTasksStatus = (is_array($userData['daily_tasks_status']) ? $userData['daily_tasks_status'] : []);
    global $TASKS_CONFIG;
    $tasksWithStatus = [];
    if(is_array($TASKS_CONFIG)){ foreach ($TASKS_CONFIG as $id_key => $task) { $tasksWithStatus[] = array_merge($task, ['completed' => $userTasksStatus[(string)$id_key] ?? false]); } }
    $response = ['success' => true, 'tasks' => $tasksWithStatus];
}
// ---- COMPLETE TASK ----
elseif ($action === 'completeTask') {
    $userId = $_SESSION['user_id'];
    $taskId = $input['taskId'] ?? null;
    global $TASKS_CONFIG;
    if (!isset($TASKS_CONFIG[$taskId])) {
        $response['message'] = "Invalid task ID: " . htmlspecialchars($taskId);
    } else {
        checkAndResetDailyLimits($pdo, $userId);
        $userData = getCurrentUserDataFromApi($pdo, $userId);
        $currentTasksStatus = (is_array($userData['daily_tasks_status']) ? $userData['daily_tasks_status'] : []);
        if ($currentTasksStatus[(string)$taskId] ?? false) {
            $response['message'] = "Task already completed today.";
        } else {
            $currentTasksStatus[(string)$taskId] = true;
            $pointsToAward = $TASKS_CONFIG[$taskId]['points'];
            $stmt = $pdo->prepare("UPDATE users SET points = points + :points, daily_tasks_status = :tasks_status WHERE user_id = :user_id");
            $stmt->execute([':points' => $pointsToAward, ':tasks_status' => json_encode($currentTasksStatus), ':user_id' => $userId]);
            $updatedUserData = getCurrentUserDataFromApi($pdo, $userId);
            $updatedUserTasksStatus = (is_array($updatedUserData['daily_tasks_status']) ? $updatedUserData['daily_tasks_status'] : []);
            $tasksWithStatus = [];
            if(is_array($TASKS_CONFIG)){ foreach ($TASKS_CONFIG as $id_task => $task_data) { $tasksWithStatus[] = array_merge($task_data, ['completed' => $updatedUserTasksStatus[(string)$id_task] ?? false]); } }
            $response = ['success' => true, 'message' => "Task '{$TASKS_CONFIG[$taskId]['name']}' completed! {$pointsToAward} points awarded.", 'userData' => $updatedUserData, 'tasks' => $tasksWithStatus];
        }
    }
}
// ---- SUBMIT REFERRAL CODE ----
elseif ($action === 'submitReferralCode') {
    $userId = $_SESSION['user_id'];
    $submittedCode = $input['referralCode'] ?? '';
    if (empty($submittedCode)) {
        $response['message'] = "Referral code cannot be empty.";
    } else {
        $pdo->beginTransaction();
        try {
            $stmtCurrent = $pdo->prepare("SELECT referred_by_user_id FROM users WHERE user_id = :user_id");
            $stmtCurrent->execute([':user_id' => $userId]);
            $currentUser = $stmtCurrent->fetch(PDO::FETCH_ASSOC);
            if ($currentUser['referred_by_user_id'] !== null) {
                $response['message'] = "Referral code already submitted."; $pdo->rollBack();
            } else {
                $stmtReferrer = $pdo->prepare("SELECT user_id, username FROM users WHERE referral_code = :code AND user_id != :current_user_id");
                $stmtReferrer->execute([':code' => $submittedCode, ':current_user_id' => $userId]);
                $referrer = $stmtReferrer->fetch(PDO::FETCH_ASSOC);
                if (!$referrer) {
                    $response['message'] = "Invalid referral code or self-referral."; $pdo->rollBack();
                } else {
                    $stmtUpdateReferred = $pdo->prepare("UPDATE users SET points = points + :points, referred_by_user_id = :referrer_id WHERE user_id = :user_id");
                    $stmtUpdateReferred->execute([':points' => POINTS_PER_REFERRAL_FOR_REFERRED, ':referrer_id' => $referrer['user_id'], ':user_id' => $userId]);
                    $stmtUpdateReferrer = $pdo->prepare("UPDATE users SET points = points + :points, total_referrals_made = total_referrals_made + 1 WHERE user_id = :user_id");
                    $stmtUpdateReferrer->execute([':points' => POINTS_PER_REFERRAL_FOR_REFERRER, ':user_id' => $referrer['user_id']]);
                    $pdo->commit();
                    $response = ['success' => true, 'message' => "Referral accepted! You got " . POINTS_PER_REFERRAL_FOR_REFERRED . " points. {$referrer['username']} got " . POINTS_PER_REFERRAL_FOR_REFERRER . " points.", 'userData' => getCurrentUserDataFromApi($pdo, $userId)];
                }
            }
        } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
// ---- REQUEST WITHDRAWAL ----
elseif ($action === 'requestWithdrawal') {
    $userId = $_SESSION['user_id'];
    $username = $_SESSION['username'];
    $pointsToWithdraw = (int)($input['points'] ?? 0);
    $method = $input['method'] ?? '';
    $details = $input['details'] ?? '';
    checkAndResetDailyLimits($pdo, $userId);
    $userData = getCurrentUserDataFromApi($pdo, $userId);
    $userPoints = $userData['points'];
    $usedLowWithdrawal = $userData['used_4600_withdrawal'];
    $validWithdrawal = false;
    $withdrawalOptions = [4600 => ['is_low' => true], 90000 => ['is_low' => false], 170000 => ['is_low' => false], 305000 => ['is_low' => false]];
    if (!array_key_exists($pointsToWithdraw, $withdrawalOptions)) {
        $response['message'] = "Invalid withdrawal amount.";
    } elseif (empty($method) || empty($details)) {
        $response['message'] = "Method and details required.";
    } elseif ($userPoints < $pointsToWithdraw) {
        $response['message'] = "Not enough points.";
    } elseif ($withdrawalOptions[$pointsToWithdraw]['is_low'] && $usedLowWithdrawal) {
        $response['message'] = "4600 points option already used.";
    } else { $validWithdrawal = true; }
    if ($validWithdrawal) {
        $pdo->beginTransaction();
        try {
            $stmtDeduct = $pdo->prepare("UPDATE users SET points = points - :points WHERE user_id = :user_id AND points >= :points_check");
            $stmtDeduct->execute([':points' => $pointsToWithdraw, ':user_id' => $userId, ':points_check' => $pointsToWithdraw]);
            if ($stmtDeduct->rowCount() > 0) {
                if ($withdrawalOptions[$pointsToWithdraw]['is_low']) {
                    $stmtMarkUsed = $pdo->prepare("UPDATE users SET used_4600_withdrawal = 1 WHERE user_id = :user_id");
                    $stmtMarkUsed->execute([':user_id' => $userId]);
                }
                $userEmail = $userData['email'];
                $stmtInsert = $pdo->prepare("INSERT INTO withdrawals (user_id, username, email, points_withdrawn, withdrawal_method, wallet_address, status) VALUES (:user_id, :username, :email, :points, :method, :details, 'pending')");
                $stmtInsert->execute([':user_id' => $userId, ':username' => $username, ':email' => $userEmail, ':points' => $pointsToWithdraw, ':method' => $method, ':details' => $details]);
                $pdo->commit();
                $response = ['success' => true, 'message' => "Withdrawal for {$pointsToWithdraw} points requested.", 'userData' => getCurrentUserDataFromApi($pdo, $userId)];
            } else {
                $pdo->rollBack(); $response['message'] = "Withdrawal failed (insufficient points or error).";
            }
        } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
// ---- Fallback for unknown action ----
else {
    if (isset($_SESSION['user_id']) || in_array($action, ['register', 'login', 'check_session'])) {
         if($action) { $response['message'] = "Unknown API action: " . htmlspecialchars($action); }
         else { $response['message'] = "No action specified or action was invalid."; }
    }
    // If auth required & session not set, previous auth check handles it.
}

// --- Final Output ---
$json_output = json_encode($response);
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("FATAL: Failed to encode JSON response in api.php. Error: " . json_last_error_msg() . ". Original response data: " . print_r($response, true));
    @ob_end_clean(); // Attempt to clean buffer
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); }
    echo '{"success":false,"message":"Server Error: Could not create a valid JSON response. Please check server error logs."}';
} else {
    if (ob_get_length()) @ob_end_clean(); // Clean buffer before final echo
    echo $json_output;
}
exit;
?>