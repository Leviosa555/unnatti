<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireAdmin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $variantId = (int)($_POST['variant_id'] ?? 0);
    $adjType   = sanitize($_POST['adjustment_type'] ?? '');  // 'add' or 'subtract'
    $qty       = (int)($_POST['qty'] ?? 0);
    $reason    = sanitize($_POST['reason'] ?? '');

    if (!$variantId) $errors[] = 'Please search and select a product.';
    if ($qty <= 0) $errors[] = 'Quantity must be greater than zero.';
    if (empty($reason)) $errors[] = 'Reason for adjustment is required.';
    if (!in_array($adjType, ['add', 'subtract'])) $errors[] = 'Invalid adjustment type selected.';

    if (empty($errors)) {
        $variant = db()->fetchOne(
            "SELECT pv.*, p.name AS product_name 
             FROM product_variants pv 
             JOIN products p ON pv.product_id = p.id 
             WHERE pv.id = ?",
            [$variantId]
        );
        if (!$variant) { 
            $errors[] = 'Product not found.'; 
        } elseif ($adjType === 'subtract' && $variant['current_stock'] < $qty) {
            $errors[] = "Cannot subtract $qty unit(s) — only {$variant['current_stock']} currently in stock.";
        }
    }

    if (empty($errors)) {
        try {
            db()->beginTransaction();
            $adjNo     = generateAdjustmentNumber();
            $qtyChange = $adjType === 'add' ? $qty : -$qty;

            $adjId = db()->insert(
                "INSERT INTO stock_adjustments (adjustment_no, variant_id, adjustment_type, qty_change, reason, created_by)
                 VALUES (?,?,?,?,?,?)",
                [$adjNo, $variantId, $adjType, $qtyChange, $reason, currentUserId()]
            );

            recordStockMovement($variantId, 'adjustment', $qtyChange, $adjId, 'adjustment', "Adj: $adjNo — $reason", currentUserId());
            db()->commit();

            logActivity("Stock adjustment: $adjNo ({$qtyChange} units for {$variant['product_name']})", 'stock', $adjId, 'adjustment');
            $_SESSION['flash_success'] = "Adjustment $adjNo recorded successfully. Stock updated.";
            
            $activeProfile = getActiveProfile();
            if ($activeProfile === 'fashion_kingdom' || ($variant['item_type'] ?? '') === 'material') {
                header('Location: ' . APP_URL . '/admin/purchases/materials.php'); 
            } else {
                header('Location: ' . APP_URL . '/admin/products/index.php'); 
            }
            exit;
        } catch (Exception $e) {
            db()->rollback();
            $errors[] = 'Error saving adjustment: ' . $e->getMessage();
        }
    }
}

// Check for prefill variant (e.g. from Shop Products quick action)
$prefillId = (int)($_GET['variant_id'] ?? 0);
$prefillVariant = null;
if ($prefillId) {
    $prefillVariant = db()->fetchOne(
        "SELECT pv.id, pv.sku, pv.size, pv.current_stock, pv.min_stock_alert,
                pv.selling_price, pv.purchase_price_with_gst,
                p.id AS product_id, p.name AS product_name, p.brand, p.item_type, p.image,
                c.name AS category_name
         FROM product_variants pv 
         JOIN products p ON pv.product_id = p.id 
         LEFT JOIN categories c ON p.category_id = c.id
         WHERE pv.id = ?",
        [$prefillId]
    );
}

$activeProfile = getActiveProfile();
$pageTitle  = 'Stock Adjustment';
$activePage = ($activeProfile === 'fashion_kingdom') ? 'materials' : 'products';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<script>window.APP_URL = '<?= APP_URL ?>';</script>

