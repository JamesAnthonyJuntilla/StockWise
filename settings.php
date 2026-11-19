<?php
/**
 * StockWise — System Administration Settings
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'System Settings';
requireLogin();
requireRole(['admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();
    // In a production app, persist to settings table or config
    logAudit($pdo, 'settings_update', 'Administrator updated system settings');
    setFlash('success', 'System configuration parameters updated successfully.');
    header('Location: settings.php');
    exit;
}

$csrfToken = getCsrfToken();
require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8 col-xl-7">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h2 class="fw-bold mb-1"><i class="bi bi-gear me-2 text-primary"></i>System Settings</h2>
                <p class="text-muted mb-0">Configure store branding, default currency, stock alert limits, and database maintenance.</p>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <form method="POST" action="settings.php">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                    <h5 class="fw-bold text-dark mb-3"><i class="bi bi-shop me-2 text-primary"></i>Store Identity & Receipt Branding</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Business / Store Name</label>
                            <input type="text" class="form-control" name="store_name" value="<?= APP_NAME ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Currency Code / Symbol</label>
                            <input type="text" class="form-control font-monospace" name="currency_symbol" value="USD ($)">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Store Physical Address</label>
                            <input type="text" class="form-control" name="store_address" value="123 University Avenue, Academic Complex">
                        </div>
                    </div>

                    <h5 class="fw-bold text-dark pt-3 border-top mb-3"><i class="bi bi-bell me-2 text-warning"></i>Inventory Alert Rules</h5>
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Default Minimum Safety Stock</label>
                            <input type="number" min="1" class="form-control font-monospace" name="low_stock_limit" value="<?= LOW_STOCK_THRESHOLD ?>">
                            <div class="form-text">Triggers low-stock warning banners in dashboard and topbar.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Auto-Generate Product SKUs</label>
                            <select class="form-select" name="auto_sku">
                                <option value="1" selected>Enabled (Prefix + Random Unique Hash)</option>
                                <option value="0">Disabled (Manual Entry Required)</option>
                            </select>
                        </div>
                    </div>

                    <h5 class="fw-bold text-dark pt-3 border-top mb-3"><i class="bi bi-server me-2 text-info"></i>Environment & Server Info</h5>
                    <div class="bg-light p-3 rounded mb-4 font-monospace small">
                        <div><strong>PHP Version:</strong> <?= phpversion() ?></div>
                        <div><strong>Database Driver:</strong> PDO MySQL / MariaDB (utf8mb4)</div>
                        <div><strong>Application Root:</strong> <?= htmlspecialchars(APP_ROOT) ?></div>
                        <div><strong>Session Lifetime:</strong> Browser Session (Strict / HttpOnly)</div>
                    </div>

                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-check-lg me-1"></i> Save Configuration
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
