<?php
/**
 * Admin Dashboard
 */
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireAdmin();

$activeProfile = getActiveProfile();
// If Fashion Kingdom profile is active and not explicitly switching to Unnatti, route to Fashion Kingdom Hub
if ($activeProfile === 'fashion_kingdom' && (!isset($_GET['profile']) || $_GET['profile'] !== 'unnatti')) {
    header('Location: ' . APP_URL . '/admin/purchases/materials.php');
    exit;
}

$pageTitle  = 'Admin Dashboard';
$activePage = 'dashboard';
$breadcrumb = [['label' => 'Dashboard']];

// ── KPI Queries ──────────────────────────────────────────────
$totalProducts  = db()->fetchOne("SELECT COUNT(*) AS c FROM products WHERE status='active'")['c'];
$totalVariants  = db()->fetchOne("SELECT COUNT(*) AS c FROM product_variants WHERE status='active'")['c'];
$totalStock     = db()->fetchOne("SELECT COALESCE(SUM(current_stock),0) AS c FROM product_variants WHERE status='active'")['c'];
$lowStockCount  = (int)(db()->fetchOne("SELECT COUNT(*) AS c FROM product_variants WHERE current_stock <= min_stock_alert AND current_stock > 0 AND status='active'")['c'] ?? 0);
$outOfStock     = (int)(db()->fetchOne("SELECT COUNT(*) AS c FROM product_variants WHERE current_stock = 0 AND status='active'")['c'] ?? 0);
$totalPurchaseVal = db()->fetchOne("SELECT COALESCE(SUM(pi.total_amount),0) AS c FROM purchase_items pi JOIN purchases p ON pi.purchase_id=p.id WHERE p.status='active'")['c'];
$todaySales     = db()->fetchOne("SELECT COALESCE(SUM(grand_total),0) AS c FROM sales WHERE DATE(sale_date)=CURDATE() AND status='active'")['c'];
$todayBills     = db()->fetchOne("SELECT COUNT(*) AS c FROM sales WHERE DATE(sale_date)=CURDATE() AND status='active'")['c'];
$monthSales     = db()->fetchOne("SELECT COALESCE(SUM(grand_total),0) AS c FROM sales WHERE YEAR(sale_date)=YEAR(NOW()) AND MONTH(sale_date)=MONTH(NOW()) AND status='active'")['c'];
$totalCustomers = db()->fetchOne("SELECT COUNT(*) AS c FROM customers WHERE status='active' AND customer_type != 'walk-in'")['c'];
$totalEmployees = db()->fetchOne("SELECT COUNT(*) AS c FROM users WHERE role='employee' AND status='active'")['c'];

// Gross profit estimate
$profitData = db()->fetchOne(
    "SELECT COALESCE(SUM(si.line_total),0) AS total_revenue,
            COALESCE(SUM(si.purchase_cost_snapshot * si.qty),0) AS total_cost
     FROM sale_items si JOIN sales s ON si.sale_id=s.id WHERE s.status='active'"
);
$grossProfit = ($profitData['total_revenue'] ?? 0) - ($profitData['total_cost'] ?? 0);

// ── Chart Data ────────────────────────────────────────────────
// Daily sales (last 14 days)
$dailySales = db()->fetchAll(
    "SELECT DATE_FORMAT(sale_date,'%d %b') AS label, COALESCE(SUM(grand_total),0) AS total
     FROM sales WHERE sale_date >= DATE_SUB(CURDATE(),INTERVAL 13 DAY) AND status='active'
     GROUP BY sale_date ORDER BY sale_date ASC"
);

// Monthly sales (last 6 months)
$monthlySales = db()->fetchAll(
    "SELECT DATE_FORMAT(sale_date,'%b %Y') AS label, COALESCE(SUM(grand_total),0) AS total
     FROM sales WHERE sale_date >= DATE_SUB(CURDATE(),INTERVAL 5 MONTH) AND status='active'
     GROUP BY YEAR(sale_date), MONTH(sale_date) ORDER BY sale_date ASC"
);

// Top 5 selling products
$topProducts = db()->fetchAll(
    "SELECT si.product_name_snapshot AS name, SUM(si.qty) AS qty_sold, SUM(si.line_total) AS revenue
     FROM sale_items si JOIN sales s ON si.sale_id=s.id WHERE s.status='active'
     GROUP BY si.sku_snapshot, si.product_name_snapshot ORDER BY qty_sold DESC LIMIT 5"
);

