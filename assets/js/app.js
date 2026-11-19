/* ============================================================
   app.js — Unnatti Enterprises Mini ERP Core JS
   ============================================================ */

'use strict';

// ============================================================
// SIDEBAR
// ============================================================
function initSidebar() {
  const wrapper = document.getElementById('wrapper');
  if (!wrapper) return;

  // Restore collapsed state from localStorage
  if (localStorage.getItem('sidebarCollapsed') === '1') {
    wrapper.classList.add('sidebar-collapsed');
  }

  // Active menu detection
  markActiveNav();

  // Close dropdown on outside click
  document.addEventListener('click', function(e) {
    const dropdowns = document.querySelectorAll('.dropdown-menu.show');
    dropdowns.forEach(function(dd) {
      if (!dd.parentElement.contains(e.target)) {
        dd.classList.remove('show');
      }
    });
  });
}

function toggleSidebar() {
  const wrapper = document.getElementById('wrapper');
  const overlay = document.getElementById('sidebarOverlay');
  const sidebar = document.getElementById('sidebar');

  if (window.innerWidth <= 991) {
    // Mobile: slide in/out
    if (sidebar) sidebar.classList.toggle('mobile-open');
    if (overlay) overlay.classList.toggle('active');
  } else {
    // Desktop: collapse/expand
    if (wrapper) {
      wrapper.classList.toggle('sidebar-collapsed');
      localStorage.setItem('sidebarCollapsed', wrapper.classList.contains('sidebar-collapsed') ? '1' : '0');
    }
  }
}

function closeSidebar() {
  const sidebar = document.getElementById('sidebar');
  const overlay = document.getElementById('sidebarOverlay');
  if (sidebar) sidebar.classList.remove('mobile-open');
  if (overlay) overlay.classList.remove('active');
}

function toggleSubmenu(trigger) {
  const li = trigger.parentElement;
  const submenu = li.querySelector('.submenu');
  const isOpen = submenu && submenu.classList.contains('open');

  // Close all other submenus
  document.querySelectorAll('.nav-item.submenu-open').forEach(function(item) {
    item.classList.remove('submenu-open');
    const sub = item.querySelector('.submenu');
    if (sub) sub.classList.remove('open');
  });

  if (!isOpen && submenu) {
    submenu.classList.add('open');
    li.classList.add('submenu-open');
  }
}

function markActiveNav() {
  const currentPath = window.location.pathname;
  const links = document.querySelectorAll('.sidebar-menu .nav-item > a, .sidebar-menu .submenu li a');
  links.forEach(function(link) {
    const href = link.getAttribute('href') || '';
    if (href && href !== '#' && currentPath.includes(href.split('?')[0].split('/').slice(-2).join('/'))) {
      const navItem = link.closest('.nav-item');
      if (navItem) navItem.classList.add('active');
      // Open parent submenu if inside one
      const parentSubmenu = link.closest('.submenu');
      if (parentSubmenu) {
        parentSubmenu.classList.add('open');
        const parentLi = parentSubmenu.closest('.nav-item');
        if (parentLi) parentLi.classList.add('submenu-open');
      }
    }
  });
}

// ============================================================
// DROPDOWN TOGGLE
// ============================================================
function toggleDropdown(menuId) {
  const menu = document.getElementById(menuId);
  if (!menu) return;
  const isShown = menu.classList.contains('show');
  // Close all
  document.querySelectorAll('.dropdown-menu.show').forEach(function(d) { d.classList.remove('show'); });
  if (!isShown) menu.classList.add('show');
}

// ============================================================
// TOASTS
// ============================================================
function showToast(message, type) {
  type = type || 'success';
  const icons = { success: 'fa-check-circle', error: 'fa-times-circle', warning: 'fa-exclamation-triangle', info: 'fa-info-circle' };
  const icon = icons[type] || icons.info;

  const container = document.getElementById('toastContainer');
  if (!container) return;

  const toast = document.createElement('div');
  toast.className = 'toast ' + type;
  toast.innerHTML =
    '<i class="fas ' + icon + ' toast-icon"></i>' +
    '<div class="toast-body">' + escapeHtml(message) + '</div>' +
    '<button class="toast-close" onclick="dismissToast(this.parentElement)">&times;</button>';

  container.appendChild(toast);

  // Auto-dismiss after 4 seconds
  setTimeout(function() { dismissToast(toast); }, 4000);
}

