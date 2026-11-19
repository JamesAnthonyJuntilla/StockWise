<?php
/**
 * StockWise — AJAX Product Search Endpoint
 * Returns JSON array of products for POS and live autocomplete search.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

// Authentication check
if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$query      = trim($_GET['q'] ?? '');
$categoryId = (int)($_GET['category_id'] ?? 0);
$inStockOnly= isset($_GET['in_stock']) ? (bool)$_GET['in_stock'] : false;

try {
    $where  = ['1=1'];
    $params = [];

    if ($query !== '') {
        $where[] = '(p.name LIKE :q OR p.sku LIKE :q OR c.name LIKE :q)';
        $params[':q'] = '%' . $query . '%';
    }

    if ($categoryId > 0) {
        $where[] = 'p.category_id = :cat_id';
        $params[':cat_id'] = $categoryId;
    }

    if ($inStockOnly) {
        $where[] = 'p.stock_quantity > 0';
    }

    $sql = "
        SELECT 
            p.id,
            p.sku,
            p.name,
            p.description,
            p.selling_price,
            p.purchase_price,
            p.stock_quantity,
            p.min_stock_level,
            p.image_path,
            COALESCE(c.name, 'Uncategorized') AS category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY p.name ASC
        LIMIT 50
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format fields
    foreach ($products as &$prod) {
        $prod['id']             = (int)$prod['id'];
        $prod['selling_price']  = (float)$prod['selling_price'];
        $prod['purchase_price'] = (float)$prod['purchase_price'];
        $prod['stock_quantity'] = (int)$prod['stock_quantity'];
        $prod['min_stock_level']= (int)$prod['min_stock_level'];
        $prod['formatted_price']= '$' . number_format($prod['selling_price'], 2);
    }

    echo json_encode(['success' => true, 'products' => $products]);
} catch (PDOException $e) {
    error_log('AJAX search error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error occurred.']);
}
