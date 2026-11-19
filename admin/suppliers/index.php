<?php
/**
 * Redirect Suppliers Directory to unified Supplier Bills & Directory Hub
 */
require_once dirname(dirname(__DIR__)) . '/config/config.php';
$search = $_GET['search'] ?? '';
$target = APP_URL . '/admin/purchases/index.php?tab=suppliers' . ($search ? '&supplier_search=' . urlencode($search) : '');
header('Location: ' . $target);
exit;


