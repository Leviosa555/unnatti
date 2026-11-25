<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
$emp = db()->fetchOne("SELECT * FROM users WHERE id=? AND role='employee'", [$id]);
if (!$emp) { $_SESSION['flash_error']='Not found.'; header('Location:'.APP_URL.'/admin/employees/index.php'); exit; }

$stats = db()->fetchOne(
    "SELECT COUNT(*) AS bills, COALESCE(SUM(grand_total),0) AS revenue, COALESCE(SUM(discount_amount),0) AS discounts
     FROM sales WHERE created_by=? AND status='active'",
    [$id]
);
$monthStats = db()->fetchOne(
    "SELECT COUNT(*) AS bills, COALESCE(SUM(grand_total),0) AS revenue
     FROM sales WHERE created_by=? AND MONTH(sale_date)=MONTH(NOW()) AND YEAR(sale_date)=YEAR(NOW()) AND status='active'",
    [$id]
);
$recentBills = db()->fetchAll(
    "SELECT invoice_no, sale_date, customer_name, grand_total, payment_status FROM sales WHERE created_by=? ORDER BY id DESC LIMIT 10",
    [$id]
);
$recentActivity = db()->fetchAll(
    "SELECT action, module, created_at FROM activity_logs WHERE user_id=? ORDER BY id DESC LIMIT 10",
    [$id]
);

$pageTitle = e($emp['full_name']); $activePage='employees';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title"><i class="fas fa-user-tie text-primary" style="margin-right:10px"></i><?= e($emp['full_name']) ?></h1>
    <p class="page-subtitle">Employee &bull; @<?= e($emp['username']) ?> &bull; <span class="badge badge-<?= $emp['status']==='active'?'success':'danger' ?>"><?= ucfirst($emp['status']) ?></span></p>
  </div>
  <div class="page-header-right">
    <a href="<?= APP_URL ?>/admin/employees/edit.php?id=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-edit"></i> Edit</a>
    <button type="button" class="btn btn-outline-danger btn-sm" onclick="showDeleteModal()"><i class="fas fa-trash"></i> Delete</button>
    <a href="<?= APP_URL ?>/admin/employees/index.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
  </div>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px">
  <div class="stat-card stat-card-primary"><div class="stat-icon"><i class="fas fa-receipt"></i></div><div class="stat-body"><div class="stat-value"><?= $stats['bills'] ?></div><div class="stat-label">Total Bills</div></div></div>
  <div class="stat-card stat-card-success"><div class="stat-icon"><i class="fas fa-rupee-sign"></i></div><div class="stat-body"><div class="stat-value" style="font-size:1rem"><?= formatCurrency($stats['revenue']) ?></div><div class="stat-label">All-time Revenue</div></div></div>
  <div class="stat-card stat-card-warning"><div class="stat-icon"><i class="fas fa-calendar"></i></div><div class="stat-body"><div class="stat-value" style="font-size:1rem"><?= formatCurrency($monthStats['revenue']) ?></div><div class="stat-label">This Month</div></div></div>
  <div class="stat-card stat-card-secondary"><div class="stat-icon"><i class="fas fa-tag"></i></div><div class="stat-body"><div class="stat-value" style="font-size:1rem"><?= formatCurrency($stats['discounts']) ?></div><div class="stat-label">Total Discounts</div></div></div>
</div>