// Category-wise sales
$categorySales = db()->fetchAll(
    "SELECT COALESCE(c.name,'Uncategorized') AS label, SUM(si.line_total) AS total
     FROM sale_items si
     JOIN sales s ON si.sale_id=s.id
     JOIN product_variants pv ON si.variant_id=pv.id
     JOIN products p ON pv.product_id=p.id
     LEFT JOIN categories c ON p.category_id=c.id
     WHERE s.status='active'
     GROUP BY c.id ORDER BY total DESC LIMIT 6"
);

// Employee-wise sales
$empSales = db()->fetchAll(
    "SELECT u.full_name, COUNT(s.id) AS bills, COALESCE(SUM(s.grand_total),0) AS total
     FROM sales s JOIN users u ON s.created_by=u.id
     WHERE s.status='active' AND MONTH(s.sale_date)=MONTH(NOW())
     GROUP BY u.id ORDER BY total DESC LIMIT 5"
);

// Recent bills
$recentBills = db()->fetchAll(
    "SELECT s.invoice_no, s.customer_name, s.grand_total, s.sale_date, s.payment_status, s.status, u.full_name AS employee
     FROM sales s LEFT JOIN users u ON s.created_by=u.id
     ORDER BY s.id DESC LIMIT 8"
);

// Recent stock entries
$recentStock = db()->fetchAll(
    "SELECT sm.created_at, sm.qty_change, sm.movement_type, pv.sku, p.name AS product_name, u.full_name AS employee
     FROM stock_movements sm
     JOIN product_variants pv ON sm.variant_id=pv.id
     JOIN products p ON pv.product_id=p.id
     LEFT JOIN users u ON sm.created_by=u.id
     WHERE sm.movement_type='purchase'
     ORDER BY sm.id DESC LIMIT 6"
);

// Recent activity
$recentActivity = db()->fetchAll(
    "SELECT al.action, al.module, al.created_at, u.full_name
     FROM activity_logs al LEFT JOIN users u ON al.user_id=u.id
     ORDER BY al.id DESC LIMIT 10"
);

include dirname(__DIR__) . '/includes/header.php';
?>
<script>window.APP_URL = '<?= APP_URL ?>';</script>

<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title">Dashboard</h1>
    <p class="page-subtitle">Welcome back, <strong><?= e($user['name']) ?></strong> — <?= date('l, d F Y') ?></p>
  </div>
  <div class="page-header-right">
    <a href="<?= APP_URL ?>/admin/billing/create.php" class="btn btn-primary">
      <i class="fas fa-plus"></i> New Bill
    </a>
    <a href="<?= APP_URL ?>/admin/purchases/add.php" class="btn btn-outline-primary">
      <i class="fas fa-shopping-cart"></i> Add Stock
    </a>
  </div>
</div>

<!-- ── KPI CARDS ─────────────────────────────────────────── -->
<div class="stats-grid">
  <div class="stat-card stat-card-primary">
    <div class="stat-icon"><i class="fas fa-box"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= $totalProducts ?></div>
      <div class="stat-label">Total Products</div>
      <div class="stat-trend up"><i class="fas fa-layer-group"></i> <?= $totalVariants ?> variants</div>
    </div>
  </div>
  <div class="stat-card stat-card-success">
    <div class="stat-icon"><i class="fas fa-warehouse"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($totalStock) ?></div>
      <div class="stat-label">Total Stock Units</div>
    </div>
  </div>
  <div class="stat-card stat-card-secondary">
    <div class="stat-icon"><i class="fas fa-rupee-sign"></i></div>
    <div class="stat-body">
      <div class="stat-value" style="font-size:1.2rem"><?= formatCurrency($totalPurchaseVal) ?></div>
      <div class="stat-label">Total Purchase Value</div>
    </div>
  </div>
  <div class="stat-card stat-card-warning">
    <div class="stat-icon"><i class="fas fa-chart-line"></i></div>
    <div class="stat-body">
      <div class="stat-value" style="font-size:1.2rem"><?= formatCurrency($todaySales) ?></div>
      <div class="stat-label">Today's Sales</div>
      <div class="stat-trend"><i class="fas fa-receipt"></i> <?= $todayBills ?> bill(s)</div>
    </div>
  </div>
  <div class="stat-card stat-card-primary">
    <div class="stat-icon"><i class="fas fa-calendar-alt"></i></div>
    <div class="stat-body">
      <div class="stat-value" style="font-size:1.2rem"><?= formatCurrency($monthSales) ?></div>
      <div class="stat-label">This Month's Sales</div>
    </div>
  </div>
  <div class="stat-card stat-card-success">
    <div class="stat-icon"><i class="fas fa-users"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= $totalCustomers ?></div>
      <div class="stat-label">Customers</div>
    </div>
  </div>
  <div class="stat-card stat-card-warning">
    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($lowStockCount) ?></div>
      <div class="stat-label">Low Stock Items</div>
      <?php if ($outOfStock > 0): ?>
      <div class="stat-trend down"><i class="fas fa-times-circle"></i> <?= $outOfStock ?> out of stock</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="stat-card stat-card-secondary">
    <div class="stat-icon"><i class="fas fa-hand-holding-usd"></i></div>
    <div class="stat-body">
      <div class="stat-value" style="font-size:1.2rem"><?= formatCurrency($grossProfit) ?></div>
      <div class="stat-label">Gross Profit (All-time)</div>
    </div>
  </div>
