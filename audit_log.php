<?php
/**
 * StockWise — System Security & Operations Audit Log
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Audit Log';
requireLogin();
requireRole(['admin']);

$filterAction = trim($_GET['action_filter'] ?? 'all');
$filterUser   = (int)($_GET['user_id'] ?? 0);
$dateFrom     = trim($_GET['date_from'] ?? '');
$dateTo       = trim($_GET['date_to'] ?? '');

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($filterAction !== '' && $filterAction !== 'all') {
    $where[] = 'a.action = :act';
    $params[':act'] = $filterAction;
}

if ($filterUser > 0) {
    $where[] = 'a.user_id = :uid';
    $params[':uid'] = $filterUser;
}

if ($dateFrom !== '') {
    $where[] = 'DATE(a.created_at) >= :d_from';
    $params[':d_from'] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = 'DATE(a.created_at) <= :d_to';
    $params[':d_to'] = $dateTo;
}

$whereSql = implode(' AND ', $where);

// Count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs a WHERE {$whereSql}");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

// Log items
$stmt = $pdo->prepare("
    SELECT 
        a.*,
        COALESCE(u.full_name, u.username, 'System') AS user_name,
        u.role AS user_role
    FROM audit_logs a
    LEFT JOIN users u ON a.user_id = u.id
    WHERE {$whereSql}
    ORDER BY a.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stmt->execute($params);
$logs = $stmt->fetchAll();

$allUsers   = $pdo->query('SELECT id, full_name, username FROM users ORDER BY full_name ASC')->fetchAll();
$allActions = $pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action ASC')->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h2 class="fw-bold mb-1"><i class="bi bi-shield-check me-2 text-primary"></i>System Security & Audit Trail</h2>
        <p class="text-muted mb-0">Immutable records of authentication, configuration changes, catalog modifications, and financial sales.</p>
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3">
        <form method="GET" action="audit_log.php" class="row g-2 align-items-center">
            <div class="col-md-3">
                <select name="action_filter" class="form-select">
                    <option value="all">All Actions</option>
                    <?php foreach ($allActions as $act): ?>
                        <option value="<?= htmlspecialchars($act) ?>" <?= $filterAction === $act ? 'selected' : '' ?>>
                            <?= htmlspecialchars($act) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="user_id" class="form-select">
                    <option value="0">All Users</option>
                    <?php foreach ($allUsers as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $filterUser === (int)$u['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['full_name'] ?? $u['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>" placeholder="From Date">
            </div>
            <div class="col-md-2">
                <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($dateTo) ?>" placeholder="To Date">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100">Filter</button>
                <?php if ($filterAction !== 'all' || $filterUser > 0 || $dateFrom !== '' || $dateTo !== ''): ?>
                    <a href="audit_log.php" class="btn btn-outline-secondary" title="Clear"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width: 190px;">Timestamp</th>
                        <th>User Responsible</th>
                        <th>Action Category</th>
                        <th>Event Description</th>
                        <th class="text-end pe-3">IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">No audit events match your criteria.</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $l): ?>
                            <tr>
                                <td class="ps-3 text-muted small font-monospace">
                                    <?= date('M d, Y h:i:s A', strtotime($l['created_at'])) ?>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><i class="bi bi-person me-1 text-secondary"></i><?= htmlspecialchars($l['user_name']) ?></div>
                                    <?php if (!empty($l['user_role'])): ?>
                                        <small class="text-muted text-uppercase" style="font-size:0.65rem;"><?= htmlspecialchars($l['user_role']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-secondary font-monospace"><?= htmlspecialchars($l['action']) ?></span>
                                </td>
                                <td>
                                    <div class="text-dark small"><?= htmlspecialchars($l['description']) ?></div>
                                </td>
                                <td class="text-end pe-3 font-monospace small text-muted">
                                    <?= htmlspecialchars($l['ip_address'] ?? '127.0.0.1') ?>
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
                <small class="text-muted">Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalRows) ?> of <?= $totalRows ?> events</small>
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

<?php require_once __DIR__ . '/includes/footer.php'; ?>
