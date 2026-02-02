<?php
// pages/logout.php
declare(strict_types=1);

session_start();

// Clear all session data
$_SESSION = [];

// Delete the session cookie properly
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 3600,
        $params['path'] ?? '/',
        $params['domain'] ?? '',
        (bool)($params['secure'] ?? false),
        (bool)($params['httponly'] ?? true)
    );
}

// Destroy the session
session_destroy();

// Extra: prevent caching of logout response
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Redirect to login
header("Location: login.php");
exit;
