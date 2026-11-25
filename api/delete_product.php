<?php
/**
 * API: Delete Product
 * Deletes a product and all its variants
 */
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json');
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'Method not allowed']));
}

validateCSRF();

$productId = (int)($_POST['product_id'] ?? 0);

if (!$productId) {
    die(json_encode(['success' => false, 'message' => 'Product ID is required']));
}

try {
    // Get product details for logging
    $product = db()->fetchOne("SELECT id, name, image FROM products WHERE id = ?", [$productId]);
    
    if (!$product) {
        die(json_encode(['success' => false, 'message' => 'Product not found']));
    }

    // Check if product has any sales
    $salesCount = db()->fetchOne(
        "SELECT COUNT(*) AS c FROM sale_items si 
         JOIN product_variants pv ON si.variant_id = pv.id 
         WHERE pv.product_id = ?",
        [$productId]
    )['c'];

    if ($salesCount > 0) {
        die(json_encode([
            'success' => false, 
            'message' => 'Cannot delete product that has sales history. Consider marking it as inactive instead.'
        ]));
    }

    // Check if product has any purchases
    $purchaseCount = db()->fetchOne(
        "SELECT COUNT(*) AS c FROM purchase_items pi 
         JOIN product_variants pv ON pi.variant_id = pv.id 
         WHERE pv.product_id = ?",
        [$productId]
    )['c'];

    if ($purchaseCount > 0) {
        die(json_encode([
            'success' => false, 
            'message' => 'Cannot delete product that has supplier purchase history. Consider marking it as inactive instead.'
        ]));
    }

    db()->beginTransaction();

    // Delete product image if exists
    if ($product['image']) {
        $imagePath = UPLOAD_PATH . 'products/' . $product['image'];
        if (file_exists($imagePath)) {
            @unlink($imagePath);
        }
    }

    // Remove dependent history rows first (these FKs have no ON DELETE CASCADE)
    db()->execute(
        "DELETE sa FROM stock_adjustments sa
         JOIN product_variants pv ON sa.variant_id = pv.id
         WHERE pv.product_id = ?",
        [$productId]
    );
    db()->execute(
        "DELETE sm FROM stock_movements sm
         JOIN product_variants pv ON sm.variant_id = pv.id
         WHERE pv.product_id = ?",
        [$productId]
    );
    db()->execute(
        "DELETE mci FROM material_consumption_items mci
         JOIN product_variants pv ON mci.variant_id = pv.id
         WHERE pv.product_id = ?",
        [$productId]
    );
    db()->execute(
        "UPDATE material_consumptions SET finished_product_id = NULL, finished_variant_id = NULL 
         WHERE finished_product_id = ?",
        [$productId]
    );

    // Delete variants
    db()->execute("DELETE FROM product_variants WHERE product_id = ?", [$productId]);

    // Delete product
    db()->execute("DELETE FROM products WHERE id = ?", [$productId]);

    // Log activity
    logActivity(
        "Deleted product: " . $product['name'] . " (ID: $productId)",
        'products',
        $productId,
        'product'
    );

    db()->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Product deleted successfully'
    ]);

} catch (Exception $e) {
    if (db()->getConnection()->inTransaction()) {
        db()->rollback();
    }
    error_log("Delete product error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to delete product: ' . $e->getMessage()
    ]);
}
