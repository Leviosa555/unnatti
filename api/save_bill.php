<?php
/**
 * API: Save Bill (Create Invoice)
 * Accepts JSON POST, runs full transaction:
 * validate → deduct stock → insert sale header + items → return invoice_no
 */
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

requireLogin();

// Detect AJAX / JSON vs standard browser form submission
$isJsonContent = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
          || (stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false)
          || $isJsonContent;

if ($isAjax) {
    header('Content-Type: application/json');
}

function billError(string $message, int $code = 400) {
    global $isAjax;
    if (!$isAjax) {
        $_SESSION['flash_error'] = $message;
        $dest = $_SERVER['HTTP_REFERER'] ?? (APP_URL . '/admin/billing/create.php');
        header('Location: ' . $dest);
        exit;
    }
    jsonError($message, $code);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') billError('Method not allowed', 405);

// Accept JSON or form-encoded
$raw  = file_get_contents('php://input');
$post = ($isJsonContent ? json_decode($raw, true) : null) ?? $_POST;

validateCSRF();

// ── Parse inputs ───────────────────────────────────
$saleDate          = sanitize($post['sale_date'] ?? date('Y-m-d'));
$customerName      = trim(sanitize($post['customer_name'] ?? ''));
if (empty($customerName)) {
    billError('Customer Name is required and cannot be ignored.');
}
$customerMobile    = cleanMobile($post['customer_mobile'] ?? '');
if ($customerMobile && !isValidMobile($customerMobile)) {
    billError('Customer mobile number must be exactly 10 digits.');
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

if (empty($items) || !is_array($items)) billError('No items in bill.');

$allowedPaymentMethods = ['cash', 'upi', 'card', 'bank_transfer', 'credit', 'mixed'];
if (!in_array($paymentMethod, $allowedPaymentMethods)) $paymentMethod = 'cash';

// ── Validate items ──────────────────────────────────
// GST is applied on taxable amount (selling price * qty - discount)
$validItems    = [];
$subtotal      = 0;   // gross amount (price × qty) before discounts
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
    if (!$variant) billError("Product not found (ID: $variantId)");
    if ($variant['current_stock'] < $qty) {
        billError("Insufficient stock for {$variant['product_name']} [{$variant['sku']}]. Available: {$variant['current_stock']}, Requested: $qty");
    }

    // Price always comes from the DB — never trust the browser
    $unitPrice = (float)round($variant['selling_price']);
    if ($unitPrice <= 0) billError("No selling price set for {$variant['product_name']} [{$variant['sku']}].");

    // Billing form sends discount PER UNIT as `discount`; JSON clients may send line total as `discount_amount`
    if (isset($item['discount'])) {
        $lineDiscount = (float)round(max(0, (float)$item['discount']) * $qty);
    } else {
        $lineDiscount = (float)round(max(0, (float)($item['discount_amount'] ?? 0)));
    }
    if ($lineDiscount > $unitPrice * $qty) {
        billError("Discount cannot exceed the price for {$variant['product_name']}.");
    }

    // Check discount permission
    if ($lineDiscount > 0 && !canApplyDiscount()) {
        billError('You do not have permission to apply discounts.');
    }
    if (!isAdmin() && $lineDiscount > 0) {
        $maxAllowed = $unitPrice * $qty * maxDiscountPct() / 100;
        if ($lineDiscount > $maxAllowed) {
            billError("Discount exceeds your allowed maximum (" . maxDiscountPct() . "%) for {$variant['product_name']}.");
        }
    }

    $gstPct      = (float)$variant['sales_gst_pct'];
    $lineGross   = (float)round($unitPrice * $qty);
    $lineTaxable = (float)round($lineGross - $lineDiscount);
    $gstAmount   = (float)round($lineTaxable * $gstPct / 100);
    $lineTotal   = (float)round($lineTaxable + $gstAmount);
    $subtotal      += $lineGross;
    $lineDiscounts += $lineDiscount;

    $validItems[] = [
        'variant_id'              => $variantId,
        'sku_snapshot'            => $variant['sku'],
        'product_name_snapshot'   => $variant['product_name'],
        'size_snapshot'           => $variant['size'],
        'qty'                     => $qty,
        'purchase_cost_snapshot'  => (float)round($variant['purchase_price_with_gst']),
        'selling_price_snapshot'  => $unitPrice,
        'discount_amount'         => $lineDiscount,
        'gst_pct_snapshot'        => $gstPct,
        'gst_amount'              => $gstAmount,
        'line_total'              => $lineTotal,
    ];
}

if (empty($validItems)) billError('No valid items to process.');

// ── Compute totals (clean whole numbers for boutique billing) ───────
$additionalCharges = (float)round(max(0, $additionalCharges));
$discountAmount    = (float)round(max(0, $discountAmount) + $lineDiscounts); // bill-level + line discounts
$subtotal    = (float)round($subtotal);
$taxAmount   = (float)round(array_sum(array_column($validItems, 'gst_amount')));
$grandTotal  = (float)round($subtotal - $discountAmount + $taxAmount + $additionalCharges);
if ($grandTotal < 0) billError('Discount cannot exceed the bill total.');
// clamp
if ($amountReceived > $grandTotal) $amountReceived = $grandTotal;
$amountReceived = (float)round($amountReceived);
$balanceDue  = (float)round($grandTotal - $amountReceived);
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

    $redirectUrl = APP_URL . (isAdmin() ? '/admin/billing/invoice.php?id=' : '/employee/billing/invoice.php?id=') . $saleId;

    if (!$isAjax) {
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
    if (!$isAjax) {
        $_SESSION['flash_error'] = 'Failed to save bill: ' . $e->getMessage();
        header('Location: ' . APP_URL . '/admin/billing/create.php');
        exit;
    }
    jsonError('Transaction failed: ' . $e->getMessage(), 500);
}
