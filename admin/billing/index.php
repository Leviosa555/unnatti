<?php
/**
 * Admin: Bill List & Customer Purchases
 */
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireAdmin();

$search  = sanitize($_GET['search'] ?? '');
$status  = sanitize($_GET['status'] ?? '');
$payMode = sanitize($_GET['payment'] ?? '');
$empId   = (int)($_GET['employee'] ?? 0);
$from    = sanitize($_GET['from'] ?? '');
$to      = sanitize($_GET['to'] ?? '');
$page    = max(1, (int)($_GET['page'] ?? 1));

$where = ['1=1']; $params = [];
if ($search) {
    $where[] = '(s.invoice_no LIKE ? OR s.customer_name LIKE ? OR s.customer_mobile LIKE ? OR s.customer_address LIKE ?)';
    $s = "%$search%";
    $params = array_merge($params, [$s, $s, $s, $s]);
}
if ($status)  { $where[] = 's.status=?'; $params[] = $status; }
if ($payMode) { $where[] = 's.payment_method=?'; $params[] = $payMode; }
if ($empId)   { $where[] = 's.created_by=?'; $params[] = $empId; }
if ($from)    { $where[] = 's.sale_date>=?'; $params[] = $from; }
if ($to)      { $where[] = 's.sale_date<=?'; $params[] = $to; }
$whereStr = implode(' AND ', $where);

$total = db()->fetchOne("SELECT COUNT(*) AS c FROM sales s WHERE $whereStr", $params)['c'];
$pagination = paginate($total, $page);

$bills = db()->fetchAll(
    "SELECT s.id, s.invoice_no, s.sale_date, s.customer_id, s.customer_name,
            COALESCE(NULLIF(s.customer_mobile, ''), c.mobile, '') AS customer_mobile,
            COALESCE(NULLIF(s.customer_address, ''), c.address, '') AS customer_address,
            COALESCE(c.id, s.customer_id, 0) AS resolved_customer_id,
            s.grand_total, s.amount_received, s.balance_due, s.payment_status, s.payment_method, s.status,
            u.full_name AS employee,
            (SELECT COUNT(*) FROM sale_items WHERE sale_id=s.id) AS item_count
     FROM sales s
     LEFT JOIN customers c ON s.customer_id = c.id
     JOIN users u ON s.created_by = u.id
     WHERE $whereStr ORDER BY s.id DESC LIMIT " . DEFAULT_PAGE_SIZE . " OFFSET {$pagination['offset']}",
    $params
);

$employees = db()->fetchAll("SELECT id, full_name FROM users ORDER BY full_name");
$pageTitle = 'Bills & Invoices'; $activePage = 'billing';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<script>window.APP_URL='<?= APP_URL ?>';</script>

<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title"><i class="fas fa-receipt text-primary" style="margin-right:10px"></i>Bills &amp; Invoices</h1>
    <p class="page-subtitle"><?= number_format($total) ?> bill(s)</p>
  </div>
  <div class="page-header-right">
    <a href="<?= APP_URL ?>/admin/billing/create.php" class="btn btn-primary"><i class="fas fa-plus"></i> New Bill</a>
  </div>
</div>

