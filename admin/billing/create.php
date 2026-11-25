<?php
/**
 * Admin/Employee: Create Bill
 */
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireLogin();

$isAdmin    = isAdmin();
$userCan    = currentUser();
$pageTitle  = 'Create Bill';
$activePage = 'create_bill';
$breadcrumb = [['label' => 'Billing'], ['label' => 'Create Bill']];

// Optional prefill when coming from Customer Modal (+ New Bill for Customer)
$prefillCustId = (int)($_GET['customer_id'] ?? 0);
$prefillCust = null;
if ($prefillCustId > 0) {
    $prefillCust = db()->fetchOne("SELECT * FROM customers WHERE id = ?", [$prefillCustId]);
}

$extraJs = '';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<script>
window.APP_URL = '<?= APP_URL ?>';
var canApplyDiscount = <?= canApplyDiscount() ? 'true' : 'false' ?>;
</script>

<!-- Confirm Modal -->
<div class="modal-overlay" id="confirmModal">
  <div class="modal modal-sm">
    <div class="modal-header"><span class="modal-title">Confirm</span></div>
    <div class="modal-body"><p id="confirmMessage"></p></div>
    <div class="modal-footer">
      <button class="btn btn-light" onclick="closeModal('confirmModal')">Cancel</button>
      <button class="btn btn-danger" id="confirmOkBtn">Confirm</button>
    </div>
  </div>
</div>

<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title"><i class="fas fa-file-invoice text-primary" style="margin-right:10px"></i>Create New Bill</h1>
    <p class="page-subtitle">Invoice Date: <?= date('d/m/Y') ?></p>
  </div>
  <div class="page-header-right">
    <a href="<?= APP_URL ?>/<?= $isAdmin ? 'admin' : 'employee' ?>/billing/<?= $isAdmin ? 'index' : 'my_bills' ?>.php" class="btn btn-light">
      <i class="fas fa-list"></i> All Bills
    </a>
  </div>
</div>

<form method="POST" action="<?= APP_URL ?>/api/save_bill.php" id="billForm" onsubmit="return validateBillForm()">
  <?= csrfField() ?>
  <input type="hidden" id="grandTotalHidden" name="grand_total" value="0">
  <input type="hidden" id="balanceDueHidden" name="balance_due" value="0">
  <input type="hidden" id="billRowCount" name="row_count" value="0">

  <div class="grid-2" style="align-items:start">
    <!-- Left: Product Search + Bill Table -->
    <div style="grid-column:1/-1">
      <!-- Product Search Bar -->
      <div class="card" style="overflow:visible !important;">
        <div class="card-body" style="padding:16px 20px; overflow:visible !important; position:relative; z-index:100;">
          <div class="d-flex gap-3 align-center">
            <div style="flex:1;position:relative; z-index:101;">
              <i class="fas fa-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;pointer-events:none; z-index:2;"></i>
              <input type="text" id="productSearchInput" class="form-control" placeholder="Search fabric or product name, SKU, material type... (type to search)"
                     style="padding-left:38px;font-size:0.95rem; position:relative; z-index:1;" autocomplete="off"
                     oninput="searchBillingProduct(this.value)">
              <div id="productBillingDropdown" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #cbd5e1;border-radius:8px;box-shadow:0 12px 28px rgba(0,0,0,0.18);z-index:9999;max-height:320px;overflow-y:auto;margin-top:4px;"></div>
            </div>
            <div class="text-muted text-sm" id="summaryItemCount" style="white-space:nowrap;font-weight:600">0 item(s)</div>
          </div>
        </div>
      </div>

      <!-- Bill Items Table -->
      <div class="card">
        <div class="card-header">
          <span class="card-title"><i class="fas fa-shopping-cart"></i> Bill Items</span>
        </div>
        <div class="billing-table-wrapper">
          <table class="billing-table">
            <thead>
              <tr>
                <th style="min-width:220px">Product</th>
                <th class="text-right" style="width:110px">Unit Price</th>
                <th class="text-center" style="width:110px">Qty</th>
                <th class="text-right" style="width:120px">Discount/Unit</th>
                <th class="text-right" style="width:90px">GST</th>
                <th class="text-right" style="width:120px">Total</th>
                <th class="text-center" style="width:50px"></th>
              </tr>
            </thead>
            <tbody id="billTableBody">
              <tr>
                <td colspan="7" class="text-center text-muted" style="padding:40px">
                  <i class="fas fa-shopping-cart" style="font-size:2.5rem;opacity:0.15;display:block;margin-bottom:12px"></i>
                  Search and add products above
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

