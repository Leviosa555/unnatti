<?php
/**
 * Admin: Add Purchase / Stock Entry
 * Complete supplier-side stock inward with Material Type, Category, Supplier Invoice, Qty (Meters), Price, and GST.
 */
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireAdmin();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $purchaseDate   = sanitize($_POST['purchase_date'] ?? date('Y-m-d'));
    $supplierName   = sanitize($_POST['supplier_name'] ?? '');
    $supplierInvNo  = sanitize($_POST['supplier_invoice_no'] ?? '');
    $notes          = sanitize($_POST['notes'] ?? '');
    $items          = $_POST['items'] ?? [];

    if (empty($items)) $errors[] = 'Add at least one material/product.';
    if (!strtotime($purchaseDate)) $errors[] = 'Invalid purchase date.';

    if (empty($errors)) {
        try {
            db()->beginTransaction();

            // Find or create supplier
            $supplierId = null;
            if ($supplierName) {
                $existingSupp = db()->fetchOne("SELECT id FROM suppliers WHERE name=?", [$supplierName]);
                $supplierId   = $existingSupp ? $existingSupp['id'] : db()->insert("INSERT INTO suppliers (name) VALUES (?)", [$supplierName]);
            }

            $purchaseNo = generatePurchaseNumber();
            $totalAmt   = 0;
            $totalQty   = 0;

            // Validate all items first
            $validItems = [];
            foreach ($items as $i => $item) {
                $variantId  = (int)($item['variant_id'] ?? 0);
                $qty        = (int)($item['qty'] ?? 0);
                $purPrice   = (float)($item['purchase_price'] ?? 0);
                $purGstPct  = (float)($item['purchase_gst_pct'] ?? getSetting('purchase_gst_pct', '5'));
                $manualName = sanitize(trim($item['manual_name'] ?? ''));
                $manualUnit = sanitize(trim($item['manual_unit'] ?? 'Meter'));

                if (($variantId <= 0 && $manualName === '') || $qty <= 0 || $purPrice < 0) continue;

                // Handle on-the-fly manually entered material
                if ($variantId <= 0 && $manualName !== '') {
                    $existingVar = db()->fetchOne(
                        "SELECT pv.id, pv.sku, p.id AS product_id, pv.size 
                         FROM product_variants pv 
                         JOIN products p ON pv.product_id = p.id 
                         WHERE p.name = ? AND p.item_type = 'material' LIMIT 1",
                        [$manualName]
                    );

                    if ($existingVar) {
                        $variantId = (int)$existingVar['id'];
                        $variant   = $existingVar;
                    } else {
                        $catId = 34;
                        if (stripos($manualUnit, 'Pcs') !== false || stripos($manualName, 'Button') !== false || stripos($manualName, 'Hook') !== false || stripos($manualName, 'Zip') !== false || stripos($manualName, 'Thread') !== false || stripos($manualName, 'Tag') !== false) {
                            $catId = 35;
                        }
                        $cleanCode = strtoupper(preg_replace('/[^A-Z0-9]/', '', substr($manualName, 0, 8))) ?: 'MAT';
                        $sku = 'MAT-' . $cleanCode . '-01';
                        $skuCount = (int)(db()->fetchOne("SELECT COUNT(*) as c FROM product_variants WHERE sku=?", [$sku])['c'] ?? 0);
                        if ($skuCount > 0) $sku = 'MAT-' . $cleanCode . '-' . rand(10, 99);

                        $manualGarmentCat = sanitize(trim($item['garment_category'] ?? 'Pant / Trouser'));
                        if (!in_array($manualGarmentCat, ['Pant / Trouser', 'Shirt'])) {
                            $manualGarmentCat = 'Pant / Trouser';
                        }

                        $newProdId = db()->insert(
                            "INSERT INTO products (category_id, item_type, garment_category, name, brand, status, created_by) VALUES (?, 'material', ?, ?, 'Manual Tailoring Material', 'active', ?)",
                            [$catId, $manualGarmentCat, $manualName, currentUserId()]
                        );
                        $initialSize = (!empty($item['custom_spec'])) ? trim($item['custom_spec']) : ($manualUnit ?: 'Meter');
                        $newVarId = db()->insert(
                            "INSERT INTO product_variants (product_id, sku, size, purchase_price, purchase_gst_pct, purchase_gst_amount, purchase_price_with_gst, current_stock, min_stock_alert, status) VALUES (?,?,?,?,?,?,?,0,5,'active')",
                            [$newProdId, $sku, $initialSize, $purPrice, $purGstPct, round($purPrice * $purGstPct / 100, 2), round($purPrice * (1 + $purGstPct / 100), 2)]
                        );
                        $variantId = (int)$newVarId;
                        $variant   = ['id' => $variantId, 'sku' => $sku, 'product_id' => $newProdId, 'size' => $initialSize];
                    }
                } else {
                    $variant = db()->fetchOne("SELECT id, sku, product_id, size FROM product_variants WHERE id=?", [$variantId]);
                    if (!$variant) continue;
                }

                // Handle custom specification (e.g. Color: Sky Blue, Size: 17 and half)
                $customSpec = trim($item['custom_spec'] ?? '');
                if ($customSpec !== '' && $customSpec !== $variant['size']) {
                    $existingVar = db()->fetchOne("SELECT id, sku FROM product_variants WHERE product_id=? AND size=?", [$variant['product_id'], $customSpec]);
                    if ($existingVar) {
                        $variantId = (int)$existingVar['id'];
                    } else {
                        $cleanCode = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', substr($customSpec, 0, 10)));
                        $newSku = $variant['sku'] . '-' . ($cleanCode ?: rand(100, 999));
                        $skuCount = (int)(db()->fetchOne("SELECT COUNT(*) as c FROM product_variants WHERE sku=?", [$newSku])['c'] ?? 0);
                        if ($skuCount > 0) $newSku .= '-' . rand(10, 99);
                        $newVarId = db()->insert(
                            "INSERT INTO product_variants (product_id, sku, size, purchase_price, purchase_gst_pct, purchase_gst_amount, purchase_price_with_gst, current_stock, min_stock_alert, status) VALUES (?,?,?,?,?,?,?,0,5,'active')",
                            [$variant['product_id'], $newSku, $customSpec, $purPrice, $purGstPct, round($purPrice * $purGstPct / 100, 2), round($purPrice * (1 + $purGstPct / 100), 2)]
                        );
                        $variantId = (int)$newVarId;
                    }
                }

                $pricing   = calculatePricing($purPrice, $purGstPct, 0, 0); // Only purchase part
                $totalItem = $pricing['purchase_price_with_gst'] * $qty;
                $totalAmt += $totalItem;
                $totalQty += $qty;

                $validItems[] = [
                    'variant_id'              => $variantId,
                    'qty'                     => $qty,
                    'purchase_price'          => $purPrice,
                    'purchase_gst_pct'        => $purGstPct,
                    'purchase_gst_amount'     => $pricing['purchase_gst_amount'],
                    'purchase_price_with_gst' => $pricing['purchase_price_with_gst'],
                    'total_amount'            => $totalItem,
                ];
            }

            if (empty($validItems)) throw new Exception('No valid materials to save. Please ensure quantity and price are entered.');

            // Create purchase header
            $purchaseId = db()->insert(
                "INSERT INTO purchases (purchase_no, purchase_date, supplier_id, supplier_name, supplier_invoice_no, total_items, total_qty, total_amount, notes, created_by)
                 VALUES (?,?,?,?,?,?,?,?,?,?)",
                [$purchaseNo, $purchaseDate, $supplierId, $supplierName ?: null, $supplierInvNo ?: null, count($validItems), $totalQty, $totalAmt, $notes, currentUserId()]
            );

            // Insert items and update stock
            foreach ($validItems as $vi) {
                $piId = db()->insert(
                    "INSERT INTO purchase_items (purchase_id, variant_id, qty, purchase_price, purchase_gst_pct, purchase_gst_amount, purchase_price_with_gst, total_amount, remaining_qty)
                     VALUES (?,?,?,?,?,?,?,?,?)",
                    [$purchaseId, $vi['variant_id'], $vi['qty'], $vi['purchase_price'], $vi['purchase_gst_pct'], $vi['purchase_gst_amount'], $vi['purchase_price_with_gst'], $vi['total_amount'], $vi['qty']]
                );

                recordStockMovement($vi['variant_id'], 'purchase', $vi['qty'], $purchaseId, 'purchase', "Purchase: $purchaseNo (Inv: $supplierInvNo)", currentUserId());

                // Update variant's current purchase price
                db()->execute(
                    "UPDATE product_variants SET purchase_price=?, purchase_gst_pct=?, purchase_gst_amount=?, purchase_price_with_gst=? WHERE id=?",
                    [$vi['purchase_price'], $vi['purchase_gst_pct'], $vi['purchase_gst_amount'], $vi['purchase_price_with_gst'], $vi['variant_id']]
                );
            }

            db()->commit();
            logActivity("Stock entry recorded: $purchaseNo (₹$totalAmt)", 'purchases', $purchaseId, 'purchase');
            $_SESSION['flash_success'] = "Purchase $purchaseNo saved successfully! Total: ₹" . number_format($totalAmt, 2);
            header('Location: ' . APP_URL . '/admin/purchases/view.php?id=' . $purchaseId);
            exit;
        } catch (Exception $e) {
            db()->rollback();
            $errors[] = 'Error: ' . $e->getMessage();
        }
    }
}

// Prefill material from query parameter if present
$prefillSku = sanitize($_GET['sku'] ?? '');
$prefillMaterial = null;
if ($prefillSku) {
    $prefillMaterial = db()->fetchOne(
        "SELECT pv.id, pv.sku, pv.size, pv.purchase_price, pv.purchase_gst_pct, pv.current_stock,
                p.id AS product_id, p.name, p.brand, p.item_type,
                c.name AS category_name, sc.name AS subcategory_name
         FROM product_variants pv
         JOIN products p ON pv.product_id = p.id
         LEFT JOIN categories c ON p.category_id = c.id
         LEFT JOIN categories sc ON p.subcategory_id = sc.id
         WHERE pv.sku = ?",
        [$prefillSku]
    );
}

// Fetch tailoring kit raw materials for Pant & Shirt stock entry
$pantMaterialsData = db()->fetchAll(
    "SELECT pv.id, pv.sku, pv.size, pv.current_stock, pv.purchase_price, pv.purchase_gst_pct,
            p.id AS product_id, p.name, p.brand, p.item_type,
            c.name AS category_name, sc.name AS subcategory_name
     FROM product_variants pv
     JOIN products p ON pv.product_id = p.id
     LEFT JOIN categories c ON p.category_id = c.id
     LEFT JOIN categories sc ON p.subcategory_id = sc.id
     WHERE pv.sku IN ('MAT-PANT-ROLL-01', 'MAT-PANT-ZIP-01', 'MAT-PANT-HOOK-01', 'MAT-PANT-POCK-01', 'MAT-PANT-BTN-01')
     ORDER BY FIELD(pv.sku, 'MAT-PANT-ROLL-01', 'MAT-PANT-ZIP-01', 'MAT-PANT-HOOK-01', 'MAT-PANT-POCK-01', 'MAT-PANT-BTN-01')"
);

$shirtMaterialsData = db()->fetchAll(
    "SELECT pv.id, pv.sku, pv.size, pv.current_stock, pv.purchase_price, pv.purchase_gst_pct,
            p.id AS product_id, p.name, p.brand, p.item_type,
            c.name AS category_name, sc.name AS subcategory_name
     FROM product_variants pv
     JOIN products p ON pv.product_id = p.id
     LEFT JOIN categories c ON p.category_id = c.id
     LEFT JOIN categories sc ON p.subcategory_id = sc.id
     WHERE pv.sku IN ('MAT-SHIRT-CLOTH-01', 'MAT-SHIRT-COLLAR-01', 'MAT-SHIRT-CUFF-01', 'MAT-SHIRT-BTN-01')
     ORDER BY FIELD(pv.sku, 'MAT-SHIRT-CLOTH-01', 'MAT-SHIRT-COLLAR-01', 'MAT-SHIRT-CUFF-01', 'MAT-SHIRT-BTN-01')"
);

$suppliers  = db()->fetchAll("SELECT id, name FROM suppliers WHERE status='active' ORDER BY name");
$categories = db()->fetchAll("SELECT id, name FROM categories WHERE parent_id IS NULL AND status='active' ORDER BY name");

$pageTitle = 'Stock Entry / Purchase'; $activePage = 'purchases';
$breadcrumb = [['label'=>'Purchases','url'=>APP_URL.'/admin/purchases/index.php'],['label'=>'Stock Entry']];
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<script>
window.APP_URL = '<?= APP_URL ?>';
window.CSRF_TOKEN = '<?= csrfToken() ?>';
window.PREFILL_MATERIAL = <?= $prefillMaterial ? json_encode($prefillMaterial) : 'null' ?>;
window.PANT_KIT = <?= json_encode($pantMaterialsData) ?>;
window.SHIRT_KIT = <?= json_encode($shirtMaterialsData) ?>;
</script>