<div class="card">
  <form method="GET" id="billingFilterForm" class="table-filters" style="display:flex; align-items:center; flex-wrap:wrap; gap:10px; padding:12px 18px; background:#fafafa; border-bottom:1px solid #e2e8f0;">
    <!-- 1. Search Field (Small in width) -->
    <div style="position:relative; width:210px; min-width:170px;">
      <i class="fas fa-search" style="position:absolute; left:11px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:0.8rem; pointer-events:none;"></i>
      <input type="text" name="search" class="form-control" placeholder="Invoice no, customer, phone..." value="<?= e($search) ?>" style="padding-left:32px; height:36px; font-size:0.83rem; width:100%;">
    </div>

    <!-- 2. Date Range: From when to where -->
    <div style="display:flex; align-items:center; gap:6px; background:#fff; border:1.5px solid var(--input-border, #cbd5e1); border-radius:6px; padding:2px 8px; height:36px;">
      <span style="font-size:0.75rem; font-weight:700; color:#64748b; text-transform:uppercase;">From</span>
      <input type="date" name="from" value="<?= e($from) ?>" style="border:none; outline:none; font-size:0.82rem; color:#1e293b; background:transparent; padding:0 2px;">
      <span style="font-size:0.75rem; font-weight:700; color:#64748b; text-transform:uppercase; margin-left:4px;">To</span>
      <input type="date" name="to" value="<?= e($to) ?>" style="border:none; outline:none; font-size:0.82rem; color:#1e293b; background:transparent; padding:0 2px;">
    </div>

    <!-- 3. Status Dropdown -->
    <select name="status" class="form-select" style="width:130px; height:36px; font-size:0.83rem;">
      <option value="">All Status</option>
      <option value="active" <?= $status==='active'?'selected':'' ?>>Sold</option>
      <option value="returned" <?= $status==='returned'?'selected':'' ?>>Returned</option>
      <option value="cancelled" <?= $status==='cancelled'?'selected':'' ?>>Cancelled</option>
    </select>

    <!-- 4. Payment Dropdown -->
    <select name="payment" class="form-select" style="width:130px; height:36px; font-size:0.83rem;">
      <option value="">All Payment</option>
      <option value="cash" <?= $payMode==='cash'?'selected':'' ?>>Cash</option>
      <option value="upi" <?= $payMode==='upi'?'selected':'' ?>>UPI</option>
      <option value="card" <?= $payMode==='card'?'selected':'' ?>>Card</option>
      <option value="credit" <?= $payMode==='credit'?'selected':'' ?>>Credit</option>
    </select>

    <!-- 5. Employee Dropdown -->
    <select name="employee" class="form-select" style="width:145px; height:36px; font-size:0.83rem;">
      <option value="">All Employees</option>
      <?php foreach ($employees as $e): ?>
      <option value="<?= $e['id'] ?>" <?= $empId==$e['id']?'selected':'' ?>><?= e($e['full_name']) ?></option>
      <?php endforeach; ?>
    </select>

    <!-- Clear Filter Button -->
    <a href="<?= APP_URL ?>/admin/billing/index.php" id="clearFilterBtn" class="btn btn-light btn-sm" title="Clear Filters" style="<?= ($search || $status || $payMode || $empId || $from || $to) ? 'display:inline-flex;' : 'display:none;' ?> height:36px; padding:0 12px; align-items:center; gap:5px; border:1px solid #cbd5e1; color:#dc2626;" onclick="resetLiveFilters(event)">
      <i class="fas fa-times"></i> Clear
    </a>

    <!-- CSV Export -->
    <button type="button" class="btn btn-light btn-sm" onclick="exportTableToCSV('billsTable','bills_and_purchases')" style="margin-left:auto; height:36px; padding:0 14px; display:inline-flex; align-items:center; gap:6px; font-weight:600; border:1px solid #cbd5e1;">
      <i class="fas fa-file-csv text-success"></i> Export CSV
    </button>
  </form>

  <div class="table-wrapper">
    <table id="billsTable">
      <thead>
        <tr>
          <th>Invoice No</th>
          <th>Date</th>
          <th>Customer</th>
          <th>Phone</th>
          <th>Address</th>
          <th>Employee</th>
          <th class="text-center">Items</th>
          <th class="text-right">Amount</th>
          <th>Payment</th>
          <th>Status</th>
          <th>Payment Status</th>
          <th class="text-right no-print">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($bills)): ?>
        <tr><td colspan="12"><div class="empty-state"><i class="fas fa-receipt"></i><h4>No bills found</h4></div></td></tr>
        <?php else: foreach ($bills as $b): ?>
        <tr>
          <td><a href="<?= APP_URL ?>/admin/billing/invoice.php?id=<?= $b['id'] ?>" style="font-weight:700;font-size:0.85rem"><?= e($b['invoice_no']) ?></a></td>
          <td style="font-size:0.82rem; white-space:nowrap;"><?= formatDate($b['sale_date']) ?></td>
          <td>
            <a href="javascript:void(0)" onclick="openCustomerModal(<?= (int)$b['resolved_customer_id'] ?>, '<?= e(addslashes($b['customer_name'])) ?>', <?= (int)$b['id'] ?>)" style="font-weight:600; color:var(--primary, #4361ee); text-decoration:none; display:inline-flex; align-items:center; gap:5px;" title="View Customer Profile &amp; Purchases">
              <i class="fas fa-user-check text-primary" style="font-size:0.8rem;"></i>
              <?= e($b['customer_name'] ?: 'Customer') ?>
            </a>
          </td>
          <td style="white-space:nowrap;">
            <?php if (!empty($b['customer_mobile'])): ?>
              <a href="tel:<?= e($b['customer_mobile']) ?>" style="font-size:0.82rem; color:#475569; text-decoration:none; display:inline-flex; align-items:center; gap:4px;" title="Call Customer">
                <i class="fas fa-phone-alt text-muted" style="font-size:0.72rem"></i> <?= e($b['customer_mobile']) ?>
              </a>
            <?php else: ?>
              <span class="text-muted" style="font-size:0.82rem">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if (!empty($b['customer_address'])): ?>
              <span style="font-size:0.8rem; color:#475569; display:inline-block; max-width:180px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; vertical-align:middle;" title="<?= e($b['customer_address']) ?>">
                <i class="fas fa-map-marker-alt text-muted" style="font-size:0.72rem; margin-right:3px"></i><?= e($b['customer_address']) ?>
              </span>
            <?php else: ?>
              <span class="text-muted" style="font-size:0.82rem">—</span>
            <?php endif; ?>
          </td>
          <td style="font-size:0.82rem"><?= e($b['employee']) ?></td>
          <td class="text-center"><span class="badge badge-secondary" style="font-size:0.72rem;"><?= $b['item_count'] ?></span></td>
          <td class="text-right" style="white-space:nowrap;">
            <strong><?= formatCurrency($b['grand_total']) ?></strong>
            <?php if ($b['balance_due'] > 0 && $b['status'] !== 'returned'): ?>
            <div style="font-size:0.72rem;color:#dc2626">Due: <?= formatCurrency($b['balance_due']) ?></div>
            <?php endif; ?>
          </td>
          <td><span class="badge badge-light" style="font-size:0.72rem; border:1px solid #e2e8f0;"><?= ucfirst(str_replace('_',' ',$b['payment_method'])) ?></span></td>
          <td><?= billStatusBadge($b['status']) ?></td>
          <td><?= paymentBadge($b['payment_status'], $b['status']) ?></td>
          <td class="text-right no-print" style="white-space:nowrap;">
            <div class="table-actions">
              <button type="button" class="btn btn-sm btn-light text-primary" onclick="openCustomerModal(<?= (int)$b['resolved_customer_id'] ?>, '<?= e(addslashes($b['customer_name'])) ?>', <?= (int)$b['id'] ?>)" title="Customer Profile &amp; Purchases">
                <i class="fas fa-user"></i>
              </button>
              <a href="<?= APP_URL ?>/admin/billing/invoice.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-light" title="View / Print Invoice"><i class="fas fa-eye"></i></a>
              <?php if ($b['status'] === 'active'): ?>
              <a href="<?= APP_URL ?>/admin/billing/return.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-outline-primary" title="Return Items"><i class="fas fa-undo"></i></a>
              <a href="<?= APP_URL ?>/admin/billing/cancel.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-outline-danger" title="Cancel Bill"><i class="fas fa-ban"></i></a>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pagination['total_pages'] > 1): ?>
  <div class="card-footer">
    <?php $baseUrl = APP_URL.'/admin/billing/index.php?search='.urlencode($search).'&status='.$status.'&payment='.$payMode.'&employee='.$empId.'&from='.$from.'&to='.$to; echo renderPagination($pagination, $baseUrl); ?>
  </div>
  <?php endif; ?>
