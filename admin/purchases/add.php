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
                        $newVarId = db()->insert(
                            "INSERT INTO product_variants (product_id, sku, size, purchase_price, purchase_gst_pct, purchase_gst_amount, purchase_price_with_gst, current_stock, min_stock_alert, status) VALUES (?,?,?,?,?,?,?,0,5,'active')",
                            [$newProdId, $sku, $manualUnit ?: 'Meter', $purPrice, $purGstPct, round($purPrice * $purGstPct / 100, 2), round($purPrice * (1 + $purGstPct / 100), 2)]
                        );
                        $variantId = (int)$newVarId;
                        $variant   = ['id' => $variantId, 'sku' => $sku, 'product_id' => $newProdId, 'size' => $manualUnit];
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
        <span style="font-size:0.8rem;color:#64748b">Batch inward tailoring kits for Pants &amp; Shirts or add custom materials</span>
      </div>
      <div style="position:relative">
        <button type="button" class="btn btn-sm btn-success" id="addMenuBtn" onclick="toggleAddMenu(event)" style="font-weight:600;display:flex;align-items:center;gap:6px;padding:7px 16px;border-radius:6px;box-shadow:0 1px 2px rgba(0,0,0,0.05)">
          <i class="fas fa-plus"></i> Add <i class="fas fa-chevron-down" style="font-size:0.72rem;margin-left:4px"></i>
        </button>
        <div id="addDropdownMenu" style="display:none;position:absolute;right:0;top:calc(100% + 6px);background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1);min-width:270px;z-index:1050;padding:6px">
          <div style="padding:6px 12px 4px;font-size:0.7rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px">Tailoring Inward Kits</div>
          
          <a href="javascript:void(0)" onclick="openAddOption('pant')" style="display:flex;align-items:center;gap:12px;padding:9px 12px;border-radius:6px;color:#1e293b;text-decoration:none;font-size:0.88rem;font-weight:600;transition:background 0.15s" onmouseover="this.style.background='#eff6ff'" onmouseout="this.style.background='transparent'">
            <span style="width:32px;height:32px;border-radius:8px;background:#dbeafe;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0">👖</span>
            <div style="flex:1">
              <div style="color:#1e40af;line-height:1.2">+ Pant / Trouser Stock Kit</div>
              <div style="font-size:0.73rem;color:#64748b;font-weight:400;margin-top:2px">Roll, Zip, Hook, Aster, Buttons</div>
            </div>
          </a>

          <a href="javascript:void(0)" onclick="openAddOption('shirt')" style="display:flex;align-items:center;gap:12px;padding:9px 12px;border-radius:6px;color:#1e293b;text-decoration:none;font-size:0.88rem;font-weight:600;transition:background 0.15s;margin-top:2px" onmouseover="this.style.background='#f0fdfa'" onmouseout="this.style.background='transparent'">
            <span style="width:32px;height:32px;border-radius:8px;background:#cffafe;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0">👔</span>
            <div style="flex:1">
              <div style="color:#0369a1;line-height:1.2">+ Shirt Stock Kit</div>
              <div style="font-size:0.73rem;color:#64748b;font-weight:400;margin-top:2px">Color Cloth, Collar, Cuff, Buttons</div>
            </div>
          </a>

          <div style="height:1px;background:#f1f5f9;margin:6px 0"></div>

          <div style="padding:4px 12px;font-size:0.7rem;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:0.5px">Custom Material</div>

          <a href="javascript:void(0)" onclick="openAddOption('custom')" style="display:flex;align-items:center;gap:12px;padding:9px 12px;border-radius:6px;color:#1e293b;text-decoration:none;font-size:0.88rem;font-weight:600;transition:background 0.15s" onmouseover="this.style.background='#f0fdf4'" onmouseout="this.style.background='transparent'">
            <span style="width:32px;height:32px;border-radius:8px;background:#dcfce7;color:#15803d;display:flex;align-items:center;justify-content:center;font-size:0.95rem;flex-shrink:0"><i class="fas fa-magic"></i></span>
            <div style="flex:1">
              <div style="color:#15803d;line-height:1.2">Quick Add New Material</div>
              <div style="font-size:0.73rem;color:#64748b;font-weight:400;margin-top:2px">Define &amp; inward custom material</div>
            </div>
          </a>
        </div>
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
              <p style="font-size:0.85rem;margin-bottom:0">Click <strong>+ Add</strong> above to inward Pant / Trouser Stock Kit, Shirt Stock Kit, or add custom materials.</p>
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

