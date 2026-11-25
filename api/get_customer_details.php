<?php
/**
 * API: Get Customer Profile, Stats & Purchase History
 * Returns JSON for Customer Quick View Modal
 */
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json');
requireLogin();

$id     = (int)($_GET['id'] ?? 0);
$name   = sanitize($_GET['name'] ?? '');
$saleId = (int)($_GET['sale_id'] ?? 0);

if (!$id && empty($name) && !$saleId) {
    jsonError('Customer ID, name or sale ID is required.', 400);
}

// 1. Fetch Customer Record
$customer = null;
if ($id > 0) {
    $customer = db()->fetchOne("SELECT * FROM customers WHERE id = ?", [$id]);
}
if (!$customer && !empty($name) && $name !== 'Walk-In Customer') {
    $customer = db()->fetchOne("SELECT * FROM customers WHERE name = ? ORDER BY id DESC LIMIT 1", [$name]);
}

// If customer found, enrich phone/address if missing
if ($customer) {
    if (empty($customer['mobile']) || empty($customer['address'])) {
        $lastSale = db()->fetchOne(
            "SELECT customer_mobile, customer_address FROM sales 
             WHERE (customer_id = ? OR customer_name = ?) AND (customer_mobile != '' OR customer_address != '') 
             ORDER BY id DESC LIMIT 1", 
            [$customer['id'], $customer['name']]
        );
        if ($lastSale) {
            if (empty($customer['mobile']) && !empty($lastSale['customer_mobile'])) $customer['mobile'] = $lastSale['customer_mobile'];
            if (empty($customer['address']) && !empty($lastSale['customer_address'])) $customer['address'] = $lastSale['customer_address'];
        }
    }
} else {
    // Unregistered customer or Walk-in fallback
    $lastSale = null;
    if ($saleId > 0) {
        $lastSale = db()->fetchOne("SELECT customer_name, customer_mobile, customer_address FROM sales WHERE id = ?", [$saleId]);
    }
    if (!$lastSale && !empty($name)) {
        $lastSale = db()->fetchOne("SELECT customer_name, customer_mobile, customer_address FROM sales WHERE customer_name = ? ORDER BY id DESC LIMIT 1", [$name]);
    }

    $cName = $lastSale['customer_name'] ?? ($name ?: 'Customer');
    $customer = [
        'id'                  => 0,
        'name'                => $cName,
        'mobile'              => $lastSale['customer_mobile'] ?? '',
        'email'               => '',
        'address'             => $lastSale['customer_address'] ?? '',
        'customer_type'       => ($cName === 'Walk-In Customer') ? 'walk-in' : 'retail',
        'outstanding_balance' => 0.00,
        'notes'               => '',
        'status'              => 'active',
        'created_at'          => null,
    ];
}

$custId   = (int)$customer['id'];
$custName = $customer['name'];

// Determine WHERE condition for sales query
if ($custId > 0) {
    $saleWhere = "(s.customer_id = ? OR (s.customer_name = ? AND s.customer_name != 'Walk-In Customer'))";
    $saleParams = [$custId, $custName];
} elseif ($custName !== 'Walk-In Customer' && !empty($custName)) {
    $saleWhere = "s.customer_name = ?";
    $saleParams = [$custName];
} elseif ($saleId > 0) {
    $saleWhere = "s.id = ?";
    $saleParams = [$saleId];
} else {
    $saleWhere = "1=0";
    $saleParams = [];
}

// 2. Fetch Customer Stats
$stats = [
    'total_bills' => 0,
    'total_spent' => 0.00,
    'total_due'   => 0.00,
];

if (!empty($saleParams)) {
    $statRow = db()->fetchOne(
        "SELECT COUNT(*) AS total_bills,
                COALESCE(SUM(CASE WHEN s.status != 'cancelled' THEN s.grand_total ELSE 0 END), 0) AS total_spent,
                COALESCE(SUM(CASE WHEN s.status = 'active' THEN s.balance_due ELSE 0 END), 0) AS total_due
         FROM sales s
         WHERE $saleWhere",
        $saleParams
    );
    if ($statRow) {
        $stats['total_bills'] = (int)$statRow['total_bills'];
        $stats['total_spent'] = (float)$statRow['total_spent'];
        $stats['total_due']   = (float)$statRow['total_due'];
    }
}

// 3. Fetch Bills History
$bills = [];
if (!empty($saleParams)) {
    $bills = db()->fetchAll(
        "SELECT s.id, s.invoice_no, s.sale_date, s.grand_total, s.amount_received, s.balance_due,
                s.payment_status, s.payment_method, s.status, u.full_name AS employee,
                (SELECT COUNT(*) FROM sale_items WHERE sale_id = s.id) AS item_count
         FROM sales s
         LEFT JOIN users u ON s.created_by = u.id
         WHERE $saleWhere
         ORDER BY s.id DESC
         LIMIT 30",
        $saleParams
    );
}

// 4. Fetch Purchased Products
$items = [];
if (!empty($saleParams)) {
    $items = db()->fetchAll(
        "SELECT si.id, si.product_name_snapshot AS product_name, si.sku_snapshot AS sku,
                si.size_snapshot AS size, si.qty, si.selling_price_snapshot AS unit_price,
                si.discount_amount, si.gst_amount, si.line_total, 
                s.id AS sale_id, s.invoice_no, s.sale_date, s.status AS sale_status,
                pv.product_id
         FROM sale_items si
         JOIN sales s ON si.sale_id = s.id
         LEFT JOIN product_variants pv ON si.variant_id = pv.id
         WHERE $saleWhere AND s.status != 'cancelled'
         ORDER BY s.sale_date DESC, si.id DESC
         LIMIT 60",
        $saleParams
    );
}

jsonSuccess([
    'customer' => $customer,
    'stats'    => $stats,
    'bills'    => $bills,
    'items'    => $items,
], 'Customer details loaded successfully');