</div>

<!-- ===================================================== -->
<!-- CUSTOMER PROFILE & PURCHASE HISTORY MODAL            -->
<!-- ===================================================== -->
<div class="modal-overlay" id="customerQuickModal" onclick="handleCqmOverlayClick(event)">
  <div class="modal modal-xl" style="max-height:92vh; display:flex; flex-direction:column; overflow:hidden;" onclick="event.stopPropagation()">
    <!-- Modal Header -->
    <div class="modal-header" style="background:#f8fafc; padding:16px 24px; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between;">
      <div style="display:flex; align-items:center; gap:12px;">
        <div style="width:42px; height:42px; border-radius:50%; background:var(--primary, #4361ee); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:700; font-size:1.1rem;" id="cqmAvatar">
          C
        </div>
        <div>
          <div style="display:flex; align-items:center; gap:8px;">
            <h3 style="margin:0; font-size:1.15rem; font-weight:700; color:#1e293b;" id="cqmName">Customer Name</h3>
            <span class="badge badge-primary" id="cqmTypeBadge" style="font-size:0.7rem; text-transform:uppercase;">Retail</span>
          </div>
          <div style="font-size:0.8rem; color:#64748b; margin-top:2px; display:flex; align-items:center; gap:14px; flex-wrap:wrap;" id="cqmContactBar">
            <span id="cqmPhoneSpan"><i class="fas fa-phone-alt" style="margin-right:4px;"></i> <span id="cqmPhoneText">—</span></span>
            <span id="cqmAddressSpan"><i class="fas fa-map-marker-alt" style="margin-right:4px;"></i> <span id="cqmAddressText">—</span></span>
          </div>
        </div>
      </div>
      <button type="button" class="modal-close" onclick="closeCustomerModal()">&times;</button>
    </div>

    <!-- Modal KPI Stats Bar -->
    <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; padding:14px 24px; background:#f1f5f9; border-bottom:1px solid #e2e8f0;">
      <div style="background:#fff; padding:10px 14px; border-radius:8px; border:1px solid #e2e8f0; display:flex; align-items:center; gap:10px;">
        <div style="width:34px; height:34px; border-radius:6px; background:#eff6ff; color:#3b82f6; display:flex; align-items:center; justify-content:center; font-size:0.95rem;"><i class="fas fa-file-invoice"></i></div>
        <div>
          <div style="font-size:0.72rem; color:#64748b; font-weight:600; text-transform:uppercase;">Total Invoices</div>
          <div style="font-size:1.1rem; font-weight:800; color:#1e293b;" id="cqmStatBills">0</div>
        </div>
      </div>
      <div style="background:#fff; padding:10px 14px; border-radius:8px; border:1px solid #e2e8f0; display:flex; align-items:center; gap:10px;">
        <div style="width:34px; height:34px; border-radius:6px; background:#f0fdf4; color:#16a34a; display:flex; align-items:center; justify-content:center; font-size:0.95rem;"><i class="fas fa-rupee-sign"></i></div>
        <div>
          <div style="font-size:0.72rem; color:#64748b; font-weight:600; text-transform:uppercase;">Total Purchases</div>
          <div style="font-size:1.1rem; font-weight:800; color:#16a34a;" id="cqmStatSpent">₹0.00</div>
        </div>
      </div>
      <div style="background:#fff; padding:10px 14px; border-radius:8px; border:1px solid #e2e8f0; display:flex; align-items:center; gap:10px;">
        <div style="width:34px; height:34px; border-radius:6px; background:#fef2f2; color:#dc2626; display:flex; align-items:center; justify-content:center; font-size:0.95rem;"><i class="fas fa-exclamation-circle"></i></div>
        <div>
          <div style="font-size:0.72rem; color:#64748b; font-weight:600; text-transform:uppercase;">Outstanding Due</div>
          <div style="font-size:1.1rem; font-weight:800; color:#dc2626;" id="cqmStatDue">₹0.00</div>
        </div>
      </div>
    </div>

    <!-- Modal Tabs Navigation -->
    <div style="display:flex; border-bottom:1px solid #e2e8f0; background:#fff; padding:0 24px; gap:8px;">
      <button type="button" class="cqm-tab-btn active" id="cqmTabBtnItems" onclick="switchCqmTab('items')" style="padding:12px 16px; border:none; background:none; font-size:0.87rem; font-weight:600; color:var(--primary, #4361ee); border-bottom:2px solid var(--primary, #4361ee); cursor:pointer; display:flex; align-items:center; gap:6px;">
        <i class="fas fa-tshirt"></i> Purchased Products &amp; Materials
        <span class="badge badge-light" id="cqmItemBadge" style="font-size:0.72rem; background:#eff6ff; color:var(--primary, #4361ee); border:1px solid #dbeafe;">0</span>
      </button>
      <button type="button" class="cqm-tab-btn" id="cqmTabBtnBills" onclick="switchCqmTab('bills')" style="padding:12px 16px; border:none; background:none; font-size:0.87rem; font-weight:600; color:#64748b; border-bottom:2px solid transparent; cursor:pointer; display:flex; align-items:center; gap:6px;">
        <i class="fas fa-receipt"></i> Invoices History
        <span class="badge badge-light" id="cqmBillBadge" style="font-size:0.72rem; background:#f1f5f9; color:#64748b;">0</span>
      </button>
      <button type="button" class="cqm-tab-btn" id="cqmTabBtnProfile" onclick="switchCqmTab('profile')" style="padding:12px 16px; border:none; background:none; font-size:0.87rem; font-weight:600; color:#64748b; border-bottom:2px solid transparent; cursor:pointer; display:flex; align-items:center; gap:6px;">
        <i class="fas fa-address-card"></i> Customer Details
      </button>
    </div>

    <!-- Modal Body Content with Tabs -->
    <div class="modal-body" style="padding:20px 24px; overflow-y:auto; flex:1; min-height:280px; position:relative;">
      <!-- Loading State -->
      <div id="cqmLoading" style="position:absolute; inset:0; background:rgba(255,255,255,0.85); display:none; align-items:center; justify-content:center; z-index:10;">
        <div style="text-align:center;">
          <i class="fas fa-circle-notch fa-spin text-primary" style="font-size:2rem; margin-bottom:10px;"></i>
          <p style="font-size:0.85rem; color:#64748b; margin:0;">Loading customer profile &amp; purchases...</p>
        </div>
      </div>

      <!-- Tab 1: Purchased Items -->
      <div id="cqmTabContentItems" class="cqm-tab-pane">
        <div class="table-wrapper" style="border:1px solid #e2e8f0; border-radius:6px; max-height:360px; overflow-y:auto;">
          <table>
            <thead>
              <tr style="background:#f8fafc; position:sticky; top:0; z-index:2;">
                <th>Date</th>
                <th>Invoice</th>
                <th>SKU</th>
                <th>Product / Material</th>
                <th class="text-center">Size / Unit</th>
                <th class="text-center">Qty</th>
                <th class="text-right">Unit Price</th>
                <th class="text-right">Total</th>
              </tr>
            </thead>
            <tbody id="cqmItemsBody">
              <!-- Dynamically populated -->
            </tbody>
          </table>
        </div>
      </div>

      <!-- Tab 2: Invoices History -->
      <div id="cqmTabContentBills" class="cqm-tab-pane" style="display:none;">
        <div class="table-wrapper" style="border:1px solid #e2e8f0; border-radius:6px; max-height:360px; overflow-y:auto;">
          <table>
            <thead>
              <tr style="background:#f8fafc; position:sticky; top:0; z-index:2;">
                <th>Invoice No</th>
                <th>Date</th>
                <th class="text-center">Items</th>
                <th class="text-right">Amount</th>
                <th>Payment</th>
                <th>Status</th>
                <th>Payment Status</th>
                <th class="text-right">Action</th>
              </tr>
            </thead>
            <tbody id="cqmBillsBody">
              <!-- Dynamically populated -->
            </tbody>
          </table>
        </div>
      </div>

      <!-- Tab 3: Customer Details & Notes -->
      <div id="cqmTabContentProfile" class="cqm-tab-pane" style="display:none;">
        <div style="display:grid; grid-template-columns:repeat(2, 1fr); gap:16px;">
          <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px;">
            <h5 style="margin:0 0 12px; font-size:0.88rem; font-weight:700; color:#334155;"><i class="fas fa-info-circle text-primary" style="margin-right:6px;"></i> Basic Details</h5>
            <table style="width:100%; font-size:0.83rem;">
              <tr><td style="color:#64748b; padding:6px 0; width:120px; font-weight:600;">Customer Name</td><td style="font-weight:600;" id="cqmDName">—</td></tr>
              <tr><td style="color:#64748b; padding:6px 0; font-weight:600;">Mobile Number</td><td id="cqmDMobile">—</td></tr>
              <tr><td style="color:#64748b; padding:6px 0; font-weight:600;">Email Address</td><td id="cqmDEmail">—</td></tr>
              <tr><td style="color:#64748b; padding:6px 0; font-weight:600;">Customer Type</td><td id="cqmDType">—</td></tr>
            </table>
          </div>
          <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; padding:16px;">
            <h5 style="margin:0 0 12px; font-size:0.88rem; font-weight:700; color:#334155;"><i class="fas fa-map-pin text-primary" style="margin-right:6px;"></i> Address &amp; Remarks</h5>
            <table style="width:100%; font-size:0.83rem;">
              <tr><td style="color:#64748b; padding:6px 0; width:120px; font-weight:600;">Delivery / Res.</td><td id="cqmDAddress">—</td></tr>
              <tr><td style="color:#64748b; padding:6px 0; font-weight:600;">Customer Notes</td><td id="cqmDNotes">—</td></tr>
              <tr><td style="color:#64748b; padding:6px 0; font-weight:600;">Customer Since</td><td id="cqmDSince">—</td></tr>
            </table>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal Footer -->
    <div class="modal-footer" style="padding:14px 24px; background:#f8fafc; border-top:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center;">
      <div id="cqmFooterInfo" style="font-size:0.8rem; color:#64748b;">
        Customer Purchase History
      </div>
      <div style="display:flex; gap:8px;">
        <a href="#" id="cqmNewBillBtn" class="btn btn-primary btn-sm" style="display:inline-flex; align-items:center; gap:6px;">
          <i class="fas fa-plus"></i> New Bill for Customer
        </a>
        <button type="button" class="btn btn-light btn-sm" onclick="closeCustomerModal()">Close</button>
      </div>
    </div>
  </div>
