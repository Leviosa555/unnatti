<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireAdmin();

$activeProfile = getActiveProfile();
if ($activeProfile === 'fashion_kingdom') {
    $q = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: ' . APP_URL . '/admin/purchases/material_movements.php' . $q);
    exit;
}

$variantId = (int)($_GET['variant_id'] ?? 0);
$search    = sanitize($_GET['search'] ?? '');
$type      = sanitize($_GET['type'] ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));

$variant = $variantId ? db()->fetchOne(
    "SELECT pv.*, p.name AS product_name FROM product_variants pv JOIN products p ON pv.product_id=p.id WHERE pv.id=?",
    [$variantId]
) : null;

// Only finished garments/products in shop section
$where = ["(p.item_type = 'product' OR p.item_type IS NULL)"];
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

$total = db()->fetchOne("SELECT COUNT(*) AS c FROM stock_movements sm JOIN product_variants pv ON sm.variant_id=pv.id JOIN products p ON pv.product_id=p.id WHERE $w", $params)['c'];
$pagination = paginate($total, $page, 30);

$movements = db()->fetchAll(
    "SELECT sm.*, pv.sku, p.name AS product_name, p.brand, pv.size, u.full_name AS employee
     FROM stock_movements sm
     JOIN product_variants pv ON sm.variant_id=pv.id
     JOIN products p ON pv.product_id=p.id
     LEFT JOIN users u ON sm.created_by=u.id
     WHERE $w ORDER BY sm.id DESC LIMIT 30 OFFSET {$pagination['offset']}",
    $params
);

$pageTitle  = 'Product Stock Movements'; 
$activePage = 'products';
$breadcrumb = [['label' => 'Shop Products', 'url' => APP_URL . '/admin/products/index.php'], ['label' => 'Stock Movements']];
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title"><i class="fas fa-exchange-alt text-primary" style="margin-right:10px"></i>Shop Product Movements</h1>
    <?php if ($variant): ?>
      <p class="page-subtitle"><?= e($variant['product_name']) ?> [<?= e($variant['sku']) ?>] &bull; Current Stock: <strong><?= $variant['current_stock'] ?> <?= e($variant['size']) ?></strong></p>
    <?php else: ?>
      <p class="page-subtitle">Complete audit trail of sales deductions, stock additions &amp; adjustments for ready-to-sell garments</p>
    <?php endif; ?>
  </div>
  <div class="page-header-right">
    <a href="<?= APP_URL ?>/admin/products/index.php" class="btn btn-light"><i class="fas fa-arrow-left"></i> Shop Products</a>
    <a href="<?= APP_URL ?>/admin/products/add.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add Product</a>
  </div>
</div>

<div class="card">
  <!-- Filters bar -->
  <form method="GET" class="table-filters">
    <?php if ($variantId): ?><input type="hidden" name="variant_id" value="<?= $variantId ?>"><?php endif; ?>
    <input type="text" name="search" class="form-control" placeholder="Search SKU, material, notes..." value="<?= e($search) ?>">
    <select name="type" class="form-select">
      <option value="">All Movement Types</option>
      <option value="purchase" <?= $type==='purchase'?'selected':'' ?>>➕ Purchase (Supplier Inward)</option>
      <option value="adjustment" <?= $type==='adjustment'?'selected':'' ?>>⚙️ Adjustment (Sampling / Wastage)</option>
      <option value="sale" <?= $type==='sale'?'selected':'' ?>>➖ Sale (Customer Bill)</option>
      <option value="return" <?= $type==='return'?'selected':'' ?>>↩️ Return</option>
    </select>
    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Filter</button>
    <a href="<?= APP_URL ?>/admin/stock/movements.php<?= $variantId?'?variant_id='.$variantId:'' ?>" class="btn btn-light btn-sm"><i class="fas fa-times"></i> Reset</a>
    <button type="button" class="btn btn-light btn-sm" onclick="exportTableToCSV('movTable','stock_movements')" style="margin-left:auto"><i class="fas fa-file-csv"></i> Export CSV</button>
  </form>

  <div class="table-wrapper">
    <table id="movTable">
      <thead>
        <tr>
          <th>Date/Time</th>
          <th>SKU</th>
          <th>Garment / Product</th>
          <th class="text-center">Size</th>
          <th class="text-center">Type</th>
          <th class="text-center">Qty Change</th>
          <th class="text-center">Before</th>
          <th class="text-center">After</th>
          <th>Reference &amp; Notes</th>
          <th>Logged By</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($movements as $m): ?>
        <tr>
          <td style="font-size:0.8rem"><?= formatDateTime($m['created_at']) ?></td>
          <td><span class="sku-badge"><?= e($m['sku']) ?></span></td>
          <td style="font-weight:600"><?= e($m['product_name']) ?></td>
          <td class="text-center"><span class="badge badge-info"><?= e($m['size']) ?></span></td>
          <td class="text-center">
            <?php if ($m['movement_type'] === 'purchase'): ?>
              <span class="badge badge-success">Purchase (Inward)</span>
            <?php elseif ($m['movement_type'] === 'adjustment'): ?>
              <span class="badge badge-warning">Adjustment</span>
            <?php elseif ($m['movement_type'] === 'sale'): ?>
              <span class="badge badge-primary">Sale</span>
            <?php elseif ($m['movement_type'] === 'return'): ?>
              <span class="badge badge-secondary">Return</span>
            <?php else: ?>
              <span class="badge badge-light"><?= ucfirst(e($m['movement_type'])) ?></span>
            <?php endif; ?>
          </td>
          <td class="text-center <?= $m['qty_change'] > 0 ? 'text-success' : 'text-danger' ?>" style="font-weight:700">
            <?= $m['qty_change'] > 0 ? '+' : '' ?><?= $m['qty_change'] ?>
          </td>
          <td class="text-center text-muted"><?= $m['qty_before'] ?></td>
          <td class="text-center text-bold"><?= $m['qty_after'] ?></td>
          <td style="font-size:0.82rem"><?= e($m['notes'] ?? '—') ?></td>
          <td style="font-size:0.82rem"><?= e($m['employee'] ?? 'System') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($movements)): ?>
        <tr><td colspan="10"><div class="empty-state"><i class="fas fa-exchange-alt"></i><h4>No movements found</h4></div></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pagination['total_pages'] > 1): ?>
  <div class="card-footer">
    <?php 
    $qParams = array_filter(['variant_id' => $variantId, 'search' => $search, 'type' => $type]);
    $baseUrl = APP_URL.'/admin/stock/movements.php?' . http_build_query($qParams);
    echo renderPagination($pagination, $baseUrl); 
    ?>
  </div>
  <?php endif; ?>
</div>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
