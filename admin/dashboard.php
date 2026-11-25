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

// ── KPI Queries (Unnatti Shop Products & Boutique Inventory) ─────────
$totalProducts  = (int)(db()->fetchOne("SELECT COUNT(*) AS c FROM products WHERE status='active' AND (item_type = 'product' OR item_type IS NULL OR item_type != 'material')")['c'] ?? 0);
$totalVariants  = (int)(db()->fetchOne("SELECT COUNT(*) AS c FROM product_variants pv JOIN products p ON pv.product_id=p.id WHERE pv.status='active' AND p.status='active' AND (p.item_type = 'product' OR p.item_type IS NULL OR p.item_type != 'material')")['c'] ?? 0);
$totalStock     = (int)(db()->fetchOne("SELECT COALESCE(SUM(pv.current_stock),0) AS c FROM product_variants pv JOIN products p ON pv.product_id=p.id WHERE pv.status='active' AND p.status='active' AND (p.item_type = 'product' OR p.item_type IS NULL OR p.item_type != 'material')")['c'] ?? 0);
$lowStockCount  = (int)(db()->fetchOne("SELECT COUNT(*) AS c FROM product_variants pv JOIN products p ON pv.product_id=p.id WHERE pv.current_stock <= pv.min_stock_alert AND pv.current_stock > 0 AND pv.status='active' AND p.status='active' AND (p.item_type = 'product' OR p.item_type IS NULL OR p.item_type != 'material')")['c'] ?? 0);
$outOfStock     = (int)(db()->fetchOne("SELECT COUNT(*) AS c FROM product_variants pv JOIN products p ON pv.product_id=p.id WHERE pv.current_stock = 0 AND pv.status='active' AND p.status='active' AND (p.item_type = 'product' OR p.item_type IS NULL OR p.item_type != 'material')")['c'] ?? 0);
$allTimeSales   = db()->fetchOne("SELECT COALESCE(SUM(grand_total),0) AS total, COUNT(*) AS bills FROM sales WHERE status='active'");
$todaySales     = db()->fetchOne("SELECT COALESCE(SUM(grand_total),0) AS c FROM sales WHERE DATE(sale_date)=CURDATE() AND status='active'")['c'];
$todayBills     = db()->fetchOne("SELECT COUNT(*) AS c FROM sales WHERE DATE(sale_date)=CURDATE() AND status='active'")['c'];
$monthSales     = db()->fetchOne("SELECT COALESCE(SUM(grand_total),0) AS c FROM sales WHERE YEAR(sale_date)=YEAR(NOW()) AND MONTH(sale_date)=MONTH(NOW()) AND status='active'")['c'];
$monthBills     = db()->fetchOne("SELECT COUNT(*) AS c FROM sales WHERE YEAR(sale_date)=YEAR(NOW()) AND MONTH(sale_date)=MONTH(NOW()) AND status='active'")['c'];
$totalBills     = (int)($allTimeSales['bills'] ?? 0);
$totalEmployees = db()->fetchOne("SELECT COUNT(*) AS c FROM users WHERE role='employee' AND status='active'")['c'];

// Gross profit estimate
$profitData = db()->fetchOne(
    "SELECT COALESCE(SUM(si.line_total),0) AS total_revenue,
            COALESCE(SUM(si.purchase_cost_snapshot * si.qty),0) AS total_cost
     FROM sale_items si JOIN sales s ON si.sale_id=s.id WHERE s.status='active'"
);
$grossProfit = ($profitData['total_revenue'] ?? 0) - ($profitData['total_cost'] ?? 0);

// Employee-wise sales this month
$empSales = db()->fetchAll(
    "SELECT u.full_name, COUNT(s.id) AS bills, COALESCE(SUM(s.grand_total),0) AS total
     FROM sales s JOIN users u ON s.created_by=u.id
     WHERE s.status='active' AND MONTH(s.sale_date)=MONTH(NOW()) AND YEAR(s.sale_date)=YEAR(NOW())
     GROUP BY u.id ORDER BY total DESC LIMIT 5"
);

