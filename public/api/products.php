<?php
// products.php
require_once '../../inc/db.php';
require_once '../../inc/auth.php';
require_login();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        // List all products
        $stmt = $pdo->query("SELECT * FROM products ORDER BY created_at DESC");
        $products = $stmt->fetchAll();
        echo json_encode(['products' => $products]);
        break;

    case 'add':
    case 'edit':
        // Add or Edit product
        $data = json_decode(file_get_contents('php://input'), true);

        $name = trim($data['name'] ?? '');
        $category = trim($data['category'] ?? '');
        $price = floatval($data['price'] ?? 0);
        $stock_qty = intval($data['stock_qty'] ?? 0);
        $barcode = trim($data['barcode'] ?? '');
        $id = intval($data['id'] ?? 0);

        if (!$name || $price < 0 || $stock_qty < 0) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid product data']);
            exit;
        }

        if ($action === 'add') {
            $stmt = $pdo->prepare("INSERT INTO products (name, category, price, stock_qty, barcode) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $category, $price, $stock_qty, $barcode]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        } else {
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Product ID required for edit']);
                exit;
            }
            $stmt = $pdo->prepare("UPDATE products SET name=?, category=?, price=?, stock_qty=?, barcode=? WHERE id=?");
            $stmt->execute([$name, $category, $price, $stock_qty, $barcode, $id]);
            echo json_encode(['success' => true]);
        }
        break;

    case 'delete':
        $data = json_decode(file_get_contents('php://input'), true);
        $id = intval($data['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Product ID required']);
            exit;
        }
        $stmt = $pdo->prepare("DELETE FROM products WHERE id=?");
        $stmt->execute([$id]);
        echo json_encode(['success' => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Invalid action']);
        break;
}
