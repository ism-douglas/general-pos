<?php
// public/api/sales.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../inc/auth.php';
require_login();

require_once __DIR__ . '/../../inc/db.php';
// session_start();
$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

/**
 * Placeholder for MPESA STK Push.
 * Replace with actual Safaricom Daraja integration.
 * Should return array with 'success' (bool) and optionally other info.
 */
function initiateMpesaStkPush(string $phone, float $amount, int $reference): array {
    // simulate network delay / processing
    // In real implementation: request token -> STK Push -> return actual response
    return [
        'success' => true,
        'message' => "Simulated STK Push to {$phone} for KES {$amount}",
        'merchant_request_id' => uniqid('MR_'),
        'checkout_request_id' => uniqid('CR_'),
    ];
}

// read and validate JSON input
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$payment_method = $data['payment_method'] ?? null;
$items = $data['items'] ?? null;
$customer_phone = $data['customer_phone'] ?? null;

// Basic validation
if (!in_array($payment_method, ['cash', 'mpesa'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid payment_method']);
    exit;
}

if (!is_array($items) || count($items) === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No items provided']);
    exit;
}

// If MPESA, require valid phone
if ($payment_method === 'mpesa') {
    // accept formats like 2547XXXXXXXX, +2547XXXXXXXX, 07XXXXXXXX
    $phone = trim((string)$customer_phone);
    $valid = preg_match('/^(?:\+?254|0)7\d{8}$/', $phone);
    if (!$valid) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Valid MPESA phone number required (e.g. 2547XXXXXXXX or 07XXXXXXXX)']);
        exit;
    }
    // normalize to 2547XXXXXXXX
    if (strpos($phone, '+') === 0) $phone = substr($phone, 1);
    if (strpos($phone, '0') === 0) $phone = '254' . substr($phone, 1);
    if (strpos($phone, '254') !== 0) {
        // fallback - ensure safe format
        $phone = '254' . preg_replace('/^\+?0?/', '', $phone);
    }
    $customer_phone = $phone;
} else {
    // for cash payments store NULL in DB
    $customer_phone = null;
}

// Validate and compute total
$total_amount = 0.0;
$normalized_items = []; // each: ['product_id' => int, 'quantity' => int, 'price' => float]
foreach ($items as $it) {
    if (!isset($it['product_id'], $it['quantity'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Each item must include product_id and quantity']);
        exit;
    }

    $product_id = (int)$it['product_id'];
    $quantity = (int)$it['quantity'];
    if ($quantity < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Quantity must be >= 1']);
        exit;
    }

    // price can be provided by client (trusted UI) or fetched from DB server-side.
    // To be safe, fetch the current price from DB and use that as price_at_sale.
    $price = null;
    $stmt = $pdo->prepare("SELECT price FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Product ID {$product_id} not found"]);
        exit;
    }
    $price = (float)$row['price'];

    $total_amount += $price * $quantity;
    $normalized_items[] = [
        'product_id' => $product_id,
        'quantity' => $quantity,
        'price' => $price
    ];
}

try {
    // Begin transaction
    $pdo->beginTransaction();

    // Insert sale row (customer_phone may be NULL)
    $insertSale = $pdo->prepare("INSERT INTO sales (user_id, total_amount, payment_method, customer_phone, created_at) VALUES (?, ?, ?, ?, NOW())");
    $insertSale->execute([$user_id, $total_amount, $payment_method, $customer_phone]);
    $amount_tendered = isset($data['amount_tendered']) ? (float)$data['amount_tendered'] : null;

    if ($payment_method === 'cash') {
        if ($amount_tendered < $total_amount) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Amount tendered is less than total amount']);
            exit;
        }
    }

    // In the INSERT:
    $insertSale = $pdo->prepare("
        INSERT INTO sales (user_id, total_amount, payment_method, customer_phone, amount_tendered, created_at) 
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $insertSale->execute([$user_id, $total_amount, $payment_method, $customer_phone, $amount_tendered]);
    $sale_id = (int)$pdo->lastInsertId();

    // Prepare statements for inserting items and updating stock
    $insertItem = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
    // Ensure we only deduct stock if stock_qty >= requested quantity
    $updateStock = $pdo->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ? AND stock_qty >= ?");

    foreach ($normalized_items as $ni) {
        // Deduct stock
        $updateStock->execute([$ni['quantity'], $ni['product_id'], $ni['quantity']]);
        if ($updateStock->rowCount() === 0) {
            // insufficient stock for this product
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Insufficient stock for product ID {$ni['product_id']}"]);
            exit;
        }

        // Insert sale item with price snapshot
        $insertItem->execute([$sale_id, $ni['product_id'], $ni['quantity'], $ni['price']]);
    }

    // If MPESA, initiate STK push (simulated)
    $mpesa_response = null;
    if ($payment_method === 'mpesa') {
        $mpesa_response = initiateMpesaStkPush($customer_phone, $total_amount, $sale_id);
        if (!isset($mpesa_response['success']) || $mpesa_response['success'] !== true) {
            // MPESA failed -> rollback
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'MPESA STK Push failed', 'mpesa' => $mpesa_response]);
            exit;
        }
    }

    // All good -> commit
    $pdo->commit();

    $response = [
        'success' => true,
        'sale_id' => $sale_id,
        'total_amount' => number_format($total_amount, 2, '.', '')
    ];
    if ($mpesa_response !== null) $response['mpesa'] = $mpesa_response;

    echo json_encode($response);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    exit;
}