<style>
.search-material-bar {
  position: relative;
  z-index: 1000;
  padding: 16px 20px;
  background: #f8fafc;
  border-bottom: 1px solid #e2e8f0;
}
.material-dropdown-list {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  right: 0;
  background: #ffffff;
  border: 1px solid #cbd5e1;
  border-radius: 10px;
  box-shadow: 0 12px 30px rgba(0,0,0,0.18);
  z-index: 99999;
  max-height: 320px;
  overflow-y: auto;
  display: none;
}
.material-search-item {
  padding: 10px 16px;
  cursor: pointer;
  border-bottom: 1px solid #f1f5f9;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  background: #ffffff;
  transition: background 0.15s ease;
}
.material-search-item:hover {
  background: #f1f5f9;
}
.material-search-item:last-child {
  border-bottom: none;
}
.consignment-row-highlight {
  animation: rowHighlight 1.5s ease;
}
@keyframes rowHighlight {
  0% { background: #fef3c7; }
  100% { background: transparent; }
}
.quick-pill {
  cursor: pointer;
  background: #f8fafc;
  color: #334155;
  border: 1px solid #cbd5e1;
  padding: 4px 11px;
  border-radius: 9999px;
  font-size: 0.78rem;
  font-weight: 600;
  transition: all 0.15s ease;
  user-select: none;
  display: inline-flex;
  align-items: center;
  gap: 5px;
}
.quick-pill:hover {
  background: #e2e8f0;
  color: #0f172a;
  border-color: #94a3b8;
}
.quick-pill.active {
  background: #0284c7;
  color: #ffffff;
  border-color: #0369a1;
}
.stock-avail-chip {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 6px;
  font-weight: 700;
  font-size: 0.78rem;
  border: 1px solid #a7f3d0;
  background: #ecfdf5;
  color: #047857;
}
.stock-avail-chip.low {
  border-color: #fde68a;
  background: #fffbeb;
  color: #b45309;
}
.stock-avail-chip.out {
  border-color: #fecaca;
  background: #fef2f2;
  color: #b91c1c;
}
.kit-item-box {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px 14px;
  margin-bottom: 12px;
}
.mat-cat-tab {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  padding: 10px 6px;
  border: 1.5px solid #e2e8f0;
  border-radius: 10px;
  background: #ffffff;
  color: #334155;
  cursor: pointer;
  transition: all 0.18s ease;
  user-select: none;
  text-align: center;
}
.mat-cat-tab:hover {
  border-color: #3b82f6;
  background: #f8faff;
  transform: translateY(-1px);
}
.mat-cat-tab.active {
  border-color: #2563eb;
  background: #eff6ff;
  color: #1d4ed8;
  box-shadow: 0 0 0 2px rgba(37,99,235,0.2);
}
.mat-pill {
  cursor: pointer;
  background: #f1f5f9;
  color: #334155;
  border: 1px solid #cbd5e1;
  padding: 3px 9px;
  border-radius: 6px;
  font-size: 0.76rem;
  font-weight: 500;
  transition: all 0.15s ease;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  user-select: none;
}
.mat-pill:hover {
  background: #e2e8f0;
  border-color: #94a3b8;
  color: #0f172a;
}
.mat-pill.active {
  background: #2563eb;
  color: #ffffff;
  border-color: #1d4ed8;
  font-weight: 600;
}
.color-swatch-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 9px;
  border-radius: 6px;
  font-size: 0.76rem;
  font-weight: 500;
  border: 1px solid #cbd5e1;
  background: #ffffff;
  cursor: pointer;
  transition: all 0.15s ease;
  user-select: none;
}
.color-swatch-pill:hover {
  background: #f8fafc;
  border-color: #94a3b8;
}
.color-swatch-pill.active {
  border-color: #2563eb;
  background: #eff6ff;
  font-weight: 700;
  color: #1e40af;
}
.swatch-dot {
  width: 11px;
  height: 11px;
  border-radius: 50%;
  display: inline-block;
  border: 1px solid rgba(0,0,0,0.2);
}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title"><i class="fas fa-boxes text-primary" style="margin-right:10px"></i>Materials / Fabrics in this Consignment</h1>
    <p class="page-subtitle">Search and add fabrics, raw materials &amp; mill supplies to this consignment</p>
  </div>
  <div class="page-header-right">
    <a href="<?= APP_URL ?>/admin/purchases/index.php" class="btn btn-light"><i class="fas fa-arrow-left"></i> All Purchases</a>
  </div>
</div>

<?php foreach ($errors as $err): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= e($err) ?></div>
<?php endforeach; ?>

<form method="POST" id="purchaseForm" onsubmit="return validatePurchaseForm()">
  <?= csrfField() ?>

  <!-- Materials / Fabrics Items Consignment Card (TOP) -->
  <div class="card" style="margin-bottom:24px;overflow:visible">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
      <div>
        <span class="card-title" style="margin-bottom:2px;display:block"><i class="fas fa-boxes"></i> Materials / Fabrics in this Consignment</span>
        <span style="font-size:0.8rem;color:#64748b">Inward fabrics (meters), buttons, zips, hooks &amp; trims into stock</span>
      </div>
      <div>
        <button type="button" class="btn btn-sm btn-success" onclick="openAddMaterialModal()" style="font-weight:600;display:flex;align-items:center;gap:7px;padding:8px 18px;border-radius:6px;box-shadow:0 1px 2px rgba(0,0,0,0.05);font-size:0.9rem">
          <i class="fas fa-plus-circle"></i> + Add Material
        </button>
      </div>
    </div>

    <!-- Consignment Items Table -->
    <div class="table-wrapper">
      <table class="billing-table">
        <thead>
          <tr>
            <th style="min-width:240px">Material &amp; SKU</th>
            <th style="width:150px">Category / Spec</th>
            <th style="width:130px" class="text-center">Available Stock</th>
            <th style="width:110px" class="text-center">Qty (Meters/Units)</th>
            <th style="width:130px" class="text-right">Price / Unit (₹)</th>
            <th style="width:90px" class="text-right">GST %</th>
            <th style="width:100px" class="text-right">GST (₹)</th>
            <th style="width:110px" class="text-right">Price+GST</th>
            <th style="width:120px" class="text-right">Total (₹)</th>
            <th style="width:40px" class="text-center">Action</th>
          </tr>
        </thead>
        <tbody id="purchaseBody">
          <tr id="emptyRow">
            <td colspan="10" class="text-center text-muted" style="padding:48px 20px">
              <i class="fas fa-scroll" style="font-size:2.5rem;opacity:0.25;display:block;margin-bottom:12px"></i>
              <h5 style="color:#64748b;font-weight:600;margin-bottom:4px">No materials added to this consignment yet</h5>
              <p style="font-size:0.85rem;margin-bottom:0">Click <strong>+ Add Material</strong> above or search directory to add cloth, buttons, zips, hooks and trims.</p>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Stock Entry / Supplier Purchase Section (BOTTOM) -->
  <div style="margin-top:28px">
    <div style="margin-bottom:14px">
      <h2 style="font-size:1.2rem;font-weight:700;color:#1e293b;margin-bottom:4px;display:flex;align-items:center">
        <i class="fas fa-truck-loading text-primary" style="margin-right:10px"></i>Stock Entry / Supplier Purchase
      </h2>
      <p style="color:#64748b;font-size:0.85rem;margin:0">Log incoming fabric, uniform materials &amp; supplies from wholesalers/mills</p>
    </div>

    <div class="grid-2">
      <!-- Invoice & Supplier Details -->
      <div class="card">
        <div class="card-header">
          <span class="card-title"><i class="fas fa-file-invoice"></i> Supplier &amp; Invoice Details</span>
        </div>
        <div class="card-body">
          <div class="form-row cols-2">
            <div class="form-group">
              <label class="form-label">Purchase / Inward Date <span class="required">*</span></label>
              <input type="date" name="purchase_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div class="form-group">
              <label class="form-label">Supplier Name <span class="required">*</span></label>
              <input type="text" name="supplier_name" class="form-control" placeholder="e.g. Raymond Mills, Arvind Textiles..." list="supplierList" value="<?= e($_POST['supplier_name'] ?? ($_GET['supplier'] ?? '')) ?>" required>
              <datalist id="supplierList">
                <?php foreach ($suppliers as $s): ?><option value="<?= e($s['name']) ?>"><?php endforeach; ?>
              </datalist>
            </div>
          </div>
          <div class="form-row cols-2">
            <div class="form-group">
              <label class="form-label">Supplier Invoice No <span class="required">*</span></label>
              <input type="text" name="supplier_invoice_no" class="form-control" placeholder="e.g. TEX-2026-8842 / INV-9901" value="<?= e($_POST['supplier_invoice_no'] ?? '') ?>" required>
              <small class="text-muted">Exact bill number from the supplier for tax / ITC reconciliation</small>
            </div>
            <div class="form-group">
              <label class="form-label">Order Reference / Outfit Purpose</label>
              <input type="text" name="notes" class="form-control" placeholder="e.g. Uniform batch - 20 sets for St. Joseph School">
              <small class="text-muted">Notes on customer order or project requirements</small>
            </div>
          </div>
        </div>
      </div>

      <!-- Purchase Summary Card -->
      <div class="card">
        <div class="card-header">
          <span class="card-title"><i class="fas fa-calculator"></i> Inward Summary</span>
        </div>
        <div class="card-body">
          <div class="bill-summary">
            <div class="bill-summary-row"><span class="bill-summary-label">Total Materials</span><span id="summaryItems">0</span></div>
            <div class="bill-summary-row"><span class="bill-summary-label">Total Qty (Meters/Units)</span><span id="summaryQty" style="font-weight:700">0</span></div>
            <div class="bill-summary-row"><span class="bill-summary-label">Subtotal (excl. GST)</span><span id="summarySubtotal">₹0.00</span></div>
            <div class="bill-summary-row"><span class="bill-summary-label">Total GST Amount</span><span id="summaryGst" class="text-muted">₹0.00</span></div>
            <div class="bill-summary-row total"><span>Total Inward Amount</span><span class="amount" id="summaryTotal">₹0.00</span></div>
          </div>
          <button type="submit" id="savePurchaseBtn" class="btn btn-success btn-lg w-100 mt-3">
            <i class="fas fa-save"></i> Save Stock Entry
          </button>
        </div>
      </div>
    </div>
  </div>
</form>

