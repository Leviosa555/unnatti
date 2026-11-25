<?php
/**
 * Admin: Monthly Production Analytics & Uniform Manufacturing Hub
 * Fashion Kingdom institutional batch production metrics, firm breakdown & raw material consumption trends
 */
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireAdmin();

// Date & Filter controls
$currentYearMonth = date('Y-m');
$selectedMonth    = sanitize($_GET['month'] ?? $currentYearMonth);
$selectedFirm     = sanitize($_GET['firm'] ?? '');
$selectedCategory = sanitize($_GET['category'] ?? '');

// Calculate previous month for MoM comparison
$prevMonth = date('Y-m', strtotime($selectedMonth . '-01 -1 month'));

// Query filter building
$where = ["DATE_FORMAT(production_date, '%Y-%m') = ?"];
$params = [$selectedMonth];

if (!empty($selectedFirm)) {
    $where[] = "client_firm = ?";
    $params[] = $selectedFirm;
}
if (!empty($selectedCategory)) {
    $where[] = "uniform_category = ?";
    $params[] = $selectedCategory;
}
$whereSql = implode(' AND ', $where);

// ── 1. Current Month KPIs ────────────────────────────────────────────────────
$currentKpi = db()->fetchOne(
    "SELECT COUNT(id) AS total_batches,
            COALESCE(SUM(units_produced), 0) AS total_units,
            COALESCE(SUM(total_fabric_meters), 0) AS total_fabric,
            COUNT(DISTINCT client_firm) AS active_firms
     FROM material_consumptions
     WHERE $whereSql",
    $params
) ?: ['total_batches' => 0, 'total_units' => 0, 'total_fabric' => 0, 'active_firms' => 0];

// Previous Month for Growth Calculation
$prevKpi = db()->fetchOne(
    "SELECT COALESCE(SUM(units_produced), 0) AS total_units,
            COALESCE(SUM(total_fabric_meters), 0) AS total_fabric
     FROM material_consumptions
     WHERE DATE_FORMAT(production_date, '%Y-%m') = ?",
    [$prevMonth]
) ?: ['total_units' => 0, 'total_fabric' => 0];

$unitGrowthPct = 0;
if ($prevKpi['total_units'] > 0) {
    $unitGrowthPct = round((($currentKpi['total_units'] - $prevKpi['total_units']) / $prevKpi['total_units']) * 100, 1);
}

// ── 2. Production Breakdown by Uniform Category ──────────────────────────────
$categoryBreakdown = db()->fetchAll(
    "SELECT COALESCE(NULLIF(uniform_category, ''), garment_type) AS cat_name,
            COUNT(id) AS batch_count,
            SUM(units_produced) AS units,
            SUM(total_fabric_meters) AS meters
     FROM material_consumptions
     WHERE $whereSql
     GROUP BY cat_name
     ORDER BY units DESC",
    $params
);

// ── 3. Client Firm Production Breakdown ──────────────────────────────────────
$firmBreakdown = db()->fetchAll(
    "SELECT COALESCE(NULLIF(client_firm, ''), 'Direct Mill Stock') AS firm_name,
            COUNT(id) AS batch_count,
            SUM(units_produced) AS units,
            SUM(total_fabric_meters) AS meters,
            MAX(production_date) AS latest_date,
            GROUP_CONCAT(DISTINCT COALESCE(NULLIF(uniform_category, ''), garment_type) SEPARATOR ', ') AS categories_list
     FROM material_consumptions
     WHERE $whereSql
     GROUP BY firm_name
     ORDER BY units DESC",
    $params
);

// ── 4. Raw Materials & Trims Consumed in Selected Month ──────────────────────
$materialsConsumed = db()->fetchAll(
    "SELECT mci.material_role,
            p.name AS material_name,
            pv.sku,
            mci.unit,
            SUM(mci.qty_used) AS total_used,
            COUNT(DISTINCT mc.id) AS batches_used_in
     FROM material_consumption_items mci
     JOIN material_consumptions mc ON mci.consumption_id = mc.id
     JOIN product_variants pv ON mci.variant_id = pv.id
     JOIN products p ON pv.product_id = p.id
     WHERE $whereSql
     GROUP BY mci.variant_id, mci.material_role, mci.unit, p.name, pv.sku
     ORDER BY FIELD(mci.material_role, 'fabric', 'button', 'zipper', 'hook', 'other'), total_used DESC",
    $params
);