<style>
.stock-adj-grid {
  display: grid;
  grid-template-columns: 1.15fr 1fr;
  gap: 24px;
  align-items: start;
}
@media (max-width: 960px) {
  .stock-adj-grid {
    grid-template-columns: 1fr;
  }
}
.search-wrapper {
  position: relative;
}
.product-autocomplete-dropdown {
  position: absolute;
  top: 100%;
  left: 0;
  right: 0;
  background: #ffffff;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  box-shadow: 0 10px 25px rgba(0,0,0,0.12);
  z-index: 99999;
  max-height: 290px;
  overflow-y: auto;
  margin-top: 4px;
  display: none;
}
.autocomplete-item {
  padding: 10px 14px;
  cursor: pointer;
  border-bottom: 1px solid #f1f5f9;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  transition: background 0.15s ease;
}
.autocomplete-item:hover, .autocomplete-item.active {
  background: #f8fafc;
}
.autocomplete-item:last-child {
  border-bottom: none;
}
.reason-chip {
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  color: #475569;
  font-size: 0.75rem;
  padding: 4px 10px;
  border-radius: 20px;
  cursor: pointer;
  transition: all 0.15s;
}
.reason-chip:hover {
  background: #e2e8f0;
  color: #1e293b;
}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title"><i class="fas fa-sliders-h text-primary" style="margin-right:10px"></i>Stock Adjustment</h1>
    <p class="page-subtitle">
      <?= ($activeProfile === 'fashion_kingdom') ? 'Manually correct fabric meters &amp; tailoring material counts' : 'Manually correct stock levels, record physical counts, or write off damaged boutique garments' ?>
    </p>
  </div>
  <div class="page-header-right">
    <?php if ($activeProfile === 'fashion_kingdom'): ?>
      <a href="<?= APP_URL ?>/admin/purchases/materials.php" class="btn btn-light"><i class="fas fa-arrow-left"></i> Back to Materials &amp; Stock</a>
    <?php else: ?>
      <a href="<?= APP_URL ?>/admin/products/index.php" class="btn btn-light"><i class="fas fa-arrow-left"></i> Back to Shop Products</a>
    <?php endif; ?>
  </div>
</div>

<?php foreach ($errors as $err): ?>
  <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= e($err) ?></div>
<?php endforeach; ?>