<style>
.billing-bottom-grid {
  display: grid;
  grid-template-columns: 1.15fr 1fr 1fr;
  gap: 20px;
  align-items: stretch;
  margin-top: 20px;
}
.billing-bottom-grid .card {
  display: flex;
  flex-direction: column;
  margin-bottom: 0;
  border-radius: 10px;
  border: 1px solid #e2e8f0;
  box-shadow: 0 2px 8px rgba(0,0,0,0.04);
  background: #fff;
}
.billing-bottom-grid .card-header {
  padding: 13px 18px;
  border-bottom: 1px solid #f1f5f9;
  background: #fff;
}
.billing-bottom-grid .card-body {
  padding: 18px;
  flex: 1;
}
.billing-bottom-grid .card-footer {
  padding: 14px 18px;
  border-top: 1px solid #f1f5f9;
  background: #fafafa;
}
@media (max-width: 1200px) {
  .billing-bottom-grid {
    grid-template-columns: 1fr 1fr;
  }
  .billing-bottom-grid .customer-card {
    grid-column: 1 / -1;
  }
}
@media (max-width: 768px) {
  .billing-bottom-grid {
    grid-template-columns: 1fr;
  }
  .billing-bottom-grid .customer-card {
    grid-column: auto;
  }
}
</style>

  <!-- Bill Details Row: Professional 3-Column Layout -->
  <div class="billing-bottom-grid">
    <!-- 1. Customer Details -->
    <div class="card customer-card">
      <div class="card-header">
        <span class="card-title" style="font-weight:700;color:#1e293b;font-size:0.95rem;display:flex;align-items:center;">
          <i class="fas fa-user-circle text-primary" style="margin-right:8px;"></i> Customer Details
        </span>
      </div>
      <div class="card-body">
        <!-- Customer Name & Purchase Date -->
        <div class="form-row cols-2" style="margin-bottom:12px;">
          <div class="form-group mb-0">
            <label class="form-label">Customer Name <span class="required" style="color:#ef4444">*</span></label>
            <input type="text" id="customerName" name="customer_name" class="form-control"
                   placeholder="Enter customer name..." autocomplete="off" value="<?= e($prefillCust['name'] ?? '') ?>" required
                   oninput="this.style.borderColor='';">
            <input type="hidden" id="customerId" name="customer_id" value="<?= e($prefillCust['id'] ?? '') ?>">
          </div>
          <div class="form-group mb-0">
            <label class="form-label">Purchase Date <span class="required">*</span></label>
            <input type="date" name="sale_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
          </div>
        </div>

        <!-- Mobile & Email -->
        <div class="form-row cols-2" style="margin-bottom:12px;">
          <div class="form-group mb-0">
            <label class="form-label">Mobile Number</label>
            <input type="tel" name="customer_mobile" id="customerMobile" class="form-control" placeholder="10-digit mobile number" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" value="<?= e($prefillCust['mobile'] ?? '') ?>" oninput="this.value=this.value.replace(/\D/g,'').slice(0,10)">
          </div>
          <div class="form-group mb-0">
            <label class="form-label">Email <small class="text-muted">(Optional)</small></label>
            <input type="email" name="customer_email" id="customerEmail" class="form-control" placeholder="customer@example.com" value="<?= e($prefillCust['email'] ?? '') ?>">
          </div>
        </div>

        <!-- Address -->
        <div class="form-group" style="margin-bottom:12px;">
          <label class="form-label">Address <small class="text-muted">(Optional)</small></label>
          <input type="text" name="customer_address" id="customerAddress" class="form-control" placeholder="Delivery / residence address" value="<?= e($prefillCust['address'] ?? '') ?>">
        </div>

        <!-- Customer Order Notes / Remarks -->
        <div class="form-group mb-0">
          <label class="form-label">Customer Order Notes / Remarks <small class="text-muted">(Optional)</small></label>
          <input type="text" name="customer_notes" id="customerNotes" class="form-control" placeholder="e.g. Alteration remarks, measurements, batch reference" value="<?= e($prefillCust['notes'] ?? '') ?>">
        </div>
      </div>
    </div>

    <!-- 2. Payment Details -->
    <div class="card payment-card">
      <div class="card-header">
        <span class="card-title" style="font-weight:700;color:#1e293b;font-size:0.95rem;display:flex;align-items:center;">
          <i class="fas fa-credit-card text-success" style="margin-right:8px;"></i> Payment Details
        </span>
      </div>
      <div class="card-body">
        <div class="form-group" style="margin-bottom:14px;">
          <label class="form-label">Payment Method</label>
          <select name="payment_method" class="form-select">
            <option value="cash">💵 Cash</option>
            <option value="upi">📱 UPI</option>
            <option value="card">💳 Card</option>
            <option value="bank_transfer">🏦 Bank Transfer</option>
            <option value="credit">📋 Credit / Due</option>
          </select>
        </div>

        <div class="form-row cols-2" style="margin-bottom:14px;">
          <div class="form-group mb-0">
            <label class="form-label">Additional Charges (₹)</label>
            <input type="number" id="additionalCharges" name="additional_charges" class="form-control" step="0.01" min="0" value="0">
          </div>
          <div class="form-group mb-0">
            <label class="form-label">Amount Received (₹)</label>
            <input type="number" id="amountReceived" name="amount_received" class="form-control" step="0.01" min="0" value="0">
          </div>
        </div>

        <!-- Balance Due / Settlement Indicator -->
        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:#f8fafc;border-radius:8px;border:1px solid #e2e8f0;margin-top:16px;">
          <div>
            <span id="balanceLabel" style="font-weight:700;font-size:0.85rem;color:#475569;display:block">Balance Due</span>
            <small class="text-muted" style="font-size:0.75rem">Customer balance status</small>
          </div>
          <span id="balanceDisplay" style="font-weight:800;font-size:1.15rem;color:#dc2626">₹0.00</span>
        </div>
      </div>
    </div>

    <!-- 3. Bill Summary & Checkout -->
    <div class="card summary-card">
      <div class="card-header">
        <span class="card-title" style="font-weight:700;color:#1e293b;font-size:0.95rem;display:flex;align-items:center;">
          <i class="fas fa-calculator text-primary" style="margin-right:8px;"></i> Bill Summary
        </span>
      </div>
      <div class="card-body" style="padding:16px 18px;">
        <div class="bill-summary-row" style="padding:5px 0;">
          <span class="bill-summary-label">Subtotal</span>
          <span class="bill-summary-value" id="summarySubtotal">₹0.00</span>
        </div>
        <div class="bill-summary-row" style="padding:5px 0;">
          <span class="bill-summary-label">Discount</span>
          <span class="bill-summary-value text-danger" id="summaryDiscount">₹0.00</span>
        </div>
        <div class="bill-summary-row" style="padding:5px 0;">
          <span class="bill-summary-label">GST</span>
          <span class="bill-summary-value text-muted" id="summaryTax">₹0.00</span>
        </div>
        <div class="bill-summary-row" style="padding:5px 0;">
          <span class="bill-summary-label">Additional Charges</span>
          <span class="bill-summary-value" id="summaryCharges">₹0.00</span>
        </div>
        <div class="bill-summary-row total" style="margin-top:10px;padding-top:12px;border-top:2px solid #e2e8f0;">
          <span style="font-size:1rem;font-weight:700;color:#0f172a">Grand Total</span>
          <span class="amount" id="summaryTotal" style="font-size:1.3rem;font-weight:800;color:#2563eb">₹0.00</span>
        </div>
      </div>
      <div class="card-footer" style="padding:14px 18px;background:#f8fafc;border-top:1px solid #f1f5f9;">
        <button type="button" class="btn btn-success btn-lg w-100" onclick="submitBill('billForm')" style="font-weight:700;padding:12px 16px;font-size:0.95rem;box-shadow:0 4px 12px rgba(16,185,129,0.25);">
          <i class="fas fa-check-circle" style="margin-right:6px;"></i> Save Bill &amp; Generate Invoice
        </button>
      </div>
    </div>
  </div>
