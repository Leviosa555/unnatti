<?php
/**
 * Supplier Bills & Directory Hub (Fashion Kingdom & Boutique Operations)
 * Unified module combining:
 * 1. Chronological Inward Bills History (Purchases, GST, Invoice numbers)
 * 2. Suppliers & Mills Directory (Contact persons, Phones, GSTIN, Total Orders, Total Value)
 */
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireAdmin();

// Handle Save Supplier (Add / Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_supplier') {
    $suppId   = (int)($_POST['supplier_id'] ?? 0);
    $name     = sanitize($_POST['name'] ?? '');
    $contact  = sanitize($_POST['contact_person'] ?? '');
    $mobile   = cleanMobile($_POST['mobile'] ?? '');
    $gstin    = strtoupper(sanitize($_POST['gstin'] ?? ''));
    $address  = sanitize($_POST['address'] ?? '');

    if (!$name) {
        $_SESSION['flash_error'] = 'Supplier name is required.';
    } elseif ($mobile && !isValidMobile($mobile)) {
        $_SESSION['flash_error'] = 'Mobile number must be exactly 10 digits.';
    } else {
        if ($suppId > 0) {
            db()->execute(
                "UPDATE suppliers SET name=?, contact_person=?, mobile=?, gstin=?, address=? WHERE id=?",
                [$name, $contact ?: null, $mobile ?: null, $gstin ?: null, $address ?: null, $suppId]
            );
            $_SESSION['flash_success'] = "Supplier '{$name}' updated successfully.";
        } else {
            db()->execute(
                "INSERT INTO suppliers (name, contact_person, mobile, gstin, address) VALUES (?,?,?,?,?)",
                [$name, $contact ?: null, $mobile ?: null, $gstin ?: null, $address ?: null]
            );
            $_SESSION['flash_success'] = "New supplier '{$name}' registered successfully.";
        }
    }
    header('Location: ' . APP_URL . '/admin/purchases/index.php?tab=suppliers');
    exit;
}

// Active Tab ('bills' or 'suppliers')
$activeTab = sanitize($_GET['tab'] ?? 'bills');
if ($activeTab !== 'suppliers') {
    $activeTab = 'bills';
}

// ── Tab 1: Inward Bills Data ───────────────────────────────────────
$search = sanitize($_GET['search'] ?? '');
$from   = sanitize($_GET['from'] ?? '');
$to     = sanitize($_GET['to'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));

$where = ['1=1'];
$params = [];
if ($search) {
    $where[] = '(p.purchase_no LIKE ? OR p.supplier_name LIKE ? OR p.supplier_invoice_no LIKE ? OR EXISTS (
        SELECT 1 FROM purchase_items pi 
        JOIN product_variants pv ON pi.variant_id = pv.id 
        JOIN products pr ON pv.product_id = pr.id 
        WHERE pi.purchase_id = p.id AND pr.name LIKE ?
    ))';
    $s = "%$search%";
    $params = array_merge($params, [$s, $s, $s, $s]);
}
if ($from) {
    $where[] = 'p.purchase_date >= ?';
    $params[] = $from;
}
if ($to) {
    $where[] = 'p.purchase_date <= ?';
    $params[] = $to;
}
$w = implode(' AND ', $where);

$totalBills = db()->fetchOne("SELECT COUNT(*) AS c FROM purchases p WHERE $w", $params)['c'];
$paginationBills = paginate($totalBills, $page);

$purchases = db()->fetchAll(
    "SELECT p.*, u.full_name AS created_by_name, s.contact_person, s.mobile AS supplier_mobile, s.gstin AS supplier_gstin,
            (SELECT GROUP_CONCAT(DISTINCT pr.name ORDER BY pr.name SEPARATOR ', ')
             FROM purchase_items pi
             JOIN product_variants pv ON pi.variant_id = pv.id
             JOIN products pr ON pv.product_id = pr.id
             WHERE pi.purchase_id = p.id) AS product_names
     FROM purchases p 
     LEFT JOIN suppliers s ON p.supplier_id = s.id
     JOIN users u ON p.created_by = u.id
     WHERE $w 
     ORDER BY p.id DESC 
     LIMIT " . DEFAULT_PAGE_SIZE . " OFFSET {$paginationBills['offset']}",
    $params
);

