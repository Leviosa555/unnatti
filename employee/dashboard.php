<?php
/**
 * Employee Dashboard (Unnatti Enterprises)
 * Tailored for retail boutique sales staff with strict financial privacy permissions.
 */
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
requireLogin();

// Redirect admins to admin dashboard
if (isAdmin()) {
    header('Location: ' . APP_URL . '/admin/dashboard.php');
    exit;
}

$userId = currentUserId();
$user   = currentUser();

// ── KPI Queries (Strictly restricted for Employee) ─────────────
// 1. Total Products
$totalProducts = (int)(db()->fetchOne(
    "SELECT COUNT(*) AS c FROM products 
     WHERE status='active' AND (item_type = 'product' OR item_type IS NULL OR item_type != 'material')"
)['c'] ?? 0);

// 2. Total Stock Units
$totalStock = (int)(db()->fetchOne(
    "SELECT COALESCE(SUM(pv.current_stock),0) AS c 
     FROM product_variants pv 
     JOIN products p ON pv.product_id=p.id 
     WHERE pv.status='active' AND p.status='active' AND (p.item_type = 'product' OR p.item_type IS NULL OR p.item_type != 'material')"
)['c'] ?? 0);

// 3. Bills Issued by this specific employee
$myBillsCount = (int)(db()->fetchOne(
    "SELECT COUNT(*) AS c FROM sales WHERE created_by=? AND status='active'",
    [$userId]
)['c'] ?? 0);

$myMonthBills = (int)(db()->fetchOne(
    "SELECT COUNT(*) AS c FROM sales 
     WHERE created_by=? AND MONTH(sale_date)=MONTH(NOW()) AND YEAR(sale_date)=YEAR(NOW()) AND status='active'",
    [$userId]
)['c'] ?? 0);

// 4. Low Stock Items
$lowStockCount = (int)(db()->fetchOne(
    "SELECT COUNT(*) AS c 
     FROM product_variants pv 
     JOIN products p ON pv.product_id=p.id 
     WHERE pv.current_stock <= pv.min_stock_alert AND pv.current_stock > 0 AND pv.status='active' AND p.status='active' AND (p.item_type = 'product' OR p.item_type IS NULL OR p.item_type != 'material')"
)['c'] ?? 0);

$outStockCount = (int)(db()->fetchOne(
    "SELECT COUNT(*) AS c 
     FROM product_variants pv 
     JOIN products p ON pv.product_id=p.id 
     WHERE pv.current_stock = 0 AND pv.status='active' AND p.status='active' AND (p.item_type = 'product' OR p.item_type IS NULL OR p.item_type != 'material')"
)['c'] ?? 0);

$pageTitle  = 'Employee Dashboard';
$activePage = 'dashboard';
$breadcrumb = [['label' => 'Dashboard']];
include dirname(__DIR__) . '/includes/header.php';
?>
<script>window.APP_URL = '<?= APP_URL ?>';</script>

<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title"><i class="fas fa-th-large text-primary" style="margin-right:10px"></i>Dashboard</h1>
    <p class="page-subtitle">Welcome back, <strong><?= e($user['name']) ?></strong> &mdash; <?= date('l, d F Y') ?></p>
  </div>
  <div class="page-header-right">
    <a href="<?= APP_URL ?>/employee/billing/create.php" class="btn btn-primary btn-lg">
      <i class="fas fa-plus"></i> New Bill
    </a>
  </div>
</div>

<!-- ==================== 4 PERMITTED KPI CARDS ==================== -->
<div class="stats-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom: 24px;">
  <!-- 1. Total Products -->
  <a href="<?= APP_URL ?>/employee/products/index.php" class="stat-card stat-card-primary" title="View shop garments & products catalog">
    <div class="stat-icon"><i class="fas fa-tshirt"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($totalProducts) ?></div>
      <div class="stat-label">Total Products</div>
      <div class="stat-trend up"><i class="fas fa-check"></i> Active catalog</div>
    </div>
    <span class="stat-arrow"><i class="fas fa-arrow-right"></i></span>
  </a>

  <!-- 2. Total Stock Units -->
  <a href="<?= APP_URL ?>/employee/products/index.php" class="stat-card stat-card-info" title="View live boutique inventory stock">
    <div class="stat-icon"><i class="fas fa-boxes"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($totalStock) ?></div>
      <div class="stat-label">Total Stock Units</div>
      <div class="stat-trend up"><i class="fas fa-warehouse"></i> Live inventory</div>
    </div>
    <span class="stat-arrow"><i class="fas fa-arrow-right"></i></span>
  </a>

  <!-- 3. Bills Issued by You -->
  <a href="<?= APP_URL ?>/employee/billing/index.php" class="stat-card stat-card-success" title="View bills and invoices issued by you">
    <div class="stat-icon"><i class="fas fa-file-invoice"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($myBillsCount) ?></div>
      <div class="stat-label">My Bills Issued</div>
      <div class="stat-trend up"><i class="fas fa-receipt"></i> <?= number_format($myMonthBills) ?> this month</div>
    </div>
    <span class="stat-arrow"><i class="fas fa-arrow-right"></i></span>
  </a>

  <!-- 4. Low Stock Items -->
  <a href="<?= APP_URL ?>/employee/products/index.php?filter=low_stock" class="stat-card stat-card-warning" title="View low stock and out-of-stock items">
    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($lowStockCount) ?></div>
      <div class="stat-label">Low Stock Items</div>
      <?php if ($outStockCount > 0): ?>
      <div class="stat-trend down"><i class="fas fa-times-circle"></i> <?= $outStockCount ?> out of stock</div>
      <?php else: ?>
      <div class="stat-trend"><i class="fas fa-bell"></i> Stock alert</div>
      <?php endif; ?>
    </div>
    <span class="stat-arrow"><i class="fas fa-arrow-right"></i></span>
  </a>
