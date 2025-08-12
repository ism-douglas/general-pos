<?php
// public/api/sales.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../inc/auth.php';
require_login();

require_once __DIR__ . '/../../inc/db.php';

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// MPESA STK placeholder
function initiateMpesaStkPush(string $phone, float $amount, int $reference): array {
    return [
        'success' => true,
        'message' => "Simulated STK Push to {$phone} for KES {$amount}",
        'merchant_request_id' => uniqid('MR_'),
        'checkout_request_id' => uniqid('CR_'),
    ];
}

// read JSON
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$payment_method = $data['payment_method'] ?? null;
$items          = $data['items'] ?? null;
$customer_name  = $data['customer_name'] ?? null;
$customer_phone = $data['customer_phone'] ?? null;
$amount_tendered = isset($data['amount_tendered']) ? (float)$data['amount_tendered'] : null;

// Allowed payment methods
if (!in_array($payment_method, ['cash', 'mpesa', 'credit'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid payment_method']);
    exit;
}

// Items check
if (!is_array($items) || count($items) === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No items provided']);
    exit;
}

// Payment-specific validation
if ($payment_method === 'mpesa') {
    $phone = trim((string)$customer_phone);
    $valid = preg_match('/^(?:\+?254|0)7\d{8}$/', $phone);
    if (!$valid) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Valid MPESA phone number required']);
        exit;
    }
    if (strpos($phone, '+') === 0) $phone = substr($phone, 1);
    if (strpos($phone, '0') === 0) $phone = '254' . substr($phone, 1);
    if (strpos($phone, '254') !== 0) {
        $phone = '254' . preg_replace('/^\+?0?/', '', $phone);
    }
    $customer_phone = $phone;
} elseif ($payment_method === 'credit') {
    // For credit sales, name & phone required
    if (empty($customer_name) || empty($customer_phone)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Customer name and phone are required for credit sales']);
        exit;
    }
} else {
    // cash: phone optional
    $customer_phone = null;
}

// Calculate total & normalize items
$total_amount = 0.0;
$normalized_items = [];

foreach ($items as $it) {
    if (!isset($it['product_id'], $it['quantity'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Each item must include product_id and quantity']);
        exit;
    }
    $product_id = (int)$it['product_id'];
    $quantity   = (int)$it['quantity'];
    if ($quantity < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Quantity must be >= 1']);
        exit;
    }
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
        'quantity'   => $quantity,
        'price'      => $price
    ];
}

try {
    $pdo->beginTransaction();

    // For cash, ensure amount tendered covers total
    if ($payment_method === 'cash') {
        if ($amount_tendered < $total_amount) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Amount tendered is less than total amount']);
            exit;
        }
    }

    // Insert sale row
    $insertSale = $pdo->prepare("
        INSERT INTO sales (user_id, total_amount, payment_method, customer_name, customer_phone, amount_tendered, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");
    $insertSale->execute([
        $user_id,
        $total_amount,
        $payment_method,
        $customer_name,
        $customer_phone,
        $amount_tendered
    ]);
    $sale_id = (int)$pdo->lastInsertId();

    // Insert sale items + update stock
    $insertItem  = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
    $updateStock = $pdo->prepare("UPDATE products SET stock_qty = stock_qty - ? WHERE id = ? AND stock_qty >= ?");

    foreach ($normalized_items as $ni) {
        $updateStock->execute([$ni['quantity'], $ni['product_id'], $ni['quantity']]);
        if ($updateStock->rowCount() === 0) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => "Insufficient stock for product ID {$ni['product_id']}"]);
            exit;
        }
        $insertItem->execute([$sale_id, $ni['product_id'], $ni['quantity'], $ni['price']]);
    }

    // If MPESA, process
    $mpesa_response = null;
    if ($payment_method === 'mpesa') {
        $mpesa_response = initiateMpesaStkPush($customer_phone, $total_amount, $sale_id);
        if (empty($mpesa_response['success'])) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'MPESA STK Push failed', 'mpesa' => $mpesa_response]);
            exit;
        }
    }

    $pdo->commit();

    $change_due = null;
    if ($payment_method === 'cash' && $amount_tendered !== null) {
        $change_due = $amount_tendered - $total_amount;
    }

    $response = [
        'success'         => true,
        'sale_id'         => $sale_id,
        'total_amount'    => number_format($total_amount, 2, '.', ''),
        'amount_tendered' => $amount_tendered,
        'change_due'      => $change_due !== null ? number_format($change_due, 2, '.', '') : null
    ];
    if ($mpesa_response !== null) {
        $response['mpesa'] = $mpesa_response;
    }

    echo json_encode($response);
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage]()_
