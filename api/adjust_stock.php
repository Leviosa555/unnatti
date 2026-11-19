<?php
/**
 * API: Stock Adjustment
 * Manually adjust stock quantity with reason logging
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

$variantId = (int)($_POST['variant_id'] ?? 0);
$adjustmentType = sanitize($_POST['adjustment_type'] ?? ''); // 'add' or 'subtract'
$qtyChange = (int)($_POST['qty_change'] ?? 0);
$reason = !empty($_POST['reason']) ? sanitize($_POST['reason']) : 'Manual adjustment';

// Validation
if (!$variantId) {
    die(json_encode(['success' => false, 'message' => 'Variant ID is required']));
}

if (!in_array($adjustmentType, ['add', 'subtract'])) {
    die(json_encode(['success' => false, 'message' => 'Invalid adjustment type']));
}

if ($qtyChange <= 0) {
    die(json_encode(['success' => false, 'message' => 'Quantity must be greater than zero']));
}

try {
    // Get variant details
    $variant = db()->fetchOne(
        "SELECT pv.*, p.name AS product_name FROM product_variants pv 
         JOIN products p ON pv.product_id = p.id 
         WHERE pv.id = ?",
        [$variantId]
    );

    if (!$variant) {
        die(json_encode(['success' => false, 'message' => 'Variant not found']));
    }

    $currentStock = (int)$variant['current_stock'];
    $qtyBefore = $currentStock;

    // Calculate new stock
    if ($adjustmentType === 'add') {
        $qtyAfter = $currentStock + $qtyChange;
        $signedQtyChange = $qtyChange; // Positive
    } else {
        // Subtract
        if ($qtyChange > $currentStock) {
            die(json_encode([
                'success' => false,
                'message' => "Cannot remove $qtyChange units. Only $currentStock units in stock."
            ]));
        }
        $qtyAfter = $currentStock - $qtyChange;
        $signedQtyChange = -$qtyChange; // Negative
    }

    db()->beginTransaction();

    // Update variant stock
    db()->execute(
        "UPDATE product_variants SET current_stock = ?, updated_at = NOW() WHERE id = ?",
        [$qtyAfter, $variantId]
    );

    // Generate adjustment number
    $adjustmentNo = 'ADJ-' . date('Ymd') . '-' . str_pad($variantId, 4, '0', STR_PAD_LEFT) . '-' . time();

    // Log in stock_adjustments table
    db()->insert(
        "INSERT INTO stock_adjustments 
         (adjustment_no, variant_id, adjustment_type, qty_change, reason, created_by, created_at) 
         VALUES (?, ?, ?, ?, ?, ?, NOW())",
        [$adjustmentNo, $variantId, $adjustmentType, $qtyChange, $reason, currentUserId()]
    );

    // Log in stock_movements table
    db()->insert(
        "INSERT INTO stock_movements 
         (variant_id, movement_type, qty_change, qty_before, qty_after, reference_id, reference_type, notes, created_by, created_at) 
         VALUES (?, 'adjustment', ?, ?, ?, ?, 'stock_adjustment', ?, ?, NOW())",
        [$variantId, $signedQtyChange, $qtyBefore, $qtyAfter, $variantId, $reason, currentUserId()]
    );

    // Log activity
    logActivity(
        "Stock adjusted for {$variant['product_name']} ({$variant['sku']}): " . 
        ($adjustmentType === 'add' ? "+$qtyChange" : "-$qtyChange") . 
        " units. Reason: $reason",
        'inventory',
        $variantId,
        'product_variant'
    );

    db()->commit();

    echo json_encode([
        'success' => true,
        'message' => "Stock adjusted successfully. New stock: $qtyAfter units",
        'new_stock' => $qtyAfter
    ]);

} catch (Exception $e) {
    if (db()->getConnection()->inTransaction()) {
        db()->rollback();
    }
    error_log("Stock adjustment error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to adjust stock: ' . $e->getMessage()
    ]);
}
