<?php
/**
 * StockWise — Inventory Management & Movement History
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Inventory Management';
requireLogin();
requireRole(['admin', 'manager']);

$action = $_POST['action'] ?? '';

// ── Handle Stock Movements (Stock In / Out / Adjust) ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    validateCsrf();

    $productId = (int)($_POST['product_id'] ?? 0);
    $qty       = (int)($_POST['quantity'] ?? 0);
    $reason    = trim($_POST['reason'] ?? '');
    $notes     = trim($_POST['notes'] ?? '');
    $userId    = $_SESSION['user']['id'];

    if ($productId <= 0) {
        setFlash('danger', 'Please select a valid product.');
        header('Location: inventory.php');
        exit;
    }

    // Lock product row to prevent race conditions
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT id, name, sku, stock_quantity FROM products WHERE id = ? FOR UPDATE');
        $st->execute([$productId]);
        $prod = $st->fetch();

        if (!$prod) {
            throw new Exception('Product not found.');
        }

        $stockBefore = (int)$prod['stock_quantity'];

        if ($action === 'stock_in') {
            if ($qty <= 0) throw new Exception('Stock-in quantity must be at least 1.');
            $stockAfter = $stockBefore + $qty;
            $type = 'stock_in';
            $defaultReason = 'Purchase Restock';
        } elseif ($action === 'stock_out') {
            if ($qty <= 0) throw new Exception('Stock-out quantity must be at least 1.');
            if ($qty > $stockBefore) {
                throw new Exception("Cannot deduct {$qty} units. Current stock is only {$stockBefore}.");
            }
            $stockAfter = $stockBefore - $qty;
            $type = 'stock_out';
            $defaultReason = 'Damaged / Write-off';
        } elseif ($action === 'adjustment') {
            // New stock level directly set
            $newStock = (int)($_POST['new_stock'] ?? 0);
            if ($newStock < 0) throw new Exception('Stock level cannot be negative.');
            $diff = $newStock - $stockBefore;
            if ($diff === 0) throw new Exception('No stock change detected.');
            $qty = abs($diff);
            $stockAfter = $newStock;
            $type = 'adjustment';
            $defaultReason = 'Inventory Audit Adjustment';
        } else {
            throw new Exception('Invalid action.');
        }

        $finalReason = $reason !== '' ? $reason : $defaultReason;

        // Update product stock
        $up = $pdo->prepare('UPDATE products SET stock_quantity = ?, updated_at = NOW() WHERE id = ?');
        $up->execute([$stockAfter, $productId]);

        // Insert movement
        $mv = $pdo->prepare("
            INSERT INTO inventory_movements (
                product_id, movement_type, quantity, qty_before, qty_after, reason, notes, user_id, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $mv->execute([$productId, $type, $qty, $stockBefore, $stockAfter, $finalReason, $notes, $userId]);

        logAudit($pdo, 'inventory_' . $type, "{$prod['sku']} ({$prod['name']}): {$stockBefore} -> {$stockAfter} ({$finalReason})");

        $pdo->commit();
        setFlash('success', "Stock updated successfully for <strong>{$prod['name']}</strong>! New level: <strong>{$stockAfter}</strong> units.");
    } catch (Exception $e) {
        $pdo->rollBack();
        setFlash('danger', $e->getMessage());
    }

    header('Location: inventory.php');
    exit;
}

// ── Summary Stats ───────────────────────────────────────────────────
$stats = [
    'movements_today' => (int)$pdo->query("SELECT COUNT(*) FROM inventory_movements WHERE DATE(created_at) = CURDATE()")->fetchColumn(),
    'low_stock'       => (int)$pdo->query("SELECT COUNT(*) FROM products WHERE stock_quantity > 0 AND stock_quantity <= min_stock_level")->fetchColumn(),
    'out_of_stock'    => (int)$pdo->query("SELECT COUNT(*) FROM products WHERE stock_quantity = 0")->fetchColumn(),
    'total_value'     => (float)$pdo->query("SELECT COALESCE(SUM(purchase_price * stock_quantity), 0) FROM products")->fetchColumn(),
];

// ── Query Movements History ─────────────────────────────────────────
$filterProduct = (int)($_GET['product_id'] ?? 0);
$filterType    = trim($_GET['type'] ?? 'all');
$filterDateFrom= trim($_GET['date_from'] ?? '');
$filterDateTo  = trim($_GET['date_to'] ?? '');
$filterLowStock= isset($_GET['filter']) && $_GET['filter'] === 'low_stock';

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($filterProduct > 0) {
    $where[] = 'm.product_id = :p_id';
    $params[':p_id'] = $filterProduct;
}

if ($filterType !== '' && $filterType !== 'all') {
    $where[] = 'm.movement_type = :m_type';
    $params[':m_type'] = $filterType;
}

if ($filterDateFrom !== '') {
    $where[] = 'DATE(m.created_at) >= :d_from';
    $params[':d_from'] = $filterDateFrom;
}

if ($filterDateTo !== '') {
    $where[] = 'DATE(m.created_at) <= :d_to';
    $params[':d_to'] = $filterDateTo;
}

$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM inventory_movements m WHERE {$whereSql}");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

$querySql = "
    SELECT 
        m.*,
        p.name AS product_name,
        p.sku AS product_sku,
        COALESCE(u.full_name, u.username, 'System') AS user_name
    FROM inventory_movements m
    JOIN products p ON m.product_id = p.id
    LEFT JOIN users u ON m.user_id = u.id
    WHERE {$whereSql}
    ORDER BY m.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmt = $pdo->prepare($querySql);
$stmt->execute($params);
$movements = $stmt->fetchAll();

// Product options for modals & filters
$allProducts = $pdo->query('SELECT id, name, sku, stock_quantity, min_stock_level FROM products ORDER BY name ASC')->fetchAll();

// Low stock items list for dedicated alert view
$lowStockItems = [];
if ($filterLowStock) {
    $lowStockItems = $pdo->query("
        SELECT p.*, COALESCE(c.name, 'Uncategorized') as category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.stock_quantity <= p.min_stock_level
        ORDER BY p.stock_quantity ASC
    ")->fetchAll();
}

$csrfToken = getCsrfToken();
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h2 class="fw-bold mb-1"><i class="bi bi-clipboard-data me-2 text-primary"></i>Inventory Tracking</h2>
        <p class="text-muted mb-0">Monitor stock movements, record restocks, write-offs, and physical audits.</p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#stockInModal">
            <i class="bi bi-box-arrow-in-down me-1"></i> Stock In
        </button>
        <button class="btn btn-warning text-dark" data-bs-toggle="modal" data-bs-target="#stockOutModal">
            <i class="bi bi-box-arrow-up me-1"></i> Stock Out
        </button>
        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#stockAdjustModal">
            <i class="bi bi-sliders me-1"></i> Stock Adjustment
        </button>
    </div>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-primary-subtle text-primary p-3 d-flex align-items-center justify-content-center" style="width: 52px; height: 52px;">
                    <i class="bi bi-arrow-left-right fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Movements Today</div>
                    <div class="fs-4 fw-bold text-dark"><?= number_format($stats['movements_today']) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-warning-subtle text-warning p-3 d-flex align-items-center justify-content-center" style="width: 52px; height: 52px;">
                    <i class="bi bi-exclamation-triangle fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Low-Stock Alerts</div>
                    <div class="fs-4 fw-bold text-warning"><?= number_format($stats['low_stock']) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-danger-subtle text-danger p-3 d-flex align-items-center justify-content-center" style="width: 52px; height: 52px;">
                    <i class="bi bi-x-circle fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Out of Stock</div>
                    <div class="fs-4 fw-bold text-danger"><?= number_format($stats['out_of_stock']) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-success-subtle text-success p-3 d-flex align-items-center justify-content-center" style="width: 52px; height: 52px;">
                    <i class="bi bi-cash-stack fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Inventory Valuation</div>
                    <div class="fs-4 fw-bold text-success">$<?= number_format($stats['total_value'], 2) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($filterLowStock): ?>
<!-- Low Stock Warning Banner -->
<div class="card border-0 shadow-sm mb-4 border-start border-4 border-warning">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h5 class="fw-bold text-warning mb-0"><i class="bi bi-bell-fill me-2"></i>Critical Low-Stock & Out-of-Stock Products (<?= count($lowStockItems) ?>)</h5>
            <a href="inventory.php" class="btn btn-sm btn-outline-secondary">Show Movement History</a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>SKU</th>
                        <th>Product</th>
                        <th>Category</th>
                        <th class="text-center">Current Stock</th>
                        <th class="text-center">Min Level</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lowStockItems as $li): ?>
                        <tr>
                            <td><span class="badge bg-light text-dark font-monospace border"><?= htmlspecialchars($li['sku']) ?></span></td>
                            <td class="fw-semibold"><?= htmlspecialchars($li['name']) ?></td>
                            <td><?= htmlspecialchars($li['category_name']) ?></td>
                            <td class="text-center">
                                <?php if ($li['stock_quantity'] == 0): ?>
                                    <span class="badge bg-danger rounded-pill">0 (Out of stock)</span>
                                <?php else: ?>
                                    <span class="badge bg-warning text-dark rounded-pill"><?= $li['stock_quantity'] ?> units</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center text-muted"><?= $li['min_stock_level'] ?></td>
                            <td class="text-end">
                                <a href="stock_in.php?product_id=<?= $li['id'] ?>" class="btn btn-sm btn-success">
                                    <i class="bi bi-plus-lg me-1"></i> Restock
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Filters Form -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3">
        <form method="GET" action="inventory.php" class="row g-2 align-items-center">
            <div class="col-md-3">
                <select name="product_id" class="form-select">
                    <option value="0">All Products</option>
                    <?php foreach ($allProducts as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $filterProduct === (int)$p['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['sku']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="type" class="form-select">
                    <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>All Movements</option>
                    <option value="stock_in" <?= $filterType === 'stock_in' ? 'selected' : '' ?>>Stock In</option>
                    <option value="stock_out" <?= $filterType === 'stock_out' ? 'selected' : '' ?>>Stock Out</option>
                    <option value="adjustment" <?= $filterType === 'adjustment' ? 'selected' : '' ?>>Adjustment</option>
                    <option value="sale" <?= $filterType === 'sale' ? 'selected' : '' ?>>Sale Deduction</option>
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($filterDateFrom) ?>" placeholder="From Date">
            </div>
            <div class="col-md-2">
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($filterDateTo) ?>" placeholder="To Date">
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100">Filter History</button>
                <?php if ($filterProduct > 0 || $filterType !== 'all' || $filterDateFrom !== '' || $filterDateTo !== '' || $filterLowStock): ?>
                    <a href="inventory.php" class="btn btn-outline-secondary" title="Reset"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Movement History Table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Date & Time</th>
                        <th>Product</th>
                        <th>Movement Type</th>
                        <th class="text-center">Quantity</th>
                        <th class="text-center">Stock Path</th>
                        <th>Reason / Details</th>
                        <th>User</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($movements)): ?>
                        <tr>
                            <td colspan="7" class="text-center py-5 text-muted">
                                <i class="bi bi-journal-x fs-1 d-block mb-2 text-secondary"></i>
                                No inventory movements recorded matching your filters.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($movements as $m): ?>
                            <tr>
                                <td class="ps-3 text-muted small font-monospace">
                                    <?= date('M d, Y h:i A', strtotime($m['created_at'])) ?>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($m['product_name']) ?></div>
                                    <small class="text-muted font-monospace"><?= htmlspecialchars($m['product_sku']) ?></small>
                                </td>
                                <td>
                                    <?php if ($m['movement_type'] === 'stock_in'): ?>
                                        <span class="badge bg-success-subtle text-success-emphasis rounded-pill px-2.5 py-1">
                                            <i class="bi bi-box-arrow-in-down me-1"></i> Stock In
                                        </span>
                                    <?php elseif ($m['movement_type'] === 'stock_out'): ?>
                                        <span class="badge bg-warning-subtle text-warning-emphasis rounded-pill px-2.5 py-1">
                                            <i class="bi bi-box-arrow-up me-1"></i> Stock Out
                                        </span>
                                    <?php elseif ($m['movement_type'] === 'adjustment'): ?>
                                        <span class="badge bg-primary-subtle text-primary-emphasis rounded-pill px-2.5 py-1">
                                            <i class="bi bi-sliders me-1"></i> Adjustment
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary-emphasis rounded-pill px-2.5 py-1">
                                            <i class="bi bi-cart me-1"></i> Sale Deduction
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center fw-bold font-monospace">
                                    <?= ($m['movement_type'] === 'stock_out' || $m['movement_type'] === 'sale') ? '-' : '+' ?><?= abs($m['quantity']) ?>
                                </td>
                                <td class="text-center font-monospace small">
                                    <span class="text-muted"><?= $m['qty_before'] ?></span>
                                    <i class="bi bi-arrow-right mx-1 text-secondary"></i>
                                    <span class="fw-bold text-dark"><?= $m['qty_after'] ?></span>
                                </td>
                                <td>
                                    <div class="text-dark small"><?= htmlspecialchars($m['reason'] ?? 'Standard Movement') ?></div>
                                    <?php if (!empty($m['notes'])): ?>
                                        <small class="text-muted d-block"><?= htmlspecialchars($m['notes']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small class="text-muted"><i class="bi bi-person me-1"></i><?= htmlspecialchars($m['user_name']) ?></small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="d-flex justify-content-between align-items-center p-3 border-top">
                <small class="text-muted">Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalRows) ?> of <?= $totalRows ?> records</small>
                <nav>
                    <ul class="pagination pagination-sm mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">Previous</a>
                        </li>
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?= $page === $i ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next</a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Stock In -->
<div class="modal fade" id="stockInModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="inventory.php">
                <input type="hidden" name="action" value="stock_in">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-success"><i class="bi bi-box-arrow-in-down me-2"></i>Record Stock In</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Product <span class="text-danger">*</span></label>
                        <select name="product_id" class="form-select" required>
                            <option value="">Choose product to receive...</option>
                            <?php foreach ($allProducts as $p): ?>
                                <option value="<?= $p['id'] ?>">
                                    <?= htmlspecialchars($p['name']) ?> (Current: <?= $p['stock_quantity'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Quantity to Add <span class="text-danger">*</span></label>
                        <input type="number" min="1" name="quantity" class="form-control font-monospace" required placeholder="Units received">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Reason</label>
                        <select name="reason" class="form-select">
                            <option value="Supplier Purchase Order">Supplier Purchase Order</option>
                            <option value="Customer Return">Customer Return</option>
                            <option value="Found Unaccounted Stock">Found Unaccounted Stock</option>
                            <option value="Other Inflow">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notes / Reference (Optional)</label>
                        <input type="text" name="notes" class="form-control" placeholder="e.g. PO #10842">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success px-4">Confirm Stock In</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Stock Out -->
<div class="modal fade" id="stockOutModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="inventory.php">
                <input type="hidden" name="action" value="stock_out">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-warning"><i class="bi bi-box-arrow-up me-2"></i>Record Manual Stock Out</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Product <span class="text-danger">*</span></label>
                        <select name="product_id" class="form-select" required>
                            <option value="">Choose product to deduct...</option>
                            <?php foreach ($allProducts as $p): ?>
                                <option value="<?= $p['id'] ?>">
                                    <?= htmlspecialchars($p['name']) ?> (Current: <?= $p['stock_quantity'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Quantity to Deduct <span class="text-danger">*</span></label>
                        <input type="number" min="1" name="quantity" class="form-control font-monospace" required placeholder="Units removed">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Reason <span class="text-danger">*</span></label>
                        <select name="reason" class="form-select" required>
                            <option value="Damaged Goods Write-off">Damaged Goods Write-off</option>
                            <option value="Expired Product">Expired Product</option>
                            <option value="Theft or Loss">Theft / Unexplained Loss</option>
                            <option value="Internal Company Use">Internal Company Use</option>
                            <option value="Return to Supplier">Return to Supplier</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notes / Explanation</label>
                        <input type="text" name="notes" class="form-control" placeholder="Details regarding deduction...">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning px-4">Confirm Stock Out</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Stock Adjustment -->
<div class="modal fade" id="stockAdjustModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="inventory.php">
                <input type="hidden" name="action" value="adjustment">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold text-primary"><i class="bi bi-sliders me-2"></i>Stock Adjustment (Audit)</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Product <span class="text-danger">*</span></label>
                        <select name="product_id" class="form-select" required>
                            <option value="">Select product to adjust...</option>
                            <?php foreach ($allProducts as $p): ?>
                                <option value="<?= $p['id'] ?>">
                                    <?= htmlspecialchars($p['name']) ?> (Current: <?= $p['stock_quantity'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Actual Counted Physical Stock <span class="text-danger">*</span></label>
                        <input type="number" min="0" name="new_stock" class="form-control font-monospace" required placeholder="Exact verified stock count">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Audit Reason <span class="text-danger">*</span></label>
                        <input type="text" name="reason" class="form-control" required placeholder="e.g. Monthly Physical Inventory Count">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notes</label>
                        <input type="text" name="notes" class="form-control" placeholder="Observations during count...">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Apply Adjustment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