// Recent bills
$recentBills = db()->fetchAll(
    "SELECT s.id, s.invoice_no, s.customer_name, s.grand_total, s.sale_date, s.payment_status, s.status, u.full_name AS employee
     FROM sales s LEFT JOIN users u ON s.created_by=u.id
     ORDER BY s.id DESC LIMIT 8"
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

<style>
/* Clickable KPI Cards Styling */
a.stat-card {
  text-decoration: none !important;
  color: inherit !important;
  cursor: pointer;
  position: relative;
  transition: all 0.22s ease-in-out;
  display: flex;
}
a.stat-card:hover {
  transform: translateY(-3px);
  box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08), 0 8px 10px -6px rgba(0, 0, 0, 0.04);
  border-color: #93c5fd;
}
a.stat-card .stat-arrow {
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
a.stat-card:hover .stat-arrow {
  background: var(--primary, #2563eb);
  color: #ffffff;
  transform: translateX(3px);
}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title">Dashboard</h1>
    <p class="page-subtitle">Welcome back, <strong><?= e($user['name']) ?></strong> — <?= date('l, d F Y') ?></p>
  </div>
  <div class="page-header-right">
    <a href="<?= APP_URL ?>/admin/billing/create.php" class="btn btn-primary">
      <i class="fas fa-plus"></i> New Bill
    </a>
    <a href="<?= APP_URL ?>/admin/products/add.php" class="btn btn-outline-primary">
      <i class="fas fa-tshirt"></i> Add Product
    </a>
  </div>
</div>

<!-- ── KPI CARDS (Interactive & Clickable) ────────────────── -->
<div class="stats-grid">
  <!-- 1. Total Products -->
  <a href="<?= APP_URL ?>/admin/products/index.php" class="stat-card stat-card-primary" title="Click to view all shop products and stock">
    <div class="stat-icon"><i class="fas fa-tshirt"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($totalProducts) ?></div>
      <div class="stat-label">Total Products</div>
      <div class="stat-trend up"><i class="fas fa-layer-group"></i> <?= number_format($totalVariants) ?> variants</div>
    </div>
    <span class="stat-arrow"><i class="fas fa-arrow-right"></i></span>
  </a>

  <!-- 2. Total Stock Units -->
  <a href="<?= APP_URL ?>/admin/products/index.php" class="stat-card stat-card-success" title="Click to view live product stock">
    <div class="stat-icon"><i class="fas fa-warehouse"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($totalStock) ?></div>
      <div class="stat-label">Total Stock Units</div>
      <div class="stat-trend up"><i class="fas fa-boxes"></i> Live inventory</div>
    </div>
    <span class="stat-arrow"><i class="fas fa-arrow-right"></i></span>
  </a>

  <!-- 3. All-time Sales -->
  <a href="<?= APP_URL ?>/admin/billing/index.php" class="stat-card stat-card-secondary" title="Click to view all billing and customer sales history">
    <div class="stat-icon"><i class="fas fa-coins"></i></div>
    <div class="stat-body">
      <div class="stat-value" style="font-size:1.2rem"><?= formatCurrency($allTimeSales['total']) ?></div>
      <div class="stat-label">All-time Sales</div>
      <div class="stat-trend up"><i class="fas fa-receipt"></i> <?= number_format($allTimeSales['bills']) ?> bill(s)</div>
    </div>
    <span class="stat-arrow"><i class="fas fa-arrow-right"></i></span>
  </a>

  <!-- 4. Today's Sales -->
  <a href="<?= APP_URL ?>/admin/billing/index.php?from=<?= date('Y-m-d') ?>&to=<?= date('Y-m-d') ?>" class="stat-card stat-card-warning" title="Click to view today's bills & sales">
    <div class="stat-icon"><i class="fas fa-receipt"></i></div>
    <div class="stat-body">
      <div class="stat-value" style="font-size:1.2rem"><?= formatCurrency($todaySales) ?></div>
      <div class="stat-label">Today's Sales</div>
      <div class="stat-trend up"><i class="fas fa-check-circle"></i> <?= $todayBills ?> bill(s) today</div>
    </div>
    <span class="stat-arrow"><i class="fas fa-arrow-right"></i></span>
  </a>

  <!-- 5. This Month's Sales -->
  <a href="<?= APP_URL ?>/admin/billing/index.php?from=<?= date('Y-m-01') ?>&to=<?= date('Y-m-d') ?>" class="stat-card stat-card-primary" title="Click to view this month's bills & sales">
    <div class="stat-icon"><i class="fas fa-calendar-alt"></i></div>
    <div class="stat-body">
      <div class="stat-value" style="font-size:1.2rem"><?= formatCurrency($monthSales) ?></div>
      <div class="stat-label">This Month's Sales</div>
      <div class="stat-trend"><i class="fas fa-calendar-check"></i> <?= date('M Y') ?></div>
    </div>
    <span class="stat-arrow"><i class="fas fa-arrow-right"></i></span>
  </a>

  <!-- 6. Total Bills Issued -->
  <a href="<?= APP_URL ?>/admin/billing/index.php" class="stat-card stat-card-success" title="Click to view all billing and invoices history">
    <div class="stat-icon"><i class="fas fa-file-invoice-dollar"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($totalBills) ?></div>
      <div class="stat-label">Total Bills Issued</div>
      <div class="stat-trend up"><i class="fas fa-receipt"></i> <?= number_format($monthBills) ?> this month</div>
    </div>
    <span class="stat-arrow"><i class="fas fa-arrow-right"></i></span>
  </a>

  <!-- 7. Low Stock Items -->
  <a href="<?= APP_URL ?>/admin/products/index.php?filter=low_stock" class="stat-card stat-card-warning" title="Click to view low stock and out of stock items">
    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($lowStockCount) ?></div>
      <div class="stat-label">Low Stock Items</div>
      <?php if ($outOfStock > 0): ?>
      <div class="stat-trend down"><i class="fas fa-times-circle"></i> <?= $outOfStock ?> out of stock</div>
      <?php else: ?>
      <div class="stat-trend"><i class="fas fa-bell"></i> Stock alert</div>
      <?php endif; ?>
    </div>
    <span class="stat-arrow"><i class="fas fa-arrow-right"></i></span>
  </a>

  <!-- 8. Gross Profit (All-time) -->
  <a href="<?= APP_URL ?>/admin/reports/index.php?report=sales_summary" class="stat-card stat-card-secondary" title="Click to view sales summary & profit report">
    <div class="stat-icon"><i class="fas fa-hand-holding-usd"></i></div>
    <div class="stat-body">
      <div class="stat-value" style="font-size:1.2rem"><?= formatCurrency($grossProfit) ?></div>
      <div class="stat-label">Gross Profit (All-time)</div>
      <div class="stat-trend up"><i class="fas fa-chart-line"></i> Total margin</div>
    </div>
    <span class="stat-arrow"><i class="fas fa-arrow-right"></i></span>
  </a>
</div>

<!-- ── EMPLOYEE SALES THIS MONTH ─────────────────────────── -->
<div class="card" style="margin-bottom:24px">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
    <span class="card-title"><i class="fas fa-user-tie text-primary" style="margin-right:8px"></i> Employee Sales This Month</span>
    <span class="text-muted" style="font-size:0.8rem;font-weight:500"><?= date('F Y') ?></span>
  </div>
  <div class="card-body p-0">
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Employee</th>
            <th class="text-center">Bills</th>
            <th class="text-right">Total Sales</th>
            <th style="width:30%">Performance</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($empSales)): ?>
          <tr><td colspan="4"><div class="empty-state" style="padding:24px"><i class="fas fa-user-tie"></i><h4>No employee sales recorded this month yet</h4></div></td></tr>
          <?php else:
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
            <td class="text-right text-bold" style="color:#0f172a"><?= formatCurrency($emp['total']) ?></td>
            <td>
              <div style="background:#e2e8f0;border-radius:99px;height:8px;overflow:hidden">
                <div style="background:linear-gradient(90deg,#2563eb,#7c3aed);height:100%;width:<?= round($emp['total']/$maxSale*100) ?>%;border-radius:99px;transition:width 0.8s ease"></div>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ── RECENT BILLS + RECENT ACTIVITY ────────────────────── -->
<div class="grid-2">
  <!-- Recent Bills -->
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
      <span class="card-title"><i class="fas fa-receipt text-primary" style="margin-right:8px"></i> Recent Bills</span>
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
                <a href="<?= APP_URL ?>/admin/billing/invoice.php?id=<?= $bill['id'] ?>" style="font-weight:600;font-size:0.83rem">
                  <?= e($bill['invoice_no']) ?>
                </a>
                <div style="font-size:0.72rem;color:#64748b"><?= formatDate($bill['sale_date']) ?></div>
              </td>
              <td>
                <div style="font-weight:500;font-size:0.84rem;color:#1e293b"><?= e($bill['customer_name']) ?></div>
                <?php if (!empty($bill['employee'])): ?>
                <div style="font-size:0.72rem;color:#94a3b8"><i class="fas fa-user-tie" style="font-size:0.65rem"></i> <?= e($bill['employee']) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-right text-bold" style="color:#0f172a"><?= formatCurrency($bill['grand_total']) ?></td>
              <td><?= paymentBadge($bill['payment_status'], $bill['status']) ?></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Recent Activity -->
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fas fa-history text-primary" style="margin-right:8px"></i> Recent Activity</span>
    </div>
    <div class="card-body p-0">
      <div style="max-height:380px;overflow-y:auto">
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

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
