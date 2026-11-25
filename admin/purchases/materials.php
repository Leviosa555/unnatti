<?php
/**
 * Admin: Raw Material Stock & Inventory (Supplier Side - Fashion Kingdom)
 * Manages raw fabrics (by meter), rolls, trims, uniform materials and mill supplies
 */
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireAdmin();

// ── Filters & Pagination ──────────────────────────────────
$search  = sanitize($_GET['search'] ?? '');
$catId   = (int)($_GET['category'] ?? 0);
$filter  = sanitize($_GET['filter'] ?? 'all'); // all, low_stock, out_of_stock
$page    = max(1, (int)($_GET['page'] ?? 1));

$where = ["pv.status = 'active'", "p.status = 'active'", "p.item_type = 'material'"]; 
$params = [];

if ($search) { 
    $where[] = '(pv.sku LIKE ? OR p.name LIKE ? OR p.brand LIKE ? OR EXISTS(SELECT 1 FROM purchase_items pi JOIN purchases pr ON pi.purchase_id=pr.id LEFT JOIN suppliers s ON pr.supplier_id=s.id WHERE pi.variant_id=pv.id AND s.name LIKE ?))'; 
    $s = "%$search%"; 
    $params = array_merge($params, [$s, $s, $s, $s]); 
}
if ($catId)  { 
    $where[] = 'p.category_id = ?'; 
    $params[] = $catId; 
}
if ($filter === 'low_stock')  { 
    $where[] = 'pv.current_stock > 0 AND pv.current_stock <= pv.min_stock_alert'; 
}
if ($filter === 'out_of_stock') { 
    $where[] = 'pv.current_stock = 0'; 
}
$w = implode(' AND ', $where);

$total = (int)(db()->fetchOne("SELECT COUNT(*) AS c FROM product_variants pv JOIN products p ON pv.product_id=p.id WHERE $w", $params)['c'] ?? 0);
$pagination = paginate($total, $page);

// Fetch all individual materials with latest supplier, total inwarded, and total consumed
$materials = db()->fetchAll(
    "SELECT pv.id, pv.sku, pv.size, pv.current_stock, pv.min_stock_alert,
            pv.purchase_price_with_gst, pv.selling_price, pv.purchase_price, pv.purchase_gst_pct,
            p.id AS product_id, p.name AS product_name, p.brand AS material_type,
            COALESCE(p.garment_category, 'General') AS garment_category,
            c.name AS category_name,
            COALESCE((
                SELECT s.name 
                FROM purchase_items pi 
                JOIN purchases pr ON pi.purchase_id = pr.id 
                LEFT JOIN suppliers s ON pr.supplier_id = s.id 
                WHERE pi.variant_id = pv.id AND pr.status = 'active'
                ORDER BY pr.purchase_date DESC, pr.id DESC 
                LIMIT 1
            ), 'Direct Mill Inward') AS supplier_name,
            COALESCE((SELECT SUM(pi.qty) FROM purchase_items pi JOIN purchases pr ON pi.purchase_id=pr.id WHERE pi.variant_id=pv.id AND pr.status='active'),0) AS total_inwarded,
            COALESCE((SELECT SUM(ABS(sm.qty_change)) FROM stock_movements sm WHERE sm.variant_id=pv.id AND (sm.reference_type='production_consumption' OR (sm.movement_type='adjustment' AND sm.qty_change < 0) OR sm.movement_type='sale')),0) AS total_consumed
     FROM product_variants pv
     JOIN products p ON pv.product_id=p.id
     LEFT JOIN categories c ON p.category_id=c.id
     WHERE $w 
     ORDER BY pv.current_stock DESC, p.name ASC",
    $params
);

// Process materials formatting
foreach ($materials as &$m) {
    $rawSize = trim($m['size'] ?? '');
    $isFabric = (
        stripos($m['product_name'], 'Fabric') !== false || 
        stripos($m['product_name'], 'Cloth') !== false || 
        stripos($m['category_name'] ?? '', 'Fabric') !== false || 
        stripos($rawSize, 'Meter') !== false ||
        stripos($rawSize, 'Color') !== false
    );
    $isButton = (
        stripos($m['product_name'], 'Button') !== false || 
        stripos($m['sku'], 'BTN') !== false || 
        stripos($m['material_type'] ?? '', 'Button') !== false
    );

    $unit = 'Meter';
    $spec = '';
    if (stripos($rawSize, 'Color:') !== false) {
        $spec = $rawSize;
        $unit = $isButton ? 'Pcs' : 'Meter';
    } elseif ($rawSize !== '' && $rawSize !== 'Meter') {
        $unit = $rawSize;
    }

    $m['is_fabric'] = $isFabric;
    $m['is_button'] = $isButton;
    $m['unit_label'] = $unit;
    $m['spec_label'] = $spec;
    $m['total_valuation'] = (float)$m['current_stock'] * (float)$m['purchase_price_with_gst'];
}
unset($m);

