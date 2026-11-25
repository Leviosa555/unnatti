<?php
/**
 * Admin: Raw Material Stock Movements Ledger (Supplier Side)
 * Complete audit trail of fabric inward receipts, rolls, cutting consumption & mill material adjustments
 */
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireAdmin();

$variantId = (int)($_GET['variant_id'] ?? 0);
$search    = sanitize($_GET['search'] ?? '');
$type      = sanitize($_GET['type'] ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));

$variant = $variantId ? db()->fetchOne(
    "SELECT pv.*, p.name AS material_name, p.brand AS material_type 
     FROM product_variants pv 
     JOIN products p ON pv.product_id = p.id 
     WHERE pv.id = ? AND p.item_type = 'material'",
    [$variantId]
) : null;

// Only raw materials from supplier
$where = ["p.item_type = 'material'"];
$params = [];

if ($variantId) {
    $where[] = 'sm.variant_id = ?';
    $params[] = $variantId;
}

if ($search) {
    $where[] = '(pv.sku LIKE ? OR p.name LIKE ? OR sm.notes LIKE ?)';
    $s = "%$search%";
    $params = array_merge($params, [$s, $s, $s]);
}

if ($type && in_array($type, ['purchase', 'adjustment', 'sale', 'return', 'cancellation'])) {
    $where[] = 'sm.movement_type = ?';
    $params[] = $type;
}

$w = implode(' AND ', $where);

$total = db()->fetchOne(
    "SELECT COUNT(*) AS c 
     FROM stock_movements sm 
     JOIN product_variants pv ON sm.variant_id = pv.id 
     JOIN products p ON pv.product_id = p.id 
     WHERE $w", 
    $params
)['c'] ?? 0;

$pagination = paginate($total, $page, 30);

$movements = db()->fetchAll(
    "SELECT sm.*, pv.sku, p.name AS material_name, p.brand AS material_type, pv.size AS unit_measure, u.full_name AS employee
     FROM stock_movements sm
     JOIN product_variants pv ON sm.variant_id = pv.id
     JOIN products p ON pv.product_id = p.id
     LEFT JOIN users u ON sm.created_by = u.id
     WHERE $w 
     ORDER BY sm.id DESC 
     LIMIT 30 OFFSET {$pagination['offset']}",
    $params
);

$pageTitle  = 'Material Stock Movements';
$activePage = 'purchases';
$breadcrumb = [
    ['label' => 'Supplier'],
    ['label' => 'Material Stock & Inventory', 'url' => APP_URL . '/admin/purchases/materials.php'],
    ['label' => 'Material Movements']
];

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<script>window.APP_URL = '<?= APP_URL ?>';</script>

<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title"><i class="fas fa-scroll text-primary" style="margin-right:10px"></i>Material Stock Movements</h1>
    <?php if ($variant): ?>
      <p class="page-subtitle">
        <?= e($variant['material_name']) ?> [<?= e($variant['sku']) ?>] &bull; Current Balance: 
        <strong><?= $variant['current_stock'] ?> <?= e($variant['size'] ?: 'Meters') ?></strong>
      </p>
    <?php else: ?>
      <p class="page-subtitle">Complete audit trail of raw fabric inward receipts, rolls, cutting consumption &amp; adjustments</p>
    <?php endif; ?>
  </div>
  <div class="page-header-right">
    <a href="<?= APP_URL ?>/admin/purchases/materials.php" class="btn btn-light"><i class="fas fa-arrow-left"></i> Material Inventory</a>
    <a href="<?= APP_URL ?>/admin/purchases/add.php" class="btn btn-primary"><i class="fas fa-plus"></i> Inward Raw Material</a>
  </div>
</div>