</form>

<script>
window.APP_URL = <?= json_encode(APP_URL) ?>;
window.canApplyDiscount = <?= canApplyDiscount() ? 'true' : 'false' ?>;

// Universal Safe HTML Escaper
function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  const d = document.createElement('div');
  d.textContent = str;
  return d.innerHTML;
}
window.escapeHtml = escapeHtml;

// ── PRODUCT SEARCH & SELECTION ──────────────────────────
let currentProductResults = [];
let billingSearchTimer = null;

function searchBillingProduct(q) {
  const dropdown = document.getElementById('productBillingDropdown');
  if (!dropdown) return;

  clearTimeout(billingSearchTimer);
  q = (q || '').trim();

  // If query is empty, close and clear dropdown immediately
  if (q.length < 1) {
    dropdown.style.display = 'none';
    dropdown.innerHTML = '';
    currentProductResults = [];
    return;
  }

  billingSearchTimer = setTimeout(function() {
    const url = (window.APP_URL || '') + '/api/search_product.php?q=' + encodeURIComponent(q) + '&item_type=product';
    fetch(url, { headers: { 'Accept': 'application/json' } })
      .then(function(res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
      })
      .then(function(res) {
        if (res.success && res.data && res.data.length > 0) {
          currentProductResults = res.data;
          dropdown.innerHTML = res.data.map(function(product, idx) {
            const catStr = (product.category_name ? product.category_name + (product.subcategory_name ? ' › ' + product.subcategory_name : '') : '');
            const isOutOfStock = parseInt(product.current_stock) <= 0;
            const stockColor = isOutOfStock ? '#dc2626' : (product.current_stock <= 5 ? '#d97706' : '#059669');
            const unitLabel = product.size || 'Standard';
            const typeBadge = '<span class="badge badge-primary" style="font-size:0.7rem;"><i class="fas fa-tshirt"></i> Garment</span>';

            return '<div onclick="selectProductByIndex(' + idx + ')" ' +
                   'style="padding:10px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:0.85rem;background:#fff;" ' +
                   'onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'#fff\'">' +
                     '<div style="display:flex;justify-content:space-between;align-items:center">' +
                       '<div>' +
                         '<strong style="color:#1e293b;font-size:0.9rem">' + escapeHtml(product.name) + '</strong>' +
                         (product.brand ? ' <span style="color:#7c3aed;font-size:0.8rem;font-weight:500;">(' + escapeHtml(product.brand) + ')</span>' : '') +
                       '</div>' +
                       '<div style="font-weight:700;color:#2563eb;font-size:0.92rem">' +
                         '₹' + Math.round(parseFloat(product.selling_price) || 0) +
                       '</div>' +
                     '</div>' +
                     '<div style="margin-top:4px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:0.75rem;">' +
                       typeBadge +
                       '<span class="sku-badge">' + escapeHtml(product.sku) + '</span>' +
                       (catStr ? '<span class="badge badge-secondary">' + escapeHtml(catStr) + '</span>' : '') +
                       '<span class="badge badge-info">' + escapeHtml(unitLabel) + '</span>' +
                       '<span style="margin-left:auto;font-weight:600;color:' + stockColor + '">' +
                         (isOutOfStock ? '<i class="fas fa-times-circle"></i> Out of Stock' : '<i class="fas fa-check-circle"></i> In Stock: ' + product.current_stock + ' ' + unitLabel) +
                       '</span>' +
                     '</div>' +
                   '</div>';
          }).join('');
          dropdown.style.display = 'block';
        } else {
          currentProductResults = [];
          dropdown.innerHTML = '<div style="padding:16px;text-align:center;color:#64748b;font-size:0.85rem">' +
                               '<i class="fas fa-search" style="opacity:0.35;font-size:1.5rem;display:block;margin-bottom:6px"></i>' +
                               (q ? 'No matching products or fabrics for "<strong>' + escapeHtml(q) + '</strong>"<br><small style="color:#94a3b8">Try typing cotton, shirt, suiting, or SKU</small>'
                                  : 'No active products found in stock') +
                               '</div>';
          dropdown.style.display = 'block';
        }
      })
      .catch(function(err) {
        console.error('Search error:', err);
        dropdown.innerHTML = '<div style="padding:12px;text-align:center;color:#dc2626;font-size:0.82rem">Search failed. Please try again.</div>';
        dropdown.style.display = 'block';
      });
  }, 100);
}

