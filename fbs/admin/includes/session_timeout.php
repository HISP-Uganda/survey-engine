<?php
// Session timeout initialization for admin pages
// Include this at the top of admin pages after session_start()

if (!defined('SESSION_TIMEOUT_INITIALIZED')) {
    define('SESSION_TIMEOUT_INITIALIZED', true);
    
    // Initialize last activity timestamp if not set
    if (!isset($_SESSION['last_activity'])) {
        $_SESSION['last_activity'] = time();
    }
    
    // Check if session has timed out (30 minutes = 1800 seconds)
    $timeout = 1800;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        // Session has timed out
        $_SESSION = [];
        session_unset();
        session_destroy();
        
        if (ini_get("session.use_cookies")) {
            setcookie(session_name(), '', time() - 42000, '/');
        }
        
        // Redirect to login with timeout message
        header("Location: login.php?timeout=1");
        exit;
    }
    
    // Update last activity timestamp
    $_SESSION['last_activity'] = time();
}
?>