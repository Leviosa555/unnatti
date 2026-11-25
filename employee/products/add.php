<?php
/**
 * Admin: Add Product (Boutique & Retail)
 * Supports boutique-produced garments and wholesale-purchased items with full pricing calculation.
 */
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireLogin();

// Redirect admin to admin add product
if (isAdmin()) {
    header('Location: ' . APP_URL . '/admin/products/add.php');
    exit;
}

$activeProfile = getActiveProfile();
if ($activeProfile === 'fashion_kingdom' && (!isset($_GET['profile']) || $_GET['profile'] !== 'unnatti')) {
    header('Location: ' . APP_URL . '/admin/purchases/materials.php');
    exit;
}

$errors  = [];
$success = '';

// Default pricing settings
$defPurchaseGst = getSetting('purchase_gst_pct', '5');
$defSalesGst    = getSetting('sales_gst_pct', '5');
$defProfit      = getSetting('default_profit_markup_pct', '65');
$defMinStock    = getSetting('default_min_stock_alert', '5');

// Convert labels into short, safe 3-letter SKU segments for boutique tags.
function skuSegment($value, $fallback = 'BTQ', $length = 3) {
    $value = strtoupper(trim((string)$value));
    $value = preg_replace('/[^A-Z0-9]+/', '', $value);
    return $value === '' ? $fallback : substr($value, 0, $length);
}

function getCategoryCode($name) {
    $clean = strtoupper(trim((string)$name));
    if (strpos($clean, 'CLOTH') !== false) return 'CLO';
    if (strpos($clean, 'FOOT') !== false || strpos($clean, 'SHOE') !== false) return 'FTW';
    if (strpos($clean, 'ACCESS') !== false) return 'ACC';
    if (strpos($clean, 'FABRIC') !== false) return 'FAB';
    return skuSegment($clean, 'CAT', 3);
}

function getSubcategoryCode($name) {
    $clean = strtoupper(trim((string)$name));
    if (strpos($clean, 'MEN') !== false && strpos($clean, 'WOMEN') === false) return 'MEN';
    if (strpos($clean, 'WOMEN') !== false) return 'WOM';
    if (strpos($clean, 'KID') !== false || strpos($clean, 'BOY') !== false || strpos($clean, 'GIRL') !== false) return 'KID';
    if (strpos($clean, 'BAG') !== false) return 'BAG';
    if (strpos($clean, 'BELT') !== false) return 'BLT';
    if (strpos($clean, 'SHIRT') !== false) return 'SHT';
    if (strpos($clean, 'PANT') !== false || strpos($clean, 'TROUSER') !== false) return 'PNT';
    if (strpos($clean, 'KURTA') !== false || strpos($clean, 'KURTI') !== false) return 'KRT';
    if (strpos($clean, 'SAREE') !== false) return 'SAR';
    if (strpos($clean, 'DRESS') !== false) return 'DRS';
    return skuSegment($clean, 'SUB', 3);
}

function getItemTypeCode($name) {
    if (empty($name)) return '';
    $clean = strtoupper(trim((string)$name));
    if (strpos($clean, 'TROUSER') !== false) return 'TRS';
    if (strpos($clean, 'PANT') !== false) return 'PNT';
    if (strpos($clean, 'TSHIRT') !== false || strpos($clean, 'T-SHIRT') !== false) return 'TSH';
    if (strpos($clean, 'SHIRT') !== false) return 'SHT';
    if (strpos($clean, 'JEAN') !== false) return 'JNS';
    if (strpos($clean, 'KURTA') !== false || strpos($clean, 'KURTI') !== false) return 'KRT';
    if (strpos($clean, 'SAREE') !== false) return 'SAR';
    if (strpos($clean, 'DRESS') !== false || strpos($clean, 'FROCK') !== false || strpos($clean, 'GOWN') !== false) return 'DRS';
    if (strpos($clean, 'LEHENGA') !== false) return 'LHG';
    if (strpos($clean, 'BLOUSE') !== false) return 'BLS';
    if (strpos($clean, 'BLAZER') !== false || strpos($clean, 'SUIT') !== false) return 'SUT';
    if (strpos($clean, 'SHORT') !== false) return 'SHR';
    if (strpos($clean, 'SHOE') !== false) return 'SHO';
    if (strpos($clean, 'SANDAL') !== false) return 'SND';
    if (strpos($clean, 'SLIPPER') !== false || strpos($clean, 'FLIP') !== false) return 'SLP';
    if (strpos($clean, 'BOOT') !== false) return 'BOT';
    if (strpos($clean, 'HEEL') !== false) return 'HEL';
    if (strpos($clean, 'BAG') !== false || strpos($clean, 'PURSE') !== false) return 'BAG';
    if (strpos($clean, 'BELT') !== false) return 'BLT';
    return skuSegment($clean, 'ITM', 3);
}

