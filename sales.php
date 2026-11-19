<?php
/**
 * StockWise — Sales History & Receipts Management
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Sales Transactions';
requireLogin();

$user = getCurrentUser();
$isManagerOrAdmin = isAdmin() || isManager();

// ── GET ?action=get_receipt&id=X (AJAX endpoint for receipt modal) ──
if (isset($_GET['action']) && $_GET['action'] === 'get_receipt') {
    header('Content-Type: application/json');
    $saleId = (int)($_GET['id'] ?? 0);

    $sql = "
        SELECT 
            s.*,
            COALESCE(u.full_name, u.username, 'Cashier') AS cashier_name
        FROM sales s
        LEFT JOIN users u ON s.user_id = u.id
        WHERE s.id = ?
    ";
    $params = [$saleId];

    // Cashiers can only view their own
    if (!$isManagerOrAdmin) {
        $sql .= " AND s.user_id = ?";
        $params[] = $user['id'];
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $sale = $st->fetch();

    if (!$sale) {
        http_response_code(404);
        echo json_encode(['error' => 'Receipt not found or unauthorized']);
        exit;
    }

    // Get line items
    $itemsStmt = $pdo->prepare("
        SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id ASC
    ");
    $itemsStmt->execute([$saleId]);
    $items = $itemsStmt->fetchAll();

    $sale['items'] = $items;
    $sale['formatted_date'] = date('M d, Y h:i A', strtotime($sale['created_at']));
    $sale['subtotal'] = (float)$sale['subtotal'];
    $sale['discount_amount'] = (float)$sale['discount_amount'];
    $sale['total_amount'] = (float)$sale['total_amount'];
    $sale['payment_amount'] = (float)$sale['payment_amount'];
    $sale['change_amount'] = (float)$sale['change_amount'];

    echo json_encode(['success' => true, 'sale' => $sale]);
    exit;
}

// ── Filters & Query Building ────────────────────────────────────────
$searchReceipt = trim($_GET['search'] ?? '');
$filterCashier = (int)($_GET['cashier_id'] ?? 0);
$dateFrom      = trim($_GET['date_from'] ?? '');
$dateTo        = trim($_GET['date_to'] ?? '');

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if (!$isManagerOrAdmin) {
    $where[] = 's.user_id = :curr_user_id';
    $params[':curr_user_id'] = $user['id'];
} elseif ($filterCashier > 0) {
    $where[] = 's.user_id = :filter_cashier';
    $params[':filter_cashier'] = $filterCashier;
}

if ($searchReceipt !== '') {
    $where[] = 's.receipt_no LIKE :search_rcp';
    $params[':search_rcp'] = '%' . $searchReceipt . '%';
}

if ($dateFrom !== '') {
    $where[] = 'DATE(s.created_at) >= :d_from';
    $params[':d_from'] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = 'DATE(s.created_at) <= :d_to';
    $params[':d_to'] = $dateTo;
}

$whereSql = implode(' AND ', $where);

// Summary metrics (filtered)
$summaryStmt = $pdo->prepare("
    SELECT 
        COUNT(s.id) AS total_count,
        COALESCE(SUM(s.total_amount), 0) AS total_revenue,
        COALESCE(AVG(s.total_amount), 0) AS avg_ticket
    FROM sales s
    WHERE {$whereSql}
");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch();

$totalRows  = (int)$summary['total_count'];
$totalPages = ceil($totalRows / $perPage);

// Sales transaction records
$listStmt = $pdo->prepare("
    SELECT 
        s.*,
        COALESCE(u.full_name, u.username, 'System') AS cashier_name,
        (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) AS item_count
    FROM sales s
    LEFT JOIN users u ON s.user_id = u.id
    WHERE {$whereSql}
    ORDER BY s.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$listStmt->execute($params);
$sales = $listStmt->fetchAll();

// Cashiers for filter
$cashiers = [];
if ($isManagerOrAdmin) {
    $cashiers = $pdo->query("SELECT id, full_name, username FROM users ORDER BY full_name ASC")->fetchAll();
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h2 class="fw-bold mb-1"><i class="bi bi-receipt me-2 text-primary"></i>Sales Transactions</h2>
        <p class="text-muted mb-0">Browse completed sales orders, inspect itemized receipts, and reprint transaction slips.</p>
    </div>
    <a href="pos.php" class="btn btn-primary">
        <i class="bi bi-cart-plus me-1"></i> Open Point of Sale
    </a>
</div>

<!-- Summary Metrics Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm p-3">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-success-subtle text-success p-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                    <i class="bi bi-currency-dollar fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Total Revenue (Filtered)</div>
                    <div class="fs-4 fw-bold text-success">$<?= number_format($summary['total_revenue'], 2) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm p-3">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-primary-subtle text-primary p-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                    <i class="bi bi-receipt-cutoff fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Total Transactions</div>
                    <div class="fs-4 fw-bold text-primary"><?= number_format($summary['total_count']) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card border-0 shadow-sm p-3">
            <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle bg-info-subtle text-info p-3 d-flex align-items-center justify-content-center" style="width: 50px; height: 50px;">
                    <i class="bi bi-graph-up fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Average Ticket Size</div>
                    <div class="fs-4 fw-bold text-dark">$<?= number_format($summary['avg_ticket'], 2) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters Card -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3">
        <form method="GET" action="sales.php" class="row g-2 align-items-center">
            <div class="col-md-3">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control border-start-0" placeholder="Search Receipt #" value="<?= htmlspecialchars($searchReceipt) ?>">
                </div>
            </div>
            <?php if ($isManagerOrAdmin): ?>
            <div class="col-md-3">
                <select name="cashier_id" class="form-select">
                    <option value="0">All Cashiers</option>
                    <?php foreach ($cashiers as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $filterCashier === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['full_name'] ?? $c['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-2">
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>" placeholder="From Date">
            </div>
            <div class="col-md-2">
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>" placeholder="To Date">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100">Filter</button>
                <?php if ($searchReceipt !== '' || $filterCashier > 0 || $dateFrom !== '' || $dateTo !== ''): ?>
                    <a href="sales.php" class="btn btn-outline-secondary" title="Reset Filters"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Transactions Table Card -->
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
                        <th class="text-end">Total Amount</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sales)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-5 text-muted">
                                <i class="bi bi-receipt fs-1 text-secondary d-block mb-2"></i>
                                No sales transactions found matching your criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sales as $s): ?>
                            <tr>
                                <td class="ps-3">
                                    <span class="badge bg-light text-dark font-monospace border fs-6">
                                        <?= htmlspecialchars($s['receipt_no']) ?>
                                    </span>
                                </td>
                                <td class="text-muted small font-monospace">
                                    <?= date('M d, Y h:i A', strtotime($s['created_at'])) ?>
                                </td>
                                <td>
                                    <i class="bi bi-person me-1 text-secondary"></i>
                                    <?= htmlspecialchars($s['cashier_name']) ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis rounded-pill px-2.5">
                                        <?= $s['item_count'] ?> item(s)
                                    </span>
                                </td>
                                <td class="text-end text-muted font-monospace">$<?= number_format($s['subtotal'], 2) ?></td>
                                <td class="text-end text-danger font-monospace">
                                    <?= $s['discount_amount'] > 0 ? '-$' . number_format($s['discount_amount'], 2) : '$0.00' ?>
                                </td>
                                <td class="text-end fw-bold font-monospace text-primary fs-6">
                                    $<?= number_format($s['total_amount'], 2) ?>
                                </td>
                                <td class="text-end pe-3">
                                    <button class="btn btn-sm btn-outline-primary btn-view-receipt" data-id="<?= $s['id'] ?>">
                                        <i class="bi bi-eye me-1"></i> View Receipt
                                    </button>
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
                <small class="text-muted">Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalRows) ?> of <?= $totalRows ?> sales</small>
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

<!-- Modal: View Receipt Details -->
<div class="modal fade" id="viewReceiptModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm" style="max-width: 420px;">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-body p-4" id="viewPrintableReceipt">
                <div class="text-center mb-3">
                    <h4 class="fw-bold text-dark mb-0"><?= APP_NAME ?></h4>
                    <small class="text-muted d-block">Retail & Inventory System</small>
                    <div class="border-top border-bottom border-dashed my-2 py-1 small">
                        <div class="d-flex justify-content-between">
                            <span>Receipt:</span>
                            <span class="fw-bold font-monospace" id="modalReceiptNo">--</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>Date:</span>
                            <span id="modalReceiptDate">--</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>Cashier:</span>
                            <span id="modalReceiptCashier">--</span>
                        </div>
                    </div>
                </div>

                <!-- Items Table -->
                <table class="table table-sm border-0 mb-2" style="font-size: 0.85rem;">
                    <thead>
                        <tr class="border-bottom">
                            <th>Item</th>
                            <th class="text-center">Qty</th>
                            <th class="text-end">Price</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody id="modalReceiptItems"></tbody>
                </table>

                <div class="border-top border-dashed pt-2 small">
                    <div class="d-flex justify-content-between mb-1">
                        <span>Subtotal:</span>
                        <span id="modalReceiptSubtotal" class="font-monospace">$0.00</span>
                    </div>
                    <div class="d-flex justify-content-between mb-1">
                        <span>Discount:</span>
                        <span id="modalReceiptDiscount" class="font-monospace">-$0.00</span>
                    </div>
                    <div class="d-flex justify-content-between fs-6 fw-bold mb-1">
                        <span>Total:</span>
                        <span id="modalReceiptTotal" class="font-monospace">$0.00</span>
                    </div>
                    <div class="d-flex justify-content-between mb-1 text-muted">
                        <span>Cash Paid:</span>
                        <span id="modalReceiptPayment" class="font-monospace">$0.00</span>
                    </div>
                    <div class="d-flex justify-content-between fw-semibold text-success">
                        <span>Change:</span>
                        <span id="modalReceiptChange" class="font-monospace">$0.00</span>
                    </div>
                </div>

                <div class="text-center mt-3 pt-2 border-top border-dashed text-muted small">
                    <div>Thank you for shopping with us!</div>
                </div>
            </div>
            <div class="modal-footer border-0 p-3 pt-0">
                <button type="button" class="btn btn-secondary flex-fill" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary flex-fill" id="btnModalPrint">
                    <i class="bi bi-printer me-1"></i> Print
                </button>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    body * { visibility: hidden; }
    #viewPrintableReceipt, #viewPrintableReceipt * { visibility: visible; }
    #viewPrintableReceipt {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        margin: 0;
        padding: 15px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = new bootstrap.Modal(document.getElementById('viewReceiptModal'));

    document.querySelectorAll('.btn-view-receipt').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = this.getAttribute('data-id');
            fetch(`sales.php?action=get_receipt&id=${id}`)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        showToast(data.error || 'Failed to fetch receipt.', 'danger');
                        return;
                    }
                    const s = data.sale;
                    document.getElementById('modalReceiptNo').textContent = s.receipt_no;
                    document.getElementById('modalReceiptDate').textContent = s.formatted_date;
                    document.getElementById('modalReceiptCashier').textContent = s.cashier_name;
                    document.getElementById('modalReceiptSubtotal').textContent = '$' + s.subtotal.toFixed(2);
                    document.getElementById('modalReceiptDiscount').textContent = '-$' + s.discount_amount.toFixed(2);
                    document.getElementById('modalReceiptTotal').textContent = '$' + s.total_amount.toFixed(2);
                    document.getElementById('modalReceiptPayment').textContent = '$' + s.payment_amount.toFixed(2);
                    document.getElementById('modalReceiptChange').textContent = '$' + s.change_amount.toFixed(2);

                    const tbody = document.getElementById('modalReceiptItems');
                    tbody.innerHTML = '';
                    s.items.forEach(it => {
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td>${it.product_name}</td>
                            <td class="text-center font-monospace">${it.quantity}</td>
                            <td class="text-end font-monospace">$${parseFloat(it.selling_price).toFixed(2)}</td>
                            <td class="text-end font-monospace fw-semibold">$${parseFloat(it.subtotal).toFixed(2)}</td>
                        `;
                        tbody.appendChild(tr);
                    });

                    modal.show();
                })
                .catch(() => {
                    showToast('Network error loading receipt details.', 'danger');
                });
        });
    });

    document.getElementById('btnModalPrint').addEventListener('click', function () {
        window.print();
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
