<?php
/**
 * StockWise — Application Configuration
 * Database connection, constants, session setup, error reporting.
 */

// ─── Error Reporting (production style) ───────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../logs/php_errors.log');

// ─── App Constants ─────────────────────────────────────────────────────────────
define('APP_NAME',    'StockWise');
define('APP_VERSION', '1.0.0');
define('APP_ROOT',    dirname(__DIR__));
define('BASE_URL',    '/stockwise');          // Adjust to match your vhost path

// ─── Stock Threshold ───────────────────────────────────────────────────────────
if (!defined('LOW_STOCK_THRESHOLD')) {
    define('LOW_STOCK_THRESHOLD', 10);
}

// ─── Database Constants ────────────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'stockwise');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// ─── PDO Connection ────────────────────────────────────────────────────────────
$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=%s',
    DB_HOST,
    DB_NAME,
    DB_CHARSET
);

$pdoOptions = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $pdoOptions);
} catch (PDOException $e) {
    // Log without exposing credentials
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(503);
    die('<h1>Service Unavailable</h1><p>Unable to connect to the database. Please try again later.</p>');
}

// ─── Secure Session ────────────────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,           // Session cookie (expires on browser close)
        'path'     => '/',
        'domain'   => '',          // Current domain
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    // Regenerate session ID on first access to prevent fixation
    if (empty($_SESSION['_initiated'])) {
        session_regenerate_id(true);
        $_SESSION['_initiated'] = true;
    }
}