</div>

<!-- ==================== EMPLOYEE QUICK ACTIONS & SHIFT INFO ==================== -->
<div class="grid-2">
  <!-- Quick Actions Card -->
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fas fa-bolt text-primary"></i> Quick Actions</span>
    </div>
    <div class="card-body">
      <div style="display:grid;grid-template-columns:repeat(2, 1fr);gap:14px;">
        <a href="<?= APP_URL ?>/employee/billing/create.php" class="btn btn-primary" style="padding:16px;flex-direction:column;gap:8px;display:flex;align-items:center;justify-content:center;border-radius:10px;">
          <i class="fas fa-file-invoice" style="font-size:1.6rem"></i>
          <span style="font-weight:600;font-size:0.95rem">Create Bill / Sale</span>
        </a>
        <a href="<?= APP_URL ?>/employee/billing/index.php" class="btn btn-light" style="padding:16px;flex-direction:column;gap:8px;display:flex;align-items:center;justify-content:center;border-radius:10px;border:1px solid #e2e8f0;">
          <i class="fas fa-receipt text-primary" style="font-size:1.6rem"></i>
          <span style="font-weight:600;font-size:0.95rem">My Bills &amp; Invoices</span>
        </a>
        <a href="<?= APP_URL ?>/employee/products/index.php" class="btn btn-light" style="padding:16px;flex-direction:column;gap:8px;display:flex;align-items:center;justify-content:center;border-radius:10px;border:1px solid #e2e8f0;">
          <i class="fas fa-tshirt text-primary" style="font-size:1.6rem"></i>
          <span style="font-weight:600;font-size:0.95rem">Shop Products</span>
        </a>
        <a href="<?= APP_URL ?>/employee/products/add.php" class="btn btn-light" style="padding:16px;flex-direction:column;gap:8px;display:flex;align-items:center;justify-content:center;border-radius:10px;border:1px solid #e2e8f0;">
          <i class="fas fa-plus-circle text-primary" style="font-size:1.6rem"></i>
          <span style="font-weight:600;font-size:0.95rem">Add New Product</span>
        </a>
      </div>
    </div>
  </div>

  <!-- Employee Shift & Store Info Card -->
  <div class="card">
    <div class="card-header">
      <span class="card-title"><i class="fas fa-id-badge text-primary"></i> Staff Profile &amp; Counter Info</span>
    </div>
    <div class="card-body">
      <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid #f1f5f9;">
        <div style="width:54px;height:54px;border-radius:50%;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:700;">
          <?= strtoupper(substr($user['name'] ?? 'E', 0, 1)) ?>
        </div>
        <div>
          <h3 style="font-size:1.05rem;font-weight:700;color:#1e293b;margin:0 0 4px 0;"><?= e($user['name']) ?></h3>
          <span class="badge badge-primary" style="font-size:0.75rem;"><i class="fas fa-user-check"></i> Sales Staff / Counter</span>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px;font-size:0.85rem;">
        <div style="background:#f8fafc;padding:10px 14px;border-radius:8px;border:1px solid #e2e8f0;">
          <span style="color:#64748b;font-size:0.75rem;display:block;">Logged-in User</span>
          <strong style="color:#1e293b"><?= e($user['username'] ?? '') ?></strong>
        </div>
        <div style="background:#f8fafc;padding:10px 14px;border-radius:8px;border:1px solid #e2e8f0;">
          <span style="color:#64748b;font-size:0.75rem;display:block;">Discount Permission</span>
          <strong style="color:<?= !empty($user['can_apply_discount']) ? '#16a34a' : '#64748b' ?>">
            <?= !empty($user['can_apply_discount']) ? 'Allowed (up to ' . ($user['max_discount_pct'] ?? 0) . '%)' : 'Standard Rates Only' ?>
          </strong>
        </div>
        <div style="background:#f8fafc;padding:10px 14px;border-radius:8px;border:1px solid #e2e8f0;">
          <span style="color:#64748b;font-size:0.75rem;display:block;">Store Name</span>
          <strong style="color:#1e293b"><?= e(getSetting('business_name', 'Unnatti Enterprises')) ?></strong>
        </div>
        <div style="background:#f8fafc;padding:10px 14px;border-radius:8px;border:1px solid #e2e8f0;">
          <span style="color:#64748b;font-size:0.75rem;display:block;">Today's Invoices</span>
          <strong style="color:#2563eb"><?= $myMonthBills ?> bill(s) issued this month</strong>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
