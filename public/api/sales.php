<?php
// sales.php
require_once '../../inc/db.php';
require_once '../../inc/auth.php';
require_login();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$payment_method = $data['payment_method'] ?? '';
$items = $data['items'] ?? [];

if (!in_array($payment_method, ['cash', 'mpesa'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payment method']);
    exit;
}

if (empty($items)) {
    http_response_code(400);
    echo json_encode(['error' => 'No items in sale']);
    exit;
}

// Begin transaction
$pdo->beginTransaction();

try {
    $user_id = current_user_id();
    $total_amount = 0;

    // Calculate total & validate stock
    foreach ($items as $item) {
        $product_id = intval($item['product_id']);
        $qty = intval($item['quantity']);
        if ($qty < 1) throw new Exception('Invalid quantity');

        $stmt = $pdo->prepare("SELECT price, stock_qty FROM products WHERE id=? FOR UPDATE");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();
        if (!$product) throw new Exception("Product ID $product_id not found");
        if ($product['stock_qty'] < $qty) throw new Exception("Insufficient stock for product ID $product_id");

        $total_amount += $product['price'] * $qty;
    }

    // Insert sale
    $stmt = $pdo->prepare("INSERT INTO sales (user_id, total_amount, payment_method) VALUES (?, ?, ?)");
    $stmt->execute([$user_id, $total_amount, $payment_method]);
    $sale_id = $pdo->lastInsertId();

    // Insert sale_items and update stock
    foreach ($items as $item) {
        $product_id = intval($item['product_id']);
        $qty = intval($item['quantity']);
        $stmt = $pdo->prepare("SELECT price, stock_qty FROM products WHERE id=? FOR UPDATE");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();

        $price_at_sale = $product['price'];

        $stmt = $pdo->prepare("INSERT INTO sale_items (sale_id, product_id, quantity, price_at_sale) VALUES (?, ?, ?, ?)");
        $stmt->execute([$sale_id, $product_id, $qty, $price_at_sale]);

        // Update stock
        $new_stock = $product['stock_qty'] - $qty;
        $stmt = $pdo->prepare("UPDATE products SET stock_qty=? WHERE id=?");
        $stmt->execute([$new_stock, $product_id]);
    }

    // Commit transaction
    $pdo->commit();

    // TODO: For MPESA payment, call mpesa API here (simulate for now)

    echo json_encode(['success' => true, 'sale_id' => $sale_id]);

} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