<!-- PANT / TROUSER STOCK KIT MODAL -->
<div class="modal-overlay" id="pantKitModal">
  <div class="modal" style="max-width:780px">
    <div class="modal-header">
      <span class="modal-title"><i class="fas fa-layer-group text-primary"></i> 👖 Pant / Trouser Raw Materials Inward Kit</span>
      <button type="button" class="modal-close" onclick="closeModal('pantKitModal')">&times;</button>
    </div>
    <div class="modal-body">
      <p class="text-muted text-sm" style="margin-bottom:14px">
        Inward the complete tailoring kit required for pants &amp; trousers: <strong>Fabric Roll, Zip, Waist Hook, Pocketing Aster, and Buttons</strong>.
      </p>

      <!-- Quick Batch Multiplier -->
      <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
        <div style="display:flex;align-items:center;gap:8px">
          <i class="fas fa-calculator text-primary" style="font-size:1.1rem"></i>
          <div>
            <strong style="color:#1e3a8a;font-size:0.88rem">Batch Preset Calculator</strong>
            <div style="font-size:0.75rem;color:#3b82f6">Auto-scales required quantities based on number of trousers to manufacture</div>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:6px">
          <span style="font-size:0.82rem;font-weight:600;color:#1e293b">For:</span>
          <input type="number" id="pant_batch_units" value="50" min="1" max="10000" class="form-control text-center" style="width:75px;height:34px;font-weight:700">
          <span style="font-size:0.82rem;font-weight:600;color:#1e293b">Pants</span>
          <button type="button" class="btn btn-xs btn-primary" onclick="applyPantBatchQty()" style="height:34px;padding:0 12px">
            Calculate Qtys
          </button>
        </div>
      </div>

      <!-- Pant Materials Table -->
      <div class="table-wrapper">
        <table class="billing-table">
          <thead>
            <tr>
              <th style="min-width:210px">Pant Raw Material</th>
              <th style="width:130px" class="text-center">Available Stock</th>
              <th style="width:105px" class="text-center">Inward Qty</th>
              <th style="width:115px" class="text-right">Unit Price (₹)</th>
              <th style="width:110px" class="text-right">Est. Cost (₹)</th>
              <th style="width:36px" class="text-center"></th>
            </tr>
          </thead>
          <tbody id="pantKitTableBody">
            <!-- Populated by openPantKitModal() -->
          </tbody>
        </table>
      </div>

      <!-- Option at the end to add custom/extra material manually -->
      <div style="margin-top:14px;padding:12px 16px;background:#f8fafc;border:1.5px dashed #cbd5e1;border-radius:8px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <div>
          <div style="font-weight:600;font-size:0.88rem;color:#1e293b;display:flex;align-items:center;gap:6px">
            <i class="fas fa-plus-circle text-primary"></i> Add Custom / Extra Material Manually
          </div>
          <div style="font-size:0.75rem;color:#64748b">
            Need belt canvas, thread spools, special aster, or custom trims? Add them manually here.
          </div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="addPantManualMaterialRow()" style="font-weight:600">
          <i class="fas fa-plus"></i> + Add Material Manually
        </button>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-light" onclick="closeModal('pantKitModal')">Cancel</button>
      <button type="button" class="btn btn-success" id="btnInsertPantKit" onclick="insertPantKit()">
        <i class="fas fa-plus-circle"></i> + Add All Materials to Consignment
      </button>
    </div>
  </div>
</div>

