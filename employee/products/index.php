<?php
/**
 * Employee: Shop Products & Live Stock
 * Allows staff to view garment catalog, sizes, available inventory, and MRP without exposing store valuation.
 */
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireLogin();

// Redirect admin to admin product list
if (isAdmin()) {
    header('Location: ' . APP_URL . '/admin/products/index.php');
    exit;
}

$pageTitle  = 'Shop Products';
$activePage = 'products';
$breadcrumb = [['label' => 'Products', 'url' => APP_URL . '/employee/products/index.php'], ['label' => 'Shop Products']];

// ── Filters ───────────────────────────────────────────────
$search   = sanitize($_GET['search'] ?? '');
$catId    = (int)($_GET['category'] ?? 0);
$filter   = sanitize($_GET['filter'] ?? 'all'); // all, low_stock, out_of_stock
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = DEFAULT_PAGE_SIZE;

// Base condition: only finished boutique products (garments)
$where  = [
    "pv.status = 'active'",
    "p.status = 'active'",
    "(p.item_type = 'product' OR p.item_type IS NULL)"
];
$params = [];

if ($search !== '') {
    $where[]  = '(pv.sku LIKE ? OR p.name LIKE ? OR p.brand LIKE ?)';
    $s        = "%$search%";
    $params   = array_merge($params, [$s, $s, $s]);
}
if ($catId > 0) {
    $where[]  = 'p.category_id = ?';
    $params[] = $catId;
}
if ($filter === 'low_stock') {
    $where[] = 'pv.current_stock > 0 AND pv.current_stock <= pv.min_stock_alert';
} elseif ($filter === 'out_of_stock') {
    $where[] = 'pv.current_stock = 0';
}

$whereStr = implode(' AND ', $where);

// Total matching rows
$total = db()->fetchOne(
    "SELECT COUNT(*) AS c 
     FROM product_variants pv 
     JOIN products p ON pv.product_id = p.id 
     WHERE $whereStr", 
    $params
)['c'] ?? 0;

$pagination = paginate($total, $page, $perPage);

// Fetch products & variants with live stock
$stocks = db()->fetchAll(
    "SELECT pv.id AS variant_id, pv.sku, pv.size, pv.current_stock, pv.min_stock_alert,
            pv.selling_price, pv.tag_price,
            p.id AS product_id, p.name AS product_name, p.brand, p.image,
            c.name AS category_name,
            COALESCE((SELECT SUM(si.qty) 
                      FROM sale_items si 
                      JOIN sales s ON si.sale_id = s.id 
                      WHERE si.variant_id = pv.id AND s.status != 'cancelled'), 0) AS total_sold
     FROM product_variants pv
     JOIN products p ON pv.product_id = p.id
     LEFT JOIN categories c ON p.category_id = c.id
     WHERE $whereStr
     ORDER BY pv.current_stock ASC, p.name ASC
     LIMIT $perPage OFFSET {$pagination['offset']}",
    $params
);

// Categories for filter
$categories = db()->fetchAll("SELECT id, name FROM categories WHERE parent_id IS NULL AND status = 'active' ORDER BY name");

// KPIs for Finished Goods (Excluding store valuation KPI)
$kpiWhere = "pv.status = 'active' AND p.status = 'active' AND (p.item_type = 'product' OR p.item_type IS NULL)";
$totalSkus = db()->fetchOne("SELECT COUNT(*) AS c FROM product_variants pv JOIN products p ON pv.product_id = p.id WHERE $kpiWhere")['c'] ?? 0;
$lowCount  = db()->fetchOne("SELECT COUNT(*) AS c FROM product_variants pv JOIN products p ON pv.product_id = p.id WHERE $kpiWhere AND pv.current_stock > 0 AND pv.current_stock <= pv.min_stock_alert")['c'] ?? 0;
$outCount  = db()->fetchOne("SELECT COUNT(*) AS c FROM product_variants pv JOIN products p ON pv.product_id = p.id WHERE $kpiWhere AND pv.current_stock = 0")['c'] ?? 0;

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<script>window.APP_URL = '<?= APP_URL ?>';</script>

<!-- Page Header -->
<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title"><i class="fas fa-tshirt text-primary" style="margin-right:10px"></i>Shop Products</h1>
    <p class="page-subtitle">Ready-to-sell shirts, kurtas, dresses &amp; boutique outfits with live stock</p>
  </div>
  <div class="page-header-right">
    <a href="<?= APP_URL ?>/employee/products/add.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add New Product</a>
  </div>
</div>

<!-- KPI Summary Cards (Valuation strictly omitted for employee) -->
<div class="stats-grid" style="grid-template-columns: repeat(3, 1fr); margin-bottom: 20px;">
  <div class="stat-card stat-card-primary">
    <div class="stat-icon"><i class="fas fa-tshirt"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($totalSkus) ?></div>
      <div class="stat-label">Total Ready Garments</div>
      <div class="stat-trend up"><i class="fas fa-boxes"></i> Active variants</div>
    </div>
  </div>
  <div class="stat-card stat-card-warning">
    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($lowCount) ?></div>
      <div class="stat-label">Low Stock Alert</div>
      <div class="stat-trend"><i class="fas fa-bell"></i> Need restocking</div>
    </div>
  </div>
  <div class="stat-card stat-card-danger">
    <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($outCount) ?></div>
      <div class="stat-label">Out of Stock</div>
      <div class="stat-trend down"><i class="fas fa-ban"></i> 0 available</div>
    </div>
  </div>
</div>

