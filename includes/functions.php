<?php
/**
 * Core Functions — Unnatti Enterprises Mini ERP
 * Pricing engine, formatting, activity logging, utilities
 */

require_once __DIR__ . '/../config/config.php';

// =====================================================
// PRICING ENGINE
// =====================================================

/**
 * Calculate complete pricing from purchase price.
 * All values are DECIMAL-safe (string math via bcmath or rounded floats).
 *
 * @param float $purchasePrice      Base purchase price (excl. GST)
 * @param float $purchaseGstPct     Purchase GST %
 * @param float $profitMarkupPct    Profit markup %
 * @param float $salesGstPct        Sales GST %
 * @param int   $qty                Quantity
 * @return array                    Complete pricing breakdown
 */
function calculatePricing(
    float $purchasePrice,
    float $purchaseGstPct,
    float $profitMarkupPct,
    float $salesGstPct,
    int   $qty = 1
): array {
    // Step 1: Purchase GST
    $purchaseGstAmount     = round($purchasePrice * $purchaseGstPct / 100, 2);
    // Step 2: Purchase price incl. GST
    $purchasePriceWithGst  = round($purchasePrice + $purchaseGstAmount, 2);
    // Step 3: Total purchase value
    $totalPurchaseValue    = round($purchasePriceWithGst * $qty, 2);
    // Step 4: Profit amount
    $profitAmount          = round($purchasePriceWithGst * $profitMarkupPct / 100, 2);
    // Step 5: Selling price before GST
    $sellingPriceBeforeGst = round($purchasePriceWithGst + $profitAmount, 2);
    // Step 6: Sales GST amount
    $salesGstAmount        = round($sellingPriceBeforeGst * $salesGstPct / 100, 2);
    // Step 7: Final selling price
    $sellingPrice          = round($sellingPriceBeforeGst + $salesGstAmount, 2);
    // Step 8: Total selling value
    $totalSellingValue     = round($sellingPrice * $qty, 2);
    // Step 9: Gross profit
    $grossProfit           = round($totalSellingValue - $totalPurchaseValue, 2);

    return [
        'purchase_price'           => $purchasePrice,
        'purchase_gst_pct'         => $purchaseGstPct,
        'purchase_gst_amount'      => $purchaseGstAmount,
        'purchase_price_with_gst'  => $purchasePriceWithGst,
        'profit_markup_pct'        => $profitMarkupPct,
        'profit_amount'            => $profitAmount,
        'selling_price_before_gst' => $sellingPriceBeforeGst,
        'sales_gst_pct'            => $salesGstPct,
        'sales_gst_amount'         => $salesGstAmount,
        'selling_price'            => $sellingPrice,
        'qty'                      => $qty,
        'total_purchase_value'     => $totalPurchaseValue,
        'total_selling_value'      => $totalSellingValue,
        'gross_profit'             => $grossProfit,
    ];
}

/** Calculate pricing using system defaults from settings */
function calculatePricingWithDefaults(float $purchasePrice, int $qty = 1): array {
    return calculatePricing(
        $purchasePrice,
        (float)getSetting('purchase_gst_pct', '5'),
        (float)getSetting('default_profit_markup_pct', '65'),
        (float)getSetting('sales_gst_pct', '5'),
        $qty
    );
}

// =====================================================
// INVOICE NUMBER GENERATION
// =====================================================

function generateInvoiceNumber(): string {
    $prefix = getSetting('invoice_prefix', 'INV');
    $year   = date('Y');
    $month  = date('m');

    // Get last invoice number for this month
    $last = db()->fetchOne(
        "SELECT invoice_no FROM sales WHERE invoice_no LIKE ? ORDER BY id DESC LIMIT 1",
        ["{$prefix}/{$year}/{$month}/%"]
    );

    if ($last) {
        $parts = explode('/', $last['invoice_no']);
        $seq   = (int)end($parts) + 1;
    } else {
        $seq = 1;
    }

    return sprintf('%s/%s/%s/%04d', $prefix, $year, $month, $seq);
}

/** Generate Purchase Number */
function generatePurchaseNumber(): string {
    $year  = date('Y');
    $month = date('m');
    $last  = db()->fetchOne(
        "SELECT purchase_no FROM purchases WHERE purchase_no LIKE ? ORDER BY id DESC LIMIT 1",
        ["PO/{$year}/{$month}/%"]
    );
    $seq = $last ? ((int)explode('/', $last['purchase_no'])[3] + 1) : 1;
    return sprintf('PO/%s/%s/%04d', $year, $month, $seq);
}

/** Generate Return Number */
function generateReturnNumber(): string {
    $year  = date('Y');
    $last  = db()->fetchOne(
        "SELECT return_no FROM sales_returns WHERE return_no LIKE ? ORDER BY id DESC LIMIT 1",
        ["RTN/{$year}/%"]
    );
    $seq = $last ? ((int)explode('/', $last['return_no'])[2] + 1) : 1;
    return sprintf('RTN/%s/%04d', $year, $seq);
}