<!-- SHIRT STOCK KIT MODAL -->
<div class="modal-overlay" id="shirtKitModal">
  <div class="modal" style="max-width:820px">
    <div class="modal-header">
      <span class="modal-title"><i class="fas fa-tshirt text-info"></i> 👔 Shirt Raw Materials Inward Kit</span>
      <button type="button" class="modal-close" onclick="closeModal('shirtKitModal')">&times;</button>
    </div>
    <div class="modal-body">
      <p class="text-muted text-sm" style="margin-bottom:14px">
        Inward shirt materials with <strong>custom fabric color (manual typing)</strong>, <strong>collar with flexible size typing (14, 17.5 / 17 and half, Kids, Security)</strong>, cuff roll, and buttons.
      </p>

      <!-- 1. Shirt Fabric Cloth & Color -->
      <div class="kit-item-box">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <div>
            <strong style="font-size:0.95rem;color:#0f172a"><i class="fas fa-scroll text-primary"></i> 1. Shirt Fabric Cloth</strong>
            <span class="sku-badge" style="margin-left:6px">MAT-SHIRT-CLOTH-01</span>
          </div>
          <div>
            <span class="stock-avail-chip" id="sk_cloth_avail_stock">[ 100 available ]</span>
          </div>
        </div>
        <div class="form-row cols-3">
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label" style="font-weight:600">Fabric Color (Manual Typing) <span class="required">*</span></label>
            <input type="text" id="sk_cloth_color" class="form-control" placeholder="Type color e.g. Sky Blue, White, Navy Blue..." value="Sky Blue" required>
            <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:6px">
              <span class="quick-pill" onclick="setShirtColor('Sky Blue')"><i class="fas fa-palette"></i> Sky Blue</span>
              <span class="quick-pill" onclick="setShirtColor('Navy Blue')">Navy Blue</span>
              <span class="quick-pill" onclick="setShirtColor('White')">White</span>
              <span class="quick-pill" onclick="setShirtColor('Khaki Security')">Khaki Security</span>
              <span class="quick-pill" onclick="setShirtColor('Black')">Black</span>
              <span class="quick-pill" onclick="setShirtColor('Cream')">Cream</span>
            </div>
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label" style="font-weight:600">Inward Meters <span class="required">*</span></label>
            <input type="number" id="sk_cloth_qty" class="form-control text-center" value="50" min="1" required style="font-weight:700">
            <small class="text-muted">Unit: Meters (Per Meter)</small>
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label" style="font-weight:600">Price / Meter (₹) <span class="required">*</span></label>
            <input type="number" id="sk_cloth_price" class="form-control text-right" value="160.00" step="0.01" min="0" required>
            <small class="text-muted">Supplier cost excl. GST</small>
          </div>
        </div>
      </div>

      <!-- 2. Shirt Collar & Size -->
      <div class="kit-item-box">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <div>
            <strong style="font-size:0.95rem;color:#0f172a"><i class="fas fa-cut text-info"></i> 2. Collar &amp; Size Option</strong>
            <span class="sku-badge" style="margin-left:6px">MAT-SHIRT-COLLAR-01</span>
          </div>
          <div>
            <span class="stock-avail-chip" id="sk_collar_avail_stock">[ 120 available ]</span>
          </div>
        </div>
        <div class="form-row cols-3">
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label" style="font-weight:600">Size Entering Option (Manual Type Text Field) <span class="required">*</span></label>
            <input type="text" id="sk_collar_size" class="form-control" placeholder="Type size e.g. 14, 17 and half, 17.5, Kids..." value="17 and half" required>
            <div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:6px">
              <span class="quick-pill" onclick="setShirtSize('14')">14</span>
              <span class="quick-pill" onclick="setShirtSize('14.5')">14.5</span>
              <span class="quick-pill" onclick="setShirtSize('15')">15</span>
              <span class="quick-pill" onclick="setShirtSize('15.5')">15.5</span>
              <span class="quick-pill" onclick="setShirtSize('16')">16</span>
              <span class="quick-pill" onclick="setShirtSize('17 and half')">17 and half</span>
              <span class="quick-pill" onclick="setShirtSize('17.5')">17.5</span>
              <span class="quick-pill" onclick="setShirtSize('Kids (26-32)')">Kids</span>
              <span class="quick-pill" onclick="setShirtSize('Security')">Security</span>
            </div>
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label" style="font-weight:600">Inward Qty (Pcs) <span class="required">*</span></label>
            <input type="number" id="sk_collar_qty" class="form-control text-center" value="30" min="1" required style="font-weight:700">
            <small class="text-muted">Unit: Pieces / Collars</small>
          </div>
          <div class="form-group" style="margin-bottom:0">
            <label class="form-label" style="font-weight:600">Price / Pc (₹) <span class="required">*</span></label>
            <input type="number" id="sk_collar_price" class="form-control text-right" value="25.00" step="0.01" min="0" required>
            <small class="text-muted">Supplier cost excl. GST</small>
          </div>
        </div>
      </div>

      <!-- 3. Cuff Roll & 4. Buttons (Side-by-side) -->
      <div class="grid-2" style="gap:12px">
        <!-- 3. Shirt Cuff Roll -->
        <div class="kit-item-box" style="margin-bottom:0">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <div>
              <strong style="font-size:0.9rem;color:#0f172a"><i class="fas fa-tape text-purple"></i> 3. Shirt Cuff Roll</strong>
            </div>
            <div>
              <span class="stock-avail-chip" id="sk_cuff_avail_stock">[ 60 available ]</span>
            </div>
          </div>
          <div class="form-row cols-2">
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label" style="font-weight:600">Meters <span class="required">*</span></label>
              <input type="number" id="sk_cuff_qty" class="form-control text-center" value="20" min="1" required style="font-weight:700">
            </div>
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label" style="font-weight:600">Price / Mtr (₹)</label>
              <input type="number" id="sk_cuff_price" class="form-control text-right" value="35.00" step="0.01" min="0" required>
            </div>
          </div>
        </div>

        <!-- 4. Shirt Buttons -->
        <div class="kit-item-box" style="margin-bottom:0">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
            <div>
              <strong style="font-size:0.9rem;color:#0f172a"><i class="fas fa-circle text-warning"></i> 4. Shirt Buttons</strong>
            </div>
            <div>
              <span class="stock-avail-chip" id="sk_btn_avail_stock">[ 1000 available ]</span>
            </div>
          </div>
          <div class="form-row cols-2">
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label" style="font-weight:600">Qty (Pcs) <span class="required">*</span></label>
              <input type="number" id="sk_btn_qty" class="form-control text-center" value="200" min="1" required style="font-weight:700">
            </div>
            <div class="form-group" style="margin-bottom:0">
              <label class="form-label" style="font-weight:600">Price / Pc (₹)</label>
              <input type="number" id="sk_btn_price" class="form-control text-right" value="1.50" step="0.01" min="0" required>
            </div>
          </div>
        </div>
      </div>

      <!-- Custom / Extra Material Manually for Shirt Kit -->
      <div id="shirtManualMaterialsContainer" style="margin-top:12px"></div>
      <div style="margin-top:14px;padding:12px 16px;background:#f8fafc;border:1.5px dashed #cbd5e1;border-radius:8px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <div>
          <div style="font-weight:600;font-size:0.88rem;color:#1e293b;display:flex;align-items:center;gap:6px">
            <i class="fas fa-plus-circle text-info"></i> Add Custom / Extra Material Manually
          </div>
          <div style="font-size:0.75rem;color:#64748b">
            Need collar stays, brand tags, sewing thread, or custom trims? Add them manually here.
          </div>
        </div>
        <button type="button" class="btn btn-sm btn-outline-info" onclick="addShirtManualMaterialRow()" style="font-weight:600">
          <i class="fas fa-plus"></i> + Add Material Manually
        </button>
      </div>

    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-light" onclick="closeModal('shirtKitModal')">Cancel</button>
      <button type="button" class="btn btn-success" id="btnInsertShirtKit" onclick="insertShirtKit()">
        <i class="fas fa-plus-circle"></i> + Add All Materials to Consignment
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
let pCount = 0;
let currentMaterialSearchResults = [];
let materialSearchTimer = null;

