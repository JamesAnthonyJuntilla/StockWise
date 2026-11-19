<?php
/**
 * StockWise — Authentication & Authorization Helpers
 */

// ─── Redirect Helpers ──────────────────────────────────────────────────────────

/**
 * Redirect to login page if no authenticated session exists.
 */
function requireLogin(): void
{
    if (empty($_SESSION['user'])) {
        $redirect = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: /login.php' . ($redirect ? '?redirect=' . $redirect : ''));
        exit;
    }
}

/**
 * Restrict access to users whose role is in the given list.
 * Sets a 403 flash and redirects to dashboard on failure.
 *
 * @param string[] $roles Allowed role names, e.g. ['admin', 'manager']
 */
function requireRole(array $roles): void
{
    $user = getCurrentUser();
    if (!$user || !in_array($user['role'], $roles, true)) {
        setFlash('danger', 'Access denied: you do not have permission to view that page.');
        http_response_code(403);
        header('Location: /dashboard.php');
        exit;
    }
}

// ─── Current User ──────────────────────────────────────────────────────────────

/**
 * Return the session user array or null if not logged in.
 *
 * @return array<string,mixed>|null
 */
function getCurrentUser(): ?array
{
    return $_SESSION['user'] ?? null;
}

// ─── Role Checks ───────────────────────────────────────────────────────────────

/** Is the current user an admin? */
function isAdmin(): bool
{
    $user = getCurrentUser();
    return $user && $user['role'] === 'admin';
}

/**
 * Is the current user a manager or admin?
 * Managers can do everything except user management and audit logs.
 */
function isManager(): bool
{
    $user = getCurrentUser();
    return $user && in_array($user['role'], ['admin', 'manager'], true);
}

/**
 * Is the current user logged in at all (any role)?
 */
function isCashier(): bool
{
    return getCurrentUser() !== null;
}

// ─── Permission Map ────────────────────────────────────────────────────────────

/**
 * Check whether the current user has a specific named permission.
 *
 * Permission keys:
 *   products, categories, inventory, suppliers, sales, reports,
 *   users, audit_log, pos, sales_own
 *
 * @param string $permission
 */
function hasPermission(string $permission): bool
{
    $user = getCurrentUser();
    if (!$user) {
        return false;
    }

    $permissionMap = [
        'admin' => [
            'products', 'categories', 'inventory', 'suppliers',
            'sales', 'reports', 'users', 'audit_log', 'pos', 'sales_own',
        ],
        'manager' => [
            'products', 'categories', 'inventory', 'suppliers',
            'sales', 'reports', 'pos', 'sales_own',
        ],
        'cashier' => [
            'pos', 'sales_own',
        ],
    ];

    $role = $user['role'];
    return isset($permissionMap[$role])
        && in_array($permission, $permissionMap[$role], true);
}

// ─── Audit Logging ─────────────────────────────────────────────────────────────

/**
 * Insert a row into the audit_logs table.
 *
 * @param PDO    $pdo
 * @param string $action      Short action code, e.g. "product.create"
 * @param string $description Human-readable description of what happened
 */
function logAudit(PDO $pdo, string $action, string $description): void
{
    $user = getCurrentUser();
    $userId = $user['id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;

    $sql = 'INSERT INTO audit_logs (user_id, action, description, ip_address, created_at)
            VALUES (:user_id, :action, :description, :ip, NOW())';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':user_id'     => $userId,
            ':action'      => $action,
            ':description' => $description,
            ':ip'          => $ip,
        ]);
    } catch (PDOException $e) {
        // Audit failures must never break the application
        error_log('logAudit failed: ' . $e->getMessage());
    }
}
