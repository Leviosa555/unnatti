<?php
/**
 * API: Search Product for autocomplete
 * Returns matching active variants with pricing & stock
 */
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json');
requireLogin();

$q             = sanitize($_GET['q'] ?? '');
$requestedType = sanitize($_GET['item_type'] ?? '');

// Determine item_type condition:
// 'product': only finished boutique products/garments
// 'material': only raw fabrics/materials for inward stock
// 'all': all items
// default based on active profile:
if ($requestedType === 'product') {
    $typeCondition = "(p.item_type = 'product' OR p.item_type IS NULL)";
} elseif ($requestedType === 'material') {
    $typeCondition = "p.item_type = 'material'";
} elseif ($requestedType === 'all') {
    $typeCondition = "1=1";
} else {
    $activeProfile = getActiveProfile();
    if ($activeProfile === 'fashion_kingdom') {
        $typeCondition = "p.item_type = 'material'";
    } else {
        // Unnatti boutique retail profile: ONLY show finished boutique products
        $typeCondition = "(p.item_type = 'product' OR p.item_type IS NULL)";
    }
}

if (strlen($q) < 1) {
    $results = db()->fetchAll(
        "SELECT pv.id, pv.sku, pv.size, pv.current_stock,
                pv.selling_price, pv.purchase_price_with_gst,
                pv.sales_gst_pct, pv.purchase_gst_pct, pv.profit_markup_pct,
                pv.purchase_price, pv.tag_price,
                p.name, p.brand, p.item_type,
                c.name AS category_name,
                sc.name AS subcategory_name
         FROM product_variants pv
         JOIN products p ON pv.product_id = p.id
         LEFT JOIN categories c ON p.category_id = c.id
         LEFT JOIN categories sc ON p.subcategory_id = sc.id
         WHERE pv.status = 'active' AND p.status = 'active'
           AND $typeCondition
         ORDER BY pv.current_stock DESC, p.name ASC
         LIMIT 20"
    );
} else {
    $term    = "%$q%";
    $results = db()->fetchAll(
        "SELECT pv.id, pv.sku, pv.size, pv.current_stock,
                pv.selling_price, pv.purchase_price_with_gst,
                pv.sales_gst_pct, pv.purchase_gst_pct, pv.profit_markup_pct,
                pv.purchase_price, pv.tag_price,
                p.name, p.brand, p.item_type,
                c.name AS category_name,
                sc.name AS subcategory_name
         FROM product_variants pv
         JOIN products p ON pv.product_id = p.id
         LEFT JOIN categories c ON p.category_id = c.id
         LEFT JOIN categories sc ON p.subcategory_id = sc.id
         WHERE pv.status = 'active' AND p.status = 'active'
           AND $typeCondition
           AND (pv.sku LIKE ? OR p.name LIKE ? OR p.brand LIKE ? OR c.name LIKE ? OR sc.name LIKE ?)
         ORDER BY pv.current_stock DESC, p.name ASC
         LIMIT 20",
        [$term, $term, $term, $term, $term]
    );
}

$formatted = array_map(function($r) {
    return [
        'id'                       => (int)$r['id'],
        'sku'                      => $r['sku'],
        'name'                     => $r['name'],
        'brand'                    => $r['brand'] ?? '',
        'item_type'                => $r['item_type'] ?? 'product',
        'category_name'            => $r['category_name'] ?? '',
        'subcategory_name'         => $r['subcategory_name'] ?? '',
        'size'                     => $r['size'],
        'current_stock'            => (int)$r['current_stock'],
        'selling_price'            => (float)$r['selling_price'],
        'purchase_price'           => (float)$r['purchase_price'],
        'purchase_price_with_gst'  => (float)$r['purchase_price_with_gst'],
        'purchase_gst_pct'         => (float)$r['purchase_gst_pct'],
        'profit_markup_pct'        => (float)$r['profit_markup_pct'],
        'sales_gst_pct'            => (float)$r['sales_gst_pct'],
        'tag_price'                => (float)$r['tag_price'],
    ];
}, $results);

jsonSuccess($formatted, count($formatted) . ' results');
