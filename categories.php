<?php
/**
 * StockWise — Category Management
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Categories';
requireLogin();
requireRole(['admin', 'manager']);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── GET ?action=get&id=X (return JSON for edit modal) ───────────────
if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([$id]);
    $cat = $stmt->fetch();
    if (!$cat) {
        http_response_code(404);
        echo json_encode(['error' => 'Category not found']);
        exit;
    }
    echo json_encode($cat);
    exit;
}

// ── POST action=add ─────────────────────────────────────────────────
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name === '') {
        setFlash('danger', 'Category name is required.');
    } else {
        $chk = $pdo->prepare('SELECT id FROM categories WHERE name = ?');
        $chk->execute([$name]);
        if ($chk->fetchColumn()) {
            setFlash('danger', "Category '{$name}' already exists.");
        } else {
            $stmt = $pdo->prepare('INSERT INTO categories (name, description, created_at, updated_at) VALUES (?, ?, NOW(), NOW())');
            $stmt->execute([$name, $description]);
            logAudit($pdo, 'category_create', "Created category: {$name}");
            setFlash('success', "Category <strong>" . htmlspecialchars($name) . "</strong> created successfully!");
        }
    }
    header('Location: categories.php');
    exit;
}

// ── POST action=edit ────────────────────────────────────────────────
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $id          = (int)($_POST['id'] ?? 0);
    $name        = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name === '') {
        setFlash('danger', 'Category name cannot be empty.');
    } else {
        $chk = $pdo->prepare('SELECT id FROM categories WHERE name = ? AND id != ?');
        $chk->execute([$name, $id]);
        if ($chk->fetchColumn()) {
            setFlash('danger', "Category '{$name}' already exists.");
        } else {
            $stmt = $pdo->prepare('UPDATE categories SET name = ?, description = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$name, $description, $id]);
            logAudit($pdo, 'category_edit', "Edited category ID #{$id}: {$name}");
            setFlash('success', "Category <strong>" . htmlspecialchars($name) . "</strong> updated successfully!");
        }
    }
    header('Location: categories.php');
    exit;
}

// ── POST action=delete ──────────────────────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $id = (int)($_POST['id'] ?? 0);

    // Safeguard: Check if category has products
    $checkProds = $pdo->prepare('SELECT COUNT(*) FROM products WHERE category_id = ?');
    $checkProds->execute([$id]);
    $prodCount = (int)$checkProds->fetchColumn();

    if ($prodCount > 0) {
        setFlash('danger', "Cannot delete category: {$prodCount} product(s) are currently assigned to it. Please reassign or delete them first.");
        header('Location: categories.php');
        exit;
    }

    $ps = $pdo->prepare('SELECT name FROM categories WHERE id = ?');
    $ps->execute([$id]);
    $catName = $ps->fetchColumn();

    if ($catName) {
        $pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
        logAudit($pdo, 'category_delete', "Deleted category ID #{$id}: {$catName}");
        setFlash('success', "Category <strong>" . htmlspecialchars($catName) . "</strong> deleted successfully.");
    } else {
        setFlash('danger', 'Category not found.');
    }
    header('Location: categories.php');
    exit;
}

// ── Fetch Categories with Product Counts ────────────────────────────
$search = trim($_GET['search'] ?? '');
$where = '1=1';
$params = [];

if ($search !== '') {
    $where = '(c.name LIKE :s OR c.description LIKE :s)';
    $params[':s'] = '%' . $search . '%';
}

$stmt = $pdo->prepare("
    SELECT 
        c.*,
        COUNT(p.id) AS product_count,
        COALESCE(SUM(p.stock_quantity), 0) AS total_units,
        COALESCE(SUM(p.stock_quantity * p.purchase_price), 0) AS inventory_value
    FROM categories c
    LEFT JOIN products p ON c.id = p.category_id
    WHERE {$where}
    GROUP BY c.id
    ORDER BY c.name ASC
");
$stmt->execute($params);
$categories = $stmt->fetchAll();

$csrfToken = getCsrfToken();
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h2 class="fw-bold mb-1"><i class="bi bi-tags me-2 text-primary"></i>Category Management</h2>
        <p class="text-muted mb-0">Organize your inventory catalog into searchable product categories.</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCategoryModal">
        <i class="bi bi-plus-lg me-1"></i> Add Category
    </button>
</div>

<!-- Search Filter -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3">
        <form method="GET" action="categories.php" class="row g-2 align-items-center">
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control border-start-0" placeholder="Search categories by name or description..." value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100">Search</button>
                <?php if ($search !== ''): ?>
                    <a href="categories.php" class="btn btn-outline-secondary" title="Clear"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Categories Grid / Cards -->
<div class="row g-3">
    <?php if (empty($categories)): ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm text-center py-5">
                <i class="bi bi-tags fs-1 text-muted d-block mb-2"></i>
                <div class="text-muted">No categories found matching your query.</div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($categories as $cat): ?>
            <div class="col-md-6 col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <h5 class="fw-bold text-dark mb-0"><?= htmlspecialchars($cat['name']) ?></h5>
                            <span class="badge bg-primary-subtle text-primary-emphasis rounded-pill px-2.5 py-1">
                                <?= $cat['product_count'] ?> products
                            </span>
                        </div>
                        <p class="text-muted small mb-3" style="min-height: 40px;">
                            <?= !empty($cat['description']) ? htmlspecialchars($cat['description']) : '<em>No description provided.</em>' ?>
                        </p>
                        <div class="d-flex justify-content-between bg-light p-2 rounded small text-secondary mb-3">
                            <span>Total Units: <strong><?= number_format($cat['total_units']) ?></strong></span>
                            <span>Stock Value: <strong>$<?= number_format($cat['inventory_value'], 2) ?></strong></span>
                        </div>
                        <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                            <a href="products.php?category_id=<?= $cat['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-box-arrow-up-right me-1"></i> View Items
                            </a>
                            <div class="btn-group">
                                <button class="btn btn-sm btn-outline-primary btn-edit-cat" data-id="<?= $cat['id'] ?>" title="Edit Category">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger btn-delete-cat" data-id="<?= $cat['id'] ?>" data-name="<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>" data-count="<?= $cat['product_count'] ?>" title="Delete Category">
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

<!-- Modal: Add Category -->
<div class="modal fade" id="addCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="categories.php">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-tag me-2 text-primary"></i>Add Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Category Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required placeholder="e.g. Office Furniture">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Category notes or details..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Create Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Category -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="categories.php">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="id" id="editCatId">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Category Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="editCatName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" id="editCatDescription" class="form-control" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Delete Form -->
<form id="deleteCatForm" method="POST" action="categories.php">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <input type="hidden" name="id" id="deleteCatId">
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Edit Category
    document.querySelectorAll('.btn-edit-cat').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = this.getAttribute('data-id');
            fetch(`categories.php?action=get&id=${id}`)
                .then(r => r.json())
                .then(cat => {
                    if (cat.error) {
                        showToast(cat.error, 'danger');
                        return;
                    }
                    document.getElementById('editCatId').value = cat.id;
                    document.getElementById('editCatName').value = cat.name;
                    document.getElementById('editCatDescription').value = cat.description || '';
                    new bootstrap.Modal(document.getElementById('editCategoryModal')).show();
                });
        });
    });

    // Delete Category
    document.querySelectorAll('.btn-delete-cat').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');
            const count = parseInt(this.getAttribute('data-count'), 10);

            if (count > 0) {
                showToast(`Cannot delete "${name}" because it still contains ${count} product(s).`, 'warning');
                return;
            }

            confirmAction(
                `Are you sure you want to delete category "${name}"?`,
                function () {
                    document.getElementById('deleteCatId').value = id;
                    document.getElementById('deleteCatForm').submit();
                },
                'Delete Category',
                true
            );
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