<div class="stock-adj-grid">
  <!-- ========================================== -->
  <!-- LEFT COLUMN: ADJUSTMENT INPUT FORM         -->
  <!-- ========================================== -->
  <form method="POST" id="adjustmentForm" style="width:100%">
    <?= csrfField() ?>
    <div class="card" style="overflow:visible">
      <div class="card-header">
        <span class="card-title"><i class="fas fa-sliders-h"></i> Adjustment Details</span>
      </div>
      <div class="card-body">
        
        <!-- Product Search Autocomplete -->
        <div class="form-group">
          <label class="form-label" style="font-weight:600">Product / Garment <span class="required">*</span></label>
          <div class="search-wrapper">
            <div style="position:relative;display:flex;align-items:center">
              <i class="fas fa-search" style="position:absolute;left:12px;color:#94a3b8;font-size:0.9rem;pointer-events:none"></i>
              <input type="text" 
                     id="adjProductSearch" 
                     class="form-control" 
                     placeholder="Type garment name, brand, SKU..." 
                     autocomplete="off" 
                     value="<?= $prefillVariant ? e($prefillVariant['product_name'] . ' [' . $prefillVariant['sku'] . ']') : '' ?>"
                     style="padding-left:36px;padding-right:34px">
              <button type="button" 
                      id="clearSearchBtn" 
                      onclick="clearSelectedProduct()" 
                      style="position:absolute;right:8px;background:none;border:none;color:#94a3b8;padding:4px 8px;display:<?= $prefillVariant ? 'block' : 'none' ?>" 
                      title="Clear selection">
                <i class="fas fa-times-circle"></i>
              </button>
            </div>
            
            <input type="hidden" name="variant_id" id="adjVariantId" value="<?= $prefillVariant ? $prefillVariant['id'] : '' ?>">
            
            <!-- Autocomplete Dropdown List -->
            <div class="product-autocomplete-dropdown" id="productDropdown"></div>
          </div>
          <small class="text-muted" style="display:block;margin-top:4px">
            Start typing to see matching garments, sizes &amp; live stock in the dropdown.
          </small>
        </div>

        <!-- Adjustment Type & Quantity -->
        <div class="form-row cols-2" style="margin-top:16px">
          <div class="form-group">
            <label class="form-label" style="font-weight:600">Action Type <span class="required">*</span></label>
            <select name="adjustment_type" id="adjustmentType" class="form-select" onchange="updateSimulation()" required>
              <option value="add">Add Stock</option>
              <option value="subtract" selected>Remove Stock</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" style="font-weight:600">Quantity <span class="required">*</span></label>
            <input type="number" 
                   name="qty" 
                   id="adjustmentQty" 
                   class="form-control" 
                   min="1" 
                   value="1" 
                   oninput="updateSimulation()" 
                   required>
          </div>
        </div>

        <!-- Reason -->
        <div class="form-group mb-0">
          <label class="form-label" style="font-weight:600">Reason for Adjustment <span class="required">*</span></label>
          <textarea name="reason" 
                    id="adjustmentReason" 
                    class="form-control" 
                    rows="3" 
                    required 
                    placeholder="e.g. Physical count discrepancy, damaged stitching, sample display..."></textarea>
          
          <!-- Quick Reason Chips -->
          <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px">
            <button type="button" class="reason-chip" onclick="setReason('Physical count audit correction')">Physical Count Audit</button>
            <button type="button" class="reason-chip" onclick="setReason('Damaged / Defective garment')">Damaged / Defective</button>
            <button type="button" class="reason-chip" onclick="setReason('Customer return to rack')">Return to Rack</button>
            <button type="button" class="reason-chip" onclick="setReason('Sample display piece')">Sample Piece</button>
          </div>
        </div>

      </div>
      <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center">
        <a href="<?= APP_URL ?>/admin/products/index.php" class="btn btn-light">Cancel</a>
        <button type="submit" class="btn btn-warning" id="submitAdjBtn">
          <i class="fas fa-check"></i> Apply Stock Adjustment
        </button>
      </div>
    </div>
  </form>

  <!-- ======================================================= -->
  <!-- RIGHT COLUMN: SELECTED PRODUCT & LIVE STOCK DETAILS BOX -->
  <!-- ======================================================= -->
  <div class="card" id="selectedProductCard">
    <div class="card-header" style="background:#f8fafc">
      <span class="card-title">
        <i class="fas fa-boxes text-primary"></i> Selected Product &amp; Stock Status
      </span>
      <span id="headerStockBadge"></span>
    </div>
    <div class="card-body">
      
      <!-- Empty State (No Product Selected) -->
      <div id="productEmptyState" style="<?= $prefillVariant ? 'display:none;' : '' ?>text-align:center;padding:45px 20px;color:#94a3b8">
        <div style="width:68px;height:68px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;margin:0 auto 16px">
          <i class="fas fa-tshirt" style="font-size:2rem;color:#cbd5e1"></i>
        </div>
        <h4 style="color:#64748b;margin-bottom:6px;font-weight:600">No Product Selected</h4>
        <p style="font-size:0.85rem;max-width:320px;margin:0 auto;line-height:1.5">
          Type the garment name or SKU in the search input on the left. The live stock count, sizes, and pricing details will show up right here.
        </p>
      </div>

      <!-- Selected Product Details View -->
      <div id="productDetailsView" style="<?= $prefillVariant ? '' : 'display:none' ?>">
        
        <!-- Product Header Info -->
        <div style="display:flex;gap:14px;align-items:center;padding-bottom:16px;border-bottom:1px solid #f1f5f9">
          <div id="dispProductImage">
            <div style="width:48px;height:48px;border-radius:8px;background:#ede9fe;color:#7c3aed;display:flex;align-items:center;justify-content:center;font-size:1.4rem">
              <i class="fas fa-tshirt"></i>
            </div>
          </div>
          <div style="flex:1">
            <h3 id="dispProductName" style="font-size:1.15rem;font-weight:700;margin-bottom:2px;color:#0f172a">
              <?= $prefillVariant ? e($prefillVariant['product_name']) : '' ?>
            </h3>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              <span id="dispBrand" style="font-size:0.78rem;color:#64748b">
                <?= $prefillVariant && $prefillVariant['brand'] ? e($prefillVariant['brand']) : '' ?>
              </span>
              <span id="dispCategory" style="font-size:0.75rem;background:#f1f5f9;color:#475569;padding:2px 8px;border-radius:4px">
                <?= $prefillVariant ? e($prefillVariant['category_name'] ?? 'Garment') : '' ?>
              </span>
              <span id="dispSize" class="badge badge-light" style="font-weight:600;font-size:0.75rem">
                Size: <?= $prefillVariant ? e($prefillVariant['size']) : '' ?>
              </span>
            </div>
          </div>
        </div>

        <!-- SKU Code -->
        <div style="margin-top:14px;display:flex;justify-content:space-between;align-items:center">
          <span style="font-size:0.8rem;color:#64748b">SKU Reference:</span>
          <span id="dispSku" class="sku-badge"><?= $prefillVariant ? e($prefillVariant['sku']) : '' ?></span>
        </div>

        <!-- LIVE STOCK REMAINING BOX -->
        <div style="background:#f8fafc;border:2px solid #e2e8f0;border-radius:12px;padding:16px 20px;margin:16px 0;display:flex;align-items:center;justify-content:space-between">
          <div>
            <div style="font-size:0.72rem;text-transform:uppercase;letter-spacing:0.06em;color:#64748b;font-weight:600">
              Live Stock Remaining
            </div>
            <div style="display:flex;align-items:baseline;gap:8px;margin-top:2px">
              <span id="dispStockCount" style="font-size:2.2rem;font-weight:800;color:#0f172a;line-height:1">
                <?= $prefillVariant ? number_format($prefillVariant['current_stock']) : '0' ?>
              </span>
              <span style="font-size:0.9rem;font-weight:600;color:#64748b">Units Left</span>
            </div>
          </div>
          <div id="dispStockStatusBadge">
            <?php if ($prefillVariant): ?>
              <?php if ($prefillVariant['current_stock'] == 0): ?>
                <span class="badge badge-danger" style="font-size:0.85rem;padding:6px 12px">Out of Stock</span>
              <?php elseif ($prefillVariant['current_stock'] <= $prefillVariant['min_stock_alert']): ?>
                <span class="badge badge-warning" style="font-size:0.85rem;padding:6px 12px"><?= $prefillVariant['current_stock'] ?> ⚠️ Low Stock</span>
              <?php else: ?>
                <span class="badge badge-success" style="font-size:0.85rem;padding:6px 12px">In Stock</span>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- Dynamic Stock Projection Calculation -->
        <div style="background:#ffffff;border:1px dashed #cbd5e1;border-radius:10px;padding:14px;margin-bottom:16px">
          <div style="font-size:0.75rem;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px">
            <i class="fas fa-calculator text-primary"></i> Stock Impact Preview
          </div>
          
          <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:6px">
            <span style="color:#64748b">Current Stock Level:</span>
            <strong id="calcCurrentStock"><?= $prefillVariant ? (int)$prefillVariant['current_stock'] : 0 ?> units</strong>
          </div>
          
          <div style="display:flex;justify-content:space-between;font-size:0.85rem;margin-bottom:6px">
            <span style="color:#64748b">Adjustment Quantity:</span>
            <strong id="calcAdjQuantity" style="color:#dc2626">-1 unit</strong>
          </div>
          
          <div style="border-top:1px solid #e2e8f0;padding-top:8px;margin-top:6px;display:flex;justify-content:space-between;align-items:center">
            <span style="font-weight:600;color:#1e293b;font-size:0.9rem">Projected New Stock:</span>
            <strong id="calcNewStock" style="font-size:1.15rem;color:#059669">
              <?= $prefillVariant ? max(0, (int)$prefillVariant['current_stock'] - 1) . ' units' : '—' ?>
            </strong>
          </div>

          <!-- Excess deduction warning -->
          <div id="stockWarningBox" style="display:none;margin-top:10px;padding:8px 12px;background:#fef2f2;border-left:3px solid #dc2626;border-radius:4px;font-size:0.82rem;color:#dc2626">
            <i class="fas fa-exclamation-triangle"></i> <strong>Insufficient stock:</strong> You cannot subtract more units than currently in stock.
          </div>
        </div>

        <!-- Pricing & Valuation -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:10px;background:#f8fafc;border-radius:8px;font-size:0.82rem">
          <div>
            <span style="color:#64748b;display:block">Selling Price</span>
            <strong id="dispSellingPrice" style="color:#0f172a;font-size:0.95rem">
              <?= $prefillVariant ? formatCurrency($prefillVariant['selling_price']) : '₹0.00' ?>
            </strong>
          </div>
          <div>
            <span style="color:#64748b;display:block">Cost / Unit</span>
            <strong id="dispCostPrice" style="color:#0f172a;font-size:0.95rem">
              <?= $prefillVariant ? formatCurrency($prefillVariant['purchase_price_with_gst']) : '₹0.00' ?>
            </strong>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<script>