// ── 5. Month-over-Month Production Trend (Past 12 Months) ─────────────────────
$monthlyTrend = db()->fetchAll(
    "SELECT DATE_FORMAT(production_date, '%Y-%m') AS ym,
            DATE_FORMAT(production_date, '%b %Y') AS month_label,
            COUNT(id) AS batches,
            SUM(units_produced) AS total_units,
            SUM(total_fabric_meters) AS total_meters,
            COUNT(DISTINCT client_firm) AS firms_count
     FROM material_consumptions
     GROUP BY ym, month_label
     ORDER BY ym DESC
     LIMIT 12"
);

// Available months and firms for filter dropdowns
$availableMonths = db()->fetchAll(
    "SELECT DISTINCT DATE_FORMAT(production_date, '%Y-%m') AS ym,
            DATE_FORMAT(production_date, '%M %Y') AS month_label
     FROM material_consumptions
     ORDER BY ym DESC"
);
if (empty($availableMonths)) {
    $availableMonths = [['ym' => $currentYearMonth, 'month_label' => date('F Y')]];
}

$availableFirms = db()->fetchAll(
    "SELECT DISTINCT client_firm FROM material_consumptions WHERE client_firm IS NOT NULL AND client_firm != '' ORDER BY client_firm ASC"
);

// Recent Batches in Selected Month
$recentBatches = db()->fetchAll(
    "SELECT mc.*, u.full_name AS employee_name
     FROM material_consumptions mc
     LEFT JOIN users u ON mc.created_by = u.id
     WHERE $whereSql
     ORDER BY mc.id DESC LIMIT 15",
    $params
);

$pageTitle  = 'Monthly Production Analytics';
$activePage = 'purchases';
$breadcrumb = [
    ['label' => 'Supplier Operations'],
    ['label' => 'Material Used & Production', 'url' => APP_URL . '/admin/purchases/consumption.php'],
    ['label' => 'Production Analytics']
];

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="page-header" style="margin-bottom:20px">
  <div class="page-header-left">
    <h1 class="page-title"><i class="fas fa-chart-line text-primary" style="margin-right:10px"></i>Monthly Production Analytics</h1>
    <p class="page-subtitle">Fashion Kingdom institutional batch uniform manufacturing, client firm volumes &amp; raw material trends</p>
  </div>
  <div class="page-header-right" style="display:flex;gap:8px;align-items:center">
    <a href="<?= APP_URL ?>/admin/purchases/consumption.php" class="btn btn-primary">
      <i class="fas fa-cut"></i> Record Batch Production
    </a>
    <a href="<?= APP_URL ?>/admin/billing/create.php" class="btn btn-success" style="font-weight:600">
      <i class="fas fa-file-invoice"></i> Create Bill / Supply Order
    </a>
    <button type="button" class="btn btn-light" onclick="window.print()">
      <i class="fas fa-print"></i> Print Report
    </button>
  </div>
</div>

