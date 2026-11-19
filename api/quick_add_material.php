<?php
/**
 * API: Quick Add Material / Product
 * Creates a new product and variant directly from the Purchase/Stock Entry screen
 */
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json');
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

validateCSRF();

$name          = sanitize($_POST['name'] ?? '');
$categoryId    = (int)($_POST['category_id'] ?? 0);
$subCatId      = (int)($_POST['subcategory_id'] ?? 0);
$materialType  = sanitize($_POST['material_type'] ?? $_POST['brand'] ?? '');
$description   = sanitize($_POST['description'] ?? '');
$size          = sanitize($_POST['size'] ?? 'Meter');
if (empty($size)) $size = 'Meter';

$purchasePrice = (float)($_POST['purchase_price'] ?? 0);
$purchaseGst   = (float)($_POST['purchase_gst_pct'] ?? getSetting('purchase_gst_pct', '5'));
$profitMarkup  = (float)($_POST['profit_markup_pct'] ?? getSetting('default_profit_markup_pct', '40'));
$salesGst      = (float)($_POST['sales_gst_pct'] ?? getSetting('sales_gst_pct', '5'));
$minAlert      = (int)($_POST['min_stock_alert'] ?? getSetting('default_min_stock_alert', '5'));

if (empty($name)) {
    jsonError('Material / Product name is required.');
}
if ($purchasePrice < 0) {
    jsonError('Purchase price must be non-negative.');
}

// Helpers for short, clean SKU generation
function quickSkuSegment($value, $fallback = 'MAT', $length = 3) {
    $value = strtoupper(trim((string)$value));
    $value = preg_replace('/[^A-Z0-9]+/', '', $value);
    return $value === '' ? $fallback : substr($value, 0, $length);
}

function quickSizeSkuSegment($size) {
    $map = [
        'Meter' => 'MTR', 'Per Meter' => 'MTR', 'Standard' => 'STD',
        'Small' => 'S', 'Medium' => 'M', 'Large' => 'L', 'X-Large' => 'XL',
        'Roll' => 'ROLL', 'Piece' => 'PCS', 'Yard' => 'YD'
    ];
    return $map[$size] ?? quickSkuSegment($size, 'CUST', 4);
}

try {
    db()->beginTransaction();

    // 1. Insert into products
    $productId = db()->insert(
        "INSERT INTO products (category_id, subcategory_id, item_type, name, brand, description, status, created_by)
         VALUES (?, ?, 'material', ?, ?, ?, 'active', ?)",
        [
            $categoryId ?: null,
            $subCatId ?: null,
            $name,
            $materialType ?: null,
            $description ?: null,
            currentUserId()
        ]
    );

    // 2. Fetch category names for SKU and display
    $catRow    = $categoryId ? db()->fetchOne("SELECT name FROM categories WHERE id = ?", [$categoryId]) : null;
    $subCatRow = $subCatId ? db()->fetchOne("SELECT name FROM categories WHERE id = ?", [$subCatId]) : null;

    $matToken = $subCatRow ? quickSkuSegment($subCatRow['name'], 'MAT', 3) : quickSkuSegment($catRow['name'] ?? '', 'MAT', 3);
    $sizeCode = quickSizeSkuSegment($size);

    $sku = 'MAT-' . $matToken . '-P' . str_pad((string)$productId, 3, '0', STR_PAD_LEFT) . '-' . $sizeCode;

    // 3. Compute pricing
    $pricing = calculatePricing($purchasePrice, $purchaseGst, $profitMarkup, $salesGst);

    // 4. Insert into product_variants (initial stock is 0; will be incremented by the purchase entry)
    $variantId = db()->insert(
        "INSERT INTO product_variants
         (product_id, sku, size, purchase_price, purchase_gst_pct, purchase_gst_amount,
          purchase_price_with_gst, profit_markup_pct, profit_amount, selling_price_before_gst,
          sales_gst_pct, sales_gst_amount, selling_price, tag_price, current_stock, min_stock_alert)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [
            $productId, $sku, $size,
            $pricing['purchase_price'], $pricing['purchase_gst_pct'], $pricing['purchase_gst_amount'],
            $pricing['purchase_price_with_gst'], $pricing['profit_markup_pct'], $pricing['profit_amount'],
            $pricing['selling_price_before_gst'], $pricing['sales_gst_pct'], $pricing['sales_gst_amount'],
            $pricing['selling_price'], $pricing['selling_price'],
            0, $minAlert
        ]
    );

    db()->commit();

    logActivity("Quick-created material: $name (SKU: $sku)", 'products', $productId, 'product');

    jsonSuccess([
        'id'                      => (int)$variantId,
        'product_id'              => (int)$productId,
        'sku'                     => $sku,
        'name'                    => $name,
        'brand'                   => $materialType,
        'material_type'           => $materialType,
        'category_name'           => $catRow['name'] ?? '',
        'subcategory_name'        => $subCatRow['name'] ?? '',
        'size'                    => $size,
        'current_stock'           => 0,
        'purchase_price'          => (float)$pricing['purchase_price'],
        'purchase_gst_pct'        => (float)$pricing['purchase_gst_pct'],
        'purchase_gst_amount'     => (float)$pricing['purchase_gst_amount'],
        'purchase_price_with_gst' => (float)$pricing['purchase_price_with_gst'],
        'selling_price'           => (float)$pricing['selling_price'],
    ], "Material '$name' created successfully!");

} catch (Exception $e) {
    if (db()->getConnection()->inTransaction()) {
        db()->rollback();
    }
    jsonError('Failed to create material: ' . $e->getMessage(), 500);
}