function selectProductByIndex(idx) {
  const product = currentProductResults[idx];
  if (product) selectProductForBill(product);
}

function selectProductForBill(product) {
  const searchInput = document.getElementById('productSearchInput');
  const dropdown = document.getElementById('productBillingDropdown');
  
  // Immediately close and wipe dropdown
  if (dropdown) {
    dropdown.style.display = 'none';
    dropdown.innerHTML = '';
    currentProductResults = [];
  }

  if (!product) return;

  if (parseInt(product.current_stock) <= 0) {
    if (typeof showToast === 'function') showToast(product.name + ' is out of stock!', 'error');
    else alert(product.name + ' is out of stock!');
    if (searchInput) {
      searchInput.value = '';
    }
    return;
  }

  // Check if already in bill
  const existing = window.billRows.find(function(r) { return r.variant_id === product.id; });
  if (existing) {
    if (existing.qty < existing.max_stock) {
      updateRowQty(existing.idx, existing.qty + 1);
      if (typeof showToast === 'function') showToast('Quantity increased for ' + product.name, 'info');
    } else {
      if (typeof showToast === 'function') showToast('Maximum available stock (' + existing.max_stock + ') reached for ' + product.name, 'warning');
    }
  } else {
    addBillRow(product);
    if (typeof showToast === 'function') showToast(product.name + ' added to bill', 'success');
  }

  // Clear search input and dismiss focus so dropdown doesn't resurrect
  if (searchInput) {
    searchInput.value = '';
    searchInput.blur();
  }
}