function dismissToast(toast) {
  if (!toast || !toast.parentElement) return;
  toast.classList.add('hiding');
  setTimeout(function() { if (toast.parentElement) toast.parentElement.removeChild(toast); }, 250);
}

// ============================================================
// MODALS
// ============================================================
function openModal(id) {
  const overlay = document.getElementById(id);
  if (overlay) {
    overlay.classList.add('active');
    document.body.style.overflow = 'hidden';
    // Focus first input
    setTimeout(function() {
      const first = overlay.querySelector('input:not([type=hidden]), select, textarea');
      if (first) first.focus();
    }, 100);
  }
}

function closeModal(id) {
  const overlay = document.getElementById(id);
  if (overlay) {
    overlay.classList.remove('active');
    document.body.style.overflow = '';
  }
}

// Close modal on overlay click
document.addEventListener('click', function(e) {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.classList.remove('active');
    document.body.style.overflow = '';
  }
});

// Close modal on Escape
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.active').forEach(function(m) {
      m.classList.remove('active');
      document.body.style.overflow = '';
    });
    document.querySelectorAll('.dropdown-menu.show').forEach(function(d) { d.classList.remove('show'); });
  }
});

// ============================================================
// CONFIRM DIALOG
// ============================================================
function confirmAction(message, onConfirm) {
  // Use a simple modal-based confirm (falls back to browser confirm)
  const modal = document.getElementById('confirmModal');
  if (modal) {
    document.getElementById('confirmMessage').textContent = message;
    openModal('confirmModal');
    const btn = document.getElementById('confirmOkBtn');
    const newBtn = btn.cloneNode(true); // Remove old listeners
    btn.parentNode.replaceChild(newBtn, btn);
    newBtn.addEventListener('click', function() {
      closeModal('confirmModal');
      if (onConfirm) onConfirm();
    });
  } else {
    if (window.confirm(message)) {
      if (onConfirm) onConfirm();
    }
  }
}

// ============================================================
// AUTO-HIDE ALERTS
// ============================================================
function autoHideAlerts() {
  document.querySelectorAll('.alert-dismissible').forEach(function(alert) {
    setTimeout(function() {
      alert.style.transition = 'opacity 0.5s';
      alert.style.opacity = '0';
      setTimeout(function() { if (alert.parentElement) alert.remove(); }, 500);
    }, 5000);
  });
}

// ============================================================
// TABS
// ============================================================
function switchTab(tabBtn, tabPanelId) {
  const parent = tabBtn.closest('.tab-nav');
  if (parent) {
    parent.querySelectorAll('.tab-btn').forEach(function(b) { b.classList.remove('active'); });
  }
  tabBtn.classList.add('active');

  // Hide all panels in the same container
  const container = tabBtn.closest('.card, .tab-wrapper, .content-area');
  if (container) {
    container.querySelectorAll('.tab-panel').forEach(function(p) { p.classList.remove('active'); });
  }
  const panel = document.getElementById(tabPanelId);
  if (panel) panel.classList.add('active');
}