/** Generate Adjustment Number */
function generateAdjustmentNumber(): string {
    $year  = date('Y');
    $last  = db()->fetchOne(
        "SELECT adjustment_no FROM stock_adjustments WHERE adjustment_no LIKE ? ORDER BY id DESC LIMIT 1",
        ["ADJ/{$year}/%"]
    );
    $seq = $last ? ((int)explode('/', $last['adjustment_no'])[2] + 1) : 1;
    return sprintf('ADJ/%s/%04d', $year, $seq);
}

// =====================================================
// STOCK MANAGEMENT
// =====================================================

/**
 * Record a stock movement and update variant stock.
 * Must be called INSIDE a DB transaction.
 */
function recordStockMovement(
    int    $variantId,
    string $movementType,
    int    $qtyChange,
    int    $referenceId,
    string $referenceType,
    string $notes = '',
    int    $userId = 0
): bool {
    // Lock row for consistent read
    $variant = db()->fetchOne(
        "SELECT current_stock FROM product_variants WHERE id = ? FOR UPDATE",
        [$variantId]
    );

    if (!$variant) return false;

    $qtyBefore = (int)$variant['current_stock'];
    $qtyAfter  = $qtyBefore + $qtyChange;

    if ($qtyAfter < 0) {
        throw new Exception("Insufficient stock for variant ID $variantId. Available: $qtyBefore, Requested: " . abs($qtyChange));
    }

    // Update stock
    db()->execute(
        "UPDATE product_variants SET current_stock = ? WHERE id = ?",
        [$qtyAfter, $variantId]
    );

    // Record movement
    db()->insert(
        "INSERT INTO stock_movements (variant_id, movement_type, qty_change, qty_before, qty_after, reference_id, reference_type, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$variantId, $movementType, $qtyChange, $qtyBefore, $qtyAfter, $referenceId, $referenceType, $notes, $userId ?: currentUserId()]
    );

    return true;
}

// =====================================================
// ACTIVITY LOGGING
// =====================================================

function logActivity(string $action, string $module = '', int $referenceId = 0, string $referenceType = ''): void {
    try {
        db()->insert(
            "INSERT INTO activity_logs (user_id, action, module, reference_id, reference_type, ip_address) VALUES (?,?,?,?,?,?)",
            [currentUserId(), $action, $module, $referenceId ?: null, $referenceType ?: null, $_SERVER['REMOTE_ADDR'] ?? '']
        );
    } catch (Exception $e) {
        error_log("Activity log error: " . $e->getMessage());
    }
}

// =====================================================
// FORMATTING UTILITIES
// =====================================================

/** Format amount as Indian currency */
function formatCurrency(float $amount, bool $showSymbol = true): string {
    $symbol   = $showSymbol ? getSetting('currency_symbol', '₹') : '';
    $formatted = number_format($amount, 2);
    return $symbol . $formatted;
}

/** Format date */
function formatDate(string $date, string $format = ''): string {
    if (empty($date) || $date === '0000-00-00') return '—';
    $fmt = $format ?: getSetting('date_format', 'd/m/Y');
    return date($fmt, strtotime($date));
}

/** Format datetime */
function formatDateTime(string $datetime): string {
    if (empty($datetime)) return '—';
    return date('d/m/Y H:i', strtotime($datetime));
}

/**
 * Escape output for HTML
 * Compatible with PHP 7 and PHP 8
 */
function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Sanitize string input */
function sanitize(string $input): string {
    return trim(strip_tags($input));
}

/** Get stock status badge HTML */
function stockBadge(int $stock, int $minAlert = 5): string {
    if ($stock === 0) {
        return '<span class="badge badge-danger">Out of Stock</span>';
    } elseif ($stock <= $minAlert) {
        return '<span class="badge badge-warning">Low Stock (' . $stock . ')</span>';
    } else {
        return '<span class="badge badge-success">' . $stock . ' in stock</span>';
    }
}

/**
 * Get payment status badge
 */
function paymentBadge(string $status): string {

    switch ($status) {

        case 'paid':
            return '<span class="badge badge-success">Paid</span>';

        case 'partial':
            return '<span class="badge badge-warning">Partial</span>';

        case 'unpaid':
            return '<span class="badge badge-danger">Unpaid</span>';

        default:
            return '<span class="badge badge-secondary">' . e($status) . '</span>';
    }
}


/**
 * Get bill status badge
 */
function billStatusBadge(string $status): string {

    switch ($status) {

        case 'active':
            return '<span class="badge badge-success">Active</span>';

        case 'returned':
            return '<span class="badge badge-warning">Returned</span>';

        case 'cancelled':
            return '<span class="badge badge-danger">Cancelled</span>';

        default:
            return '<span class="badge badge-secondary">' . e($status) . '</span>';
    }
}

