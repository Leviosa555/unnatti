/* ============================================================
   billing.js — Billing Module JS
   ============================================================ */

'use strict';

let billRows = [];
let rowCounter = 0;

// ============================================================
// ADD PRODUCT ROW
// ============================================================
function addBillRow(product) {
  rowCounter++;
  const idx = rowCounter;
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

  billRows.push(row);
  renderBillTable();
  updateBillSummary();
}

// ============================================================
// RENDER TABLE
// ============================================================
function renderBillTable() {
  const tbody = document.getElementById('billTableBody');
  if (!tbody) return;

  if (billRows.length === 0) {
    tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted" style="padding:30px"><i class="fas fa-shopping-cart" style="font-size:2rem;opacity:0.2;display:block;margin-bottom:10px"></i>No products added yet. Search a product above.</td></tr>';
    return;
  }

  tbody.innerHTML = billRows.map(function(row) {
    const gstAmt = Math.round((row.selling_price - row.discount) * row.gst_pct / (100 + row.gst_pct) * row.qty * 100) / 100;
    const lineTotal = Math.round((row.selling_price - row.discount) * row.qty * 100) / 100;

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
        '<span class="price-cell">₹' + row.selling_price.toFixed(2) + '</span>' +
      '</td>' +
      '<td class="text-center">' +
        '<input type="number" class="form-control qty-input" min="1" max="' + row.max_stock + '" value="' + row.qty + '" ' +
          'name="items[' + row.idx + '][qty]" ' +
          'onchange="updateRowQty(' + row.idx + ', this.value)">' +
        '<div style="font-size:0.7rem;color:#64748b;margin-top:3px">Max: ' + row.max_stock + '</div>' +
      '</td>' +
      '<td class="text-right">' +
        '<input type="number" class="form-control qty-input" min="0" max="' + row.selling_price + '" step="0.01" value="' + row.discount.toFixed(2) + '" ' +
          'name="items[' + row.idx + '][discount]" ' +
          'onchange="updateRowDiscount(' + row.idx + ', this.value)" ' +
          (canApplyDiscount ? '' : 'disabled') + '>' +
      '</td>' +
      '<td class="text-right text-muted" style="font-size:0.82rem">' +
        row.gst_pct + '%<br><small>₹' + gstAmt.toFixed(2) + '</small>' +
      '</td>' +
      '<td class="text-right">' +
        '<span class="total-cell">₹' + lineTotal.toFixed(2) + '</span>' +
        '<input type="hidden" name="items[' + row.idx + '][line_total]" value="' + lineTotal + '">' +
      '</td>' +
      '<td class="text-center">' +
        '<button type="button" class="remove-row-btn" onclick="removeBillRow(' + row.idx + ')" title="Remove">' +
          '<i class="fas fa-times"></i>' +
        '</button>' +
      '</td>' +
    '</tr>';
  }).join('');
}

// ============================================================
// UPDATE ROW
// ============================================================
function updateRowQty(idx, newQty) {
  const row = billRows.find(function(r) { return r.idx === idx; });
  if (!row) return;
  newQty = parseInt(newQty) || 1;
  if (newQty < 1) newQty = 1;
  if (newQty > row.max_stock) {
    showToast('Cannot exceed available stock (' + row.max_stock + ' units)', 'warning');
    newQty = row.max_stock;
    const input = document.querySelector('#bill-row-' + idx + ' .qty-input');
    if (input) input.value = newQty;
  }
  row.qty = newQty;
  row.line_total = Math.round((row.selling_price - row.discount) * newQty * 100) / 100;

  // Update hidden + display
  const totalCell = document.querySelector('#bill-row-' + idx + ' .total-cell');
  if (totalCell) totalCell.textContent = '₹' + row.line_total.toFixed(2);
  const hiddenTotal = document.querySelector('#bill-row-' + idx + ' input[name*="[line_total]"]');
  if (hiddenTotal) hiddenTotal.value = row.line_total;

  const hiddenQty = document.querySelector('#bill-row-' + idx + ' input[name*="[qty]"]');
  if (hiddenQty) hiddenQty.value = newQty;

  updateBillSummary();
}