<!-- ================= Filter Bar ================= -->
<div class="card" style="margin-bottom:24px;border:1px solid #cbd5e1;background:#f8fafc">
  <div class="card-body" style="padding:14px 18px">
    <form method="GET" class="d-flex align-center gap-3" style="flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:8px">
        <label class="form-label mb-0" style="font-weight:700;font-size:0.85rem;color:#334155"><i class="fas fa-calendar-alt text-primary"></i> Select Month:</label>
        <select name="month" class="form-select form-select-sm" style="font-weight:600;min-width:160px" onchange="this.form.submit()">
          <?php foreach ($availableMonths as $m): ?>
          <option value="<?= $m['ym'] ?>" <?= $selectedMonth === $m['ym'] ? 'selected' : '' ?>>
            <?= e($m['month_label']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="display:flex;align-items:center;gap:8px">
        <label class="form-label mb-0" style="font-weight:700;font-size:0.85rem;color:#334155"><i class="fas fa-building text-primary"></i> Client Firm:</label>
        <select name="firm" class="form-select form-select-sm" style="min-width:180px" onchange="this.form.submit()">
          <option value="">All Client Firms</option>
          <?php foreach ($availableFirms as $f): ?>
          <option value="<?= e($f['client_firm']) ?>" <?= $selectedFirm === $f['client_firm'] ? 'selected' : '' ?>>
            <?= e($f['client_firm']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php if (!empty($selectedFirm) || $selectedMonth !== $currentYearMonth): ?>
      <a href="<?= APP_URL ?>/admin/purchases/analytics.php" class="btn btn-sm btn-light" style="padding:4px 10px;font-size:0.8rem">
        <i class="fas fa-times"></i> Reset
      </a>
      <?php endif; ?>

      <div style="margin-left:auto;font-size:0.82rem;color:#64748b">
        Showing production data for: <strong style="color:#0f172a"><?= date('F Y', strtotime($selectedMonth . '-01')) ?></strong>
      </div>
    </form>
  </div>
</div>

<!-- ================= KPI Summary Cards ================= -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px;margin-bottom:24px">
  <div class="stat-card stat-card-primary">
    <div class="stat-icon"><i class="fas fa-tshirt"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($currentKpi['total_units']) ?> pcs</div>
      <div class="stat-label">Total Uniforms Produced</div>
      <div class="stat-trend <?= $unitGrowthPct >= 0 ? 'up' : 'down' ?>">
        <i class="fas fa-arrow-<?= $unitGrowthPct >= 0 ? 'up' : 'down' ?>"></i>
        <?= abs($unitGrowthPct) ?>% vs <?= date('M', strtotime($prevMonth . '-01')) ?>
      </div>
    </div>
  </div>

  <div class="stat-card stat-card-info">
    <div class="stat-icon"><i class="fas fa-ruler-combined"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($currentKpi['total_fabric'], 1) ?> m</div>
      <div class="stat-label">Total Fabric Consumed</div>
      <div class="stat-trend"><i class="fas fa-cut"></i> Cutting Table Volume</div>
    </div>
  </div>

  <div class="stat-card stat-card-success">
    <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($currentKpi['total_batches']) ?></div>
      <div class="stat-label">Batches Completed</div>
      <div class="stat-trend up"><i class="fas fa-check-circle"></i> Logged Orders</div>
    </div>
  </div>

  <div class="stat-card stat-card-warning">
    <div class="stat-icon"><i class="fas fa-building"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($currentKpi['active_firms']) ?></div>
      <div class="stat-label">Client Firms Serviced</div>
      <div class="stat-trend"><i class="fas fa-handshake"></i> Institutional Clients</div>
    </div>
  </div>
</div>

<div class="grid-2" style="margin-bottom:24px;gap:20px">
  <!-- Uniform Category Breakdown -->
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
      <span class="card-title"><i class="fas fa-pie-chart text-primary"></i> Production by Uniform Category</span>
      <span class="badge badge-light" style="font-size:0.75rem"><?= count($categoryBreakdown) ?> categories</span>
    </div>
    <div class="card-body">
      <?php if (empty($categoryBreakdown)): ?>
      <div class="empty-state" style="padding:24px">
        <i class="fas fa-tshirt"></i>
        <p>No production recorded for <?= date('F Y', strtotime($selectedMonth . '-01')) ?></p>
      </div>
      <?php else: 
        $maxUnits = max(array_column($categoryBreakdown, 'units')) ?: 1;
        foreach ($categoryBreakdown as $cb): 
          $pctOfTotal = $currentKpi['total_units'] > 0 ? round(($cb['units'] / $currentKpi['total_units']) * 100, 1) : 0;
          $barWidth = round(($cb['units'] / $maxUnits) * 100);
      ?>
      <div style="margin-bottom:16px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
          <span style="font-weight:700;font-size:0.88rem;color:#1e293b"><?= e($cb['cat_name']) ?></span>
          <span style="font-weight:800;font-size:0.9rem;color:#0f172a">
            <?= number_format($cb['units']) ?> pcs <span style="font-size:0.75rem;color:#64748b;font-weight:500">(<?= $pctOfTotal ?>%)</span>
          </span>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:0.75rem;color:#64748b;margin-bottom:4px">
          <span><?= $cb['batch_count'] ?> batch(es)</span>
          <span><?= number_format($cb['meters'], 1) ?> meters cloth used</span>
        </div>
        <div style="background:#f1f5f9;height:8px;border-radius:4px;overflow:hidden">
          <div style="background:linear-gradient(90deg, #2563eb, #38bdf8);height:100%;width:<?= $barWidth ?>%;border-radius:4px"></div>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- Client Firm Volume Summary -->
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
      <span class="card-title"><i class="fas fa-building text-primary"></i> Client Firms &amp; Institutions</span>
      <span class="badge badge-light" style="font-size:0.75rem"><?= count($firmBreakdown) ?> firms</span>
    </div>
    <div class="card-body p-0">
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>Client Firm</th>
              <th class="text-center">Batches</th>
              <th class="text-center">Total Uniforms</th>
              <th class="text-right">Cloth (m)</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($firmBreakdown)): ?>
            <tr><td colspan="4" class="text-center text-muted" style="padding:24px">No firm production in this period</td></tr>
            <?php else: foreach ($firmBreakdown as $fb): ?>
            <tr>
              <td>
                <strong style="color:#0f172a;display:block"><?= e($fb['firm_name']) ?></strong>
                <span style="font-size:0.72rem;color:#64748b"><?= e($fb['categories_list'] ?: 'General Uniforms') ?></span>
              </td>
              <td class="text-center" style="font-weight:600"><?= $fb['batch_count'] ?></td>
              <td class="text-center" style="font-weight:800;color:#1e40af"><?= number_format($fb['units']) ?> pcs</td>
              <td class="text-right" style="font-weight:700;color:#059669"><?= number_format($fb['meters'], 1) ?> m</td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ================= Raw Materials & Trims Consumed ================= -->
<div class="card" style="margin-bottom:24px">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
    <div>
      <span class="card-title"><i class="fas fa-boxes text-success"></i> Raw Materials &amp; Trims Consumed This Month</span>
      <div style="font-size:0.8rem;color:#64748b">Exact quantities of fabrics, buttons, canvas fusing, zips &amp; hooks deducted for <?= date('F Y', strtotime($selectedMonth . '-01')) ?></div>
    </div>
    <span class="badge" style="background:#ecfdf5;color:#065f46;font-weight:700">Inventory Deductions</span>
  </div>
  <div class="card-body p-0">
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Material / Item Name</th>
            <th>SKU</th>
            <th>Material Role</th>
            <th class="text-center">Batches Used In</th>
            <th class="text-right">Total Quantity Deducted</th>
            <th class="text-center">Unit</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($materialsConsumed)): ?>
          <tr><td colspan="6" class="text-center text-muted" style="padding:28px">No material consumption recorded for this period.</td></tr>
          <?php else: foreach ($materialsConsumed as $mc): ?>
          <tr>
            <td>
              <strong style="color:#1e293b"><?= e($mc['material_name']) ?></strong>
            </td>
            <td><span class="sku-badge"><?= e($mc['sku']) ?></span></td>
            <td>
              <?php
                $roleBadge = 'badge-light';
                if ($mc['material_role'] === 'fabric') $roleBadge = 'badge-primary';
                elseif ($mc['material_role'] === 'button') $roleBadge = 'badge-warning';
                elseif ($mc['material_role'] === 'zipper' || $mc['material_role'] === 'hook') $roleBadge = 'badge-info';
              ?>
              <span class="badge <?= $roleBadge ?>" style="text-transform:capitalize"><?= e($mc['material_role']) ?></span>
            </td>
            <td class="text-center" style="font-weight:600"><?= $mc['batches_used_in'] ?></td>
            <td class="text-right" style="font-weight:800;color:#059669;font-size:0.95rem">
              <?= number_format($mc['total_used'], 2) ?>
            </td>
            <td class="text-center text-muted" style="font-size:0.82rem"><?= e($mc['unit']) ?></td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ================= Month-over-Month Historical Trends ================= -->