<div class="grid-2">
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fas fa-info-circle"></i> Profile & Permissions</span></div>
    <div class="card-body">
      <table style="width:100%;border-collapse:collapse">
        <?php
        $fields = [
          'Mobile'       => $emp['mobile'] ?? '—',
          'Email'        => $emp['email'] ?? '—',
          'Enter Stock'  => $emp['can_enter_stock'] ? '<span class="badge badge-success">Allowed</span>' : '<span class="badge badge-secondary">Not Allowed</span>',
          'Discounts'    => $emp['can_apply_discount'] ? '<span class="badge badge-success">Up to '.$emp['max_discount_pct'].'%</span>' : '<span class="badge badge-secondary">Not Allowed</span>',
          'Added'        => formatDateTime($emp['created_at']),
          'Last Active'  => $emp['last_login'] ? formatDateTime($emp['last_login']) : '—',
        ];
        foreach ($fields as $label => $val):
        ?>
        <tr style="border-bottom:1px solid #f1f5f9">
          <td style="padding:10px 0;font-size:0.82rem;color:#64748b;width:130px;font-weight:600"><?= $label ?></td>
          <td style="padding:10px 0;font-size:0.85rem"><?= $val ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fas fa-receipt"></i> Recent Bills</span></div>
    <div class="card-body p-0">
      <div class="table-wrapper">
        <table>
          <thead><tr><th>Invoice</th><th>Customer</th><th>Date</th><th class="text-right">Amount</th><th>Status</th></tr></thead>
          <tbody>
            <?php foreach ($recentBills as $b): ?>
            <tr>
              <td><a href="<?= APP_URL ?>/admin/billing/invoice.php?id=<?= urlencode($b['invoice_no']) ?>" style="font-size:0.83rem;font-weight:600"><?= e($b['invoice_no']) ?></a></td>
              <td style="font-size:0.83rem"><?= e($b['customer_name']) ?></td>
              <td style="font-size:0.8rem"><?= formatDate($b['sale_date']) ?></td>
              <td class="text-right"><?= formatCurrency($b['grand_total']) ?></td>
              <td><?= paymentBadge($b['payment_status']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recentBills)): ?><tr><td colspan="5" class="text-center text-muted" style="padding:20px">No bills yet</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Delete Employee Confirmation Modal -->
<div class="modal-overlay" id="deleteEmployeeModal">
  <div class="modal modal-sm">
    <div class="modal-header">
      <span class="modal-title" style="color:#dc2626"><i class="fas fa-user-times" style="margin-right:8px"></i>Delete Employee</span>
      <button type="button" class="modal-close" onclick="closeDeleteModal()">&times;</button>
    </div>
    <div class="modal-body">
      <p style="margin-bottom:12px;font-size:0.95rem">Are you sure you want to delete this employee?</p>
      <div style="background:#fef2f2;border-left:4px solid #ef4444;padding:12px 14px;border-radius:6px;margin-bottom:14px">
        <strong style="color:#b91c1c;font-size:1.05rem"><?= e($emp['full_name']) ?></strong>
      </div>
      <p style="font-size:0.83rem;color:#64748b;line-height:1.45;margin:0">
        <i class="fas fa-exclamation-triangle text-warning" style="margin-right:4px"></i> 
        This action permanently removes the employee from the database. Their historical sales and billing records will remain safely preserved in the store audit.
      </p>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-light" onclick="closeDeleteModal()">Cancel</button>
      <button type="button" class="btn btn-danger" id="confirmDeleteEmpBtn" onclick="confirmDeleteEmployee()">
        <i class="fas fa-trash"></i> Delete Employee
      </button>
    </div>
  </div>
</div>

<script>
function showDeleteModal() {
  const modal = document.getElementById('deleteEmployeeModal');
  if (modal) {
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
  }
}

function closeDeleteModal() {
  const modal = document.getElementById('deleteEmployeeModal');
  if (modal) {
    modal.classList.remove('active');
    document.body.style.overflow = '';
  }
}

function confirmDeleteEmployee() {
  const btn = document.getElementById('confirmDeleteEmpBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner spinner-sm"></span> Deleting...';

  const formData = new FormData();
  formData.append('employee_id', <?= $id ?>);
  formData.append('csrf_token', '<?= csrfToken() ?>');

  fetch('<?= APP_URL ?>/api/delete_employee.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      showToast(data.message || 'Employee deleted successfully.', 'success');
      closeDeleteModal();
      setTimeout(() => {
        window.location.href = '<?= APP_URL ?>/admin/employees/index.php';
      }, 700);
    } else {
      showToast(data.message || 'Failed to delete employee.', 'error');
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-trash"></i> Delete Employee';
    }
  })
  .catch(err => {
    showToast('Network error. Could not delete employee.', 'error');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-trash"></i> Delete Employee';
  });
}
</script>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
