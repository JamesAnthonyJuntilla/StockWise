<?php
/**
 * StockWise — AJAX Get Single Product Endpoint
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid product ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            COALESCE(c.name, 'Uncategorized') AS category_name,
            COALESCE(s.company_name, 'None') AS supplier_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.id
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        WHERE p.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        http_response_code(404);
        echo json_encode(['error' => 'Product not found']);
        exit;
    }

    $product['id']              = (int)$product['id'];
    $product['selling_price']   = (float)$product['selling_price'];
    $product['purchase_price']  = (float)$product['purchase_price'];
    $product['stock_quantity']  = (int)$product['stock_quantity'];
    $product['min_stock_level'] = (int)$product['min_stock_level'];
    $product['formatted_price'] = '$' . number_format($product['selling_price'], 2);

    echo json_encode(['success' => true, 'product' => $product]);
} catch (PDOException $e) {
    error_log('AJAX get product error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Database error occurred.']);
}
