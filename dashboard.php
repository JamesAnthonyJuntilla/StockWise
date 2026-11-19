<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Dashboard';
requireLogin();

// ─── 1. Summary Stats ─────────────────────────────────────────────────────────

// Total products
$totalProducts = (int) $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();

// Total categories
$totalCategories = (int) $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();

// Total inventory value  (purchase_price × stock_quantity)
$inventoryValue = (float) $pdo->query(
    "SELECT COALESCE(SUM(purchase_price * stock_quantity), 0) FROM products"
)->fetchColumn();

// Today's sales total
$todaySales = (float) $pdo->query(
    "SELECT COALESCE(SUM(total_amount), 0) FROM sales WHERE DATE(created_at) = CURDATE()"
)->fetchColumn();

// Monthly revenue (current month + year)
$monthlySales = (float) $pdo->query(
    "SELECT COALESCE(SUM(total_amount), 0) FROM sales
     WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())"
)->fetchColumn();

// Total transactions (all time)
$totalTransactions = (int) $pdo->query("SELECT COUNT(*) FROM sales")->fetchColumn();

// Low stock count (stock > 0 but at or below min level)
$lowStockCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM products WHERE stock_quantity <= min_stock_level AND stock_quantity > 0"
)->fetchColumn();

// Out of stock count
$outOfStockCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM products WHERE stock_quantity = 0"
)->fetchColumn();

// ─── 2. Last 30 Days Sales (for Chart.js) ────────────────────────────────────

$stmt = $pdo->query(
    "SELECT DATE(created_at) AS date, COALESCE(SUM(total_amount), 0) AS total
     FROM sales
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
     GROUP BY DATE(created_at)
     ORDER BY DATE(created_at) ASC"
);
$rawSalesData = $stmt->fetchAll();

// Build a filled array for every day in the range (gaps = 0)
$salesByDate = [];
foreach ($rawSalesData as $row) {
    $salesByDate[$row['date']] = (float) $row['total'];
}

$chartLabels = [];
$chartData   = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} days"));
    $chartLabels[] = date('M j', strtotime($date));
    $chartData[]   = $salesByDate[$date] ?? 0;
}

$chartLabelsJson = json_encode($chartLabels, JSON_UNESCAPED_UNICODE);
$chartDataJson   = json_encode($chartData, JSON_UNESCAPED_UNICODE);

// ─── 3. Recent Transactions (last 10) ────────────────────────────────────────

$recentTransactions = $pdo->query(
    "SELECT s.id, s.receipt_no, s.total_amount, s.created_at,
            u.full_name AS cashier,
            (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) AS item_count
     FROM sales s
     LEFT JOIN users u ON s.user_id = u.id
     ORDER BY s.created_at DESC
     LIMIT 10"
)->fetchAll();

// ─── 4. Top 5 Selling Products This Month ─────────────────────────────────────

$topProducts = $pdo->query(
    "SELECT p.name, SUM(si.quantity) AS total_qty, SUM(si.subtotal) AS total_revenue
     FROM sale_items si
     JOIN products p ON si.product_id = p.id
     JOIN sales s ON si.sale_id = s.id
     WHERE MONTH(s.created_at) = MONTH(CURDATE()) AND YEAR(s.created_at) = YEAR(CURDATE())
     GROUP BY si.product_id, p.name
     ORDER BY total_qty DESC
     LIMIT 5"
)->fetchAll();

// ─── 5. Low Stock Alerts ──────────────────────────────────────────────────────

$lowStockProducts = $pdo->query(
    "SELECT id, name, stock_quantity, min_stock_level
     FROM products
     WHERE stock_quantity <= min_stock_level
     ORDER BY stock_quantity ASC
     LIMIT 10"
)->fetchAll();

// ─── Helper ──────────────────────────────────────────────────────────────────
function money(float $v): string {
    return '$' . number_format($v, 2);
}

include __DIR__ . '/includes/header.php';
?>

