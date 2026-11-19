<?php
/**
 * StockWise — Entry Point
 * Redirects authenticated users to the dashboard; others to login.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

if (isset($_SESSION['user'])) {
    header('Location: dashboard.php');
} else {
    header('Location: login.php');
}
exit;