// Dropdown "+ Add" menu handlers
function toggleAddMenu(e) {
  if (e) e.stopPropagation();
  const menu = document.getElementById('addDropdownMenu');
  if (!menu) return;
  menu.style.display = (menu.style.display === 'block') ? 'none' : 'block';
}

function openAddOption(type) {
  const menu = document.getElementById('addDropdownMenu');
  if (menu) menu.style.display = 'none';
  if (type === 'pant') {
    openPantKitModal();
  } else if (type === 'shirt') {
    openShirtKitModal();
  } else if (type === 'custom' || type === 'quick') {
    openQuickAddModal();
  }
}

// Close add menu when clicking anywhere outside
document.addEventListener('click', function(e) {
  const menu = document.getElementById('addDropdownMenu');
  const btn = document.getElementById('addMenuBtn');
  if (menu && menu.style.display === 'block') {
    if (!menu.contains(e.target) && !btn?.contains(e.target)) {
      menu.style.display = 'none';
    }
  }
});

const searchInput = document.getElementById('mainMaterialSearchInput');
const searchDropdown = document.getElementById('mainMaterialDropdown');

// Focus helper
function focusMaterialSearch() {
  if (searchInput) {
    searchInput.focus();
    searchInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
  } else {
    toggleAddMenu();
  }
}

