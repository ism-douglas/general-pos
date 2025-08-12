<?php
// receipt.php
require_once '../../inc/db.php';
require_once '../../inc/auth.php';
require_login();

$sale_id = intval($_GET['sale_id'] ?? 0);
if (!$sale_id) {
    http_response_code(400);
    exit('Sale ID required');
}

// Fetch sale + user
$stmt = $pdo->prepare("SELECT s.*, u.username 
    FROM sales s 
    JOIN users u ON s.user_id = u.id 
    WHERE s.id = ?");
$stmt->execute([$sale_id]);
$sale = $stmt->fetch();

//Get the change due
$change_due = null;
if ($sale['payment_method'] === 'cash' && !is_null($sale['amount_tendered'])) {
    $change_due = $sale['amount_tendered'] - $sale['total_amount'];
}

if (!$sale) {
    http_response_code(404);
    exit('Sale not found');
}

// Fetch sale items — alias price to price_at_sale for UI compatibility
$stmt = $pdo->prepare("
    SELECT si.*, p.name, si.price AS price_at_sale
    FROM sale_items si
    JOIN products p ON si.product_id = p.id
    WHERE si.sale_id = ?
");
$stmt->execute([$sale_id]);
$items = $stmt->fetchAll();

$datetime = date('Y-m-d H:i:s', strtotime($sale['created_at']));
?>
<div style="font-family: monospace; font-size: 12px; max-width: 300px;">
  <div style="text-align:center;">
    <h3>Smart POS </h3>
    <p>Date: <?=htmlspecialchars($datetime)?></p>
    <hr>
  </div>

  <table style="width: 100%;">
    <?php foreach ($items as $item): ?>
      <tr>
        <td><?=htmlspecialchars($item['name'])?> x<?=$item['quantity']?></td>
        <td style="text-align:right">
          KES <?=number_format($item['price_at_sale'] * $item['quantity'], 2)?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>

  <hr>
  <div style="text-align:right; font-weight:bold;">
    Total: KES <?=number_format($sale['total_amount'], 2)?>
  </div>
  <div>
    Payment Method: <?=htmlspecialchars(strtoupper($sale['payment_method']))?>
  </div>

<?php if ($sale['payment_method'] === 'cash'): ?>
  <div>Amount Tendered: KES <?=number_format($sale['amount_tendered'], 2)?></div>
  <div>Change Due: KES <?=number_format($change_due, 2)?></div>
<?php elseif ($sale['payment_method'] === 'credit'): ?>
  <div>Customer Name: <?=htmlspecialchars($sale['customer_name'])?></div>
  <div>Customer Phone: <?=htmlspecialchars($sale['customer_phone'])?></div>
<?php endif; ?>

  <hr>
  <div style="text-align:center;">Thank you for your business!</div>
</div>