<!-- DYNAMIC ADD MATERIAL MODAL -->
<div class="modal-overlay" id="addMaterialModal">
  <div class="modal" style="max-width:800px">
    <div class="modal-header">
      <span class="modal-title" style="display:flex;align-items:center;gap:8px">
        <i class="fas fa-layer-group text-primary"></i> Add Raw Material to Stock Consignment
      </span>
      <button type="button" class="modal-close" onclick="closeAddMaterialModal()">&times;</button>
    </div>
    <div class="modal-body">
      <p class="text-muted text-sm" style="margin-bottom:14px">
        Select the raw material type below to dynamically configure meters, types, colors, and stock rates.
      </p>

      <!-- Category Selector Tabs -->
      <div class="material-cat-grid" style="display:grid;grid-template-columns:repeat(auto-fit, minmax(95px, 1fr));gap:6px;margin-bottom:18px">
        <div class="mat-cat-tab active" id="tab_cloth" onclick="switchMaterialCategory('cloth')">
          <span style="font-size:1.25rem">🧵</span>
          <span style="font-weight:700;font-size:0.8rem">Cloth / Fabric</span>
          <span style="font-size:0.68rem;color:#64748b">Meters</span>
        </div>
        <div class="mat-cat-tab" id="tab_buttons" onclick="switchMaterialCategory('buttons')">
          <span style="font-size:1.25rem">🔘</span>
          <span style="font-weight:700;font-size:0.8rem">Buttons</span>
          <span style="font-size:0.68rem;color:#64748b">Pkts (~200/pkt)</span>
        </div>
        <div class="mat-cat-tab" id="tab_collar_canvas" onclick="switchMaterialCategory('collar_canvas')">
          <span style="font-size:1.25rem">👔</span>
          <span style="font-weight:700;font-size:0.8rem">Collar Canvas</span>
          <span style="font-size:0.68rem;color:#64748b">Pkts (~15/pkt)</span>
        </div>
        <div class="mat-cat-tab" id="tab_waist_canvas" onclick="switchMaterialCategory('waist_canvas')">
          <span style="font-size:1.25rem">👖</span>
          <span style="font-weight:700;font-size:0.8rem">Waist Canvas</span>
          <span style="font-size:0.68rem;color:#64748b">Rolls / Pkts</span>
        </div>
        <div class="mat-cat-tab" id="tab_zipper" onclick="switchMaterialCategory('zipper')">
          <span style="font-size:1.25rem">🤐</span>
          <span style="font-weight:700;font-size:0.8rem">Zipper</span>
          <span style="font-size:0.68rem;color:#64748b">Pkts (~100/pkt)</span>
        </div>
        <div class="mat-cat-tab" id="tab_hook" onclick="switchMaterialCategory('hook')">
          <span style="font-size:1.25rem">🪝</span>
          <span style="font-weight:700;font-size:0.8rem">Waist Hook</span>
          <span style="font-size:0.68rem;color:#64748b">Pkts (~200/pkt)</span>
        </div>
        <div class="mat-cat-tab" id="tab_other" onclick="switchMaterialCategory('other')">
          <span style="font-size:1.25rem">📦</span>
          <span style="font-weight:700;font-size:0.8rem">Other / Trims</span>
          <span style="font-size:0.68rem;color:#64748b">Custom Unit</span>
        </div>
      </div>

      <!-- ================= 1. CLOTH / FABRIC SECTION ================= -->
      <div id="dm_sec_cloth" class="dm-category-panel">
        <div class="form-row cols-2">
          <div class="form-group">
            <label class="form-label" style="font-weight:600">Type of Cloth / Fabric <span class="required">*</span></label>
            <input type="text" id="dm_cloth_type" class="form-control" placeholder="e.g. Cotton, Linen, Poly-Viscose Twill..." value="Cotton" oninput="updateDynamicMaterialPreview()">
            <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:6px">
              <span class="mat-pill active" onclick="setClothType('Cotton', this)">Cotton</span>
              <span class="mat-pill" onclick="setClothType('Linen', this)">Linen</span>
              <span class="mat-pill" onclick="setClothType('Poly-Viscose Twill', this)">Poly-Viscose Twill</span>
              <span class="mat-pill" onclick="setClothType('Poplin', this)">Poplin</span>
              <span class="mat-pill" onclick="setClothType('Oxford Cotton', this)">Oxford</span>
              <span class="mat-pill" onclick="setClothType('Suiting Fabric', this)">Suiting</span>
              <span class="mat-pill" onclick="setClothType('Denim', this)">Denim</span>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label" style="font-weight:600">Color / Shade <span class="required">*</span></label>
            <input type="text" id="dm_cloth_color" class="form-control" placeholder="e.g. Navy Blue, Sky Blue, White..." value="Navy Blue" oninput="updateDynamicMaterialPreview()">
            <div style="display:flex;gap:5px;flex-wrap:wrap;margin-top:6px">
              <span class="color-swatch-pill active" onclick="setClothColor('Navy Blue', this)"><span class="swatch-dot" style="background:#1e3a8a"></span>Navy Blue</span>
              <span class="color-swatch-pill" onclick="setClothColor('Sky Blue', this)"><span class="swatch-dot" style="background:#0ea5e9"></span>Sky Blue</span>
              <span class="color-swatch-pill" onclick="setClothColor('White', this)"><span class="swatch-dot" style="background:#ffffff;border-color:#cbd5e1"></span>White</span>
              <span class="color-swatch-pill" onclick="setClothColor('Jet Black', this)"><span class="swatch-dot" style="background:#0f172a"></span>Jet Black</span>
              <span class="color-swatch-pill" onclick="setClothColor('Charcoal Grey', this)"><span class="swatch-dot" style="background:#475569"></span>Charcoal</span>
              <span class="color-swatch-pill" onclick="setClothColor('Beige / Khaki', this)"><span class="swatch-dot" style="background:#d4b996"></span>Beige</span>
              <span class="color-swatch-pill" onclick="setClothColor('Olive Green', this)"><span class="swatch-dot" style="background:#4d7c0f"></span>Olive</span>
              <span class="color-swatch-pill" onclick="setClothColor('Maroon', this)"><span class="swatch-dot" style="background:#831843"></span>Maroon</span>
            </div>
          </div>
        </div>

        <div class="form-row cols-3">
          <div class="form-group mb-0">
            <label class="form-label" style="font-weight:600">Pattern / Weave</label>
            <select id="dm_cloth_pattern" class="form-select" onchange="updateDynamicMaterialPreview()">
              <option value="Plain / Solid" selected>Plain / Solid</option>
              <option value="Twill Weave">Twill Weave</option>
              <option value="Striped">Striped</option>
              <option value="Checked">Checked</option>
              <option value="Self Texture">Self Texture</option>
              <option value="Dobby">Dobby</option>
            </select>
          </div>
          <div class="form-group mb-0">
            <label class="form-label" style="font-weight:600">Quantity (Meters) <span class="required">*</span></label>
            <input type="number" id="dm_cloth_qty" class="form-control text-center" min="1" step="1" value="200" style="font-weight:700" oninput="updateDynamicMaterialPreview()" required>
          </div>
          <div class="form-group mb-0">
            <label class="form-label" style="font-weight:600">Price / Meter (₹) <span class="required">*</span></label>
            <input type="number" id="dm_cloth_price" class="form-control text-right" min="0" step="0.01" value="160.00" oninput="updateDynamicMaterialPreview()" required>
          </div>
        </div>

        <!-- Tailoring Cut Note -->
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:9px 13px;font-size:0.8rem;color:#1e40af;display:flex;align-items:center;gap:10px;margin-top:12px">
          <i class="fas fa-scissors" style="font-size:1.15rem;flex-shrink:0;color:#2563eb"></i>
          <div><strong>Tailoring Cut Rule:</strong> Collar, cuffs, waistband, and pockets are cut from this same cloth during tailoring. No separate entry required!</div>
        </div>
      </div>

      <!-- ================= 2. BUTTONS SECTION ================= -->
      <div id="dm_sec_buttons" class="dm-category-panel" style="display:none">
        <div class="form-row cols-2">
          <div class="form-group">
            <label class="form-label" style="font-weight:600">Button Type / Garment Use <span class="required">*</span></label>
            <input type="text" id="dm_button_type" class="form-control" placeholder="e.g. Shirt Placket Buttons..." value="Shirt Placket Buttons" oninput="updateDynamicMaterialPreview()">
            <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:6px">
              <span class="mat-pill active" onclick="setButtonType('Shirt Placket Buttons', this)">Shirt Placket Buttons</span>
              <span class="mat-pill" onclick="setButtonType('Trouser Horn Buttons', this)">Trouser Horn Buttons</span>
              <span class="mat-pill" onclick="setButtonType('Blazer / Suit Buttons', this)">Blazer Buttons</span>
              <span class="mat-pill" onclick="setButtonType('Mother of Pearl (MOP)', this)">MOP</span>
              <span class="mat-pill" onclick="setButtonType('Casual 4-Hole Buttons', this)">Casual 4-Hole</span>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label" style="font-weight:600">Color / Finish <span class="required">*</span></label>
            <input type="text" id="dm_button_color" class="form-control" placeholder="e.g. Pearl White, Navy Blue..." value="Pearl White" oninput="updateDynamicMaterialPreview()">
            <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:6px">
              <span class="mat-pill active" onclick="setButtonColor('Pearl White', this)">Pearl White</span>
              <span class="mat-pill" onclick="setButtonColor('Navy Blue', this)">Navy Blue</span>
              <span class="mat-pill" onclick="setButtonColor('Smoke Grey', this)">Smoke Grey</span>
              <span class="mat-pill" onclick="setButtonColor('Jet Black', this)">Jet Black</span>
              <span class="mat-pill" onclick="setButtonColor('Wooden Finish', this)">Wooden</span>
              <span class="mat-pill" onclick="setButtonColor('Brass / Gold', this)">Brass</span>
            </div>
          </div>
        </div>

        <!-- Packaging & Packet Entry Box -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <span style="font-size:0.85rem;font-weight:700;color:#1e293b"><i class="fas fa-boxes text-primary"></i> Packaging &amp; Packet Calculator</span>
            <span class="badge" style="background:#e0f2fe;color:#0369a1;font-size:0.75rem">~200 pcs / packet</span>
          </div>
          <div class="form-row cols-3">
            <div class="form-group mb-0">
              <label class="form-label text-xs">Number of Packets <span class="required">*</span></label>
              <input type="number" id="dm_btn_packets" class="form-control text-center" min="1" step="1" value="5" oninput="calcButtonPacketQty()">
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-xs">Pieces per Packet</label>
              <input type="number" id="dm_btn_per_pkt" class="form-control text-center" min="1" step="1" value="200" oninput="calcButtonPacketQty()">
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-xs">Price per Packet (₹)</label>
              <input type="number" id="dm_btn_price_pkt" class="form-control text-right" min="0" step="0.5" value="300.00" oninput="calcButtonPacketQty()">
            </div>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;padding-top:8px;border-top:1px dashed #cbd5e1">
            <span style="font-size:0.82rem;color:#475569">Total Inward: <strong id="btn_calc_summary" style="color:#059669;font-size:0.95rem">5 Packets &times; 200 = 1,000 Pieces</strong></span>
            <span style="font-size:0.82rem;color:#64748b" id="btn_calc_unit_rate">Effective: ₹1.50 / piece</span>
          </div>
        </div>

        <div class="form-row cols-3">
          <div class="form-group mb-0">
            <label class="form-label" style="font-weight:600">Button Size / Ligne</label>
            <select id="dm_button_size" class="form-select" onchange="updateDynamicMaterialPreview()">
              <option value="18L (11.5mm - Shirt Standard)" selected>18L (11.5mm - Shirt Standard)</option>
              <option value="16L (10mm - Shirt Sleeve)">16L (10mm - Shirt Sleeve)</option>
              <option value="20L (12.5mm)">20L (12.5mm)</option>
              <option value="24L (15mm - Trouser Waist)">24L (15mm - Trouser Waist)</option>
              <option value="32L (20mm - Blazer Front)">32L (20mm - Blazer Front)</option>
            </select>
          </div>
          <div class="form-group mb-0">
            <label class="form-label" style="font-weight:600">Quantity (Pieces / Pcs) <span class="required">*</span></label>
            <input type="number" id="dm_button_qty" class="form-control text-center" min="1" step="1" value="1000" style="font-weight:700" oninput="updateDynamicMaterialPreview()" required>
          </div>
          <div class="form-group mb-0">
            <label class="form-label" style="font-weight:600">Price / Piece (₹) <span class="required">*</span></label>
            <input type="number" id="dm_button_price" class="form-control text-right" min="0" step="0.01" value="1.50" oninput="updateDynamicMaterialPreview()" required>
          </div>
        </div>
      </div>

      <!-- ================= 3. SHIRT COLLAR CANVAS SECTION ================= -->
      <div id="dm_sec_collar_canvas" class="dm-category-panel" style="display:none">
        <div class="form-row cols-2">
          <div class="form-group">
            <label class="form-label" style="font-weight:600">Collar Canvas / Fusing Type <span class="required">*</span></label>
            <input type="text" id="dm_cc_type" class="form-control" value="Shirt Collar Canvas (Pre-cut)" oninput="updateDynamicMaterialPreview()">
            <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:6px">
              <span class="mat-pill active" onclick="setCcType('Shirt Collar Canvas (Pre-cut)', this)">Standard Pre-cut</span>
              <span class="mat-pill" onclick="setCcType('Heavy Stiff Collar Canvas', this)">Heavy Stiff</span>
              <span class="mat-pill" onclick="setCcType('Soft Wash Collar Canvas', this)">Soft Wash</span>
              <span class="mat-pill" onclick="setCcType('Collar + Cuff Fusing Kit', this)">Collar &amp; Cuff Kit</span>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label" style="font-weight:600">Fusing Grade / Finish</label>
            <input type="text" id="dm_cc_grade" class="form-control" value="Fusing Grade-A White" oninput="updateDynamicMaterialPreview()">
            <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:6px">
              <span class="mat-pill active" onclick="setCcGrade('Fusing Grade-A White', this)">Grade-A White</span>
              <span class="mat-pill" onclick="setCcGrade('Micro-Dot Coated', this)">Micro-Dot</span>
              <span class="mat-pill" onclick="setCcGrade('Double Sided Fusing', this)">Double Sided</span>
            </div>
          </div>
        </div>

        <!-- Collar Canvas Packet Box -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <span style="font-size:0.85rem;font-weight:700;color:#1e293b"><i class="fas fa-box-open text-primary"></i> Packaging (~15 pieces per packet)</span>
            <span class="badge" style="background:#e0f2fe;color:#0369a1;font-size:0.75rem">Shirt Collar Cut Piece</span>
          </div>
          <div class="form-row cols-3">
            <div class="form-group mb-0">
              <label class="form-label text-xs">Number of Packets <span class="required">*</span></label>
              <input type="number" id="dm_cc_packets" class="form-control text-center" min="1" step="1" value="10" oninput="calcCollarCanvasQty()">
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-xs">Pieces per Packet</label>
              <input type="number" id="dm_cc_per_pkt" class="form-control text-center" min="1" step="1" value="15" oninput="calcCollarCanvasQty()">
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-xs">Price per Packet (₹)</label>
              <input type="number" id="dm_cc_price_pkt" class="form-control text-right" min="0" step="0.5" value="45.00" oninput="calcCollarCanvasQty()">
            </div>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;padding-top:8px;border-top:1px dashed #cbd5e1">
            <span style="font-size:0.82rem;color:#475569">Total Inward: <strong id="cc_calc_summary" style="color:#059669;font-size:0.95rem">10 Packets &times; 15 = 150 Pieces</strong></span>
            <span style="font-size:0.82rem;color:#64748b" id="cc_calc_unit_rate">Effective: ₹3.00 / piece</span>
          </div>
        </div>

        <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:9px 13px;font-size:0.8rem;color:#166534;display:flex;align-items:center;gap:10px">
          <i class="fas fa-info-circle" style="font-size:1.15rem;flex-shrink:0;color:#16a34a"></i>
          <div><strong>Tailoring Usage:</strong> Each shirt requires exactly 1 collar canvas fusing piece. 10 packets (150 pcs) allows production of 150 shirts.</div>
        </div>
      </div>

      <!-- ================= 4. PANT WAIST CANVAS SECTION ================= -->
      <div id="dm_sec_waist_canvas" class="dm-category-panel" style="display:none">
        <div class="form-row cols-2">
          <div class="form-group">
            <label class="form-label" style="font-weight:600">Canvas / Roll Type <span class="required">*</span></label>
            <input type="text" id="dm_wc_type" class="form-control" value="Pant Waist Canvas (Roll)" oninput="updateDynamicMaterialPreview()">
            <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:6px">
              <span class="mat-pill active" onclick="setWcType('Pant Waist Canvas (Roll)', this)">Waistband Roll (3.5cm)</span>
              <span class="mat-pill" onclick="setWcType('Trouser Buckram Canvas Roll', this)">Buckram Canvas</span>
              <span class="mat-pill" onclick="setWcType('Heavy Grip Waistband Roll', this)">Heavy Grip Roll</span>
              <span class="mat-pill" onclick="setWcType('Pre-slit Elasticated Waist Roll', this)">Elasticated Roll</span>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label" style="font-weight:600">Width / Specification</label>
            <select id="dm_wc_width" class="form-select" onchange="updateDynamicMaterialPreview()">
              <option value="1.5 Inch (3.8 cm) - Standard Trouser" selected>1.5 Inch (3.8 cm) - Standard Trouser</option>
              <option value="1.25 Inch (3.2 cm) - Slim Pant">1.25 Inch (3.2 cm) - Slim Pant</option>
              <option value="2.0 Inch (5.0 cm) - Security / Heavy Duty">2.0 Inch (5.0 cm) - Security Duty Pant</option>
            </select>
          </div>
        </div>

        <!-- Waist Canvas Roll/Packet Box -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <span style="font-size:0.85rem;font-weight:700;color:#1e293b"><i class="fas fa-tape text-primary"></i> Roll / Packet Entry (~30 to 50 meters per roll)</span>
            <span class="badge" style="background:#fef3c7;color:#92400e;font-size:0.75rem">Pant Waistband Fusing</span>
          </div>
          <div class="form-row cols-3">
            <div class="form-group mb-0">
              <label class="form-label text-xs">Number of Rolls / Packets <span class="required">*</span></label>
              <input type="number" id="dm_wc_rolls" class="form-control text-center" min="1" step="1" value="5" oninput="calcWaistCanvasQty()">
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-xs">Meters per Roll / Packet</label>
              <input type="number" id="dm_wc_per_roll" class="form-control text-center" min="1" step="1" value="30" oninput="calcWaistCanvasQty()">
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-xs">Price per Roll (₹)</label>
              <input type="number" id="dm_wc_price_roll" class="form-control text-right" min="0" step="1" value="150.00" oninput="calcWaistCanvasQty()">
            </div>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;padding-top:8px;border-top:1px dashed #cbd5e1">
            <span style="font-size:0.82rem;color:#475569">Total Inward: <strong id="wc_calc_summary" style="color:#059669;font-size:0.95rem">5 Rolls &times; 30m = 150 Meters</strong></span>
            <span style="font-size:0.82rem;color:#64748b" id="wc_calc_unit_rate">Effective: ₹5.00 / meter</span>
          </div>
        </div>

        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:9px 13px;font-size:0.8rem;color:#1e40af;display:flex;align-items:center;gap:10px">
          <i class="fas fa-scissors" style="font-size:1.15rem;flex-shrink:0;color:#2563eb"></i>
          <div><strong>Tailoring Usage:</strong> Each pant waistband takes approx 1.0 to 1.1 meters of waist canvas roll. 150 meters will cover ~140-150 trousers.</div>
        </div>
      </div>

      <!-- ================= 5. ZIPPER SECTION ================= -->
      <div id="dm_sec_zipper" class="dm-category-panel" style="display:none">
        <div class="form-row cols-3">
          <div class="form-group">
            <label class="form-label" style="font-weight:600">Zip Type <span class="required">*</span></label>
            <input type="text" id="dm_zip_type" class="form-control" placeholder="e.g. Pant Metal Zip..." value="Pant Metal Zip" oninput="updateDynamicMaterialPreview()">
            <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:6px">
              <span class="mat-pill active" onclick="setZipType('Pant Metal Zip (Brass)', this)">Metal (Brass)</span>
              <span class="mat-pill" onclick="setZipType('Pant Metal Zip (Aluminium)', this)">Metal (Alu)</span>
              <span class="mat-pill" onclick="setZipType('Nylon Coil Zip', this)">Nylon Coil</span>
              <span class="mat-pill" onclick="setZipType('Concealed / Invisible Zip', this)">Concealed</span>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label" style="font-weight:600">Length / Size</label>
            <select id="dm_zip_length" class="form-select" onchange="updateDynamicMaterialPreview()">
              <option value="6 Inch">6 Inch</option>
              <option value="7 Inch (Standard Pant)" selected>7 Inch (Standard Pant)</option>
              <option value="8 Inch">8 Inch</option>
              <option value="9 Inch">9 Inch</option>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label" style="font-weight:600">Color</label>
            <input type="text" id="dm_zip_color" class="form-control" placeholder="e.g. Navy Blue..." value="Navy Blue" oninput="updateDynamicMaterialPreview()">
            <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:6px">
              <span class="mat-pill active" onclick="setZipColor('Navy Blue', this)">Navy</span>
              <span class="mat-pill" onclick="setZipColor('Jet Black', this)">Black</span>
              <span class="mat-pill" onclick="setZipColor('Dark Grey', this)">Grey</span>
              <span class="mat-pill" onclick="setZipColor('Beige / Khaki', this)">Beige</span>
            </div>
          </div>
        </div>

        <!-- Packaging & Packet Entry Box -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <span style="font-size:0.85rem;font-weight:700;color:#1e293b"><i class="fas fa-boxes text-primary"></i> Packaging &amp; Packet Calculator</span>
            <span class="badge" style="background:#e0f2fe;color:#0369a1;font-size:0.75rem">~100 pcs / packet</span>
          </div>
          <div class="form-row cols-3">
            <div class="form-group mb-0">
              <label class="form-label text-xs">Number of Packets <span class="required">*</span></label>
              <input type="number" id="dm_zip_packets" class="form-control text-center" min="1" step="1" value="2" oninput="calcZipPacketQty()">
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-xs">Pieces per Packet</label>
              <input type="number" id="dm_zip_per_pkt" class="form-control text-center" min="1" step="1" value="100" oninput="calcZipPacketQty()">
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-xs">Price per Packet (₹)</label>
              <input type="number" id="dm_zip_price_pkt" class="form-control text-right" min="0" step="1" value="850.00" oninput="calcZipPacketQty()">
            </div>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;padding-top:8px;border-top:1px dashed #cbd5e1">
            <span style="font-size:0.82rem;color:#475569">Total Inward: <strong id="zip_calc_summary" style="color:#059669;font-size:0.95rem">2 Packets &times; 100 = 200 Pieces</strong></span>
            <span style="font-size:0.82rem;color:#64748b" id="zip_calc_unit_rate">Effective: ₹8.50 / piece</span>
          </div>
        </div>

        <div class="form-row cols-2">
          <div class="form-group mb-0">
            <label class="form-label" style="font-weight:600">Quantity (Pieces / Pcs) <span class="required">*</span></label>
            <input type="number" id="dm_zip_qty" class="form-control text-center" min="1" step="1" value="200" style="font-weight:700" oninput="updateDynamicMaterialPreview()" required>
          </div>
          <div class="form-group mb-0">
            <label class="form-label" style="font-weight:600">Price / Piece (₹) <span class="required">*</span></label>
            <input type="number" id="dm_zip_price" class="form-control text-right" min="0" step="0.01" value="8.50" oninput="updateDynamicMaterialPreview()" required>
          </div>
        </div>
      </div>

      <!-- ================= 6. WAIST HOOK SECTION ================= -->
      <div id="dm_sec_hook" class="dm-category-panel" style="display:none">
        <div class="form-row cols-2">
          <div class="form-group">
            <label class="form-label" style="font-weight:600">Hook Type <span class="required">*</span></label>
            <input type="text" id="dm_hook_type" class="form-control" placeholder="e.g. Trouser Hook &amp; Bar Set..." value="Trouser Hook &amp; Bar Set" oninput="updateDynamicMaterialPreview()">
            <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:6px">
              <span class="mat-pill active" onclick="setHookType('Trouser Hook &amp; Bar Set', this)">Hook &amp; Bar Set</span>
              <span class="mat-pill" onclick="setHookType('2-Prong Pant Clasp', this)">2-Prong Clasp</span>
              <span class="mat-pill" onclick="setHookType('Waist Extender Set', this)">Waist Extender</span>
              <span class="mat-pill" onclick="setHookType('Skirt Hook &amp; Eye', this)">Skirt Hook</span>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label" style="font-weight:600">Finish / Material <span class="required">*</span></label>
            <input type="text" id="dm_hook_finish" class="form-control" placeholder="e.g. Nickel Plated Steel..." value="Nickel Plated Steel (Anti-rust)" oninput="updateDynamicMaterialPreview()">
            <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:6px">
              <span class="mat-pill active" onclick="setHookFinish('Nickel Plated Steel (Anti-rust)', this)">Nickel (Anti-rust)</span>
              <span class="mat-pill" onclick="setHookFinish('Brass Finish', this)">Brass</span>
              <span class="mat-pill" onclick="setHookFinish('Gunmetal / Dark', this)">Gunmetal</span>
              <span class="mat-pill" onclick="setHookFinish('Silver Plated', this)">Silver</span>
            </div>
          </div>
        </div>

        <!-- Packaging & Packet Entry Box -->
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <span style="font-size:0.85rem;font-weight:700;color:#1e293b"><i class="fas fa-boxes text-primary"></i> Packaging &amp; Packet Calculator</span>
            <span class="badge" style="background:#e0f2fe;color:#0369a1;font-size:0.75rem">~200 sets / packet</span>
          </div>
          <div class="form-row cols-3">
            <div class="form-group mb-0">
              <label class="form-label text-xs">Number of Packets <span class="required">*</span></label>
              <input type="number" id="dm_hook_packets" class="form-control text-center" min="1" step="1" value="1" oninput="calcHookPacketQty()">
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-xs">Sets per Packet</label>
              <input type="number" id="dm_hook_per_pkt" class="form-control text-center" min="1" step="1" value="200" oninput="calcHookPacketQty()">
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-xs">Price per Packet (₹)</label>
              <input type="number" id="dm_hook_price_pkt" class="form-control text-right" min="0" step="1" value="800.00" oninput="calcHookPacketQty()">
            </div>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;padding-top:8px;border-top:1px dashed #cbd5e1">
            <span style="font-size:0.82rem;color:#475569">Total Inward: <strong id="hook_calc_summary" style="color:#059669;font-size:0.95rem">1 Packet &times; 200 = 200 Sets</strong></span>
            <span style="font-size:0.82rem;color:#64748b" id="hook_calc_unit_rate">Effective: ₹4.00 / set</span>
          </div>
        </div>

        <div class="form-row cols-2">
          <div class="form-group mb-0">
            <label class="form-label" style="font-weight:600">Quantity (Sets / Pieces) <span class="required">*</span></label>
            <input type="number" id="dm_hook_qty" class="form-control text-center" min="1" step="1" value="200" style="font-weight:700" oninput="updateDynamicMaterialPreview()" required>
          </div>
          <div class="form-group mb-0">
            <label class="form-label" style="font-weight:600">Price / Set (₹) <span class="required">*</span></label>
            <input type="number" id="dm_hook_price" class="form-control text-right" min="0" step="0.01" value="4.00" oninput="updateDynamicMaterialPreview()" required>
          </div>
        </div>
      </div>

      <!-- ================= 7. OTHER / TRIMS SECTION ================= -->
      <div id="dm_sec_other" class="dm-category-panel" style="display:none">
        <div class="form-row cols-2">
          <div class="form-group">
            <label class="form-label" style="font-weight:600">Item Name <span class="required">*</span></label>
            <input type="text" id="dm_other_name" class="form-control" placeholder="e.g. Sewing Thread Spool..." value="Sewing Thread Spool 1000m" oninput="updateDynamicMaterialPreview()">
            <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:6px">
              <span class="mat-pill active" onclick="setOtherName('Sewing Thread Spool 1000m', this)">Thread Spool</span>
              <span class="mat-pill" onclick="setOtherName('Elastic Band 1.5 Inch', this)">Elastic Band</span>
              <span class="mat-pill" onclick="setOtherName('Brand Woven Label Tags', this)">Brand Woven Tags</span>
              <span class="mat-pill" onclick="setOtherName('Wash Care Labels', this)">Wash Care Labels</span>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label" style="font-weight:600">Unit of Measure</label>
            <select id="dm_other_unit" class="form-select" onchange="updateDynamicMaterialPreview()">
              <option value="Meter">Meter</option>
              <option value="Pieces">Pieces (Pcs)</option>
              <option value="Spool" selected>Spool</option>
              <option value="Roll">Roll</option>
              <option value="Yard">Yard</option>
              <option value="Box">Box</option>
            </select>
          </div>
        </div>

        <div class="form-row cols-2">
          <div class="form-group mb-0">
            <label class="form-label" style="font-weight:600">Quantity <span class="required">*</span></label>
            <input type="number" id="dm_other_qty" class="form-control text-center" min="1" step="1" value="20" style="font-weight:700" oninput="updateDynamicMaterialPreview()" required>
          </div>
          <div class="form-group mb-0">
            <label class="form-label" style="font-weight:600">Price / Unit (₹) <span class="required">*</span></label>
            <input type="number" id="dm_other_price" class="form-control text-right" min="0" step="0.01" value="35.00" oninput="updateDynamicMaterialPreview()" required>
          </div>
        </div>
      </div>

      <!-- Shared GST & Garment Alignment -->
      <div class="form-row cols-2" style="margin-top:14px;padding-top:12px;border-top:1px dashed #e2e8f0">
        <div class="form-group mb-0">
          <label class="form-label" style="font-weight:600">Purchase GST %</label>
          <input type="number" id="dm_gst_pct" class="form-control" value="5" min="0" max="28" step="1" oninput="updateDynamicMaterialPreview()">
        </div>
        <div class="form-group mb-0">
          <label class="form-label" style="font-weight:600">Garment Group</label>
          <select id="dm_garment_category" class="form-select">
            <option value="General" selected>General Fabrics &amp; Trims</option>
            <option value="Shirt">Shirt</option>
            <option value="Pant / Trouser">Pant / Trouser</option>
          </select>
        </div>
      </div>

      <!-- Live Summary & Preview Card -->
      <div style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:12px 16px;margin-top:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <div>
          <div style="font-size:0.75rem;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;font-weight:700">Stock Inward Item Preview</div>
          <div id="dm_preview_name" style="font-weight:700;font-size:1.02rem;color:#0f172a;margin-top:2px">Navy Blue Cotton Fabric</div>
          <div style="display:flex;gap:6px;align-items:center;margin-top:4px;flex-wrap:wrap">
            <span class="sku-badge" id="dm_preview_sku">MAT-CLOTH-NAVY</span>
            <span class="badge badge-info" id="dm_preview_unit">Meter</span>
            <span style="font-size:0.75rem;color:#64748b" id="dm_preview_calc">200 Meters &times; ₹160.00</span>
          </div>
        </div>
        <div style="text-align:right">
          <div style="font-size:0.75rem;color:#64748b">Total Inward Value (incl. GST)</div>
          <div id="dm_preview_total" style="font-size:1.35rem;font-weight:800;color:#059669">₹33,600.00</div>
          <div style="font-size:0.73rem;color:#94a3b8" id="dm_preview_gst">GST: ₹1,600.00 (5%)</div>
        </div>
      </div>

    </div>
    <div class="modal-footer" style="display:flex;justify-content:space-between;align-items:center">
      <button type="button" class="btn btn-light" onclick="closeAddMaterialModal()">Cancel</button>
      <button type="button" class="btn btn-success btn-lg" onclick="submitDynamicMaterial()" style="font-weight:700;display:flex;align-items:center;gap:8px">
        <i class="fas fa-plus-circle"></i> + Add to Consignment Table
      </button>
    </div>
  </div>