<!-- ═══ Quick Actions Bar ═══════════════════════════════════════════════════ -->
<div class="d-flex flex-wrap gap-2 mb-4 align-items-center">
    <span class="text-muted small me-1 fw-semibold">Quick Actions:</span>
    <a href="pos.php" class="btn btn-primary btn-sm px-3">
        <i class="bi bi-cart-plus me-1"></i>New Sale (POS)
    </a>
    <a href="products.php?action=add" class="btn btn-success btn-sm px-3">
        <i class="bi bi-plus-circle me-1"></i>Add Product
    </a>
    <a href="stock_in.php" class="btn btn-warning btn-sm px-3 text-dark">
        <i class="bi bi-box-arrow-in-down me-1"></i>Stock In
    </a>
    <a href="reports.php" class="btn btn-info btn-sm px-3 text-dark">
        <i class="bi bi-bar-chart-line me-1"></i>View Reports
    </a>
</div>

<!-- ═══ Stat Cards — Row 1 ══════════════════════════════════════════════════ -->
<div class="row g-3 mb-3">

    <!-- Products -->
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #2563eb!important;">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-3" style="background:#eff6ff;">
                    <i class="bi bi-box-seam-fill fs-4" style="color:#2563eb;"></i>
                </div>
                <div>
                    <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.5px;font-size:.72rem;">Total Products</div>
                    <div class="fw-bold fs-4 lh-1 mt-1"><?= $totalProducts ?></div>
                    <div class="text-muted" style="font-size:.78rem;">Active SKUs</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Categories -->
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #7c3aed!important;">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-3" style="background:#f5f3ff;">
                    <i class="bi bi-tags-fill fs-4" style="color:#7c3aed;"></i>
                </div>
                <div>
                    <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.5px;font-size:.72rem;">Categories</div>
                    <div class="fw-bold fs-4 lh-1 mt-1"><?= $totalCategories ?></div>
                    <div class="text-muted" style="font-size:.78rem;">Product groups</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Inventory Value -->
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #16a34a!important;">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-3" style="background:#f0fdf4;">
                    <i class="bi bi-currency-dollar fs-4" style="color:#16a34a;"></i>
                </div>
                <div>
                    <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.5px;font-size:.72rem;">Inventory Value</div>
                    <div class="fw-bold fs-5 lh-1 mt-1"><?= money($inventoryValue) ?></div>
                    <div class="text-muted" style="font-size:.78rem;">At cost price</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Today's Sales -->
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #0d9488!important;">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-3" style="background:#f0fdfa;">
                    <i class="bi bi-calendar-check-fill fs-4" style="color:#0d9488;"></i>
                </div>
                <div>
                    <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.5px;font-size:.72rem;">Today's Sales</div>
                    <div class="fw-bold fs-5 lh-1 mt-1"><?= money($todaySales) ?></div>
                    <div class="text-muted" style="font-size:.78rem;"><?= date('M j, Y') ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ Stat Cards — Row 2 ══════════════════════════════════════════════════ -->
<div class="row g-3 mb-4">

    <!-- Monthly Revenue -->
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #4338ca!important;">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-3" style="background:#eef2ff;">
                    <i class="bi bi-graph-up-arrow fs-4" style="color:#4338ca;"></i>
                </div>
                <div>
                    <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.5px;font-size:.72rem;">Monthly Revenue</div>
                    <div class="fw-bold fs-5 lh-1 mt-1"><?= money($monthlySales) ?></div>
                    <div class="text-muted" style="font-size:.78rem;"><?= date('F Y') ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Total Transactions -->
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #0891b2!important;">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-3" style="background:#ecfeff;">
                    <i class="bi bi-receipt fs-4" style="color:#0891b2;"></i>
                </div>
                <div>
                    <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.5px;font-size:.72rem;">Total Transactions</div>
                    <div class="fw-bold fs-4 lh-1 mt-1"><?= $totalTransactions ?></div>
                    <div class="text-muted" style="font-size:.78rem;">All time sales</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Low Stock -->
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #d97706!important;">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-3" style="background:#fffbeb;">
                    <i class="bi bi-exclamation-triangle-fill fs-4" style="color:#d97706;"></i>
                </div>
                <div>
                    <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.5px;font-size:.72rem;">Low Stock</div>
                    <div class="fw-bold fs-4 lh-1 mt-1"><?= $lowStockCount ?></div>
                    <div class="text-muted" style="font-size:.78rem;">Need restocking</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Out of Stock -->
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #dc2626!important;">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-3" style="background:#fef2f2;">
                    <i class="bi bi-x-circle-fill fs-4" style="color:#dc2626;"></i>
                </div>
                <div>
                    <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.5px;font-size:.72rem;">Out of Stock</div>
                    <div class="fw-bold fs-4 lh-1 mt-1"><?= $outOfStockCount ?></div>
                    <div class="text-muted" style="font-size:.78rem;">Unavailable items</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ Chart + Top Products ════════════════════════════════════════════════ -->
