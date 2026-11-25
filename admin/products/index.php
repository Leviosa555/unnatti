<?php
/**
 * Admin: Shop Products & Live Stock
 * Consolidated view of ready-to-sell boutique products (shirts, kurtas, dresses, outfits) and live stock inventory.
 */
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireAdmin();

$activeProfile = getActiveProfile();
if ($activeProfile === 'fashion_kingdom' && (!isset($_GET['profile']) || $_GET['profile'] !== 'unnatti')) {
    header('Location: ' . APP_URL . '/admin/purchases/materials.php');
    exit;
}

$pageTitle  = 'Shop Products';
$activePage = 'products';
$breadcrumb = [['label' => 'Shop Products']];

// ── Filters ───────────────────────────────────────────────
$search   = sanitize($_GET['search'] ?? '');
$catId    = (int)($_GET['category'] ?? 0);
$filter   = sanitize($_GET['filter'] ?? 'all'); // all, low_stock, out_of_stock
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = DEFAULT_PAGE_SIZE;

// Base condition: only finished boutique products (garments), excluding raw materials
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
            pv.purchase_price_with_gst, pv.selling_price,
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

// KPIs for Finished Goods
$kpiWhere = "pv.status = 'active' AND p.status = 'active' AND (p.item_type = 'product' OR p.item_type IS NULL)";
$totalSkus    = db()->fetchOne("SELECT COUNT(*) AS c FROM product_variants pv JOIN products p ON pv.product_id = p.id WHERE $kpiWhere")['c'] ?? 0;
$lowCount     = db()->fetchOne("SELECT COUNT(*) AS c FROM product_variants pv JOIN products p ON pv.product_id = p.id WHERE $kpiWhere AND pv.current_stock > 0 AND pv.current_stock <= pv.min_stock_alert")['c'] ?? 0;
$outCount     = db()->fetchOne("SELECT COUNT(*) AS c FROM product_variants pv JOIN products p ON pv.product_id = p.id WHERE $kpiWhere AND pv.current_stock = 0")['c'] ?? 0;
$totalValue   = db()->fetchOne("SELECT COALESCE(SUM(pv.purchase_price_with_gst * pv.current_stock), 0) AS v FROM product_variants pv JOIN products p ON pv.product_id = p.id WHERE $kpiWhere")['v'] ?? 0;
$totalSaleVal = db()->fetchOne("SELECT COALESCE(SUM(pv.selling_price * pv.current_stock), 0) AS v FROM product_variants pv JOIN products p ON pv.product_id = p.id WHERE $kpiWhere")['v'] ?? 0;

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<script>window.APP_URL = '<?= APP_URL ?>';</script>

<!-- Delete Confirmation Modal -->
<div class="modal-overlay" id="deleteModal">
  <div class="modal modal-sm">
    <div class="modal-header">
      <span class="modal-title"><i class="fas fa-trash text-danger"></i> Delete Product</span>
      <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
    </div>
    <div class="modal-body">
      <p style="margin-bottom:16px">Are you sure you want to delete this product?</p>
      <div id="deleteProductInfo" style="padding:12px;background:#fef2f2;border-radius:6px;border-left:3px solid #dc2626;margin-bottom:16px">
        <strong id="deleteProductName"></strong>
      </div>
      <p style="font-size:0.85rem;color:#64748b">
        <i class="fas fa-exclamation-triangle text-warning"></i> 
        This action cannot be undone. All variants, stock records and product data will be removed.
      </p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-light" onclick="closeModal('deleteModal')">Cancel</button>
      <button class="btn btn-danger" id="confirmDeleteBtn" onclick="confirmDelete()">
        <i class="fas fa-trash"></i> Delete Product
      </button>
    </div>
  </div>
</div>

<!-- Page Header -->
<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title"><i class="fas fa-tshirt text-primary" style="margin-right:10px"></i>Shop Products</h1>
    <p class="page-subtitle">Ready-to-sell shirts, kurtas, dresses &amp; complete boutique outfits with live stock</p>
  </div>
  <div class="page-header-right">
    <a href="<?= APP_URL ?>/admin/stock/movements.php" class="btn btn-light"><i class="fas fa-exchange-alt"></i> Stock Movements</a>
    <a href="<?= APP_URL ?>/admin/products/add.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add New Product</a>
  </div>
</div>

