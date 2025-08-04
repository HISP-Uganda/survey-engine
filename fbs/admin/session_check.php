<?php
session_start();

// Set session timeout to 30 minutes (1800 seconds)
$timeout = 1800;

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['status' => 'not_logged_in']);
    exit;
}

// Check if last activity timestamp exists
if (!isset($_SESSION['last_activity'])) {
    $_SESSION['last_activity'] = time();
}

// Calculate time since last activity
$time_since_last_activity = time() - $_SESSION['last_activity'];

// If session has timed out
if ($time_since_last_activity > $timeout) {
    // Destroy session
    $_SESSION = [];
    session_unset();
    session_destroy();
    
    // Delete session cookie
    if (ini_get("session.use_cookies")) {
        setcookie(session_name(), '', time() - 42000, '/');
    }
    
    http_response_code(401);
    echo json_encode(['status' => 'timeout', 'message' => 'Session expired due to inactivity']);
    exit;
}

// Update last activity timestamp
$_SESSION['last_activity'] = time();

// Calculate remaining time
$remaining_time = $timeout - $time_since_last_activity;

// Return session status
echo json_encode([
    'status' => 'active',
    'remaining_time' => $remaining_time,
    'timeout_in' => $timeout
]);
?>