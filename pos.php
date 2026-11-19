<?php
/**
 * StockWise — Point of Sale (POS) Interface
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'Point of Sale';
requireLogin();
// All roles can access POS

$categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll();
$csrfToken  = getCsrfToken();

require_once __DIR__ . '/includes/header.php';
?>

<div class="row g-3">
    <!-- LEFT PANEL: Product Catalog & Search (7 Cols) -->
    <div class="col-lg-7 col-xl-8">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body p-3">
                <div class="row g-2 align-items-center">
                    <div class="col-md-7">
                        <div class="input-group">
                            <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
                            <input type="text" id="posSearchInput" class="form-control border-start-0" placeholder="Search by name, SKU, barcode..." autofocus>
                            <button class="btn btn-outline-secondary" type="button" id="posClearSearch"><i class="bi bi-x-lg"></i></button>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <select id="posCategorySelect" class="form-select">
                            <option value="0">All Categories</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Category Pills Quick Filter -->
                <div class="d-flex gap-1 overflow-auto mt-2 pt-1 pb-1" style="white-space: nowrap;">
                    <button class="btn btn-sm btn-primary cat-pill active" data-id="0">All Items</button>
                    <?php foreach ($categories as $c): ?>
                        <button class="btn btn-sm btn-outline-secondary cat-pill" data-id="<?= $c['id'] ?>">
                            <?= htmlspecialchars($c['name']) ?>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Products Grid Container -->
        <div class="card border-0 shadow-sm">
            <div class="card-body p-3">
                <div id="productGridLoading" class="text-center py-5 d-none">
                    <div class="spinner-border text-primary" role="status"></div>
                    <div class="text-muted mt-2">Loading products...</div>
                </div>
                <div id="productGrid" class="row row-cols-2 row-cols-md-3 row-cols-xl-4 g-2" style="max-height: 600px; overflow-y: auto;">
                    <!-- Populated via JS -->
                </div>
                <div id="productGridEmpty" class="text-center py-5 text-muted d-none">
                    <i class="bi bi-search fs-1 text-secondary mb-2 d-block"></i>
                    No products found matching your search.
                </div>
            </div>
        </div>
    </div>

    <!-- RIGHT PANEL: Interactive Cart & Checkout (5 Cols) -->
    <div class="col-lg-5 col-xl-4">
        <div class="card border-0 shadow-sm sticky-top" style="top: 80px;">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-cart3 me-2 text-primary"></i>Current Cart</h5>
                <button type="button" class="btn btn-sm btn-outline-danger" id="btnClearCart">
                    <i class="bi bi-trash3 me-1"></i> Clear
                </button>
            </div>

            <!-- Cart Items List -->
            <div class="card-body p-2" id="cartContainer" style="max-height: 320px; min-height: 200px; overflow-y: auto;">
                <div id="cartEmptyMessage" class="text-center py-5 text-muted">
                    <i class="bi bi-cart-x fs-1 d-block mb-2 text-secondary"></i>
                    Cart is currently empty.<br><small>Click any product on the left to add items.</small>
                </div>
                <div id="cartItemsList" class="d-flex flex-column gap-2"></div>
            </div>

            <!-- Cart Calculations & Payment -->
            <div class="card-footer bg-light p-3 border-top">
                <div class="d-flex justify-content-between text-muted mb-1 small">
                    <span>Subtotal</span>
                    <span id="cartSubtotal" class="font-monospace fw-semibold">$0.00</span>
                </div>

                <!-- Discount Row -->
                <div class="row g-2 align-items-center mb-2">
                    <div class="col-5">
                        <span class="small text-muted">Discount</span>
                    </div>
                    <div class="col-7">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">$</span>
                            <input type="number" step="0.01" min="0" id="cartDiscount" class="form-control text-end font-monospace" value="0.00">
                        </div>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center py-2 border-top border-bottom mb-3">
                    <span class="fs-5 fw-bold text-dark">Total Due</span>
                    <span id="cartTotal" class="fs-4 fw-bold font-monospace text-primary">$0.00</span>
                </div>

                <!-- Payment Form -->
                <div class="mb-2">
                    <label class="form-label small fw-semibold text-muted mb-1">Cash Tendered ($)</label>
                    <div class="input-group">
                        <span class="input-group-text">$</span>
                        <input type="number" step="0.01" min="0" id="cartPayment" class="form-control form-control-lg text-end font-monospace fw-bold" placeholder="0.00">
                    </div>
                </div>

                <!-- Quick Tender Buttons -->
                <div class="d-flex gap-1 mb-3">
                    <button class="btn btn-sm btn-outline-secondary flex-fill quick-cash" data-amount="exact">Exact</button>
                    <button class="btn btn-sm btn-outline-secondary flex-fill quick-cash" data-amount="10">$10</button>
                    <button class="btn btn-sm btn-outline-secondary flex-fill quick-cash" data-amount="20">$20</button>
                    <button class="btn btn-sm btn-outline-secondary flex-fill quick-cash" data-amount="50">$50</button>
                    <button class="btn btn-sm btn-outline-secondary flex-fill quick-cash" data-amount="100">$100</button>
                </div>

                <!-- Change Due -->
                <div class="d-flex justify-content-between align-items-center bg-white p-2 rounded border mb-3">
                    <span class="fw-semibold text-muted small">Change Due:</span>
                    <span id="cartChange" class="fs-5 fw-bold font-monospace text-success">$0.00</span>
                </div>

                <!-- Checkout Action Button -->
                <button type="button" id="btnCheckout" class="btn btn-success btn-lg w-100 fw-bold py-2 shadow-sm" disabled>
                    <i class="bi bi-check2-circle me-1"></i> Complete Transaction
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: Printable Receipt -->
<div class="modal fade" id="receiptModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-sm" style="max-width: 420px;">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-body p-4" id="printableReceipt">
                <div class="text-center mb-3">
                    <h4 class="fw-bold text-dark mb-0"><?= APP_NAME ?></h4>
                    <small class="text-muted d-block">Retail & Inventory System</small>
                    <small class="text-muted d-block">123 University Avenue, IT Center</small>
                    <div class="border-top border-bottom border-dashed my-2 py-1 small">
                        <div class="d-flex justify-content-between">
                            <span>Receipt:</span>
                            <span class="fw-bold font-monospace" id="receiptNo">RCP-XXXXX</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>Date:</span>
                            <span id="receiptDate">--</span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>Cashier:</span>
                            <span id="receiptCashier">--</span>
                        </div>
                    </div>
                </div>

                <!-- Items Table -->
                <table class="table table-sm border-0 mb-2" style="font-size: 0.85rem;">
                    <thead>
                        <tr class="border-bottom">
                            <th>Item</th>
                            <th class="text-center">Qty</th>
                            <th class="text-end">Price</th>
                            <th class="text-end">Total</th>
                        </tr>
                    </thead>
                    <tbody id="receiptItems"></tbody>
                </table>

                <div class="border-top border-dashed pt-2 small">
                    <div class="d-flex justify-content-between mb-1">
                        <span>Subtotal:</span>
                        <span id="receiptSubtotal" class="font-monospace">$0.00</span>
                    </div>
                    <div class="d-flex justify-content-between mb-1" id="receiptDiscountRow">
                        <span>Discount:</span>
                        <span id="receiptDiscount" class="font-monospace">-$0.00</span>
                    </div>
                    <div class="d-flex justify-content-between fs-6 fw-bold mb-1">
                        <span>Total:</span>
                        <span id="receiptTotal" class="font-monospace">$0.00</span>
                    </div>
                    <div class="d-flex justify-content-between mb-1 text-muted">
                        <span>Cash Paid:</span>
                        <span id="receiptPayment" class="font-monospace">$0.00</span>
                    </div>
                    <div class="d-flex justify-content-between fw-semibold text-success">
                        <span>Change:</span>
                        <span id="receiptChange" class="font-monospace">$0.00</span>
                    </div>
                </div>

                <div class="text-center mt-3 pt-2 border-top border-dashed text-muted small">
                    <div>Thank you for your business!</div>
                    <small class="text-secondary">Please retain this receipt for warranty.</small>
                </div>
            </div>
            <div class="modal-footer border-0 p-3 pt-0">
                <button type="button" class="btn btn-secondary flex-fill" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary flex-fill" id="btnPrintReceipt">
                    <i class="bi bi-printer me-1"></i> Print Receipt
                </button>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    body * { visibility: hidden; }
    #printableReceipt, #printableReceipt * { visibility: visible; }
    #printableReceipt {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        margin: 0;
        padding: 10px;
    }
}
.product-card {
    cursor: pointer;
    transition: transform .15s ease, box-shadow .15s ease;
}
.product-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.08) !important;
}
.product-card.out-of-stock {
    opacity: 0.55;
    cursor: not-allowed;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    let cart = []; // Array of { id, name, sku, price, qty, maxStock }
    let allProducts = [];

    const searchInput = document.getElementById('posSearchInput');
    const categorySelect = document.getElementById('posCategorySelect');
    const clearSearchBtn = document.getElementById('posClearSearch');
    const productGrid = document.getElementById('productGrid');
    const productLoading = document.getElementById('productGridLoading');
    const productEmpty = document.getElementById('productGridEmpty');

    const cartContainer = document.getElementById('cartContainer');
    const cartItemsList = document.getElementById('cartItemsList');
    const cartEmptyMsg = document.getElementById('cartEmptyMessage');
    const cartSubtotalEl = document.getElementById('cartSubtotal');
    const cartDiscountInput = document.getElementById('cartDiscount');
    const cartTotalEl = document.getElementById('cartTotal');
    const cartPaymentInput = document.getElementById('cartPayment');
    const cartChangeEl = document.getElementById('cartChange');
    const btnCheckout = document.getElementById('btnCheckout');
    const btnClearCart = document.getElementById('btnClearCart');

    // ── Load Products via AJAX ──────────────────────────────────────
    function loadProducts() {
        const q = searchInput.value.trim();
        const catId = categorySelect.value;

        productLoading.classList.remove('d-none');
        productEmpty.classList.add('d-none');
        productGrid.innerHTML = '';

        const params = new URLSearchParams({
            q: q,
            category_id: catId,
            in_stock: 0
        });

        fetch(`ajax/search_products.php?${params}`)
            .then(res => res.json())
            .then(data => {
                productLoading.classList.add('d-none');
                if (!data.success || !data.products || data.products.length === 0) {
                    productEmpty.classList.remove('d-none');
                    return;
                }

                allProducts = data.products;
                renderProductGrid(allProducts);
            })
            .catch(err => {
                productLoading.classList.add('d-none');
                showToast('Failed to load products.', 'danger');
            });
    }

    function renderProductGrid(products) {
        productGrid.innerHTML = '';
        products.forEach(p => {
            const isOutOfStock = p.stock_quantity <= 0;
            const col = document.createElement('div');
            col.className = 'col';

            col.innerHTML = `
                <div class="card h-100 border shadow-sm product-card ${isOutOfStock ? 'out-of-stock' : ''}" data-id="${p.id}">
                    <div class="position-relative bg-light text-center p-2 rounded-top" style="height: 100px;">
                        ${p.image_path ? `
                            <img src="${p.image_path}" class="h-100 w-100 object-fit-contain" alt="">
                        ` : `
                            <div class="h-100 d-flex align-items-center justify-content-center text-muted">
                                <i class="bi bi-box fs-1"></i>
                            </div>
                        `}
                        <span class="position-absolute top-0 end-0 m-1 badge ${isOutOfStock ? 'bg-danger' : (p.stock_quantity <= p.min_stock_level ? 'bg-warning text-dark' : 'bg-success')} rounded-pill" style="font-size:0.65rem;">
                            ${isOutOfStock ? 'Out' : p.stock_quantity + ' left'}
                        </span>
                    </div>
                    <div class="card-body p-2 d-flex flex-column justify-content-between">
                        <div>
                            <div class="fw-semibold text-truncate small text-dark" title="${p.name}">${p.name}</div>
                            <small class="text-muted font-monospace" style="font-size:0.75rem;">${p.sku}</small>
                        </div>
                        <div class="mt-2 d-flex justify-content-between align-items-center">
                            <span class="fw-bold text-primary font-monospace">${p.formatted_price}</span>
                            <button class="btn btn-sm btn-outline-primary py-0 px-2 rounded-pill" ${isOutOfStock ? 'disabled' : ''}>
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `;

            if (!isOutOfStock) {
                col.querySelector('.product-card').addEventListener('click', () => addToCart(p));
            }

            productGrid.appendChild(col);
        });
    }

    // ── Cart Operations ─────────────────────────────────────────────
    function addToCart(product) {
        const existing = cart.find(item => item.id === product.id);
        if (existing) {
            if (existing.qty + 1 > product.stock_quantity) {
                showToast(`Cannot add more. Only ${product.stock_quantity} available in stock.`, 'warning');
                return;
            }
            existing.qty++;
        } else {
            cart.push({
                id: product.id,
                name: product.name,
                sku: product.sku,
                price: parseFloat(product.selling_price),
                qty: 1,
                maxStock: product.stock_quantity
            });
        }
        updateCartUI();
    }

    function updateCartQty(id, delta) {
        const item = cart.find(i => i.id === id);
        if (!item) return;

        const newQty = item.qty + delta;
        if (newQty <= 0) {
            cart = cart.filter(i => i.id !== id);
        } else if (newQty > item.maxStock) {
            showToast(`Cannot exceed stock limit (${item.maxStock}).`, 'warning');
            return;
        } else {
            item.qty = newQty;
        }
        updateCartUI();
    }

    function removeCartItem(id) {
        cart = cart.filter(i => i.id !== id);
        updateCartUI();
    }

    function updateCartUI() {
        if (cart.length === 0) {
            cartEmptyMsg.classList.remove('d-none');
            cartItemsList.innerHTML = '';
            cartSubtotalEl.textContent = '$0.00';
            cartTotalEl.textContent = '$0.00';
            cartChangeEl.textContent = '$0.00';
            btnCheckout.disabled = true;
            return;
        }

        cartEmptyMsg.classList.add('d-none');
        cartItemsList.innerHTML = '';

        let subtotal = 0;

        cart.forEach(item => {
            const lineTotal = item.price * item.qty;
            subtotal += lineTotal;

            const row = document.createElement('div');
            row.className = 'd-flex justify-content-between align-items-center bg-white p-2 rounded border';
            row.innerHTML = `
                <div class="flex-grow-1 me-2" style="max-width: 150px;">
                    <div class="fw-semibold text-truncate small">${item.name}</div>
                    <small class="text-muted font-monospace">$${item.price.toFixed(2)} each</small>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <button class="btn btn-sm btn-light border py-0 px-2 btn-minus" data-id="${item.id}">-</button>
                    <span class="font-monospace fw-bold px-1 small">${item.qty}</span>
                    <button class="btn btn-sm btn-light border py-0 px-2 btn-plus" data-id="${item.id}">+</button>
                </div>
                <div class="text-end ms-2" style="min-width: 60px;">
                    <div class="fw-bold font-monospace small">$${lineTotal.toFixed(2)}</div>
                    <button class="btn btn-sm text-danger p-0 border-0 btn-remove" data-id="${item.id}"><i class="bi bi-x-circle"></i></button>
                </div>
            `;

            row.querySelector('.btn-minus').addEventListener('click', () => updateCartQty(item.id, -1));
            row.querySelector('.btn-plus').addEventListener('click', () => updateCartQty(item.id, 1));
            row.querySelector('.btn-remove').addEventListener('click', () => removeCartItem(item.id));

            cartItemsList.appendChild(row);
        });

        const discount = Math.max(0, parseFloat(cartDiscountInput.value) || 0);
        const total = Math.max(0, subtotal - discount);

        cartSubtotalEl.textContent = '$' + subtotal.toFixed(2);
        cartTotalEl.textContent = '$' + total.toFixed(2);

        calculateChange(total);
    }

    function calculateChange(total) {
        const payment = parseFloat(cartPaymentInput.value) || 0;
        const change = Math.max(0, payment - total);

        cartChangeEl.textContent = '$' + change.toFixed(2);

        if (cart.length > 0 && payment >= total && total > 0) {
            btnCheckout.disabled = false;
        } else {
            btnCheckout.disabled = true;
        }
    }

    // ── Input Listeners ─────────────────────────────────────────────
    cartDiscountInput.addEventListener('input', () => updateCartUI());
    cartPaymentInput.addEventListener('input', () => {
        const subtotal = cart.reduce((acc, i) => acc + (i.price * i.qty), 0);
        const discount = Math.max(0, parseFloat(cartDiscountInput.value) || 0);
        const total = Math.max(0, subtotal - discount);
        calculateChange(total);
    });

    document.querySelectorAll('.quick-cash').forEach(btn => {
        btn.addEventListener('click', function () {
            const val = this.getAttribute('data-amount');
            const subtotal = cart.reduce((acc, i) => acc + (i.price * i.qty), 0);
            const discount = Math.max(0, parseFloat(cartDiscountInput.value) || 0);
            const total = Math.max(0, subtotal - discount);

            if (val === 'exact') {
                cartPaymentInput.value = total.toFixed(2);
            } else {
                cartPaymentInput.value = parseFloat(val).toFixed(2);
            }
            calculateChange(total);
        });
    });

    btnClearCart.addEventListener('click', function () {
        if (cart.length === 0) return;
        confirmAction('Are you sure you want to clear all items in the cart?', function () {
            cart = [];
            cartPaymentInput.value = '';
            cartDiscountInput.value = '0.00';
            updateCartUI();
        }, 'Clear Cart', false);
    });

    // ── Search & Filter Listeners ───────────────────────────────────
    let debounceTimer;
    searchInput.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(loadProducts, 250);
    });

    categorySelect.addEventListener('change', () => {
        const id = categorySelect.value;
        document.querySelectorAll('.cat-pill').forEach(btn => {
            btn.classList.toggle('active', btn.getAttribute('data-id') === id);
            btn.classList.toggle('btn-primary', btn.getAttribute('data-id') === id);
            btn.classList.toggle('btn-outline-secondary', btn.getAttribute('data-id') !== id);
        });
        loadProducts();
    });

    clearSearchBtn.addEventListener('click', () => {
        searchInput.value = '';
        loadProducts();
        searchInput.focus();
    });

    document.querySelectorAll('.cat-pill').forEach(btn => {
        btn.addEventListener('click', function () {
            document.querySelectorAll('.cat-pill').forEach(b => {
                b.classList.remove('active', 'btn-primary');
                b.classList.add('btn-outline-secondary');
            });
            this.classList.add('active', 'btn-primary');
            this.classList.remove('btn-outline-secondary');

            categorySelect.value = this.getAttribute('data-id');
            loadProducts();
        });
    });

    // ── Checkout Button Action ──────────────────────────────────────
    btnCheckout.addEventListener('click', function () {
        const subtotal = cart.reduce((acc, i) => acc + (i.price * i.qty), 0);
        const discount = Math.max(0, parseFloat(cartDiscountInput.value) || 0);
        const total = Math.max(0, subtotal - discount);
        const payment = parseFloat(cartPaymentInput.value) || 0;

        if (payment < total) {
            showToast('Payment amount is less than total balance.', 'danger');
            return;
        }

        btnCheckout.disabled = true;
        btnCheckout.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Processing...';

        const payload = {
            items: cart.map(i => ({ id: i.id, qty: i.qty })),
            subtotal: subtotal,
            discount_amount: discount,
            total_amount: total,
            payment_amount: payment,
            payment_method: 'Cash',
            notes: 'POS Register'
        };

        fetch('ajax/complete_sale.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
        .then(res => res.json())
        .then(result => {
            btnCheckout.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Complete Transaction';
            btnCheckout.disabled = false;

            if (!result.success) {
                showToast(result.error || 'Failed to complete sale.', 'danger');
                return;
            }

            // Populate & Show Printable Receipt Modal
            const r = result.receipt;
            document.getElementById('receiptNo').textContent = r.receipt_no;
            document.getElementById('receiptDate').textContent = r.date;
            document.getElementById('receiptCashier').textContent = r.cashier_name;
            document.getElementById('receiptSubtotal').textContent = '$' + r.subtotal.toFixed(2);
            document.getElementById('receiptDiscount').textContent = '-$' + r.discount_amount.toFixed(2);
            document.getElementById('receiptTotal').textContent = '$' + r.total_amount.toFixed(2);
            document.getElementById('receiptPayment').textContent = '$' + r.payment_amount.toFixed(2);
            document.getElementById('receiptChange').textContent = '$' + r.change_amount.toFixed(2);

            const rItemsTbody = document.getElementById('receiptItems');
            rItemsTbody.innerHTML = '';
            r.items.forEach(it => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${it.name}</td>
                    <td class="text-center">${it.qty}</td>
                    <td class="text-end">$${it.price.toFixed(2)}</td>
                    <td class="text-end fw-semibold">$${it.line_subtotal.toFixed(2)}</td>
                `;
                rItemsTbody.appendChild(tr);
            });

            const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));
            receiptModal.show();

            // Clear Cart & Refresh Product Grid to reflect new stock
            cart = [];
            cartPaymentInput.value = '';
            cartDiscountInput.value = '0.00';
            updateCartUI();
            loadProducts();
            showToast('Transaction completed successfully!', 'success');
        })
        .catch(err => {
            btnCheckout.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Complete Transaction';
            btnCheckout.disabled = false;
            showToast('A network error occurred. Please try again.', 'danger');
        });
    });

    document.getElementById('btnPrintReceipt').addEventListener('click', function () {
        window.print();
    });

    // Initial load
    loadProducts();
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
