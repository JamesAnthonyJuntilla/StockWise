<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Log the logout if a user session exists
if (isset($_SESSION['user'])) {
    logAudit($pdo, 'LOGOUT', 'User logged out: ' . $_SESSION['user']['username']);
}

// Destroy the session completely
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

// Start a fresh session just to store the flash message
session_start();
session_regenerate_id(true);
$_SESSION['flash'] = [
    'type'    => 'success',
    'message' => 'You have been logged out successfully.',
];

header('Location: login.php');
exit;
