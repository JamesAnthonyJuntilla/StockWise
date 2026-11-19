<?php
/**
 * StockWise — Global Header & Sidebar
 *
 * Include this at the top of every protected page. The page MUST set
 * $pageTitle before including header.php, e.g.:
 *
 *   $pageTitle = 'Dashboard';
 *   require_once __DIR__ . '/../includes/header.php';
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

requireLogin();

$currentUser  = getCurrentUser();
$currentPage  = basename($_SERVER['PHP_SELF'], '.php');   // e.g. "dashboard"
$pageTitle    = $pageTitle ?? 'StockWise';

// ─── Low-stock notification count ──────────────────────────────────────────────
$lowStockCount = 0;
try {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM products WHERE stock_quantity <= min_stock_level AND min_stock_level > 0'
    );
    $stmt->execute();
    $lowStockCount = (int) $stmt->fetchColumn();
} catch (PDOException $e) {
    error_log('Low-stock query failed: ' . $e->getMessage());
}

// Helper: is this nav item the active page?
$isActive = static function (string ...$pages) use ($currentPage): bool {
    return in_array($currentPage, $pages, true);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> — <?= APP_NAME ?></title>

    <!-- Bootstrap 5.3 CSS -->
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"
          crossorigin="anonymous">

    <!-- Bootstrap Icons 1.11 -->
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- Custom styles -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">

    <style>
        /* ── Layout skeleton ─────────────────────────────────────────── */
        :root {
            --sidebar-width: 260px;
            --sidebar-bg:    #1a2332;
            --sidebar-hover: #243447;
            --sidebar-active:#2d4a7a;
            --topbar-height: 60px;
        }

        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        /* ── Sidebar ─────────────────────────────────────────────────── */
        #sidebar {
            position: fixed;
            top: 0; left: 0; bottom: 0;
            width: var(--sidebar-width);
            background: var(--sidebar-bg);
            overflow-y: auto;
            overflow-x: hidden;
            z-index: 1040;
            transition: transform .25s ease;
            display: flex;
            flex-direction: column;
        }

        #sidebar .brand {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,.08);
            display: flex;
            align-items: center;
            gap: .6rem;
            text-decoration: none;
        }
        #sidebar .brand .brand-icon {
            width: 36px; height: 36px;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            border-radius: 8px;
            display: flex; align-items: center; justify-content: center;
        }
        #sidebar .brand .brand-name {
            font-size: 1.25rem;
            font-weight: 700;
            color: #fff;
            letter-spacing: .5px;
        }
        #sidebar .brand .brand-version {
            font-size: .65rem;
            color: rgba(255,255,255,.4);
            display: block;
        }

        #sidebar .nav-section-label {
            padding: .75rem 1.5rem .3rem;
            font-size: .65rem;
            font-weight: 700;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            color: rgba(255,255,255,.35);
        }

        #sidebar .nav-link {
            padding: .6rem 1.5rem;
            color: rgba(255,255,255,.75);
            font-size: .875rem;
            border-radius: 0;
            display: flex;
            align-items: center;
            gap: .75rem;
            transition: background .15s, color .15s;
            white-space: nowrap;
            position: relative;
        }
        #sidebar .nav-link i {
            font-size: 1.1rem;
            width: 20px;
            text-align: center;
            flex-shrink: 0;
        }
        #sidebar .nav-link:hover {
            background: var(--sidebar-hover);
            color: #fff;
        }
        #sidebar .nav-link.active {
            background: var(--sidebar-active);
            color: #fff;
            font-weight: 600;
        }
        #sidebar .nav-link.active::before {
            content: '';
            position: absolute;
            left: 0; top: 0; bottom: 0;
            width: 3px;
            background: #3b82f6;
            border-radius: 0 2px 2px 0;
        }

        #sidebar .sidebar-footer {
            margin-top: auto;
            padding: 1rem 1.5rem;
            border-top: 1px solid rgba(255,255,255,.08);
            font-size: .75rem;
            color: rgba(255,255,255,.3);
        }

        /* ── Top navbar ──────────────────────────────────────────────── */
        #topbar {
            position: fixed;
            top: 0;
            left: var(--sidebar-width);
            right: 0;
            height: var(--topbar-height);
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            padding: 0 1.25rem;
            z-index: 1030;
            gap: 1rem;
            transition: left .25s ease;
        }

        .topbar-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1a2332;
            flex: 1;
        }

        .role-badge {
            font-size: .65rem;
            padding: .15rem .45rem;
            border-radius: 20px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .5px;
        }
        .role-badge.admin    { background:#fef3c7; color:#92400e; }
        .role-badge.manager  { background:#dbeafe; color:#1e40af; }
        .role-badge.cashier  { background:#d1fae5; color:#065f46; }

        /* ── Main content ─────────────────────────────────────────────── */
        .main-content {
            margin-left: var(--sidebar-width);
            padding-top: calc(var(--topbar-height) + 1.5rem);
            padding-left: 1.5rem;
            padding-right: 1.5rem;
            padding-bottom: 2rem;
            min-height: 100vh;
            transition: margin-left .25s ease;
        }

        /* ── Mobile ──────────────────────────────────────────────────── */
        @media (max-width: 991.98px) {
            #sidebar {
                transform: translateX(-100%);
            }
            #sidebar.show {
                transform: translateX(0);
            }
            #topbar {
                left: 0;
            }
            .main-content {
                margin-left: 0;
            }
        }

        /* ── Overlay (mobile) ────────────────────────────────────────── */
        #sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.5);
            z-index: 1035;
        }
        #sidebar-overlay.show { display: block; }
    </style>