<div class="card">
  <!-- Status Filter Tabs -->
  <div style="padding:14px 20px;border-bottom:1px solid #e2e8f0;display:flex;gap:8px;background:#fafbfc">
    <a href="?filter=all<?= $search ? '&search=' . urlencode($search) : '' ?><?= $catId ? '&category=' . $catId : '' ?>" 
       class="btn btn-sm <?= $filter === 'all' ? 'btn-primary' : 'btn-light' ?>">
      All Garments (<?= number_format($totalSkus) ?>)
    </a>
    <a href="?filter=low_stock<?= $search ? '&search=' . urlencode($search) : '' ?><?= $catId ? '&category=' . $catId : '' ?>" 
       class="btn btn-sm <?= $filter === 'low_stock' ? 'btn-warning' : 'btn-light' ?>">
      <i class="fas fa-exclamation-triangle"></i> Low Stock (<?= number_format($lowCount) ?>)
    </a>
    <a href="?filter=out_of_stock<?= $search ? '&search=' . urlencode($search) : '' ?><?= $catId ? '&category=' . $catId : '' ?>" 
       class="btn btn-sm <?= $filter === 'out_of_stock' ? 'btn-danger' : 'btn-light' ?>">
      <i class="fas fa-times-circle"></i> Out of Stock (<?= number_format($outCount) ?>)
    </a>
  </div>

  <!-- Search & Filters -->
  <form method="GET" class="table-filters">
    <input type="hidden" name="filter" value="<?= e($filter) ?>">
    <input type="text" name="search" class="form-control" placeholder="Search SKU, product name..." value="<?= e($search) ?>" style="min-width:240px">
    <select name="category" class="form-select">
      <option value="">All Categories</option>
      <?php foreach ($categories as $cat): ?>
      <option value="<?= $cat['id'] ?>" <?= $catId == $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
    <a href="<?= APP_URL ?>/employee/products/index.php" class="btn btn-light btn-sm"><i class="fas fa-times"></i> Clear</a>
  </form>

  <!-- Combined Shop Products & Stock Table (Valuation column omitted for employee) -->
  <div class="table-wrapper">
    <table id="shopProductsTable">
      <thead>
        <tr>
          <th>SKU</th>
          <th>Product / Garment</th>
          <th>Category</th>
          <th class="text-center">Size / Unit</th>
          <th class="text-center">Current Stock</th>
          <th class="text-center">Min Alert</th>
          <th class="text-right">Price / MRP</th>
          <th class="text-center">Total Sold</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($stocks)): ?>
        <tr>
          <td colspan="8">
            <div class="empty-state">
              <i class="fas fa-tshirt"></i>
              <h4>No shop products found</h4>
              <p>Add your first ready-to-sell shirt, kurta, or dress to get started.</p>
              <a href="<?= APP_URL ?>/employee/products/add.php" class="btn btn-primary mt-3"><i class="fas fa-plus"></i> Add Product</a>
            </div>
          </td>
        </tr>
        <?php else: foreach ($stocks as $s): ?>
        <?php
          $rowClass = '';
          if ($s['current_stock'] == 0) {
              $rowClass = 'table-row-danger';
          } elseif ($s['current_stock'] <= $s['min_stock_alert']) {
              $rowClass = 'table-row-warning';
          }
        ?>
        <tr class="<?= $rowClass ?>">
          <td><span class="sku-badge"><?= e($s['sku']) ?></span></td>
          <td>
            <div class="d-flex align-center gap-3">
              <?php if (!empty($s['image']) && file_exists(UPLOAD_PATH . 'products/' . $s['image'])): ?>
                <img src="<?= APP_URL ?>/uploads/products/<?= e($s['image']) ?>" 
                     style="width:36px;height:36px;border-radius:6px;object-fit:cover;border:1px solid #e2e8f0;flex-shrink:0" 
                     alt="<?= e($s['product_name']) ?>">
              <?php else: ?>
                <div style="width:36px;height:36px;border-radius:6px;background:#eff6ff;display:flex;align-items:center;justify-content:center;color:#2563eb;flex-shrink:0">
                  <i class="fas fa-tshirt" style="font-size:0.85rem"></i>
                </div>
              <?php endif; ?>
              <div>
                <strong><?= e($s['product_name']) ?></strong>
                <?php if (!empty($s['brand'])): ?>
                  <span class="text-muted" style="font-size:0.75rem"> &bull; <?= e($s['brand']) ?></span>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td><?= e($s['category_name'] ?? '—') ?></td>
          <td class="text-center"><span class="badge badge-light" style="font-weight:600"><?= e($s['size']) ?></span></td>
          <td class="text-center">
            <?php if ($s['current_stock'] == 0): ?>
              <span class="badge badge-danger">0 Out</span>
            <?php elseif ($s['current_stock'] <= $s['min_stock_alert']): ?>
              <span class="badge badge-warning"><?= $s['current_stock'] ?> Low</span>
            <?php else: ?>
              <span class="badge badge-success"><?= $s['current_stock'] ?></span>
            <?php endif; ?>
          </td>
          <td class="text-center text-muted"><?= $s['min_stock_alert'] ?></td>
          <td class="text-right text-bold" style="color:#2563eb;font-size:0.95rem">
            <?= formatCurrency($s['tag_price'] ?: $s['selling_price']) ?>
          </td>
          <td class="text-center text-muted"><?= number_format($s['total_sold']) ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pagination['pages'] > 1): ?>
  <div class="pagination">
    <?php for ($i = 1; $i <= $pagination['pages']; $i++): ?>
      <a href="?page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $catId ? '&category=' . $catId : '' ?><?= $filter ? '&filter=' . $filter : '' ?>" 
         class="page-link <?= $i == $page ? 'active' : '' ?>">
        <?= $i ?>
      </a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