<div class="card" style="margin-bottom:24px">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
    <span class="card-title"><i class="fas fa-history text-primary"></i> 12-Month Production History &amp; Monthly Trends</span>
    <span class="text-muted" style="font-size:0.8rem">Historical monthly uniform volumes</span>
  </div>
  <div class="card-body p-0">
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Month</th>
            <th class="text-center">Batches Completed</th>
            <th class="text-center">Total Uniforms Produced</th>
            <th class="text-center">Fabric Consumed (m)</th>
            <th class="text-center">Active Client Firms</th>
            <th class="text-center">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($monthlyTrend)): ?>
          <tr><td colspan="6" class="text-center text-muted" style="padding:24px">No historical production records</td></tr>
          <?php else: foreach ($monthlyTrend as $t): ?>
          <tr <?= $t['ym'] === $selectedMonth ? 'style="background:#eff6ff"' : '' ?>>
            <td>
              <strong style="color:<?= $t['ym'] === $selectedMonth ? '#1d4ed8' : '#0f172a' ?>">
                <?= e($t['month_label']) ?>
              </strong>
              <?php if ($t['ym'] === $currentYearMonth): ?>
                <span class="badge" style="background:#dbeafe;color:#1e40af;font-size:0.7rem;margin-left:4px">Current Month</span>
              <?php endif; ?>
            </td>
            <td class="text-center" style="font-weight:600"><?= number_format($t['batches']) ?></td>
            <td class="text-center" style="font-weight:800;color:#0f172a"><?= number_format($t['total_units']) ?> pcs</td>
            <td class="text-center" style="font-weight:700;color:#059669"><?= number_format($t['total_meters'], 1) ?> m</td>
            <td class="text-center"><?= $t['firms_count'] ?> firms</td>
            <td class="text-center">
              <a href="<?= APP_URL ?>/admin/purchases/analytics.php?month=<?= $t['ym'] ?>" class="btn btn-xs btn-outline-primary">
                <i class="fas fa-filter"></i> View Month
              </a>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