// Keep selected product object in state
let selectedProduct = <?= $prefillVariant ? json_encode([
  'id'                       => (int)$prefillVariant['id'],
  'sku'                      => $prefillVariant['sku'],
  'name'                     => $prefillVariant['product_name'],
  'brand'                    => $prefillVariant['brand'] ?? '',
  'category_name'            => $prefillVariant['category_name'] ?? 'Garment',
  'size'                     => $prefillVariant['size'] ?? 'Standard',
  'current_stock'            => (int)$prefillVariant['current_stock'],
  'min_stock_alert'          => (int)$prefillVariant['min_stock_alert'],
  'selling_price'            => (float)$prefillVariant['selling_price'],
  'purchase_price_with_gst'  => (float)$prefillVariant['purchase_price_with_gst'],
  'image'                    => $prefillVariant['image'] ?? ''
]) : 'null' ?>;

let currentSearchResults = [];

const searchInput    = document.getElementById('adjProductSearch');
const variantIdInput = document.getElementById('adjVariantId');
const dropdown       = document.getElementById('productDropdown');
const clearBtn       = document.getElementById('clearSearchBtn');
const submitBtn      = document.getElementById('submitAdjBtn');

let searchTimeout = null;

// Handle search input typing
searchInput.addEventListener('input', function() {
  const query = this.value.trim();
  clearTimeout(searchTimeout);
  
  // If user modifies the text, clear the previous selection ID
  if (selectedProduct && this.value !== (selectedProduct.name + (selectedProduct.sku ? ' [' + selectedProduct.sku + ']' : ''))) {
    variantIdInput.value = '';
    clearBtn.style.display = 'none';
  }

  if (query.length < 1) {
    dropdown.style.display = 'none';
    dropdown.innerHTML = '';
    currentSearchResults = [];
    return;
  }
  
  searchTimeout = setTimeout(() => {
    fetchProducts(query);
  }, 200);
});

