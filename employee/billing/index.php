<?php
/**
 * Employee: Bills & Invoices History
 * Shows only the sales history of the logged-in staff member without store-wide financials.
 */
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireLogin();

// Redirect admin to admin bills overview
if (isAdmin()) {
    header('Location: ' . APP_URL . '/admin/billing/index.php');
    exit;
}

$userId   = currentUserId();
$search   = sanitize($_GET['search'] ?? '');
$status   = sanitize($_GET['status'] ?? '');
$payMode  = sanitize($_GET['payment'] ?? '');
$from     = sanitize($_GET['from'] ?? '');
$to       = sanitize($_GET['to'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = DEFAULT_PAGE_SIZE;

// Strictly filtered to the logged-in employee only
$where  = ['s.created_by = ?'];
$params = [$userId];

if ($search !== '') {
    $where[]  = '(s.invoice_no LIKE ? OR s.customer_name LIKE ? OR s.customer_mobile LIKE ?)';
    $s        = "%$search%";
    $params   = array_merge($params, [$s, $s, $s]);
}
if ($status !== '') {
    $where[]  = 's.status = ?';
    $params[] = $status;
}
if ($payMode !== '') {
    $where[]  = 's.payment_method = ?';
    $params[] = $payMode;
}
if ($from !== '') {
    $where[]  = 's.sale_date >= ?';
    $params[] = $from;
}
if ($to !== '') {
    $where[]  = 's.sale_date <= ?';
    $params[] = $to;
}

$whereStr = implode(' AND ', $where);

$total = db()->fetchOne("SELECT COUNT(*) AS c FROM sales s WHERE $whereStr", $params)['c'] ?? 0;
$pagination = paginate($total, $page, $perPage);

$bills = db()->fetchAll(
    "SELECT s.id, s.invoice_no, s.sale_date, s.customer_name, s.customer_mobile,
            s.grand_total, s.amount_received, s.balance_due, s.payment_status, s.payment_method, s.status,
            (SELECT COUNT(*) FROM sale_items WHERE sale_id=s.id) AS item_count
     FROM sales s
     WHERE $whereStr 
     ORDER BY s.id DESC 
     LIMIT $perPage OFFSET {$pagination['offset']}",
    $params
);

// Employee-only KPI stats (counts only, no company revenues or margins)
$todayBills = (int)(db()->fetchOne(
    "SELECT COUNT(*) AS c FROM sales WHERE created_by=? AND DATE(sale_date)=CURDATE() AND status='active'",
    [$userId]
)['c'] ?? 0);

$monthBills = (int)(db()->fetchOne(
    "SELECT COUNT(*) AS c FROM sales WHERE created_by=? AND MONTH(sale_date)=MONTH(NOW()) AND YEAR(sale_date)=YEAR(NOW()) AND status='active'",
    [$userId]
)['c'] ?? 0);

$allTimeBills = (int)(db()->fetchOne(
    "SELECT COUNT(*) AS c FROM sales WHERE created_by=? AND status='active'",
    [$userId]
)['c'] ?? 0);

$pageTitle  = 'Bills & Invoices';
$activePage = 'billing';
$breadcrumb = [['label' => 'Sales & Billing', 'url' => APP_URL . '/employee/billing/index.php'], ['label' => 'Bills & Invoices']];
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<script>window.APP_URL = '<?= APP_URL ?>';</script>

<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title"><i class="fas fa-receipt text-primary" style="margin-right:10px"></i>Bills &amp; Invoices</h1>
    <p class="page-subtitle">Showing counter invoices issued by you</p>
  </div>
  <div class="page-header-right">
    <a href="<?= APP_URL ?>/employee/billing/create.php" class="btn btn-primary"><i class="fas fa-plus"></i> New Bill</a>
  </div>
</div>

<!-- Employee KPI Summary Cards (Counts only) -->
<div class="stats-grid" style="grid-template-columns: repeat(3, 1fr); margin-bottom: 20px;">
  <div class="stat-card stat-card-primary">
    <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($todayBills) ?></div>
      <div class="stat-label">Bills Issued Today</div>
      <div class="stat-trend up"><i class="fas fa-check"></i> Today's counter sales</div>
    </div>
  </div>
  <div class="stat-card stat-card-success">
    <div class="stat-icon"><i class="fas fa-calendar-alt"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($monthBills) ?></div>
      <div class="stat-label">Bills This Month</div>
      <div class="stat-trend up"><i class="fas fa-receipt"></i> <?= date('F Y') ?></div>
    </div>
  </div>
  <div class="stat-card stat-card-info">
    <div class="stat-icon"><i class="fas fa-file-invoice"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($allTimeBills) ?></div>
      <div class="stat-label">Total Bills Issued</div>
      <div class="stat-trend up"><i class="fas fa-user-check"></i> Your all-time total</div>
    </div>
  </div>
</div>

<div class="card">
  <!-- Search & Filters -->
  <form method="GET" class="table-filters" style="display:flex;align-items:center;flex-wrap:wrap;gap:10px;padding:12px 18px;background:#fafbfc;border-bottom:1px solid #e2e8f0;">
    <div style="position:relative;width:220px;">
      <i class="fas fa-search" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:0.8rem;pointer-events:none;"></i>
      <input type="text" name="search" class="form-control" placeholder="Search invoice, customer..." value="<?= e($search) ?>" style="padding-left:32px;height:36px;font-size:0.83rem;">
    </div>
    <select name="status" class="form-select" style="height:36px;font-size:0.83rem;width:130px;">
      <option value="">All Status</option>
      <option value="active" <?= $status==='active'?'selected':'' ?>>Active</option>
      <option value="cancelled" <?= $status==='cancelled'?'selected':'' ?>>Cancelled</option>
    </select>
    <select name="payment" class="form-select" style="height:36px;font-size:0.83rem;width:140px;">
      <option value="">All Payments</option>
      <option value="cash" <?= $payMode==='cash'?'selected':'' ?>>Cash</option>
      <option value="upi" <?= $payMode==='upi'?'selected':'' ?>>UPI</option>
      <option value="card" <?= $payMode==='card'?'selected':'' ?>>Card</option>
      <option value="bank_transfer" <?= $payMode==='bank_transfer'?'selected':'' ?>>Bank Transfer</option>
    </select>
    <input type="date" name="from" class="form-control" value="<?= e($from) ?>" style="height:36px;font-size:0.83rem;width:135px;" title="From date">
    <input type="date" name="to" class="form-control" value="<?= e($to) ?>" style="height:36px;font-size:0.83rem;width:135px;" title="To date">
    <button type="submit" class="btn btn-primary btn-sm" style="height:36px;padding:0 14px;"><i class="fas fa-search"></i> Filter</button>
    <a href="<?= APP_URL ?>/employee/billing/index.php" class="btn btn-light btn-sm" style="height:36px;line-height:34px;padding:0 12px;"><i class="fas fa-times"></i> Clear</a>
  </form>

  <!-- Table -->
  <div class="table-wrapper">
    <table>
      <thead>
        <tr>
          <th>Invoice No</th>
          <th>Sale Date</th>
          <th>Customer</th>
          <th class="text-center">Items</th>
          <th class="text-right">Bill Total</th>
          <th class="text-center">Payment Mode</th>
          <th class="text-center">Payment Status</th>
          <th class="text-center">Status</th>
          <th class="text-right no-print">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($bills)): ?>
        <tr>
          <td colspan="9">
            <div class="empty-state">
              <i class="fas fa-receipt"></i>
              <h4>No bills found</h4>
              <p>You have not issued any bills matching the current filters.</p>
              <a href="<?= APP_URL ?>/employee/billing/create.php" class="btn btn-primary mt-3"><i class="fas fa-plus"></i> Create New Bill</a>
            </div>
          </td>
        </tr>
        <?php else: foreach ($bills as $b): ?>
        <tr>
          <td>
            <a href="<?= APP_URL ?>/employee/billing/invoice.php?id=<?= $b['id'] ?>" style="font-weight:700;color:#2563eb;font-size:0.88rem">
              <?= e($b['invoice_no']) ?>
            </a>
          </td>
          <td style="font-size:0.83rem;color:#475569"><?= formatDate($b['sale_date']) ?></td>
          <td>
            <div style="font-weight:600;font-size:0.85rem;color:#1e293b"><?= e($b['customer_name'] ?: 'Walk-In Customer') ?></div>
            <?php if (!empty($b['customer_mobile'])): ?>
              <div style="font-size:0.75rem;color:#94a3b8"><i class="fas fa-phone-alt" style="font-size:0.65rem"></i> <?= e($b['customer_mobile']) ?></div>
            <?php endif; ?>
          </td>
          <td class="text-center"><span class="badge badge-light"><?= (int)$b['item_count'] ?> item(s)</span></td>
          <td class="text-right text-bold" style="color:#0f172a;font-size:0.92rem"><?= formatCurrency($b['grand_total']) ?></td>
          <td class="text-center"><span class="badge badge-secondary" style="font-size:0.75rem"><?= strtoupper(e($b['payment_method'])) ?></span></td>
          <td class="text-center"><?= paymentBadge($b['payment_status'], $b['status']) ?></td>
          <td class="text-center"><?= billStatusBadge($b['status']) ?></td>
          <td class="text-right no-print">
            <a href="<?= APP_URL ?>/employee/billing/invoice.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-light" title="View & Print Invoice">
              <i class="fas fa-eye text-primary"></i> View
            </a>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pagination['pages'] > 1): ?>
  <div class="pagination">
    <?php for ($i = 1; $i <= $pagination['pages']; $i++): ?>
      <a href="?page=<?= $i ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $status ? '&status=' . $status : '' ?><?= $payMode ? '&payment=' . $payMode : '' ?><?= $from ? '&from=' . $from : '' ?><?= $to ? '&to=' . $to : '' ?>" 
         class="page-link <?= $i == $page ? 'active' : '' ?>">
        <?= $i ?>
      </a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
