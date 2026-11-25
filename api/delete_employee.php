<?php
/**
 * API: Delete Employee
 * Permanently deletes an employee account and safely preserves audit references
 */
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json');
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'Method not allowed.']));
}

validateCSRF();

$employeeId = (int)($_POST['employee_id'] ?? 0);

if (!$employeeId) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Employee ID is required.']));
}

$adminId = currentUserId();

// Security checks
if ($employeeId === $adminId) {
    die(json_encode(['success' => false, 'message' => 'You cannot delete your own admin account.']));
}

$emp = db()->fetchOne("SELECT id, full_name, username, role FROM users WHERE id = ?", [$employeeId]);

if (!$emp) {
    die(json_encode(['success' => false, 'message' => 'Employee not found.']));
}

if ($emp['role'] !== 'employee') {
    die(json_encode(['success' => false, 'message' => 'Only employee accounts can be deleted.']));
}

try {
    db()->beginTransaction();

    // 1. Reassign historical transactions created by this employee to active Admin to preserve financial records
    db()->execute("UPDATE sales SET created_by = ? WHERE created_by = ?", [$adminId, $employeeId]);
    db()->execute("UPDATE sales_returns SET created_by = ? WHERE created_by = ?", [$adminId, $employeeId]);
    db()->execute("UPDATE bill_cancellations SET cancelled_by = ? WHERE cancelled_by = ?", [$adminId, $employeeId]);
    db()->execute("UPDATE purchases SET created_by = ? WHERE created_by = ?", [$adminId, $employeeId]);
    db()->execute("UPDATE stock_adjustments SET created_by = ? WHERE created_by = ?", [$adminId, $employeeId]);
    db()->execute("UPDATE stock_movements SET created_by = ? WHERE created_by = ?", [$adminId, $employeeId]);
    db()->execute("UPDATE products SET created_by = ? WHERE created_by = ?", [$adminId, $employeeId]);

    // 2. Clear activity log user references
    db()->execute("UPDATE activity_logs SET user_id = NULL WHERE user_id = ?", [$employeeId]);

    // 3. Log admin audit activity
    logActivity("Deleted employee: {$emp['full_name']} (@{$emp['username']})", 'employees', $employeeId, 'user');

    // 4. Permanently delete employee from users table
    db()->execute("DELETE FROM users WHERE id = ? AND role = 'employee'", [$employeeId]);

    db()->commit();

    echo json_encode([
        'success' => true,
        'message' => "Employee '{$emp['full_name']}' has been permanently deleted from the system."
    ]);
    exit;

} catch (Exception $e) {
    if (db()->getConnection()->inTransaction()) {
        db()->rollback();
    }
    error_log("Error deleting employee {$employeeId}: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Failed to delete employee: ' . $e->getMessage()
    ]);
    exit;
}