// Show dropdown again on focus if query present and not already selected
searchInput.addEventListener('focus', function() {
  const query = this.value.trim();
  if (query.length >= 1 && currentSearchResults.length > 0 && !variantIdInput.value) {
    dropdown.style.display = 'block';
  }
});

// Close dropdown on outside click
document.addEventListener('click', function(e) {
  if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
    dropdown.style.display = 'none';
  }
});

// Fetch product suggestions from API
function fetchProducts(query) {
  fetch(window.APP_URL + '/api/search_product.php?q=' + encodeURIComponent(query))
    .then(res => res.json())
    .then(res => {
      if (!res.success || !res.data || res.data.length === 0) {
        currentSearchResults = [];
        dropdown.innerHTML = '<div style="padding:14px;text-align:center;color:#94a3b8;font-size:0.85rem"><i class="fas fa-search" style="margin-right:6px"></i> No products matching "' + escapeHtml(query) + '"</div>';
        dropdown.style.display = 'block';
        return;
      }
      
      currentSearchResults = res.data;
      let html = '';
      res.data.forEach((item, index) => {
        let stockBadge = '';
        if (item.current_stock <= 0) {
          stockBadge = '<span class="badge badge-danger" style="font-size:0.7rem">0 Out of Stock</span>';
        } else if (item.current_stock <= 5) {
          stockBadge = '<span class="badge badge-warning" style="font-size:0.7rem">' + item.current_stock + ' Low</span>';
        } else {
          stockBadge = '<span class="badge badge-success" style="font-size:0.7rem">' + item.current_stock + ' in stock</span>';
        }
        
        const sizeLabel = (item.size && item.size !== 'Standard') ? item.size : '';
        const brandLabel = item.brand ? ' <span style="color:#64748b;font-size:0.8rem">(' + escapeHtml(item.brand) + ')</span>' : '';
        
        html += `
          <div class="autocomplete-item" data-index="${index}">
            <div>
              <div style="font-weight:600;color:#0f172a">
                ${escapeHtml(item.name)}${brandLabel}
              </div>
              <div style="display:flex;gap:6px;align-items:center;margin-top:2px">
                <span class="sku-badge" style="font-size:0.7rem">${escapeHtml(item.sku)}</span>
                <span style="font-size:0.75rem;color:#64748b">${escapeHtml(item.category_name || 'Garment')}</span>
                ${sizeLabel ? `<span style="font-size:0.72rem;background:#ede9fe;color:#6d28d9;padding:1px 6px;border-radius:4px">Size: ${escapeHtml(sizeLabel)}</span>` : ''}
              </div>
            </div>
            <div style="text-align:right;flex-shrink:0">
              ${stockBadge}
              <div style="font-size:0.75rem;font-weight:600;color:#0f172a;margin-top:2px">₹${parseFloat(item.selling_price || 0).toFixed(2)}</div>
            </div>
          </div>
        `;
      });
      
      dropdown.innerHTML = html;
      dropdown.style.display = 'block';
    })
    .catch(err => {
      console.error('Search error:', err);
    });
}

