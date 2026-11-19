<?php
/**
 * StockWise — Product Management
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Products';
requireLogin();
requireRole(['admin', 'manager']);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ── GET ?action=get&id=X (return JSON for edit modal) ───────────────
if ($action === 'get' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $product = $stmt->fetch();
    if (!$product) {
        http_response_code(404);
        echo json_encode(['error' => 'Product not found']);
        exit;
    }
    echo json_encode($product);
    exit;
}

// ── Image Upload Helper ─────────────────────────────────────────────
function handleProductImageUpload(array $file): ?string {
    $uploadDir = __DIR__ . '/assets/images/products/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowed, true)) {
        return null;
    }
    if ($file['size'] > 3 * 1024 * 1024) { // 3 MB max
        return null;
    }
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'prod_' . uniqid('', true) . '.' . strtolower($ext);
    $dest = $uploadDir . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return null;
    }
    return 'assets/images/products/' . $filename;
}

// ── POST action=add ─────────────────────────────────────────────────
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $name            = trim($_POST['name'] ?? '');
    $autoSku         = !empty($_POST['auto_sku']);
    $description     = trim($_POST['description'] ?? '');
    $category_id     = (int)($_POST['category_id'] ?? 0) ?: null;
    $supplier_id     = (int)($_POST['supplier_id'] ?? 0) ?: null;
    $purchase_price  = (float)($_POST['purchase_price'] ?? 0);
    $selling_price   = (float)($_POST['selling_price'] ?? 0);
    $stock_quantity  = (int)($_POST['stock_quantity'] ?? 0);
    $min_stock_level = (int)($_POST['min_stock_level'] ?? 5);

    if ($autoSku || empty($_POST['sku'])) {
        $sku = 'SKU-' . strtoupper(substr(md5(uniqid('', true)), 0, 8));
    } else {
        $sku = trim($_POST['sku']);
    }

    $errors = [];
    if ($name === '') $errors[] = 'Product name is required.';
    if ($selling_price <= 0) $errors[] = 'Selling price must be greater than zero.';

    // Check SKU duplicate
    $chk = $pdo->prepare('SELECT id FROM products WHERE sku = ?');
    $chk->execute([$sku]);
    if ($chk->fetchColumn()) {
        $errors[] = "SKU '{$sku}' already exists.";
    }

    $image_path = null;
    if (!empty($_FILES['image']['name'])) {
        $image_path = handleProductImageUpload($_FILES['image']);
        if ($image_path === null) {
            $errors[] = 'Image upload failed. Allowed formats: JPG, PNG, GIF, WEBP under 3MB.';
        }
    }

    if (!empty($errors)) {
        setFlash('danger', implode('<br>', $errors));
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO products (
                sku, name, description, category_id, supplier_id,
                purchase_price, selling_price, stock_quantity, min_stock_level,
                image_path, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $sku, $name, $description, $category_id, $supplier_id,
            $purchase_price, $selling_price, $stock_quantity, $min_stock_level, $image_path
        ]);
        $newId = (int)$pdo->lastInsertId();

        // Record initial inventory movement if stock > 0
        if ($stock_quantity > 0) {
            $mv = $pdo->prepare("
                INSERT INTO inventory_movements (
                    product_id, movement_type, quantity, qty_before, qty_after, reason, notes, user_id, created_at
                ) VALUES (?, 'stock_in', ?, 0, ?, 'Initial Stock', 'Added during product creation', ?, NOW())
            ");
            $mv->execute([$newId, $stock_quantity, $stock_quantity, $_SESSION['user']['id']]);
        }

        logAudit($pdo, 'product_create', "Created product {$sku} ({$name})");
        setFlash('success', "Product <strong>" . htmlspecialchars($name) . "</strong> created successfully!");
    }
    header('Location: products.php');
    exit;
}

// ── POST action=edit ────────────────────────────────────────────────
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $id              = (int)($_POST['id'] ?? 0);
    $name            = trim($_POST['name'] ?? '');
    $sku             = trim($_POST['sku'] ?? '');
    $description     = trim($_POST['description'] ?? '');
    $category_id     = (int)($_POST['category_id'] ?? 0) ?: null;
    $supplier_id     = (int)($_POST['supplier_id'] ?? 0) ?: null;
    $purchase_price  = (float)($_POST['purchase_price'] ?? 0);
    $selling_price   = (float)($_POST['selling_price'] ?? 0);
    $stock_quantity  = (int)($_POST['stock_quantity'] ?? 0);
    $min_stock_level = (int)($_POST['min_stock_level'] ?? 5);

    // Fetch existing
    $currStmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
    $currStmt->execute([$id]);
    $curr = $currStmt->fetch();

    if (!$curr) {
        setFlash('danger', 'Product not found.');
        header('Location: products.php');
        exit;
    }

    $errors = [];
    if ($name === '') $errors[] = 'Product name cannot be empty.';
    if ($sku === '')  $errors[] = 'SKU cannot be empty.';
    if ($selling_price <= 0) $errors[] = 'Selling price must be greater than zero.';

    // Check SKU duplicate
    $chk = $pdo->prepare('SELECT id FROM products WHERE sku = ? AND id != ?');
    $chk->execute([$sku, $id]);
    if ($chk->fetchColumn()) {
        $errors[] = "SKU '{$sku}' is already assigned to another product.";
    }

    $image_path = $curr['image_path'];
    if (!empty($_FILES['image']['name'])) {
        $uploaded = handleProductImageUpload($_FILES['image']);
        if ($uploaded === null) {
            $errors[] = 'Image upload failed. Allowed formats: JPG, PNG, GIF, WEBP under 3MB.';
        } else {
            $image_path = $uploaded;
        }
    }

    if (!empty($errors)) {
        setFlash('danger', implode('<br>', $errors));
    } else {
        $stmt = $pdo->prepare("
            UPDATE products SET
                sku = ?, name = ?, description = ?, category_id = ?, supplier_id = ?,
                purchase_price = ?, selling_price = ?, stock_quantity = ?, min_stock_level = ?,
                image_path = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $sku, $name, $description, $category_id, $supplier_id,
            $purchase_price, $selling_price, $stock_quantity, $min_stock_level,
            $image_path, $id
        ]);

        // Stock quantity diff tracking
        $oldQty = (int)$curr['stock_quantity'];
        if ($oldQty !== $stock_quantity) {
            $diff = $stock_quantity - $oldQty;
            $type = $diff > 0 ? 'stock_in' : 'adjustment';
            $mv = $pdo->prepare("
                INSERT INTO inventory_movements (
                    product_id, movement_type, quantity, qty_before, qty_after, reason, notes, user_id, created_at
                ) VALUES (?, ?, ?, ?, ?, 'Manual Stock Adjustment', 'Updated via Product Edit screen', ?, NOW())
            ");
            $mv->execute([$id, $type, abs($diff), $oldQty, $stock_quantity, $_SESSION['user']['id']]);
        }

        logAudit($pdo, 'product_edit', "Edited product ID #{$id}: {$name}");
        setFlash('success', "Product <strong>" . htmlspecialchars($name) . "</strong> updated successfully.");
    }
    header('Location: products.php');
    exit;
}

// ── POST action=delete ──────────────────────────────────────────────
if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrf();

    $id = (int)($_POST['id'] ?? 0);

    // Safeguard: Check if product has sales history
    $checkSales = $pdo->prepare('SELECT COUNT(*) FROM sale_items WHERE product_id = ?');
    $checkSales->execute([$id]);
    if ($checkSales->fetchColumn() > 0) {
        setFlash('danger', 'Cannot delete this product because it has associated sales records. Consider updating its stock to 0 instead.');
        header('Location: products.php');
        exit;
    }

    $ps = $pdo->prepare('SELECT name FROM products WHERE id = ?');
    $ps->execute([$id]);
    $prodName = $ps->fetchColumn();

    if ($prodName) {
        $pdo->prepare('DELETE FROM inventory_movements WHERE product_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
        logAudit($pdo, 'product_delete', "Deleted product ID #{$id} ({$prodName})");
        setFlash('success', "Product <strong>" . htmlspecialchars($prodName) . "</strong> deleted successfully.");
    } else {
        setFlash('danger', 'Product not found.');
    }
    header('Location: products.php');
    exit;
}

// ── Fetch Product Catalog for Display ───────────────────────────────
$search      = trim($_GET['search'] ?? '');
$categoryId  = (int)($_GET['category_id'] ?? 0);
$stockStatus = $_GET['stock_status'] ?? 'all';
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 15;
$offset      = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(p.name LIKE :search OR p.sku LIKE :search OR p.description LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}

if ($categoryId > 0) {
    $where[] = 'p.category_id = :cat_id';
    $params[':cat_id'] = $categoryId;
}

if ($stockStatus === 'in_stock') {
    $where[] = 'p.stock_quantity > p.min_stock_level';
} elseif ($stockStatus === 'low_stock') {
    $where[] = 'p.stock_quantity > 0 AND p.stock_quantity <= p.min_stock_level';
} elseif ($stockStatus === 'out_of_stock') {
    $where[] = 'p.stock_quantity = 0';
}

$whereSql = implode(' AND ', $where);

// Total count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE {$whereSql}");
$countStmt->execute($params);
$totalProducts = (int)$countStmt->fetchColumn();
$totalPages    = ceil($totalProducts / $perPage);

// Catalog list
$listStmt = $pdo->prepare("
    SELECT 
        p.*,
        COALESCE(c.name, 'Uncategorized') AS category_name,
        COALESCE(s.company_name, 'No Supplier') AS supplier_name
    FROM products p
    LEFT JOIN categories c ON p.category_id = c.id
    LEFT JOIN suppliers s ON p.supplier_id = s.id
    WHERE {$whereSql}
    ORDER BY p.name ASC
    LIMIT {$perPage} OFFSET {$offset}
");
$listStmt->execute($params);
$products = $listStmt->fetchAll();

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll();
$suppliers  = $pdo->query('SELECT id, company_name FROM suppliers ORDER BY company_name ASC')->fetchAll();
$csrfToken  = getCsrfToken();

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h2 class="fw-bold mb-1"><i class="bi bi-box-seam me-2 text-primary"></i>Product Catalog</h2>
        <p class="text-muted mb-0">Manage inventory items, pricing, SKU codes, and suppliers.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="stock_in.php" class="btn btn-outline-success">
            <i class="bi bi-box-arrow-in-down me-1"></i> Stock In
        </a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addProductModal">
            <i class="bi bi-plus-lg me-1"></i> Add New Product
        </button>
    </div>
</div>

<!-- Filters Card -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-3">
        <form method="GET" action="products.php" class="row g-2 align-items-center">
            <div class="col-md-4">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" class="form-control border-start-0" placeholder="Search by name, SKU..." value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>
            <div class="col-md-3">
                <select name="category_id" class="form-select">
                    <option value="0">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $categoryId === (int)$cat['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select name="stock_status" class="form-select">
                    <option value="all" <?= $stockStatus === 'all' ? 'selected' : '' ?>>All Stock Statuses</option>
                    <option value="in_stock" <?= $stockStatus === 'in_stock' ? 'selected' : '' ?>>In Stock</option>
                    <option value="low_stock" <?= $stockStatus === 'low_stock' ? 'selected' : '' ?>>Low Stock</option>
                    <option value="out_of_stock" <?= $stockStatus === 'out_of_stock' ? 'selected' : '' ?>>Out of Stock</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary w-100">Filter</button>
                <?php if ($search !== '' || $categoryId > 0 || $stockStatus !== 'all'): ?>
                    <a href="products.php" class="btn btn-outline-secondary" title="Clear Filters"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Products Table Card -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3" style="width: 60px;">Image</th>
                        <th>SKU</th>
                        <th>Product Name</th>
                        <th>Category</th>
                        <th class="text-end">Cost</th>
                        <th class="text-end">Price</th>
                        <th class="text-center">Stock</th>
                        <th>Supplier</th>
                        <th class="text-end pe-3" style="width: 140px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-5 text-muted">
                                <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary"></i>
                                No products found matching your search criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($products as $p): ?>
                            <tr>
                                <td class="ps-3">
                                    <?php if (!empty($p['image_path']) && file_exists(__DIR__ . '/' . $p['image_path'])): ?>
                                        <img src="<?= BASE_URL ?>/<?= htmlspecialchars($p['image_path']) ?>" alt="" class="rounded object-fit-cover" style="width: 44px; height: 44px;">
                                    <?php else: ?>
                                        <div class="bg-light text-secondary rounded d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                                            <i class="bi bi-image fs-5"></i>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark font-monospace border"><?= htmlspecialchars($p['sku']) ?></span>
                                </td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($p['name']) ?></div>
                                    <?php if (!empty($p['description'])): ?>
                                        <small class="text-muted text-truncate d-block" style="max-width: 250px;"><?= htmlspecialchars($p['description']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-secondary-subtle text-secondary-emphasis rounded-pill px-2 py-1">
                                        <?= htmlspecialchars($p['category_name']) ?>
                                    </span>
                                </td>
                                <td class="text-end text-muted font-monospace">$<?= number_format($p['purchase_price'], 2) ?></td>
                                <td class="text-end fw-bold font-monospace text-primary">$<?= number_format($p['selling_price'], 2) ?></td>
                                <td class="text-center">
                                    <?php if ($p['stock_quantity'] <= 0): ?>
                                        <span class="badge bg-danger rounded-pill px-2">Out of Stock (0)</span>
                                    <?php elseif ($p['stock_quantity'] <= $p['min_stock_level']): ?>
                                        <span class="badge bg-warning text-dark rounded-pill px-2">Low Stock (<?= $p['stock_quantity'] ?>)</span>
                                    <?php else: ?>
                                        <span class="badge bg-success rounded-pill px-2"><?= $p['stock_quantity'] ?> in stock</span>
                                    <?php endif; ?>
                                </td>
                                <td><small class="text-muted"><?= htmlspecialchars($p['supplier_name']) ?></small></td>
                                <td class="text-end pe-3">
                                    <button class="btn btn-sm btn-outline-primary me-1 btn-edit" data-id="<?= $p['id'] ?>" title="Edit Product">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger btn-delete" data-id="<?= $p['id'] ?>" data-name="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>" title="Delete Product">
                                        <i class="bi bi-trash"></i>
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
                <small class="text-muted">Showing <?= $offset + 1 ?> to <?= min($offset + $perPage, $totalProducts) ?> of <?= $totalProducts ?> products</small>
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

<!-- Modal: Add Product -->
<div class="modal fade" id="addProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="products.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-box-seam me-2 text-primary"></i>Add New Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required placeholder="e.g. Ergonomic Office Chair">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">SKU / Barcode</label>
                            <div class="input-group">
                                <input type="text" name="sku" id="addSkuInput" class="form-control font-monospace" placeholder="e.g. OFC-001">
                            </div>
                            <div class="form-check mt-1">
                                <input class="form-check-input" type="checkbox" name="auto_sku" id="autoSkuCheck" value="1" checked>
                                <label class="form-check-label small text-muted" for="autoSkuCheck">Auto-generate SKU</label>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Category</label>
                            <select name="category_id" class="form-select">
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Supplier</label>
                            <select name="supplier_id" class="form-select">
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $sup): ?>
                                    <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['company_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Cost / Purchase Price ($)</label>
                            <input type="number" step="0.01" min="0" name="purchase_price" class="form-control font-monospace" value="0.00">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Selling Price ($) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0.01" name="selling_price" class="form-control font-monospace" required value="0.00">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Initial Stock Quantity</label>
                            <input type="number" min="0" name="stock_quantity" class="form-control font-monospace" value="0">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Minimum Stock Warning Level</label>
                            <input type="number" min="1" name="min_stock_level" class="form-control font-monospace" value="5">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea name="description" class="form-control" rows="2" placeholder="Brief product summary..."></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Product Image (Optional)</label>
                            <input type="file" name="image" class="form-control" accept="image/*">
                            <div class="form-text">Supported: JPG, PNG, GIF, WEBP up to 3MB.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-check-lg me-1"></i> Save Product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Edit Product -->
<div class="modal fade" id="editProductModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST" action="products.php" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="id" id="editId">

                <div class="modal-header">
                    <h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2 text-primary"></i>Edit Product</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" id="editName" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">SKU / Barcode <span class="text-danger">*</span></label>
                            <input type="text" name="sku" id="editSku" class="form-control font-monospace" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Category</label>
                            <select name="category_id" id="editCategory" class="form-select">
                                <option value="">Select Category</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Supplier</label>
                            <select name="supplier_id" id="editSupplier" class="form-select">
                                <option value="">Select Supplier</option>
                                <?php foreach ($suppliers as $sup): ?>
                                    <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['company_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Cost / Purchase Price ($)</label>
                            <input type="number" step="0.01" min="0" name="purchase_price" id="editPurchasePrice" class="form-control font-monospace">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Selling Price ($) <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0.01" name="selling_price" id="editSellingPrice" class="form-control font-monospace" required>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Current Stock Quantity</label>
                            <input type="number" min="0" name="stock_quantity" id="editStockQuantity" class="form-control font-monospace">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Minimum Stock Warning Level</label>
                            <input type="number" min="1" name="min_stock_level" id="editMinStockLevel" class="form-control font-monospace">
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Description</label>
                            <textarea name="description" id="editDescription" class="form-control" rows="2"></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Update Image (Optional)</label>
                            <input type="file" name="image" class="form-control" accept="image/*">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4"><i class="bi bi-save me-1"></i> Update Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Delete Confirmation -->
<form id="deleteForm" method="POST" action="products.php">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <input type="hidden" name="id" id="deleteId">
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // If URL has action=add, show addProductModal automatically
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('action') === 'add') {
        const addModalEl = document.getElementById('addProductModal');
        if (addModalEl) {
            new bootstrap.Modal(addModalEl).show();
        }
    }

    // Auto-SKU checkbox behavior
    const autoSkuCheck = document.getElementById('autoSkuCheck');
    const addSkuInput = document.getElementById('addSkuInput');
    if (autoSkuCheck && addSkuInput) {
        autoSkuCheck.addEventListener('change', function () {
            addSkuInput.disabled = this.checked;
            if (this.checked) {
                addSkuInput.value = '';
                addSkuInput.placeholder = 'Auto-generated on save';
            } else {
                addSkuInput.placeholder = 'e.g. OFC-001';
                addSkuInput.focus();
            }
        });
        if (autoSkuCheck.checked) {
            addSkuInput.disabled = true;
            addSkuInput.placeholder = 'Auto-generated on save';
        }
    }

    // Edit Product Modal population
    document.querySelectorAll('.btn-edit').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = this.getAttribute('data-id');
            fetch(`products.php?action=get&id=${id}`)
                .then(r => r.json())
                .then(prod => {
                    if (prod.error) {
                        showToast(prod.error, 'danger');
                        return;
                    }
                    document.getElementById('editId').value = prod.id;
                    document.getElementById('editName').value = prod.name;
                    document.getElementById('editSku').value = prod.sku;
                    document.getElementById('editCategory').value = prod.category_id || '';
                    document.getElementById('editSupplier').value = prod.supplier_id || '';
                    document.getElementById('editPurchasePrice').value = parseFloat(prod.purchase_price).toFixed(2);
                    document.getElementById('editSellingPrice').value = parseFloat(prod.selling_price).toFixed(2);
                    document.getElementById('editStockQuantity').value = prod.stock_quantity;
                    document.getElementById('editMinStockLevel').value = prod.min_stock_level;
                    document.getElementById('editDescription').value = prod.description || '';

                    new bootstrap.Modal(document.getElementById('editProductModal')).show();
                })
                .catch(err => {
                    showToast('Failed to load product details.', 'danger');
                });
        });
    });

    // Delete Product
    document.querySelectorAll('.btn-delete').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = this.getAttribute('data-id');
            const name = this.getAttribute('data-name');
            confirmAction(
                `Are you sure you want to delete "${name}"? This action cannot be undone.`,
                function () {
                    document.getElementById('deleteId').value = id;
                    document.getElementById('deleteForm').submit();
                },
                'Delete Product',
                true
            );
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