// ── Tab 2: Suppliers & Mills Directory Data ────────────────────────
$suppSearch = sanitize($_GET['supplier_search'] ?? '');
$suppWhere = ["s.status = 'active'"];
$suppParams = [];
if ($suppSearch) {
    $suppWhere[] = '(s.name LIKE ? OR s.contact_person LIKE ? OR s.mobile LIKE ? OR s.gstin LIKE ?)';
    $str = "%$suppSearch%";
    $suppParams = array_merge($suppParams, [$str, $str, $str, $str]);
}
$sw = implode(' AND ', $suppWhere);

$totalSuppliers = db()->fetchOne("SELECT COUNT(*) AS c FROM suppliers s WHERE $sw", $suppParams)['c'];

$suppliersList = db()->fetchAll(
    "SELECT s.*, 
            COUNT(p.id) AS total_orders,
            COALESCE(SUM(p.total_amount), 0) AS total_purchased
     FROM suppliers s
     LEFT JOIN purchases p ON s.id = p.supplier_id
     WHERE $sw
     GROUP BY s.id
     ORDER BY total_purchased DESC, s.name ASC",
    $suppParams
);

// ── Overall KPIs ───────────────────────────────────────────────────
$kpiBillsTotal = db()->fetchOne("SELECT COUNT(*) AS c FROM purchases")['c'];
$kpiSpendTotal = db()->fetchOne("SELECT COALESCE(SUM(total_amount), 0) AS v FROM purchases")['v'];
$kpiQtyTotal   = db()->fetchOne("SELECT COALESCE(SUM(total_qty), 0) AS v FROM purchases")['v'];
$kpiSuppTotal  = db()->fetchOne("SELECT COUNT(*) AS c FROM suppliers WHERE status='active'")['c'];

$pageTitle  = 'Supplier Bills & Directory';
$activePage = 'purchases';
$breadcrumb = [['label' => 'Supplier'], ['label' => 'Supplier Bills & Directory']];
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>

<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title"><i class="fas fa-file-invoice-dollar text-primary" style="margin-right:10px"></i>Supplier Bills &amp; Directory</h1>
    <p class="page-subtitle">Track wholesale inward invoices, delivery bills &amp; manage mill / supplier contacts</p>
  </div>
  <div class="page-header-right">
    <button type="button" class="btn btn-outline-primary" onclick="openSupplierModal()"><i class="fas fa-plus"></i> New Supplier / Mill</button>
    <a href="<?= APP_URL ?>/admin/purchases/add.php" class="btn btn-primary"><i class="fas fa-plus-circle"></i> Stock Inward Entry</a>
  </div>
</div>

<!-- KPIs Overview -->
<div class="stats-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px">
  <div class="stat-card stat-card-primary">
    <div class="stat-icon"><i class="fas fa-file-invoice"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($kpiBillsTotal) ?></div>
      <div class="stat-label">Inward Bills / Consignments</div>
    </div>
  </div>
  <div class="stat-card stat-card-success">
    <div class="stat-icon"><i class="fas fa-rupee-sign"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= formatCurrency($kpiSpendTotal) ?></div>
      <div class="stat-label">Total Inward Spend</div>
    </div>
  </div>
  <div class="stat-card stat-card-info">
    <div class="stat-icon"><i class="fas fa-boxes"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($kpiQtyTotal) ?></div>
      <div class="stat-label">Total Material Qty Inwarded</div>
    </div>
  </div>
  <div class="stat-card stat-card-warning">
    <div class="stat-icon"><i class="fas fa-truck"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($kpiSuppTotal) ?></div>
      <div class="stat-label">Registered Suppliers &amp; Mills</div>
    </div>
  </div>
</div>