// =====================================================
// PAGINATION
// =====================================================

function paginate(int $total, int $page, int $perPage = DEFAULT_PAGE_SIZE): array {
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page       = max(1, min($page, $totalPages));
    $offset     = ($page - 1) * $perPage;

    return [
        'total'       => $total,
        'per_page'    => $perPage,
        'page'        => $page,
        'total_pages' => $totalPages,
        'offset'      => $offset,
        'has_prev'    => $page > 1,
        'has_next'    => $page < $totalPages,
    ];
}

function renderPagination(array $pagination, string $baseUrl): string {
    if ($pagination['total_pages'] <= 1) return '';
    $html = '<nav class="pagination-nav"><ul class="pagination">';
    
    if ($pagination['has_prev']) {
        $html .= '<li><a href="' . e($baseUrl) . '&page=' . ($pagination['page'] - 1) . '"><i class="fas fa-chevron-left"></i></a></li>';
    }
    
    $start = max(1, $pagination['page'] - 2);
    $end   = min($pagination['total_pages'], $pagination['page'] + 2);
    
    for ($i = $start; $i <= $end; $i++) {
        $active = $i === $pagination['page'] ? ' active' : '';
        $html  .= "<li class=\"$active\"><a href=\"" . e($baseUrl) . "&page=$i\">$i</a></li>";
    }
    
    if ($pagination['has_next']) {
        $html .= '<li><a href="' . e($baseUrl) . '&page=' . ($pagination['page'] + 1) . '"><i class="fas fa-chevron-right"></i></a></li>';
    }
    
    $html .= '</ul></nav>';
    return $html;
}

// =====================================================
// VALIDATION
// =====================================================

function validateRequired(array $fields, array $data): array {
    $errors = [];
    foreach ($fields as $field => $label) {
        if (empty($data[$field]) && $data[$field] !== '0') {
            $errors[$field] = "$label is required.";
        }
    }
    return $errors;
}

function validatePositiveNumber($value, string $label): string {
    if (!is_numeric($value) || (float)$value < 0) {
        return "$label must be a non-negative number.";
    }
    return '';
}

// =====================================================
// FILE UPLOAD
// =====================================================

/**
 * Upload image
 */
function uploadImage(array $file, string $destination, string $prefix = '') {

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    if ($file['size'] > MAX_FILE_SIZE) {
        return false;
    }

    if (!in_array($file['type'], ALLOWED_IMAGE_TYPES)) {
        return false;
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);

    $filename = $prefix . uniqid() . '.' . strtolower($ext);

    $path = rtrim($destination, '/') . '/' . $filename;

    if (move_uploaded_file($file['tmp_name'], $path)) {
        return $filename;
    }

    return false;
}
// =====================================================
// MOBILE NUMBER HELPERS
// =====================================================

/**
 * Validate that a mobile/phone number contains exactly 10 digits
 */
function isValidMobile(?string $mobile): bool {
    if ($mobile === null || trim($mobile) === '') return false;
    return (bool)preg_match('/^[0-9]{10}$/', trim($mobile));
}

/**
 * Clean a mobile number string to digits only, up to 10 characters
 */
function cleanMobile(?string $mobile): string {
    if ($mobile === null) return '';
    return substr(preg_replace('/\D/', '', $mobile), 0, 10);
}

// =====================================================
// JSON RESPONSE HELPERS (for API endpoints)
// =====================================================

function jsonSuccess($data = null, string $message = 'Success') {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => $message, 'data' => $data]);
    exit;
}

function jsonError(string $message = 'Error', int $code = 400, $data = null) {
    header('Content-Type: application/json');
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message, 'data' => $data]);
    exit;
}

/**
 * Get active business profile: 'unnatti' or 'fashion_kingdom'
 * Persisted in session and strictly maintained until explicitly changed via ?profile= from the dropdown
 */
function getActiveProfile(): string {
    // Explicit profile switch from dropdown menu
    if (isset($_GET['profile'])) {
        $profile = ($_GET['profile'] === 'fashion_kingdom') ? 'fashion_kingdom' : 'unnatti';
        $_SESSION['active_profile'] = $profile;
        return $profile;
    }

    // If active profile is already stored in session, strictly preserve it
    if (!empty($_SESSION['active_profile'])) {
        return $_SESSION['active_profile'];
    }

    // Default fallback only on initial fresh session
    $currentPath = $_SERVER['PHP_SELF'] ?? '';
    if (strpos($currentPath, '/admin/purchases') !== false || strpos($currentPath, '/admin/suppliers') !== false) {
        $_SESSION['active_profile'] = 'fashion_kingdom';
        return 'fashion_kingdom';
    }

    $_SESSION['active_profile'] = 'unnatti';
    return 'unnatti';
}
