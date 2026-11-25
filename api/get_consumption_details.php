<?php
/**
 * API: Get material consumption details and used items
 */
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

header('Content-Type: application/json');
requireLogin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    jsonError('Invalid consumption ID.');
}

$consumption = db()->fetchOne("SELECT * FROM material_consumptions WHERE id = ?", [$id]);
if (!$consumption) {
    jsonError('Consumption record not found.');
}

$items = db()->fetchAll(
    "SELECT mci.*, pv.sku, p.name AS material_name, p.brand AS material_type
     FROM material_consumption_items mci
     JOIN product_variants pv ON mci.variant_id = pv.id
     JOIN products p ON pv.product_id = p.id
     WHERE mci.consumption_id = ?
     ORDER BY mci.id ASC",
    [$id]
);

jsonSuccess([
    'consumption' => $consumption,
    'items'       => $items
], 'Consumption details retrieved successfully.');