<!-- Unified Main Card with Tabs -->
<div class="card">
  <!-- View Mode Switcher Header -->
  <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 20px;border-bottom:1px solid #e2e8f0;background:#fafbfc;flex-wrap:wrap;gap:10px">
    <div class="d-flex align-center gap-2">
      <button type="button" id="tabBtnBills" class="btn btn-sm <?= $activeTab === 'bills' ? 'btn-primary' : 'btn-light' ?>" onclick="switchHubTab('bills')">
        <i class="fas fa-file-invoice"></i> Inward Bills History (<?= number_format($totalBills) ?>)
      </button>
      <button type="button" id="tabBtnSuppliers" class="btn btn-sm <?= $activeTab === 'suppliers' ? 'btn-primary' : 'btn-light' ?>" onclick="switchHubTab('suppliers')">
        <i class="fas fa-truck"></i> Suppliers &amp; Mills Directory (<?= number_format($totalSuppliers) ?>)
      </button>
    </div>
    <div style="font-size:0.8rem;color:#64748b">
      <span id="tabTipBills" style="<?= $activeTab === 'bills' ? '' : 'display:none' ?>">
        <i class="fas fa-info-circle"></i> Click "View" to open individual line-item fabric receipt &amp; tax breakdown
      </span>
      <span id="tabTipSuppliers" style="<?= $activeTab === 'suppliers' ? '' : 'display:none' ?>">
        <i class="fas fa-info-circle"></i> Click "Invoices" on any mill to view only bills received from them
      </span>
    </div>
  </div>

  <!-- ================================================================= -->
  <!-- TAB 1: INWARD BILLS HISTORY                                       -->
  <!-- ================================================================= -->
  <div id="tabContentBills" style="<?= $activeTab === 'bills' ? '' : 'display:none' ?>">
    <!-- Filters Bar -->
    <form method="GET" class="table-filters" style="border-bottom:1px solid #e2e8f0">
      <input type="hidden" name="tab" value="bills">
      <input type="text" name="search" class="form-control" placeholder="Search purchase no, supplier, product, invoice no..." value="<?= e($search) ?>" style="max-width:320px">
      <input type="date" name="from" class="form-control" value="<?= e($from) ?>" title="From Inward Date">
      <input type="date" name="to" class="form-control" value="<?= e($to) ?>" title="To Inward Date">
      <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
      <?php if ($search || $from || $to): ?>
        <a href="<?= APP_URL ?>/admin/purchases/index.php?tab=bills" class="btn btn-light btn-sm"><i class="fas fa-times"></i> Clear</a>
      <?php endif; ?>
      <div style="margin-left:auto">
        <button type="button" class="btn btn-light btn-sm" onclick="exportTableToCSV('purTable','supplier_inward_bills')">
          <i class="fas fa-download"></i> Export CSV
        </button>
      </div>
    </form>

    <?php if ($search): ?>
    <div style="padding:10px 20px;background:#eff6ff;border-bottom:1px solid #bfdbfe;display:flex;align-items:center;justify-content:space-between">
      <span style="font-size:0.83rem;color:#1e40af">
        <i class="fas fa-filter"></i> Filtering bills for: <strong>"<?= e($search) ?>"</strong>
      </span>
      <a href="<?= APP_URL ?>/admin/purchases/index.php?tab=bills" class="btn btn-xs btn-outline-primary" style="font-size:0.75rem;padding:2px 8px">
        <i class="fas fa-times"></i> View All Bills
      </a>
    </div>
    <?php endif; ?>

    <div class="table-wrapper">
      <table id="purTable">
        <thead>
          <tr>
            <th style="width:14%">Purchase No</th>
            <th style="width:11%">Inward Date</th>
            <th style="width:16%">Supplier / Mill</th>
            <th style="width:16%">Product</th>
            <th style="width:11%">Supplier Inv No</th>
            <th class="text-center" style="width:7%">Items</th>
            <th class="text-center" style="width:9%">Total Qty</th>
            <th class="text-right" style="width:10%">Total Amount</th>
            <th class="text-center no-print" style="width:6%">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($purchases)): ?>
          <tr>
            <td colspan="9" class="text-center text-muted" style="padding:40px">
              <i class="fas fa-file-invoice" style="font-size:2.5rem;opacity:0.2;display:block;margin-bottom:10px"></i>
              No inward bills found matching criteria.
            </td>
          </tr>
          <?php else: foreach ($purchases as $p): ?>
          <tr>
            <td>
              <strong style="font-size:0.88rem;color:#0f172a"><?= e($p['purchase_no']) ?></strong>
              <?php if (!empty($p['notes'])): ?>
                <div style="font-size:0.72rem;color:#64748b;margin-top:2px" title="<?= e($p['notes']) ?>">
                  <i class="fas fa-sticky-note text-warning"></i> <?= e(mb_strimwidth($p['notes'], 0, 32, '...')) ?>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <span style="font-size:0.84rem"><?= formatDate($p['purchase_date']) ?></span>
              <div style="font-size:0.7rem;color:#64748b">By <?= e($p['created_by_name']) ?></div>
            </td>
            <td>
              <strong style="font-size:0.88rem;color:#1e293b"><?= e($p['supplier_name'] ?? '—') ?></strong>
              <?php if (!empty($p['supplier_mobile']) || !empty($p['supplier_gstin'])): ?>
              <div style="font-size:0.72rem;color:#64748b;margin-top:2px">
                <?php if ($p['supplier_mobile']): ?>
                  <span><i class="fas fa-phone-alt" style="font-size:0.65rem"></i> <?= e($p['supplier_mobile']) ?></span>
                <?php endif; ?>
                <?php if ($p['supplier_mobile'] && $p['supplier_gstin']): ?> &bull; <?php endif; ?>
                <?php if ($p['supplier_gstin']): ?>
                  <span>GST: <?= e($p['supplier_gstin']) ?></span>
                <?php endif; ?>
              </div>
              <?php endif; ?>
            </td>
            <td>
              <span style="font-weight:600;font-size:0.86rem;color:#1e293b"><?= e($p['product_names'] ?: '—') ?></span>
            </td>
            <td>
              <?php if ($p['supplier_invoice_no']): ?>
                <span class="badge badge-secondary" style="font-weight:600;font-size:0.76rem;letter-spacing:0.02em">
                  <?= e($p['supplier_invoice_no']) ?>
                </span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <span class="badge badge-light" style="border:1px solid #cbd5e1;font-weight:600"><?= $p['total_items'] ?></span>
            </td>
            <td class="text-center">
              <strong style="color:#0f172a"><?= number_format($p['total_qty']) ?></strong>
              <small class="text-muted" style="font-size:0.7rem;display:block">units / mtr</small>
            </td>
            <td class="text-right">
              <strong style="font-size:0.92rem;color:#047857"><?= formatCurrency($p['total_amount']) ?></strong>
            </td>
            <td class="text-center no-print">
              <a href="<?= APP_URL ?>/admin/purchases/view.php?id=<?= $p['id'] ?>" class="btn btn-xs btn-light" title="View Inward Bill Receipt" style="font-weight:600;padding:4px 9px">
                <i class="fas fa-eye"></i> View
              </a>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <?php if ($paginationBills['total_pages'] > 1): ?>
    <div class="card-footer">
      <?php 
      $baseUrl = APP_URL . '/admin/purchases/index.php?tab=bills&search=' . urlencode($search) . '&from=' . $from . '&to=' . $to;
      echo renderPagination($paginationBills, $baseUrl); 
      ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- ================================================================= -->
  <!-- TAB 2: SUPPLIERS & MILLS DIRECTORY                                -->
  <!-- ================================================================= -->
  <div id="tabContentSuppliers" style="<?= $activeTab === 'suppliers' ? '' : 'display:none' ?>">
    <!-- Filters Bar -->
    <form method="GET" class="table-filters" style="border-bottom:1px solid #e2e8f0">
      <input type="hidden" name="tab" value="suppliers">
      <input type="text" name="supplier_search" class="form-control" placeholder="Search supplier / mill name, contact person, mobile, GSTIN..." value="<?= e($suppSearch) ?>" style="max-width:380px">
      <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
      <?php if ($suppSearch): ?>
        <a href="<?= APP_URL ?>/admin/purchases/index.php?tab=suppliers" class="btn btn-light btn-sm"><i class="fas fa-times"></i> Clear</a>
      <?php endif; ?>
      <div style="margin-left:auto;display:flex;gap:8px">
        <button type="button" class="btn btn-primary btn-sm" onclick="openSupplierModal()">
          <i class="fas fa-plus"></i> Add Supplier / Mill
        </button>
        <button type="button" class="btn btn-light btn-sm" onclick="exportTableToCSV('suppTable','suppliers_directory')">
          <i class="fas fa-download"></i> Export CSV
        </button>
      </div>
    </form>

    <div class="table-wrapper">
      <table id="suppTable">
        <thead>
          <tr>
            <th style="width:26%">Supplier / Mill Name</th>
            <th style="width:16%">Contact Person</th>
            <th style="width:14%">Phone / Mobile</th>
            <th style="width:14%">GSTIN</th>
            <th class="text-center" style="width:12%">Total Inward Orders</th>
            <th class="text-right" style="width:18%">Total Purchased Value</th>
            <th class="text-center no-print" style="width:10%">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($suppliersList)): ?>
          <tr>
            <td colspan="7" class="text-center text-muted" style="padding:40px">
              <i class="fas fa-truck" style="font-size:2.5rem;opacity:0.2;display:block;margin-bottom:10px"></i>
              No suppliers found. Click "+ Add Supplier / Mill" above to add one.
            </td>
          </tr>
          <?php else: foreach ($suppliersList as $s): ?>
          <tr>
            <td>
              <div style="font-weight:700;font-size:0.91rem;color:#0f172a"><?= e($s['name']) ?></div>
              <?php if (!empty($s['address'])): ?>
                <div style="font-size:0.72rem;color:#64748b;margin-top:2px">
                  <i class="fas fa-map-marker-alt text-muted"></i> <?= e($s['address']) ?>
                </div>
              <?php endif; ?>
            </td>
            <td>
              <span style="font-size:0.85rem;color:#334155"><?= e($s['contact_person'] ?: '—') ?></span>
            </td>
            <td>
              <?php if (!empty($s['mobile'])): ?>
                <a href="tel:<?= e($s['mobile']) ?>" style="font-weight:600;font-size:0.85rem;color:#2563eb">
                  <i class="fas fa-phone-alt" style="font-size:0.75rem"></i> <?= e($s['mobile']) ?>
                </a>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($s['gstin'])): ?>
                <span class="sku-badge" style="font-size:0.73rem;padding:2px 6px"><?= e($s['gstin']) ?></span>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <span class="badge badge-secondary" style="font-weight:700;font-size:0.78rem">
                <?= number_format($s['total_orders']) ?> order(s)
              </span>
            </td>
            <td class="text-right">
              <strong style="font-size:0.92rem;color:#0f172a"><?= formatCurrency($s['total_purchased']) ?></strong>
            </td>
            <td class="text-center no-print">
              <div style="display:flex;gap:4px;justify-content:center;align-items:center;flex-wrap:nowrap">
                <a href="<?= APP_URL ?>/admin/purchases/index.php?tab=bills&search=<?= urlencode($s['name']) ?>" class="btn btn-xs btn-light" title="View Inward Invoices from this Supplier" style="font-weight:600;padding:4px 8px;white-space:nowrap">
                  <i class="fas fa-file-invoice"></i> Invoices
                </a>
                <a href="<?= APP_URL ?>/admin/purchases/add.php?supplier=<?= urlencode($s['name']) ?>" class="btn btn-xs btn-outline-success" title="Inward Stock from this Supplier" style="font-weight:600;padding:4px 8px;white-space:nowrap">
                  <i class="fas fa-plus"></i> Inward
                </a>
                <button type="button" class="btn btn-xs btn-outline-primary" title="Edit Supplier Contact Details" onclick='openEditSupplierModal(<?= json_encode($s) ?>)' style="padding:4px 7px">
                  <i class="fas fa-pencil-alt"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ================================================================= -->