// ============================================================
// UTILITIES
// ============================================================
function debounce(fn, delay) {
  let timer;
  return function() {
    const args = arguments;
    const ctx = this;
    clearTimeout(timer);
    timer = setTimeout(function() { fn.apply(ctx, args); }, delay || 300);
  };
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

function formatCurrency(amount) {
  const num = parseFloat(amount) || 0;
  return '₹' + num.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatNumber(n, decimals) {
  const d = decimals !== undefined ? decimals : 2;
  return parseFloat(n || 0).toFixed(d);
}

// ============================================================
// AJAX HELPER
// ============================================================
function apiCall(url, method, data) {
  method = (method || 'GET').toUpperCase();
  const opts = {
    method: method,
    headers: { 'Accept': 'application/json' }
  };

  if (method === 'POST') {
    if (data instanceof FormData) {
      opts.body = data;
    } else if (data) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(data);
    }
  } else if (data) {
    const params = new URLSearchParams(data);
    url += (url.includes('?') ? '&' : '?') + params.toString();
  }

  return fetch(url, opts).then(function(res) {
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return res.json();
  });
}

// ============================================================
// PRICING CALCULATOR (mirrors PHP engine)
// ============================================================
function calculatePricing(purchasePrice, purchaseGstPct, profitMarkupPct, salesGstPct, qty) {
  purchasePrice = parseFloat(purchasePrice) || 0;
  purchaseGstPct = parseFloat(purchaseGstPct) || 5;
  profitMarkupPct = parseFloat(profitMarkupPct) || 65;
  salesGstPct = parseFloat(salesGstPct) || 5;
  qty = parseInt(qty) || 1;

  const purchaseGstAmount    = Math.round(purchasePrice * purchaseGstPct / 100 * 100) / 100;
  const purchasePriceWithGst = Math.round((purchasePrice + purchaseGstAmount) * 100) / 100;
  const totalPurchaseValue   = Math.round(purchasePriceWithGst * qty * 100) / 100;
  const profitAmount         = Math.round(purchasePriceWithGst * profitMarkupPct / 100 * 100) / 100;
  const sellingPriceBeforeGst = Math.round((purchasePriceWithGst + profitAmount) * 100) / 100;
  const salesGstAmount       = Math.round(sellingPriceBeforeGst * salesGstPct / 100 * 100) / 100;
  const sellingPrice         = Math.round((sellingPriceBeforeGst + salesGstAmount) * 100) / 100;
  const totalSellingValue    = Math.round(sellingPrice * qty * 100) / 100;
  const grossProfit          = Math.round((totalSellingValue - totalPurchaseValue) * 100) / 100;

  return {
    purchasePrice, purchaseGstPct, purchaseGstAmount, purchasePriceWithGst,
    profitMarkupPct, profitAmount, sellingPriceBeforeGst,
    salesGstPct, salesGstAmount, sellingPrice,
    qty, totalPurchaseValue, totalSellingValue, grossProfit
  };
}

// ============================================================
// CLIENT-SIDE TABLE SEARCH
// ============================================================
function initTableSearch(inputId, tableId, columns) {
  const input = document.getElementById(inputId);
  const table = document.getElementById(tableId);
  if (!input || !table) return;

  input.addEventListener('input', debounce(function() {
    const term = input.value.toLowerCase().trim();
    const rows = table.querySelectorAll('tbody tr');
    rows.forEach(function(row) {
      const cells = row.querySelectorAll('td');
      let match = !term;
      if (!match) {
        const cols = columns || Array.from({length: cells.length}, function(_, i) { return i; });
        cols.forEach(function(idx) {
          if (cells[idx] && cells[idx].textContent.toLowerCase().includes(term)) match = true;
        });
      }
      row.style.display = match ? '' : 'none';
    });
  }, 250));
}

// ============================================================
// CSV EXPORT
// ============================================================
function exportTableToCSV(tableId, filename) {
  const table = document.getElementById(tableId);
  if (!table) return;
  const rows = table.querySelectorAll('tr');
  const csvLines = [];
  rows.forEach(function(row) {
    const cols = row.querySelectorAll('th, td');
    const line = Array.from(cols).map(function(col) {
      let text = col.innerText.replace(/\n/g, ' ').trim();
      // Remove action buttons text
      if (col.querySelector('.table-actions')) return '';
      text = text.replace(/"/g, '""');
      return '"' + text + '"';
    }).join(',');
    csvLines.push(line);
  });
  const blob = new Blob([csvLines.join('\n')], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = (filename || 'export') + '_' + new Date().toISOString().slice(0,10) + '.csv';
  a.click();
  URL.revokeObjectURL(url);
}

// ============================================================
// PRINT
// ============================================================
function printInvoice() {
  window.print();
}

// ============================================================
// KEYBOARD SHORTCUTS
// ============================================================
document.addEventListener('keydown', function(e) {
  if (e.ctrlKey || e.metaKey) {
    if (e.key === 'b') {
      e.preventDefault();
      const billLink = document.querySelector('a[href*="create_bill"]') || document.querySelector('a[href*="create.php"]');
      if (billLink) window.location.href = billLink.href;
    }
    if (e.key === 'p' && document.querySelector('.invoice-wrapper')) {
      e.preventDefault();
      printInvoice();
    }
  }
});

// ============================================================
// PRODUCT SEARCH AUTOCOMPLETE (shared)
// ============================================================
function initProductSearch(inputEl, callback) {
  if (!inputEl) return;
  const wrapper = inputEl.parentElement;

  let dropdown = wrapper.querySelector('.product-search-dropdown');
  if (!dropdown) {
    dropdown = document.createElement('div');
    dropdown.className = 'product-search-dropdown';
    dropdown.style.cssText = 'position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,0.12);z-index:10000;max-height:280px;overflow-y:auto;display:none;margin-top:4px;';
    wrapper.style.position = 'relative';
    wrapper.style.zIndex = '10001';
    wrapper.appendChild(dropdown);
  }

  const search = debounce(function() {
    const q = inputEl.value.trim();
    if (q.length < 1) { dropdown.style.display = 'none'; return; }

    apiCall(window.APP_URL + '/api/search_product.php', 'GET', { q: q }).then(function(res) {
      dropdown.innerHTML = '';
      if (res.success && res.data && res.data.length) {
        res.data.forEach(function(product) {
          const item = document.createElement('div');
          item.style.cssText = 'padding:10px 14px;cursor:pointer;border-bottom:1px solid #f1f5f9;font-size:0.85rem;';
          item.innerHTML =
            '<strong>' + escapeHtml(product.name) + '</strong>' +
            ' <span style="color:#64748b;font-size:0.78rem;">[' + escapeHtml(product.sku) + ']</span>' +
            (product.size !== 'Standard' ? ' <span style="color:#7c3aed;font-size:0.78rem;">' + escapeHtml(product.size) + '</span>' : '') +
            '<div style="font-size:0.76rem;color:#64748b;margin-top:2px;">' +
            'Stock: <strong style="color:' + (product.current_stock > 0 ? '#059669' : '#dc2626') + '">' + product.current_stock + '</strong>' +
            ' | Price: <strong>₹' + parseFloat(product.selling_price).toFixed(2) + '</strong>' +
            '</div>';
          item.addEventListener('mousedown', function(e) {
            e.preventDefault();
            inputEl.value = product.sku;
            dropdown.style.display = 'none';
            dropdown.innerHTML = '';
            inputEl.blur();
            if (callback) callback(product);
          });
          item.addEventListener('mouseover', function() { item.style.background = '#f8fafc'; });
          item.addEventListener('mouseout', function() { item.style.background = ''; });
          dropdown.appendChild(item);
        });
        dropdown.style.display = 'block';
      } else {
        dropdown.style.display = 'none';
        dropdown.innerHTML = '';
      }
    }).catch(function() { 
      dropdown.style.display = 'none'; 
      dropdown.innerHTML = '';
    });
  }, 280);

  inputEl.addEventListener('input', search);
  inputEl.addEventListener('blur', function() { setTimeout(function() { dropdown.style.display = 'none'; }, 200); });
}

// ============================================================
// GLOBAL 10-DIGIT MOBILE NUMBER ENFORCEMENT
// ============================================================
(function() {
  function isTargetPhone(el) {
    if (!el || !el.tagName || el.tagName.toLowerCase() !== 'input') return false;
    const type = (el.type || '').toLowerCase();
    const name = (el.name || '').toLowerCase();
    const id   = (el.id || '').toLowerCase();

    // Skip general search / filter inputs
    const isSearch = type === 'search' || name.includes('search') || id.includes('search') || name === 'q' || id === 'q';
    if (isSearch) return false;

    return type === 'tel' ||
           name.includes('mobile') || name.includes('phone') ||
           id.includes('mobile') || id.includes('phone') ||
           el.classList.contains('phone-input') || el.classList.contains('mobile-input');
  }

  // Real-time input cleaner (handles typing, paste, drag & drop)
  document.addEventListener('input', function(e) {
    const el = e.target;
    if (isTargetPhone(el)) {
      const cleaned = el.value.replace(/\D/g, '').slice(0, 10);
      if (el.value !== cleaned) {
        el.value = cleaned;
      }
    }
  });

  // Keypress preventer (blocks non-digits from being typed)
  document.addEventListener('keypress', function(e) {
    const el = e.target;
    if (isTargetPhone(el)) {
      // Allow enter key (13), prevent non-numeric characters
      if (e.charCode !== 0 && e.charCode !== 13 && (e.charCode < 48 || e.charCode > 57)) {
        e.preventDefault();
      }
      // Block additional digits if already 10 digits and nothing is selected
      const selStart = el.selectionStart;
      const selEnd   = el.selectionEnd;
      if (el.value.length >= 10 && selStart === selEnd && e.charCode >= 48 && e.charCode <= 57) {
        e.preventDefault();
      }
    }
  });
})();

// Global APP_URL (set in each page)
window.APP_URL = window.APP_URL || '';