function sizeSkuSegment($size) {
    $map = [
        'Small'      => 'S',
        'Medium'     => 'M',
        'Large'      => 'L',
        'X-Large'    => 'XL',
        'XX-Large'   => '2XL',
        'Size 28'    => '28',
        'Size 30'    => '30',
        'Size 32'    => '32',
        'Size 34'    => '34',
        'Size 36'    => '36',
        'Size 38'    => '38',
        'Size 40'    => '40',
        'Size 42'    => '42',
        'Size 44'    => '44',
        'Size 6'     => '6',
        'Size 7'     => '7',
        'Size 8'     => '8',
        'Size 9'     => '9',
        'Size 10'    => '10',
        'Size 11'    => '11',
        'Size 12'    => '12',
        'UK 6'       => 'UK6',
        'UK 7'       => 'UK7',
        'UK 8'       => 'UK8',
        'UK 9'       => 'UK9',
        'UK 10'      => 'UK10',
        'UK 11'      => 'UK11',
        'UK 12'      => 'UK12',
        'Free Size'  => 'FREE',
        'Standard'   => 'STD',
        'One Size'   => 'ONE'
    ];
    return isset($map[$size]) ? $map[$size] : skuSegment($size, 'CUST', 4);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();

    // Product fields (clean: no brand or description needed)
    $name        = sanitize($_POST['name'] ?? '');
    $categoryId  = (int)($_POST['category_id'] ?? 0);
    $subCatId    = (int)($_POST['subcategory_id'] ?? 0);
    $itemType    = sanitize($_POST['item_type'] ?? '');
    if ($itemType === 'Custom') {
        $itemType = sanitize($_POST['custom_item_type'] ?? '');
    }
    $status      = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';

    // Variants (at least 1 required)
    $variants = $_POST['variants'] ?? [];

    if (empty($name)) $errors[] = 'Product name is required.';
    if (empty($variants)) $errors[] = 'At least one garment size/variant is required.';

    // Validate variants
    $allowedSizes = [
        'Small', 'Medium', 'Large', 'X-Large', 'XX-Large',
        'Size 28', 'Size 30', 'Size 32', 'Size 34', 'Size 36', 'Size 38', 'Size 40', 'Size 42', 'Size 44',
        'Size 6', 'Size 7', 'Size 8', 'Size 9', 'Size 10', 'Size 11', 'Size 12',
        'UK 6', 'UK 7', 'UK 8', 'UK 9', 'UK 10', 'UK 11', 'UK 12',
        'Free Size', 'Standard', 'One Size',
        'Custom'
    ];
    foreach ($variants as $i => $v) {
        if (!isset($v['purchase_price']) || !is_numeric($v['purchase_price']) || (float)$v['purchase_price'] < 0) {
            $errors[] = "Variant #" . ($i+1) . ": Purchase price must be 0 or greater.";
        }
        $postedSize = $v['size'] ?? 'Medium';
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
                // Insert product (Finished shop garment)
                $productId = db()->insert(
                    "INSERT INTO products (category_id, subcategory_id, item_type, name, brand, description, image, status, created_by)
                     VALUES (?, ?, 'product', ?, ?, NULL, ?, ?, ?)",
                    [$categoryId ?: null, $subCatId ?: null, $name, $itemType ?: null, $imageName, $status, currentUserId()]
                );

                // Generate clean, standard boutique SKU: {CATEGORY}-{SUBCATEGORY}-{ITEM}-{ID}-{SIZE}
                $categoryRow = $categoryId ? db()->fetchOne("SELECT name FROM categories WHERE id = ?", [$categoryId]) : null;
                $subCategoryRow = $subCatId ? db()->fetchOne("SELECT name FROM categories WHERE id = ?", [$subCatId]) : null;
                
                $catPart = ($categoryRow && !empty($categoryRow['name'])) ? getCategoryCode($categoryRow['name']) : '';
                $subPart = ($subCategoryRow && !empty($subCategoryRow['name'])) ? getSubcategoryCode($subCategoryRow['name']) : '';
                $itemPart = !empty($itemType) ? getItemTypeCode($itemType) : '';

                $prefixParts = array_filter([$catPart, $subPart, $itemPart]);
                $skuPrefix = !empty($prefixParts) ? implode('-', $prefixParts) : 'BTQ';

                // Clean 3-digit product code (no letter P)
                $prodCode = str_pad((string)$productId, 3, '0', STR_PAD_LEFT);

                // Track sizes in this batch to keep SKUs clean and short
                $seenSizes = [];
                $variantNumber = 0;
                foreach ($variants as $v) {
                    $variantNumber++;
                    $selectedSize = $v['size'] ?? 'Medium';
                    $size = ($selectedSize === 'Custom')
                        ? sanitize($v['custom_size'] ?? '')
                        : sanitize($selectedSize);
                    if ($size === '') $size = 'Medium';

                    $sizeCode = sizeSkuSegment($size);
                    $seenSizes[$sizeCode] = ($seenSizes[$sizeCode] ?? 0) + 1;
                    $suffix = ($seenSizes[$sizeCode] > 1) ? '-' . $seenSizes[$sizeCode] : '';

                    $baseSku = $skuPrefix . '-' . $prodCode . '-' . $sizeCode . $suffix;

                    // Ensure database uniqueness
                    $sku = $baseSku;
                    $exists = db()->fetchOne("SELECT id FROM product_variants WHERE sku = ?", [$sku]);
                    if ($exists) {
                        $sku = $skuPrefix . '-' . $prodCode . '-' . $sizeCode . '-' . $variantNumber;
                    }

                    $purchasePrice   = (float)($v['purchase_price'] ?? 0);
                    $purchaseGstPct  = (float)($v['purchase_gst_pct'] ?? $defPurchaseGst);
                    $profitMarkupPct = (float)($v['profit_markup_pct'] ?? $defProfit);
                    $salesGstPct     = (float)($v['sales_gst_pct'] ?? $defSalesGst);
                    $tagPrice        = (float)($v['tag_price'] ?? 0);
                    $initStock       = (int)($v['initial_stock'] ?? 0);
                    $minAlert        = (int)($v['min_stock_alert'] ?? $defMinStock);

                    // Compute complete pricing breakdown
                    $calc = calculatePricing($purchasePrice, $purchaseGstPct, $profitMarkupPct, $salesGstPct);
                    if ($tagPrice <= 0) {
                        $tagPrice = $calc['selling_price'];
                    }
                    $sellingPrice = $tagPrice;

                    // If selling price was manually adjusted, recalculate taxes and profit
                    if ($purchasePrice > 0 && abs($sellingPrice - $calc['selling_price']) > 0.01) {
                        $sellingBeforeGst     = (float)round($sellingPrice / (1 + ($salesGstPct / 100)));
                        $salesGstAmount       = (float)round($sellingPrice - $sellingBeforeGst);
                        $purchaseGstAmount    = $calc['purchase_gst_amount'];
                        $purchasePriceWithGst = $calc['purchase_price_with_gst'];
                        $profitAmount         = (float)round($sellingBeforeGst - $purchasePriceWithGst);
                        $profitMarkupPct      = ($purchasePriceWithGst > 0)
                            ? round(($profitAmount / $purchasePriceWithGst) * 100, 2)
                            : 0;
                    } else {
                        $purchaseGstAmount    = $calc['purchase_gst_amount'];
                        $purchasePriceWithGst = $calc['purchase_price_with_gst'];
                        $profitAmount         = $calc['profit_amount'];
                        $sellingBeforeGst     = $calc['selling_price_before_gst'];
                        $salesGstAmount       = $calc['sales_gst_amount'];
                    }

                    $variantId = db()->insert(
                        "INSERT INTO product_variants
                         (product_id, sku, size, purchase_price, purchase_gst_pct, purchase_gst_amount,
                          purchase_price_with_gst, profit_markup_pct, profit_amount, selling_price_before_gst,
                          sales_gst_pct, sales_gst_amount, selling_price, tag_price, current_stock, min_stock_alert, status)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                        [
                            $productId, $sku, $size,
                            $purchasePrice, $purchaseGstPct, $purchaseGstAmount,
                            $purchasePriceWithGst, $profitMarkupPct, $profitAmount, $sellingBeforeGst,
                            $salesGstPct, $salesGstAmount, $sellingPrice, $tagPrice,
                            $initStock, $minAlert, $status
                        ]
                    );

                    // Record initial stock movement if provided
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
                header('Location: ' . APP_URL . '/employee/products/index.php');
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
$breadcrumb  = [['label' => 'Products', 'url' => APP_URL . '/employee/products/index.php'], ['label' => 'Add Product']];
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<script>window.APP_URL='<?= APP_URL ?>';</script>

<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title"><i class="fas fa-plus-circle text-primary" style="margin-right:10px"></i>Add New Product</h1>
    <p class="page-subtitle">Add ready outfits, garments &amp; boutique products with purchase &amp; pricing rates</p>
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

  <div class="grid-2" style="align-items: stretch;">
    <!-- Left column: Product Details & Default Pricing Rates -->
    <div style="display: flex; flex-direction: column;">
      <div class="card" style="margin-bottom: 20px;">
        <div class="card-header" style="min-height: 57px; display: flex; align-items: center;"><span class="card-title"><i class="fas fa-info-circle text-primary"></i> Product Details</span></div>
        <div class="card-body">
          <div class="form-group">
            <label class="form-label">Product Name <span class="required">*</span></label>
            <input type="text" name="name" class="form-control" required placeholder="e.g. Cotton T-Shirt, Silk Kurta" value="<?= e($_POST['name'] ?? '') ?>" autofocus>
          </div>
          <div class="form-row cols-3">
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
              <select name="subcategory_id" class="form-select" id="subcatSelect" onchange="onSubcategoryChange()">
                <option value="">Select Subcategory</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Garment / Item Type</label>
              <select name="item_type" class="form-select" id="itemTypeSelect" onchange="onItemTypeChange()">
                <option value="">Select Type</option>
              </select>
              <input type="text" name="custom_item_type" id="customItemTypeInput" class="form-control mt-2" placeholder="e.g. Waistcoat, Shrug, Shawl" style="display:none;" oninput="onItemTypeChange()">
            </div>
          </div>
          <div class="form-group mb-0">
            <label class="form-label">Product Image <span class="text-muted" style="font-weight:400">(Optional - JPG, PNG, WebP)</span></label>
            <input type="file" name="image" class="form-control" accept="image/*" onchange="previewImage(this)">
            <div id="imagePreview" style="display:none;margin-top:12px;text-align:center">
              <img id="previewImg" style="max-height:160px;border-radius:10px;border:1px solid #e2e8f0;object-fit:cover" alt="Preview">
            </div>
          </div>
        </div>
        <div class="card-footer" style="min-height: 57px; display: flex; align-items: center;">
          <small class="text-muted"><i class="fas fa-info-circle"></i> Product identity for boutique billing &amp; inventory.</small>
        </div>
      </div>

      <!-- Default Pricing Rates -->
      <div class="card" style="flex: 1; display: flex; flex-direction: column; margin-bottom: 0;">
        <div class="card-header" style="min-height: 57px; display: flex; align-items: center;"><span class="card-title"><i class="fas fa-calculator text-primary"></i> Default Pricing Rates</span></div>
        <div class="card-body" style="flex: 1; display: flex; flex-direction: column; justify-content: space-between;">
          <div>
            <p class="text-xs text-muted mb-3">Set store default markup &amp; GST rates to quickly apply across all variant sizes.</p>
            <div class="form-row cols-3">
              <div class="form-group mb-0">
                <label class="form-label text-xs">Purchase GST %</label>
                <input type="number" id="defPurchaseGst" value="<?= $defPurchaseGst ?>" class="form-control" step="0.01" oninput="applyDefaultRates(true)">
              </div>
              <div class="form-group mb-0">
                <label class="form-label text-xs">Profit Markup %</label>
                <input type="number" id="defProfit" value="<?= $defProfit ?>" class="form-control" step="0.01" oninput="applyDefaultRates(true)">
              </div>
              <div class="form-group mb-0">
                <label class="form-label text-xs">Sales GST %</label>
                <input type="number" id="defSalesGst" value="<?= $defSalesGst ?>" class="form-control" step="0.01" oninput="applyDefaultRates(true)">
              </div>
            </div>
          </div>
          <div class="mt-3">
            <button type="button" class="btn btn-sm btn-outline-primary w-100" onclick="applyDefaultRates()">
              <i class="fas fa-sync-alt"></i> Apply These Rates to All Variants
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Right column: Variants -->
    <div style="display: flex; flex-direction: column;">
      <div class="card" style="height: 100%; display: flex; flex-direction: column; margin-bottom: 0;">
        <div class="card-header" style="min-height: 57px; display: flex; justify-content: space-between; align-items: center;">
          <span class="card-title"><i class="fas fa-layer-group text-primary"></i> Product Variants (Sizes)</span>
          <button type="button" class="btn btn-sm btn-primary" onclick="addVariantRow()">
            <i class="fas fa-plus"></i> Add Size
          </button>
        </div>
        <div class="card-body p-0" style="flex: 1;">
          <div id="variantsContainer">
            <!-- Variant rows inserted here -->
          </div>
        </div>
        <div class="card-footer" style="min-height: 57px; display: flex; align-items: center;">
          <small class="text-muted"><i class="fas fa-info-circle"></i> Each size is a separate variant with its own SKU, cost pricing, and stock quantity.</small>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex gap-3 justify-end mt-4">
    <a href="<?= APP_URL ?>/employee/products/index.php" class="btn btn-light">Cancel</a>
    <button type="submit" class="btn btn-primary btn-lg">
      <i class="fas fa-save"></i> Save Product
    </button>
  </div>
</form>

<script>
let variantCount = 0;

const ITEM_TYPES_DATA = {
  "Men's Clothing": [
    { name: "Trouser", code: "TRS", defaultSize: "Size 32" },
    { name: "Shirt", code: "SHT", defaultSize: "Medium" },
    { name: "T-Shirt", code: "TSH", defaultSize: "Medium" },
    { name: "Jeans", code: "JNS", defaultSize: "Size 32" },
    { name: "Kurta", code: "KRT", defaultSize: "Medium" },
    { name: "Blazer / Suit", code: "SUT", defaultSize: "Size 38" },
    { name: "Shorts", code: "SHR", defaultSize: "Size 32" }
  ],
  "Women's Clothing": [
    { name: "Saree", code: "SAR", defaultSize: "Free Size" },
    { name: "Kurti / Suit", code: "KRT", defaultSize: "Medium" },
    { name: "Dress", code: "DRS", defaultSize: "Medium" },
    { name: "Top / T-Shirt", code: "TSH", defaultSize: "Medium" },
    { name: "Jeans / Trouser", code: "TRS", defaultSize: "Size 30" },
    { name: "Lehenga", code: "LHG", defaultSize: "Free Size" },
    { name: "Skirt / Shorts", code: "SHR", defaultSize: "Size 30" }
  ],
  "Kids' Clothing": [
    { name: "T-Shirt / Top", code: "TSH", defaultSize: "Medium" },
    { name: "Shirt", code: "SHT", defaultSize: "Medium" },
    { name: "Trouser / Pant", code: "TRS", defaultSize: "Size 28" },
    { name: "Shorts", code: "SHR", defaultSize: "Size 28" },
    { name: "Frock / Dress", code: "DRS", defaultSize: "Medium" },
    { name: "Kurta / Ethnic", code: "KRT", defaultSize: "Medium" }
  ],
  "Men's Footwear": [
    { name: "Formal Shoes", code: "SHO", defaultSize: "Size 8" },
    { name: "Casual Shoes / Sneakers", code: "SHO", defaultSize: "Size 8" },
    { name: "Sandals", code: "SND", defaultSize: "Size 8" },
    { name: "Slippers", code: "SLP", defaultSize: "Size 8" },
    { name: "Boots", code: "BOT", defaultSize: "Size 8" }
  ],
  "Women's Footwear": [
    { name: "Heels", code: "HEL", defaultSize: "Size 7" },
    { name: "Flats / Sandals", code: "SND", defaultSize: "Size 7" },
    { name: "Shoes / Sneakers", code: "SHO", defaultSize: "Size 7" },
    { name: "Slippers", code: "SLP", defaultSize: "Size 7" },
    { name: "Boots", code: "BOT", defaultSize: "Size 7" }
  ],
  "Bags": [
    { name: "Handbag", code: "BAG", defaultSize: "Standard" },
    { name: "Backpack", code: "BAG", defaultSize: "Standard" },
    { name: "Tote Bag", code: "BAG", defaultSize: "Standard" },
    { name: "Sling Bag", code: "BAG", defaultSize: "Standard" },
    { name: "Clutch / Wallet", code: "BAG", defaultSize: "Standard" }
  ],
  "Belts": [
    { name: "Leather Belt", code: "BLT", defaultSize: "Size 32" },
    { name: "Casual Belt", code: "BLT", defaultSize: "Size 32" },
    { name: "Formal Belt", code: "BLT", defaultSize: "Size 32" }
  ]
};

function getItemTypesFor(subcatName, catName) {
  if (subcatName && ITEM_TYPES_DATA[subcatName]) {
    return ITEM_TYPES_DATA[subcatName];
  }
  const s = (subcatName || '').toLowerCase();
  const c = (catName || '').toLowerCase();

  if (s.includes('men') && !s.includes('women') && (s.includes('cloth') || c.includes('cloth'))) {
    return ITEM_TYPES_DATA["Men's Clothing"];
  }
  if (s.includes('women') && (s.includes('cloth') || c.includes('cloth'))) {
    return ITEM_TYPES_DATA["Women's Clothing"];
  }
  if ((s.includes('kid') || s.includes('boy') || s.includes('girl')) && (s.includes('cloth') || c.includes('cloth'))) {
    return ITEM_TYPES_DATA["Kids' Clothing"];
  }
  if (s.includes('men') && (s.includes('foot') || s.includes('shoe') || c.includes('foot'))) {
    return ITEM_TYPES_DATA["Men's Footwear"];
  }
  if (s.includes('women') && (s.includes('foot') || s.includes('shoe') || c.includes('foot'))) {
    return ITEM_TYPES_DATA["Women's Footwear"];
  }
  if (s.includes('bag') || c.includes('bag')) {
    return ITEM_TYPES_DATA["Bags"];
  }
  if (s.includes('belt') || c.includes('belt')) {
    return ITEM_TYPES_DATA["Belts"];
  }
  if (c.includes('foot') || c.includes('shoe')) {
    return ITEM_TYPES_DATA["Men's Footwear"];
  }
  if (c.includes('cloth') || c.includes('apparel')) {
    return ITEM_TYPES_DATA["Men's Clothing"];
  }
  return [
    { name: "Shirt", code: "SHT", defaultSize: "Medium" },
    { name: "T-Shirt", code: "TSH", defaultSize: "Medium" },
    { name: "Trouser", code: "TRS", defaultSize: "Size 32" },
    { name: "Jeans", code: "JNS", defaultSize: "Size 32" },
    { name: "Dress", code: "DRS", defaultSize: "Medium" },
    { name: "Kurta", code: "KRT", defaultSize: "Medium" },
    { name: "Standard Item", code: "ITM", defaultSize: "Standard" }
  ];
}

function onSubcategoryChange(preselectItemType = null) {
  const cat = document.getElementById('catSelect');
  const sub = document.getElementById('subcatSelect');
  const itm = document.getElementById('itemTypeSelect');
  const customInput = document.getElementById('customItemTypeInput');
  if (customInput) customInput.style.display = 'none';

  const catName = cat && cat.selectedIndex > 0 ? cat.options[cat.selectedIndex].text : '';
  const subName = sub && sub.selectedIndex > 0 ? sub.options[sub.selectedIndex].text : '';

  itm.innerHTML = '<option value="">Select Type</option>';
  if (!subName && !catName) {
    onItemTypeChange();
    return;
  }

  const items = getItemTypesFor(subName, catName);
  let matched = false;
  items.forEach(function(item) {
    const opt = document.createElement('option');
    opt.value = item.name;
    opt.textContent = item.name;
    opt.setAttribute('data-code', item.code);
    opt.setAttribute('data-default-size', item.defaultSize || '');
    if (preselectItemType && item.name.toLowerCase() === preselectItemType.toLowerCase()) {
      opt.selected = true;
      matched = true;
    }
    itm.appendChild(opt);
  });

  const custOpt = document.createElement('option');
  custOpt.value = 'Custom';
  custOpt.textContent = 'Other / Custom Type...';
  if (preselectItemType && !matched && preselectItemType !== '') {
    custOpt.selected = true;
    if (customInput) {
      customInput.value = preselectItemType;
      customInput.style.display = 'block';
    }
  }
  itm.appendChild(custOpt);

  if (!preselectItemType && items.length > 0) {
    itm.selectedIndex = 1;
  }

  onItemTypeChange();
}

function onItemTypeChange() {
  const itm = document.getElementById('itemTypeSelect');
  const customInput = document.getElementById('customItemTypeInput');
  const isCustom = itm && itm.value === 'Custom';
  if (customInput) {
    customInput.style.display = isCustom ? 'block' : 'none';
    customInput.required = isCustom;
  }

  refreshAllVariantSizes();
  updateSkuPreviews();
}

function getSizeOptionsHtml(catName, subcatName, itemTypeName, selectedVal) {
  catName = (catName || '').toLowerCase();
  subcatName = (subcatName || '').toLowerCase();
  itemTypeName = (itemTypeName || '').toLowerCase();

  const isFootwear = catName.includes('foot') || catName.includes('shoe') ||
                     subcatName.includes('foot') || subcatName.includes('shoe') ||
                     itemTypeName.includes('shoe') || itemTypeName.includes('sneaker') ||
                     itemTypeName.includes('sandal') || itemTypeName.includes('slipper') ||
                     itemTypeName.includes('boot') || itemTypeName.includes('heel') || itemTypeName.includes('flat');

  const isWaist = itemTypeName.includes('trouser') || itemTypeName.includes('pant') ||
                  itemTypeName.includes('jean') || itemTypeName.includes('short') ||
                  itemTypeName.includes('belt');

  const isFreeSize = itemTypeName.includes('saree') || itemTypeName.includes('lehenga') ||
                     itemTypeName.includes('bag') || itemTypeName.includes('backpack') ||
                     itemTypeName.includes('tote') || itemTypeName.includes('clutch') ||
                     itemTypeName.includes('wallet') || itemTypeName.includes('dupatta') ||
                     itemTypeName.includes('shawl');

  let groups = [];

  if (isFootwear) {
    groups.push({
      label: "Footwear Sizes (Numbers)",
      options: ['Size 6', 'Size 7', 'Size 8', 'Size 9', 'Size 10', 'Size 11', 'Size 12'],
      default: (subcatName.includes('women') || itemTypeName.includes('heel')) ? 'Size 7' : 'Size 8'
    });
    groups.push({
      label: "UK Footwear Sizes",
      options: ['UK 6', 'UK 7', 'UK 8', 'UK 9', 'UK 10', 'UK 11', 'UK 12'],
      default: 'UK 8'
    });
  } else if (isWaist) {
    groups.push({
      label: "Garment Fit Sizes (Waist Numbers)",
      options: ['Size 28', 'Size 30', 'Size 32', 'Size 34', 'Size 36', 'Size 38', 'Size 40', 'Size 42', 'Size 44'],
      default: subcatName.includes('women') ? 'Size 30' : 'Size 32'
    });
    groups.push({
      label: "Garment Sizes (Alpha)",
      options: ['Small', 'Medium', 'Large', 'X-Large', 'XX-Large'],
      default: 'Medium'
    });
  } else if (isFreeSize) {
    groups.push({
      label: "Standard / Free Sizes",
      options: ['Free Size', 'Standard', 'One Size'],
      default: (itemTypeName.includes('bag') || itemTypeName.includes('backpack') || itemTypeName.includes('tote') || itemTypeName.includes('clutch')) ? 'Standard' : 'Free Size'
    });
    groups.push({
      label: "Garment Sizes (Alpha)",
      options: ['Small', 'Medium', 'Large', 'X-Large', 'XX-Large'],
      default: 'Medium'
    });
  } else {
    // Upper wear, shirts, t-shirts, kurtas, dresses, etc.
    groups.push({
      label: "Garment Sizes (Alpha)",
      options: ['Small', 'Medium', 'Large', 'X-Large', 'XX-Large'],
      default: 'Medium'
    });
    groups.push({
      label: "Garment Fit Sizes (Numbers)",
      options: ['Size 28', 'Size 30', 'Size 32', 'Size 34', 'Size 36', 'Size 38', 'Size 40', 'Size 42', 'Size 44'],
      default: 'Size 32'
    });
  }

  // Check if selectedVal matches any group option
  let allOptions = [];
  groups.forEach(function(g) { allOptions = allOptions.concat(g.options); });
  const isCustom = selectedVal === 'Custom';
  const effectiveVal = (selectedVal && allOptions.includes(selectedVal))
    ? selectedVal
    : (groups[0] ? groups[0].default : 'Medium');

  let html = '';
  groups.forEach(function(g) {
    html += '<optgroup label="' + g.label + '">';
    g.options.forEach(function(opt) {
      const isSel = (!isCustom && opt === effectiveVal) ? ' selected' : '';
      html += '<option value="' + opt + '"' + isSel + '>' + opt + '</option>';
    });
    html += '</optgroup>';
  });
  html += '<option value="Custom"' + (isCustom ? ' selected' : '') + '>Other / Custom Size...</option>';
  return html;
}

function refreshAllVariantSizes() {
  const cat = document.getElementById('catSelect');
  const sub = document.getElementById('subcatSelect');
  const itm = document.getElementById('itemTypeSelect');
  const custItm = document.getElementById('customItemTypeInput');

  const catName = cat && cat.selectedIndex > 0 ? cat.options[cat.selectedIndex].text : '';
  const subName = sub && sub.selectedIndex > 0 ? sub.options[sub.selectedIndex].text : '';
  let itmName = itm && itm.selectedIndex > 0 ? itm.options[itm.selectedIndex].value : '';
  if (itmName === 'Custom' && custItm && custItm.value.trim()) {
    itmName = custItm.value.trim();
  }

  document.querySelectorAll('[id^="variant-size-"]').forEach(function(select) {
    const currentVal = select.value;
    const idx = select.id.replace('variant-size-', '');
    select.innerHTML = getSizeOptionsHtml(catName, subName, itmName, currentVal);
    toggleCustomSize(idx);
  });
  updateSkuPreviews();
}

function calculatePricing(purchasePrice, purchaseGstPct, profitMarkupPct, salesGstPct) {
  const pgstAmt = Math.round(purchasePrice * (purchaseGstPct / 100));
  const ppWithGst = Math.round(purchasePrice + pgstAmt);
  const profAmt = Math.round(ppWithGst * (profitMarkupPct / 100));
  const spBeforeGst = Math.round(ppWithGst + profAmt);
  const sgstAmt = Math.round(spBeforeGst * (salesGstPct / 100));
  const sellingPrice = Math.round(spBeforeGst + sgstAmt);
  return {
    purchaseGstAmount: pgstAmt,
    purchasePriceWithGst: ppWithGst,
    profitAmount: profAmt,
    sellingPriceBeforeGst: spBeforeGst,
    salesGstAmount: sgstAmt,
    sellingPrice: sellingPrice
  };
}

function calcVariant(idx) {
  const ppInput = document.querySelector(`[name="variants[${idx}][purchase_price]"]`);
  const tagInput = document.querySelector(`[name="variants[${idx}][tag_price]"]`);
  const pp = parseFloat(ppInput?.value) || 0;
  const pgst = parseFloat(document.querySelector(`[name="variants[${idx}][purchase_gst_pct]"]`)?.value) || 0;
  const prof = parseFloat(document.querySelector(`[name="variants[${idx}][profit_markup_pct]"]`)?.value) || 0;
  const sgst = parseFloat(document.querySelector(`[name="variants[${idx}][sales_gst_pct]"]`)?.value) || 0;

  const preview = document.getElementById('pricing-preview-' + idx);
  if (pp <= 0) {
    const tagVal = parseFloat(tagInput?.value) || 0;
    if (tagVal > 0) {
      onTagPriceInput(idx);
      return;
    }
    if (preview) preview.style.display = 'none';
    return;
  }
  const result = calculatePricing(pp, pgst, prof, sgst);
  if (preview) {
    preview.style.display = 'block';
    const ppEl = document.getElementById('pp-with-gst-' + idx);
    const spBeforeEl = document.getElementById('sp-before-gst-' + idx);
    const sgstEl = document.getElementById('sales-gst-' + idx);
    const sgstPctLabel = document.getElementById('sgst-pct-label-' + idx);
    const spEl = document.getElementById('selling-price-' + idx);
    const gpEl = document.getElementById('gross-profit-' + idx);
    const sgstNoteVal = document.getElementById('sgst-note-val-' + idx);

    if (ppEl) ppEl.textContent = '₹' + Math.round(result.purchasePriceWithGst);
    if (spBeforeEl) spBeforeEl.textContent = '₹' + Math.round(result.sellingPriceBeforeGst);
    if (sgstEl) sgstEl.textContent = '+₹' + Math.round(result.salesGstAmount);
    if (sgstPctLabel) sgstPctLabel.textContent = sgst;
    if (spEl) spEl.textContent = '₹' + Math.round(result.sellingPrice);
    if (gpEl) gpEl.textContent = '₹' + Math.round(result.profitAmount);
    if (sgstNoteVal) sgstNoteVal.textContent = '₹' + Math.round(result.salesGstAmount);
    
    // Auto-fill Tag/MRP if not manually entered
    if (tagInput && !tagInput.dataset.manual) {
      tagInput.value = Math.round(result.sellingPrice);
    }
  }
}

function onTagPriceInput(idx) {
  const tagInput = document.querySelector(`[name="variants[${idx}][tag_price]"]`);
  const tagVal = parseFloat(tagInput?.value) || 0;
  const sgst = parseFloat(document.querySelector(`[name="variants[${idx}][sales_gst_pct]"]`)?.value) || 0;
  const pp = parseFloat(document.querySelector(`[name="variants[${idx}][purchase_price]"]`)?.value) || 0;
  const pgst = parseFloat(document.querySelector(`[name="variants[${idx}][purchase_gst_pct]"]`)?.value) || 0;
  const preview = document.getElementById('pricing-preview-' + idx);

  if (tagVal > 0) {
    if (tagInput) tagInput.dataset.manual = 'true';
    const spBefore = Math.round(tagVal / (1 + (sgst / 100)));
    const sgstAmt = Math.round(tagVal - spBefore);
    const ppWithGst = pp > 0 ? Math.round(pp + (pp * (pgst / 100))) : 0;
    const profit = ppWithGst > 0 ? Math.round(spBefore - ppWithGst) : spBefore;

    if (preview) {
      preview.style.display = 'block';
      const ppEl = document.getElementById('pp-with-gst-' + idx);
      const spBeforeEl = document.getElementById('sp-before-gst-' + idx);
      const sgstEl = document.getElementById('sales-gst-' + idx);
      const sgstPctLabel = document.getElementById('sgst-pct-label-' + idx);
      const spEl = document.getElementById('selling-price-' + idx);
      const gpEl = document.getElementById('gross-profit-' + idx);
      const sgstNoteVal = document.getElementById('sgst-note-val-' + idx);

      if (ppEl) ppEl.textContent = ppWithGst > 0 ? '₹' + ppWithGst : '₹0';
      if (spBeforeEl) spBeforeEl.textContent = '₹' + spBefore;
      if (sgstEl) sgstEl.textContent = '+₹' + sgstAmt;
      if (sgstPctLabel) sgstPctLabel.textContent = sgst;
      if (spEl) spEl.textContent = '₹' + Math.round(tagVal);
      if (gpEl) gpEl.textContent = '₹' + profit;
      if (sgstNoteVal) sgstNoteVal.textContent = '₹' + sgstAmt;
    }
  } else {
    if (tagInput) delete tagInput.dataset.manual;
    calcVariant(idx);
  }
}

function applyDefaultRates(silent = false) {
  const pGst = parseFloat(document.getElementById('defPurchaseGst').value) || 0;
  const prof = parseFloat(document.getElementById('defProfit').value) || 0;
  const sGst = parseFloat(document.getElementById('defSalesGst').value) || 0;

  document.querySelectorAll('[id^="variant-"]').forEach(function(row) {
    const idx = row.id.replace('variant-', '');
    const pInput = document.querySelector(`[name="variants[${idx}][purchase_gst_pct]"]`);
    const profInput = document.querySelector(`[name="variants[${idx}][profit_markup_pct]"]`);
    const sInput = document.querySelector(`[name="variants[${idx}][sales_gst_pct]"]`);
    if (pInput) pInput.value = pGst;
    if (profInput) profInput.value = prof;
    if (sInput) sInput.value = sGst;
    calcVariant(idx);
  });
  if (!silent && typeof showToast === 'function') {
    showToast('Default rates applied to all variants', 'success');
  }
}

function addVariantRow(data = null) {
  variantCount++;
  const idx = variantCount;
  const defPGst = (document.getElementById('defPurchaseGst') ? document.getElementById('defPurchaseGst').value : '') || '<?= $defPurchaseGst ?>';
  const defProfit = (document.getElementById('defProfit') ? document.getElementById('defProfit').value : '') || '<?= $defProfit ?>';
  const defSGst = (document.getElementById('defSalesGst') ? document.getElementById('defSalesGst').value : '') || '<?= $defSalesGst ?>';

  const div = document.createElement('div');
  div.id = 'variant-' + idx;
  div.style.cssText = 'border-bottom:1px solid #e2e8f0;padding:18px 20px;';

  const cat = document.getElementById('catSelect');
  const sub = document.getElementById('subcatSelect');
  const itm = document.getElementById('itemTypeSelect');
  const custItm = document.getElementById('customItemTypeInput');
  const catName = cat && cat.selectedIndex > 0 ? cat.options[cat.selectedIndex].text : '';
  const subName = sub && sub.selectedIndex > 0 ? sub.options[sub.selectedIndex].text : '';
  let itmName = itm && itm.selectedIndex > 0 ? itm.options[itm.selectedIndex].value : '';
  if (itmName === 'Custom' && custItm && custItm.value.trim()) {
    itmName = custItm.value.trim();
  }
  const sizeOptions = getSizeOptionsHtml(catName, subName, itmName, data?.size || '');

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
        <small class="text-muted">Generated automatically from category, subcategory and size.</small>
      </div>
      <div class="form-group mb-0">
        <label class="form-label text-xs">Garment Size / Unit <span class="required">*</span></label>
        <select name="variants[${idx}][size]" id="variant-size-${idx}" class="form-select"
                onchange="toggleCustomSize(${idx}); updateSkuPreviews()" required>
          ${sizeOptions}
        </select>
        <input type="text" name="variants[${idx}][custom_size]" id="custom-size-${idx}"
               class="form-control mt-2" placeholder="Enter custom size, e.g. Kids 6-7 Yrs, 42 Slim Fit, Tailored"
               style="display:none" maxlength="40">
      </div>
    </div>
    <div class="form-row cols-2" style="margin-bottom:12px">
      <div class="form-group mb-0">
        <label class="form-label text-xs">Purchase Price (₹) <span class="required">*</span></label>
        <input type="number" name="variants[${idx}][purchase_price]" class="form-control" step="1" min="0" required
               oninput="calcVariant(${idx})" placeholder="0" value="${data?.purchase_price ? Math.round(data.purchase_price) : ''}">
      </div>
      <div class="form-group mb-0">
        <label class="form-label text-xs">Initial Stock Qty</label>
        <input type="number" name="variants[${idx}][initial_stock]" class="form-control" min="0" value="${data?.initial_stock||0}">
      </div>
    </div>
    <div class="form-row cols-3" style="margin-bottom:12px">
      <div class="form-group mb-0">
        <label class="form-label text-xs">Purchase GST %</label>
        <input type="number" name="variants[${idx}][purchase_gst_pct]" class="form-control" step="0.01" min="0"
               oninput="calcVariant(${idx})" value="${data?.purchase_gst_pct || defPGst}">
      </div>
      <div class="form-group mb-0">
        <label class="form-label text-xs">Profit Markup %</label>
        <input type="number" name="variants[${idx}][profit_markup_pct]" class="form-control" step="0.01" min="0"
               oninput="calcVariant(${idx})" value="${data?.profit_markup_pct || defProfit}">
      </div>
      <div class="form-group mb-0">
        <label class="form-label text-xs">Sales GST %</label>
        <input type="number" name="variants[${idx}][sales_gst_pct]" class="form-control" step="0.01" min="0"
               oninput="calcVariant(${idx})" value="${data?.sales_gst_pct || defSGst}">
      </div>
    </div>
    <div id="pricing-preview-${idx}" style="background:#f8fafc;border-radius:8px;padding:12px;border:1px solid #e2e8f0;font-size:0.8rem;display:none;margin-bottom:12px">
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px;text-align:center;">
        <div style="background:#fff;padding:8px 4px;border-radius:6px;border:1px solid #e2e8f0;">
          <span style="color:#64748b;font-size:0.72rem;display:block;">Cost + GST</span>
          <strong id="pp-with-gst-${idx}" style="font-size:0.88rem;color:#334155;">₹0</strong>
        </div>
        <div style="background:#fff;padding:8px 4px;border-radius:6px;border:1px solid #e2e8f0;">
          <span style="color:#64748b;font-size:0.72rem;display:block;">Pre-GST Sell</span>
          <strong id="sp-before-gst-${idx}" style="font-size:0.88rem;color:#334155;">₹0</strong>
        </div>
        <div style="background:#eff6ff;padding:8px 4px;border-radius:6px;border:1px solid #bfdbfe;">
          <span style="color:#1e40af;font-size:0.72rem;display:block;font-weight:600;">Sales GST (<span id="sgst-pct-label-${idx}">5</span>%)</span>
          <strong id="sales-gst-${idx}" style="font-size:0.88rem;color:#2563eb;">₹0</strong>
        </div>
        <div style="background:#f0fdf4;padding:8px 4px;border-radius:6px;border:1px solid #bbf7d0;">
          <span style="color:#166534;font-size:0.72rem;display:block;font-weight:600;">Tag / MRP (₹)</span>
          <strong id="selling-price-${idx}" style="font-size:0.88rem;color:#15803d;">₹0</strong>
        </div>
      </div>
      <div style="margin-top:8px;padding-top:8px;border-top:1px dashed #cbd5e1;display:flex;justify-content:space-between;align-items:center;font-size:0.76rem;color:#64748b;">
        <span>Est. Gross Profit / unit: <strong id="gross-profit-${idx}" style="color:#059669">₹0</strong></span>
        <span>Tag/MRP includes <strong id="sgst-note-val-${idx}" style="color:#2563eb">₹0</strong> Sales GST</span>
      </div>
    </div>
    <div class="form-row cols-2">
      <div class="form-group mb-0">
        <label class="form-label text-xs">Tag/MRP (₹)</label>
        <input type="number" name="variants[${idx}][tag_price]" class="form-control" step="1" min="0"
               oninput="onTagPriceInput(${idx})"
               placeholder="Auto-filled from selling price" value="${data?.tag_price ? Math.round(data.tag_price) : (data?.selling_price ? Math.round(data.selling_price) : '')}" style="font-weight:700;color:#2563eb">
      </div>
      <div class="form-group mb-0">
        <label class="form-label text-xs">Min Stock Alert</label>
        <input type="number" name="variants[${idx}][min_stock_alert]" class="form-control" min="0" value="${data?.min_stock_alert || '<?= $defMinStock ?>'}">
      </div>
    </div>
  `;

  document.getElementById('variantsContainer').appendChild(div);
  updateSkuPreviews();
  if (data?.purchase_price) {
    calcVariant(idx);
  }
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

function loadSubcats(catId, preselectSubId = null, preselectItemType = null) {
  const select = document.getElementById('subcatSelect');
  select.innerHTML = '<option value="">Loading...</option>';
  if (!catId) {
    select.innerHTML = '<option value="">Select Subcategory</option>';
    onSubcategoryChange();
    return;
  }
  apiCall(window.APP_URL + '/api/get_subcategories.php', 'GET', { parent_id: catId }).then(function(res) {
    select.innerHTML = '<option value="">Select Subcategory</option>';
    if (res.success && res.data && res.data.length > 0) {
      res.data.forEach(function(sub) {
        const opt = document.createElement('option');
        opt.value = sub.id; opt.textContent = sub.name;
        if (preselectSubId && String(sub.id) === String(preselectSubId)) {
          opt.selected = true;
        }
        select.appendChild(opt);
      });
      if (!preselectSubId && select.options.length > 1) {
        select.selectedIndex = 1;
      }
    }
    onSubcategoryChange(preselectItemType);
  }).catch(function() {
    select.innerHTML = '<option value="">Select Subcategory</option>';
    onSubcategoryChange();
  });
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

function getCategoryToken(name) {
  if (!name) return '';
  const upper = name.toUpperCase();
  if (upper.includes('CLOTH') || upper.includes('APPAREL')) return 'CLO';
  if (upper.includes('FOOT') || upper.includes('SHOE')) return 'FTW';
  if (upper.includes('ACCESS')) return 'ACC';
  if (upper.includes('FABRIC')) return 'FAB';
  return skuPreviewToken(upper, 'CAT', 3);
}

function getSubcategoryToken(name) {
  if (!name) return '';
  const upper = name.toUpperCase();
  if (upper.includes('MEN') && !upper.includes('WOMEN')) return 'MEN';
  if (upper.includes('WOMEN')) return 'WOM';
  if (upper.includes('KID') || upper.includes('BOY') || upper.includes('GIRL')) return 'KID';
  if (upper.includes('BAG')) return 'BAG';
  if (upper.includes('BELT')) return 'BLT';
  return skuPreviewToken(upper, 'SUB', 3);
}

function getItemTypeCode(name) {
  if (!name) return '';
  const upper = name.toUpperCase();
  if (upper.includes('TSHIRT') || upper.includes('T-SHIRT') || upper.includes('T SHIRT')) return 'TSH';
  if (upper.includes('SHIRT')) return 'SHT';
  if (upper.includes('JEAN')) return 'JNS';
  if (upper.includes('TROUSER')) return 'TRS';
  if (upper.includes('PANT')) return 'PNT';
  if (upper.includes('KURTA') || upper.includes('KURTI')) return 'KRT';
  if (upper.includes('SAREE')) return 'SAR';
  if (upper.includes('DRESS') || upper.includes('FROCK') || upper.includes('GOWN')) return 'DRS';
  if (upper.includes('LEHENGA')) return 'LHG';
  if (upper.includes('BLOUSE')) return 'BLS';
  if (upper.includes('BLAZER') || upper.includes('SUIT')) return 'SUT';
  if (upper.includes('SHORT')) return 'SHR';
  if (upper.includes('SHOE') || upper.includes('SNEAKER')) return 'SHO';
  if (upper.includes('SANDAL')) return 'SND';
  if (upper.includes('SLIPPER') || upper.includes('FLIP')) return 'SLP';
  if (upper.includes('BOOT')) return 'BOT';
  if (upper.includes('HEEL')) return 'HEL';
  if (upper.includes('BAG') || upper.includes('PURSE')) return 'BAG';
  if (upper.includes('BELT')) return 'BLT';
  return skuPreviewToken(upper, 'ITM', 3);
}

function updateSkuPreviews() {
  const cat = document.getElementById('catSelect');
  const sub = document.getElementById('subcatSelect');
  const itm = document.getElementById('itemTypeSelect');
  const custItm = document.getElementById('customItemTypeInput');

  const catName = cat && cat.selectedIndex > 0 ? cat.options[cat.selectedIndex].text : '';
  const subName = sub && sub.selectedIndex > 0 ? sub.options[sub.selectedIndex].text : '';
  let itmName = itm && itm.selectedIndex > 0 ? itm.options[itm.selectedIndex].value : '';
  if (itmName === 'Custom' && custItm && custItm.value.trim()) {
    itmName = custItm.value.trim();
  }

  const catCode = catName ? getCategoryToken(catName) : '';
  const subCode = subName ? getSubcategoryToken(subName) : '';
  const itmCode = itmName ? getItemTypeCode(itmName) : '';

  const prefixParts = [catCode, subCode, itmCode].filter(Boolean);
  const prefix = prefixParts.length > 0 ? prefixParts.join('-') : 'BTQ';

  const map = {
    'Small':'S','Medium':'M','Large':'L','X-Large':'XL','XX-Large':'2XL',
    'Size 28':'28','Size 30':'30','Size 32':'32','Size 34':'34','Size 36':'36',
    'Size 38':'38','Size 40':'40','Size 42':'42','Size 44':'44',
    'Size 6':'6','Size 7':'7','Size 8':'8','Size 9':'9','Size 10':'10','Size 11':'11','Size 12':'12',
    'UK 6':'UK6','UK 7':'UK7','UK 8':'UK8','UK 9':'UK9','UK 10':'UK10','UK 11':'UK11','UK 12':'UK12',
    'Free Size':'FREE','Standard':'STD','One Size':'ONE'
  };

  document.querySelectorAll('[id^="variant-size-"]').forEach(function(select) {
    const idx = select.id.replace('variant-size-', '');
    const customInput = document.getElementById('custom-size-' + idx);
    const sizeValue = select.value === 'Custom' ? ((customInput && customInput.value) || 'CUSTOM') : select.value;
    const sizeCode = map[sizeValue] || skuPreviewToken(sizeValue, 'CUST', 4);
    const preview = document.getElementById('sku-preview-' + idx);
    if (preview) preview.value = prefix + '-[ID]-' + sizeCode;
  });
}

// Add first variant by default
addVariantRow();
document.getElementById('catSelect').addEventListener('change', updateSkuPreviews);
document.getElementById('subcatSelect').addEventListener('change', updateSkuPreviews);
document.getElementById('itemTypeSelect').addEventListener('change', updateSkuPreviews);
document.getElementById('variantsContainer').addEventListener('input', function(event) {
  if (event.target && event.target.id.indexOf('custom-size-') === 0) updateSkuPreviews();
});

document.addEventListener('DOMContentLoaded', function() {
  const catSelect = document.getElementById('catSelect');
  const preSubId = '<?= (int)($_POST['subcategory_id'] ?? 0) ?>';
  const preItemType = '<?= addslashes($_POST['item_type'] ?? '') ?>';
  if (catSelect && catSelect.value) {
    loadSubcats(catSelect.value, preSubId, preItemType);
  }
});
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