// Store-wide Fabric KPI & Available Fabrics list for Modal
$fabricDbItems = db()->fetchAll("
    SELECT pv.id, pv.sku, pv.size, pv.current_stock, pv.min_stock_alert, pv.purchase_price_with_gst,
           p.id AS product_id, p.name AS product_name, p.brand AS material_type,
           c.name AS category_name,
           COALESCE((
               SELECT s.name 
               FROM purchase_items pi 
               JOIN purchases pr ON pi.purchase_id = pr.id 
               LEFT JOIN suppliers s ON pr.supplier_id = s.id 
               WHERE pi.variant_id = pv.id AND pr.status = 'active'
               ORDER BY pr.purchase_date DESC, pr.id DESC 
               LIMIT 1
           ), 'Direct Mill Inward') AS supplier_name
    FROM product_variants pv
    JOIN products p ON pv.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE pv.status = 'active' AND p.status = 'active' AND p.item_type = 'material'
      AND (
          pv.size IN ('Meter', 'Roll', 'Yard') 
          OR pv.size LIKE '%Color%' 
          OR p.name LIKE '%Fabric%' 
          OR p.name LIKE '%Cloth%' 
          OR c.name LIKE '%Fabric%'
      )
    ORDER BY pv.current_stock DESC, p.name ASC
");

$totalFabricMeters = 0;
$totalFabricValuation = 0;
foreach ($fabricDbItems as &$f) {
    $fRaw = trim($f['size'] ?? '');
    $fSpec = (stripos($fRaw, 'Color:') !== false) ? $fRaw : '';
    $f['spec_label'] = $fSpec;
    $fVal = (float)$f['current_stock'] * (float)$f['purchase_price_with_gst'];
    $f['valuation'] = $fVal;
    $totalFabricMeters += (float)$f['current_stock'];
    $totalFabricValuation += $fVal;
}
unset($f);

// Store-wide Buttons KPI & Buttons list for Modal
$buttonDbItems = db()->fetchAll("
    SELECT pv.id, pv.sku, pv.size, pv.current_stock, pv.min_stock_alert, pv.purchase_price_with_gst,
           p.id AS product_id, p.name AS product_name, p.brand AS material_type,
           c.name AS category_name,
           COALESCE((
               SELECT s.name 
               FROM purchase_items pi 
               JOIN purchases pr ON pi.purchase_id = pr.id 
               LEFT JOIN suppliers s ON pr.supplier_id = s.id 
               WHERE pi.variant_id = pv.id AND pr.status = 'active'
               ORDER BY pr.purchase_date DESC, pr.id DESC 
               LIMIT 1
           ), 'Direct Mill Inward') AS supplier_name
    FROM product_variants pv
    JOIN products p ON pv.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE pv.status = 'active' AND p.status = 'active' AND p.item_type = 'material'
      AND (
          p.name LIKE '%Button%' 
          OR pv.sku LIKE '%BTN%' 
          OR p.brand LIKE '%Button%'
      )
    ORDER BY pv.current_stock DESC, p.name ASC
");

$totalButtonPieces = 0;
$totalButtonValuation = 0;
foreach ($buttonDbItems as &$b) {
    $bVal = (float)$b['current_stock'] * (float)$b['purchase_price_with_gst'];
    $b['valuation'] = $bVal;
    $totalButtonPieces += (int)$b['current_stock'];
    $totalButtonValuation += $bVal;
}
unset($b);

// Materials available in stock for the Rough Production Estimator (Buttons, Zips, Hooks, Trims, etc.)
$estimatorAvailableMaterials = db()->fetchAll("
    SELECT pv.id, pv.sku, pv.size, pv.current_stock,
           p.id AS product_id, p.name AS product_name, p.brand AS material_type,
           COALESCE(c.name, 'Trims & Accessories') AS category_name
    FROM product_variants pv
    JOIN products p ON pv.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE pv.status = 'active' AND p.status = 'active' AND p.item_type = 'material'
    ORDER BY pv.current_stock DESC, p.name ASC
");

// Counts & General KPIs
$categories   = db()->fetchAll("SELECT id, name FROM categories WHERE parent_id IS NULL AND status='active' ORDER BY name");
$lowCount     = (int)(db()->fetchOne("SELECT COUNT(*) AS c FROM product_variants pv JOIN products p ON pv.product_id=p.id WHERE pv.status='active' AND p.status='active' AND p.item_type='material' AND pv.current_stock > 0 AND pv.current_stock <= pv.min_stock_alert")['c'] ?? 0);
$outCount     = (int)(db()->fetchOne("SELECT COUNT(*) AS c FROM product_variants pv JOIN products p ON pv.product_id=p.id WHERE pv.status='active' AND p.status='active' AND p.item_type='material' AND pv.current_stock=0")['c'] ?? 0);
$totalValue   = (float)(db()->fetchOne("SELECT COALESCE(SUM(pv.purchase_price_with_gst * pv.current_stock),0) AS v FROM product_variants pv JOIN products p ON pv.product_id=p.id WHERE pv.status='active' AND p.status='active' AND p.item_type='material'")['v'] ?? 0);
$totalUnits   = (float)(db()->fetchOne("SELECT COALESCE(SUM(pv.current_stock),0) AS v FROM product_variants pv JOIN products p ON pv.product_id=p.id WHERE pv.status='active' AND p.status='active' AND p.item_type='material'")['v'] ?? 0);

// Supplier Inward KPIs
$totalPurchaseVal   = (float)(db()->fetchOne("SELECT COALESCE(SUM(total_amount),0) AS c FROM purchases WHERE status='active'")['c'] ?? 0);
$totalSupplierBills = (int)(db()->fetchOne("SELECT COUNT(*) AS c FROM purchases WHERE status='active'")['c'] ?? 0);
$totalSuppliers     = (int)(db()->fetchOne("SELECT COUNT(*) AS c FROM suppliers WHERE status='active'")['c'] ?? 0);

// Month inward purchases
$monthPurchaseData  = db()->fetchOne("SELECT COALESCE(SUM(total_amount),0) AS total, COUNT(*) AS bills FROM purchases WHERE status='active' AND MONTH(purchase_date)=MONTH(NOW()) AND YEAR(purchase_date)=YEAR(NOW())");
$monthPurchaseVal   = (float)($monthPurchaseData['total'] ?? 0);
$monthPurchaseBills = (int)($monthPurchaseData['bills'] ?? 0);

$pageTitle  = 'Material Stock & Inventory';
$activePage = 'purchases';
$breadcrumb = [['label' => 'Supplier'], ['label' => 'Material Stock & Inventory']];
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<script>window.APP_URL='<?= APP_URL ?>';</script>

<style>
/* Clickable KPI Cards Styling */
.stat-card-kpi {
  text-decoration: none !important;
  color: inherit !important;
  cursor: pointer;
  position: relative;
  transition: all 0.22s ease-in-out;
  display: flex;
}
.stat-card-kpi:hover {
  transform: translateY(-3px);
  box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08), 0 8px 10px -6px rgba(0, 0, 0, 0.04);
}
.stat-card-kpi .stat-arrow {
  position: absolute;
  top: 12px;
  right: 12px;
  width: 26px;
  height: 26px;
  border-radius: 50%;
  background: #f1f5f9;
  color: #94a3b8;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.72rem;
  transition: all 0.2s ease;
}
.stat-card-kpi:hover .stat-arrow {
  background: var(--primary, #2563eb);
  color: #ffffff;
  transform: translateX(3px);
}

.materials-compact-table {
  width: 100% !important;
  border-collapse: collapse;
}
.materials-compact-table th {
  padding: 11px 12px !important;
  font-size: 0.73rem !important;
  letter-spacing: 0.03em;
  white-space: nowrap;
}
.materials-compact-table td {
  padding: 12px 12px !important;
  font-size: 0.86rem;
  vertical-align: middle;
}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title"><i class="fas fa-boxes text-primary" style="margin-right:10px"></i>Material Stock &amp; Inventory</h1>
    <p class="page-subtitle">Raw fabrics (meters), buttons, zips, hooks &amp; trims supplied by mills</p>
  </div>
  <div class="page-header-right">
    <a href="<?= APP_URL ?>/admin/purchases/consumption.php" class="btn btn-primary" title="Open Rough Production Yield Estimator & Log Cutting">
      <i class="fas fa-calculator"></i> Production Estimator &amp; Cutting
    </a>
    <a href="<?= APP_URL ?>/admin/purchases/material_movements.php" class="btn btn-light"><i class="fas fa-exchange-alt"></i> Material Movements</a>
    <a href="<?= APP_URL ?>/admin/purchases/add.php" class="btn btn-success"><i class="fas fa-plus"></i> Inward Stock</a>
  </div>
</div>

<!-- Supplier & Material KPIs (Interactive & Clickable) -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit, minmax(230px, 1fr));gap:16px;margin-bottom:20px">
  
  <!-- 1. Total Fabrics Available KPI (Clickable -> Opens Fabrics Modal) -->
  <div onclick="openFabricsModal()" class="stat-card stat-card-kpi stat-card-primary" title="Click to view all available fabrics and meterage modal" style="border:1.5px solid #818cf8;background:linear-gradient(135deg, #ffffff 0%, #f5f3ff 100%)">
    <div class="stat-icon" style="background:#e0e7ff;color:#4338ca"><i class="fas fa-scroll"></i></div>
    <div class="stat-body">
      <div class="stat-value" style="color:#312e81;display:flex;align-items:baseline;gap:5px">
        <?= number_format($totalFabricMeters) ?> <span style="font-size:0.85rem;font-weight:700;color:#6366f1">Meters</span>
      </div>
      <div class="stat-label" style="font-weight:700;color:#1e1b4b">Total Fabrics Available</div>
      <div class="stat-trend up" style="color:#4f46e5;font-weight:600">
        <i class="fas fa-layer-group"></i> <?= count($fabricDbItems) ?> fabric type(s) &bull; Click to View
      </div>
    </div>
    <span class="stat-arrow" style="background:#e0e7ff;color:#4338ca"><i class="fas fa-expand-arrows-alt"></i></span>
  </div>

  <!-- 2. Buttons Available KPI (Clickable -> Opens Buttons Modal) -->
  <div onclick="openButtonsModal()" class="stat-card stat-card-kpi stat-card-warning" title="Click to view all buttons and trim varieties modal" style="border:1.5px solid #fcd34d;background:linear-gradient(135deg, #ffffff 0%, #fffbeb 100%)">
    <div class="stat-icon" style="background:#fef3c7;color:#d97706"><i class="fas fa-dot-circle"></i></div>
    <div class="stat-body">
      <div class="stat-value" style="color:#92400e;display:flex;align-items:baseline;gap:5px">
        <?= number_format($totalButtonPieces) ?> <span style="font-size:0.85rem;font-weight:700;color:#d97706">Pcs</span>
      </div>
      <div class="stat-label" style="font-weight:700;color:#78350f">Total Buttons Available</div>
      <div class="stat-trend" style="color:#b45309;font-weight:600">
        <i class="fas fa-circle"></i> <?= count($buttonDbItems) ?> button type(s) &bull; Click to View
      </div>
    </div>
    <span class="stat-arrow" style="background:#fef3c7;color:#d97706"><i class="fas fa-expand-arrows-alt"></i></span>
  </div>

  <!-- 3. Material SKUs -->
  <a href="?filter=all" class="stat-card stat-card-kpi stat-card-primary" title="Click to view all raw material SKUs">
    <div class="stat-icon"><i class="fas fa-boxes"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($total) ?></div>
      <div class="stat-label">Material SKUs</div>
      <div class="stat-trend up"><i class="fas fa-check-circle"></i> Active materials in stock</div>
    </div>
    <span class="stat-arrow"><i class="fas fa-arrow-right"></i></span>
  </a>

  <!-- 4. Material Stock Valuation -->
  <a href="<?= APP_URL ?>/admin/reports/index.php?report=stock_valuation" class="stat-card stat-card-kpi stat-card-success" title="Click to view stock valuation report">
    <div class="stat-icon"><i class="fas fa-coins"></i></div>
    <div class="stat-body">
      <div class="stat-value" style="font-size:1.15rem"><?= formatCurrency($totalValue) ?></div>
      <div class="stat-label">Material Valuation</div>
      <div class="stat-trend up"><i class="fas fa-warehouse"></i> Inventory worth</div>
    </div>
    <span class="stat-arrow"><i class="fas fa-arrow-right"></i></span>
  </a>

  <!-- 5. Low Stock / Reorder Alert -->
  <a href="?filter=low_stock" class="stat-card stat-card-kpi stat-card-warning" title="Click to view low stock materials">
    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= $lowCount ?></div>
      <div class="stat-label">Low Stock Materials</div>
      <div class="stat-trend"><?= $outCount > 0 ? '<span class="text-danger"><i class="fas fa-times-circle"></i> ' . $outCount . ' out of stock</span>' : '<span class="text-warning"><i class="fas fa-bell"></i> Reorder alerts</span>' ?></div>
    </div>
    <span class="stat-arrow"><i class="fas fa-arrow-right"></i></span>
  </a>

  <!-- 6. Total Purchase Inward Value -->
  <a href="<?= APP_URL ?>/admin/purchases/index.php?tab=bills" class="stat-card stat-card-kpi stat-card-secondary" title="Click to view supplier purchase bills">
    <div class="stat-icon"><i class="fas fa-file-invoice-dollar"></i></div>
    <div class="stat-body">
      <div class="stat-value" style="font-size:1.15rem"><?= formatCurrency($totalPurchaseVal) ?></div>
      <div class="stat-label">Total Purchase Value</div>
      <div class="stat-trend"><i class="fas fa-receipt"></i> <?= number_format($totalSupplierBills) ?> inward bill(s)</div>
    </div>
    <span class="stat-arrow"><i class="fas fa-arrow-right"></i></span>
  </a>

  <!-- 7. This Month's Inward -->
  <a href="<?= APP_URL ?>/admin/purchases/index.php?tab=bills" class="stat-card stat-card-kpi stat-card-primary" title="Click to view supplier purchase bills this month">
    <div class="stat-icon"><i class="fas fa-calendar-check"></i></div>
    <div class="stat-body">
      <div class="stat-value" style="font-size:1.15rem"><?= formatCurrency($monthPurchaseVal) ?></div>
      <div class="stat-label">This Month's Inward</div>
      <div class="stat-trend up"><i class="fas fa-truck-loading"></i> <?= $monthPurchaseBills ?> bill(s) in <?= date('M Y') ?></div>
    </div>
    <span class="stat-arrow"><i class="fas fa-arrow-right"></i></span>
  </a>

  <!-- 8. Active Suppliers & Mills -->
  <a href="<?= APP_URL ?>/admin/suppliers/index.php" class="stat-card stat-card-kpi stat-card-success" title="Click to view suppliers and mills directory">
    <div class="stat-icon"><i class="fas fa-truck"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($totalSuppliers) ?></div>
      <div class="stat-label">Active Suppliers &amp; Mills</div>
      <div class="stat-trend up"><i class="fas fa-address-book"></i> Suppliers directory</div>
    </div>
    <span class="stat-arrow"><i class="fas fa-arrow-right"></i></span>
  </a>
</div>

<!-- Tab Pills -->
<div class="tab-wrapper" style="margin-bottom:16px">
  <div class="tab-nav">
    <a href="?filter=all<?= $search ? '&search='.urlencode($search) : '' ?><?= $catId ? '&category='.$catId : '' ?>"
       class="tab-btn <?= $filter==='all' ? 'active' : '' ?>">All Materials (<?= $total ?>)</a>
    <a href="?filter=low_stock<?= $search ? '&search='.urlencode($search) : '' ?><?= $catId ? '&category='.$catId : '' ?>"
       class="tab-btn <?= $filter==='low_stock' ? 'active' : '' ?>">
       <i class="fas fa-exclamation-triangle text-warning"></i> Low Stock (<?= $lowCount ?>)
    </a>
    <a href="?filter=out_of_stock<?= $search ? '&search='.urlencode($search) : '' ?><?= $catId ? '&category='.$catId : '' ?>"
       class="tab-btn <?= $filter==='out_of_stock' ? 'active' : '' ?>">
       <i class="fas fa-times-circle text-danger"></i> Out of Stock (<?= $outCount ?>)
    </a>
  </div>
</div>

<div class="card">
  <!-- Filters -->
  <form method="GET" class="table-filters">
    <input type="hidden" name="filter" value="<?= e($filter) ?>">
    <input type="text" name="search" class="form-control" placeholder="Search material name, fabric type, SKU, supplier..." value="<?= e($search) ?>" style="min-width:260px">
    <select name="category" class="form-select">
      <option value="">All Categories</option>
      <?php foreach ($categories as $cat): ?>
      <option value="<?= $cat['id'] ?>" <?= $catId==$cat['id']?'selected':'' ?>><?= e($cat['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
    <a href="<?= APP_URL ?>/admin/purchases/materials.php" class="btn btn-light btn-sm"><i class="fas fa-times"></i> Clear</a>
    <div style="margin-left:auto">
      <button type="button" class="btn btn-light btn-sm" onclick="exportTableToCSV('materialsTable','material_stock')">
        <i class="fas fa-download"></i> Export CSV
      </button>
    </div>
  </form>

  <!-- Clean Individual Materials Table (Group category layout removed as requested) -->
  <div class="table-wrapper">
    <table class="data-table materials-compact-table" id="materialsTable">
      <thead>
        <tr>
          <th style="width:26%;min-width:210px">Product / Material</th>
          <th style="width:24%;min-width:180px">Product Info &amp; Specs</th>
          <th class="text-center" style="width:13%;min-width:120px">Available Stock</th>
          <th class="text-center" style="width:8%;min-width:70px" title="Sold &amp; Consumed">Consumed</th>
          <th class="text-center" style="width:8%;min-width:70px" title="Total Inwarded">Inwarded</th>
          <th class="text-right" style="width:10%;min-width:85px">Valuation</th>
          <th class="text-center" style="width:11%;min-width:120px">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($materials)): ?>
        <tr>
          <td colspan="7" class="text-center text-muted" style="padding:48px 20px">
            <i class="fas fa-scroll" style="font-size:2.5rem;opacity:0.25;display:block;margin-bottom:12px"></i>
            <h5 style="color:#64748b;font-weight:600;margin-bottom:4px">No materials found</h5>
            <p style="font-size:0.85rem;margin-bottom:12px">Inward fabrics (meters), buttons, zips, hooks &amp; trims into stock.</p>
            <a href="<?= APP_URL ?>/admin/purchases/add.php" class="btn btn-sm btn-success">
              <i class="fas fa-plus"></i> Inward New Material Now
            </a>
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($materials as $m):
          $val = $m['total_valuation'];
          $isLow = $m['current_stock'] > 0 && $m['current_stock'] <= $m['min_stock_alert'];
          $isOut = $m['current_stock'] <= 0;
          $unitLabel = $m['unit_label'];
        ?>
        <tr>
          <!-- 1. Product Name, Code (SKU) & Supplier Name -->
          <td>
            <div style="font-weight:700;font-size:0.95rem;color:#0f172a;line-height:1.25">
              <?= e($m['product_name']) ?>
            </div>
            <div style="display:flex;align-items:center;gap:6px;margin-top:4px;flex-wrap:wrap">
              <span class="sku-badge" style="font-size:0.72rem"><?= e($m['sku']) ?></span>
              <span style="font-size:0.75rem;color:#64748b;display:inline-flex;align-items:center;gap:4px">
                <i class="fas fa-truck" style="font-size:0.68rem;color:#94a3b8"></i> 
                Supplier: <strong style="color:#334155"><?= e($m['supplier_name']) ?></strong>
              </span>
            </div>
          </td>

          <!-- 2. Product Info & Specs -->
          <td>
            <div style="display:flex;flex-wrap:wrap;gap:4px;align-items:center">
              <?php if (!empty($m['material_type']) && $m['material_type'] !== 'Manual Tailoring Material'): ?>
                <span class="badge badge-primary" style="font-size:0.72rem;background:#ede9fe;color:#6d28d9;border:1px solid #ddd6fe">
                  <i class="fas fa-tag"></i> <?= e($m['material_type']) ?>
                </span>
              <?php endif; ?>

              <?php if (!empty($m['spec_label'])): ?>
                <span class="badge" style="font-size:0.72rem;background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;font-weight:600">
                  <i class="fas fa-palette"></i> <?= e($m['spec_label']) ?>
                </span>
              <?php endif; ?>

              <span class="badge badge-light" style="font-size:0.72rem;border:1px solid #cbd5e1;background:#f8fafc">
                Unit: <strong><?= e($unitLabel) ?></strong>
              </span>

              <?php if (!empty($m['category_name'])): ?>
                <span class="badge badge-secondary" style="font-size:0.72rem">
                  <?= e($m['category_name']) ?>
                </span>
              <?php endif; ?>

              <?php if (!empty($m['garment_category']) && $m['garment_category'] !== 'General'): ?>
                <span class="badge" style="font-size:0.7rem;background:#f1f5f9;color:#475569">
                  <?= e($m['garment_category']) ?>
                </span>
              <?php endif; ?>
            </div>
          </td>

          <!-- 3. Available Stock -->
          <td class="text-center">
            <?php if ($isOut): ?>
              <span class="badge badge-danger" style="font-weight:700;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;padding:3px 8px;font-size:0.75rem">[ 0 available ] &bull; Out</span>
            <?php elseif ($isLow): ?>
              <span class="badge badge-warning" style="font-weight:700;background:#fffbeb;color:#b45309;border:1px solid #fde68a;padding:3px 8px;font-size:0.75rem">[ <?= number_format($m['current_stock']) ?> <?= e($unitLabel) ?> ] &bull; Low</span>
            <?php else: ?>
              <span class="badge badge-success" style="font-weight:700;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;padding:3px 8px;font-size:0.75rem">[ <?= number_format($m['current_stock']) ?> <?= e($unitLabel) ?> available ]</span>
            <?php endif; ?>
          </td>

          <!-- 4. Consumed -->
          <td class="text-center">
            <span style="font-weight:600;color:#64748b;font-size:0.84rem"><?= number_format($m['total_consumed']) ?></span>
          </td>

          <!-- 5. Inwarded -->
          <td class="text-center">
            <span style="font-weight:600;color:#2563eb;font-size:0.84rem"><?= number_format($m['total_inwarded']) ?></span>
          </td>

          <!-- 6. Valuation & Cost -->
          <td class="text-right">
            <div style="font-weight:700;font-size:0.92rem;color:#0f172a"><?= formatCurrency($val) ?></div>
            <div style="font-size:0.72rem;color:#64748b"><?= formatCurrency($m['purchase_price_with_gst']) ?> / <?= e($unitLabel) ?></div>
          </td>

          <!-- 7. Actions -->
          <td class="text-center">
            <div class="table-actions" style="justify-content:center;gap:4px">
              <a href="<?= APP_URL ?>/admin/purchases/add.php?sku=<?= urlencode($m['sku']) ?>" class="btn btn-xs btn-success" title="Inward More Stock" style="font-weight:600;padding:3px 8px">
                <i class="fas fa-plus"></i> Inward
              </a>
              <a href="<?= APP_URL ?>/admin/purchases/material_movements.php?variant_id=<?= $m['id'] ?>" class="btn-icon btn-sm btn-icon-light" title="Material Movement Ledger">
                <i class="fas fa-history"></i>
              </a>
              <a href="<?= APP_URL ?>/admin/inventory/adjustments.php?variant_id=<?= $m['id'] ?>" class="btn-icon btn-sm btn-icon-warning" title="Adjust Stock">
                <i class="fas fa-sliders-h"></i>
              </a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pagination['pages'] > 1): ?>
  <div class="card-footer d-flex justify-between align-center">
    <span class="text-muted text-sm">Showing <?= count($materials) ?> of <?= number_format($total) ?> material(s)</span>
    <div class="pagination">
      <?php for ($p=1; $p<=$pagination['pages']; $p++): ?>
      <a href="?page=<?= $p ?>&search=<?= urlencode($search) ?>&category=<?= $catId ?>&filter=<?= e($filter) ?>"
         class="page-link <?= $p==$page?'active':'' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ===================================================== -->
<!-- MODAL 1: ALL AVAILABLE FABRICS MODAL                  -->
<!-- ===================================================== -->
<div class="modal-overlay" id="fabricsModal">
  <div class="modal" style="max-width:980px">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:10px">
        <div style="width:36px;height:36px;border-radius:8px;background:#e0e7ff;color:#4338ca;display:flex;align-items:center;justify-content:center;font-size:1.15rem">
          <i class="fas fa-scroll"></i>
        </div>
        <div>
          <span class="modal-title" style="font-size:1.15rem">Available Fabrics &amp; Live Meterage in Stock</span>
          <div style="font-size:0.75rem;color:#64748b">Raw fabric rolls and meters supplied by mills available for tailoring &amp; production</div>
        </div>
      </div>
      <button type="button" class="modal-close" onclick="closeModal('fabricsModal')">&times;</button>
    </div>

    <div class="modal-body" style="padding:16px 20px">
      <!-- Metric Summary Bar -->
      <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:12px;margin-bottom:16px">
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px">
          <div style="font-size:0.75rem;color:#64748b;font-weight:600;text-transform:uppercase">Active Fabric Lines</div>
          <div style="font-size:1.3rem;font-weight:800;color:#0f172a;margin-top:2px"><?= count($fabricDbItems) ?> Varieties</div>
        </div>
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px">
          <div style="font-size:0.75rem;color:#1e40af;font-weight:600;text-transform:uppercase">Total Available Meters</div>
          <div style="font-size:1.3rem;font-weight:800;color:#2563eb;margin-top:2px"><?= number_format($totalFabricMeters) ?> Meters</div>
        </div>
        <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;padding:12px 16px">
          <div style="font-size:0.75rem;color:#065f46;font-weight:600;text-transform:uppercase">Total Fabric Valuation</div>
          <div style="font-size:1.3rem;font-weight:800;color:#059669;margin-top:2px"><?= formatCurrency($totalFabricValuation) ?></div>
        </div>
      </div>

      <!-- Fabrics Table inside Modal -->
      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr>
              <th style="min-width:180px">Fabric Name &amp; SKU</th>
              <th style="min-width:150px">Color &amp; Specifications</th>
              <th style="min-width:140px">Supplier Mill</th>
              <th class="text-center" style="width:120px">Available Stock</th>
              <th class="text-right" style="width:105px">Cost / Meter</th>
              <th class="text-right" style="width:115px">Valuation</th>
              <th class="text-center" style="min-width:150px">Tailoring Potential</th>
              <th class="text-center" style="width:120px">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($fabricDbItems)): ?>
            <tr>
              <td colspan="8" class="text-center text-muted" style="padding:36px">
                <i class="fas fa-scroll" style="font-size:2rem;opacity:0.25;display:block;margin-bottom:8px"></i>
                No fabrics currently in stock.
              </td>
            </tr>
            <?php else: ?>
            <?php foreach ($fabricDbItems as $f): 
              $fStock = (float)$f['current_stock'];
              $fVal = $f['valuation'];
              // Yield calculation presets
              $fullShirts = $fStock > 0 ? floor($fStock / 1.70) : 0;
              $trousers = $fStock > 0 ? floor($fStock / 1.25) : 0;
            ?>
            <tr>
              <td>
                <strong style="color:#0f172a;font-size:0.92rem"><?= e($f['product_name']) ?></strong>
                <div style="margin-top:2px"><span class="sku-badge" style="font-size:0.71rem"><?= e($f['sku']) ?></span></div>
              </td>
              <td>
                <?php if (!empty($f['spec_label'])): ?>
                  <span class="badge" style="font-size:0.72rem;background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd;font-weight:600">
                    <i class="fas fa-palette"></i> <?= e($f['spec_label']) ?>
                  </span>
                <?php endif; ?>
                <?php if (!empty($f['material_type']) && $f['material_type'] !== 'Manual Tailoring Material'): ?>
                  <div style="font-size:0.72rem;color:#7c3aed;margin-top:2px"><i class="fas fa-tag"></i> <?= e($f['material_type']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span style="font-size:0.83rem;font-weight:600;color:#334155">
                  <i class="fas fa-truck text-muted" style="font-size:0.72rem;margin-right:3px"></i> <?= e($f['supplier_name']) ?>
                </span>
              </td>
              <td class="text-center">
                <?php if ($fStock <= 0): ?>
                  <span class="badge badge-danger" style="font-weight:700">[ 0 available ] &bull; Out</span>
                <?php else: ?>
                  <span class="badge badge-success" style="font-weight:700;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0">[ <?= number_format($fStock) ?> Meters ]</span>
                <?php endif; ?>
              </td>
              <td class="text-right" style="font-size:0.85rem"><?= formatCurrency($f['purchase_price_with_gst']) ?></td>
              <td class="text-right font-weight-bold" style="font-size:0.9rem;color:#059669"><?= formatCurrency($fVal) ?></td>
              <td class="text-center">
                <span class="badge" style="font-size:0.72rem;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0">
                  ~<?= $fullShirts ?> Shirts or ~<?= $trousers ?> Pants
                </span>
              </td>
              <td class="text-center">
                <div class="d-flex gap-1 justify-center">
                  <a href="<?= APP_URL ?>/admin/purchases/add.php?sku=<?= urlencode($f['sku']) ?>" class="btn btn-xs btn-success" title="Inward more of this fabric">
                    <i class="fas fa-plus"></i> Inward
                  </a>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="modal-footer" style="display:flex;justify-content:space-between;align-items:center;background:#f8fafc">
      <div style="font-size:0.82rem;color:#64748b">
        <i class="fas fa-scissors text-primary"></i> Tailoring Note: Collar, cuffs, waistband &amp; pockets are cut from this same cloth.
      </div>
      <div class="d-flex gap-2">
        <button type="button" class="btn btn-light" onclick="closeModal('fabricsModal')">Close</button>
        <a href="<?= APP_URL ?>/admin/purchases/add.php?open_cat=cloth" class="btn btn-success" style="font-weight:600">
          <i class="fas fa-plus-circle"></i> Inward New Fabric &rarr;
        </a>
      </div>
    </div>
  </div>
</div>

<!-- ===================================================== -->
<!-- MODAL 2: ALL BUTTONS INVENTORY MODAL                  -->
<!-- ===================================================== -->
<div class="modal-overlay" id="buttonsModal">
  <div class="modal" style="max-width:920px">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:10px">
        <div style="width:36px;height:36px;border-radius:8px;background:#fef3c7;color:#d97706;display:flex;align-items:center;justify-content:center;font-size:1.15rem">
          <i class="fas fa-dot-circle"></i>
        </div>
        <div>
          <span class="modal-title" style="font-size:1.15rem">Buttons Stock &amp; Inventory</span>
          <div style="font-size:0.75rem;color:#64748b">Shirt placket buttons, trouser horn buttons, blazer buttons &amp; fasteners</div>
        </div>
      </div>
      <button type="button" class="modal-close" onclick="closeModal('buttonsModal')">&times;</button>
    </div>

    <div class="modal-body" style="padding:16px 20px">
      <!-- Metric Summary Bar -->
      <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:12px;margin-bottom:16px">
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 16px">
          <div style="font-size:0.75rem;color:#64748b;font-weight:600;text-transform:uppercase">Button Varieties</div>
          <div style="font-size:1.3rem;font-weight:800;color:#0f172a;margin-top:2px"><?= count($buttonDbItems) ?> Types</div>
        </div>
        <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 16px">
          <div style="font-size:0.75rem;color:#92400e;font-weight:600;text-transform:uppercase">Total Button Pieces</div>
          <div style="font-size:1.3rem;font-weight:800;color:#d97706;margin-top:2px"><?= number_format($totalButtonPieces) ?> Pcs</div>
        </div>
        <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;padding:12px 16px">
          <div style="font-size:0.75rem;color:#065f46;font-weight:600;text-transform:uppercase">Total Buttons Valuation</div>
          <div style="font-size:1.3rem;font-weight:800;color:#059669;margin-top:2px"><?= formatCurrency($totalButtonValuation) ?></div>
        </div>
      </div>

      <!-- Buttons Table inside Modal -->
      <div class="table-wrapper">
        <table class="data-table">
          <thead>
            <tr>
              <th style="min-width:180px">Button Name &amp; SKU</th>
              <th style="min-width:140px">Specification / Ligne</th>
              <th style="min-width:140px">Supplier Mill</th>
              <th class="text-center" style="width:120px">Available Stock</th>
              <th class="text-right" style="width:105px">Cost / Piece</th>
              <th class="text-right" style="width:115px">Valuation</th>
              <th class="text-center" style="width:110px">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($buttonDbItems)): ?>
            <tr>
              <td colspan="7" class="text-center text-muted" style="padding:40px 20px">
                <i class="fas fa-dot-circle" style="font-size:2.2rem;opacity:0.25;display:block;margin-bottom:8px"></i>
                <div style="font-weight:600;color:#64748b;font-size:0.95rem">No buttons currently logged in stock</div>
                <div style="font-size:0.8rem;color:#94a3b8;margin-top:4px;margin-bottom:14px">Inward shirt placket, trouser horn, blazer or casual buttons to keep track of garment trims.</div>
                <a href="<?= APP_URL ?>/admin/purchases/add.php?open_cat=buttons" class="btn btn-sm btn-success">
                  <i class="fas fa-plus"></i> Inward Buttons Now
                </a>
              </td>
            </tr>
            <?php else: ?>
            <?php foreach ($buttonDbItems as $b): 
              $bStock = (int)$b['current_stock'];
              $bVal = $b['valuation'];
            ?>
            <tr>
              <td>
                <strong style="color:#0f172a;font-size:0.92rem"><?= e($b['product_name']) ?></strong>
                <div style="margin-top:2px"><span class="sku-badge" style="font-size:0.71rem"><?= e($b['sku']) ?></span></div>
              </td>
              <td>
                <span class="badge badge-light" style="font-size:0.73rem;border:1px solid #cbd5e1">
                  <?= e($b['size'] ?: 'Pcs') ?>
                </span>
                <?php if (!empty($b['material_type'])): ?>
                  <div style="font-size:0.72rem;color:#7c3aed;margin-top:2px"><?= e($b['material_type']) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <span style="font-size:0.83rem;font-weight:600;color:#334155">
                  <i class="fas fa-truck text-muted" style="font-size:0.72rem;margin-right:3px"></i> <?= e($b['supplier_name']) ?>
                </span>
              </td>
              <td class="text-center">
                <?php if ($bStock <= 0): ?>
                  <span class="badge badge-danger" style="font-weight:700">[ 0 available ] &bull; Out</span>
                <?php else: ?>
                  <span class="badge badge-success" style="font-weight:700;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0">[ <?= number_format($bStock) ?> Pcs ]</span>
                <?php endif; ?>
              </td>
              <td class="text-right" style="font-size:0.85rem"><?= formatCurrency($b['purchase_price_with_gst']) ?></td>
              <td class="text-right font-weight-bold" style="font-size:0.9rem;color:#059669"><?= formatCurrency($bVal) ?></td>
              <td class="text-center">
                <a href="<?= APP_URL ?>/admin/purchases/add.php?sku=<?= urlencode($b['sku']) ?>" class="btn btn-xs btn-success" title="Inward more of these buttons">
                  <i class="fas fa-plus"></i> Inward
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="modal-footer" style="display:flex;justify-content:space-between;align-items:center;background:#f8fafc">
      <div style="font-size:0.82rem;color:#64748b">
        <i class="fas fa-info-circle text-primary"></i> Buttons can be dynamically inwarded in 18L, 24L, 32L or custom sizes.
      </div>
      <div class="d-flex gap-2">
        <button type="button" class="btn btn-light" onclick="closeModal('buttonsModal')">Close</button>
        <a href="<?= APP_URL ?>/admin/purchases/add.php?open_cat=buttons" class="btn btn-success" style="font-weight:600">
          <i class="fas fa-plus-circle"></i> Inward Buttons Now &rarr;
        </a>
      </div>
    </div>
  </div>
</div>



<script>
function openFabricsModal() {
  openModal('fabricsModal');
}

function openButtonsModal() {
  openModal('buttonsModal');
}
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
