<?php
/**
 * StockWise — Business Reports & Analytics Hub
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Reports & Analytics';
requireLogin();
requireRole(['admin', 'manager']);

$reportType = $_GET['type'] ?? 'sales';
$dateFrom   = $_GET['date_from'] ?? date('Y-m-01'); // First of current month
$dateTo     = $_GET['date_to'] ?? date('Y-m-d');
$exportCsv  = isset($_GET['export']) && $_GET['export'] === 'csv';

// ── Export CSV Handler ──────────────────────────────────────────────
if ($exportCsv) {
    $filename = "stockwise_{$reportType}_report_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');

    if ($reportType === 'sales') {
        fputcsv($output, ['Receipt No', 'Date', 'Cashier', 'Subtotal', 'Discount', 'Total Amount', 'Payment Method']);
        $stmt = $pdo->prepare("
            SELECT s.receipt_no, s.created_at, COALESCE(u.full_name, u.username) AS cashier,
                   s.subtotal, s.discount_amount, s.total_amount, 'Cash' as method
            FROM sales s
            LEFT JOIN users u ON s.user_id = u.id
            WHERE DATE(s.created_at) BETWEEN ? AND ?
            ORDER BY s.created_at DESC
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
    } elseif ($reportType === 'inventory') {
        fputcsv($output, ['SKU', 'Product Name', 'Category', 'Stock Qty', 'Cost Price', 'Selling Price', 'Inventory Cost Value', 'Retail Value']);
        $stmt = $pdo->query("
            SELECT p.sku, p.name, COALESCE(c.name, 'Uncategorized'), p.stock_quantity,
                   p.purchase_price, p.selling_price,
                   (p.stock_quantity * p.purchase_price) as cost_val,
                   (p.stock_quantity * p.selling_price) as retail_val
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            ORDER BY p.name ASC
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
    } elseif ($reportType === 'low_stock') {
        fputcsv($output, ['SKU', 'Product Name', 'Current Stock', 'Min Threshold', 'Deficit', 'Suggested Reorder']);
        $stmt = $pdo->query("
            SELECT sku, name, stock_quantity, min_stock_level,
                   (min_stock_level - stock_quantity) AS deficit,
                   GREATEST((min_stock_level * 2) - stock_quantity, 10) AS suggested
            FROM products
            WHERE stock_quantity <= min_stock_level
            ORDER BY stock_quantity ASC
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
    } elseif ($reportType === 'out_of_stock') {
        fputcsv($output, ['SKU', 'Product Name', 'Category', 'Supplier', 'Min Threshold', 'Suggested Reorder']);
        $stmt = $pdo->query("
            SELECT p.sku, p.name, COALESCE(c.name, 'Uncategorized'), COALESCE(s.company_name, 'None'),
                   p.min_stock_level, GREATEST(p.min_stock_level * 2, 10) AS suggested
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN suppliers s ON p.supplier_id = s.id
            WHERE p.stock_quantity <= 0
            ORDER BY p.name ASC
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
    } elseif ($reportType === 'performance') {
        fputcsv($output, ['Product Name', 'SKU', 'Units Sold', 'Total Revenue Generated']);
        $stmt = $pdo->prepare("
            SELECT si.product_name, p.sku, SUM(si.quantity) as units_sold, SUM(si.subtotal) as total_rev
            FROM sale_items si
            JOIN sales s ON si.sale_id = s.id
            LEFT JOIN products p ON si.product_id = p.id
            WHERE DATE(s.created_at) BETWEEN ? AND ?
            GROUP BY si.product_id, si.product_name, p.sku
            ORDER BY total_rev DESC
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
    } elseif ($reportType === 'stock_movement') {
        fputcsv($output, ['Date', 'Product SKU', 'Product Name', 'Type', 'Quantity', 'Qty Before', 'Qty After', 'Reason', 'User', 'Notes']);
        $stmt = $pdo->prepare("
            SELECT m.created_at, p.sku, p.name, m.movement_type, m.quantity,
                   m.qty_before, m.qty_after, m.reason, COALESCE(u.full_name, u.username, 'System'), m.notes
            FROM inventory_movements m
            JOIN products p ON m.product_id = p.id
            LEFT JOIN users u ON m.user_id = u.id
            WHERE DATE(m.created_at) BETWEEN ? AND ?
            ORDER BY m.created_at DESC
        ");
        $stmt->execute([$dateFrom, $dateTo]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
    }

    fclose($output);
    exit;
}

// ── Query Specific Data ─────────────────────────────────────────────
if ($reportType === 'sales') {
    // Daily totals for Chart.js
    $chartStmt = $pdo->prepare("
        SELECT DATE(created_at) as sale_date, SUM(total_amount) as daily_total, COUNT(id) as tx_count
        FROM sales
        WHERE DATE(created_at) BETWEEN ? AND ?
        GROUP BY DATE(created_at)
        ORDER BY sale_date ASC
    ");
    $chartStmt->execute([$dateFrom, $dateTo]);
    $chartRows = $chartStmt->fetchAll();

    $chartLabels = [];
    $chartValues = [];
    foreach ($chartRows as $cr) {
        $chartLabels[] = date('M d', strtotime($cr['sale_date']));
        $chartValues[] = (float)$cr['daily_total'];
    }

    // Detail table
    $tableStmt = $pdo->prepare("
        SELECT s.*, COALESCE(u.full_name, u.username) AS cashier_name,
               (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id) as item_count
        FROM sales s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE DATE(s.created_at) BETWEEN ? AND ?
        ORDER BY s.created_at DESC
        LIMIT 100
    ");
    $tableStmt->execute([$dateFrom, $dateTo]);
    $salesList = $tableStmt->fetchAll();

    // Summary stats
    $salesMetrics = $pdo->prepare("
        SELECT COUNT(id) as total_orders, COALESCE(SUM(total_amount), 0) as total_revenue,
               COALESCE(AVG(total_amount), 0) as avg_order, COALESCE(MAX(total_amount), 0) as max_order
        FROM sales
        WHERE DATE(created_at) BETWEEN ? AND ?
    ");
    $salesMetrics->execute([$dateFrom, $dateTo]);
    $metric = $salesMetrics->fetch();
} elseif ($reportType === 'inventory') {
    $invStmt = $pdo->query("
        SELECT p.*, COALESCE(c.name, 'Uncategorized') as category_name,
               (p.stock_quantity * p.purchase_price) as cost_valuation,
               (p.stock_quantity * p.selling_price) as retail_valuation
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        ORDER BY cost_valuation DESC
    ");
    $invList = $invStmt->fetchAll();

    $invSummary = $pdo->query("
        SELECT 
            COUNT(id) as total_skus,
            COALESCE(SUM(stock_quantity), 0) as total_units,
            COALESCE(SUM(stock_quantity * purchase_price), 0) as total_cost,
            COALESCE(SUM(stock_quantity * selling_price), 0) as total_retail
        FROM products
    ")->fetch();
} elseif ($reportType === 'low_stock') {
    $lowStmt = $pdo->query("
        SELECT p.*, COALESCE(c.name, 'Uncategorized') as category_name,
               (p.min_stock_level - p.stock_quantity) as deficit,
               GREATEST((p.min_stock_level * 2) - p.stock_quantity, 10) as suggested_reorder
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE p.stock_quantity <= p.min_stock_level
        ORDER BY p.stock_quantity ASC
    ");
    $lowList = $lowStmt->fetchAll();
} elseif ($reportType === 'out_of_stock') {
    $outStmt = $pdo->query("
        SELECT p.*, COALESCE(c.name, 'Uncategorized') as category_name,
               COALESCE(s.company_name, 'No Supplier') as supplier_name,
               p.min_stock_level as deficit,
               GREATEST(p.min_stock_level * 2, 10) as suggested_reorder
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        WHERE p.stock_quantity <= 0
        ORDER BY p.name ASC
    ");
    $outList = $outStmt->fetchAll();
} elseif ($reportType === 'performance') {
    $perfStmt = $pdo->prepare("
        SELECT 
            si.product_name,
            p.sku,
            COALESCE(c.name, 'Uncategorized') as category_name,
            SUM(si.quantity) as units_sold,
            SUM(si.subtotal) as total_revenue,
            p.stock_quantity as current_stock
        FROM sale_items si
        JOIN sales s ON si.sale_id = s.id
        LEFT JOIN products p ON si.product_id = p.id
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE DATE(s.created_at) BETWEEN ? AND ?
        GROUP BY si.product_id, si.product_name, p.sku, c.name, p.stock_quantity
        ORDER BY total_revenue DESC
        LIMIT 50
    ");
    $perfStmt->execute([$dateFrom, $dateTo]);
    $perfList = $perfStmt->fetchAll();
} elseif ($reportType === 'stock_movement') {
    $movStmt = $pdo->prepare("
        SELECT m.*, p.name as product_name, p.sku as product_sku,
               COALESCE(u.full_name, u.username, 'System') as user_name
        FROM inventory_movements m
        JOIN products p ON m.product_id = p.id
        LEFT JOIN users u ON m.user_id = u.id
        WHERE DATE(m.created_at) BETWEEN ? AND ?
        ORDER BY m.created_at DESC
        LIMIT 250
    ");
    $movStmt->execute([$dateFrom, $dateTo]);
    $movList = $movStmt->fetchAll();

    $movSummary = $pdo->prepare("
        SELECT 
            COUNT(m.id) as total_moves,
            SUM(CASE WHEN m.movement_type = 'stock_in' THEN 1 ELSE 0 END) as in_moves,
            SUM(CASE WHEN m.movement_type = 'stock_out' THEN 1 ELSE 0 END) as out_moves,
            SUM(CASE WHEN m.movement_type = 'sale' THEN 1 ELSE 0 END) as sale_moves,
            SUM(CASE WHEN m.movement_type = 'adjustment' THEN 1 ELSE 0 END) as adj_moves
        FROM inventory_movements m
        WHERE DATE(m.created_at) BETWEEN ? AND ?
    ");
    $movSummary->execute([$dateFrom, $dateTo]);
    $movStats = $movSummary->fetch();
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h2 class="fw-bold mb-1"><i class="bi bi-bar-chart-line me-2 text-primary"></i>Reports & Analytics</h2>
        <p class="text-muted mb-0">Generate data-driven performance audits, inventory valuation, and sales metrics.</p>
    </div>
    <div>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-outline-success">
            <i class="bi bi-file-earmark-excel me-1"></i> Export to CSV
        </a>
    </div>
</div>

<!-- Report Navigation Tabs -->
<ul class="nav nav-pills mb-4 gap-2 border-bottom pb-3">
    <li class="nav-item">
        <a class="nav-link <?= $reportType === 'sales' ? 'active' : '' ?>" href="reports.php?type=sales">
            <i class="bi bi-cash-stack me-1"></i> Sales Revenue
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $reportType === 'inventory' ? 'active' : '' ?>" href="reports.php?type=inventory">
            <i class="bi bi-box-seam me-1"></i> Inventory Valuation
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $reportType === 'low_stock' ? 'active' : '' ?>" href="reports.php?type=low_stock">
            <i class="bi bi-exclamation-triangle me-1"></i> Low Stock Audit
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $reportType === 'out_of_stock' ? 'active' : '' ?>" href="reports.php?type=out_of_stock">
            <i class="bi bi-x-circle me-1"></i> Out of Stock
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $reportType === 'performance' ? 'active' : '' ?>" href="reports.php?type=performance">
            <i class="bi bi-trophy me-1"></i> Product Performance
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $reportType === 'stock_movement' ? 'active' : '' ?>" href="reports.php?type=stock_movement">
            <i class="bi bi-arrow-left-right me-1"></i> Stock Movement
        </a>
    </li>
</ul>

<!-- Date Filter Form (For date-sensitive reports) -->
<?php if ($reportType === 'sales' || $reportType === 'performance' || $reportType === 'stock_movement'): ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3">
        <form method="GET" action="reports.php" class="row g-2 align-items-center">
            <input type="hidden" name="type" value="<?= htmlspecialchars($reportType) ?>">
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">Start Date</label>
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">End Date</label>
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
            </div>
            <div class="col-md-4 d-flex align-items-end gap-2" style="margin-top: auto;">
                <button type="submit" class="btn btn-primary flex-fill">Apply Date Filter</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ─── REPORT 1: SALES REVENUE ────────────────────────────────────── -->
<?php if ($reportType === 'sales'): ?>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3">
            <div class="text-muted small">Total Revenue</div>
            <div class="fs-4 fw-bold text-success font-monospace">$<?= number_format($metric['total_revenue'], 2) ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3">
            <div class="text-muted small">Transactions Count</div>
            <div class="fs-4 fw-bold text-primary font-monospace"><?= number_format($metric['total_orders']) ?> orders</div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3">
            <div class="text-muted small">Average Ticket Size</div>
            <div class="fs-4 fw-bold text-dark font-monospace">$<?= number_format($metric['avg_order'], 2) ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3">
            <div class="text-muted small">Highest Single Ticket</div>
            <div class="fs-4 fw-bold text-info font-monospace">$<?= number_format($metric['max_order'], 2) ?></div>
        </div>
    </div>
</div>

<!-- Sales Revenue Chart -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3 border-0">
        <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-graph-up me-2 text-primary"></i>Revenue Trend Over Time</h5>
    </div>
    <div class="card-body p-3">
        <canvas id="salesTrendChart" height="90"></canvas>
    </div>
</div>

<!-- Sales Table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Receipt No</th>
                        <th>Date & Time</th>
                        <th>Cashier</th>
                        <th class="text-center">Items</th>
                        <th class="text-end">Subtotal</th>
                        <th class="text-end">Discount</th>
                        <th class="text-end pe-3">Net Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($salesList)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">No sales recorded during this date window.</td></tr>
                    <?php else: ?>
                        <?php foreach ($salesList as $s): ?>
                            <tr>
                                <td class="ps-3 font-monospace fw-semibold text-dark"><?= htmlspecialchars($s['receipt_no']) ?></td>
                                <td class="small text-muted font-monospace"><?= date('M d, Y h:i A', strtotime($s['created_at'])) ?></td>
                                <td><?= htmlspecialchars($s['cashier_name']) ?></td>
                                <td class="text-center"><?= $s['item_count'] ?></td>
                                <td class="text-end font-monospace text-muted">$<?= number_format($s['subtotal'], 2) ?></td>
                                <td class="text-end font-monospace text-danger"><?= $s['discount_amount'] > 0 ? '-$' . number_format($s['discount_amount'], 2) : '$0.00' ?></td>
                                <td class="text-end pe-3 font-monospace fw-bold text-primary">$<?= number_format($s['total_amount'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const ctx = document.getElementById('salesTrendChart').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($chartLabels) ?>,
            datasets: [{
                label: 'Daily Revenue ($)',
                data: <?= json_encode($chartValues) ?>,
                backgroundColor: 'rgba(13, 110, 253, 0.75)',
                borderColor: '#0d6efd',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function (context) {
                            return '$' + context.parsed.y.toLocaleString('en-US', { minimumFractionDigits: 2 });
                        }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function (val) { return '$' + val.toLocaleString(); }
                    }
                }
            }
        }
    });
});
</script>

<!-- ─── REPORT 2: INVENTORY VALUATION ──────────────────────────────── -->
<?php elseif ($reportType === 'inventory'): ?>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3">
            <div class="text-muted small">Active Products (SKUs)</div>
            <div class="fs-4 fw-bold text-dark font-monospace"><?= number_format($invSummary['total_skus']) ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3">
            <div class="text-muted small">Total Physical Units</div>
            <div class="fs-4 fw-bold text-primary font-monospace"><?= number_format($invSummary['total_units']) ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3">
            <div class="text-muted small">Total Cost Asset Value</div>
            <div class="fs-4 fw-bold text-success font-monospace">$<?= number_format($invSummary['total_cost'], 2) ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3">
            <div class="text-muted small">Estimated Potential Retail</div>
            <div class="fs-4 fw-bold text-info font-monospace">$<?= number_format($invSummary['total_retail'], 2) ?></div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">SKU</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th class="text-center">Stock Units</th>
                        <th class="text-end">Unit Cost</th>
                        <th class="text-end">Unit Retail</th>
                        <th class="text-end">Total Cost Valuation</th>
                        <th class="text-end pe-3">Potential Retail</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invList as $p): ?>
                        <tr>
                            <td class="ps-3 font-monospace small"><span class="badge bg-light text-dark border"><?= htmlspecialchars($p['sku']) ?></span></td>
                            <td class="fw-semibold"><?= htmlspecialchars($p['name']) ?></td>
                            <td><?= htmlspecialchars($p['category_name']) ?></td>
                            <td class="text-center font-monospace <?= $p['stock_quantity'] <= 0 ? 'text-danger fw-bold' : '' ?>">
                                <?= $p['stock_quantity'] ?>
                            </td>
                            <td class="text-end font-monospace text-muted">$<?= number_format($p['purchase_price'], 2) ?></td>
                            <td class="text-end font-monospace text-dark">$<?= number_format($p['selling_price'], 2) ?></td>
                            <td class="text-end font-monospace fw-semibold text-success">$<?= number_format($p['cost_valuation'], 2) ?></td>
                            <td class="text-end pe-3 font-monospace fw-semibold text-primary">$<?= number_format($p['retail_valuation'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ─── REPORT 3: LOW STOCK AUDIT ──────────────────────────────────── -->
<?php elseif ($reportType === 'low_stock'): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 border-0">
        <h5 class="fw-bold mb-0 text-warning"><i class="bi bi-exclamation-triangle-fill me-2"></i>Understocked Inventory Checklist</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">SKU</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th class="text-center">Current Stock</th>
                        <th class="text-center">Min Threshold</th>
                        <th class="text-center">Deficit</th>
                        <th class="text-center">Suggested Reorder</th>
                        <th class="text-end pe-3">Quick Restock</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($lowList)): ?>
                        <tr><td colspan="8" class="text-center py-5 text-success"><i class="bi bi-check-circle fs-2 d-block mb-2"></i>All product inventories are currently above minimum safety levels!</td></tr>
                    <?php else: ?>
                        <?php foreach ($lowList as $item): ?>
                            <tr>
                                <td class="ps-3 font-monospace small"><span class="badge bg-light text-dark border"><?= htmlspecialchars($item['sku']) ?></span></td>
                                <td class="fw-semibold"><?= htmlspecialchars($item['name']) ?></td>
                                <td><?= htmlspecialchars($item['category_name']) ?></td>
                                <td class="text-center">
                                    <?php if ($item['stock_quantity'] <= 0): ?>
                                        <span class="badge bg-danger">0 (Out of stock)</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark"><?= $item['stock_quantity'] ?> units</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center font-monospace text-muted"><?= $item['min_stock_level'] ?></td>
                                <td class="text-center font-monospace text-danger fw-bold">-<?= max(0, $item['deficit']) ?></td>
                                <td class="text-center font-monospace fw-bold text-primary">+<?= $item['suggested_reorder'] ?> units</td>
                                <td class="text-end pe-3">
                                    <a href="stock_in.php?product_id=<?= $item['id'] ?>" class="btn btn-sm btn-success">
                                        <i class="bi bi-box-arrow-in-down me-1"></i> Order
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ─── REPORT 4: PRODUCT PERFORMANCE ──────────────────────────────── -->
<?php elseif ($reportType === 'performance'): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 border-0">
        <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-trophy me-2 text-warning"></i>Best-Selling Products by Volume & Revenue</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Rank</th>
                        <th>Product Name</th>
                        <th>SKU</th>
                        <th>Category</th>
                        <th class="text-center">Units Sold</th>
                        <th class="text-end">Total Gross Revenue</th>
                        <th class="text-center pe-3">Current Stock</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($perfList)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">No sales items recorded for this timeframe.</td></tr>
                    <?php else: ?>
                        <?php $rank = 1; foreach ($perfList as $p): ?>
                            <tr>
                                <td class="ps-3 fw-bold text-muted">#<?= $rank++ ?></td>
                                <td class="fw-semibold text-dark"><?= htmlspecialchars($p['product_name']) ?></td>
                                <td class="font-monospace small"><?= htmlspecialchars($p['sku'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($p['category_name']) ?></td>
                                <td class="text-center font-monospace fw-bold text-primary"><?= number_format($p['units_sold']) ?></td>
                                <td class="text-end font-monospace fw-bold text-success">$<?= number_format($p['total_revenue'], 2) ?></td>
                                <td class="text-center pe-3 font-monospace"><?= $p['current_stock'] !== null ? $p['current_stock'] : 'N/A' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ─── REPORT 5: OUT OF STOCK ──────────────────────────────────── -->
<?php elseif ($reportType === 'out_of_stock'): ?>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
        <h5 class="fw-bold mb-0 text-danger"><i class="bi bi-x-circle-fill me-2"></i>Out-of-Stock Products</h5>
        <span class="badge bg-danger"><?= count($outList) ?> Items</span>
    </div>
    <div class="card-body p-0">
        <?php if (empty($outList)): ?>
            <div class="text-center py-5 text-success">
                <i class="bi bi-check-circle fs-1 d-block mb-2"></i>
                <strong>All products currently have stock available!</strong>
            </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">SKU</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th>Supplier</th>
                        <th class="text-center">Min Stock Threshold</th>
                        <th class="text-center">Suggested Reorder</th>
                        <th class="text-end pe-3">Quick Restock</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($outList as $item): ?>
                        <tr class="table-danger bg-opacity-25">
                            <td class="ps-3 font-monospace small"><span class="badge bg-light text-dark border"><?= htmlspecialchars($item['sku']) ?></span></td>
                            <td class="fw-semibold"><?= htmlspecialchars($item['name']) ?></td>
                            <td><?= htmlspecialchars($item['category_name']) ?></td>
                            <td><?= htmlspecialchars($item['supplier_name']) ?></td>
                            <td class="text-center font-monospace text-muted"><?= $item['min_stock_level'] ?></td>
                            <td class="text-center font-monospace fw-bold text-primary">+<?= $item['suggested_reorder'] ?> units</td>
                            <td class="text-end pe-3">
                                <a href="stock_in.php?product_id=<?= $item['id'] ?>" class="btn btn-sm btn-danger">
                                    <i class="bi bi-box-arrow-in-down me-1"></i> Restock
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ─── REPORT 6: STOCK MOVEMENT ──────────────────────────────── -->
<?php elseif ($reportType === 'stock_movement'): ?>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3">
            <div class="text-muted small">Total Movements</div>
            <div class="fs-4 fw-bold text-dark font-monospace"><?= number_format($movStats['total_moves']) ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3">
            <div class="text-muted small">Stock-In Events</div>
            <div class="fs-4 fw-bold text-success font-monospace"><?= number_format($movStats['in_moves']) ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3">
            <div class="text-muted small">Sales Deductions</div>
            <div class="fs-4 fw-bold text-primary font-monospace"><?= number_format($movStats['sale_moves'] + $movStats['out_moves']) ?></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm p-3">
            <div class="text-muted small">Manual Adjustments</div>
            <div class="fs-4 fw-bold text-warning font-monospace"><?= number_format($movStats['adj_moves']) ?></div>
        </div>
    </div>
</div>
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 border-0">
        <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-arrow-left-right me-2 text-primary"></i>Inventory Movement History</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Date & Time</th>
                        <th>Product</th>
                        <th>SKU</th>
                        <th class="text-center">Type</th>
                        <th class="text-center">Qty</th>
                        <th class="text-center">Before → After</th>
                        <th>Reason</th>
                        <th class="pe-3">User</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($movList)): ?>
                        <tr><td colspan="8" class="text-center py-4 text-muted">No inventory movements recorded in this date range.</td></tr>
                    <?php else: ?>
                        <?php foreach ($movList as $m): ?>
                            <tr>
                                <td class="ps-3 font-monospace small text-muted"><?= date('M d, Y H:i', strtotime($m['created_at'])) ?></td>
                                <td class="fw-semibold"><?= htmlspecialchars($m['product_name']) ?></td>
                                <td class="font-monospace small"><?= htmlspecialchars($m['product_sku']) ?></td>
                                <td class="text-center">
                                    <?php
                                    $typeConfig = [
                                        'stock_in'   => ['bg-success', 'bi-arrow-down-circle', 'Stock In'],
                                        'stock_out'  => ['bg-warning text-dark', 'bi-arrow-up-circle', 'Stock Out'],
                                        'sale'       => ['bg-primary', 'bi-cart-check', 'Sale'],
                                        'adjustment' => ['bg-secondary', 'bi-wrench', 'Adjustment'],
                                    ];
                                    $tc = $typeConfig[$m['movement_type']] ?? ['bg-dark', 'bi-question', $m['movement_type']];
                                    ?>
                                    <span class="badge <?= $tc[0] ?>"><i class="bi <?= $tc[1] ?> me-1"></i><?= $tc[2] ?></span>
                                </td>
                                <td class="text-center font-monospace fw-bold <?= $m['quantity'] > 0 ? 'text-success' : 'text-danger' ?>">
                                    <?= ($m['quantity'] > 0 ? '+' : '') . $m['quantity'] ?>
                                </td>
                                <td class="text-center font-monospace text-muted">
                                    <?= $m['qty_before'] ?> → <strong class="text-dark"><?= $m['qty_after'] ?></strong>
                                </td>
                                <td class="text-muted small"><?= htmlspecialchars($m['reason'] ?? '—') ?></td>
                                <td class="pe-3 small"><?= htmlspecialchars($m['user_name']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