</div>

<script>
(function() {
  const form = document.getElementById('billingFilterForm');
  if (!form) return;

  const tableWrapper = document.querySelector('.table-wrapper');
  const cardFooter = document.querySelector('.card-footer');
  const pageSubtitle = document.querySelector('.page-subtitle');
  const clearBtn = document.getElementById('clearFilterBtn');
  let searchTimer = null;

  function hasActiveFilters() {
    const fd = new FormData(form);
    for (const [k, v] of fd.entries()) {
      if (k !== 'page' && v.trim() !== '') return true;
    }
    return false;
  }

  function updateClearButton() {
    if (!clearBtn) return;
    clearBtn.style.display = hasActiveFilters() ? 'inline-flex' : 'none';
  }

  function applyLiveFilter() {
    const fd = new FormData(form);
    const cleanParams = new URLSearchParams();
    for (const [k, v] of fd.entries()) {
      if (v.trim() !== '') cleanParams.append(k, v.trim());
    }

    const queryStr = cleanParams.toString();
    const targetUrl = window.location.pathname + (queryStr ? '?' + queryStr : '');

    updateClearButton();

    if (tableWrapper) tableWrapper.style.opacity = '0.5';

    fetch(targetUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function(res) { return res.text(); })
      .then(function(html) {
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');

        // Update table body and headers
        const newTable = doc.querySelector('#billsTable');
        const curTable = document.querySelector('#billsTable');
        if (newTable && curTable) {
          curTable.innerHTML = newTable.innerHTML;
        }

        // Update pagination
        const newFooter = doc.querySelector('.card-footer');
        const curFooter = document.querySelector('.card-footer');
        if (newFooter && curFooter) {
          curFooter.innerHTML = newFooter.innerHTML;
          curFooter.style.display = '';
        } else if (newFooter && !curFooter) {
          const card = document.querySelector('.card');
          if (card) card.appendChild(newFooter);
        } else if (!newFooter && curFooter) {
          curFooter.style.display = 'none';
        }

        // Update count subtitle
        const newSub = doc.querySelector('.page-subtitle');
        if (newSub && pageSubtitle) {
          pageSubtitle.textContent = newSub.textContent;
        }

        window.history.replaceState(null, '', targetUrl);
      })
      .catch(function(err) {
        console.warn('Live filter fallback:', err);
        form.submit();
      })
      .finally(function() {
        if (tableWrapper) tableWrapper.style.opacity = '1';
      });
  }

  // Live input & change listeners
  const searchInput = form.querySelector('input[name="search"]');
  if (searchInput) {
    searchInput.addEventListener('input', function() {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(applyLiveFilter, 300);
    });
    searchInput.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        clearTimeout(searchTimer);
        applyLiveFilter();
      }
    });
  }

  form.querySelectorAll('input[type="date"], select').forEach(function(el) {
    el.addEventListener('change', applyLiveFilter);
  });

  window.resetLiveFilters = function(e) {
    if (e) e.preventDefault();
    form.reset();
    form.querySelectorAll('input, select').forEach(function(el) {
      el.value = '';
    });
    applyLiveFilter();
  };
})();

