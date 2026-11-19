<?php
/**
 * StockWise — User Management & RBAC Administration
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'User Management';
requireLogin();
requireRole(['admin']);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── GET ?action=get&id=X (return JSON for edit modal) ───────────────
if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT id, username, email, full_name, role, is_active FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $user = $stmt->fetch();
    if (!$user) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found']);
        exit;
    }
    echo json_encode($user);
    exit;
}

// ── POST action=add ─────────────────────────────────────────────────
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $role     = trim($_POST['role'] ?? 'cashier');
    $password = $_POST['password'] ?? '';

    $errors = [];
    if ($username === '') $errors[] = 'Username is required.';
    if ($fullName === '') $errors[] = 'Full name is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
    if (!in_array($role, ['admin', 'manager', 'cashier'], true)) $errors[] = 'Invalid user role selected.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';

    // Check duplicate username or email
    $chk = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
    $chk->execute([$username, $email]);
    if ($chk->fetchColumn()) {
        $errors[] = 'Username or Email is already registered.';
    }

    if (!empty($errors)) {
        setFlash('danger', implode('<br>', $errors));
    } else {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $pdo->prepare("
            INSERT INTO users (username, email, password, full_name, role, is_active, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
        ");
        $stmt->execute([$username, $email, $hash, $fullName, $role]);
        logAudit($pdo, 'user_create', "Created user account '{$username}' ({$role})");
        setFlash('success', "User account for <strong>" . htmlspecialchars($fullName) . "</strong> created successfully!");
    }
    header('Location: users.php');
    exit;
}

// ── POST action=edit ────────────────────────────────────────────────
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $id       = (int)($_POST['id'] ?? 0);
    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $role     = trim($_POST['role'] ?? 'cashier');
    $password = $_POST['password'] ?? '';

    $errors = [];
    if ($fullName === '') $errors[] = 'Full name is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
    if (!in_array($role, ['admin', 'manager', 'cashier'], true)) $errors[] = 'Invalid user role selected.';

    // Check duplicate email for another user
    $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
    $chk->execute([$email, $id]);
    if ($chk->fetchColumn()) {
        $errors[] = 'Email address is already in use by another account.';
    }

    if (!empty($errors)) {
        setFlash('danger', implode('<br>', $errors));
    } else {
        if (!empty($password)) {
            if (strlen($password) < 6) {
                setFlash('danger', 'Password must be at least 6 characters.');
                header('Location: users.php');
                exit;
            }
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, role = ?, password = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$fullName, $email, $role, $hash, $id]);
        } else {
            $stmt = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, role = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$fullName, $email, $role, $id]);
        }

        logAudit($pdo, 'user_edit', "Updated user account ID #{$id}");
        setFlash('success', "User account <strong>" . htmlspecialchars($fullName) . "</strong> updated successfully!");
    }
    header('Location: users.php');
    exit;
}

// ── POST action=toggle_status ───────────────────────────────────────
if ($action === 'toggle_status' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $id = (int)($_POST['id'] ?? 0);

    // Prevent deactivating own account
    if ($id === (int)$_SESSION['user']['id']) {
        setFlash('danger', 'Security Restriction: You cannot deactivate your own active session account.');
        header('Location: users.php');
        exit;
    }

    $st = $pdo->prepare('SELECT is_active, username FROM users WHERE id = ?');
    $st->execute([$id]);
    $u = $st->fetch();

    if ($u) {
        $newStatus = $u['is_active'] ? 0 : 1;
        $pdo->prepare('UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?')->execute([$newStatus, $id]);
        $statusLabel = $newStatus ? 'activated' : 'deactivated';
        logAudit($pdo, 'user_status_change', "User {$u['username']} was {$statusLabel}");
        setFlash('success', "User <strong>{$u['username']}</strong> has been {$statusLabel}.");
    }

    header('Location: users.php');
    exit;
}

$users = $pdo->query('SELECT * FROM users ORDER BY role ASC, full_name ASC')->fetchAll();
$csrfToken = getCsrfToken();
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h2 class="fw-bold mb-1"><i class="bi bi-people me-2 text-primary"></i>User & Staff Management</h2>
        <p class="text-muted mb-0">Control staff credentials, assign role-based permissions, and manage account access.</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="bi bi-person-plus me-1"></i> Add New User
    </button>
</div>

<!-- Users Table Card -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">User Profile</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>System Role</th>
                        <th class="text-center">Status</th>
                        <th>Created Date</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td class="ps-3">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="rounded-circle bg-primary-subtle text-primary fw-bold d-flex align-items-center justify-content-center" style="width: 38px; height: 38px;">
                                        <?= strtoupper(substr($u['full_name'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <div class="fw-semibold text-dark"><?= htmlspecialchars($u['full_name']) ?></div>
                                        <?php if ((int)$u['id'] === (int)$_SESSION['user']['id']): ?>
                                            <span class="badge bg-info-subtle text-info-emphasis rounded-pill" style="font-size:0.65rem;">You</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="font-monospace"><?= htmlspecialchars($u['username']) ?></td>
                            <td class="text-muted small"><?= htmlspecialchars($u['email']) ?></td>
                            <td>
                                <?php if ($u['role'] === 'admin'): ?>
                                    <span class="badge bg-warning text-dark px-2.5 py-1 rounded-pill"><i class="bi bi-shield-lock me-1"></i>Administrator</span>
                                <?php elseif ($u['role'] === 'manager'): ?>
                                    <span class="badge bg-primary px-2.5 py-1 rounded-pill"><i class="bi bi-person-gear me-1"></i>Manager</span>
                                <?php else: ?>
                                    <span class="badge bg-success px-2.5 py-1 rounded-pill"><i class="bi bi-cart me-1"></i>Cashier</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($u['is_active']): ?>
                                    <span class="badge bg-success-subtle text-success-emphasis rounded-pill">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger-emphasis rounded-pill">Deactivated</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted small font-monospace"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                            <td class="text-end pe-3">
                                <button class="btn btn-sm btn-outline-primary me-1 btn-edit-user" data-id="<?= $u['id'] ?>" title="Edit User">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php if ((int)$u['id'] !== (int)$_SESSION['user']['id']): ?>
                                    <button class="btn btn-sm <?= $u['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?> btn-toggle-status" data-id="<?= $u['id'] ?>" data-username="<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>" data-status="<?= $u['is_active'] ?>" title="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                        <i class="bi <?= $u['is_active'] ? 'bi-person-x' : 'bi-person-check' ?>"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: Add User -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="users.php">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-person-plus me-2 text-primary"></i>Create New User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" class="form-control" required placeholder="e.g. John Doe">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" required placeholder="e.g. jdoe">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control" required placeholder="jdoe@stockwise.local">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                        <select name="role" class="form-select" required>
                            <option value="cashier">Cashier (Point of Sale & Own Transactions)</option>
                            <option value="manager">Manager (Inventory, Products, Sales, Reports)</option>
                            <option value="admin">Administrator (Full Access & User Control)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                        <input type="password" name="password" class="form-control" required minlength="6" placeholder="Minimum 6 characters">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Create Account</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit User -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="users.php">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="id" id="editUserId">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit User Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="full_name" id="editUserFullName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                        <input type="email" name="email" id="editUserEmail" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                        <select name="role" id="editUserRole" class="form-select" required>
                            <option value="cashier">Cashier</option>
                            <option value="manager">Manager</option>
                            <option value="admin">Administrator</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Change Password (Optional)</label>
                        <input type="password" name="password" class="form-control" minlength="6" placeholder="Leave blank to keep existing password">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Form: Toggle Status -->
<form id="toggleStatusForm" method="POST" action="users.php">
    <input type="hidden" name="action" value="toggle_status">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <input type="hidden" name="id" id="toggleStatusId">
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Edit User
    document.querySelectorAll('.btn-edit-user').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = this.getAttribute('data-id');
            fetch(`users.php?action=get&id=${id}`)
                .then(r => r.json())
                .then(u => {
                    if (u.error) {
                        showToast(u.error, 'danger');
                        return;
                    }
                    document.getElementById('editUserId').value = u.id;
                    document.getElementById('editUserFullName').value = u.full_name;
                    document.getElementById('editUserEmail').value = u.email;
                    document.getElementById('editUserRole').value = u.role;
                    new bootstrap.Modal(document.getElementById('editUserModal')).show();
                });
        });
    });

    // Toggle Status
    document.querySelectorAll('.btn-toggle-status').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = this.getAttribute('data-id');
            const username = this.getAttribute('data-username');
            const isActive = this.getAttribute('data-status') === '1';
            const actionText = isActive ? 'deactivate' : 'activate';

            confirmAction(
                `Are you sure you want to ${actionText} user account "${username}"?`,
                function () {
                    document.getElementById('toggleStatusId').value = id;
                    document.getElementById('toggleStatusForm').submit();
                },
                `${isActive ? 'Deactivate' : 'Activate'} User`,
                isActive
            );
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