<!-- MODAL: ADD / EDIT SUPPLIER                                        -->
<!-- ================================================================= -->
<div id="supplierModal" class="modal-overlay" onclick="if(event.target===this)closeSupplierModal()">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <div style="display:flex;align-items:center;gap:10px">
        <div style="width:34px;height:34px;border-radius:8px;background:#e0e7ff;color:#4338ca;display:flex;align-items:center;justify-content:center">
          <i class="fas fa-truck"></i>
        </div>
        <strong id="suppModalTitle" style="font-size:1.05rem;color:#0f172a">Add Supplier / Mill</strong>
      </div>
      <button type="button" class="modal-close" onclick="closeSupplierModal()">&times;</button>
    </div>

    <form method="POST" action="<?= APP_URL ?>/admin/purchases/index.php">
      <div class="modal-body">
        <input type="hidden" name="action" value="save_supplier">
        <input type="hidden" name="supplier_id" id="modal_supp_id" value="0">

        <div class="form-group" style="margin-bottom:14px">
          <label class="form-label" style="font-weight:600;font-size:0.84rem">Supplier / Mill Name <span class="required">*</span></label>
          <input type="text" name="name" id="modal_supp_name" class="form-control" placeholder="e.g. Raymond Mills, Arvind Textiles..." required>
        </div>

        <div class="form-row cols-2" style="margin-bottom:14px">
          <div class="form-group">
            <label class="form-label" style="font-weight:600;font-size:0.84rem">Contact Person</label>
            <input type="text" name="contact_person" id="modal_supp_contact" class="form-control" placeholder="e.g. Rajesh Kumar">
          </div>
          <div class="form-group">
            <label class="form-label" style="font-weight:600;font-size:0.84rem">Phone / Mobile</label>
            <input type="tel" name="mobile" id="modal_supp_mobile" class="form-control" placeholder="10-digit mobile number" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'').slice(0,10)">
          </div>
        </div>

        <div class="form-group" style="margin-bottom:14px">
          <label class="form-label" style="font-weight:600;font-size:0.84rem">GSTIN (GST Identification Number)</label>
          <input type="text" name="gstin" id="modal_supp_gstin" class="form-control" placeholder="e.g. 29AAAAA0000A1Z5" maxlength="20" style="text-transform:uppercase">
        </div>

        <div class="form-group" style="margin-bottom:0">
          <label class="form-label" style="font-weight:600;font-size:0.84rem">Address / City</label>
          <textarea name="address" id="modal_supp_address" class="form-control" rows="2" placeholder="e.g. Surat Textile Hub, Ring Road, Surat, Gujarat"></textarea>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-light" onclick="closeSupplierModal()">Cancel</button>
        <button type="submit" class="btn btn-primary" id="suppModalSubmitBtn"><i class="fas fa-save"></i> Save Supplier</button>
      </div>
    </form>
  </div>