<!-- KPI Summary Cards -->
<div class="stats-grid" style="margin-bottom:20px">
  <div class="stat-card stat-card-primary">
    <div class="stat-icon"><i class="fas fa-tshirt"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($totalSkus) ?></div>
      <div class="stat-label">Total Ready SKUs</div>
    </div>
  </div>
  <div class="stat-card stat-card-warning">
    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($lowCount) ?></div>
      <div class="stat-label">Low Stock</div>
    </div>
  </div>
  <div class="stat-card stat-card-danger">
    <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($outCount) ?></div>
      <div class="stat-label">Out of Stock</div>
    </div>
  </div>
  <div class="stat-card stat-card-success">
    <div class="stat-icon"><i class="fas fa-rupee-sign"></i></div>
    <div class="stat-body">
      <div class="stat-value" style="font-size:1.1rem"><?= formatCurrency($totalSaleVal) ?></div>
      <div class="stat-label">Stock Value (MRP)</div>
      <?php if ($totalValue > 0): ?>
        <div class="stat-trend"><i class="fas fa-tag"></i> Cost: <?= formatCurrency($totalValue) ?></div>
      <?php endif; ?>
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
    <a href="<?= APP_URL ?>/admin/products/index.php" class="btn btn-light btn-sm"><i class="fas fa-times"></i> Clear</a>
    
    <div style="margin-left:auto">
      <button type="button" class="btn btn-light btn-sm" onclick="exportTableToCSV('shopProductsTable','shop_products_inventory')">
        <i class="fas fa-file-csv"></i> Export CSV
      </button>
    </div>
  </form>

  <!-- Combined Shop Products & Stock Table -->
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
          <th class="text-right">Stock Value</th>
          <th class="text-center">Total Sold</th>
          <th class="text-right no-print" style="width:115px;min-width:115px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($stocks)): ?>
        <tr>
          <td colspan="10">
            <div class="empty-state">
              <i class="fas fa-tshirt"></i>
              <h4>No shop products found</h4>
              <p>Add your first ready-to-sell shirt, kurta, or dress to get started.</p>
              <a href="<?= APP_URL ?>/admin/products/add.php" class="btn btn-primary mt-3"><i class="fas fa-plus"></i> Add Product</a>
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
                <img src="<?= APP_URL ?>/uploads/products/<?= e($s['image']) ?>" class="product-thumb" alt="">
              <?php else: ?>
                <div class="product-thumb-placeholder"><i class="fas fa-tshirt"></i></div>
              <?php endif; ?>
              <div>
                <div style="font-weight:600"><?= e($s['product_name']) ?></div>
                <?php if (!empty($s['brand'])): ?>
                  <div style="font-size:0.75rem;color:#64748b"><?= e($s['brand']) ?></div>
                <?php endif; ?>
              </div>
            </div>
          </td>
          <td style="font-size:0.85rem"><?= e($s['category_name'] ?? '—') ?></td>
          <td class="text-center">
            <span class="badge badge-light" style="font-weight:600;font-size:0.8rem"><?= e($s['size'] ?: 'Standard') ?></span>
          </td>
          <td class="text-center">
            <?php if ($s['current_stock'] == 0): ?>
              <span class="badge badge-danger" style="font-weight:700">[ 0 available ] &bull; Out</span>
            <?php elseif ($s['current_stock'] <= $s['min_stock_alert']): ?>
              <span class="badge badge-warning" style="font-weight:700">[ <?= number_format($s['current_stock']) ?> available ] &bull; Low</span>
            <?php else: ?>
              <span class="badge badge-success" style="font-weight:700;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0">[ <?= number_format($s['current_stock']) ?> available ]</span>
            <?php endif; ?>
          </td>
          <td class="text-center text-muted"><?= (int)$s['min_stock_alert'] ?></td>
          <td class="text-right font-weight-bold" style="color:#0f172a"><?= formatCurrency($s['selling_price']) ?></td>
          <td class="text-right"><strong><?= formatCurrency($s['selling_price'] * $s['current_stock']) ?></strong></td>
          <td class="text-center"><span class="badge badge-secondary" style="font-weight:600">[ <?= number_format($s['total_sold']) ?> sold ]</span></td>
          <td class="text-right no-print">
            <div class="table-actions">
              <a href="<?= APP_URL ?>/admin/products/view.php?id=<?= $s['product_id'] ?>" class="btn btn-sm btn-light" title="View Product Details"><i class="fas fa-eye"></i></a>
              <a href="<?= APP_URL ?>/admin/products/edit.php?id=<?= $s['product_id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit Product &amp; Pricing"><i class="fas fa-edit"></i></a>
              <button type="button" class="btn btn-sm btn-outline-danger" title="Delete Product" 
                      onclick="showDeleteModal(<?= $s['product_id'] ?>, '<?= addslashes(e($s['product_name'])) ?>')">
                <i class="fas fa-trash"></i>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($pagination['total_pages'] > 1): ?>
  <div class="card-footer">
    <div style="display:flex;justify-content:space-between;align-items:center">
      <small class="text-muted">Showing <?= $pagination['offset'] + 1 ?>–<?= min($pagination['offset'] + $perPage, $total) ?> of <?= $total ?> SKUs</small>
      <?php
      $baseUrl = APP_URL . '/admin/products/index.php?search=' . urlencode($search) . '&category=' . $catId . '&filter=' . urlencode($filter);
      echo renderPagination($pagination, $baseUrl);
      ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
let deleteProductId = null;

function showDeleteModal(productId, productName) {
  deleteProductId = productId;
  document.getElementById('deleteProductName').textContent = productName;
  openModal('deleteModal');
}

function confirmDelete() {
  if (!deleteProductId) return;
  
  const btn = document.getElementById('confirmDeleteBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner spinner-sm"></span> Deleting...';
  
  const formData = new FormData();
  formData.append('product_id', deleteProductId);
  formData.append('csrf_token', '<?= csrfToken() ?>');
  
  fetch(APP_URL + '/api/delete_product.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      showToast(data.message || 'Product deleted successfully.', 'success');
      closeModal('deleteModal');
      setTimeout(() => window.location.reload(), 700);
    } else {
      showToast(data.message || 'Failed to delete product.', 'error');
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-trash"></i> Delete Product';
    }
  })
  .catch(err => {
    showToast('Network error. Could not delete product.', 'error');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-trash"></i> Delete Product';
  });
}
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