// Dismiss product search dropdown on outside click
document.addEventListener('click', function(e) {
  const dd = document.getElementById('productBillingDropdown');
  const input = document.getElementById('productSearchInput');
  if (dd && !dd.contains(e.target) && e.target !== input) {
    dd.style.display = 'none';
  }
});

// ── BILL ITEMS STATE & RENDERING ───────────────────────
window.billRows = [];
window.rowCounter = 0;

function addBillRow(product) {
  window.rowCounter++;
  const idx = window.rowCounter;
  const row = {
    idx: idx,
    variant_id: product.id,
    sku: product.sku,
    name: product.name,
    size: product.size || 'Standard',
    purchase_cost: parseFloat(product.purchase_price_with_gst) || 0,
    selling_price: parseFloat(product.selling_price) || 0,
    gst_pct: parseFloat(product.sales_gst_pct) || 5,
    max_stock: parseInt(product.current_stock) || 0,
    qty: 1,
    discount: 0,
    line_total: parseFloat(product.selling_price) || 0
  };

  window.billRows.push(row);
  renderBillTable();
  updateBillSummary();
}

function renderBillTable() {
  const tbody = document.getElementById('billTableBody');
  if (!tbody) return;

  if (window.billRows.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted" style="padding:40px"><i class="fas fa-shopping-cart" style="font-size:2.5rem;opacity:0.15;display:block;margin-bottom:12px"></i>Search and add products above</td></tr>';
    return;
  }

  tbody.innerHTML = window.billRows.map(function(row) {
    const unitPrice = Math.round(row.selling_price);
    const unitDiscount = Math.round(row.discount || 0);
    const taxableAmount = Math.round((unitPrice - unitDiscount) * row.qty);
    const gstAmt = Math.round(taxableAmount * (row.gst_pct / 100));
    const lineTotal = Math.round(taxableAmount + gstAmt);

    return '<tr id="bill-row-' + row.idx + '">' +
      '<td>' +
        '<div style="font-weight:600;font-size:0.88rem">' + escapeHtml(row.name) + '</div>' +
        '<div style="font-size:0.75rem;color:#64748b">' +
          '<span class="sku-badge">' + escapeHtml(row.sku) + '</span>' +
          (row.size !== 'Standard' ? ' <span style="color:#7c3aed">' + escapeHtml(row.size) + '</span>' : '') +
        '</div>' +
        '<input type="hidden" name="items[' + row.idx + '][variant_id]" value="' + row.variant_id + '">' +
        '<input type="hidden" name="items[' + row.idx + '][sku]" value="' + escapeHtml(row.sku) + '">' +
        '<input type="hidden" name="items[' + row.idx + '][name]" value="' + escapeHtml(row.name) + '">' +
        '<input type="hidden" name="items[' + row.idx + '][size]" value="' + escapeHtml(row.size) + '">' +
        '<input type="hidden" name="items[' + row.idx + '][purchase_cost]" value="' + row.purchase_cost + '">' +
        '<input type="hidden" name="items[' + row.idx + '][gst_pct]" value="' + row.gst_pct + '">' +
      '</td>' +
      '<td class="text-right">' +
        '<span class="price-cell">₹' + unitPrice + '</span>' +
      '</td>' +
      '<td class="text-center">' +
        '<input type="number" class="form-control qty-input" min="1" max="' + row.max_stock + '" value="' + row.qty + '" ' +
          'name="items[' + row.idx + '][qty]" style="width:75px;display:inline-block;text-align:center;" ' +
          'onchange="updateRowQty(' + row.idx + ', this.value)">' +
        '<div style="font-size:0.7rem;color:#64748b;margin-top:3px">Max: ' + row.max_stock + '</div>' +
      '</td>' +
      '<td class="text-right">' +
        '<input type="number" class="form-control qty-input" min="0" max="' + unitPrice + '" step="1" value="' + unitDiscount + '" ' +
          'name="items[' + row.idx + '][discount]" style="width:85px;display:inline-block;text-align:right;" ' +
          'onchange="updateRowDiscount(' + row.idx + ', this.value)" ' +
          (window.canApplyDiscount ? '' : 'disabled') + '>' +
      '</td>' +
      '<td class="text-right text-muted" style="font-size:0.82rem">' +
        row.gst_pct + '%<br><small>₹' + gstAmt + '</small>' +
      '</td>' +
      '<td class="text-right">' +
        '<span class="total-cell" style="font-weight:700">₹' + lineTotal + '</span>' +
        '<input type="hidden" name="items[' + row.idx + '][line_total]" value="' + lineTotal + '">' +
      '</td>' +
      '<td class="text-center">' +
        '<button type="button" class="btn btn-sm btn-light text-danger" onclick="removeBillRow(' + row.idx + ')" title="Remove">' +
          '<i class="fas fa-times"></i>' +
        '</button>' +
      '</td>' +
    '</tr>';
  }).join('');
}