</head>
<body>

<!-- ───────────────────────── SIDEBAR ───────────────────────────────────── -->
<nav id="sidebar">

    <!-- Brand -->
    <a href="<?= BASE_URL ?>/dashboard.php" class="brand">
        <div class="brand-icon">
            <i class="bi bi-box-seam text-white" style="font-size:1.1rem;"></i>
        </div>
        <div>
            <span class="brand-name"><?= APP_NAME ?></span>
            <span class="brand-version">v<?= APP_VERSION ?></span>
        </div>
    </a>

    <!-- Navigation -->
    <nav class="mt-2 flex-grow-1">

        <!-- Main -->
        <div class="nav-section-label">Main</div>

        <a href="<?= BASE_URL ?>/dashboard.php"
           class="nav-link <?= $isActive('dashboard') ? 'active' : '' ?>">
            <i class="bi bi-speedometer2"></i> Dashboard
        </a>

        <a href="<?= BASE_URL ?>/pos.php"
           class="nav-link <?= $isActive('pos') ? 'active' : '' ?>">
            <i class="bi bi-cart3"></i> Point of Sale
        </a>

        <?php if (isManager()): ?>
        <!-- Inventory -->
        <div class="nav-section-label">Inventory</div>

        <a href="<?= BASE_URL ?>/products.php"
           class="nav-link <?= $isActive('products', 'product_add', 'product_edit') ? 'active' : '' ?>">
            <i class="bi bi-box"></i> Products
        </a>

        <a href="<?= BASE_URL ?>/categories.php"
           class="nav-link <?= $isActive('categories') ? 'active' : '' ?>">
            <i class="bi bi-tags"></i> Categories
        </a>

        <a href="<?= BASE_URL ?>/inventory.php"
           class="nav-link <?= $isActive('inventory') ? 'active' : '' ?>">
            <i class="bi bi-clipboard-data"></i> Inventory
        </a>

        <a href="<?= BASE_URL ?>/suppliers.php"
           class="nav-link <?= $isActive('suppliers', 'supplier_add', 'supplier_edit') ? 'active' : '' ?>">
            <i class="bi bi-truck"></i> Suppliers
        </a>
        <?php endif; ?>

        <!-- Sales -->
        <div class="nav-section-label">Sales</div>

        <a href="<?= BASE_URL ?>/sales.php"
           class="nav-link <?= $isActive('sales', 'sale_view') ? 'active' : '' ?>">
            <i class="bi bi-receipt"></i> Sales
            <?php if (!isManager()): ?>
            <small class="ms-auto text-muted" style="font-size:.65rem;">(own)</small>
            <?php endif; ?>
        </a>

        <?php if (isManager()): ?>
        <a href="<?= BASE_URL ?>/reports.php"
           class="nav-link <?= $isActive('reports') ? 'active' : '' ?>">
            <i class="bi bi-bar-chart-line"></i> Reports
        </a>
        <?php endif; ?>

        <?php if (isAdmin()): ?>
        <!-- Admin -->
        <div class="nav-section-label">Admin</div>

        <a href="<?= BASE_URL ?>/users.php"
           class="nav-link <?= $isActive('users', 'user_add', 'user_edit') ? 'active' : '' ?>">
            <i class="bi bi-people"></i> Users
        </a>

        <a href="<?= BASE_URL ?>/audit_log.php"
           class="nav-link <?= $isActive('audit_log') ? 'active' : '' ?>">
            <i class="bi bi-journal-text"></i> Audit Log
        </a>
        <?php endif; ?>

    </nav><!-- /nav -->

    <div class="sidebar-footer">
        <?= APP_NAME ?> &copy; <?= date('Y') ?>
    </div>