function updateRowDiscount(idx, discountVal) {
  const row = billRows.find(function(r) { return r.idx === idx; });
  if (!row) return;
  discountVal = parseFloat(discountVal) || 0;
  if (discountVal > row.selling_price) {
    showToast('Discount cannot exceed selling price', 'warning');
    discountVal = 0;
  }
  row.discount = discountVal;
  row.line_total = Math.round((row.selling_price - discountVal) * row.qty * 100) / 100;

  const totalCell = document.querySelector('#bill-row-' + idx + ' .total-cell');
  if (totalCell) totalCell.textContent = '₹' + row.line_total.toFixed(2);
  const hiddenTotal = document.querySelector('#bill-row-' + idx + ' input[name*="[line_total]"]');
  if (hiddenTotal) hiddenTotal.value = row.line_total;

  const hiddenDiscount = document.querySelector('#bill-row-' + idx + ' input[name*="[discount]"]');
  if (hiddenDiscount) hiddenDiscount.value = discountVal;

  updateBillSummary();
}

function removeBillRow(idx) {
  billRows = billRows.filter(function(r) { return r.idx !== idx; });
  renderBillTable();
  updateBillSummary();
}

// ============================================================
// BILL SUMMARY
// ============================================================
function updateBillSummary() {
  let subtotal = 0;
  let totalDiscount = 0;
  let totalGst = 0;

  billRows.forEach(function(row) {
    const lineBase = row.selling_price * row.qty;
    const lineDiscount = row.discount * row.qty;
    const lineAfterDiscount = lineBase - lineDiscount;
    const gstRate = row.gst_pct / (100 + row.gst_pct);
    const gstAmt = Math.round(lineAfterDiscount * gstRate * 100) / 100;

    subtotal      += lineBase;
    totalDiscount += lineDiscount;
    totalGst      += gstAmt;
  });

  const additionalCharges = parseFloat(document.getElementById('additionalCharges')?.value || 0) || 0;
  const grandTotal = Math.round((subtotal - totalDiscount + additionalCharges) * 100) / 100;

  setVal('summarySubtotal', '₹' + subtotal.toFixed(2));
  setVal('summaryDiscount', '₹' + totalDiscount.toFixed(2));
  setVal('summaryTax', '₹' + totalGst.toFixed(2));
  setVal('summaryCharges', '₹' + additionalCharges.toFixed(2));
  setVal('summaryTotal', '₹' + grandTotal.toFixed(2));
  setVal('summaryItemCount', billRows.length + ' item(s)');

  // Update hidden grand total
  const hiddenGT = document.getElementById('grandTotalHidden');
  if (hiddenGT) hiddenGT.value = grandTotal;

  // Update balance
  updateBalance();
}

function updateBalance() {
  const grandTotal = parseFloat(document.getElementById('grandTotalHidden')?.value || 0);
  const received = parseFloat(document.getElementById('amountReceived')?.value || 0);
  const balance = Math.round((grandTotal - received) * 100) / 100;
  setVal('balanceDisplay', '₹' + Math.abs(balance).toFixed(2));
  const balanceEl = document.getElementById('balanceDisplay');
  if (balanceEl) {
    balanceEl.style.color = balance > 0 ? '#dc2626' : '#059669';
    const balanceLabel = document.getElementById('balanceLabel');
    if (balanceLabel) balanceLabel.textContent = balance > 0 ? 'Balance Due:' : 'Change:';
  }
  const hiddenBalance = document.getElementById('balanceDueHidden');
  if (hiddenBalance) hiddenBalance.value = Math.max(0, balance);
}

function setVal(id, val) {
  const el = document.getElementById(id);
  if (el) el.textContent = val;
}

// ============================================================
// PRODUCT LOOKUP IN BILLING
// ============================================================
let billingSearchTimer = null;

