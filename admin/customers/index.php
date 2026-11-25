<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireAdmin();

// Retail POS: redirect to Bills & Invoices
header('Location: ' . APP_URL . '/admin/billing/index.php');
exit;

$total = db()->fetchOne("SELECT COUNT(*) AS c FROM customers c WHERE $w", $params)['c'];
$pagination = paginate($total, $page);

$customers = db()->fetchAll(
    "SELECT c.*,
            COUNT(s.id) AS total_bills,
            COALESCE(SUM(s.grand_total),0) AS total_spent,
            MAX(s.sale_date) AS last_purchase
     FROM customers c
     LEFT JOIN sales s ON s.customer_name=c.name AND s.status='active'
     WHERE $w GROUP BY c.id ORDER BY c.name ASC
     LIMIT " . DEFAULT_PAGE_SIZE . " OFFSET {$pagination['offset']}",
    $params
);

$pageTitle='Customers'; $activePage='customers';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title"><i class="fas fa-users text-primary" style="margin-right:10px"></i>Customers</h1>
    <p class="page-subtitle"><?= number_format($total) ?> customer(s)</p>
  </div>
  <div class="page-header-right">
    <a href="<?= APP_URL ?>/admin/customers/add.php" class="btn btn-primary"><i class="fas fa-plus"></i> Add Customer</a>
  </div>
</div>
<div class="card">
  <form method="GET" class="table-filters">
    <input type="text" name="search" class="form-control" placeholder="Name, mobile, email..." value="<?= e($search) ?>">
    <select name="type" class="form-select">
      <option value="">All Types</option>
      <option value="retail" <?= $type==='retail'?'selected':'' ?>>Retail</option>
      <option value="wholesale" <?= $type==='wholesale'?'selected':'' ?>>Wholesale</option>
      <option value="walk-in" <?= $type==='walk-in'?'selected':'' ?>>Walk-in</option>
    </select>
    <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i></button>
    <a href="<?= APP_URL ?>/admin/customers/index.php" class="btn btn-light btn-sm"><i class="fas fa-times"></i></a>
    <button type="button" class="btn btn-light btn-sm" onclick="exportTableToCSV('custTable','customers')" style="margin-left:auto"><i class="fas fa-file-csv"></i> Export</button>
  </form>
  <div class="table-wrapper">
    <table id="custTable">
      <thead>
        <tr><th>Name</th><th>Mobile</th><th>Type</th><th>Address</th><th class="text-center">Bills</th><th class="text-right">Total Spent</th><th class="text-right">Outstanding</th><th>Last Purchase</th><th class="text-right no-print">Actions</th></tr>
      </thead>
      <tbody>
        <?php if (empty($customers)): ?>
        <tr><td colspan="9"><div class="empty-state"><i class="fas fa-users"></i><h4>No customers found</h4><p>Add your first customer.</p><a href="<?= APP_URL ?>/admin/customers/add.php" class="btn btn-primary mt-3"><i class="fas fa-plus"></i> Add Customer</a></div></td></tr>
        <?php else: foreach ($customers as $c): ?>
        <tr>
          <td>
            <div style="font-weight:600"><?= e($c['name']) ?></div>
            <?php if ($c['email']): ?><div style="font-size:0.75rem;color:#64748b"><?= e($c['email']) ?></div><?php endif; ?>
          </td>
          <td><?= e($c['mobile'] ?? '—') ?></td>
          <td><span class="badge badge-secondary"><?= ucfirst(e($c['customer_type'])) ?></span></td>
          <td style="font-size:0.82rem;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($c['address'] ?? '—') ?></td>
          <td class="text-center"><?= $c['total_bills'] ?></td>
          <td class="text-right"><?= formatCurrency($c['total_spent']) ?></td>
          <td class="text-right <?= $c['outstanding_balance'] > 0 ? 'text-danger text-bold' : '' ?>">
            <?= $c['outstanding_balance'] > 0 ? formatCurrency($c['outstanding_balance']) : '—' ?>
          </td>
          <td style="font-size:0.82rem"><?= $c['last_purchase'] ? formatDate($c['last_purchase']) : '—' ?></td>
          <td class="text-right no-print">
            <div class="table-actions">
              <a href="<?= APP_URL ?>/admin/customers/view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-light" title="View"><i class="fas fa-eye"></i></a>
              <a href="<?= APP_URL ?>/admin/customers/edit.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fas fa-edit"></i></a>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pagination['total_pages'] > 1): ?>
  <div class="card-footer"><?php $baseUrl = APP_URL.'/admin/customers/index.php?search='.urlencode($search).'&type='.$type; echo renderPagination($pagination, $baseUrl); ?></div>
  <?php endif; ?>
</div>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