// Event delegation on dropdown for mousedown and click
dropdown.addEventListener('mousedown', function(e) {
  const itemEl = e.target.closest('.autocomplete-item');
  if (!itemEl) return;
  e.preventDefault();
  const idx = parseInt(itemEl.dataset.index, 10);
  if (!isNaN(idx) && currentSearchResults[idx]) {
    selectProductByIndex(idx);
  }
});

dropdown.addEventListener('click', function(e) {
  const itemEl = e.target.closest('.autocomplete-item');
  if (!itemEl) return;
  const idx = parseInt(itemEl.dataset.index, 10);
  if (!isNaN(idx) && currentSearchResults[idx]) {
    selectProductByIndex(idx);
  }
});

// Select product by index in currentSearchResults
function selectProductByIndex(idx) {
  const product = currentSearchResults[idx];
  if (!product) return;
  
  selectedProduct = product;
  
  // Fill form inputs
  searchInput.value = product.name + (product.sku ? ' [' + product.sku + ']' : '');
  variantIdInput.value = product.id;
  clearBtn.style.display = 'block';
  
  // Dropdown disappears completely and clears
  dropdown.style.display = 'none';
  dropdown.innerHTML = '';
  currentSearchResults = [];
  searchInput.blur();
  
  // Render details box on the right
  renderProductBox(product);
}