function searchBillingProduct(q) {
  const dropdown = document.getElementById('productBillingDropdown');
  if (!dropdown) return;

  clearTimeout(billingSearchTimer);
  q = (q || '').trim();

  if (q.length < 1) {
    dropdown.style.display = 'none';
    dropdown.innerHTML = '';
    return;
  }

  billingSearchTimer = setTimeout(function() {
    apiCall((window.APP_URL || '') + '/api/search_product.php', 'GET', { q: q, item_type: 'product' })
      .then(function(res) {
        if (res.success && res.data && res.data.length > 0) {
          dropdown.innerHTML = res.data.map(function(product) {
            const catStr = (product.category_name ? product.category_name + (product.subcategory_name ? ' › ' + product.subcategory_name : '') : '');
            const isOutOfStock = parseInt(product.current_stock) <= 0;
            const stockColor = isOutOfStock ? '#dc2626' : (product.current_stock <= 5 ? '#d97706' : '#059669');
            const unitLabel = product.size || 'Unit';

            return '<div onclick="selectProductForBill(' + JSON.stringify(product).replace(/"/g, '&quot;') + ')" ' +
                   'style="padding:10px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:0.85rem;background:#fff;" ' +
                   'onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'#fff\'">' +
                     '<div style="display:flex;justify-content:space-between;align-items:center">' +
                       '<div>' +
                         '<strong style="color:#1e293b;font-size:0.9rem">' + escapeHtml(product.name) + '</strong>' +
                         (product.brand ? ' <span style="color:#7c3aed;font-size:0.8rem;font-weight:500;">(' + escapeHtml(product.brand) + ')</span>' : '') +
                       '</div>' +
                       '<div style="font-weight:700;color:#2563eb;font-size:0.92rem">' +
                         '₹' + parseFloat(product.selling_price).toFixed(2) +
                       '</div>' +
                     '</div>' +
                     '<div style="margin-top:4px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;font-size:0.75rem;">' +
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
          dropdown.innerHTML = '<div style="padding:16px;text-align:center;color:#64748b;font-size:0.85rem">' +
                               '<i class="fas fa-search" style="opacity:0.35;font-size:1.5rem;display:block;margin-bottom:6px"></i>' +
                               'No products or fabrics found matching "<strong>' + escapeHtml(q) + '</strong>"' +
                               '</div>';
          dropdown.style.display = 'block';
        }
      })
      .catch(function(err) {
        console.error('Search error:', err);
        dropdown.style.display = 'none';
      });
  }, 150);
}

function selectProductForBill(product) {
  const searchInput = document.getElementById('productSearchInput');
  const dropdown = document.getElementById('productBillingDropdown');
  if (dropdown) dropdown.style.display = 'none';

  if (parseInt(product.current_stock) <= 0) {
    showToast(product.name + ' is out of stock!', 'error');
    if (searchInput) {
      searchInput.value = '';
      searchInput.focus();
    }
    return;
  }

  // Check if already in bill
  const existing = billRows.find(function(r) { return r.variant_id === product.id; });
  if (existing) {
    if (existing.qty < existing.max_stock) {
      updateRowQty(existing.idx, existing.qty + 1);
      showToast('Quantity increased for ' + product.name, 'info');
    } else {
      showToast('Maximum available stock (' + existing.max_stock + ') reached for ' + product.name, 'warning');
    }
  } else {
    addBillRow(product);
    showToast(product.name + ' added to bill', 'success');
  }

  if (searchInput) {
    searchInput.value = '';
    searchInput.focus();
  }
}

// Fallback init
function setupBillingProductSearch() {
  const searchInput = document.getElementById('productSearchInput');
  if (!searchInput) return;

  // Add click outside listener
  document.addEventListener('click', function(e) {
    const dd = document.getElementById('productBillingDropdown');
    if (dd && !dd.contains(e.target) && e.target !== searchInput) {
      dd.style.display = 'none';
    }
  });

  searchInput.addEventListener('focus', function() {
    if (searchInput.value.trim().length >= 1) {
      searchBillingProduct(searchInput.value);
    }
  });
}

// ============================================================
// BILL FORM SUBMIT
// ============================================================
function submitBill(formId) {
  const form = document.getElementById(formId);
  if (!form) return;

  if (billRows.length === 0) {
    showToast('Please add at least one product to the bill.', 'error');
    return;
  }

  const customerName = document.getElementById('customerName')?.value?.trim();
  if (!customerName) {
    showToast('Customer name is required.', 'error');
    document.getElementById('customerName')?.focus();
    return;
  }

  // Check row count hidden input
  const rowCountInput = document.getElementById('billRowCount');
  if (rowCountInput) rowCountInput.value = billRows.length;

  // Show loading
  const submitBtn = form.querySelector('button[onclick*="submitBill"]') || form.querySelector('[type=submit]');
  if (submitBtn) {
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner spinner-sm"></span> Saving...';
  }

  form.submit();
}

// Permission flag (default fallback if not set from PHP)
if (typeof canApplyDiscount === 'undefined') {
  window.canApplyDiscount = false;
}

// Init on DOM ready
document.addEventListener('DOMContentLoaded', function() {
  setupBillingProductSearch();

  const addCharges = document.getElementById('additionalCharges');
  if (addCharges) addCharges.addEventListener('input', updateBillSummary);

  const amountReceived = document.getElementById('amountReceived');
  if (amountReceived) amountReceived.addEventListener('input', updateBalance);
});
