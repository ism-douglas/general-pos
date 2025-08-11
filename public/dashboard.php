<?php
require_once '../inc/auth.php';
require_login();
$username = current_user();
$role = current_user_role();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Kenya POS - Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
  <style>
    @media print {
      body * { visibility: hidden; }
      #receipt, #receipt * { visibility: visible; }
      #receipt { position: absolute; top: 0; left: 0; width: 300px; }
    }
  </style>
</head>
<body>
  <nav class="navbar navbar-expand-lg navbar-dark bg-primary px-3">
    <a class="navbar-brand" href="#">Kenya POS</a>
    <div class="ms-auto d-flex align-items-center text-white">
      <span class="me-3">Hello, <?=htmlspecialchars($username)?></span>
      <a href="logout.php" class="btn btn-outline-light btn-sm">Logout</a>
    </div>
  </nav>

  <div class="container mt-3">
    <div class="row">
      <!-- Sales section -->
      <section class="col-lg-7 mb-4">
        <h4>Sales</h4>
        <input type="text" id="productSearch" placeholder="Scan or search product" class="form-control mb-2" autofocus />
        <table class="table table-sm">
          <thead>
            <tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th><th></th></tr>
          </thead>
          <tbody id="cartBody"></tbody>
        </table>
        <div class="d-flex justify-content-between align-items-center mt-3">
          <strong>Total: KES <span id="totalAmount">0.00</span></strong>
          <select id="paymentMethod" class="form-select w-auto">
            <option value="cash">Cash</option>
            <option value="mpesa">MPESA</option>
          </select>
          <button id="completeSaleBtn" class="btn btn-success">Complete Sale</button>
        </div>
      </section>

      <!-- Stock Management -->
      <section class="col-lg-5">
        <h4>Stock Management</h4>
        <button id="addProductBtn" class="btn btn-primary mb-2">Add Product</button>
        <table class="table table-striped table-sm" id="stockTable">
          <thead>
            <tr><th>Name</th><th>Stock</th><th>Price</th><th>Actions</th></tr>
          </thead>
          <tbody></tbody>
        </table>
      </section>
    </div>
  </div>

  <!-- Add/Edit product modal -->
  <div class="modal" tabindex="-1" id="productModal">
    <div class="modal-dialog">
      <form id="productForm" class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="modalTitle">Add Product</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" id="productId" />
          <div class="mb-3">
            <label for="productName" class="form-label">Name</label>
            <input type="text" id="productName" class="form-control" required />
          </div>
          <div class="mb-3">
            <label for="productCategory" class="form-label">Category</label>
            <input type="text" id="productCategory" class="form-control" />
          </div>
          <div class="mb-3">
            <label for="productPrice" class="form-label">Price (KES)</label>
            <input type="number" id="productPrice" class="form-control" min="0" step="0.01" required />
          </div>
          <div class="mb-3">
            <label for="productStock" class="form-label">Stock Quantity</label>
            <input type="number" id="productStock" class="form-control" min="0" required />
          </div>
          <div class="mb-3">
            <label for="productBarcode" class="form-label">Barcode (optional)</label>
            <input type="text" id="productBarcode" class="form-control" />
          </div>
        </div>
        <div class="modal-footer">
          <button type="submit" class="btn btn-primary">Save Product</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <!-- Receipt modal -->
  <div class="modal" tabindex="-1" id="receiptModal">
    <div class="modal-dialog">
      <div class="modal-content p-3" id="receipt" style="width:300px;">
        <!-- Receipt content inserted here dynamically -->
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    let products = [];
    let cart = [];

    const stockTableBody = document.querySelector('#stockTable tbody');
    const cartBody = document.getElementById('cartBody');
    const totalAmountSpan = document.getElementById('totalAmount');
    const paymentMethodSelect = document.getElementById('paymentMethod');
    const productSearchInput = document.getElementById('productSearch');

    const productModal = new bootstrap.Modal(document.getElementById('productModal'));
    const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));

    // Load products from API
    async function loadProducts() {
      const res = await fetch('api/products.php?action=list');
      if (!res.ok) {
        alert('Failed to load products');
        return;
      }
      const data = await res.json();
      products = data.products || [];
      renderStockTable();
    }

    // Render stock table
    function renderStockTable() {
      stockTableBody.innerHTML = '';
      for (const p of products) {
        stockTableBody.insertAdjacentHTML('beforeend', `
          <tr>
            <td>${p.name}</td>
            <td>${p.stock_qty}</td>
            <td>${p.price.toFixed(2)}</td>
            <td>
              <button class="btn btn-sm btn-warning" onclick="editProduct(${p.id})">Edit</button>
              <button class="btn btn-sm btn-danger" onclick="deleteProduct(${p.id})">Delete</button>
            </td>
          </tr>
        `);
      }
    }

    // Render cart table
    function renderCart() {
      cartBody.innerHTML = '';
      let total = 0;
      for (const item of cart) {
        const product = products.find(p => p.id === item.productId);
        if (!product) continue;
        const lineTotal = item.qty * product.price;
        total += lineTotal;
        cartBody.insertAdjacentHTML('beforeend', `
          <tr>
            <td>${product.name}</td>
            <td><input type="number" min="1" value="${item.qty}" style="width:60px" onchange="updateQty(${item.productId}, this.value)" /></td>
            <td>${product.price.toFixed(2)}</td>
            <td>${lineTotal.toFixed(2)}</td>
            <td><button class="btn btn-sm btn-danger" onclick="removeFromCart(${item.productId})">&times;</button></td>
          </tr>
        `);
      }
      totalAmountSpan.textContent = total.toFixed(2);
    }

    function updateQty(productId, qty) {
      qty = parseInt(qty);
      if (qty < 1) qty = 1;
      const item = cart.find(c => c.productId === productId);
      if (item) {
        item.qty = qty;
        renderCart();
      }
    }

    function removeFromCart(productId) {
      cart = cart.filter(c => c.productId !== productId);
      renderCart();
    }

    // Add to cart on product search enter
    productSearchInput.addEventListener('keypress', e => {
      if (e.key === 'Enter') {
        const query = productSearchInput.value.trim().toLowerCase();
        if (!query) return;
        const product = products.find(p => p.name.toLowerCase().includes(query) || (p.barcode && p.barcode === query));
        if (product) {
          addToCart(product.id);
          productSearchInput.value = '';
        } else {
          alert('Product not found');
        }
      }
    });

    function addToCart(productId) {
      const item = cart.find(c => c.productId === productId);
      if (item) {
        item.qty++;
      } else {
        cart.push({productId, qty: 1});
      }
      renderCart();
    }

    // Complete sale
    document.getElementById('completeSaleBtn').addEventListener('click', async () => {
      if (cart.length === 0) {
        alert('Cart is empty');
        return;
      }
      const paymentMethod = paymentMethodSelect.value;
      const saleData = {
        payment_method: paymentMethod,
        items: cart.map(item => ({ product_id: item.productId, quantity: item.qty }))
      };
      try {
        const res = await fetch('api/sales.php', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify(saleData)
        });
        const data = await res.json();
        if (res.ok) {
          cart = [];
          renderCart();
          alert('Sale completed!');
          loadReceipt(data.sale_id);
          loadProducts(); // reload stock after sale
        } else {
          alert(data.error || 'Sale failed');
        }
      } catch {
        alert('Network error');
      }
    });

    // Load receipt content
    async function loadReceipt(saleId) {
      const res = await fetch(`api/receipt.php?sale_id=${saleId}`);
      const data = await res.text();
      const receiptEl = document.getElementById('receipt');
      receiptEl.innerHTML = data;
      receiptModal.show();
    }

    // Stock management buttons
    document.getElementById('addProductBtn').addEventListener('click', () => {
      document.getElementById('modalTitle').textContent = 'Add Product';
      document.getElementById('productForm').reset();
      document.getElementById('productId').value = '';
      productModal.show();
    });

    // Edit product
    window.editProduct = function(id) {
      const p = products.find(prod => prod.id === id);
      if (!p) return alert('Product not found');
      document.getElementById('modalTitle').textContent = 'Edit Product';
      document.getElementById('productId').value = p.id;
      document.getElementById('productName').value = p.name;
      document.getElementById('productCategory').value = p.category;
      document.getElementById('productPrice').value = p.price;
      document.getElementById('productStock').value = p.stock_qty;
      document.getElementById('productBarcode').value = p.barcode || '';
      productModal.show();
    };

    // Delete product
    window.deleteProduct = async function(id) {
      if (!confirm('Are you sure you want to delete this product?')) return;
      try {
        const res = await fetch('api/products.php?action=delete', {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify({id})
        });
        const data = await res.json();
        if (res.ok) {
          alert('Product deleted');
          loadProducts();
        } else {
          alert(data.error || 'Failed to delete product');
        }
      } catch {
        alert('Network error');
      }
    };

    // Product form submit (add/edit)
    document.getElementById('productForm').addEventListener('submit', async (e) => {
      e.preventDefault();
      const id = document.getElementById('productId').value;
      const name = document.getElementById('productName').value.trim();
      const category = document.getElementById('productCategory').value.trim();
      const price = parseFloat(document.getElementById('productPrice').value);
      const stock_qty = parseInt(document.getElementById('productStock').value);
      const barcode = document.getElementById('productBarcode').value.trim();

      if (!name || isNaN(price) || isNaN(stock_qty)) {
        return alert('Please fill in all required fields correctly.');
      }

      const payload = { id, name, category, price, stock_qty, barcode };
      const action = id ? 'edit' : 'add';

      try {
        const res = await fetch(`api/products.php?action=${action}`, {
          method: 'POST',
          headers: {'Content-Type': 'application/json'},
          body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (res.ok) {
          productModal.hide();
          loadProducts();
        } else {
          alert(data.error || 'Failed to save product');
        }
      } catch {
        alert('Network error');
      }
    });

    // Initial load
    loadProducts();
    renderCart();
  </script>
</body>
</html>
