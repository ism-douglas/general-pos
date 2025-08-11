<?php
require_once __DIR__ . '/../inc/auth.php';
require_login(); // Redirects to login if not logged in
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>POS Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet"/>
  <style>
    body { background: #f5f7fa; }
    .product-card { transition: transform 0.15s ease; cursor: pointer; }
    .product-card:hover { transform: scale(1.02); box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
    .cart-table th, .cart-table td { vertical-align: middle; }
  </style>
</head>
<body>
<div class="container-fluid py-3">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="fw-bold">POS Dashboard</h3>
    <a href="logout.php" class="btn btn-outline-danger btn-sm">Logout</a>
  </div>

  <div class="row">
    <!-- Product search & list -->
    <div class="col-lg-8">
      <div class="mb-3">
        <input type="text" id="searchBox" class="form-control" placeholder="Search products by name or barcode...">
      </div>
      <div id="productList" class="row g-3"></div>
    </div>

    <!-- Cart panel -->
    <div class="col-lg-4">
      <div class="card shadow-sm">
        <div class="card-header fw-bold">Sale Cart</div>
        <div class="card-body">
          <table class="table table-sm cart-table">
            <thead>
              <tr>
                <th>Item</th>
                <th>Qty</th>
                <th>Price</th>
                <th>Total</th>
                <th></th>
              </tr>
            </thead>
            <tbody id="cartItems"></tbody>
          </table>
          <div class="d-flex justify-content-between align-items-center fs-5 fw-bold mb-3">
            <span>Total:</span>
            <span id="cartTotal">KES 0.00</span>
          </div>

          <!-- Payment selector -->
          <div class="mb-3">
            <label for="paymentMethod" class="form-label">Payment Method</label>
            <select id="paymentMethod" class="form-select">
              <option value="cash" selected>Cash</option>
              <option value="mpesa">MPESA</option>
            </select>
          </div>

          <!-- Amount tendered -->
          <div class="mb-3" id="amountTenderedGroup">
            <label for="amount_tendered" class="form-label">Amount Tendered</label>
            <input type="number" step="0.01" class="form-control" id="amount_tendered">
          </div>


          <div class="mb-3" id="mpesaPhoneGroup" style="display:none;">
            <label for="mpesaPhone" class="form-label">MPESA Phone Number</label>
            <input
              type="tel"
              id="mpesaPhone"
              class="form-control"
              placeholder="2547XXXXXXXX"
              pattern="2547\d{8}"
            />
            <div class="form-text">Enter phone number starting with 2547...</div>
          </div>

          <button class="btn btn-success w-100" id="btnCompleteSale">
            <i class="bi bi-check-circle me-1"></i> Complete Sale
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Receipt Modal -->
<div class="modal fade" id="receiptModal" tabindex="-1" aria-labelledby="receiptModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-sm">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="receiptModalLabel">Sale Receipt</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="receiptContent" style="font-family: monospace; font-size: 12px;"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-primary" id="btnPrintReceipt">Print Receipt</button>
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let products = [];
let cart = [];

const productListEl = document.getElementById('productList');
const searchBox = document.getElementById('searchBox');
const cartItemsEl = document.getElementById('cartItems');
const cartTotalEl = document.getElementById('cartTotal');
const btnCompleteSale = document.getElementById('btnCompleteSale');
const paymentMethodEl = document.getElementById('paymentMethod');
const mpesaPhoneGroupEl = document.getElementById('mpesaPhoneGroup');
const mpesaPhoneEl = document.getElementById('mpesaPhone');
const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));
const receiptContentEl = document.getElementById('receiptContent');
const btnPrintReceipt = document.getElementById('btnPrintReceipt');

paymentMethodEl.addEventListener('change', () => {
  mpesaPhoneGroupEl.style.display = paymentMethodEl.value === 'mpesa' ? 'block' : 'none';
  if (paymentMethodEl.value !== 'mpesa') mpesaPhoneEl.value = '';
});

function fetchProducts(query = '') {
  fetch(`api/products.php?search=${encodeURIComponent(query)}`)
    .then(res => res.json())
    .then(data => {
      products = data;
      renderProducts();
    });
}

// function renderProducts() {
//   productListEl.innerHTML = '';
//   products.forEach(prod => {
//     const col = document.createElement('div');
//     col.className = 'col-md-4';
//     col.innerHTML = `
//       <div class="card product-card p-2 h-100" onclick="addToCart(${prod.id})">
//         <div class="card-body">
//           <h6 class="card-title">${prod.name}</h6>
//           <p class="card-text small mb-1 text-muted">${prod.category || ''}</p>
//           <p class="fw-bold mb-0">KES ${parseFloat(prod.price).toFixed(2)}</p>
//           <span class="badge bg-secondary">Stock: ${prod.stock_qty}</span>
//         </div>
//       </div>
//     `;
//     productListEl.appendChild(col);
//   });
// }

