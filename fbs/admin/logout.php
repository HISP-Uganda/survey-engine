<?php
session_start();
$_SESSION = [];
session_unset();
session_destroy();

if (ini_get("session.use_cookies")) {
    setcookie(session_name(), '', time() - 42000, '/');
}

// Redirect to the root index.php (outside fbs folder)
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
$baseUrl = $protocol . $_SERVER['HTTP_HOST'];
// Get the project root by going up two levels from /fbs/admin/
$projectRoot = dirname(dirname($_SERVER['SCRIPT_NAME']));
$siteUrl = $baseUrl . rtrim($projectRoot, '/') . '/../' ;

header('Location: ' . $siteUrl);
exit;