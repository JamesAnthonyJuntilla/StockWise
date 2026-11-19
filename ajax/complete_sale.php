<?php
/**
 * StockWise — AJAX Complete Sale Endpoint
 * Handles atomic checkout, stock reduction, inventory movement recording, and receipt generation.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'User session expired. Please log in again.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// Read raw JSON body
$input = file_get_contents('php://input');
$data  = json_decode($input, true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload received.']);
    exit;
}

$items          = $data['items'] ?? [];
$subtotal       = (float)($data['subtotal'] ?? 0);
$discountType   = $data['discount_type'] ?? 'fixed'; // 'fixed' or 'percent'
$discountVal    = (float)($data['discount_value'] ?? 0);
$discountAmount = (float)($data['discount_amount'] ?? 0);
$totalAmount    = (float)($data['total_amount'] ?? 0);
$paymentAmount  = (float)($data['payment_amount'] ?? 0);
$paymentMethod  = trim($data['payment_method'] ?? 'Cash');
$notes          = trim($data['notes'] ?? '');

if (empty($items) || !is_array($items)) {
    echo json_encode(['success' => false, 'error' => 'Your shopping cart is empty.']);
    exit;
}

if ($paymentAmount < $totalAmount) {
    echo json_encode([
        'success' => false, 
        'error' => sprintf('Payment received ($%.2f) is less than the total balance ($%.2f).', $paymentAmount, $totalAmount)
    ]);
    exit;
}

$changeAmount = max(0, $paymentAmount - $totalAmount);
$userId       = $_SESSION['user']['id'];
$cashierName  = $_SESSION['user']['full_name'] ?? $_SESSION['user']['username'];

// Start database transaction
try {
    $pdo->beginTransaction();

    // 1. Generate unique receipt number
    $receiptNo = 'RCP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));

    // 2. Validate current stock for each item before writing
    $itemDetails = [];
    $calculatedSubtotal = 0.0;

    foreach ($items as $item) {
        $prodId = (int)($item['id'] ?? 0);
        $qty    = (int)($item['qty'] ?? 0);

        if ($prodId <= 0 || $qty <= 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => 'Invalid product or quantity in cart.']);
            exit;
        }

        // Fetch product with row locking
        $chkStmt = $pdo->prepare("SELECT id, name, sku, selling_price, stock_quantity FROM products WHERE id = :id FOR UPDATE");
        $chkStmt->execute([':id' => $prodId]);
        $prod = $chkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$prod) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'error' => "Product ID #{$prodId} no longer exists."]);
            exit;
        }

        if ($prod['stock_quantity'] < $qty) {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'error' => "Insufficient stock for '{$prod['name']}'. Available: {$prod['stock_quantity']}, Requested: {$qty}."
            ]);
            exit;
        }

        $price = (float)$prod['selling_price'];
        $lineSubtotal = round($price * $qty, 2);
        $calculatedSubtotal += $lineSubtotal;

        $itemDetails[] = [
            'product_id'   => $prod['id'],
            'name'         => $prod['name'],
            'sku'          => $prod['sku'],
            'price'        => $price,
            'qty'          => $qty,
            'line_subtotal'=> $lineSubtotal,
            'stock_before' => (int)$prod['stock_quantity'],
            'stock_after'  => (int)$prod['stock_quantity'] - $qty,
        ];
    }

    // 3. Insert into sales table
    $saleStmt = $pdo->prepare("
        INSERT INTO sales (
            receipt_no, user_id, subtotal, discount_amount, total_amount, payment_amount, change_amount, notes, created_at
        ) VALUES (
            :receipt_no, :user_id, :subtotal, :discount_amount, :total_amount, :payment_amount, :change_amount, :notes, NOW()
        )
    ");
    $saleStmt->execute([
        ':receipt_no'      => $receiptNo,
        ':user_id'         => $userId,
        ':subtotal'        => $calculatedSubtotal,
        ':discount_amount' => $discountAmount,
        ':total_amount'    => $totalAmount,
        ':payment_amount'  => $paymentAmount,
        ':change_amount'   => $changeAmount,
        ':notes'           => $notes,
    ]);

    $saleId = (int)$pdo->lastInsertId();

    // 4. Insert sale items & deduct inventory
    $itemInsertStmt = $pdo->prepare("
        INSERT INTO sale_items (
            sale_id, product_id, product_name, selling_price, quantity, subtotal
        ) VALUES (
            :sale_id, :product_id, :product_name, :selling_price, :quantity, :subtotal
        )
    ");

    $stockUpdateStmt = $pdo->prepare("
        UPDATE products 
        SET stock_quantity = stock_quantity - :qty, updated_at = NOW() 
        WHERE id = :id
    ");

    $movementStmt = $pdo->prepare("
        INSERT INTO inventory_movements (
            product_id, movement_type, quantity, qty_before, qty_after, reason, notes, user_id, created_at
        ) VALUES (
            :product_id, 'sale', :quantity, :qty_before, :qty_after, :reason, :notes, :user_id, NOW()
        )
    ");

    foreach ($itemDetails as $item) {
        // Line item
        $itemInsertStmt->execute([
            ':sale_id'       => $saleId,
            ':product_id'    => $item['product_id'],
            ':product_name'  => $item['name'],
            ':selling_price' => $item['price'],
            ':quantity'      => $item['qty'],
            ':subtotal'      => $item['line_subtotal'],
        ]);

        // Stock deduction
        $stockUpdateStmt->execute([
            ':qty' => $item['qty'],
            ':id'  => $item['product_id'],
        ]);

        // Inventory movement log
        $movementStmt->execute([
            ':product_id' => $item['product_id'],
            ':quantity'   => $item['qty'],
            ':qty_before' => $item['stock_before'],
            ':qty_after'  => $item['stock_after'],
            ':reason'     => "Sale #{$receiptNo}",
            ':notes'      => "Cashier: {$cashierName}",
            ':user_id'    => $userId,
        ]);
    }

    // 5. System Audit Log
    logAudit($pdo, 'sale_completed', "Sale {$receiptNo} completed. Total: $" . number_format($totalAmount, 2));

    // Commit transaction
    $pdo->commit();

    // Prepare printable receipt payload
    $receiptPayload = [
        'sale_id'        => $saleId,
        'receipt_no'     => $receiptNo,
        'date'           => date('M d, Y h:i A'),
        'cashier_name'   => $cashierName,
        'items'          => $itemDetails,
        'subtotal'       => $calculatedSubtotal,
        'discount_amount'=> $discountAmount,
        'total_amount'   => $totalAmount,
        'payment_amount' => $paymentAmount,
        'change_amount'  => $changeAmount,
        'payment_method' => $paymentMethod,
        'notes'          => $notes,
    ];

    echo json_encode([
        'success'   => true,
        'receipt'   => $receiptPayload,
        'message'   => 'Sale completed successfully!'
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Sale completion error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Checkout failed: ' . $e->getMessage()]);
}