function renderProducts() {
  productListEl.innerHTML = '';
  products.forEach(prod => {
    const col = document.createElement('div');
    col.className = 'col-md-4';
    col.innerHTML = `
      <div class="card product-card p-2 h-100" onclick="addToCart('${prod.id}')">
        <div class="card-body">
          <h6 class="card-title">${prod.name}</h6>
          <p class="card-text small mb-1 text-muted">${prod.category || ''}</p>
          <p class="fw-bold mb-0">KES ${parseFloat(prod.price).toFixed(2)}</p>
          <span class="badge bg-secondary">Stock: ${prod.stock_qty}</span>
        </div>
      </div>
    `;
    productListEl.appendChild(col);
  });
}


function addToCart(id) {
  const prod = products.find(p => Number(p.id) === Number(id));

  if (!prod || prod.stock_qty <= 0) {
    alert('Out of stock');
    return;
  }
  const existing = cart.find(item => item.product_id === id);
  if (existing) {
    existing.quantity += 1;
  } else {
    cart.push({ product_id: prod.id, name: prod.name, quantity: 1, price: parseFloat(prod.price) });
  }
  renderCart();
}

function removeFromCart(id) {
  cart = cart.filter(item => item.product_id !== id);
  renderCart();
}

function renderCart() {
  cartItemsEl.innerHTML = '';
  let total = 0;
  cart.forEach(item => {
    const itemTotal = item.quantity * item.price;
    total += itemTotal;
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${item.name}</td>
      <td><input type="number" min="1" value="${item.quantity}" class="form-control form-control-sm" style="width:60px"
        onchange="updateQty(${item.product_id}, this.value)"></td>
      <td>${item.price.toFixed(2)}</td>
      <td>${itemTotal.toFixed(2)}</td>
      <td><button class="btn btn-sm btn-danger" onclick="removeFromCart(${item.product_id})"><i class="bi bi-trash"></i></button></td>
    `;
    cartItemsEl.appendChild(tr);
  });
  cartTotalEl.textContent = 'KES ' + total.toFixed(2);
}

function updateQty(id, qty) {
  const item = cart.find(i => i.product_id === id);
  if (item) {
    item.quantity = parseInt(qty) || 1;
    renderCart();
  }
}

btnCompleteSale.addEventListener('click', async () => {
  if (cart.length === 0) return alert('Cart is empty!');

  if (paymentMethodEl.value === 'mpesa') {
    const phone = mpesaPhoneEl.value.trim();
    const phonePattern = /^2547\d{8}$/;
    if (!phonePattern.test(phone)) {
      alert('Enter a valid MPESA phone number starting with 2547...');
      mpesaPhoneEl.focus();
      return;
    }
  }

  const payload = {
    payment_method: paymentMethodEl.value,
    items: cart.map(item => ({
      product_id: item.product_id,
      quantity: item.quantity,
      price: item.price
    }))
  };

  if (paymentMethodEl.value === 'mpesa') {
    payload.customer_phone = mpesaPhoneEl.value.trim();
  }

  btnCompleteSale.disabled = true;
  btnCompleteSale.textContent = 'Processing...';

  try {
    const response = await fetch('api/sales.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload)
    });

    const result = await response.json();

    if (result.success) {
      // Fetch receipt HTML
      fetch(`api/receipt.php?sale_id=${result.sale_id}`)
        .then(res => res.text())
        .then(html => {
          receiptContentEl.innerHTML = html;
          receiptModal.show();
        });

      cart = [];
      renderCart();
      fetchProducts();
    } else {
      alert('Error: ' + result.error);
    }
  } catch (err) {
    alert('Network error. Please try again.');
  } finally {
    btnCompleteSale.disabled = false;
    btnCompleteSale.textContent = 'Complete Sale';
  }
});

btnPrintReceipt.addEventListener('click', () => {
  const printWindow = window.open('', 'Print Receipt', 'width=300,height=600');
  printWindow.document.write(`
    <html>
      <head><title>Receipt</title></head>
      <body>${receiptContentEl.innerHTML}</body>
    </html>
  `);
  printWindow.document.close();
  printWindow.focus();
  printWindow.print();
  printWindow.close();
});

searchBox.addEventListener('input', () => {
  fetchProducts(searchBox.value);
});

fetchProducts();
</script>
</body>
</html>