<div class="card">
  <!-- Filters bar -->
  <form method="GET" class="table-filters">
    <?php if ($variantId): ?><input type="hidden" name="variant_id" value="<?= $variantId ?>"><?php endif; ?>
    <input type="text" name="search" class="form-control" placeholder="Search SKU, fabric, bill ref..." value="<?= e($search) ?>" style="min-width:240px">
    <select name="type" class="form-select">
      <option value="">All Movement Types</option>
      <option value="purchase" <?= $type === 'purchase' ? 'selected' : '' ?>>➕ Purchase (Supplier Inward)</option>
      <option value="adjustment" <?= $type === 'adjustment' ? 'selected' : '' ?>>⚙️ Adjustment (Sampling / Wastage / Cutting)</option>
      <option value="sale" <?= $type === 'sale' ? 'selected' : '' ?>>➖ Consumption / Direct Sale</option>
      <option value="return" <?= $type === 'return' ? 'selected' : '' ?>>↩️ Return to Supplier</option>
    </select>
    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filter</button>
    <a href="<?= APP_URL ?>/admin/purchases/material_movements.php<?= $variantId ? '?variant_id=' . $variantId : '' ?>" class="btn btn-light btn-sm"><i class="fas fa-times"></i> Reset</a>
    
    <div style="margin-left:auto">
      <button type="button" class="btn btn-light btn-sm" onclick="exportTableToCSV('materialMovTable','material_stock_movements')">
        <i class="fas fa-file-csv"></i> Export CSV
      </button>
    </div>
  </form>

  <div class="table-wrapper">
    <table id="materialMovTable">
      <thead>
        <tr>
          <th>Date / Time</th>
          <th>SKU</th>
          <th>Fabric / Material</th>
          <th class="text-center">Measure</th>
          <th class="text-center">Type</th>
          <th class="text-center">Qty Change</th>
          <th class="text-center">Before</th>
          <th class="text-center">After</th>
          <th>Reference &amp; Notes</th>
          <th>Logged By</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($movements)): ?>
        <tr>
          <td colspan="10">
            <div class="empty-state">
              <i class="fas fa-scroll"></i>
              <h4>No material movements recorded</h4>
              <p>Inward fabrics from suppliers or record usage adjustments to see activity here.</p>
              <a href="<?= APP_URL ?>/admin/purchases/add.php" class="btn btn-primary mt-3"><i class="fas fa-plus"></i> Inward Material</a>
            </div>
          </td>
        </tr>
        <?php else: foreach ($movements as $m): 
          $measure = $m['unit_measure'] ?: 'Meter';
        ?>
        <tr>
          <td style="font-size:0.8rem"><?= formatDateTime($m['created_at']) ?></td>
          <td><span class="sku-badge"><?= e($m['sku']) ?></span></td>
          <td>
            <div style="font-weight:600"><?= e($m['material_name']) ?></div>
            <?php if (!empty($m['material_type'])): ?>
              <div style="font-size:0.75rem;color:#7c3aed"><i class="fas fa-tag"></i> <?= e($m['material_type']) ?></div>
            <?php endif; ?>
          </td>
          <td class="text-center"><span class="badge badge-info"><?= e($measure) ?></span></td>
          <td class="text-center">
            <?php if ($m['reference_type'] === 'production_consumption'): ?>
              <span class="badge" style="background:#fce7f3;color:#be185d;border:1px solid #fbcfe8;font-weight:600"><i class="fas fa-cut"></i> Production Used</span>
            <?php elseif ($m['movement_type'] === 'purchase'): ?>
              <span class="badge badge-success">Inward Entry</span>
            <?php elseif ($m['movement_type'] === 'adjustment'): ?>
              <span class="badge badge-warning">Adjustment</span>
            <?php elseif ($m['movement_type'] === 'sale'): ?>
              <span class="badge badge-primary">Consumption</span>
            <?php elseif ($m['movement_type'] === 'return'): ?>
              <span class="badge badge-secondary">Return</span>
            <?php else: ?>
              <span class="badge badge-light"><?= ucfirst(e($m['movement_type'])) ?></span>
            <?php endif; ?>
          </td>
          <td class="text-center <?= $m['qty_change'] > 0 ? 'text-success' : 'text-danger' ?>" style="font-weight:700">
            <?= $m['qty_change'] > 0 ? '+' : '' ?><?= $m['qty_change'] ?> <?= e($measure) ?>
          </td>
          <td class="text-center text-muted"><?= $m['qty_before'] ?></td>
          <td class="text-center" style="font-weight:700;color:#0f172a"><?= $m['qty_after'] ?></td>
          <td style="font-size:0.82rem"><?= e($m['notes'] ?? '—') ?></td>
          <td style="font-size:0.82rem"><?= e($m['employee'] ?? 'System') ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pagination['total_pages'] > 1): ?>
  <div class="card-footer">
    <?php 
    $qParams = array_filter(['variant_id' => $variantId, 'search' => $search, 'type' => $type]);
    $baseUrl = APP_URL . '/admin/purchases/material_movements.php?' . http_build_query($qParams);
    echo renderPagination($pagination, $baseUrl); 
    ?>
  </div>
  <?php endif; ?>
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>

