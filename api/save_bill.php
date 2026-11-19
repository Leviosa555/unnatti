<?php
/**
 * API: Save Bill (Create Invoice)
 * Accepts JSON POST, runs full transaction:
 * validate → deduct stock → insert sale header + items → return invoice_no
 */
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

// Accept JSON or form-encoded
$raw  = file_get_contents('php://input');
$post = json_decode($raw, true) ?? $_POST;

validateCSRF();

// ── Parse inputs ───────────────────────────────────
$saleDate          = sanitize($post['sale_date'] ?? date('Y-m-d'));
$customerName      = sanitize($post['customer_name'] ?? 'Walk-In Customer');
$customerMobile    = cleanMobile($post['customer_mobile'] ?? '');
if ($customerMobile && !isValidMobile($customerMobile)) {
    jsonError('Customer mobile number must be exactly 10 digits.');
}
$customerAddress   = sanitize($post['customer_address'] ?? '');
$customerEmail     = sanitize($post['customer_email'] ?? '');
$customerType      = in_array($post['customer_type'] ?? '', ['retail', 'wholesale', 'walk-in']) ? $post['customer_type'] : 'retail';
$customerGstin     = sanitize($post['customer_gstin'] ?? '');
$customerNotes     = sanitize($post['customer_notes'] ?? '');
$customerId        = (int)($post['customer_id'] ?? 0);
$paymentMethod     = sanitize($post['payment_method'] ?? 'cash');
$amountReceived    = (float)($post['amount_received'] ?? 0);
$discountAmount    = (float)($post['discount_amount'] ?? 0);
$additionalCharges = (float)($post['additional_charges'] ?? 0);
$notes             = sanitize($post['notes'] ?? '');
if (empty($notes) && !empty($customerNotes)) {
    $notes = $customerNotes;
}
$items             = $post['items'] ?? [];

if (empty($customerName)) $customerName = 'Walk-In Customer';
if (empty($items) || !is_array($items)) jsonError('No items in bill.');

$allowedPaymentMethods = ['cash', 'upi', 'card', 'bank_transfer', 'credit', 'mixed'];
if (!in_array($paymentMethod, $allowedPaymentMethods)) $paymentMethod = 'cash';

// ── Validate items ──────────────────────────────────
// Selling prices are GST-INCLUSIVE (see calculatePricing()), so GST is
// extracted from the line amount, never added on top of it.
$validItems    = [];
$subtotal      = 0;   // gross amount (price × qty, GST incl.) before discounts
$lineDiscounts = 0;   // sum of all line discounts
foreach ($items as $item) {
    $variantId = (int)($item['variant_id'] ?? 0);
    $qty       = (int)($item['qty'] ?? 0);

    if (!$variantId || $qty <= 0) continue;

    $variant = db()->fetchOne(
        "SELECT pv.*, p.name AS product_name FROM product_variants pv
         JOIN products p ON pv.product_id = p.id
         WHERE pv.id = ? AND pv.status = 'active'",
        [$variantId]
    );
    if (!$variant) jsonError("Product not found (ID: $variantId)");
    if ($variant['current_stock'] < $qty) {
        jsonError("Insufficient stock for {$variant['product_name']} [{$variant['sku']}]. Available: {$variant['current_stock']}, Requested: $qty");
    }

    // Price always comes from the DB — never trust the browser
    $unitPrice = (float)$variant['selling_price'];
    if ($unitPrice <= 0) jsonError("No selling price set for {$variant['product_name']} [{$variant['sku']}].");

    // Billing form sends discount PER UNIT as `discount`; JSON clients may send line total as `discount_amount`
    if (isset($item['discount'])) {
        $lineDiscount = round(max(0, (float)$item['discount']) * $qty, 2);
    } else {
        $lineDiscount = round(max(0, (float)($item['discount_amount'] ?? 0)), 2);
    }
    if ($lineDiscount > $unitPrice * $qty) {
        jsonError("Discount cannot exceed the price for {$variant['product_name']}.");
    }

    // Check discount permission
    if ($lineDiscount > 0 && !canApplyDiscount()) {
        jsonError('You do not have permission to apply discounts.');
    }
    if (!isAdmin() && $lineDiscount > 0) {
        $maxAllowed = $unitPrice * $qty * maxDiscountPct() / 100;
        if ($lineDiscount > $maxAllowed) {
            jsonError("Discount exceeds your allowed maximum (" . maxDiscountPct() . "%) for {$variant['product_name']}.");
        }
    }

    $gstPct    = (float)$variant['sales_gst_pct'];
    $lineGross = round($unitPrice * $qty, 2);
    $lineTotal = round($lineGross - $lineDiscount, 2);                     // what the customer pays (GST incl.)
    $gstAmount = round($lineTotal * $gstPct / (100 + $gstPct), 2);         // GST contained in that amount
    $subtotal      += $lineGross;
    $lineDiscounts += $lineDiscount;

    $validItems[] = [
        'variant_id'              => $variantId,
        'sku_snapshot'            => $variant['sku'],
        'product_name_snapshot'   => $variant['product_name'],
        'size_snapshot'           => $variant['size'],
        'qty'                     => $qty,
        'purchase_cost_snapshot'  => $variant['purchase_price_with_gst'],
        'selling_price_snapshot'  => $unitPrice,
        'discount_amount'         => $lineDiscount,
        'gst_pct_snapshot'        => $gstPct,
        'gst_amount'              => $gstAmount,
        'line_total'              => $lineTotal,
    ];
}