</nav><!-- /#sidebar -->

<!-- Mobile overlay -->
<div id="sidebar-overlay"></div>

<!-- ───────────────────────── TOP NAVBAR ────────────────────────────────── -->
<header id="topbar">

    <!-- Hamburger (mobile) -->
    <button id="sidebar-toggle"
            class="btn btn-sm btn-light border-0 d-flex align-items-center justify-content-center"
            style="width:36px;height:36px;"
            aria-label="Toggle sidebar">
        <i class="bi bi-list fs-5"></i>
    </button>

    <!-- Page title -->
    <span class="topbar-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></span>

    <!-- Low-stock notification -->
    <div class="position-relative me-1">
        <a href="<?= BASE_URL ?>/inventory.php?filter=low_stock"
           class="btn btn-sm btn-light border-0 position-relative"
           title="Low stock alerts"
           style="width:36px;height:36px;">
            <i class="bi bi-bell fs-5"></i>
            <?php if ($lowStockCount > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                  style="font-size:.6rem;">
                <?= $lowStockCount ?>
                <span class="visually-hidden">low stock items</span>
            </span>
            <?php endif; ?>
        </a>
    </div>

    <!-- User dropdown -->
    <div class="dropdown">
        <button class="btn btn-sm btn-light border-0 d-flex align-items-center gap-2"
                type="button"
                data-bs-toggle="dropdown"
                aria-expanded="false"
                style="max-width:200px;">
            <div class="rounded-circle bg-primary text-white d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:30px;height:30px;font-size:.8rem;font-weight:600;">
                <?= strtoupper(substr($currentUser['full_name'] ?? $currentUser['username'], 0, 1)) ?>
            </div>
            <span class="d-none d-md-block text-truncate" style="max-width:120px;font-size:.875rem;font-weight:500;">
                <?= htmlspecialchars($currentUser['full_name'] ?? $currentUser['username'], ENT_QUOTES, 'UTF-8') ?>
            </span>
            <i class="bi bi-chevron-down d-none d-md-block" style="font-size:.65rem;"></i>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="min-width:200px;">
            <li>
                <div class="px-3 py-2 border-bottom">
                    <div class="fw-semibold" style="font-size:.875rem;">
                        <?= htmlspecialchars($currentUser['full_name'] ?? $currentUser['username'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <span class="role-badge <?= htmlspecialchars($currentUser['role'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars(ucfirst($currentUser['role']), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
            </li>
            <li>
                <a class="dropdown-item" href="<?= BASE_URL ?>/profile.php">
                    <i class="bi bi-person me-2"></i>My Profile
                </a>
            </li>
            <?php if (isAdmin()): ?>
            <li>
                <a class="dropdown-item" href="<?= BASE_URL ?>/settings.php">
                    <i class="bi bi-gear me-2"></i>Settings
                </a>
            </li>
            <?php endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li>
                <a class="dropdown-item text-danger" href="<?= BASE_URL ?>/logout.php">
                    <i class="bi bi-box-arrow-right me-2"></i>Logout
                </a>
            </li>
        </ul>
    </div>

</header><!-- /#topbar -->

<!-- ───────────────────── FLASH MESSAGES ────────────────────────────────── -->
<?php renderFlash(); ?>

<!-- ───────────────────── MAIN CONTENT WRAPPER ──────────────────────────── -->
<div class="main-content">

<script>
(function () {
    'use strict';
    var toggle  = document.getElementById('sidebar-toggle');
    var sidebar = document.getElementById('sidebar');
    var overlay = document.getElementById('sidebar-overlay');

    function open()  { sidebar.classList.add('show');    overlay.classList.add('show');    }
    function close() { sidebar.classList.remove('show'); overlay.classList.remove('show'); }

    if (toggle)  toggle.addEventListener('click', function () { sidebar.classList.contains('show') ? close() : open(); });
    if (overlay) overlay.addEventListener('click', close);
})();
</script>