// Search materials in real-time
function searchMainMaterial(q) {
  clearTimeout(materialSearchTimer);
  q = (q || '').trim();

  if (q.length < 1) {
    searchDropdown.style.display = 'none';
    searchDropdown.innerHTML = '';
    currentMaterialSearchResults = [];
    return;
  }

  materialSearchTimer = setTimeout(() => {
    fetch(window.APP_URL + '/api/search_product.php?q=' + encodeURIComponent(q) + '&item_type=material')
      .then(res => res.json())
      .then(res => {
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

// Click and mousedown delegation on search dropdown
searchDropdown.addEventListener('mousedown', function(e) {
  const itemEl = e.target.closest('.material-search-item');
  if (!itemEl) return;
  e.preventDefault();
  const idx = parseInt(itemEl.dataset.index, 10);
  if (!isNaN(idx) && currentMaterialSearchResults[idx]) {
    addPurchaseRow(currentMaterialSearchResults[idx]);
  }
});

// Close dropdown on outside click
document.addEventListener('click', function(e) {
  if (!searchInput.contains(e.target) && !searchDropdown.contains(e.target)) {
    searchDropdown.style.display = 'none';
  }
});

// Add a material row to the consignment table
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

    // Clear search and dismiss dropdown
    searchDropdown.style.display = 'none';
    searchDropdown.innerHTML = '';
    searchInput.value = '';
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

  // Clear search bar and dropdown completely
  searchDropdown.style.display = 'none';
  searchDropdown.innerHTML = '';
  currentMaterialSearchResults = [];
  searchInput.value = '';

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
          <p style="font-size:0.85rem;margin-bottom:0">Click <strong>+ Pant / Trouser Stock Kit</strong>, <strong>+ Shirt Stock Kit</strong>, or type in the search bar above to inward materials.</p>
        </td>
      </tr>`;
  }
  updatePurchaseSummary();
}

// PANT / TROUSER STOCK KIT HANDLERS
function openPantKitModal() {
  const tbody = document.getElementById('pantKitTableBody');
  if (!tbody) return;

  const kitItems = (window.PANT_KIT && window.PANT_KIT.length) ? window.PANT_KIT : [
    { id: 7, sku: 'MAT-PANT-ROLL-01', name: 'Pant Fabric Roll', size: 'Meter', current_stock: 0, purchase_price: 220.00, purchase_gst_pct: 5 },
    { id: 8, sku: 'MAT-PANT-ZIP-01', name: 'Pant Zip (Zipper)', size: 'Pcs', current_stock: 0, purchase_price: 8.50, purchase_gst_pct: 5 },
    { id: 9, sku: 'MAT-PANT-HOOK-01', name: 'Pant Waist Hook', size: 'Pcs', current_stock: 0, purchase_price: 4.00, purchase_gst_pct: 5 },
    { id: 10, sku: 'MAT-PANT-POCK-01', name: 'Pocketing Fabric', size: 'Meter', current_stock: 0, purchase_price: 45.00, purchase_gst_pct: 5 },
    { id: 11, sku: 'MAT-PANT-BTN-01', name: 'Pant Buttons', size: 'Pcs', current_stock: 0, purchase_price: 2.50, purchase_gst_pct: 5 }
  ];

  const defaultQtys = {
    'MAT-PANT-ROLL-01': 60,
    'MAT-PANT-ZIP-01': 50,
    'MAT-PANT-HOOK-01': 50,
    'MAT-PANT-POCK-01': 25,
    'MAT-PANT-BTN-01': 100
  };

  let rowsHtml = '';
  kitItems.forEach((item, i) => {
    const defQty = defaultQtys[item.sku] || 50;
    const price = parseFloat(item.purchase_price || 0).toFixed(2);
    const est = (defQty * price).toFixed(2);
    const stockCount = parseInt(item.current_stock || 0);
    const stockClass = stockCount <= 0 ? 'out' : '';

    rowsHtml += `
      <tr data-sku="${escapeHtml(item.sku)}" data-id="${item.id}">
        <td>
          <div style="font-weight:600;color:#0f172a">${escapeHtml(item.name)}</div>
          <div style="font-size:0.75rem;color:#64748b">${escapeHtml(item.sku)} &bull; Unit: <strong>${escapeHtml(item.size || 'Unit')}</strong></div>
        </td>
        <td class="text-center">
          <span class="stock-avail-chip ${stockClass}">
            [ ${stockCount} available ]
          </span>
        </td>
        <td class="text-center">
          <input type="number" class="form-control text-center pant-item-qty" value="${defQty}" min="0" oninput="calcPantKitRowEst(this)" style="font-weight:700">
        </td>
        <td class="text-right">
          <input type="number" class="form-control text-right pant-item-price" value="${price}" step="0.01" min="0" oninput="calcPantKitRowEst(this)">
        </td>
        <td class="text-right pant-item-est" style="font-weight:600;font-size:0.9rem">
          ₹${est}
        </td>
        <td class="text-center"></td>
      </tr>
    `;
  });

  tbody.innerHTML = rowsHtml;
  openModal('pantKitModal');
}

function addPantManualMaterialRow() {
  const tbody = document.getElementById('pantKitTableBody');
  if (!tbody) return;

  const tr = document.createElement('tr');
  tr.className = 'pant-manual-row';
  tr.style.background = '#fefce8';
  tr.style.borderLeft = '3px solid #eab308';
  tr.innerHTML = `
    <td>
      <div style="display:flex;gap:6px;align-items:center">
        <input type="text" class="form-control pant-manual-name" placeholder="e.g. Belt Lining Canvas, Thread..." style="font-weight:600;font-size:0.88rem;padding:5px 8px" required>
        <select class="form-select pant-manual-unit" style="width:90px;font-size:0.8rem;padding:4px 6px">
          <option value="Meter">Meter</option>
          <option value="Pcs">Pcs</option>
          <option value="Roll">Roll</option>
          <option value="Spool">Spool</option>
          <option value="Yard">Yard</option>
        </select>
      </div>
      <div style="font-size:0.72rem;color:#b45309;margin-top:3px"><i class="fas fa-magic"></i> Custom Added Material</div>
    </td>
    <td class="text-center">
      <span class="stock-avail-chip" style="background:#fef3c7;color:#92400e;border-color:#fde68a;font-size:0.75rem">
        [ New Material ]
      </span>
    </td>
    <td class="text-center">
      <input type="number" class="form-control text-center pant-item-qty" value="1" min="1" oninput="calcPantKitRowEst(this)" style="font-weight:700">
    </td>
    <td class="text-right">
      <input type="number" class="form-control text-right pant-item-price" value="0.00" step="0.01" min="0" oninput="calcPantKitRowEst(this)">
    </td>
    <td class="text-right pant-item-est" style="font-weight:600;font-size:0.9rem">
      ₹0.00
    </td>
    <td class="text-center">
      <button type="button" class="btn btn-xs btn-outline-danger" onclick="this.closest('tr').remove()" title="Remove this manual material">
        <i class="fas fa-trash"></i>
      </button>
    </td>
  `;
  tbody.appendChild(tr);

  const nameInput = tr.querySelector('.pant-manual-name');
  if (nameInput) nameInput.focus();
}

function calcPantKitRowEst(inputEl) {
  const row = inputEl.closest('tr');
  if (!row) return;
  const qty = parseInt(row.querySelector('.pant-item-qty')?.value) || 0;
  const price = parseFloat(row.querySelector('.pant-item-price')?.value) || 0;
  const estEl = row.querySelector('.pant-item-est');
  if (estEl) estEl.textContent = '₹' + (qty * price).toFixed(2);
}

function applyPantBatchQty() {
  const units = parseInt(document.getElementById('pant_batch_units').value) || 50;
  const ratios = {
    'MAT-PANT-ROLL-01': Math.round(units * 1.2),
    'MAT-PANT-ZIP-01': units * 1,
    'MAT-PANT-HOOK-01': units * 1,
    'MAT-PANT-POCK-01': Math.round(units * 0.5),
    'MAT-PANT-BTN-01': units * 2
  };

  const rows = document.querySelectorAll('#pantKitTableBody tr');
  rows.forEach(tr => {
    const sku = tr.dataset.sku;
    if (ratios[sku] !== undefined) {
      const qInput = tr.querySelector('.pant-item-qty');
      if (qInput) {
        qInput.value = ratios[sku];
        calcPantKitRowEst(qInput);
      }
    }
  });
  showToast('Applied standard kit qtys for ' + units + ' trousers', 'info');
}

function insertPantKit() {
  const rows = document.querySelectorAll('#pantKitTableBody tr');
  if (!rows || rows.length === 0) return;

  const kitItems = (window.PANT_KIT && window.PANT_KIT.length) ? window.PANT_KIT : [];
  let addedCount = 0;

  // Validate any manual rows first
  const manualRows = document.querySelectorAll('#pantKitTableBody tr.pant-manual-row');
  for (const mRow of manualRows) {
    const nameInput = mRow.querySelector('.pant-manual-name');
    const nameVal = (nameInput?.value || '').trim();
    if (!nameVal) {
      showToast('Please enter the name for your custom added material.', 'danger');
      nameInput.focus();
      return;
    }
  }

  rows.forEach(tr => {
    if (tr.classList.contains('pant-manual-row')) {
      const name = tr.querySelector('.pant-manual-name')?.value.trim();
      const unit = tr.querySelector('.pant-manual-unit')?.value || 'Meter';
      const qty = parseInt(tr.querySelector('.pant-item-qty')?.value) || 0;
      const price = parseFloat(tr.querySelector('.pant-item-price')?.value) || 0;
      if (qty > 0 && name) {
        addPurchaseRow({
          id: 0,
          is_manual: true,
          manual_name: name,
          manual_unit: unit,
          garment_category: 'Pant / Trouser',
          sku: 'NEW-MAT',
          name: name,
          size: unit,
          category_name: 'Pant / Trouser Materials',
          subcategory_name: unit === 'Meter' ? 'Fabrics' : 'Trims & Accessories',
          current_stock: 0,
          qty: qty,
          purchase_price: price,
          purchase_gst_pct: 5
        });
        addedCount++;
      }
    } else {
      const sku = tr.dataset.sku;
      const qty = parseInt(tr.querySelector('.pant-item-qty')?.value) || 0;
      const price = parseFloat(tr.querySelector('.pant-item-price')?.value) || 0;
      if (qty > 0) {
        const baseItem = kitItems.find(it => it.sku === sku) || {
          id: parseInt(tr.dataset.id),
          sku: sku,
          name: tr.querySelector('div')?.textContent || sku,
          size: 'Pcs',
          current_stock: 0
        };
        addPurchaseRow({
          ...baseItem,
          qty: qty,
          purchase_price: price
        });
        addedCount++;
      }
    }
  });

  closeModal('pantKitModal');
  showToast(`Successfully added ${addedCount} Pant / Trouser materials to consignment!`, 'success');
}

// SHIRT KIT MODAL HANDLERS
function openShirtKitModal() {
  const shirtItems = (window.SHIRT_KIT && window.SHIRT_KIT.length) ? window.SHIRT_KIT : [];
  const cloth  = shirtItems.find(i => i.sku === 'MAT-SHIRT-CLOTH-01');
  const collar = shirtItems.find(i => i.sku === 'MAT-SHIRT-COLLAR-01');
  const cuff   = shirtItems.find(i => i.sku === 'MAT-SHIRT-CUFF-01');
  const btn    = shirtItems.find(i => i.sku === 'MAT-SHIRT-BTN-01');

  if (cloth && document.getElementById('sk_cloth_avail_stock')) {
    document.getElementById('sk_cloth_avail_stock').textContent = `[ ${cloth.current_stock} available ]`;
    if (cloth.purchase_price) document.getElementById('sk_cloth_price').value = parseFloat(cloth.purchase_price).toFixed(2);
  }
  if (collar && document.getElementById('sk_collar_avail_stock')) {
    document.getElementById('sk_collar_avail_stock').textContent = `[ ${collar.current_stock} available ]`;
    if (collar.purchase_price) document.getElementById('sk_collar_price').value = parseFloat(collar.purchase_price).toFixed(2);
  }
  if (cuff && document.getElementById('sk_cuff_avail_stock')) {
    document.getElementById('sk_cuff_avail_stock').textContent = `[ ${cuff.current_stock} available ]`;
    if (cuff.purchase_price) document.getElementById('sk_cuff_price').value = parseFloat(cuff.purchase_price).toFixed(2);
  }
  if (btn && document.getElementById('sk_btn_avail_stock')) {
    document.getElementById('sk_btn_avail_stock').textContent = `[ ${btn.current_stock} available ]`;
    if (btn.purchase_price) document.getElementById('sk_btn_price').value = parseFloat(btn.purchase_price).toFixed(2);
  }

  const manualContainer = document.getElementById('shirtManualMaterialsContainer');
  if (manualContainer) manualContainer.innerHTML = '';

  openModal('shirtKitModal');
  document.getElementById('sk_cloth_color')?.focus();
}

function addShirtManualMaterialRow() {
  const container = document.getElementById('shirtManualMaterialsContainer');
  if (!container) return;

  const div = document.createElement('div');
  div.className = 'kit-item-box shirt-manual-box';
  div.style.marginBottom = '12px';
  div.style.background = '#fefce8';
  div.style.borderLeft = '3px solid #eab308';
  div.innerHTML = `
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
      <div>
        <strong style="font-size:0.9rem;color:#0f172a"><i class="fas fa-magic text-warning"></i> Custom Shirt Material</strong>
        <span class="badge" style="background:#fde68a;color:#92400e;font-size:0.72rem;margin-left:6px">Manual Entry</span>
      </div>
      <button type="button" class="btn btn-xs btn-outline-danger" onclick="this.closest('.shirt-manual-box').remove()" title="Remove">
        <i class="fas fa-trash"></i> Remove
      </button>
    </div>
    <div class="form-row cols-3">
      <div class="form-group" style="margin-bottom:0">
        <label class="form-label" style="font-weight:600">Material Name <span class="required">*</span></label>
        <input type="text" class="form-control shirt-manual-name" placeholder="e.g. Collar Stays, Brand Tag, Thread..." required>
      </div>
      <div class="form-group" style="margin-bottom:0">
        <label class="form-label" style="font-weight:600">Unit of Measure</label>
        <select class="form-select shirt-manual-unit">
          <option value="Meter">Meter</option>
          <option value="Pcs" selected>Pcs</option>
          <option value="Roll">Roll</option>
          <option value="Spool">Spool</option>
          <option value="Yard">Yard</option>
        </select>
      </div>
      <div class="form-group" style="margin-bottom:0">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
          <div>
            <label class="form-label" style="font-weight:600">Qty <span class="required">*</span></label>
            <input type="number" class="form-control text-center shirt-manual-qty" value="10" min="1" required style="font-weight:700">
          </div>
          <div>
            <label class="form-label" style="font-weight:600">Price (₹) <span class="required">*</span></label>
            <input type="number" class="form-control text-right shirt-manual-price" value="0.00" step="0.01" min="0" required>
          </div>
        </div>
      </div>
    </div>
  `;
  container.appendChild(div);

  const nameInput = div.querySelector('.shirt-manual-name');
  if (nameInput) nameInput.focus();
}

function setShirtColor(c) {
  const input = document.getElementById('sk_cloth_color');
  if (input) input.value = c;
}

function setShirtSize(s) {
  const input = document.getElementById('sk_collar_size');
  if (input) input.value = s;
}

function insertShirtKit() {
  const clothColor = document.getElementById('sk_cloth_color').value.trim() || 'Sky Blue';
  const clothQty   = parseInt(document.getElementById('sk_cloth_qty').value) || 50;
  const clothPrice = parseFloat(document.getElementById('sk_cloth_price').value) || 160.00;

  const collarSize = document.getElementById('sk_collar_size').value.trim() || '17 and half';
  const collarQty  = parseInt(document.getElementById('sk_collar_qty').value) || 30;
  const collarPrice= parseFloat(document.getElementById('sk_collar_price').value) || 25.00;

  const cuffQty    = parseInt(document.getElementById('sk_cuff_qty').value) || 20;
  const cuffPrice  = parseFloat(document.getElementById('sk_cuff_price').value) || 35.00;

  const btnQty     = parseInt(document.getElementById('sk_btn_qty').value) || 200;
  const btnPrice   = parseFloat(document.getElementById('sk_btn_price').value) || 1.50;

  // Validate any manual rows first
  const manualBoxes = document.querySelectorAll('.shirt-manual-box');
  for (const box of manualBoxes) {
    const nameInput = box.querySelector('.shirt-manual-name');
    const nameVal = (nameInput?.value || '').trim();
    if (!nameVal) {
      showToast('Please enter the name for your custom added shirt material.', 'danger');
      nameInput.focus();
      return;
    }
  }

  const shirtItems = (window.SHIRT_KIT && window.SHIRT_KIT.length) ? window.SHIRT_KIT : [];
  const cloth  = shirtItems.find(i => i.sku === 'MAT-SHIRT-CLOTH-01') || { id: 12, sku: 'MAT-SHIRT-CLOTH-01', name: 'Shirt Fabric Cloth', size: 'Meter', current_stock: 0 };
  const collar = shirtItems.find(i => i.sku === 'MAT-SHIRT-COLLAR-01') || { id: 15, sku: 'MAT-SHIRT-COLLAR-01', name: 'Shirt Collar Fusing', size: 'Pcs', current_stock: 0 };
  const cuff   = shirtItems.find(i => i.sku === 'MAT-SHIRT-CUFF-01') || { id: 14, sku: 'MAT-SHIRT-CUFF-01', name: 'Shirt Cuff Roll', size: 'Meter', current_stock: 0 };
  const btn    = shirtItems.find(i => i.sku === 'MAT-SHIRT-BTN-01') || { id: 13, sku: 'MAT-SHIRT-BTN-01', name: 'Shirt Buttons', size: 'Pcs', current_stock: 0 };

  let addedCount = 0;

  // 1. Cloth with color
  if (clothQty > 0) {
    addPurchaseRow({
      ...cloth,
      qty: clothQty,
      purchase_price: clothPrice,
      custom_spec: 'Color: ' + clothColor
    });
    addedCount++;
  }

  // 2. Collar with size
  if (collarQty > 0) {
    addPurchaseRow({
      ...collar,
      qty: collarQty,
      purchase_price: collarPrice,
      custom_spec: 'Size: ' + collarSize
    });
    addedCount++;
  }

  // 3. Cuff roll
  if (cuffQty > 0) {
    addPurchaseRow({
      ...cuff,
      qty: cuffQty,
      purchase_price: cuffPrice
    });
    addedCount++;
  }

  // 4. Buttons
  if (btnQty > 0) {
    addPurchaseRow({
      ...btn,
      qty: btnQty,
      purchase_price: btnPrice
    });
    addedCount++;
  }

  // 5. Custom / Extra Manual Materials
  manualBoxes.forEach(box => {
    const name = box.querySelector('.shirt-manual-name')?.value.trim();
    const unit = box.querySelector('.shirt-manual-unit')?.value || 'Pcs';
    const qty  = parseInt(box.querySelector('.shirt-manual-qty')?.value) || 0;
    const price= parseFloat(box.querySelector('.shirt-manual-price')?.value) || 0;
    if (name && qty > 0) {
      addPurchaseRow({
        id: 0,
        is_manual: true,
        manual_name: name,
        manual_unit: unit,
        garment_category: 'Shirt',
        sku: 'NEW-MAT',
        name: name,
        size: unit,
        category_name: 'Shirt Materials',
        subcategory_name: unit === 'Meter' ? 'Fabrics' : 'Trims & Accessories',
        current_stock: 0,
        qty: qty,
        purchase_price: price,
        purchase_gst_pct: 5
      });
      addedCount++;
    }
  });

  closeModal('shirtKitModal');
  showToast(`Successfully added ${addedCount} Shirt materials to consignment!`, 'success');
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
    if (!vid) return;

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
      // Add row pre-filled with this brand-new material
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
    toggleAddMenu();
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

// On page load, auto-add prefilled material or auto-open kit modal if requested
document.addEventListener('DOMContentLoaded', function() {
  if (window.PREFILL_MATERIAL) {
    addPurchaseRow(window.PREFILL_MATERIAL);
  }
  const urlParams = new URLSearchParams(window.location.search);
  const openKit = urlParams.get('open_kit');
  if (openKit === 'pant') {
    openPantKitModal();
  } else if (openKit === 'shirt') {
    openShirtKitModal();
  }
});
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