</div>

<!-- QUICK ADD MATERIAL MODAL -->
<div class="modal-overlay" id="quickAddModal">
  <div class="modal modal-md">
    <div class="modal-header">
      <span class="modal-title"><i class="fas fa-magic text-primary"></i> Quick Add New Material / Fabric</span>
      <button class="modal-close" onclick="closeModal('quickAddModal')">&times;</button>
    </div>
    <form id="quickAddForm" onsubmit="submitQuickAddMaterial(event)">
      <div class="modal-body">
        <p class="text-muted text-sm" style="margin-bottom:16px">Define a new fabric or uniform material on the fly. It will be immediately added to this stock entry with 0 opening stock.</p>
        
        <div class="form-group">
          <label class="form-label">Material / Fabric Name <span class="required">*</span></label>
          <input type="text" id="qa_name" class="form-control" placeholder="e.g. Navy Blue Poly-Viscose Twill, White Oxford Cotton..." required>
        </div>

        <div class="form-row cols-2">
          <div class="form-group">
            <label class="form-label">Category</label>
            <select id="qa_category_id" class="form-select" onchange="loadQuickAddSubcategories(this.value)">
              <option value="">Select Category</option>
              <?php foreach ($categories as $cat): ?>
              <option value="<?= $cat['id'] ?>"><?= e($cat['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Subcategory</label>
            <select id="qa_subcategory_id" class="form-select">
              <option value="">Select Category First</option>
            </select>
          </div>
        </div>

        <div class="form-row cols-2">
          <div class="form-group">
            <label class="form-label">Material Type / Mill / Brand</label>
            <input type="text" id="qa_material_type" class="form-control" placeholder="e.g. Suiting Fabric, Raymond Uniform Line">
          </div>
          <div class="form-group">
            <label class="form-label">Unit of Measure</label>
            <select id="qa_size" class="form-select" onchange="toggleCustomUnit(this.value)">
              <option value="Meter" selected>Meter (Per Meter)</option>
              <option value="Roll">Roll</option>
              <option value="Piece">Piece</option>
              <option value="Yard">Yard</option>
              <option value="Standard">Standard</option>
              <option value="Custom">Custom Unit...</option>
            </select>
            <input type="text" id="qa_custom_size" class="form-control mt-1" placeholder="Enter unit (e.g. 50m Roll)" style="display:none">
          </div>
        </div>

        <div class="form-row cols-3">
          <div class="form-group">
            <label class="form-label">Purchase Price (₹) <span class="required">*</span></label>
            <input type="number" id="qa_purchase_price" class="form-control" step="0.01" min="0" placeholder="0.00" required>
            <small class="text-muted">Supplier cost excl. GST</small>
          </div>
          <div class="form-group">
            <label class="form-label">GST %</label>
            <input type="number" id="qa_gst_pct" class="form-control" step="0.01" min="0" value="<?= getSetting('purchase_gst_pct', '5') ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label">Markup %</label>
            <input type="number" id="qa_markup_pct" class="form-control" step="0.01" min="0" value="<?= getSetting('default_profit_markup_pct', '40') ?>" required>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" onclick="closeModal('quickAddModal')">Cancel</button>
        <button type="submit" class="btn btn-success" id="qa_submit_btn">
          <i class="fas fa-check"></i> Create &amp; Add Material
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// ==============================================================================
// MODAL CONTROLS & UTILITIES (Defined first to guarantee availability)
// ==============================================================================
if (typeof escapeHtml !== 'function') {
  window.escapeHtml = function(str) {
    if (!str && str !== 0) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  };
}

window.openModal = function(id) {
  const overlay = document.getElementById(id);
  if (overlay) {
    overlay.classList.add('active');
    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
    setTimeout(function() {
      const first = overlay.querySelector('input:not([type=hidden]), select, textarea');
      if (first) first.focus();
    }, 100);
  }
};

window.closeModal = function(id) {
  const overlay = document.getElementById(id);
  if (overlay) {
    overlay.classList.remove('active');
    overlay.style.display = 'none';
    document.body.style.overflow = '';
  }
};

let currentDynamicCat = 'cloth';

window.openAddMaterialModal = function(category = 'cloth') {
  switchMaterialCategory(category);
  const overlay = document.getElementById('addMaterialModal');
  if (overlay) {
    overlay.classList.add('active');
    overlay.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }
  updateDynamicMaterialPreview();
};

window.closeAddMaterialModal = function() {
  const overlay = document.getElementById('addMaterialModal');
  if (overlay) {
    overlay.classList.remove('active');
    overlay.style.display = 'none';
    document.body.style.overflow = '';
  }
};

// Close modals when clicking on dark backdrop overlay
document.addEventListener('click', function(e) {
  if (e.target && e.target.id === 'addMaterialModal') {
    closeAddMaterialModal();
  }
  if (e.target && e.target.id === 'quickAddModal') {
    closeModal('quickAddModal');
  }
});

// ==============================================================================
// DYNAMIC RAW MATERIAL MODAL (Cloth, Buttons, Zipper, Waist Hook, Trims)
// ==============================================================================
function switchMaterialCategory(cat) {
  currentDynamicCat = cat;
  const categories = ['cloth', 'buttons', 'collar_canvas', 'waist_canvas', 'zipper', 'hook', 'other'];

  // Switch tabs
  categories.forEach(c => {
    const tab = document.getElementById('tab_' + c);
    if (tab) tab.classList.toggle('active', c === cat);
    const sec = document.getElementById('dm_sec_' + c);
    if (sec) sec.style.display = (c === cat) ? 'block' : 'none';
  });

  // Auto-adjust garment group suggestion
  const gGrp = document.getElementById('dm_garment_category');
  if (gGrp) {
    if (cat === 'collar_canvas') {
      gGrp.value = 'Shirt';
    } else if (cat === 'waist_canvas' || cat === 'zipper' || cat === 'hook') {
      gGrp.value = 'Pant / Trouser';
    } else if (cat === 'buttons') {
      const bType = document.getElementById('dm_button_type')?.value || '';
      if (bType.toLowerCase().includes('shirt')) gGrp.value = 'Shirt';
      else if (bType.toLowerCase().includes('trouser') || bType.toLowerCase().includes('pant')) gGrp.value = 'Pant / Trouser';
      else gGrp.value = 'General';
    } else {
      gGrp.value = 'General';
    }
  }

  updateDynamicMaterialPreview();
}

// Button packet calculation
function calcButtonPacketQty() {
  const pkts = parseInt(document.getElementById('dm_btn_packets')?.value) || 0;
  const perPkt = parseInt(document.getElementById('dm_btn_per_pkt')?.value) || 200;
  const totalPieces = pkts * perPkt;
  const pktPrice = parseFloat(document.getElementById('dm_btn_price_pkt')?.value) || 0;
  const unitPrice = totalPieces > 0 ? (pktPrice / perPkt) : 0;

  const qtyInp = document.getElementById('dm_button_qty');
  const priceInp = document.getElementById('dm_button_price');
  if (qtyInp) qtyInp.value = totalPieces;
  if (priceInp) priceInp.value = unitPrice.toFixed(2);

  const sumEl = document.getElementById('btn_calc_summary');
  if (sumEl) sumEl.textContent = `${pkts} Packets × ${perPkt} = ${totalPieces.toLocaleString()} Pieces`;
  const rateEl = document.getElementById('btn_calc_unit_rate');
  if (rateEl) rateEl.textContent = `Effective: ₹${unitPrice.toFixed(2)} / piece`;

  updateDynamicMaterialPreview();
}

// Collar Canvas packet calculation
function calcCollarCanvasQty() {
  const pkts = parseInt(document.getElementById('dm_cc_packets')?.value) || 0;
  const perPkt = parseInt(document.getElementById('dm_cc_per_pkt')?.value) || 15;
  const totalPieces = pkts * perPkt;
  const pktPrice = parseFloat(document.getElementById('dm_cc_price_pkt')?.value) || 0;
  const unitPrice = perPkt > 0 ? (pktPrice / perPkt) : 0;

  const sumEl = document.getElementById('cc_calc_summary');
  if (sumEl) sumEl.textContent = `${pkts} Packets × ${perPkt} = ${totalPieces.toLocaleString()} Pieces`;
  const rateEl = document.getElementById('cc_calc_unit_rate');
  if (rateEl) rateEl.textContent = `Effective: ₹${unitPrice.toFixed(2)} / piece`;

  updateDynamicMaterialPreview();
}

// Waist Canvas roll calculation
function calcWaistCanvasQty() {
  const rolls = parseInt(document.getElementById('dm_wc_rolls')?.value) || 0;
  const perRoll = parseFloat(document.getElementById('dm_wc_per_roll')?.value) || 30;
  const totalMeters = rolls * perRoll;
  const rollPrice = parseFloat(document.getElementById('dm_wc_price_roll')?.value) || 0;
  const meterPrice = perRoll > 0 ? (rollPrice / perRoll) : 0;

  const sumEl = document.getElementById('wc_calc_summary');
  if (sumEl) sumEl.textContent = `${rolls} Rolls × ${perRoll}m = ${totalMeters.toLocaleString()} Meters`;
  const rateEl = document.getElementById('wc_calc_unit_rate');
  if (rateEl) rateEl.textContent = `Effective: ₹${meterPrice.toFixed(2)} / meter`;

  updateDynamicMaterialPreview();
}

// Zipper packet calculation
function calcZipPacketQty() {
  const pkts = parseInt(document.getElementById('dm_zip_packets')?.value) || 0;
  const perPkt = parseInt(document.getElementById('dm_zip_per_pkt')?.value) || 100;
  const totalPieces = pkts * perPkt;
  const pktPrice = parseFloat(document.getElementById('dm_zip_price_pkt')?.value) || 0;
  const unitPrice = perPkt > 0 ? (pktPrice / perPkt) : 0;

  const qtyInp = document.getElementById('dm_zip_qty');
  const priceInp = document.getElementById('dm_zip_price');
  if (qtyInp) qtyInp.value = totalPieces;
  if (priceInp) priceInp.value = unitPrice.toFixed(2);

  const sumEl = document.getElementById('zip_calc_summary');
  if (sumEl) sumEl.textContent = `${pkts} Packets × ${perPkt} = ${totalPieces.toLocaleString()} Pieces`;
  const rateEl = document.getElementById('zip_calc_unit_rate');
  if (rateEl) rateEl.textContent = `Effective: ₹${unitPrice.toFixed(2)} / piece`;

  updateDynamicMaterialPreview();
}

// Hook packet calculation
function calcHookPacketQty() {
  const pkts = parseInt(document.getElementById('dm_hook_packets')?.value) || 0;
  const perPkt = parseInt(document.getElementById('dm_hook_per_pkt')?.value) || 200;
  const totalPieces = pkts * perPkt;
  const pktPrice = parseFloat(document.getElementById('dm_hook_price_pkt')?.value) || 0;
  const unitPrice = perPkt > 0 ? (pktPrice / perPkt) : 0;

  const qtyInp = document.getElementById('dm_hook_qty');
  const priceInp = document.getElementById('dm_hook_price');
  if (qtyInp) qtyInp.value = totalPieces;
  if (priceInp) priceInp.value = unitPrice.toFixed(2);

  const sumEl = document.getElementById('hook_calc_summary');
  if (sumEl) sumEl.textContent = `${pkts} Packets × ${perPkt} = ${totalPieces.toLocaleString()} Sets`;
  const rateEl = document.getElementById('hook_calc_unit_rate');
  if (rateEl) rateEl.textContent = `Effective: ₹${unitPrice.toFixed(2)} / set`;

  updateDynamicMaterialPreview();
}

// Collar Canvas pills
function setCcType(val, el) {
  const inp = document.getElementById('dm_cc_type');
  if (inp) inp.value = val;
  if (el && el.parentElement) {
    el.parentElement.querySelectorAll('.mat-pill').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
  }
  updateDynamicMaterialPreview();
}

function setCcGrade(val, el) {
  const inp = document.getElementById('dm_cc_grade');
  if (inp) inp.value = val;
  if (el && el.parentElement) {
    el.parentElement.querySelectorAll('.mat-pill').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
  }
  updateDynamicMaterialPreview();
}

// Waist Canvas pills
function setWcType(val, el) {
  const inp = document.getElementById('dm_wc_type');
  if (inp) inp.value = val;
  if (el && el.parentElement) {
    el.parentElement.querySelectorAll('.mat-pill').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
  }
  updateDynamicMaterialPreview();
}

// Cloth quick pills
function setClothType(type, el) {
  const inp = document.getElementById('dm_cloth_type');
  if (inp) inp.value = type;
  if (el && el.parentElement) {
    el.parentElement.querySelectorAll('.mat-pill').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
  }
  updateDynamicMaterialPreview();
}

function setClothColor(color, el) {
  const inp = document.getElementById('dm_cloth_color');
  if (inp) inp.value = color;
  if (el && el.parentElement) {
    el.parentElement.querySelectorAll('.color-swatch-pill').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
  }
  updateDynamicMaterialPreview();
}

// Button quick pills
function setButtonType(type, el) {
  const inp = document.getElementById('dm_button_type');
  if (inp) inp.value = type;
  if (el && el.parentElement) {
    el.parentElement.querySelectorAll('.mat-pill').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
  }
  const gGrp = document.getElementById('dm_garment_category');
  if (gGrp) {
    if (type.toLowerCase().includes('shirt')) gGrp.value = 'Shirt';
    else if (type.toLowerCase().includes('trouser') || type.toLowerCase().includes('pant')) gGrp.value = 'Pant / Trouser';
  }
  updateDynamicMaterialPreview();
}

function setButtonColor(color, el) {
  const inp = document.getElementById('dm_button_color');
  if (inp) inp.value = color;
  if (el && el.parentElement) {
    el.parentElement.querySelectorAll('.mat-pill').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
  }
  updateDynamicMaterialPreview();
}

// Zipper quick pills
function setZipType(type, el) {
  const inp = document.getElementById('dm_zip_type');
  if (inp) inp.value = type;
  if (el && el.parentElement) {
    el.parentElement.querySelectorAll('.mat-pill').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
  }
  updateDynamicMaterialPreview();
}

function setZipColor(color, el) {
  const inp = document.getElementById('dm_zip_color');
  if (inp) inp.value = color;
  if (el && el.parentElement) {
    el.parentElement.querySelectorAll('.mat-pill').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
  }
  updateDynamicMaterialPreview();
}

// Hook quick pills
function setHookType(type, el) {
  const inp = document.getElementById('dm_hook_type');
  if (inp) inp.value = type;
  if (el && el.parentElement) {
    el.parentElement.querySelectorAll('.mat-pill').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
  }
  updateDynamicMaterialPreview();
}

function setHookFinish(finish, el) {
  const inp = document.getElementById('dm_hook_finish');
  if (inp) inp.value = finish;
  if (el && el.parentElement) {
    el.parentElement.querySelectorAll('.mat-pill').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
  }
  updateDynamicMaterialPreview();
}

// Other trims quick pills
function setOtherName(name, el) {
  const inp = document.getElementById('dm_other_name');
  if (inp) inp.value = name;
  if (el && el.parentElement) {
    el.parentElement.querySelectorAll('.mat-pill').forEach(p => p.classList.remove('active'));
    el.classList.add('active');
  }
  const uSelect = document.getElementById('dm_other_unit');
  if (uSelect) {
    if (name.toLowerCase().includes('spool') || name.toLowerCase().includes('thread')) uSelect.value = 'Spool';
    else if (name.toLowerCase().includes('elastic') || name.toLowerCase().includes('band')) uSelect.value = 'Meter';
    else if (name.toLowerCase().includes('label') || name.toLowerCase().includes('tag')) uSelect.value = 'Pieces';
  }
  updateDynamicMaterialPreview();
}

// Real-time Preview & Cost Calculation
function updateDynamicMaterialPreview() {
  const cat = currentDynamicCat;
  let name = '', unit = '', qty = 0, price = 0, spec = '', skuCode = 'MAT';
  const gstPct = parseFloat(document.getElementById('dm_gst_pct')?.value) || 5;

  if (cat === 'cloth') {
    const type = document.getElementById('dm_cloth_type')?.value.trim() || 'Cotton';
    const color = document.getElementById('dm_cloth_color')?.value.trim() || '';
    const pattern = document.getElementById('dm_cloth_pattern')?.value.trim() || 'Plain / Solid';
    qty = parseFloat(document.getElementById('dm_cloth_qty')?.value) || 0;
    price = parseFloat(document.getElementById('dm_cloth_price')?.value) || 0;
    unit = 'Meter';

    const patStr = (pattern && pattern !== 'Plain / Solid') ? ' (' + pattern + ')' : '';
    name = (color ? color + ' ' : '') + type + patStr + ' Fabric';
    const cleanCol = (color || 'CLOTH').replace(/[^a-zA-Z0-9]/g, '').substring(0, 5).toUpperCase();
    skuCode = 'MAT-CLOTH-' + cleanCol;
  } else if (cat === 'buttons') {
    const type = document.getElementById('dm_button_type')?.value.trim() || 'Buttons';
    const color = document.getElementById('dm_button_color')?.value.trim() || '';
    const size = document.getElementById('dm_button_size')?.value.trim() || '';
    qty = parseInt(document.getElementById('dm_button_qty')?.value) || 0;
    price = parseFloat(document.getElementById('dm_button_price')?.value) || 0;
    unit = 'Pcs';

    const shortSize = size.split(' ')[0] || '';
    name = (shortSize ? shortSize + ' ' : '') + (color ? color + ' ' : '') + type;
    const cleanSz = shortSize.replace(/[^a-zA-Z0-9]/g, '') || 'PCS';
    skuCode = 'MAT-BTN-' + cleanSz;
  } else if (cat === 'collar_canvas') {
    const type = document.getElementById('dm_cc_type')?.value.trim() || 'Shirt Collar Canvas';
    const grade = document.getElementById('dm_cc_grade')?.value.trim() || 'Grade-A';
    const pkts = parseInt(document.getElementById('dm_cc_packets')?.value) || 0;
    const perPkt = parseInt(document.getElementById('dm_cc_per_pkt')?.value) || 15;
    const pktPrice = parseFloat(document.getElementById('dm_cc_price_pkt')?.value) || 0;
    qty = pkts * perPkt;
    price = perPkt > 0 ? (pktPrice / perPkt) : 0;
    unit = 'Pcs';
    name = type + (grade ? ' (' + grade + ')' : '');
    skuCode = 'MAT-CANVAS-COL';
  } else if (cat === 'waist_canvas') {
    const type = document.getElementById('dm_wc_type')?.value.trim() || 'Pant Waist Canvas (Roll)';
    const width = document.getElementById('dm_wc_width')?.value.trim() || '';
    const rolls = parseInt(document.getElementById('dm_wc_rolls')?.value) || 0;
    const perRoll = parseFloat(document.getElementById('dm_wc_per_roll')?.value) || 30;
    const rollPrice = parseFloat(document.getElementById('dm_wc_price_roll')?.value) || 0;
    qty = rolls * perRoll;
    price = perRoll > 0 ? (rollPrice / perRoll) : 0;
    unit = 'Meter';
    name = type;
    skuCode = 'MAT-CANVAS-WST';
  } else if (cat === 'zipper') {
    const type = document.getElementById('dm_zip_type')?.value.trim() || 'Zip';
    const length = document.getElementById('dm_zip_length')?.value.trim() || '7 Inch';
    const color = document.getElementById('dm_zip_color')?.value.trim() || '';
    qty = parseInt(document.getElementById('dm_zip_qty')?.value) || 0;
    price = parseFloat(document.getElementById('dm_zip_price')?.value) || 0;
    unit = 'Pcs';

    const shortLen = length.split(' ')[0] || '';
    name = (shortLen ? shortLen + ' ' : '') + (color ? color + ' ' : '') + type;
    skuCode = 'MAT-ZIP-' + (shortLen.replace(/[^a-zA-Z0-9]/g, '') || '7IN');
  } else if (cat === 'hook') {
    const type = document.getElementById('dm_hook_type')?.value.trim() || 'Waist Hook';
    const finish = document.getElementById('dm_hook_finish')?.value.trim() || '';
    qty = parseInt(document.getElementById('dm_hook_qty')?.value) || 0;
    price = parseFloat(document.getElementById('dm_hook_price')?.value) || 0;
    unit = 'Sets';

    const shortFinish = finish.split(' ')[0] || '';
    name = (shortFinish ? shortFinish + ' ' : '') + type;
    skuCode = 'MAT-HOOK-BAR';
  } else {
    name = document.getElementById('dm_other_name')?.value.trim() || 'Custom Trim Material';
    unit = document.getElementById('dm_other_unit')?.value || 'Meter';
    qty = parseFloat(document.getElementById('dm_other_qty')?.value) || 0;
    price = parseFloat(document.getElementById('dm_other_price')?.value) || 0;
    const cleanName = name.replace(/[^a-zA-Z0-9]/g, '').substring(0, 5).toUpperCase();
    skuCode = 'MAT-TRIM-' + (cleanName || 'GEN');
  }

  const subtotal = Math.round(qty * price * 100) / 100;
  const gstAmount = Math.round(subtotal * (gstPct / 100) * 100) / 100;
  const total = Math.round((subtotal + gstAmount) * 100) / 100;

  const pNameEl = document.getElementById('dm_preview_name');
  const pSkuEl  = document.getElementById('dm_preview_sku');
  const pUnitEl = document.getElementById('dm_preview_unit');
  const pCalcEl = document.getElementById('dm_preview_calc');
  const pTotEl  = document.getElementById('dm_preview_total');
  const pGstEl  = document.getElementById('dm_preview_gst');

  if (pNameEl) pNameEl.textContent = name;
  if (pSkuEl)  pSkuEl.textContent  = skuCode;
  if (pUnitEl) pUnitEl.textContent = unit;
  if (pCalcEl) pCalcEl.textContent = `${qty.toLocaleString()} ${unit} × ₹${price.toFixed(2)}`;
  if (pTotEl)  pTotEl.textContent  = '₹' + total.toFixed(2);
  if (pGstEl)  pGstEl.textContent  = `GST: ₹${gstAmount.toFixed(2)} (${gstPct}%)`;
}

// Submit Dynamic Material to Consignment Table
function submitDynamicMaterial() {
  const cat = currentDynamicCat;
  let name = '', unit = '', qty = 0, price = 0, spec = '', codeSuffix = '';
  const gstPct = parseFloat(document.getElementById('dm_gst_pct')?.value) || 5;
  let garmentCat = document.getElementById('dm_garment_category')?.value || 'General';

  if (cat === 'cloth') {
    const type = document.getElementById('dm_cloth_type')?.value.trim() || 'Cotton';
    const color = document.getElementById('dm_cloth_color')?.value.trim() || '';
    const pattern = document.getElementById('dm_cloth_pattern')?.value.trim() || 'Plain / Solid';
    qty = parseFloat(document.getElementById('dm_cloth_qty')?.value) || 0;
    price = parseFloat(document.getElementById('dm_cloth_price')?.value) || 0;
    unit = 'Meter';

    const patStr = (pattern && pattern !== 'Plain / Solid') ? ' (' + pattern + ')' : '';
    name = (color ? color + ' ' : '') + type + patStr + ' Fabric';
    spec = (color ? 'Color: ' + color : '') + (pattern && pattern !== 'Plain / Solid' ? ', Weave: ' + pattern : '');
    const cleanCol = (color || 'CLOTH').replace(/[^a-zA-Z0-9]/g, '').substring(0, 4).toUpperCase();
    codeSuffix = 'CLOTH-' + cleanCol;
  } else if (cat === 'buttons') {
    const type = document.getElementById('dm_button_type')?.value.trim() || 'Buttons';
    const color = document.getElementById('dm_button_color')?.value.trim() || '';
    const size = document.getElementById('dm_button_size')?.value.trim() || '';
    const pkts = parseInt(document.getElementById('dm_btn_packets')?.value) || 0;
    const perPkt = parseInt(document.getElementById('dm_btn_per_pkt')?.value) || 200;
    qty = parseInt(document.getElementById('dm_button_qty')?.value) || 0;
    price = parseFloat(document.getElementById('dm_button_price')?.value) || 0;
    unit = 'Pcs';

    const shortSize = size.split(' ')[0] || '';
    name = (shortSize ? shortSize + ' ' : '') + (color ? color + ' ' : '') + type;
    const pktInfo = pkts > 0 ? `${pkts} pkts @ ${perPkt}/pkt` : '';
    spec = (color ? 'Color: ' + color : '') + (pktInfo ? ' (' + pktInfo + ')' : '');
    const cleanSz = shortSize.replace(/[^a-zA-Z0-9]/g, '') || 'PCS';
    codeSuffix = 'BTN-' + cleanSz;
    if (garmentCat === 'General' && type.toLowerCase().includes('shirt')) garmentCat = 'Shirt';
    if (garmentCat === 'General' && (type.toLowerCase().includes('pant') || type.toLowerCase().includes('trouser'))) garmentCat = 'Pant / Trouser';
  } else if (cat === 'collar_canvas') {
    const type = document.getElementById('dm_cc_type')?.value.trim() || 'Shirt Collar Canvas (Pre-cut)';
    const grade = document.getElementById('dm_cc_grade')?.value.trim() || 'Grade-A';
    const pkts = parseInt(document.getElementById('dm_cc_packets')?.value) || 0;
    const perPkt = parseInt(document.getElementById('dm_cc_per_pkt')?.value) || 15;
    const pktPrice = parseFloat(document.getElementById('dm_cc_price_pkt')?.value) || 0;
    qty = pkts * perPkt;
    price = perPkt > 0 ? (pktPrice / perPkt) : 0;
    unit = 'Pcs';
    name = type;
    spec = `${pkts} pkts @ ${perPkt} pcs/pkt (~15/pkt) for Shirt Collar`;
    codeSuffix = 'CANVAS-COL';
    garmentCat = 'Shirt';
  } else if (cat === 'waist_canvas') {
    const type = document.getElementById('dm_wc_type')?.value.trim() || 'Pant Waist Canvas (Roll)';
    const width = document.getElementById('dm_wc_width')?.value.trim() || '1.5 Inch';
    const rolls = parseInt(document.getElementById('dm_wc_rolls')?.value) || 0;
    const perRoll = parseFloat(document.getElementById('dm_wc_per_roll')?.value) || 30;
    const rollPrice = parseFloat(document.getElementById('dm_wc_price_roll')?.value) || 0;
    qty = rolls * perRoll;
    price = perRoll > 0 ? (rollPrice / perRoll) : 0;
    unit = 'Meter';
    name = type;
    spec = `${rolls} rolls @ ${perRoll}m/roll (${width}) for Pant Waistband`;
    codeSuffix = 'CANVAS-WST';
    garmentCat = 'Pant / Trouser';
  } else if (cat === 'zipper') {
    const type = document.getElementById('dm_zip_type')?.value.trim() || 'Zip';
    const length = document.getElementById('dm_zip_length')?.value.trim() || '7 Inch';
    const color = document.getElementById('dm_zip_color')?.value.trim() || '';
    const pkts = parseInt(document.getElementById('dm_zip_packets')?.value) || 0;
    const perPkt = parseInt(document.getElementById('dm_zip_per_pkt')?.value) || 100;
    qty = parseInt(document.getElementById('dm_zip_qty')?.value) || 0;
    price = parseFloat(document.getElementById('dm_zip_price')?.value) || 0;
    unit = 'Pcs';

    const shortLen = length.split(' ')[0] || '';
    name = (shortLen ? shortLen + ' ' : '') + (color ? color + ' ' : '') + type;
    const pktInfo = pkts > 0 ? `${pkts} pkts @ ${perPkt}/pkt` : '';
    spec = (length ? 'Length: ' + length : '') + (pktInfo ? ' (' + pktInfo + ')' : '');
    codeSuffix = 'ZIP-' + (shortLen.replace(/[^a-zA-Z0-9]/g, '') || '7IN');
    if (garmentCat === 'General') garmentCat = 'Pant / Trouser';
  } else if (cat === 'hook') {
    const type = document.getElementById('dm_hook_type')?.value.trim() || 'Waist Hook';
    const finish = document.getElementById('dm_hook_finish')?.value.trim() || '';
    const pkts = parseInt(document.getElementById('dm_hook_packets')?.value) || 0;
    const perPkt = parseInt(document.getElementById('dm_hook_per_pkt')?.value) || 200;
    qty = parseInt(document.getElementById('dm_hook_qty')?.value) || 0;
    price = parseFloat(document.getElementById('dm_hook_price')?.value) || 0;
    unit = 'Sets';

    const shortFinish = finish.split(' ')[0] || '';
    name = (shortFinish ? shortFinish + ' ' : '') + type;
    const pktInfo = pkts > 0 ? `${pkts} pkts @ ${perPkt}/pkt` : '';
    spec = (finish ? 'Finish: ' + finish : 'Waist Hook Set') + (pktInfo ? ' (' + pktInfo + ')' : '');
    codeSuffix = 'HOOK-BAR';
    if (garmentCat === 'General') garmentCat = 'Pant / Trouser';
  } else {
    name = document.getElementById('dm_other_name')?.value.trim() || 'Custom Trim Material';
    unit = document.getElementById('dm_other_unit')?.value || 'Meter';
    qty = parseFloat(document.getElementById('dm_other_qty')?.value) || 0;
    price = parseFloat(document.getElementById('dm_other_price')?.value) || 0;
    spec = 'Trim';
    const cleanName = name.replace(/[^a-zA-Z0-9]/g, '').substring(0, 4).toUpperCase();
    codeSuffix = 'TRIM-' + (cleanName || 'GEN');
  }

  if (!name) {
    showToast('Please enter a valid material name / type.', 'danger');
    return;
  }
  if (qty <= 0) {
    showToast('Please enter a quantity greater than 0.', 'danger');
    return;
  }

  const generatedSku = 'MAT-' + codeSuffix + '-' + Math.floor(Math.random() * 89 + 10);

  addPurchaseRow({
    id: 0,
    is_manual: true,
    manual_name: name,
    manual_unit: unit,
    garment_category: garmentCat,
    sku: generatedSku,
    name: name,
    size: unit,
    category_name: (cat === 'cloth' ? 'Fabrics & Rolls' : 'Trims & Accessories'),
    subcategory_name: (cat === 'cloth' ? 'Tailoring Cloth' : cat.charAt(0).toUpperCase() + cat.slice(1)),
    current_stock: 0,
    qty: qty,
    purchase_price: price,
    purchase_gst_pct: gstPct,
    custom_spec: spec
  });

  closeAddMaterialModal();
  showToast(`Added ${name} (${qty} ${unit}) to consignment table!`, 'success');
}

// ==============================================================================
// CONSIGNMENT TABLE MANAGEMENT
// ==============================================================================
let pCount = 0;

function addPurchaseRow(p) {
  if (!p) return;

  const emptyRow = document.getElementById('emptyRow');
  if (emptyRow) emptyRow.remove();

  const customSpec = (p.custom_spec || '').trim();
  const isManual = p.is_manual || (p.id <= 0 && p.manual_name);
  const manualName = p.manual_name || (isManual ? p.name : '');
  const manualUnit = p.manual_unit || p.size || 'Meter';
  const garmentCat = p.garment_category || 'Pant / Trouser';

  // Check if this material + custom spec is already in the table
  const existingRow = Array.from(document.querySelectorAll('#purchaseBody tr[id^="pr-"]')).find(tr => {
    const hiddenId = tr.querySelector('[name*="[variant_id]"]');
    const manualInput = tr.querySelector('[name*="[manual_name]"]');
    const specHidden = tr.querySelector('[name*="[custom_spec]"]');
    const existingSpec = (specHidden?.value || '').trim();
    if (isManual) {
      return manualInput && manualInput.value.toLowerCase() === manualName.toLowerCase() && existingSpec === customSpec;
    }
    return hiddenId && parseInt(hiddenId.value) === parseInt(p.id) && existingSpec === customSpec;
  });

  if (existingRow) {
    const qtyInput = existingRow.querySelector('[name*="[qty]"]');
    if (qtyInput) {
      const increment = p.qty ? parseInt(p.qty) : 1;
      qtyInput.value = parseInt(qtyInput.value || 0) + increment;
      const idx = existingRow.id.replace('pr-', '');
      calcPurchaseRow(idx);
      qtyInput.focus();
      qtyInput.select();
    }
    existingRow.classList.remove('consignment-row-highlight');
    void existingRow.offsetWidth; // trigger reflow
    existingRow.classList.add('consignment-row-highlight');
    showToast('Updated quantity for ' + p.name + (customSpec ? ' (' + customSpec + ')' : ''), 'info');

    // Clear search and dismiss dropdown if present
    if (searchDropdown) {
      searchDropdown.style.display = 'none';
      searchDropdown.innerHTML = '';
    }
    if (searchInput) searchInput.value = '';
    return;
  }

  pCount++;
  const idx = pCount;
  const defGst = p.purchase_gst_pct !== undefined ? p.purchase_gst_pct : '<?= getSetting('purchase_gst_pct', '5') ?>';
  const initialPrice = p.purchase_price !== undefined ? parseFloat(p.purchase_price).toFixed(2) : '0.00';
  const initialQty = p.qty ? parseInt(p.qty) : 1;
  const unitLabel = manualUnit;
  const catText = (p.category_name ? p.category_name + (p.subcategory_name ? ' › ' + p.subcategory_name : '') : (isManual ? (garmentCat + ' Materials') : 'Fabrics'));
  const typeText = p.brand ? p.brand : (p.material_type ? p.material_type : (isManual ? 'Manual Custom' : ''));
  const stockCount = p.current_stock !== undefined ? parseInt(p.current_stock) : 0;
  const stockClass = stockCount <= 0 ? 'out' : '';

  const tr = document.createElement('tr');
  tr.id = 'pr-' + idx;
  tr.innerHTML = `
    <td>
      <div style="font-weight:600;color:#0f172a;font-size:0.92rem">${escapeHtml(p.name)}</div>
      <div style="display:flex;gap:6px;align-items:center;margin-top:3px;flex-wrap:wrap">
        <span class="sku-badge" style="font-size:0.72rem">${escapeHtml(p.sku || 'NEW-MAT')}</span>
        <span class="badge badge-info" style="font-size:0.72rem">${escapeHtml(unitLabel)}</span>
        ${isManual ? `<span class="badge" style="font-size:0.72rem;background:#fef3c7;color:#92400e;border:1px solid #fde68a"><i class="fas fa-magic"></i> Manual Added</span>` : ''}
        ${customSpec ? `<span class="badge" style="font-size:0.73rem;font-weight:600;background:#e0f2fe;color:#0369a1;border:1px solid #bae6fd"><i class="fas fa-tag"></i> ${escapeHtml(customSpec)}</span>` : ''}
      </div>
      <input type="hidden" name="items[${idx}][variant_id]" value="${p.id || 0}" id="pvid-${idx}">
      <input type="hidden" name="items[${idx}][manual_name]" value="${escapeHtml(manualName)}">
      <input type="hidden" name="items[${idx}][manual_unit]" value="${escapeHtml(manualUnit)}">
      <input type="hidden" name="items[${idx}][garment_category]" value="${escapeHtml(garmentCat)}">
      <input type="hidden" name="items[${idx}][custom_spec]" value="${escapeHtml(customSpec)}">
    </td>
    <td>
      <div style="font-size:0.8rem;color:#475569;font-weight:500">${escapeHtml(catText)}</div>
      ${typeText ? `<div style="font-size:0.72rem;color:#7c3aed;margin-top:2px"><i class="fas fa-tag"></i> ${escapeHtml(typeText)}</div>` : ''}
    </td>
    <td class="text-center">
      <span class="stock-avail-chip ${stockClass}">
        [ ${stockCount} available ]
      </span>
    </td>
    <td class="text-center">
      <input type="number" name="items[${idx}][qty]" class="form-control text-center" min="1" value="${initialQty}" oninput="calcPurchaseRow(${idx})" required style="font-weight:600">
    </td>
    <td class="text-right">
      <input type="number" name="items[${idx}][purchase_price]" class="form-control text-right" step="0.01" min="0" value="${initialPrice}" oninput="calcPurchaseRow(${idx})" placeholder="0.00" required>
    </td>
    <td class="text-right">
      <input type="number" name="items[${idx}][purchase_gst_pct]" class="form-control text-right" step="0.01" min="0" value="${defGst}" oninput="calcPurchaseRow(${idx})" required>
    </td>
    <td class="text-right text-muted" id="pgstamt-${idx}" style="font-size:0.85rem">₹0.00</td>
    <td class="text-right price-cell" id="ppwg-${idx}" style="font-size:0.85rem">₹0.00</td>
    <td class="text-right total-cell" id="ptotal-${idx}" style="font-size:0.95rem;font-weight:700">₹0.00</td>
    <td class="text-center">
      <button type="button" class="btn btn-xs btn-outline-danger" onclick="removePRow(${idx})" title="Remove material">
        <i class="fas fa-trash"></i>
      </button>
    </td>
  `;

  document.getElementById('purchaseBody').appendChild(tr);

  // Compute this row's calculations immediately
  calcPurchaseRow(idx);

  // Clear search bar and dropdown completely if present
  if (searchDropdown) {
    searchDropdown.style.display = 'none';
    searchDropdown.innerHTML = '';
  }
  currentMaterialSearchResults = [];
  if (searchInput) searchInput.value = '';

  // Focus quantity field of the newly added row
  const qtyInput = tr.querySelector('[name*="[qty]"]');
  if (qtyInput) {
    qtyInput.focus();
    qtyInput.select();
  }
}

// Remove row
function removePRow(idx) {
  const tr = document.getElementById('pr-' + idx);
  if (tr) tr.remove();

  const rows = document.querySelectorAll('#purchaseBody tr[id^="pr-"]');
  if (rows.length === 0) {
    document.getElementById('purchaseBody').innerHTML = `
      <tr id="emptyRow">
        <td colspan="10" class="text-center text-muted" style="padding:48px 20px">
          <i class="fas fa-scroll" style="font-size:2.5rem;opacity:0.25;display:block;margin-bottom:12px"></i>
          <h5 style="color:#64748b;font-weight:600;margin-bottom:4px">No materials added to this consignment yet</h5>
          <p style="font-size:0.85rem;margin-bottom:0">Click <strong style="cursor:pointer;color:#059669" onclick="openAddMaterialModal()">+ Add Material</strong> above or search directory to add cloth, buttons, zips, hooks and trims.</p>
        </td>
      </tr>`;
  }
  updatePurchaseSummary();
}

// Calculate row prices & GST
function calcPurchaseRow(idx) {
  const row = document.getElementById('pr-' + idx);
  if (!row) return;

  const qtyInput   = row.querySelector('[name*="[qty]"]');
  const priceInput = row.querySelector('[name*="[purchase_price]"]');
  const gstInput   = row.querySelector('[name*="[purchase_gst_pct]"]');

  const qty   = parseInt(qtyInput?.value) || 0;
  const price = parseFloat(priceInput?.value) || 0;
  const gst   = parseFloat(gstInput?.value) || 0;

  const gstPerUnit = Math.round((price * gst / 100) * 100) / 100;
  const pwg        = Math.round((price + gstPerUnit) * 100) / 100;
  const lineGst    = Math.round((gstPerUnit * qty) * 100) / 100;
  const lineTotal  = Math.round((pwg * qty) * 100) / 100;

  const gstAmtEl = document.getElementById('pgstamt-' + idx);
  const ppwgEl   = document.getElementById('ppwg-' + idx);
  const ptEl     = document.getElementById('ptotal-' + idx);

  if (gstAmtEl) gstAmtEl.textContent = '₹' + lineGst.toFixed(2);
  if (ppwgEl)   ppwgEl.textContent   = '₹' + pwg.toFixed(2);
  if (ptEl)     ptEl.textContent     = '₹' + lineTotal.toFixed(2);

  updatePurchaseSummary();
}

// Update totals summary
function updatePurchaseSummary() {
  const rows = document.querySelectorAll('#purchaseBody tr[id^="pr-"]');
  let items = 0, totalQty = 0, subtotal = 0, totalGst = 0, grandTotal = 0;

  rows.forEach(function(r) {
    const vid = r.querySelector('[name*="[variant_id]"]')?.value;
    const manual = r.querySelector('[name*="[manual_name]"]')?.value;
    if (!vid && !manual) return;

    const qty   = parseInt(r.querySelector('[name*="[qty]"]')?.value) || 0;
    const price = parseFloat(r.querySelector('[name*="[purchase_price]"]')?.value) || 0;
    const gst   = parseFloat(r.querySelector('[name*="[purchase_gst_pct]"]')?.value) || 0;

    const gstPerUnit = Math.round((price * gst / 100) * 100) / 100;
    const pwg        = price + gstPerUnit;

    items++;
    totalQty   += qty;
    subtotal   += price * qty;
    totalGst   += gstPerUnit * qty;
    grandTotal += pwg * qty;
  });

  document.getElementById('summaryItems').textContent    = items;
  document.getElementById('summaryQty').textContent      = totalQty.toLocaleString();
  document.getElementById('summarySubtotal').textContent = '₹' + subtotal.toFixed(2);
  document.getElementById('summaryGst').textContent      = '₹' + totalGst.toFixed(2);
  document.getElementById('summaryTotal').textContent    = '₹' + grandTotal.toFixed(2);
}

// ==============================================================================
// DIRECTORY SEARCH & QUICK ADD
// ==============================================================================
let currentMaterialSearchResults = [];
let materialSearchTimer = null;

const searchInput = document.getElementById('mainMaterialSearchInput');
const searchDropdown = document.getElementById('mainMaterialDropdown');

// Focus helper
function focusMaterialSearch() {
  if (searchInput) {
    searchInput.focus();
    searchInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
  } else {
    openAddMaterialModal();
  }
}

// Search materials in real-time
function searchMainMaterial(q) {
  clearTimeout(materialSearchTimer);
  q = (q || '').trim();

  if (q.length < 1) {
    if (searchDropdown) {
      searchDropdown.style.display = 'none';
      searchDropdown.innerHTML = '';
    }
    currentMaterialSearchResults = [];
    return;
  }

  materialSearchTimer = setTimeout(() => {
    fetch(window.APP_URL + '/api/search_product.php?q=' + encodeURIComponent(q) + '&item_type=material')
      .then(res => res.json())
      .then(res => {
        if (!searchDropdown) return;
        if (!res.success || !res.data || res.data.length === 0) {
          currentMaterialSearchResults = [];
          searchDropdown.innerHTML = `
            <div style="padding:16px;text-align:center;color:#64748b;font-size:0.85rem">
              <i class="fas fa-search" style="margin-right:6px"></i> No materials matching "${escapeHtml(q)}" found in directory.
              <div style="margin-top:8px">
                <button type="button" class="btn btn-xs btn-success" onclick="openQuickAddModal('${escapeHtml(q)}')">
                  <i class="fas fa-plus"></i> + Create "${escapeHtml(q)}" Now
                </button>
              </div>
            </div>`;
          searchDropdown.style.display = 'block';
          return;
        }

        currentMaterialSearchResults = res.data;
        let html = '';
        res.data.forEach((p, idx) => {
          const catStr = (p.category_name ? p.category_name + (p.subcategory_name ? ' › ' + p.subcategory_name : '') : 'Fabrics');
          const isMaterial = (p.item_type === 'material' || p.size === 'Meter' || p.size === 'Roll');
          const typeBadge = isMaterial 
            ? '<span class="badge badge-warning" style="font-size:0.7rem;"><i class="fas fa-scroll"></i> Fabric</span>'
            : '<span class="badge badge-primary" style="font-size:0.7rem;"><i class="fas fa-tshirt"></i> Garment</span>';

          html += `
            <div class="material-search-item" data-index="${idx}">
              <div>
                <div style="font-weight:600;color:#0f172a;font-size:0.92rem">
                  ${escapeHtml(p.name)} 
                  ${p.brand ? `<span style="font-weight:400;color:#7c3aed;font-size:0.8rem">(${escapeHtml(p.brand)})</span>` : ''}
                </div>
                <div style="display:flex;gap:6px;align-items:center;margin-top:3px;flex-wrap:wrap">
                  <span class="sku-badge" style="font-size:0.72rem">${escapeHtml(p.sku)}</span>
                  ${typeBadge}
                  <span style="font-size:0.75rem;color:#64748b">${escapeHtml(catStr)}</span>
                  <span class="badge badge-light" style="font-size:0.72rem;font-weight:600">${escapeHtml(p.size || 'Meter')}</span>
                </div>
              </div>
              <div style="text-align:right;flex-shrink:0">
                <div style="font-size:0.75rem;color:#059669;font-weight:600">Stock: ${p.current_stock} ${p.size || 'Mtr'}</div>
                <div style="font-size:0.75rem;color:#64748b;margin-top:2px">Cost: <strong>₹${parseFloat(p.purchase_price || 0).toFixed(2)}</strong></div>
              </div>
            </div>`;
        });

        searchDropdown.innerHTML = html;
        searchDropdown.style.display = 'block';
      })
      .catch(err => {
        console.error('Material search error:', err);
      });
  }, 200);
}

// Click and mousedown delegation on search dropdown (safely guarded)
if (searchDropdown) {
  searchDropdown.addEventListener('mousedown', function(e) {
    const itemEl = e.target.closest('.material-search-item');
    if (!itemEl) return;
    e.preventDefault();
    const idx = parseInt(itemEl.dataset.index, 10);
    if (!isNaN(idx) && currentMaterialSearchResults[idx]) {
      addPurchaseRow(currentMaterialSearchResults[idx]);
    }
  });
}

// Close dropdown on outside click (safely guarded)
document.addEventListener('click', function(e) {
  if (searchInput && searchDropdown) {
    if (!searchInput.contains(e.target) && !searchDropdown.contains(e.target)) {
      searchDropdown.style.display = 'none';
    }
  }
});

// QUICK ADD MATERIAL MODAL LOGIC
function openQuickAddModal(suggestedName) {
  document.getElementById('quickAddForm').reset();
  if (suggestedName) {
    document.getElementById('qa_name').value = suggestedName;
  }
  document.getElementById('qa_custom_size').style.display = 'none';
  openModal('quickAddModal');
  document.getElementById('qa_name').focus();
}

function toggleCustomUnit(val) {
  const customInput = document.getElementById('qa_custom_size');
  if (val === 'Custom') {
    customInput.style.display = 'block';
    customInput.required = true;
    customInput.focus();
  } else {
    customInput.style.display = 'none';
    customInput.required = false;
  }
}

function loadQuickAddSubcategories(parentId) {
  const subSelect = document.getElementById('qa_subcategory_id');
  if (!parentId) {
    subSelect.innerHTML = '<option value="">Select Category First</option>';
    return;
  }
  subSelect.innerHTML = '<option value="">Loading...</option>';
  apiCall(window.APP_URL + '/api/get_subcategories.php', 'GET', { parent_id: parentId }).then(function(res) {
    if (res.success && res.data && res.data.length) {
      subSelect.innerHTML = '<option value="">Select Subcategory</option>' +
        res.data.map(s => `<option value="${s.id}">${escapeHtml(s.name)}</option>`).join('');
    } else {
      subSelect.innerHTML = '<option value="">No subcategories (General)</option>';
    }
  });
}

function submitQuickAddMaterial(e) {
  e.preventDefault();
  const btn = document.getElementById('qa_submit_btn');
  const originalText = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner spinner-sm"></span> Creating...';

  const unitSelect = document.getElementById('qa_size').value;
  const unitValue  = unitSelect === 'Custom' ? document.getElementById('qa_custom_size').value.trim() : unitSelect;

  const payload = {
    csrf_token: window.CSRF_TOKEN,
    name: document.getElementById('qa_name').value.trim(),
    category_id: document.getElementById('qa_category_id').value,
    subcategory_id: document.getElementById('qa_subcategory_id').value,
    material_type: document.getElementById('qa_material_type').value.trim(),
    size: unitValue || 'Meter',
    purchase_price: document.getElementById('qa_purchase_price').value,
    purchase_gst_pct: document.getElementById('qa_gst_pct').value,
    profit_markup_pct: document.getElementById('qa_markup_pct').value
  };

  fetch(window.APP_URL + '/api/quick_add_material.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(payload)
  })
  .then(res => res.json())
  .then(res => {
    btn.disabled = false;
    btn.innerHTML = originalText;
    if (res.success && res.data) {
      showToast('Material "' + res.data.name + '" created!', 'success');
      closeModal('quickAddModal');
      addPurchaseRow(res.data);
    } else {
      showToast(res.message || 'Failed to create material', 'error');
    }
  })
  .catch(err => {
    btn.disabled = false;
    btn.innerHTML = originalText;
    showToast('Network error while creating material', 'error');
  });
}

function validatePurchaseForm() {
  const rows = document.querySelectorAll('#purchaseBody tr[id^="pr-"]');
  if (rows.length === 0) {
    showToast('Please add at least one material to this stock entry', 'error');
    openAddMaterialModal();
    return false;
  }
  let hasValid = false;
  rows.forEach(function(r) {
    const vid = r.querySelector('[name*="[variant_id]"]')?.value;
    const manual = r.querySelector('[name*="[manual_name]"]')?.value;
    const qty = parseInt(r.querySelector('[name*="[qty]"]')?.value) || 0;
    if ((vid || manual) && qty > 0) hasValid = true;
  });

  if (!hasValid) {
    showToast('Please enter valid quantities for your materials', 'error');
    return false;
  }

  const btn = document.getElementById('savePurchaseBtn');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner spinner-sm"></span> Saving Stock Entry...';
  }
  return true;
}

// On page load, auto-add prefilled material or auto-open material modal if requested
document.addEventListener('DOMContentLoaded', function() {
  if (window.PREFILL_MATERIAL) {
    addPurchaseRow(window.PREFILL_MATERIAL);
  }
  const urlParams = new URLSearchParams(window.location.search);
  const openCat = urlParams.get('open_cat') || urlParams.get('open_kit');
  if (openCat) {
    if (openCat === 'pant') openAddMaterialModal('zipper');
    else if (openCat === 'shirt') openAddMaterialModal('cloth');
    else openAddMaterialModal(openCat);
  }
});
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
