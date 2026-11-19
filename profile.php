<?php
/**
 * StockWise — User Profile & Security Settings
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'My Profile';
requireLogin();

$user = getCurrentUser();
$userId = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $fullName = trim($_POST['full_name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $curPass  = $_POST['current_password'] ?? '';
    $newPass  = $_POST['new_password'] ?? '';
    $confPass = $_POST['confirm_password'] ?? '';

    $errors = [];
    if ($fullName === '') $errors[] = 'Full name is required.';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';

    // Check duplicate email
    $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
    $chk->execute([$email, $userId]);
    if ($chk->fetchColumn()) {
        $errors[] = 'Email address is already in use by another user.';
    }

    // Password change verification
    $wantsPasswordChange = !empty($newPass) || !empty($curPass);
    if ($wantsPasswordChange) {
        $st = $pdo->prepare('SELECT password FROM users WHERE id = ?');
        $st->execute([$userId]);
        $currentHash = $st->fetchColumn();

        if (!password_verify($curPass, $currentHash)) {
            $errors[] = 'Current password entered is incorrect.';
        } elseif (strlen($newPass) < 6) {
            $errors[] = 'New password must be at least 6 characters.';
        } elseif ($newPass !== $confPass) {
            $errors[] = 'New password and confirmation password do not match.';
        }
    }

    if (!empty($errors)) {
        setFlash('danger', implode('<br>', $errors));
    } else {
        if ($wantsPasswordChange) {
            $newHash = password_hash($newPass, PASSWORD_BCRYPT);
            $up = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, password = ?, updated_at = NOW() WHERE id = ?');
            $up->execute([$fullName, $email, $newHash, $userId]);
            logAudit($pdo, 'password_change', "User changed their password.");
        } else {
            $up = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, updated_at = NOW() WHERE id = ?');
            $up->execute([$fullName, $email, $userId]);
            logAudit($pdo, 'profile_update', "User updated their profile information.");
        }

        // Update session
        $_SESSION['user']['full_name'] = $fullName;
        $_SESSION['user']['email']     = $email;

        setFlash('success', 'Your profile details have been updated successfully!');
        header('Location: profile.php');
        exit;
    }
}

// Fetch fresh details
$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$userId]);
$profile = $stmt->fetch();

$csrfToken = getCsrfToken();
require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8 col-xl-7">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h2 class="fw-bold mb-1"><i class="bi bi-person-circle me-2 text-primary"></i>My Profile</h2>
                <p class="text-muted mb-0">View your account privileges and manage security credentials.</p>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-3 pb-3 border-bottom mb-4">
                    <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center fs-2 fw-bold" style="width: 64px; height: 64px;">
                        <?= strtoupper(substr($profile['full_name'], 0, 1)) ?>
                    </div>
                    <div>
                        <h4 class="fw-bold mb-0 text-dark"><?= htmlspecialchars($profile['full_name']) ?></h4>
                        <div class="text-muted small">@<?= htmlspecialchars($profile['username']) ?> &bull; <span class="badge bg-primary-subtle text-primary-emphasis text-uppercase"><?= htmlspecialchars($profile['role']) ?></span></div>
                    </div>
                </div>

                <form method="POST" action="profile.php">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Username</label>
                            <input type="text" class="form-control bg-light font-monospace" value="<?= htmlspecialchars($profile['username']) ?>" readonly>
                            <div class="form-text">Usernames are system-managed.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">System Role</label>
                            <input type="text" class="form-control bg-light text-capitalize" value="<?= htmlspecialchars($profile['role']) ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="full_name" class="form-control" required value="<?= htmlspecialchars($profile['full_name']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control" required value="<?= htmlspecialchars($profile['email']) ?>">
                        </div>
                    </div>

                    <h5 class="fw-bold text-dark pt-3 border-top mb-3"><i class="bi bi-key me-2 text-warning"></i>Security & Password Change</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Current Password</label>
                            <input type="password" name="current_password" class="form-control" placeholder="Required only if changing password">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">New Password</label>
                            <input type="password" name="new_password" class="form-control" minlength="6" placeholder="Minimum 6 characters">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Confirm New Password</label>
                            <input type="password" name="confirm_password" class="form-control" minlength="6" placeholder="Repeat new password">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-lg me-1"></i> Save Changes
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
