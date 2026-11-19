<?php
/**
 * Admin: Add Product (Boutique & Retail)
 * Clean, fast in-store product entry tailored for local tailoring boutique.
 */
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireAdmin();

$errors  = [];
$success = '';

// Default settings
$defSalesGst    = getSetting('sales_gst_pct', '5');
$defPurchaseGst = getSetting('purchase_gst_pct', '5');
$defMinStock    = getSetting('default_min_stock_alert', '5');

// Convert labels into short, safe 3-letter SKU segments for boutique tags.
function skuSegment($value, $fallback = 'BTQ', $length = 3) {
    $value = strtoupper(trim((string)$value));
    $value = preg_replace('/[^A-Z0-9]+/', '', $value);
    return $value === '' ? $fallback : substr($value, 0, $length);
}

function sizeSkuSegment($size) {
    $map = [
        'Standard'   => 'STD',
        'Free Size'  => 'FREE',
        'One Size'   => 'ONE',
        'X-Small'    => 'XS',
        'Small'      => 'S',
        'Medium'     => 'M',
        'Large'      => 'L',
        'X-Large'    => 'XL',
        'XX-Large'   => '2XL',
        '3X-Large'   => '3XL',
        '4X-Large'   => '4XL',
        '5X-Large'   => '5XL',
        'Size 28'    => '28',
        'Size 30'    => '30',
        'Size 32'    => '32',
        'Size 34'    => '34',
        'Size 36'    => '36',
        'Size 38'    => '38',
        'Size 40'    => '40',
        'Size 42'    => '42',
        'Size 44'    => '44',
        'Piece'      => 'PCS',
        'Set'        => 'SET',
        'Pair'       => 'PR',
        // Legacy fallback support
        'Meter'      => 'MTR',
        'Roll'       => 'ROLL',
        'Yard'       => 'YD',
        'Packet'     => 'PKT'
    ];
    return isset($map[$size]) ? $map[$size] : skuSegment($size, 'CUST', 4);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();

    // Product fields (clean: no brand, description, or status needed)
    $name        = sanitize($_POST['name'] ?? '');
    $categoryId  = (int)($_POST['category_id'] ?? 0);
    $subCatId    = (int)($_POST['subcategory_id'] ?? 0);

    // Variants (at least 1 required)
    $variants = $_POST['variants'] ?? [];

    if (empty($name)) $errors[] = 'Product name is required.';
    if (empty($variants)) $errors[] = 'At least one garment size/variant is required.';

    // Validate variants
    $allowedSizes = [
        'Standard', 'Free Size', 'One Size',
        'X-Small', 'Small', 'Medium', 'Large', 'X-Large', 'XX-Large', '3X-Large', '4X-Large', '5X-Large',
        'Size 28', 'Size 30', 'Size 32', 'Size 34', 'Size 36', 'Size 38', 'Size 40', 'Size 42', 'Size 44',
        'Piece', 'Set', 'Pair',
        'Custom',
        // Legacy fallback
        'Meter', 'Roll', 'Yard', 'Packet'
    ];
    foreach ($variants as $i => $v) {
        $sellPrice = (float)($v['selling_price'] ?? 0);
        if ($sellPrice <= 0) {
            $errors[] = "Variant #" . ($i+1) . ": Please enter a valid Selling Price / MRP.";
        }
        $postedSize = $v['size'] ?? 'Standard';
        if (!in_array($postedSize, $allowedSizes, true)) {
            $errors[] = "Variant #" . ($i+1) . ": Please select a valid size.";
        }
        if ($postedSize === 'Custom' && trim($v['custom_size'] ?? '') === '') {
            $errors[] = "Variant #" . ($i+1) . ": Enter the custom size.";
        }
    }

    // Validate selected category and subcategory
    if ($categoryId > 0) {
        $categoryCheck = db()->fetchOne("SELECT id FROM categories WHERE id = ? AND parent_id IS NULL AND status='active'", [$categoryId]);
        if (!$categoryCheck) $errors[] = 'Please select a valid category.';
    }
    if ($subCatId > 0) {
        $subCategoryCheck = db()->fetchOne("SELECT id FROM categories WHERE id = ? AND parent_id = ? AND status='active'", [$subCatId, $categoryId]);
        if (!$categoryId || !$subCategoryCheck) $errors[] = 'Please select a valid subcategory for the chosen category.';
    }

    if (empty($errors)) {
        try {
            db()->beginTransaction();

            // Handle image upload
            $imageName = null;
            if (!empty($_FILES['image']['name'])) {
                $uploaded = uploadImage($_FILES['image'], UPLOAD_PATH . 'products/', 'prod_');
                if ($uploaded) $imageName = $uploaded;
                else $errors[] = 'Image upload failed. Check file type and size.';
            }

            if (empty($errors)) {
                // Insert finished shop garment (always active)
                $productId = db()->insert(
                    "INSERT INTO products (category_id, subcategory_id, item_type, name, brand, description, image, status, created_by)
                     VALUES (?, ?, 'product', ?, NULL, NULL, ?, 'active', ?)",
                    [$categoryId ?: null, $subCatId ?: null, $name, $imageName, currentUserId()]
                );

                // Generate clean, short boutique SKU: e.g. SHT-P004-M, MEN-P012-L
                $categoryRow = $categoryId ? db()->fetchOne("SELECT name FROM categories WHERE id = ?", [$categoryId]) : null;
                $subCategoryRow = $subCatId ? db()->fetchOne("SELECT name FROM categories WHERE id = ?", [$subCatId]) : null;
                
                $catToken = '';
                if ($subCategoryRow && !empty($subCategoryRow['name'])) {
                    $catToken = skuSegment($subCategoryRow['name'], '', 3);
                }
                if (!$catToken && $categoryRow && !empty($categoryRow['name'])) {
                    $catToken = skuSegment($categoryRow['name'], '', 3);
                }
                if (!$catToken) {
                    $catToken = 'BTQ';
                }

                $prodCode = 'P' . str_pad((string)$productId, 3, '0', STR_PAD_LEFT);

                // Track sizes in this batch to keep SKUs clean and short
                $seenSizes = [];
                $variantNumber = 0;
                foreach ($variants as $v) {
                    $variantNumber++;
                    $selectedSize = $v['size'] ?? 'Standard';
                    $size = ($selectedSize === 'Custom')
                        ? sanitize($v['custom_size'] ?? '')
                        : sanitize($selectedSize);
                    if ($size === '') $size = 'Standard';

                    $sizeCode = sizeSkuSegment($size);
                    $seenSizes[$sizeCode] = ($seenSizes[$sizeCode] ?? 0) + 1;
                    $suffix = ($seenSizes[$sizeCode] > 1) ? '-' . $seenSizes[$sizeCode] : '';

                    $baseSku = $catToken . '-' . $prodCode . '-' . $sizeCode . $suffix;

                    // Ensure database uniqueness
                    $sku = $baseSku;
                    $exists = db()->fetchOne("SELECT id FROM product_variants WHERE sku = ?", [$sku]);
                    if ($exists) {
                        $sku = $catToken . '-' . $prodCode . '-' . $sizeCode . '-' . $variantNumber;
                    }

                    $sellPrice = (float)($v['selling_price'] ?? 0);
                    $initStock = (int)($v['initial_stock'] ?? 0);
                    $minAlert  = (int)($v['min_stock_alert'] ?? $defMinStock);

                    // Compute tax breakdown from single Selling Price / MRP
                    $salesGstPct = (float)$defSalesGst;
                    $sellingBeforeGst = round($sellPrice / (1 + ($salesGstPct / 100)), 2);
                    $salesGstAmount = round($sellPrice - $sellingBeforeGst, 2);

                    $variantId = db()->insert(
                        "INSERT INTO product_variants
                         (product_id, sku, size, purchase_price, purchase_gst_pct, purchase_gst_amount,
                          purchase_price_with_gst, profit_markup_pct, profit_amount, selling_price_before_gst,
                          sales_gst_pct, sales_gst_amount, selling_price, tag_price, current_stock, min_stock_alert)
                         VALUES (?,?,?,0.00,?,0.00,0.00,0.00,0.00,?,?,?,?,?,?,?)",
                        [
                            $productId, $sku, $size,
                            $defPurchaseGst,
                            $sellingBeforeGst, $salesGstPct, $salesGstAmount,
                            $sellPrice, $sellPrice,
                            $initStock, $minAlert
                        ]
                    );

                    // Record initial stock if provided
                    if ($initStock > 0) {
                        db()->insert(
                            "INSERT INTO stock_movements (variant_id, movement_type, qty_change, qty_before, qty_after, reference_id, reference_type, notes, created_by)
                             VALUES (?,?,?,?,?,?,?,?,?)",
                            [$variantId, 'purchase', $initStock, 0, $initStock, $productId, 'product', 'Initial stock on product creation', currentUserId()]
                        );
                    }
                }

                db()->commit();
                logActivity("Added product: $name (ID: $productId)", 'products', $productId, 'product');
                $_SESSION['flash_success'] = "Product '$name' added successfully.";
                header('Location: ' . APP_URL . '/admin/products/view.php?id=' . $productId);
                exit;
            }
        } catch (Exception $e) {
            db()->rollback();
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

$categories = db()->fetchAll("SELECT id, name FROM categories WHERE parent_id IS NULL AND status='active' ORDER BY name");
$pageTitle   = 'Add Product';
$activePage  = 'products';
$breadcrumb  = [['label' => 'Products', 'url' => APP_URL . '/admin/products/index.php'], ['label' => 'Add Product']];
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<script>window.APP_URL='<?= APP_URL ?>';</script>

<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title"><i class="fas fa-plus-circle text-primary" style="margin-right:10px"></i>Add New Product</h1>
    <p class="page-subtitle">Add ready outfits, garments &amp; stitched boutique products for customer billing</p>
  </div>
  <div class="page-header-right">
    <a href="<?= APP_URL ?>/admin/products/index.php" class="btn btn-light"><i class="fas fa-arrow-left"></i> Back</a>
  </div>
</div>

<?php foreach ($errors as $err): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= e($err) ?></div>
<?php endforeach; ?>

<form method="POST" enctype="multipart/form-data" id="productForm">
  <?= csrfField() ?>

  <div class="grid-2" style="align-items:stretch">
    <!-- Left column: Product Info -->
    <div style="display:flex;flex-direction:column">
      <div class="card" style="height:100%;display:flex;flex-direction:column;margin-bottom:0">
        <div class="card-header" style="min-height:57px;display:flex;align-items:center"><span class="card-title"><i class="fas fa-info-circle"></i> Product Details</span></div>
        <div class="card-body" style="flex:1;display:flex;flex-direction:column">
          <div class="form-group">
            <label class="form-label">Product Name <span class="required">*</span></label>
            <input type="text" name="name" class="form-control" required placeholder="e.g. Cotton Formal Shirt, School Uniform Pant..." value="<?= e($_POST['name'] ?? '') ?>" autofocus>
          </div>
          <div class="form-row cols-2">
            <div class="form-group">
              <label class="form-label">Category</label>
              <select name="category_id" class="form-select" id="catSelect" onchange="loadSubcats(this.value)">
                <option value="">Select Category</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= ($_POST['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Subcategory</label>
              <select name="subcategory_id" class="form-select" id="subcatSelect">
                <option value="">Select Subcategory</option>
              </select>
            </div>
          </div>
          <div class="form-group mb-0" style="flex:1;display:flex;flex-direction:column">
            <label class="form-label">Product Image <span class="text-muted" style="font-weight:400">(Optional - JPG, PNG, WebP)</span></label>
            <input type="file" name="image" class="form-control" accept="image/*" onchange="previewImage(this)">
            <div id="imagePreview" style="display:none;margin-top:12px;text-align:center">
              <img id="previewImg" style="max-height:160px;border-radius:10px;border:1px solid #e2e8f0;object-fit:cover" alt="Preview">
            </div>
          </div>
        </div>
        <div class="card-footer" style="min-height:49px;display:flex;align-items:center">
          <small class="text-muted"><i class="fas fa-info-circle"></i> Product identity for boutique billing &amp; inventory.</small>
        </div>
      </div>
    </div>

    <!-- Right column: Variants (Sizes & Pricing) -->
    <div style="display:flex;flex-direction:column">
      <div class="card" style="height:100%;display:flex;flex-direction:column;margin-bottom:0">
        <div class="card-header" style="min-height:57px;display:flex;align-items:center;justify-content:space-between">
          <span class="card-title"><i class="fas fa-layer-group"></i> Garment Sizes &amp; Pricing</span>
          <button type="button" class="btn btn-sm btn-primary" onclick="addVariantRow()">
            <i class="fas fa-plus"></i> Add Size
          </button>
        </div>
        <div class="card-body p-0" style="flex:1">
          <div id="variantsContainer">
            <!-- Variant rows inserted here -->
          </div>
        </div>
        <div class="card-footer" style="min-height:49px;display:flex;align-items:center">
          <small class="text-muted"><i class="fas fa-info-circle"></i> Each size has its own SKU code, price and stock tracking.</small>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex gap-3 justify-end mt-4">
    <a href="<?= APP_URL ?>/admin/products/index.php" class="btn btn-light">Cancel</a>
    <button type="submit" class="btn btn-primary btn-lg">
      <i class="fas fa-save"></i> Save Product
    </button>
  </div>
</form>

<script>
let variantCount = 0;

function addVariantRow(data) {
  variantCount++;
  const idx = variantCount;

  const div = document.createElement('div');
  div.id = 'variant-' + idx;
  div.style.cssText = 'border-bottom:1px solid #e2e8f0;padding:18px 20px;';

  div.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
      <strong style="font-size:0.88rem;color:#1e293b">Variant #${idx}</strong>
      ${idx > 1 ? '<button type="button" class="btn btn-xs btn-outline-danger" onclick="removeVariant('+idx+')" title="Remove this size"><i class="fas fa-trash"></i></button>' : ''}
    </div>
    <div class="form-row cols-2" style="margin-bottom:12px">
      <div class="form-group mb-0">
        <label class="form-label text-xs">SKU / Product Code</label>
        <input type="text" id="sku-preview-${idx}" class="form-control" readonly
               value="Generated automatically" style="background:#f1f5f9;color:#475569;font-weight:600">
        <small class="text-muted">Auto-generated compact code</small>
      </div>
      <div class="form-group mb-0">
        <label class="form-label text-xs">Garment Size / Unit <span class="required">*</span></label>
        <select name="variants[${idx}][size]" id="variant-size-${idx}" class="form-select"
                onchange="toggleCustomSize(${idx}); updateSkuPreviews()" required>
          <optgroup label="Garment Sizes (Alpha)">
            <option value="Standard" selected>Standard</option>
            <option value="Free Size">Free Size</option>
            <option value="X-Small">X-Small (XS)</option>
            <option value="Small">Small (S)</option>
            <option value="Medium">Medium (M)</option>
            <option value="Large">Large (L)</option>
            <option value="X-Large">X-Large (XL)</option>
            <option value="XX-Large">XX-Large (XXL)</option>
            <option value="3X-Large">3X-Large (3XL)</option>
          </optgroup>
          <optgroup label="Garment Fit Sizes (Collar / Waist / Chest)">
            <option value="Size 28">Size 28</option>
            <option value="Size 30">Size 30</option>
            <option value="Size 32">Size 32</option>
            <option value="Size 34">Size 34</option>
            <option value="Size 36">Size 36</option>
            <option value="Size 38">Size 38</option>
            <option value="Size 40">Size 40</option>
            <option value="Size 42">Size 42</option>
            <option value="Size 44">Size 44</option>
          </optgroup>
          <optgroup label="Retail Units">
            <option value="Piece">Piece (1 Pc)</option>
            <option value="Set">Set (Suit / Uniform Set)</option>
            <option value="Pair">Pair</option>
          </optgroup>
          <option value="Custom">Other / Custom Size...</option>
        </select>
        <input type="text" name="variants[${idx}][custom_size]" id="custom-size-${idx}"
               class="form-control mt-2" placeholder="Enter custom size, e.g. Kids 6-7 Yrs, 42 Slim Fit, Tailored"
               style="display:none" maxlength="40">
      </div>
    </div>
    <div class="form-row cols-3">
      <div class="form-group mb-0">
        <label class="form-label text-xs">Selling Price / MRP (₹) <span class="required">*</span></label>
        <input type="number" name="variants[${idx}][selling_price]" class="form-control" step="0.01" min="0" required
               placeholder="e.g. 750.00" value="${data?.selling_price||data?.tag_price||''}" style="font-weight:700;color:#2563eb;font-size:0.95rem">
        <small class="text-muted">Price billed to customer</small>
      </div>
      <div class="form-group mb-0">
        <label class="form-label text-xs">Initial Stock Qty</label>
        <input type="number" name="variants[${idx}][initial_stock]" class="form-control" min="0" value="${data?.initial_stock||0}">
        <small class="text-muted">Current items in shop</small>
      </div>
      <div class="form-group mb-0">
        <label class="form-label text-xs">Min Stock Alert</label>
        <input type="number" name="variants[${idx}][min_stock_alert]" class="form-control" min="0" value="<?= $defMinStock ?>">
        <small class="text-muted">Low stock warning</small>
      </div>
    </div>
  `;

  document.getElementById('variantsContainer').appendChild(div);
  updateSkuPreviews();
}

function removeVariant(idx) {
  const el = document.getElementById('variant-' + idx);
  if (el) el.remove();
}

function previewImage(input) {
  const preview = document.getElementById('imagePreview');
  const img     = document.getElementById('previewImg');
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = function(e) {
      img.src = e.target.result;
      preview.style.display = 'block';
    };
    reader.readAsDataURL(input.files[0]);
  } else {
    preview.style.display = 'none';
  }
}

function loadSubcats(catId) {
  const select = document.getElementById('subcatSelect');
  select.innerHTML = '<option value="">Loading...</option>';
  if (!catId) { select.innerHTML = '<option value="">Select Subcategory</option>'; return; }
  apiCall(window.APP_URL + '/api/get_subcategories.php', 'GET', { parent_id: catId }).then(function(res) {
    select.innerHTML = '<option value="">Select Subcategory</option>';
    if (res.success && res.data) {
      res.data.forEach(function(sub) {
        const opt = document.createElement('option');
        opt.value = sub.id; opt.textContent = sub.name;
        select.appendChild(opt);
      });
    }
    updateSkuPreviews();
  }).catch(function() { select.innerHTML = '<option value="">Select Subcategory</option>'; updateSkuPreviews(); });
}

function toggleCustomSize(idx) {
  const select = document.getElementById('variant-size-' + idx);
  const input = document.getElementById('custom-size-' + idx);
  if (!select || !input) return;
  const custom = select.value === 'Custom';
  input.style.display = custom ? 'block' : 'none';
  input.required = custom;
}

function skuPreviewToken(value, fallback, length) {
  const token = String(value || '').toUpperCase().replace(/[^A-Z0-9]/g, '').substring(0, length || 3);
  return token || fallback;
}

function updateSkuPreviews() {
  const cat = document.getElementById('catSelect');
  const sub = document.getElementById('subcatSelect');
  const catName = cat && cat.selectedIndex > 0 ? cat.options[cat.selectedIndex].text : '';
  const subName = sub && sub.selectedIndex > 0 ? sub.options[sub.selectedIndex].text : '';
  
  let code = '';
  if (subName) {
    code = skuPreviewToken(subName, '', 3);
  }
  if (!code && catName) {
    code = skuPreviewToken(catName, '', 3);
  }
  if (!code) {
    code = 'BTQ';
  }

  document.querySelectorAll('[id^="variant-size-"]').forEach(function(select) {
    const idx = select.id.replace('variant-size-', '');
    const customInput = document.getElementById('custom-size-' + idx);
    const sizeValue = select.value === 'Custom' ? ((customInput && customInput.value) || 'CUSTOM') : select.value;
    const map = {
      'Standard':'STD','Free Size':'FREE','One Size':'ONE',
      'X-Small':'XS','Small':'S','Medium':'M','Large':'L',
      'X-Large':'XL','XX-Large':'2XL','3X-Large':'3XL','4X-Large':'4XL','5X-Large':'5XL',
      'Size 28':'28','Size 30':'30','Size 32':'32','Size 34':'34','Size 36':'36',
      'Size 38':'38','Size 40':'40','Size 42':'42','Size 44':'44',
      'Piece':'PCS','Set':'SET','Pair':'PR'
    };
    const sizeCode = map[sizeValue] || skuPreviewToken(sizeValue, 'CUST', 4);
    const preview = document.getElementById('sku-preview-' + idx);
    if (preview) preview.value = code + '-P[ID]-' + sizeCode;
  });
}

// Add first variant by default
addVariantRow();
document.getElementById('catSelect').addEventListener('change', updateSkuPreviews);
document.getElementById('subcatSelect').addEventListener('change', updateSkuPreviews);
document.getElementById('variantsContainer').addEventListener('input', function(event) {
  if (event.target && event.target.id.indexOf('custom-size-') === 0) updateSkuPreviews();
});
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
