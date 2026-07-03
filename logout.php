<?php
/**
 * IPManager Pro - Secure Logout
 * Handles session destruction for both standard and Redis handlers.
 */

session_start();

// 1. Clear session variables from memory
$_SESSION = array();

// 2. Invalidate the session cookie on the browser
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// 3. Destroy the session in the storage (Files or Redis)
session_destroy();

// 4. Redirect to login page
header('Location: login?logout=success');
exit;