<div class="row g-3 mb-3">

    <!-- Sales Overview Chart -->
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
                <div>
                    <h6 class="mb-0 fw-bold">Sales Overview</h6>
                    <small class="text-muted">Last 30 days revenue</small>
                </div>
                <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2 rounded-pill">
                    <i class="bi bi-graph-up me-1"></i>30 Days
                </span>
            </div>
            <div class="card-body">
                <canvas id="salesChart" height="260"></canvas>
            </div>
        </div>
    </div>

    <!-- Top Selling Products -->
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom py-3">
                <h6 class="mb-0 fw-bold">Top Selling This Month</h6>
                <small class="text-muted">By units sold</small>
            </div>
            <div class="card-body p-0">
                <?php if (empty($topProducts)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="bi bi-bar-chart fs-2 d-block mb-2 opacity-25"></i>
                        No sales data for this month yet.
                    </div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($topProducts as $i => $p): ?>
                        <li class="list-group-item border-0 py-3 px-4">
                            <div class="d-flex align-items-center gap-3">
                                <div class="fw-bold text-muted" style="width:22px;font-size:.85rem;">#<?= $i + 1 ?></div>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-semibold text-truncate" style="font-size:.88rem;">
                                        <?= htmlspecialchars($p['name']) ?>
                                    </div>
                                    <div class="text-muted" style="font-size:.78rem;">
                                        <?= money($p['total_revenue']) ?> revenue
                                    </div>
                                </div>
                                <span class="badge rounded-pill" style="background:#eff6ff;color:#2563eb;font-size:.8rem;padding:.35rem .65rem;">
                                    <?= (int)$p['total_qty'] ?> sold
                                </span>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ═══ Recent Transactions + Low Stock Alerts ══════════════════════════════ -->
<div class="row g-3 mb-4">

    <!-- Recent Transactions -->
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
                <div>
                    <h6 class="mb-0 fw-bold">Recent Transactions</h6>
                    <small class="text-muted">Last 10 sales</small>
                </div>
                <a href="sales.php" class="btn btn-sm btn-outline-secondary px-3">
                    View All <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentTransactions)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="bi bi-receipt fs-2 d-block mb-2 opacity-25"></i>
                        No transactions found.
                    </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-4 py-3" style="font-size:.8rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Receipt #</th>
                                <th class="py-3" style="font-size:.8rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Cashier</th>
                                <th class="py-3 text-center" style="font-size:.8rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Items</th>
                                <th class="py-3 text-end" style="font-size:.8rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Total</th>
                                <th class="pe-4 py-3" style="font-size:.8rem;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Date &amp; Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentTransactions as $tx): ?>
                            <tr>
                                <td class="ps-4">
                                    <a href="sales.php?id=<?= $tx['id'] ?>" class="text-decoration-none fw-semibold" style="font-size:.88rem;color:#2563eb;">
                                        <?= htmlspecialchars($tx['receipt_no']) ?>
                                    </a>
                                </td>
                                <td style="font-size:.88rem;"><?= htmlspecialchars($tx['cashier'] ?? '—') ?></td>
                                <td class="text-center">
                                    <span class="badge bg-secondary bg-opacity-10 text-secondary rounded-pill px-2">
                                        <?= (int)$tx['item_count'] ?>
                                    </span>
                                </td>
                                <td class="text-end fw-semibold" style="font-size:.88rem;color:#16a34a;">
                                    <?= money($tx['total_amount']) ?>
                                </td>
                                <td class="pe-4 text-muted" style="font-size:.82rem;">
                                    <?= date('M j, Y g:i A', strtotime($tx['created_at'])) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Low Stock Alerts -->
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white border-bottom d-flex justify-content-between align-items-center py-3">
                <div>
                    <h6 class="mb-0 fw-bold">
                        <i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>
                        Low Stock Alerts
                    </h6>
                    <small class="text-muted">Items needing restock</small>
                </div>
                <a href="products.php?filter=low_stock" class="btn btn-sm btn-outline-warning px-2">
                    View All
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($lowStockProducts)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="bi bi-check-circle fs-2 d-block mb-2" style="color:#16a34a;opacity:.5;"></i>
                        All products are well-stocked!
                    </div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($lowStockProducts as $p): ?>
                        <li class="list-group-item border-0 px-4 py-3">
                            <div class="d-flex align-items-center justify-content-between gap-2">
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-semibold text-truncate" style="font-size:.87rem;">
                                        <?= htmlspecialchars($p['name']) ?>
                                    </div>
                                    <div class="mt-1 d-flex align-items-center gap-2">
                                        <?php
                                        $isOut = $p['stock_quantity'] == 0;
                                        $badgeClass = $isOut ? 'bg-danger' : 'bg-warning text-dark';
                                        $label = $isOut ? 'Out of stock' : 'Stock: ' . $p['stock_quantity'];
                                        ?>
                                        <span class="badge <?= $badgeClass ?>" style="font-size:.72rem;">
                                            <?= $label ?>
                                        </span>
                                        <span class="text-muted" style="font-size:.75rem;">
                                            Min: <?= (int)$p['min_stock_level'] ?>
                                        </span>
                                    </div>
                                </div>
                                <a href="stock_in.php?product_id=<?= $p['id'] ?>"
                                   class="btn btn-sm btn-outline-primary px-2 py-1 flex-shrink-0"
                                   title="Stock In" style="font-size:.78rem;">
                                    <i class="bi bi-plus-lg me-1"></i>Restock
                                </a>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ═══ Chart.js ════════════════════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    const ctx   = document.getElementById('salesChart').getContext('2d');
    const labels = <?= $chartLabelsJson ?>;
    const data   = <?= $chartDataJson ?>;

    // Gradient fill
    const gradient = ctx.createLinearGradient(0, 0, 0, 280);
    gradient.addColorStop(0, 'rgba(37, 99, 235, 0.25)');
    gradient.addColorStop(1, 'rgba(37, 99, 235, 0.0)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Sales',
                data: data,
                borderColor: '#2563eb',
                borderWidth: 2.5,
                backgroundColor: gradient,
                fill: true,
                tension: 0.4,
                pointRadius: 3,
                pointHoverRadius: 6,
                pointBackgroundColor: '#2563eb',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#1e3a5f',
                    titleColor: '#93c5fd',
                    bodyColor: '#fff',
                    padding: 12,
                    cornerRadius: 8,
                    callbacks: {
                        label: function (ctx) {
                            const v = ctx.parsed.y;
                            return ' $' + v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: {
                        color: '#9ca3af',
                        font: { size: 11 },
                        maxTicksLimit: 10,
                        maxRotation: 0,
                    }
                },
                y: {
                    grid: {
                        color: 'rgba(0,0,0,0.05)',
                        drawBorder: false,
                    },
                    ticks: {
                        color: '#9ca3af',
                        font: { size: 11 },
                        callback: function (v) {
                            if (v >= 1000) return '$' + (v / 1000).toFixed(1) + 'k';
                            return '$' + v;
                        }
                    }
                }
            }
        }
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