if (empty($validItems)) jsonError('No valid items to process.');

// ── Compute totals ──────────────────────────────────
$additionalCharges = max(0, $additionalCharges);
$discountAmount    = round(max(0, $discountAmount) + $lineDiscounts, 2); // bill-level + line discounts
$subtotal    = round($subtotal, 2);
$taxAmount   = round(array_sum(array_column($validItems, 'gst_amount')), 2); // informational: already inside prices
$grandTotal  = round($subtotal - $discountAmount + $additionalCharges, 2);
if ($grandTotal < 0) jsonError('Discount cannot exceed the bill total.');
// clamp
if ($amountReceived > $grandTotal) $amountReceived = $grandTotal;
$balanceDue  = round($grandTotal - $amountReceived, 2);
$payStatus   = $balanceDue <= 0 ? 'paid' : ($amountReceived > 0 ? 'partial' : 'unpaid');

// ── Transaction ─────────────────────────────────────
try {
    db()->beginTransaction();

    // Auto-lookup or register customer if not selected and not Walk-In Customer
    if (!$customerId && !empty($customerName) && strcasecmp($customerName, 'Walk-In Customer') !== 0) {
        if ($customerMobile) {
            $foundCust = db()->fetchOne("SELECT id FROM customers WHERE mobile = ?", [$customerMobile]);
            if ($foundCust) {
                $customerId = (int)$foundCust['id'];
            }
        }
        if (!$customerId) {
            $customerId = (int)db()->insert(
                "INSERT INTO customers (name, mobile, email, address, customer_type, gstin, notes, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)",
                [
                    $customerName,
                    $customerMobile ?: null,
                    $customerEmail ?: null,
                    $customerAddress ?: null,
                    $customerType,
                    $customerGstin ?: null,
                    $customerNotes ?: null,
                    currentUserId()
                ]
            );
            logActivity("Auto-registered customer on billing: $customerName", 'customers', $customerId, 'customer');
        }
    }

    $invoiceNo = generateInvoiceNumber();

    $saleId = db()->insert(
        "INSERT INTO sales
         (invoice_no, sale_date, customer_id, customer_name, customer_mobile, customer_address,
          subtotal, discount_amount, tax_amount, additional_charges, grand_total,
          amount_received, balance_due, payment_status, payment_method, notes, created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
        [
            $invoiceNo, $saleDate,
            $customerId ?: null, $customerName, $customerMobile ?: null, $customerAddress ?: null,
            $subtotal, $discountAmount, $taxAmount, $additionalCharges, $grandTotal,
            $amountReceived, $balanceDue, $payStatus, $paymentMethod, $notes, currentUserId()
        ]
    );

    foreach ($validItems as $vi) {
        db()->insert(
            "INSERT INTO sale_items
             (sale_id, variant_id, sku_snapshot, product_name_snapshot, size_snapshot,
              qty, purchase_cost_snapshot, selling_price_snapshot, discount_amount,
              gst_pct_snapshot, gst_amount, line_total)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
            [
                $saleId, $vi['variant_id'], $vi['sku_snapshot'], $vi['product_name_snapshot'],
                $vi['size_snapshot'], $vi['qty'], $vi['purchase_cost_snapshot'],
                $vi['selling_price_snapshot'], $vi['discount_amount'],
                $vi['gst_pct_snapshot'], $vi['gst_amount'], $vi['line_total']
            ]
        );

        // Deduct stock
        recordStockMovement(
            $vi['variant_id'], 'sale', -$vi['qty'],
            $saleId, 'sale', "Invoice: $invoiceNo", currentUserId()
        );
    }

    // Record payment if received
    if ($amountReceived > 0) {
        db()->insert(
            "INSERT INTO payments (sale_id, payment_method, amount, payment_date)
             VALUES (?,?,?,?)",
            [$saleId, $paymentMethod, $amountReceived, $saleDate]
        );
    }

    // Update customer totals (and outstanding if credit)
    if ($customerId) {
        db()->execute(
            "UPDATE customers SET outstanding_balance = outstanding_balance + ?,
             total_purchases = total_purchases + ?, updated_at = NOW() WHERE id = ?",
            [max(0, $balanceDue), $grandTotal, $customerId]
        );
    }

    db()->commit();

    logActivity("Bill created: $invoiceNo (₹$grandTotal)", 'billing', $saleId, 'sale');

    $redirectUrl = APP_URL . '/admin/billing/invoice.php?id=' . $saleId;

    // Standard HTML form submission redirects directly to the invoice; AJAX gets JSON
    $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
              || (strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
              || !empty($raw);

    if (!$isAjax && !empty($_POST)) {
        $_SESSION['flash_success'] = "Bill $invoiceNo created successfully.";
        header('Location: ' . $redirectUrl);
        exit;
    }

    jsonSuccess([
        'invoice_no'  => $invoiceNo,
        'sale_id'     => $saleId,
        'grand_total' => $grandTotal,
        'balance_due' => $balanceDue,
        'redirect'    => $redirectUrl,
    ], "Bill $invoiceNo created successfully.");

} catch (Exception $e) {
    db()->rollback();
    if (!empty($_POST) && empty($raw)) {
        $_SESSION['flash_error'] = 'Failed to save bill: ' . $e->getMessage();
        header('Location: ' . APP_URL . '/admin/billing/create.php');
        exit;
    }
    jsonError('Transaction failed: ' . $e->getMessage(), 500);
}