function updateRowQty(idx, newQty) {
  const row = window.billRows.find(function(r) { return r.idx === idx; });
  if (!row) return;

  newQty = parseInt(newQty) || 1;
  if (newQty < 1) newQty = 1;
  if (newQty > row.max_stock) {
    if (typeof showToast === 'function') showToast('Maximum available stock is ' + row.max_stock, 'warning');
    newQty = row.max_stock;
  }

  row.qty = newQty;
  renderBillTable();
  updateBillSummary();
}

function updateRowDiscount(idx, newDiscount) {
  const row = window.billRows.find(function(r) { return r.idx === idx; });
  if (!row) return;

  let discountVal = parseFloat(newDiscount) || 0;
  if (discountVal < 0) discountVal = 0;
  if (discountVal > row.selling_price) {
    if (typeof showToast === 'function') showToast('Discount cannot exceed selling price', 'warning');
    discountVal = 0;
  }

  row.discount = discountVal;
  renderBillTable();
  updateBillSummary();
}

function removeBillRow(idx) {
  window.billRows = window.billRows.filter(function(r) { return r.idx !== idx; });
  renderBillTable();
  updateBillSummary();
}

function updateBillSummary() {
  let subtotal = 0;
  let totalDiscount = 0;
  let totalGst = 0;

  window.billRows.forEach(function(row) {
    const unitPrice = Math.round(row.selling_price);
    const unitDiscount = Math.round(row.discount || 0);
    const lineBase = unitPrice * row.qty;
    const lineDiscount = unitDiscount * row.qty;
    const taxableAmount = lineBase - lineDiscount;
    const gstAmt = Math.round(taxableAmount * (row.gst_pct / 100));

    subtotal      += lineBase;
    totalDiscount += lineDiscount;
    totalGst      += gstAmt;
  });

  const additionalCharges = Math.round(parseFloat(document.getElementById('additionalCharges')?.value || 0) || 0);
  const grandTotal = Math.round(subtotal - totalDiscount + totalGst + additionalCharges);

  setSummaryVal('summarySubtotal', '₹' + subtotal);
  setSummaryVal('summaryDiscount', '₹' + totalDiscount);
  setSummaryVal('summaryTax', '₹' + totalGst);
  setSummaryVal('summaryCharges', '₹' + additionalCharges);
  setSummaryVal('summaryTotal', '₹' + grandTotal);
  setSummaryVal('summaryItemCount', window.billRows.length + ' item(s)');

  const hiddenGT = document.getElementById('grandTotalHidden');
  if (hiddenGT) hiddenGT.value = grandTotal;

  updateBalance();
}

