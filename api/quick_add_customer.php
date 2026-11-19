<?php
/**
 * API: Quick Add Customer (from Create Bill)
 */
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Method not allowed', 405);
}

validateCSRF();

$name    = sanitize($_POST['name'] ?? '');
$mobile  = cleanMobile($_POST['mobile'] ?? '');
$email   = sanitize($_POST['email'] ?? '');
$address = sanitize($_POST['address'] ?? '');
$type    = in_array($_POST['type'] ?? '', ['retail', 'wholesale', 'walk-in']) ? $_POST['type'] : 'retail';
$gstin   = sanitize($_POST['gstin'] ?? '');
$notes   = sanitize($_POST['notes'] ?? '');

if (empty($name)) {
    jsonError('Customer name is required.');
}
if ($mobile && !isValidMobile($mobile)) {
    jsonError('Invalid mobile number (must be exactly 10 digits).');
}
if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonError('Invalid email address.');
}

if ($mobile) {
    $existing = db()->fetchOne("SELECT id, name FROM customers WHERE mobile = ?", [$mobile]);
    if ($existing) {
        jsonError("A customer named '{$existing['name']}' already exists with mobile number $mobile.");
    }
}

try {
    $id = db()->insert(
        "INSERT INTO customers (name, mobile, email, address, customer_type, gstin, notes, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)",
        [
            $name,
            $mobile ?: null,
            $email ?: null,
            $address ?: null,
            $type,
            $gstin ?: null,
            $notes ?: null,
            currentUserId()
        ]
    );

    logActivity("Added customer: $name", 'customers', $id, 'customer');

    jsonSuccess([
        'id'            => (int)$id,
        'name'          => $name,
        'mobile'        => $mobile,
        'email'         => $email,
        'address'       => $address,
        'customer_type' => $type,
        'gstin'         => $gstin,
        'notes'         => $notes
    ], 'Customer added successfully');
} catch (Exception $e) {
    jsonError('Failed to save customer: ' . $e->getMessage());
}

