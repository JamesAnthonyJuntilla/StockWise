<?php
/**
 * StockWise — Dedicated Stock In Form
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Stock In';
requireLogin();
requireRole(['admin', 'manager']);

$preselectedId = (int)($_GET['product_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $productId = (int)($_POST['product_id'] ?? 0);
    $qty       = (int)($_POST['quantity'] ?? 0);
    $reason    = trim($_POST['reason'] ?? 'Supplier Restock');
    $notes     = trim($_POST['notes'] ?? '');
    $userId    = $_SESSION['user']['id'];

    if ($productId <= 0 || $qty <= 0) {
        setFlash('danger', 'Please select a valid product and enter a positive quantity.');
    } else {
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT id, name, sku, stock_quantity FROM products WHERE id = ? FOR UPDATE');
            $st->execute([$productId]);
            $prod = $st->fetch();

            if (!$prod) {
                throw new Exception('Product not found.');
            }

            $before = (int)$prod['stock_quantity'];
            $after  = $before + $qty;

            $up = $pdo->prepare('UPDATE products SET stock_quantity = ?, updated_at = NOW() WHERE id = ?');
            $up->execute([$after, $productId]);

            $mv = $pdo->prepare("
                INSERT INTO inventory_movements (
                    product_id, movement_type, quantity, qty_before, qty_after, reason, notes, user_id, created_at
                ) VALUES (?, 'stock_in', ?, ?, ?, ?, ?, ?, NOW())
            ");
            $mv->execute([$productId, $qty, $before, $after, $reason, $notes, $userId]);

            logAudit($pdo, 'inventory_stock_in', "Received {$qty} units of {$prod['sku']} ({$prod['name']}). New stock: {$after}");

            $pdo->commit();
            setFlash('success', "Successfully added <strong>{$qty}</strong> units to <strong>{$prod['name']}</strong>! Current stock: <strong>{$after}</strong>");
            header('Location: inventory.php');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('danger', $e->getMessage());
        }
    }
}

$products = $pdo->query('SELECT id, name, sku, stock_quantity, min_stock_level FROM products ORDER BY name ASC')->fetchAll();
$csrfToken = getCsrfToken();
require_once __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8 col-xl-6">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <div>
                <h2 class="fw-bold mb-1"><i class="bi bi-box-arrow-in-down me-2 text-success"></i>Stock In</h2>
                <p class="text-muted mb-0">Record incoming inventory from supplier purchase orders or returns.</p>
            </div>
            <a href="inventory.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i> Back
            </a>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body p-4">
                <form method="POST" action="stock_in.php">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Target Product <span class="text-danger">*</span></label>
                        <select name="product_id" id="productSelect" class="form-select form-select-lg" required>
                            <option value="">Select product to restock...</option>
                            <?php foreach ($products as $p): ?>
                                <option value="<?= $p['id'] ?>" data-stock="<?= $p['stock_quantity'] ?>" <?= $preselectedId === (int)$p['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['sku']) ?>) — Current: <?= $p['stock_quantity'] ?> units
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Quantity Received <span class="text-danger">*</span></label>
                            <input type="number" min="1" name="quantity" class="form-control form-control-lg font-monospace" required placeholder="Units added">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Movement Reason</label>
                            <select name="reason" class="form-select form-select-lg">
                                <option value="Supplier Purchase Order">Supplier Purchase Order</option>
                                <option value="Customer Return">Customer Return</option>
                                <option value="Found Unaccounted Stock">Found Unaccounted Stock</option>
                                <option value="Other Inflow">Other Inflow</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Notes / Purchase Order # (Optional)</label>
                        <input type="text" name="notes" class="form-control" placeholder="e.g. Received from Supplier PO-2026-88">
                    </div>

                    <button type="submit" class="btn btn-success btn-lg w-100 fw-semibold">
                        <i class="bi bi-check-circle me-1"></i> Confirm & Update Stock
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