function updateBalance() {
  const grandTotal = Math.round(parseFloat(document.getElementById('grandTotalHidden')?.value || 0) || 0);
  const received = Math.round(parseFloat(document.getElementById('amountReceived')?.value || 0) || 0);
  const balance = Math.round(grandTotal - received);
  setSummaryVal('balanceDisplay', '₹' + Math.abs(balance));
  const balanceEl = document.getElementById('balanceDisplay');
  if (balanceEl) {
    balanceEl.style.color = balance > 0 ? '#dc2626' : '#059669';
    const balanceLabel = document.getElementById('balanceLabel');
    if (balanceLabel) balanceLabel.textContent = balance > 0 ? 'Balance Due:' : 'Change:';
  }
  const hiddenBalance = document.getElementById('balanceDueHidden');
  if (hiddenBalance) hiddenBalance.value = Math.max(0, balance);
}

function setSummaryVal(id, val) {
  const el = document.getElementById(id);
  if (el) el.textContent = val;
}

function submitBill(formId) {
  const form = document.getElementById(formId);
  if (!form) return;

  if (window.billRows.length === 0) {
    if (typeof showToast === 'function') showToast('Please add at least one product to the bill.', 'error');
    else alert('Please add at least one product to the bill.');
    return;
  }

  const customerInput = document.getElementById('customerName');
  if (!customerInput || !customerInput.value.trim()) {
    if (typeof showToast === 'function') showToast('Customer Name is required and cannot be empty.', 'error');
    else alert('Customer Name is required and cannot be empty.');
    if (customerInput) {
      customerInput.focus();
      customerInput.style.borderColor = '#ef4444';
    }
    return;
  }

  const rowCountInput = document.getElementById('billRowCount');
  if (rowCountInput) rowCountInput.value = window.billRows.length;

  const submitBtn = form.querySelector('button[onclick*="submitBill"]') || form.querySelector('[type=submit]');
  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:6px;"></i> Generating Invoice...';
  }

  const formData = new FormData(form);
  fetch(form.action, {
    method: 'POST',
    body: formData,
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
      'Accept': 'application/json'
    }
  })
  .then(function(res) {
    if (res.redirected) {
      window.location.href = res.url;
      return null;
    }
    return res.json();
  })
  .then(function(data) {
    if (!data) return;
    if (data.success && data.data && data.data.redirect) {
      window.location.href = data.data.redirect;
    } else if (data.success && data.data && data.data.sale_id) {
      window.location.href = window.APP_URL + '/admin/billing/invoice.php?id=' + data.data.sale_id;
    } else {
      if (typeof showToast === 'function') showToast(data.message || 'Error saving bill', 'error');
      else alert(data.message || 'Error saving bill');
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-check-circle" style="margin-right:6px;"></i> Save Bill &amp; Generate Invoice';
      }
    }
  })
  .catch(function(err) {
    console.warn('Fetch submission error, falling back to form submit:', err);
    form.submit();
  });
}



function validateBillForm() {
  if (window.billRows.length === 0) {
    if (typeof showToast === 'function') showToast('Please add at least one product', 'error');
    else alert('Please add at least one product');
    return false;
  }
  const customerInput = document.getElementById('customerName');
  if (!customerInput || !customerInput.value.trim()) {
    if (typeof showToast === 'function') showToast('Customer Name is required and cannot be empty.', 'error');
    else alert('Customer Name is required and cannot be empty.');
    if (customerInput) {
      customerInput.focus();
      customerInput.style.borderColor = '#ef4444';
    }
    return false;
  }
  return true;
}

// Attach event listeners for summary & balance
document.addEventListener('DOMContentLoaded', function() {
  const addCharges = document.getElementById('additionalCharges');
  if (addCharges) addCharges.addEventListener('input', updateBillSummary);

  const amountReceived = document.getElementById('amountReceived');
  if (amountReceived) amountReceived.addEventListener('input', updateBalance);
});
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