</div>

<!-- ── CHARTS ROW ─────────────────────────────────────────── -->
<div class="grid-2">
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fas fa-chart-area"></i> Daily Sales (Last 14 Days)</span>
    </div>
    <div class="card-body">
      <div class="chart-container"><canvas id="dailySalesChart"></canvas></div>
    </div>
  </div>
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fas fa-chart-bar"></i> Monthly Sales Trend</span>
    </div>
    <div class="card-body">
      <div class="chart-container"><canvas id="monthlySalesChart"></canvas></div>
    </div>
  </div>
</div>

<div class="grid-2" style="margin-top:0">
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fas fa-trophy"></i> Top Selling Products</span>
    </div>
    <div class="card-body">
      <div class="chart-container chart-container-sm"><canvas id="topProductsChart"></canvas></div>
    </div>
  </div>
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fas fa-tags"></i> Category-wise Sales</span>
    </div>
    <div class="card-body">
      <div class="chart-container chart-container-sm"><canvas id="categoryChart"></canvas></div>
    </div>
  </div>
</div>

<!-- ── EMPLOYEE PERFORMANCE ─────────────────────────────── -->
<?php if (!empty($empSales)): ?>
<div class="card">
  <div class="card-header">
    <span class="card-title"><i class="fas fa-user-tie"></i> Employee Sales This Month</span>
  </div>
  <div class="card-body p-0">
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Employee</th>
            <th class="text-center">Bills</th>
            <th class="text-right">Total Sales</th>
            <th>Performance</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $maxSale = max(array_column($empSales, 'total')) ?: 1;
          foreach ($empSales as $emp): ?>
          <tr>
            <td>
              <div class="d-flex align-center gap-2">
                <div class="user-avatar-sm" style="width:32px;height:32px;font-size:0.8rem"><?= strtoupper(substr($emp['full_name'],0,1)) ?></div>
                <strong><?= e($emp['full_name']) ?></strong>
              </div>
            </td>
            <td class="text-center"><span class="badge badge-primary"><?= $emp['bills'] ?></span></td>
            <td class="text-right text-bold"><?= formatCurrency($emp['total']) ?></td>
            <td>
              <div style="background:#e2e8f0;border-radius:99px;height:8px;overflow:hidden">
                <div style="background:linear-gradient(90deg,#2563eb,#7c3aed);height:100%;width:<?= round($emp['total']/$maxSale*100) ?>%;border-radius:99px;transition:width 0.8s ease"></div>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ── RECENT BILLS + ACTIVITY ──────────────────────────── -->
<div class="grid-2" style="margin-top:0">
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fas fa-receipt"></i> Recent Bills</span>
      <a href="<?= APP_URL ?>/admin/billing/index.php" class="btn btn-sm btn-light">View All</a>
    </div>
    <div class="card-body p-0">
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>Invoice</th>
              <th>Customer</th>
              <th class="text-right">Amount</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recentBills)): ?>
            <tr><td colspan="4"><div class="empty-state" style="padding:30px"><i class="fas fa-receipt"></i><h4>No bills yet</h4></div></td></tr>
            <?php else: foreach ($recentBills as $bill): ?>
            <tr>
              <td>
                <a href="<?= APP_URL ?>/admin/billing/view.php?id=<?= $bill['invoice_no'] ?>" style="font-weight:600;font-size:0.83rem">
                  <?= e($bill['invoice_no']) ?>
                </a>
                <div style="font-size:0.72rem;color:#64748b"><?= formatDate($bill['sale_date']) ?></div>
              </td>
              <td><?= e($bill['customer_name']) ?></td>
              <td class="text-right text-bold"><?= formatCurrency($bill['grand_total']) ?></td>
              <td><?= paymentBadge($bill['payment_status']) ?></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fas fa-history"></i> Recent Activity</span>
    </div>
    <div class="card-body p-0">
      <div style="max-height:360px;overflow-y:auto">
        <?php if (empty($recentActivity)): ?>
        <div class="empty-state" style="padding:30px"><i class="fas fa-history"></i><h4>No activity yet</h4></div>
        <?php else: foreach ($recentActivity as $log): ?>
        <div style="display:flex;gap:12px;padding:12px 18px;border-bottom:1px solid #f1f5f9;align-items:flex-start">
          <div style="width:32px;height:32px;border-radius:50%;background:var(--primary-light);display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <i class="fas fa-<?= $log['module'] === 'auth' ? 'sign-in-alt' : ($log['module'] === 'billing' ? 'receipt' : 'box') ?> text-primary" style="font-size:0.8rem"></i>
          </div>
          <div style="flex:1;min-width:0">
            <div style="font-size:0.83rem;font-weight:500;color:#1e293b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($log['action']) ?></div>
            <div style="font-size:0.73rem;color:#94a3b8;margin-top:2px">
              <?= e($log['full_name'] ?? 'System') ?> &bull; <?= formatDateTime($log['created_at']) ?>
            </div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ── Chart.js Init ─────────────────────────────────────── -->