// =========================================================
// CUSTOMER QUICK MODAL CONTROLLER
// =========================================================
window.switchCqmTab = function(tab) {
  const tabs = ['items', 'bills', 'profile'];
  tabs.forEach(function(t) {
    const pane = document.getElementById('cqmTabContent' + t.charAt(0).toUpperCase() + t.slice(1));
    const btn = document.getElementById('cqmTabBtn' + t.charAt(0).toUpperCase() + t.slice(1));
    if (pane) pane.style.display = (t === tab) ? 'block' : 'none';
    if (btn) {
      if (t === tab) {
        btn.style.color = 'var(--primary, #4361ee)';
        btn.style.borderBottom = '2px solid var(--primary, #4361ee)';
      } else {
        btn.style.color = '#64748b';
        btn.style.borderBottom = '2px solid transparent';
      }
    }
  });
};

window.openCustomerModal = function(id, name, saleId) {
  const modal = document.getElementById('customerQuickModal');
  const loading = document.getElementById('cqmLoading');
  if (!modal) return;

  // Show modal and loading overlay
  modal.classList.add('active');
  if (loading) loading.style.display = 'flex';
  switchCqmTab('items');

  // Set provisional header name
  const avatarEl = document.getElementById('cqmAvatar');
  const nameEl = document.getElementById('cqmName');
  if (nameEl) nameEl.textContent = name || 'Customer Details';
  if (avatarEl) avatarEl.textContent = (name && name.trim()) ? name.trim().charAt(0).toUpperCase() : 'C';

  const params = new URLSearchParams();
  if (id) params.append('id', id);
  if (name) params.append('name', name);
  if (saleId) params.append('sale_id', saleId);

  fetch(window.APP_URL + '/api/get_customer_details.php?' + params.toString())
    .then(function(res) { return res.json(); })
    .then(function(res) {
      if (!res.success) {
        alert(res.message || 'Could not load customer details.');
        return;
      }
      const data = res.data;
      const c = data.customer || {};
      const s = data.stats || {};
      const items = data.items || [];
      const bills = data.bills || [];

      // Update Header
      if (nameEl) nameEl.textContent = c.name || 'Customer Details';
      if (avatarEl) avatarEl.textContent = (c.name && c.name.trim()) ? c.name.trim().charAt(0).toUpperCase() : 'C';

      const typeBadge = document.getElementById('cqmTypeBadge');
      if (typeBadge) {
        typeBadge.textContent = (c.customer_type || 'retail').toUpperCase();
        typeBadge.className = 'badge ' + (c.customer_type === 'walk-in' ? 'badge-secondary' : 'badge-primary');
      }

      const phoneText = document.getElementById('cqmPhoneText');
      if (phoneText) {
        if (c.mobile) {
          phoneText.innerHTML = '<a href="tel:' + encodeURIComponent(c.mobile) + '" style="color:#64748b; text-decoration:none;">' + escapeHtml(c.mobile) + '</a>';
        } else {
          phoneText.textContent = '—';
        }
      }

      const addrText = document.getElementById('cqmAddressText');
      if (addrText) addrText.textContent = c.address || '—';

      // Update Stats
      const statBills = document.getElementById('cqmStatBills');
      const statSpent = document.getElementById('cqmStatSpent');
      const statDue = document.getElementById('cqmStatDue');
      if (statBills) statBills.textContent = s.total_bills || '0';
      if (statSpent) statSpent.textContent = '₹' + parseFloat(s.total_spent || 0).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});
      if (statDue) statDue.textContent = '₹' + parseFloat(s.total_due || 0).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});

      // Update Badges
      const itemBadge = document.getElementById('cqmItemBadge');
      const billBadge = document.getElementById('cqmBillBadge');
      if (itemBadge) itemBadge.textContent = items.length;
      if (billBadge) billBadge.textContent = bills.length;

      // Tab 1: Render Items Table
      const itemsBody = document.getElementById('cqmItemsBody');
      if (itemsBody) {
        if (items.length === 0) {
          itemsBody.innerHTML = '<tr><td colspan="8" class="text-center text-muted" style="padding:30px;"><i class="fas fa-box-open" style="font-size:1.5rem; display:block; margin-bottom:6px; color:#cbd5e1;"></i>No purchased products recorded for this customer yet.</td></tr>';
        } else {
          itemsBody.innerHTML = items.map(function(item) {
            const dateStr = item.sale_date ? new Date(item.sale_date).toLocaleDateString('en-GB') : '—';
            const unitPrice = parseFloat(item.unit_price || 0).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});
            const lineTotal = parseFloat(item.line_total || 0).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});
            return '<tr>' +
              '<td style="font-size:0.8rem; color:#64748b;">' + dateStr + '</td>' +
              '<td><a href="' + window.APP_URL + '/admin/billing/invoice.php?id=' + item.sale_id + '" target="_blank" style="font-weight:600; font-size:0.83rem;">' + escapeHtml(item.invoice_no) + '</a></td>' +
              '<td><span class="sku-badge" style="font-size:0.75rem;">' + escapeHtml(item.sku || '—') + '</span></td>' +
              '<td style="font-weight:600; font-size:0.83rem;">' + escapeHtml(item.product_name) + '</td>' +
              '<td class="text-center"><span class="badge badge-info" style="font-size:0.72rem;">' + escapeHtml(item.size || 'Standard') + '</span></td>' +
              '<td class="text-center"><strong>' + item.qty + '</strong></td>' +
              '<td class="text-right" style="font-size:0.83rem;">₹' + unitPrice + '</td>' +
              '<td class="text-right" style="font-weight:700; font-size:0.85rem;">₹' + lineTotal + '</td>' +
            '</tr>';
          }).join('');
        }
      }

      // Tab 2: Render Bills Table
      const billsBody = document.getElementById('cqmBillsBody');
      if (billsBody) {
        if (bills.length === 0) {
          billsBody.innerHTML = '<tr><td colspan="8" class="text-center text-muted" style="padding:30px;"><i class="fas fa-receipt" style="font-size:1.5rem; display:block; margin-bottom:6px; color:#cbd5e1;"></i>No invoices recorded yet.</td></tr>';
        } else {
          billsBody.innerHTML = bills.map(function(b) {
            const dateStr = b.sale_date ? new Date(b.sale_date).toLocaleDateString('en-GB') : '—';
            const grandTotal = parseFloat(b.grand_total || 0).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});
            const balanceDue = parseFloat(b.balance_due || 0);
            const dueHtml = (balanceDue > 0 && b.status !== 'returned') 
              ? '<div style="font-size:0.72rem;color:#dc2626">Due: ₹' + balanceDue.toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2}) + '</div>' 
              : '';
            
            // Badges
            const isReturned = (b.status === 'returned');
            const statusBadge = isReturned 
              ? '<span class="badge badge-warning" style="font-size:0.72rem;">Returned</span>'
              : (b.status === 'cancelled' ? '<span class="badge badge-danger" style="font-size:0.72rem;">Cancelled</span>' : '<span class="badge badge-success" style="font-size:0.72rem;">Sold</span>');
            
            let payBadge = '<span class="badge badge-light" style="font-size:0.72rem;">' + escapeHtml(b.payment_status) + '</span>';
            if (isReturned || b.payment_status === 'refunded') {
              payBadge = '<span class="badge" style="background:#ede9fe; color:#6d28d9; border:1px solid #ddd6fe; font-weight:600; font-size:0.72rem;"><i class="fas fa-undo"></i> Refunded</span>';
            } else if (b.payment_status === 'paid') {
              payBadge = '<span class="badge badge-success" style="font-size:0.72rem;">Paid</span>';
            } else if (b.payment_status === 'partial') {
              payBadge = '<span class="badge badge-warning" style="font-size:0.72rem;">Partial</span>';
            } else if (b.payment_status === 'due') {
              payBadge = '<span class="badge badge-danger" style="font-size:0.72rem;">Due</span>';
            }

            return '<tr>' +
              '<td><a href="' + window.APP_URL + '/admin/billing/invoice.php?id=' + b.id + '" target="_blank" style="font-weight:700; font-size:0.83rem;">' + escapeHtml(b.invoice_no) + '</a></td>' +
              '<td style="font-size:0.8rem; color:#64748b;">' + dateStr + '</td>' +
              '<td class="text-center">' + (b.item_count || 1) + '</td>' +
              '<td class="text-right"><strong>₹' + grandTotal + '</strong>' + dueHtml + '</td>' +
              '<td><span class="badge badge-secondary" style="font-size:0.7rem;">' + escapeHtml(b.payment_method || 'Cash').toUpperCase() + '</span></td>' +
              '<td>' + statusBadge + '</td>' +
              '<td>' + payBadge + '</td>' +
              '<td class="text-right">' +
                '<a href="' + window.APP_URL + '/admin/billing/invoice.php?id=' + b.id + '" target="_blank" class="btn btn-xs btn-light" title="View Invoice"><i class="fas fa-eye"></i> View</a>' +
              '</td>' +
            '</tr>';
          }).join('');
        }
      }

      // Tab 3: Customer Details Info
      const dName = document.getElementById('cqmDName');
      const dMobile = document.getElementById('cqmDMobile');
      const dEmail = document.getElementById('cqmDEmail');
      const dType = document.getElementById('cqmDType');
      const dAddress = document.getElementById('cqmDAddress');
      const dNotes = document.getElementById('cqmDNotes');
      const dSince = document.getElementById('cqmDSince');

      if (dName) dName.textContent = c.name || '—';
      if (dMobile) dMobile.textContent = c.mobile || '—';
      if (dEmail) dEmail.textContent = c.email || '—';
      if (dType) dType.textContent = (c.customer_type ? c.customer_type.toUpperCase() : 'RETAIL');
      if (dAddress) dAddress.textContent = c.address || '—';
      if (dNotes) dNotes.textContent = c.notes || '—';
      if (dSince) dSince.textContent = c.created_at ? new Date(c.created_at).toLocaleDateString('en-GB') : '—';

      // Footer button
      const newBillBtn = document.getElementById('cqmNewBillBtn');
      if (newBillBtn) {
        if (c.id && c.id > 0) {
          newBillBtn.href = window.APP_URL + '/admin/billing/create.php?customer_id=' + c.id;
          newBillBtn.style.display = 'inline-flex';
        } else if (c.name && c.name !== 'Walk-In Customer') {
          newBillBtn.href = window.APP_URL + '/admin/billing/create.php?customer_name=' + encodeURIComponent(c.name);
          newBillBtn.style.display = 'inline-flex';
        } else {
          newBillBtn.href = window.APP_URL + '/admin/billing/create.php';
          newBillBtn.style.display = 'inline-flex';
        }
      }
    })
    .catch(function(err) {
      console.error('Customer quick modal fetch error:', err);
      alert('Failed to load customer profile.');
    })
    .finally(function() {
      if (loading) loading.style.display = 'none';
    });
};

window.closeCustomerModal = function() {
  const modal = document.getElementById('customerQuickModal');
  if (modal) modal.classList.remove('active');
};

window.handleCqmOverlayClick = function(e) {
  if (e.target && e.target.id === 'customerQuickModal') {
    closeCustomerModal();
  }
};

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    closeCustomerModal();
  }
});

function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
