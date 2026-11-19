<?php
/**
 * StockWise — One-time user seeder
 * ─────────────────────────────────
 * Run this script ONCE from the CLI (or via a web request on localhost)
 * AFTER importing stockwise.sql to create the default user accounts with
 * properly hashed passwords.
 *
 * Usage (CLI):
 *   php seed_passwords.php
 *
 * IMPORTANT: Delete or move this file after running it.
 *            Change all passwords immediately after first login.
 *
 * Requirements: PHP 7.2+, PDO with mysql driver, stockwise.sql already imported.
 */

// ── Database connection ────────────────────────────────────────────────────
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'stockwise');
define('DB_USER', 'root');       // ← change to your DB username
define('DB_PASS', '');           // ← change to your DB password

// ── Default users to seed ─────────────────────────────────────────────────
// CHANGE THESE PASSWORDS before running in production!
$users = [
    [
        'username'  => 'admin',
        'email'     => 'admin@stockwise.local',
        'password'  => 'Admin@1234!',       // CHANGE ME
        'full_name' => 'System Administrator',
        'role'      => 'admin',
        'is_active' => 1,
    ],
    [
        'username'  => 'manager',
        'email'     => 'manager@stockwise.local',
        'password'  => 'Manager@1234!',     // CHANGE ME
        'full_name' => 'Store Manager',
        'role'      => 'manager',
        'is_active' => 1,
    ],
    [
        'username'  => 'cashier',
        'email'     => 'cashier@stockwise.local',
        'password'  => 'Cashier@1234!',     // CHANGE ME
        'full_name' => 'Default Cashier',
        'role'      => 'cashier',
        'is_active' => 1,
    ],
];

// ── Connect ───────────────────────────────────────────────────────────────
try {
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage() . PHP_EOL);
}

// ── Insert users ──────────────────────────────────────────────────────────
$sql = "INSERT INTO `users`
            (`username`, `email`, `password`, `full_name`, `role`, `is_active`)
        VALUES
            (:username, :email, :password, :full_name, :role, :is_active)
        ON DUPLICATE KEY UPDATE
            `password`  = VALUES(`password`),
            `full_name` = VALUES(`full_name`),
            `role`      = VALUES(`role`),
            `is_active` = VALUES(`is_active`),
            `updated_at` = NOW()";

$stmt = $pdo->prepare($sql);

foreach ($users as $user) {
    $hash = password_hash($user['password'], PASSWORD_BCRYPT, ['cost' => 12]);

    $stmt->execute([
        ':username'  => $user['username'],
        ':email'     => $user['email'],
        ':password'  => $hash,
        ':full_name' => $user['full_name'],
        ':role'      => $user['role'],
        ':is_active' => $user['is_active'],
    ]);

    printf(
        "✔  Created/updated user %-12s  [%s]  hash: %s\n",
        $user['username'],
        $user['role'],
        $hash
    );
}

echo PHP_EOL;
echo "────────────────────────────────────────────────────────────────\n";
echo "Users seeded successfully.\n";
echo "IMPORTANT: Change all default passwords and delete this file.\n";
echo "────────────────────────────────────────────────────────────────\n";