<script>
const chartDefaults = {
  font: { family: 'Poppins', size: 11 },
  color: '#64748b'
};
Chart.defaults.font.family = 'Poppins';
Chart.defaults.color = '#64748b';

// Daily Sales
new Chart(document.getElementById('dailySalesChart'), {
  type: 'line',
  data: {
    labels: <?= json_encode(array_column($dailySales, 'label')) ?>,
    datasets: [{
      label: 'Sales (₹)',
      data: <?= json_encode(array_map(fn($r) => (float)$r['total'], $dailySales)) ?>,
      borderColor: '#2563eb',
      backgroundColor: 'rgba(37,99,235,0.08)',
      borderWidth: 2.5,
      pointBackgroundColor: '#2563eb',
      pointRadius: 4,
      pointHoverRadius: 6,
      tension: 0.4,
      fill: true
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(c) { return '₹' + parseFloat(c.raw).toLocaleString('en-IN', {minimumFractionDigits: 2}); } } } },
    scales: {
      y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { callback: function(v) { return '₹' + (v>=1000?(v/1000).toFixed(1)+'k':v); } } },
      x: { grid: { display: false } }
    }
  }
});

// Monthly Sales
new Chart(document.getElementById('monthlySalesChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($monthlySales, 'label')) ?>,
    datasets: [{
      label: 'Sales (₹)',
      data: <?= json_encode(array_map(fn($r) => (float)$r['total'], $monthlySales)) ?>,
      backgroundColor: 'rgba(124,58,237,0.8)',
      borderRadius: 8,
      borderSkipped: false
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false }, tooltip: { callbacks: { label: function(c) { return '₹' + parseFloat(c.raw).toLocaleString('en-IN', {minimumFractionDigits: 2}); } } } },
    scales: {
      y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { callback: function(v) { return '₹' + (v>=1000?(v/1000).toFixed(1)+'k':v); } } },
      x: { grid: { display: false } }
    }
  }
});

// Top Products
new Chart(document.getElementById('topProductsChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_map(fn($r) => strlen($r['name'])>18?substr($r['name'],0,18).'…':$r['name'], $topProducts)) ?>,
    datasets: [{
      label: 'Units Sold',
      data: <?= json_encode(array_column($topProducts, 'qty_sold')) ?>,
      backgroundColor: ['#2563eb','#7c3aed','#059669','#d97706','#0891b2'],
      borderRadius: 6
    }]
  },
  options: {
    indexAxis: 'y',
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { beginAtZero: true, grid: { color: '#f1f5f9' } },
      y: { grid: { display: false } }
    }
  }
});

// Category chart
new Chart(document.getElementById('categoryChart'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode(array_column($categorySales, 'label')) ?>,
    datasets: [{
      data: <?= json_encode(array_map(fn($r) => (float)$r['total'], $categorySales)) ?>,
      backgroundColor: ['#2563eb','#7c3aed','#059669','#d97706','#0891b2','#dc2626'],
      borderWidth: 0,
      hoverOffset: 8
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    cutout: '65%',
    plugins: {
      legend: { position: 'right', labels: { usePointStyle: true, pointStyle: 'circle', padding: 12 } },
      tooltip: { callbacks: { label: function(c) { return c.label + ': ₹' + parseFloat(c.raw).toLocaleString('en-IN', {minimumFractionDigits: 2}); } } }
    }
  }
});
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