</div>

<script>
function switchHubTab(tab) {
  const isBills = (tab === 'bills');
  
  // Toggle buttons
  const btnB = document.getElementById('tabBtnBills');
  const btnS = document.getElementById('tabBtnSuppliers');
  if (btnB) {
    btnB.className = 'btn btn-sm ' + (isBills ? 'btn-primary' : 'btn-light');
  }
  if (btnS) {
    btnS.className = 'btn btn-sm ' + (!isBills ? 'btn-primary' : 'btn-light');
  }

  // Toggle content
  const cB = document.getElementById('tabContentBills');
  const cS = document.getElementById('tabContentSuppliers');
  if (cB) cB.style.display = isBills ? 'block' : 'none';
  if (cS) cS.style.display = !isBills ? 'block' : 'none';

  // Toggle tips
  const tB = document.getElementById('tabTipBills');
  const tS = document.getElementById('tabTipSuppliers');
  if (tB) tB.style.display = isBills ? 'inline' : 'none';
  if (tS) tS.style.display = !isBills ? 'inline' : 'none';

  // Update URL history without reload
  const url = new URL(window.location);
  url.searchParams.set('tab', tab);
  window.history.replaceState({}, '', url);
}

function openSupplierModal() {
  document.getElementById('suppModalTitle').textContent = 'Add Supplier / Mill';
  document.getElementById('modal_supp_id').value = '0';
  document.getElementById('modal_supp_name').value = '';
  document.getElementById('modal_supp_contact').value = '';
  document.getElementById('modal_supp_mobile').value = '';
  document.getElementById('modal_supp_gstin').value = '';
  document.getElementById('modal_supp_address').value = '';
  document.getElementById('suppModalSubmitBtn').innerHTML = '<i class="fas fa-save"></i> Save Supplier';
  
  const m = document.getElementById('supplierModal');
  if (m) m.classList.add('active');
  setTimeout(() => document.getElementById('modal_supp_name').focus(), 50);
}

function openEditSupplierModal(s) {
  document.getElementById('suppModalTitle').textContent = 'Edit Supplier: ' + s.name;
  document.getElementById('modal_supp_id').value = s.id;
  document.getElementById('modal_supp_name').value = s.name || '';
  document.getElementById('modal_supp_contact').value = s.contact_person || '';
  document.getElementById('modal_supp_mobile').value = s.mobile || '';
  document.getElementById('modal_supp_gstin').value = s.gstin || '';
  document.getElementById('modal_supp_address').value = s.address || '';
  document.getElementById('suppModalSubmitBtn').innerHTML = '<i class="fas fa-save"></i> Update Supplier';
  
  const m = document.getElementById('supplierModal');
  if (m) m.classList.add('active');
  setTimeout(() => document.getElementById('modal_supp_name').focus(), 50);
}

function closeSupplierModal() {
  const m = document.getElementById('supplierModal');
  if (m) m.classList.remove('active');
}

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') closeSupplierModal();
});
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
