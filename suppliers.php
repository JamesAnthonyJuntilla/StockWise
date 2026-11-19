<?php
/**
 * StockWise — Supplier Directory & Management
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Suppliers';
requireLogin();
requireRole(['admin', 'manager']);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── GET ?action=get&id=X (return JSON for edit modal) ───────────────
if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM suppliers WHERE id = ?');
    $stmt->execute([$id]);
    $sup = $stmt->fetch();
    if (!$sup) {
        http_response_code(404);
        echo json_encode(['error' => 'Supplier not found']);
        exit;
    }
    echo json_encode($sup);
    exit;
}

// ── POST action=add ─────────────────────────────────────────────────
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $companyName   = trim($_POST['company_name'] ?? '');
    $contactPerson = trim($_POST['contact_person'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $address       = trim($_POST['address'] ?? '');
    $notes         = trim($_POST['notes'] ?? '');

    if ($companyName === '') {
        setFlash('danger', 'Company name is required.');
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO suppliers (
                company_name, contact_person, phone, email, address, notes, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$companyName, $contactPerson, $phone, $email, $address, $notes]);
        logAudit($pdo, 'supplier_create', "Created supplier: {$companyName}");
        setFlash('success', "Supplier <strong>" . htmlspecialchars($companyName) . "</strong> added successfully!");
    }
    header('Location: suppliers.php');
    exit;
}

// ── POST action=edit ────────────────────────────────────────────────
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $id            = (int)($_POST['id'] ?? 0);
    $companyName   = trim($_POST['company_name'] ?? '');
    $contactPerson = trim($_POST['contact_person'] ?? '');
    $phone         = trim($_POST['phone'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $address       = trim($_POST['address'] ?? '');
    $notes         = trim($_POST['notes'] ?? '');

    if ($companyName === '') {
        setFlash('danger', 'Company name cannot be empty.');
    } else {
        $stmt = $pdo->prepare("
            UPDATE suppliers SET
                company_name = ?, contact_person = ?, phone = ?, email = ?,
                address = ?, notes = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$companyName, $contactPerson, $phone, $email, $address, $notes, $id]);
        logAudit($pdo, 'supplier_edit', "Edited supplier ID #{$id}: {$companyName}");
        setFlash('success', "Supplier <strong>" . htmlspecialchars($companyName) . "</strong> updated successfully!");
    }
    header('Location: suppliers.php');
    exit;
}

// ── POST action=delete ──────────────────────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $id = (int)($_POST['id'] ?? 0);

    // Safeguard: Check if supplier has associated products
    $check = $pdo->prepare('SELECT COUNT(*) FROM products WHERE supplier_id = ?');
    $check->execute([$id]);
    $prodCount = (int)$check->fetchColumn();

    if ($prodCount > 0) {
        setFlash('danger', "Cannot delete this supplier: {$prodCount} product(s) are currently associated with it.");
        header('Location: suppliers.php');
        exit;
    }

    $ps = $pdo->prepare('SELECT company_name FROM suppliers WHERE id = ?');
    $ps->execute([$id]);
    $supName = $ps->fetchColumn();

    if ($supName) {
        $pdo->prepare('DELETE FROM suppliers WHERE id = ?')->execute([$id]);
        logAudit($pdo, 'supplier_delete', "Deleted supplier ID #{$id}: {$supName}");
        setFlash('success', "Supplier <strong>" . htmlspecialchars($supName) . "</strong> deleted successfully.");
    } else {
        setFlash('danger', 'Supplier not found.');
    }
    header('Location: suppliers.php');
    exit;
}

// ── Query Suppliers List ────────────────────────────────────────────
$search = trim($_GET['search'] ?? '');
$where = '1=1';
$params = [];

if ($search !== '') {
    $where = '(s.company_name LIKE :s OR s.contact_person LIKE :s OR s.phone LIKE :s OR s.email LIKE :s)';
    $params[':s'] = '%' . $search . '%';
}

$stmt = $pdo->prepare("
    SELECT 
        s.*,
        COUNT(p.id) AS product_count
    FROM suppliers s
    LEFT JOIN products p ON s.id = p.supplier_id
    WHERE {$where}
    GROUP BY s.id
    ORDER BY s.company_name ASC
");
$stmt->execute($params);
$suppliers = $stmt->fetchAll();

$csrfToken = getCsrfToken();
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h2 class="fw-bold mb-1"><i class="bi bi-truck me-2 text-primary"></i>Supplier Directory</h2>
        <p class="text-muted mb-0">Maintain vendor relationships, contact details, and inventory procurement contacts.</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSupplierModal">
        <i class="bi bi-plus-lg me-1"></i> Add Supplier
    </button>
</div>

<!-- Search Bar -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3">
        <form method="GET" action="suppliers.php" class="row g-2 align-items-center">
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control border-start-0" placeholder="Search suppliers by company, contact person, email, phone..." value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100">Search</button>
                <?php if ($search !== ''): ?>
                    <a href="suppliers.php" class="btn btn-outline-secondary" title="Clear"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Suppliers Cards Grid -->
<div class="row g-3">
    <?php if (empty($suppliers)): ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm text-center py-5">
                <i class="bi bi-truck fs-1 text-muted d-block mb-2"></i>
                <div class="text-muted">No suppliers found matching your query.</div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($suppliers as $s): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h5 class="fw-bold text-dark mb-0"><?= htmlspecialchars($s['company_name']) ?></h5>
                            <span class="badge bg-primary-subtle text-primary-emphasis rounded-pill px-2.5 py-1">
                                <?= $s['product_count'] ?> products supplied
                            </span>
                        </div>
                        <?php if (!empty($s['contact_person'])): ?>
                            <div class="text-muted small mb-2">
                                <i class="bi bi-person me-1"></i>Contact: <strong><?= htmlspecialchars($s['contact_person']) ?></strong>
                            </div>
                        <?php endif; ?>

                        <div class="small mb-3">
                            <?php if (!empty($s['phone'])): ?>
                                <div class="text-secondary"><i class="bi bi-telephone me-1"></i><?= htmlspecialchars($s['phone']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($s['email'])): ?>
                                <div class="text-secondary"><i class="bi bi-envelope me-1"></i><?= htmlspecialchars($s['email']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($s['address'])): ?>
                                <div class="text-secondary text-truncate" title="<?= htmlspecialchars($s['address']) ?>"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($s['address']) ?></div>
                            <?php endif; ?>
                        </div>

                        <?php if (!empty($s['notes'])): ?>
                            <div class="p-2 bg-light rounded text-muted small mb-3 fst-italic">
                                <?= htmlspecialchars($s['notes']) ?>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                            <span class="text-muted small">ID #<?= $s['id'] ?></span>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-outline-primary btn-edit-sup" data-id="<?= $s['id'] ?>" title="Edit Supplier">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger btn-delete-sup" data-id="<?= $s['id'] ?>" data-name="<?= htmlspecialchars($s['company_name'], ENT_QUOTES) ?>" data-count="<?= $s['product_count'] ?>" title="Delete Supplier">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Modal: Add Supplier -->
<div class="modal fade" id="addSupplierModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="suppliers.php">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-truck me-2 text-primary"></i>Add Supplier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Company Name <span class="text-danger">*</span></label>
                        <input type="text" name="company_name" class="form-control" required placeholder="e.g. Apex Global Distributors">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Contact Person</label>
                        <input type="text" name="contact_person" class="form-control" placeholder="Representative name">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Phone</label>
                            <input type="text" name="phone" class="form-control" placeholder="+1 (555) 000-0000">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="email" name="email" class="form-control" placeholder="supplier@example.com">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Business Address</label>
                        <textarea name="address" class="form-control" rows="2" placeholder="Street, City, State, ZIP"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Payment terms, delivery schedules..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Save Supplier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Supplier -->
<div class="modal fade" id="editSupplierModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="suppliers.php">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="id" id="editSupId">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Supplier</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Company Name <span class="text-danger">*</span></label>
                        <input type="text" name="company_name" id="editSupCompany" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Contact Person</label>
                        <input type="text" name="contact_person" id="editSupContact" class="form-control">
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Phone</label>
                            <input type="text" name="phone" id="editSupPhone" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Email</label>
                            <input type="email" name="email" id="editSupEmail" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Business Address</label>
                        <textarea name="address" id="editSupAddress" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Notes</label>
                        <textarea name="notes" id="editSupNotes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Update Supplier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Delete Form -->
<form id="deleteSupForm" method="POST" action="suppliers.php">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <input type="hidden" name="id" id="deleteSupId">
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Edit Supplier Modal
    document.querySelectorAll('.btn-edit-sup').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = this.getAttribute('data-id');
            fetch(`suppliers.php?action=get&id=${id}`)
                .then(r => r.json())
                .then(sup => {
                    if (sup.error) {
                        showToast(sup.error, 'danger');
                        return;
                    }
                    document.getElementById('editSupId').value = sup.id;
                    document.getElementById('editSupCompany').value = sup.company_name;
                    document.getElementById('editSupContact').value = sup.contact_person || '';
                    document.getElementById('editSupPhone').value = sup.phone || '';
                    document.getElementById('editSupEmail').value = sup.email || '';
                    document.getElementById('editSupAddress').value = sup.address || '';
                    document.getElementById('editSupNotes').value = sup.notes || '';
                    new bootstrap.Modal(document.getElementById('editSupplierModal')).show();
                });
        });
    });

    // Delete Supplier
    document.querySelectorAll('.btn-delete-sup').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');
            const count = parseInt(this.getAttribute('data-count'), 10);

            if (count > 0) {
                showToast(`Cannot delete "${name}" because ${count} product(s) are linked to it.`, 'warning');
                return;
            }

            confirmAction(
                `Are you sure you want to delete supplier "${name}"?`,
                function () {
                    document.getElementById('deleteSupId').value = id;
                    document.getElementById('deleteSupForm').submit();
                },
                'Delete Supplier',
                true
            );
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