// Render the details box on the right
function renderProductBox(p) {
  document.getElementById('productEmptyState').style.display = 'none';
  document.getElementById('productDetailsView').style.display = 'block';
  
  document.getElementById('dispProductName').textContent = p.name || 'Product';
  document.getElementById('dispBrand').textContent = p.brand ? p.brand : '';
  document.getElementById('dispCategory').textContent = p.category_name || 'Garment';
  document.getElementById('dispSize').textContent = 'Size: ' + (p.size || 'Standard');
  document.getElementById('dispSku').textContent = p.sku || '—';
  
  // Stock count & status
  const stock = parseInt(p.current_stock || 0);
  document.getElementById('dispStockCount').textContent = stock.toLocaleString();
  
  let statusHtml = '';
  if (stock === 0) {
    statusHtml = '<span class="badge badge-danger" style="font-size:0.85rem;padding:6px 12px">Out of Stock</span>';
  } else if (stock <= (p.min_stock_alert || 5)) {
    statusHtml = '<span class="badge badge-warning" style="font-size:0.85rem;padding:6px 12px">' + stock + ' Low Stock</span>';
  } else {
    statusHtml = '<span class="badge badge-success" style="font-size:0.85rem;padding:6px 12px">In Stock</span>';
  }
  document.getElementById('dispStockStatusBadge').innerHTML = statusHtml;
  
  // Pricing
  document.getElementById('dispSellingPrice').textContent = '₹' + parseFloat(p.selling_price || 0).toFixed(2);
  document.getElementById('dispCostPrice').textContent = '₹' + parseFloat(p.purchase_price_with_gst || 0).toFixed(2);
  
  // Update live calculation
  updateSimulation();
}

// Clear selected product
function clearSelectedProduct() {
  selectedProduct = null;
  currentSearchResults = [];
  searchInput.value = '';
  variantIdInput.value = '';
  clearBtn.style.display = 'none';
  dropdown.style.display = 'none';
  
  document.getElementById('productDetailsView').style.display = 'none';
  document.getElementById('productEmptyState').style.display = 'block';
  submitBtn.disabled = false;
}

// Update simulation calculation as user changes qty or type
function updateSimulation() {
  if (!selectedProduct) return;
  
  const currentStock = parseInt(selectedProduct.current_stock || 0);
  const type         = document.getElementById('adjustmentType').value;
  const qtyInput     = document.getElementById('adjustmentQty');
  let qty            = parseInt(qtyInput.value) || 0;
  if (qty < 1) qty   = 1;
  
  document.getElementById('calcCurrentStock').textContent = currentStock.toLocaleString() + ' units';
  
  const warningBox = document.getElementById('stockWarningBox');
  const calcNewStock = document.getElementById('calcNewStock');
  const calcAdjQty   = document.getElementById('calcAdjQuantity');
  
  if (type === 'add') {
    const newStock = currentStock + qty;
    calcAdjQty.textContent = '+' + qty + ' unit' + (qty > 1 ? 's' : '');
    calcAdjQty.style.color = '#059669';
    calcNewStock.textContent = newStock.toLocaleString() + ' units';
    calcNewStock.style.color = '#059669';
    warningBox.style.display = 'none';
    submitBtn.disabled = false;
  } else {
    // subtract
    const newStock = currentStock - qty;
    calcAdjQty.textContent = '-' + qty + ' unit' + (qty > 1 ? 's' : '');
    calcAdjQty.style.color = '#dc2626';
    
    if (newStock < 0) {
      calcNewStock.textContent = '0 units - Deficit ' + Math.abs(newStock);
      calcNewStock.style.color = '#dc2626';
      warningBox.style.display = 'block';
      submitBtn.disabled = true;
    } else {
      calcNewStock.textContent = newStock.toLocaleString() + ' unit' + (newStock !== 1 ? 's' : '');
      calcNewStock.style.color = newStock === 0 ? '#dc2626' : (newStock <= 5 ? '#d97706' : '#059669');
      warningBox.style.display = 'none';
      submitBtn.disabled = false;
    }
  }
}

// Quick reason helper
function setReason(text) {
  document.getElementById('adjustmentReason').value = text;
}

// Initial simulation if prefilled
<?php if ($prefillVariant): ?>
document.addEventListener('DOMContentLoaded', function() {
  updateSimulation();
});
<?php endif; ?>

function escapeHtml(text) {
  if (!text) return '';
  return String(text)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
}
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
