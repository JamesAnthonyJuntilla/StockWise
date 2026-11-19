<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Redirect if already logged in
if (isset($_SESSION['user'])) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$username = '';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        $error = 'Invalid request. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password  = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Please enter your username and password.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    "SELECT id, username, email, password, full_name, role, is_active
                     FROM users WHERE username = ? LIMIT 1"
                );
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if ($user && $user['is_active'] && password_verify($password, $user['password'])) {
                    session_regenerate_id(true);
                    $_SESSION['user'] = [
                        'id'        => $user['id'],
                        'username'  => $user['username'],
                        'full_name' => $user['full_name'],
                        'role'      => $user['role'],
                        'email'     => $user['email'],
                    ];
                    // Regenerate CSRF token after successful login
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    logAudit($pdo, 'LOGIN', 'User logged in: ' . $user['username']);

                    header('Location: dashboard.php');
                    exit;
                } else {
                    $error = 'Invalid username or password.';
                }
            } catch (PDOException $e) {
                error_log('Login error: ' . $e->getMessage());
                $error = 'A system error occurred. Please try again later.';
            }
        }
    }
}

// Flash messages (e.g. from logout)
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — StockWise</title>

    <!-- Bootstrap 5 CDN -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <!-- Bootstrap Icons CDN -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <style>
        :root {
            --brand-blue: #2563eb;
            --brand-dark: #1e3a5f;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 50%, #2563eb 100%);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            padding: 1rem;
        }

        .login-wrapper {
            width: 100%;
            max-width: 420px;
        }

        .login-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 25px 60px rgba(0, 0, 0, 0.35);
            overflow: hidden;
        }

        .login-header {
            background: linear-gradient(135deg, #1e3a5f, #2563eb);
            padding: 2rem 2rem 1.5rem;
            text-align: center;
            color: #fff;
        }

        .login-logo {
            width: 60px;
            height: 60px;
            background: rgba(255,255,255,0.15);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            backdrop-filter: blur(4px);
            border: 1.5px solid rgba(255,255,255,0.3);
        }

        .login-logo i {
            font-size: 2rem;
            color: #fff;
        }

        .login-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0 0 0.25rem;
            letter-spacing: -0.5px;
        }

        .login-header .subtitle {
            font-size: 0.9rem;
            opacity: 0.8;
            margin: 0;
        }

        .login-body {
            padding: 2rem;
        }

        .form-label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.4rem;
        }

        .input-group-text {
            background: #f3f4f6;
            border-color: #d1d5db;
            color: #6b7280;
        }

        .form-control {
            border-color: #d1d5db;
            font-size: 0.95rem;
            padding: 0.55rem 0.75rem;
        }

        .form-control:focus {
            border-color: var(--brand-blue);
            box-shadow: 0 0 0 3px rgba(37,99,235,0.15);
        }

        .btn-login {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            border: none;
            font-weight: 600;
            font-size: 1rem;
            padding: 0.65rem;
            border-radius: 8px;
            letter-spacing: 0.3px;
            transition: all 0.2s;
        }

        .btn-login:hover {
            background: linear-gradient(135deg, #1d4ed8, #1e40af);
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(37,99,235,0.4);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .login-footer {
            background: #f8fafc;
            border-top: 1px solid #e5e7eb;
            padding: 1rem 2rem;
            text-align: center;
        }

        .login-footer small {
            color: #9ca3af;
            font-size: 0.78rem;
        }

        .password-toggle {
            cursor: pointer;
            border-left: none;
            background: #f3f4f6;
            border-color: #d1d5db;
        }

        .password-toggle:hover {
            background: #e5e7eb;
        }

        .alert-danger {
            border-radius: 8px;
            font-size: 0.875rem;
        }

        .alert-success {
            border-radius: 8px;
            font-size: 0.875rem;
        }

        .form-check-label {
            font-size: 0.85rem;
            color: #6b7280;
        }

        .forgot-link {
            font-size: 0.85rem;
            color: var(--brand-blue);
            text-decoration: none;
        }

        .forgot-link:hover {
            text-decoration: underline;
        }

        .particles {
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            pointer-events: none;
            overflow: hidden;
            z-index: 0;
        }

        .login-wrapper { position: relative; z-index: 1; }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); opacity: 0.3; }
            50% { transform: translateY(-20px) rotate(180deg); opacity: 0.6; }
        }

        .particle {
            position: absolute;
            width: 6px; height: 6px;
            background: rgba(255,255,255,0.3);
            border-radius: 50%;
            animation: float linear infinite;
        }
    </style>
</head>
<body>

<!-- Decorative floating particles -->
<div class="particles" aria-hidden="true">
    <?php for ($i = 0; $i < 12; $i++):
        $left = rand(5, 95);
        $top  = rand(5, 95);
        $dur  = rand(4, 10);
        $delay = rand(0, 6);
        $size = rand(4, 10);
    ?>
    <div class="particle" style="left:<?= $left ?>%;top:<?= $top ?>%;width:<?= $size ?>px;height:<?= $size ?>px;animation-duration:<?= $dur ?>s;animation-delay:-<?= $delay ?>s;"></div>
    <?php endfor; ?>
</div>

<div class="login-wrapper">
    <div class="login-card">
        <!-- Header -->
        <div class="login-header">
            <div class="login-logo">
                <i class="bi bi-box-seam-fill"></i>
            </div>
            <h1>StockWise</h1>
            <p class="subtitle">Welcome back — please sign in to continue</p>
        </div>

        <!-- Body -->
        <div class="login-body">
            <?php if ($flash && $flash['type'] === 'success'): ?>
                <div class="alert alert-success alert-dismissible fade show py-2" role="alert">
                    <i class="bi bi-check-circle me-1"></i>
                    <?= htmlspecialchars($flash['message']) ?>
                    <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-center py-2" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

                <!-- Username -->
                <div class="mb-3">
                    <label for="username" class="form-label">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person-fill"></i></span>
                        <input
                            type="text"
                            class="form-control"
                            id="username"
                            name="username"
                            value="<?= htmlspecialchars($username) ?>"
                            placeholder="Enter your username"
                            autocomplete="username"
                            autofocus
                            required
                        >
                    </div>
                </div>

                <!-- Password -->
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                        <input
                            type="password"
                            class="form-control"
                            id="password"
                            name="password"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                        >
                        <button
                            class="btn btn-outline-secondary password-toggle"
                            type="button"
                            id="togglePassword"
                            title="Show/hide password"
                            aria-label="Toggle password visibility"
                        >
                            <i class="bi bi-eye-fill" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Remember me -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="rememberMe" name="remember_me">
                        <label class="form-check-label" for="rememberMe">Remember me</label>
                    </div>
                    <span class="text-muted" style="font-size:0.82rem;">Contact admin to reset password</span>
                </div>

                <!-- Submit -->
                <button type="submit" class="btn btn-primary btn-login w-100 text-white">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                </button>
            </form>
        </div>

        <!-- Footer -->
        <div class="login-footer">
            <small>StockWise v1.0 &mdash; Inventory Management System</small>
        </div>
    </div>
</div>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Password show/hide toggle
    const toggleBtn  = document.getElementById('togglePassword');
    const passwordEl = document.getElementById('password');
    const toggleIcon = document.getElementById('toggleIcon');

    toggleBtn.addEventListener('click', function () {
        const isPassword = passwordEl.type === 'password';
        passwordEl.type  = isPassword ? 'text' : 'password';
        toggleIcon.className = isPassword ? 'bi bi-eye-slash-fill' : 'bi bi-eye-fill';
    });
</script>
</body>
</html>
