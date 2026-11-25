<?php
/**
 * Admin: Material Used & Production (Fashion Kingdom / Supplier Hub)
 * Rough calculation estimator, cutting consumption & manual raw material deduction
 */
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireAdmin();

// Ensure required tables exist
function ensureConsumptionTables() {
    try {
        db()->execute("
            CREATE TABLE IF NOT EXISTS `material_consumptions` (
              `id`                  INT AUTO_INCREMENT PRIMARY KEY,
              `consumption_no`      VARCHAR(50)  UNIQUE NOT NULL,
              `production_date`     DATE         NOT NULL,
              `garment_type`        VARCHAR(100) NOT NULL,
              `units_produced`      INT          NOT NULL DEFAULT 0,
              `finished_product_id` INT          DEFAULT NULL,
              `finished_variant_id` INT          DEFAULT NULL,
              `tailor_name`         VARCHAR(100) DEFAULT NULL,
              `total_fabric_meters` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
              `notes`               TEXT,
              `created_by`          INT          DEFAULT NULL,
              `created_at`          TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
              INDEX (`production_date`),
              INDEX (`garment_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        db()->execute("
            CREATE TABLE IF NOT EXISTS `material_consumption_items` (
              `id`             INT AUTO_INCREMENT PRIMARY KEY,
              `consumption_id` INT          NOT NULL,
              `variant_id`     INT          NOT NULL,
              `material_role`  VARCHAR(50)  DEFAULT 'fabric',
              `qty_used`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
              `unit`           VARCHAR(20)  DEFAULT 'meters',
              `notes`          VARCHAR(255) DEFAULT NULL,
              FOREIGN KEY (`consumption_id`) REFERENCES `material_consumptions`(`id`) ON DELETE CASCADE,
              INDEX (`variant_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } catch (Exception $e) {
        // Table may already exist
    }
}
ensureConsumptionTables();

function generateConsumptionNo(): string {
    $prefix = 'PRD-' . date('Ym') . '-';
    $last = db()->fetchOne("SELECT consumption_no FROM material_consumptions WHERE consumption_no LIKE ? ORDER BY id DESC LIMIT 1", [$prefix . '%']);
    if ($last) {
        $num = (int)substr($last['consumption_no'], strlen($prefix)) + 1;
    } else {
        $num = 1;
    }
    return $prefix . str_pad((string)$num, 4, '0', STR_PAD_LEFT);
}

$errors = [];
$successMsg = '';

// Handle Material Consumption Entry POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();

    $productionDate    = sanitize($_POST['production_date'] ?? date('Y-m-d'));
    $clientFirm        = sanitize($_POST['client_firm'] ?? '');
    $batchOrderNo      = sanitize($_POST['batch_order_no'] ?? '');
    $uniformCategory   = sanitize($_POST['uniform_category'] ?? '');
    $garmentType       = sanitize($_POST['garment_type'] ?? '');
    $customGarment     = sanitize($_POST['custom_garment'] ?? '');
    if ($garmentType === 'Custom' && $customGarment !== '') {
        $garmentType = $customGarment;
    }
    if (empty($garmentType)) {
        $garmentType = $uniformCategory ?: 'Security Guard Uniform';
    }
    if (empty($batchOrderNo)) {
        $batchOrderNo = 'BATCH-' . date('Ym') . '-' . str_pad((string)rand(10, 999), 3, '0', STR_PAD_LEFT);
    }
    $unitsProduced     = max(1, (int)($_POST['units_produced'] ?? 0));
    $tailorName        = sanitize($_POST['tailor_name'] ?? '');
    $notes             = sanitize($_POST['notes'] ?? '');
    $addToFinished     = !empty($_POST['add_to_finished_stock']);
    $finishedVariantId = (int)($_POST['finished_variant_id'] ?? 0);

    // Process Guard / Staff size breakdown array
    $sizesInput    = $_POST['sizes'] ?? [];
    $sizeBreakdown = [];
    if (is_array($sizesInput)) {
        foreach ($sizesInput as $s) {
            $sz     = sanitize($s['size'] ?? '');
            $qty    = (int)($s['qty'] ?? 0);
            $meters = (float)($s['meters'] ?? 0);
            $style  = sanitize($s['style'] ?? 'Standard');
            if (!empty($sz) && $qty > 0) {
                $sizeBreakdown[] = [
                    'size'        => $sz,
                    'qty'         => $qty,
                    'meters'      => $meters,
                    'total_cloth' => round($qty * $meters, 2),
                    'style'       => $style
                ];
            }
        }
    }
    $sizeBreakdownJson = !empty($sizeBreakdown) ? json_encode($sizeBreakdown, JSON_UNESCAPED_UNICODE) : null;

    $items = $_POST['items'] ?? [];

    if (empty($garmentType)) {
        $errors[] = 'Please specify the garment produced (e.g. Full Sleeve Shirt, Trouser).';
    }
    if ($unitsProduced <= 0) {
        $errors[] = 'Units produced must be at least 1.';
    }
    if (empty($items) || !is_array($items)) {
        $errors[] = 'Please add at least one material that was used (e.g. fabric cloth or buttons).';
    }

    // Validate materials and stock
    $validItems = [];
    $totalFabricMeters = 0.0;

    foreach ($items as $idx => $it) {
        $variantId = (int)($it['variant_id'] ?? 0);
        $qtyUsed   = (float)($it['qty_used'] ?? 0);
        $role      = sanitize($it['material_role'] ?? 'fabric');
        $unit      = sanitize($it['unit'] ?? 'meters');
        $itemNotes = sanitize($it['notes'] ?? '');

        if (!$variantId || $qtyUsed <= 0) {
            continue; // Skip blank rows
        }

        $vRow = db()->fetchOne(
            "SELECT pv.id, pv.current_stock, pv.sku, p.name AS material_name 
             FROM product_variants pv 
             JOIN products p ON pv.product_id = p.id 
             WHERE pv.id = ? AND pv.status = 'active' AND p.item_type = 'material'",
            [$variantId]
        );

        if (!$vRow) {
            $errors[] = "Material in row #" . ($idx + 1) . " was not found.";
            continue;
        }

        if ($vRow['current_stock'] < $qtyUsed) {
            $errors[] = "Insufficient stock for '{$vRow['material_name']}' ({$vRow['sku']}). Available: {$vRow['current_stock']} {$unit}, but attempted to deduct: {$qtyUsed} {$unit}.";
        }

        if ($role === 'fabric') {
            $totalFabricMeters += $qtyUsed;
        }

        $validItems[] = [
            'variant_id'    => $variantId,
            'material_role' => $role,
            'qty_used'      => $qtyUsed,
            'unit'          => $unit,
            'notes'         => $itemNotes,
            'material_name' => $vRow['material_name']
        ];
    }

    if (empty($validItems) && empty($errors)) {
        $errors[] = 'Please enter valid materials and quantities used.';
    }

    if (empty($errors)) {
        try {
            db()->beginTransaction();

            $consumptionNo = generateConsumptionNo();

            // Insert master consumption record with firm, batch # & size breakdown
            $consumptionId = db()->insert(
                "INSERT INTO material_consumptions 
                 (consumption_no, production_date, client_firm, batch_order_no, uniform_category, garment_type, units_produced, finished_variant_id, tailor_name, total_fabric_meters, size_breakdown, notes, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $consumptionNo,
                    $productionDate,
                    $clientFirm ?: null,
                    $batchOrderNo ?: null,
                    $uniformCategory ?: null,
                    $garmentType,
                    $unitsProduced,
                    $addToFinished && $finishedVariantId ? $finishedVariantId : null,
                    $tailorName ?: null,
                    $totalFabricMeters,
                    $sizeBreakdownJson,
                    $notes ?: null,
                    currentUserId()
                ]
            );

            // Insert detail items and deduct stock
            foreach ($validItems as $vit) {
                db()->insert(
                    "INSERT INTO material_consumption_items 
                     (consumption_id, variant_id, material_role, qty_used, unit, notes)
                     VALUES (?, ?, ?, ?, ?, ?)",
                    [
                        $consumptionId,
                        $vit['variant_id'],
                        $vit['material_role'],
                        $vit['qty_used'],
                        $vit['unit'],
                        $vit['notes'] ?: null
                    ]
                );

                // Deduct stock using standard movement ledger
                $qtyChange = -(int)round($vit['qty_used']);
                if ($qtyChange == 0 && $vit['qty_used'] > 0) {
                    $qtyChange = -1; // ensure at least 1 unit deducted if fractional rounded down
                }

                $firmInfo = $clientFirm ? " for $clientFirm" : "";
                $batchInfo = $batchOrderNo ? " (Batch #$batchOrderNo)" : "";
                $movementNote = "Production: $unitsProduced $garmentType$firmInfo$batchInfo ($consumptionNo) — {$vit['qty_used']} {$vit['unit']} deducted";

                recordStockMovement(
                    $vit['variant_id'],
                    'adjustment',
                    $qtyChange,
                    $consumptionId,
                    'production_consumption',
                    $movementNote,
                    currentUserId()
                );
            }

            // Optional: If adding finished product to Unnatti boutique inventory
            if ($addToFinished && $finishedVariantId > 0) {
                $fVar = db()->fetchOne("SELECT pv.id, p.name FROM product_variants pv JOIN products p ON pv.product_id=p.id WHERE pv.id=?", [$finishedVariantId]);
                if ($fVar) {
                    recordStockMovement(
                        $finishedVariantId,
                        'adjustment',
                        $unitsProduced,
                        $consumptionId,
                        'production_finished',
                        "Produced: $unitsProduced pcs via Fashion Kingdom ($consumptionNo)",
                        currentUserId()
                    );
                }
            }

            db()->commit();

            logActivity("Recorded material consumption: $consumptionNo for {$clientFirm} ({$unitsProduced} {$garmentType} produced)", 'production', $consumptionId, 'production');
            $_SESSION['flash_success'] = "Production batch <strong>$consumptionNo</strong> for <strong>" . ($clientFirm ?: 'Order') . "</strong> recorded successfully. Stock deducted for all materials used!";
            header('Location: ' . APP_URL . '/admin/purchases/consumption.php');
            exit;

        } catch (Exception $e) {
            db()->rollback();
            $errors[] = 'Error saving consumption entry: ' . $e->getMessage();
        }
    }
}

// ── Fetch Data for Forms & Lists ──────────────────────────────────────────
// 1. Raw materials available in Fashion Kingdom
$rawMaterials = db()->fetchAll(
    "SELECT pv.id, pv.sku, pv.size AS unit, pv.current_stock, pv.purchase_price_with_gst,
            p.id AS product_id, p.name AS material_name, p.brand AS material_type,
            COALESCE(p.garment_category, 'General') AS garment_category
     FROM product_variants pv 
     JOIN products p ON pv.product_id = p.id 
     WHERE pv.status = 'active' AND p.status = 'active' AND p.item_type = 'material'
     ORDER BY FIELD(COALESCE(p.garment_category,'General'), 'Shirt', 'Pant / Trouser', 'General'), p.name ASC"
);

// Materials available in stock for the Rough Production Estimator (Buttons, Zips, Hooks, Trims, etc.)
$estimatorAvailableMaterials = db()->fetchAll("
    SELECT pv.id, pv.sku, pv.size, pv.current_stock,
           p.id AS product_id, p.name AS product_name, p.brand AS material_type,
           COALESCE(c.name, 'Trims & Accessories') AS category_name
    FROM product_variants pv
    JOIN products p ON pv.product_id = p.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE pv.status = 'active' AND p.status = 'active' AND p.item_type = 'material'
    ORDER BY pv.current_stock DESC, p.name ASC
");

// Separate fabrics vs trims for easy selection
$fabrics = [];
$trims = [];
foreach ($rawMaterials as $rm) {
    $name = strtolower($rm['material_name'] . ' ' . $rm['material_type'] . ' ' . $rm['unit']);
    $isCanvasOrTrim = (strpos($name, 'canvas') !== false || strpos($name, 'button') !== false || strpos($name, 'zip') !== false || strpos($name, 'hook') !== false);
    if (!$isCanvasOrTrim && (strpos($name, 'cloth') !== false || strpos($name, 'fabric') !== false || strpos($name, 'meter') !== false)) {
        $fabrics[] = $rm;
    } else {
        $trims[] = $rm;
    }
}
if (empty($fabrics)) $fabrics = $rawMaterials; // fallback

// 2. Finished boutique ready products (for optional link to Unnatti)
$boutiqueVariants = db()->fetchAll(
    "SELECT pv.id, pv.sku, pv.size, pv.current_stock, p.name AS product_name
     FROM product_variants pv
     JOIN products p ON pv.product_id = p.id
     WHERE pv.status = 'active' AND p.status = 'active' AND (p.item_type = 'product' OR p.item_type IS NULL)
     ORDER BY p.name ASC, pv.size ASC"
);

// 3. Recent material consumption history
$recentConsumptions = db()->fetchAll(
    "SELECT mc.*, u.full_name AS employee_name,
            (SELECT COUNT(*) FROM material_consumption_items mci WHERE mci.consumption_id = mc.id) AS items_count
     FROM material_consumptions mc
     LEFT JOIN users u ON mc.created_by = u.id
     ORDER BY mc.id DESC LIMIT 25"
);

// Summary KPIs
$totalBatches = count($recentConsumptions);
$totalMetersConsumed = (float)(db()->fetchOne("SELECT COALESCE(SUM(total_fabric_meters),0) AS s FROM material_consumptions")['s'] ?? 0);
$totalPiecesProduced = (int)(db()->fetchOne("SELECT COALESCE(SUM(units_produced),0) AS s FROM material_consumptions")['s'] ?? 0);

$pageTitle  = 'Material Used & Production';
$activePage = 'purchases';
$breadcrumb = [
    ['label' => 'Supplier'],
    ['label' => 'Material Stock & Inventory', 'url' => APP_URL . '/admin/purchases/materials.php'],
    ['label' => 'Material Used & Production']
];

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<script>window.APP_URL = '<?= APP_URL ?>';</script>

<style>
.preset-card {
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 14px 16px;
  background: #ffffff;
  cursor: pointer;
  transition: all 0.2s ease;
  position: relative;
}
.preset-card:hover {
  border-color: #3b82f6;
  background: #f8faff;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(37,99,235,0.08);
}
.preset-card.active {
  border-color: #2563eb;
  background: #eff6ff;
  box-shadow: 0 0 0 2px rgba(37,99,235,0.2);
}
.calc-result-box {
  background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
  color: #ffffff;
  border-radius: 12px;
  padding: 20px 24px;
}
.badge-pill-soft {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 10px;
  border-radius: 20px;
  font-size: 0.78rem;
  font-weight: 600;
}
</style>

<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title"><i class="fas fa-cut text-primary" style="margin-right:10px"></i>Material Used &amp; Production</h1>
    <p class="page-subtitle">Rough production yield calculator &amp; manual consumption logging for fabrics, buttons and trims</p>
  </div>
  <div class="page-header-right">
    <a href="<?= APP_URL ?>/admin/purchases/materials.php" class="btn btn-light">
      <i class="fas fa-scroll"></i> Material Stock
    </a>
    <a href="<?= APP_URL ?>/admin/purchases/material_movements.php" class="btn btn-light">
      <i class="fas fa-history"></i> Movements Ledger
    </a>
  </div>
</div>

<?php foreach ($errors as $err): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= e($err) ?></div>
<?php endforeach; ?>

<!-- Quick Summary Stats -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit, minmax(220px, 1fr));gap:16px;margin-bottom:24px">
  <div class="stat-card stat-card-primary">
    <div class="stat-icon"><i class="fas fa-tshirt"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($totalPiecesProduced) ?></div>
      <div class="stat-label">Total Garments Produced</div>
      <div class="stat-trend up"><i class="fas fa-check-circle"></i> From raw materials</div>
    </div>
  </div>
  <div class="stat-card stat-card-info">
    <div class="stat-icon"><i class="fas fa-ruler-combined"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($totalMetersConsumed) ?> m</div>
      <div class="stat-label">Total Fabric Consumed</div>
      <div class="stat-trend"><i class="fas fa-cut"></i> Cutting logs</div>
    </div>
  </div>
  <div class="stat-card stat-card-success">
    <div class="stat-icon"><i class="fas fa-clipboard-list"></i></div>
    <div class="stat-body">
      <div class="stat-value"><?= number_format($totalBatches) ?></div>
      <div class="stat-label">Production Batches</div>
      <div class="stat-trend up"><i class="fas fa-layer-group"></i> Logged entries</div>
    </div>
  </div>
</div>

<!-- ======================================================================= -->
<!-- SECTION 1: ROUGH PRODUCTION YIELD ESTIMATOR                             -->
<!-- ======================================================================= -->
<div class="card" id="estimatorCard" style="margin-bottom:24px;border:1px solid #bfdbfe;background:#f8faff;">
  <div class="card-header" style="background:#eff6ff;border-bottom:1px solid #dbeafe;display:flex;justify-content:space-between;align-items:center">
    <span class="card-title" style="color:#1e40af;font-weight:700">
      <i class="fas fa-calculator text-primary" style="margin-right:8px"></i>Rough Production Yield Estimator
    </span>
    <span class="badge" style="background:#dbeafe;color:#1e40af;font-size:0.75rem">
      <i class="fas fa-magic"></i> Live Estimator
    </span>
  </div>
  <div class="card-body" style="padding:20px">
    <!-- Add Material Section (Fabric / Cloth, Buttons, Zippers, Canvas, Hooks) -->
    <div style="background:#f8fafc;padding:14px 16px;border-radius:10px;border:1px solid #cbd5e1;margin-bottom:16px">
      <div style="font-size:0.78rem;color:#334155;text-transform:uppercase;font-weight:700;margin-bottom:8px;display:flex;align-items:center;gap:6px">
        <i class="fas fa-layer-group text-primary"></i> Add Material (Fabric / Cloth, Buttons, Zippers, Canvas, Hooks):
      </div>
      <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <div style="flex:1;min-width:240px">
          <select id="estimatorAddMaterialSelect" class="form-select form-select-sm" style="background:#fff;font-size:0.86rem;height:38px">
            <option value="">-- Choose available material to include (Fabric, Buttons, Zip, Hook, Canvas...) --</option>
            <option value="manual_fabric" data-name="Manual Cloth / Fabric (Custom Meters)" data-sku="MANUAL-FABRIC" data-stock="10000" data-unit="meters">✂️ Manual Cloth / Fabric (Enter Custom Meters)</option>
            <?php foreach ($estimatorAvailableMaterials as $matItem): ?>
              <option value="<?= $matItem['id'] ?>" 
                      data-name="<?= e(addslashes($matItem['product_name'])) ?>" 
                      data-sku="<?= e($matItem['sku']) ?>"
                      data-stock="<?= (float)$matItem['current_stock'] ?>" 
                      data-unit="<?= e($matItem['size']) ?>">
                <?= e($matItem['product_name']) ?> (<?= e($matItem['size']) ?>) &bull; Available: <?= (float)$matItem['current_stock'] ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <button type="button" class="btn btn-sm btn-primary" onclick="addMaterialToEstimator()" style="white-space:nowrap;font-size:0.84rem;font-weight:600;padding:8px 18px;height:38px;display:flex;align-items:center;gap:6px">
          <i class="fas fa-plus"></i> Add Material
        </button>
      </div>

      <!-- Quick Add Buttons for faster access -->
      <div style="margin-top:10px;display:flex;align-items:center;gap:6px;flex-wrap:wrap">
        <span style="font-size:0.75rem;color:#64748b;font-weight:600">Quick Add:</span>
        <button type="button" class="btn btn-xs btn-outline-primary" onclick="quickAddTrim('fabric')" style="font-size:0.75rem;padding:3px 8px;font-weight:600">+ Fabric / Cloth Roll</button>
        <button type="button" class="btn btn-xs btn-outline-primary" onclick="quickAddTrim('button')" style="font-size:0.75rem;padding:3px 8px;font-weight:600">+ Shirt Buttons</button>
        <button type="button" class="btn btn-xs btn-outline-primary" onclick="quickAddTrim('collar')" style="font-size:0.75rem;padding:3px 8px;font-weight:600">+ Collar Canvas</button>
        <button type="button" class="btn btn-xs btn-outline-primary" onclick="quickAddTrim('pant_btn')" style="font-size:0.75rem;padding:3px 8px;font-weight:600">+ Pant Buttons</button>
        <button type="button" class="btn btn-xs btn-outline-primary" onclick="quickAddTrim('waist')" style="font-size:0.75rem;padding:3px 8px;font-weight:600">+ Pant Waist Canvas</button>
        <button type="button" class="btn btn-xs btn-outline-primary" onclick="quickAddTrim('zip')" style="font-size:0.75rem;padding:3px 8px;font-weight:600">+ Pant Zippers</button>
        <button type="button" class="btn btn-xs btn-outline-primary" onclick="quickAddTrim('hook')" style="font-size:0.75rem;padding:3px 8px;font-weight:600">+ Waist Hooks</button>
      </div>

      <!-- Cards Container for Added Materials -->
      <div id="estimatorSelectedMaterialsList" style="margin-top:12px">
        <!-- Rendered dynamically via renderSelectedMaterialsCards() -->
      </div>
    </div>

    <div style="font-size:0.82rem;color:#64748b;margin-bottom:16px">
      <i class="fas fa-info-circle text-primary"></i> Collar, cuffs, waistband &amp; pockets are cut from the main fabric roll. Trims and accessories are calculated from their entered units.
    </div>

    <!-- 3. Garment Selection with Size & Manual Meter Calculation Bar -->
    <div style="background:#f8fafc;border:1px solid #cbd5e1;border-radius:8px;padding:14px 16px;margin-bottom:18px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <span style="font-size:0.78rem;font-weight:700;color:#334155;text-transform:uppercase">
          <i class="fas fa-sliders-h text-primary" style="margin-right:4px"></i> Calculate Specific Garment Yield by Size:
        </span>
        <span style="font-size:0.75rem;color:#64748b">Select garment, size &amp; tailoring rate</span>
      </div>

      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <!-- Garment Dropdown -->
        <div style="flex:1.2;min-width:180px">
          <label style="font-size:0.74rem;font-weight:700;color:#475569;display:block;margin-bottom:3px">Garment to Produce:</label>
          <select id="estimatorGarmentSelect" class="form-select form-select-sm" style="height:38px;font-size:0.86rem;font-weight:600" onchange="onGarmentOrSizeChange()">
            <option value="full_shirt" data-label="Full Sleeve Shirt">👔 Full Sleeve Shirt</option>
            <option value="half_shirt" data-label="Half Sleeve Shirt">👕 Half Sleeve Shirt</option>
            <option value="tshirt" data-label="T-Shirt / Polo">👕 T-Shirt / Polo</option>
            <option value="trouser" data-label="Trousers / Pants">👖 Trousers / Pants</option>
            <option value="apron" data-label="Doctor Apron / Coat">🥼 Doctor Apron / Coat</option>
            <option value="kurta" data-label="Kurta">🥻 Kurta</option>
            <option value="custom" data-label="Custom Garment">✂️ Custom / Other Garment</option>
          </select>
        </div>

        <!-- Garment Size Dropdown -->
        <div style="flex:1;min-width:160px">
          <label style="font-size:0.74rem;font-weight:700;color:#475569;display:block;margin-bottom:3px">Staff / Uniform Size:</label>
          <select id="estimatorGarmentSize" class="form-select form-select-sm" style="height:38px;font-size:0.86rem;font-weight:600" onchange="onGarmentOrSizeChange()">
            <option value="40">Size 40 (M) — Standard</option>
            <option value="38">Size 38 (S) — Compact</option>
            <option value="42">Size 42 (L) — Large</option>
            <option value="44">Size 44 (XL) — Extra Large</option>
            <option value="46">Size 46 (XXL) — 2XL</option>
            <option value="36">Size 36 (XS) — Small</option>
            <option value="free">Free Size / Standard</option>
            <option value="custom">Custom Size / Manual Meter</option>
          </select>
        </div>

        <!-- Cloth per Garment Input -->
        <div style="width:120px">
          <label style="font-size:0.74rem;font-weight:700;color:#475569;display:block;margin-bottom:3px">Cloth per Pc:</label>
          <div style="display:flex;align-items:center;gap:4px">
            <input type="number" id="estimatorManualMeter" class="form-control form-control-sm" step="0.05" min="0.1" value="1.70" placeholder="Meter" style="height:38px;font-size:0.95rem;font-weight:700;text-align:center" oninput="onManualMeterInput()">
            <span style="font-size:0.82rem;color:#64748b;font-weight:600">m</span>
          </div>
        </div>

        <!-- Buttons per Pc Input -->
        <div style="width:120px" id="estimatorButtonsBox">
          <label style="font-size:0.74rem;font-weight:700;color:#475569;display:block;margin-bottom:3px">Buttons per Pc:</label>
          <div style="display:flex;align-items:center;gap:4px">
            <input type="number" id="estimatorManualButtons" class="form-control form-control-sm" min="0" max="100" step="1" value="11" placeholder="Btns" style="height:38px;font-size:0.95rem;font-weight:700;text-align:center" oninput="onManualButtonsInput()">
            <span style="font-size:0.82rem;color:#64748b;font-weight:600">btns</span>
          </div>
        </div>

        <!-- Action buttons -->
        <div style="display:flex;gap:6px;align-self:flex-end">
          <button type="button" class="btn btn-sm btn-primary" onclick="applyCustomMeter()" style="height:38px;padding:0 18px;font-weight:700;font-size:0.84rem;display:flex;align-items:center;gap:6px">
            <i class="fas fa-calculator"></i> Apply &amp; Calculate
          </button>
          <button type="button" class="btn btn-sm btn-light" onclick="clearCustomMeter()" style="height:38px;padding:0 14px;font-weight:600;font-size:0.84rem;display:flex;align-items:center;gap:4px">
            <i class="fas fa-undo"></i> Clear
          </button>
        </div>
      </div>

      <!-- Dynamic Applied Custom Calculation Result Banner -->
      <div id="appliedCustomResult" style="display:none;margin-top:14px"></div>
    </div>

    <!-- 4. Four Grid Cards -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:12px;margin-bottom:20px" id="estimatorCardsGrid">
      <!-- 1. Full Sleeve Shirt -->
      <div class="yield-card" id="cardFullShirt" onclick="selectEstimatorCard('full_shirt')" title="Click to select Full Sleeve Shirt" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;transition:all 0.2s;cursor:pointer">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <div style="font-weight:700;color:#1e293b;font-size:0.92rem"><i class="fas fa-tshirt text-primary"></i> Full Sleeve Shirts</div>
          <span class="custom-badge" id="badgeFullShirt" style="display:none;font-size:0.7rem;background:#2563eb;color:#fff;padding:2px 8px;border-radius:10px;font-weight:600">Active</span>
        </div>
        <div style="font-size:0.75rem;color:#64748b;margin-top:2px" id="specFullShirt">1.7 meters / shirt + 11 buttons</div>
        <div style="font-size:1.45rem;font-weight:800;color:#10b981;margin-top:6px" id="yieldFullShirt">~117 pcs</div>
        <div style="font-size:0.78rem;color:#d97706;font-weight:600" id="yieldFullShirtButtons"><i class="fas fa-circle"></i> ~1,287 buttons needed</div>
        <div id="stockCheckFullShirt" style="font-size:0.75rem;margin-top:4px;display:none"></div>
      </div>

      <!-- 2. Half Sleeve Shirt -->
      <div class="yield-card" id="cardHalfShirt" onclick="selectEstimatorCard('half_shirt')" title="Click to select Half Sleeve Shirt" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;transition:all 0.2s;cursor:pointer">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <div style="font-weight:700;color:#1e293b;font-size:0.92rem"><i class="fas fa-tshirt" style="color:#0284c7"></i> Half Sleeve Shirts</div>
          <span class="custom-badge" id="badgeHalfShirt" style="display:none;font-size:0.7rem;background:#0284c7;color:#fff;padding:2px 8px;border-radius:10px;font-weight:600">Active</span>
        </div>
        <div style="font-size:0.75rem;color:#64748b;margin-top:2px" id="specHalfShirt">1.4 meters / shirt + 8 buttons</div>
        <div style="font-size:1.45rem;font-weight:800;color:#0284c7;margin-top:6px" id="yieldHalfShirt">~142 pcs</div>
        <div style="font-size:0.78rem;color:#d97706;font-weight:600" id="yieldHalfShirtButtons"><i class="fas fa-circle"></i> ~1,136 buttons needed</div>
        <div id="stockCheckHalfShirt" style="font-size:0.75rem;margin-top:4px;display:none"></div>
      </div>

      <!-- 3. Trouser / Pant -->
      <div class="yield-card" id="cardTrouser" onclick="selectEstimatorCard('trouser')" title="Click to select Trousers / Pants" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;transition:all 0.2s;cursor:pointer">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <div style="font-weight:700;color:#1e293b;font-size:0.92rem"><i class="fas fa-columns" style="color:#8b5cf6"></i> Trousers / Pants</div>
          <span class="custom-badge" id="badgeTrouser" style="display:none;font-size:0.7rem;background:#8b5cf6;color:#fff;padding:2px 8px;border-radius:10px;font-weight:600">Active</span>
        </div>
        <div style="font-size:0.75rem;color:#64748b;margin-top:2px" id="specTrouser">1.25 meters / pant + 1 zip + 1 hook</div>
        <div style="font-size:1.45rem;font-weight:800;color:#8b5cf6;margin-top:6px" id="yieldTrouser">~160 pcs</div>
        <div style="font-size:0.78rem;color:#64748b;font-weight:600" id="yieldTrouserTrims"><i class="fas fa-check"></i> 160 zips &amp; hooks needed</div>
        <div id="stockCheckTrouser" style="font-size:0.75rem;margin-top:4px;display:none"></div>
      </div>

      <!-- 4. Kurta -->
      <div class="yield-card" id="cardKurta" onclick="selectEstimatorCard('kurta')" title="Click to select Kurtas" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:14px;transition:all 0.2s;cursor:pointer">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <div style="font-weight:700;color:#1e293b;font-size:0.92rem"><i class="fas fa-user-tie" style="color:#64748b"></i> Kurtas</div>
          <span class="custom-badge" id="badgeKurta" style="display:none;font-size:0.7rem;background:#475569;color:#fff;padding:2px 8px;border-radius:10px;font-weight:600">Active</span>
        </div>
        <div style="font-size:0.75rem;color:#64748b;margin-top:2px" id="specKurta">2.2 meters / kurta + 4 buttons</div>
        <div style="font-size:1.45rem;font-weight:800;color:#475569;margin-top:6px" id="yieldKurta">~90 pcs</div>
        <div style="font-size:0.78rem;color:#d97706;font-weight:600" id="yieldKurtaButtons"><i class="fas fa-circle"></i> ~360 buttons needed</div>
        <div id="stockCheckKurta" style="font-size:0.75rem;margin-top:4px;display:none"></div>
      </div>
    </div>

    <!-- Apply to Batch Form Button -->
    <div style="display:flex;justify-content:flex-end;align-items:center;border-top:1px solid #dbeafe;padding-top:16px">
      <button type="button" class="btn btn-primary" onclick="applyEstimatorToBatchForm()" style="font-weight:700;padding:8px 22px;box-shadow:0 2px 4px rgba(37,99,235,0.2)">
        <i class="fas fa-arrow-down"></i> Apply to Batch Order / Production Form Below &darr;
      </button>
    </div>
  </div>
</div>

<!-- ======================================================================= -->
<!-- SECTION 2: MANUAL CONSUMPTION ENTRY FORM ("Record Material Used")       -->
<!-- ======================================================================= -->
<div class="card" id="consumptionFormCard" style="margin-bottom:24px">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
    <div>
      <span class="card-title" style="display:block;margin-bottom:2px">
        <i class="fas fa-clipboard-check text-primary"></i> Batch Order &amp; Material Consumption Entry
      </span>
      <span class="text-muted" style="font-size:0.8rem">Record firm batch order, staff size breakdown &amp; auto-deduct cloth, buttons, canvas, zips &amp; hooks</span>
    </div>
    <span class="badge" style="background:#eff6ff;color:#1d4ed8;font-weight:700">
      <i class="fas fa-building"></i> Fashion Kingdom Institutional Hub
    </span>
  </div>
  <div class="card-body">
    <form method="POST" id="consumptionForm">
      <?= csrfField() ?>

      <!-- 1. Client Firm & Batch Identification -->
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:20px">
        <h4 style="font-size:0.9rem;font-weight:700;color:#0f172a;margin-bottom:12px;display:flex;align-items:center;gap:8px">
          <i class="fas fa-building text-primary"></i> 1. Client Firm &amp; Batch Order Details
        </h4>
        <div class="form-row cols-3" style="margin-bottom:14px">
          <div class="form-group mb-0">
            <label class="form-label" style="font-weight:600">Client / Institution / Firm Name <span class="required">*</span></label>
            <input type="text" name="client_firm" id="formClientFirm" class="form-control" placeholder="e.g. G4S Security Agency, Apollo Hospital, Raymond Retail..." list="firmSuggestions" value="G4S Security Agency" required>
            <datalist id="firmSuggestions">
              <option value="G4S Security Agency">
              <option value="SIS Security Group">
              <option value="Apollo Hospitals">
              <option value="Max Healthcare">
              <option value="St. Xavier's School">
              <option value="Delhi Public School">
              <option value="Tata Motors Security Wing">
              <option value="City Hospital">
            </datalist>
          </div>
          <div class="form-group mb-0">
            <label class="form-label" style="font-weight:600">Batch Order Number</label>
            <input type="text" name="batch_order_no" id="formBatchOrderNo" class="form-control" placeholder="e.g. SEC-2026-B01" value="SEC-<?= date('Ym') ?>-01" style="font-weight:700;color:#1e40af">
          </div>
          <div class="form-group mb-0">
            <label class="form-label" style="font-weight:600">Uniform Category <span class="required">*</span></label>
            <select name="uniform_category" id="formUniformCategory" class="form-select" onchange="onUniformCategoryChange(this.value)" style="font-weight:600">
              <option value="Security Guard Uniform (Full Sleeve Shirt)">Security Guard Uniform (Full Sleeve Shirt)</option>
              <option value="Security Guard Uniform (Half Sleeve Shirt)">Security Guard Uniform (Half Sleeve Shirt)</option>
              <option value="Security Guard Trouser / Pant">Security Guard Trouser / Pant</option>
              <option value="Security Guard Set (Shirt + Pant)">Security Guard Set (Shirt + Pant)</option>
              <option value="Doctor / Hospital Apron">Doctor / Hospital Apron</option>
              <option value="Nurse Medical Scrub Suit">Nurse Medical Scrub Suit</option>
              <option value="Corporate Executive Uniform">Corporate Executive Uniform</option>
              <option value="School Uniform">School Uniform</option>
              <option value="Custom Uniform Order">Custom Uniform Order</option>
            </select>
          </div>
        </div>

        <div class="form-row cols-3">
          <div class="form-group mb-0">
            <label class="form-label">Production Date <span class="required">*</span></label>
            <input type="date" name="production_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="form-group mb-0">
            <label class="form-label">Garment Type Identifier</label>
            <input type="text" name="garment_type" id="formGarmentType" class="form-control" value="Full Sleeve Shirt" required>
            <input type="text" name="custom_garment" id="customGarmentInput" class="form-control mt-2" placeholder="e.g. Waistcoat, Blazer, Safari Suit" style="display:none">
          </div>
          <div class="form-group mb-0">
            <label class="form-label">Tailor / Master / Unit</label>
            <input type="text" name="tailor_name" class="form-control" placeholder="e.g. Master Ramesh / Cutting Table 1">
          </div>
        </div>
      </div>

      <!-- 2. Staff / Guard Size-Wise Breakdown Grid -->
      <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:10px;padding:16px;margin-bottom:20px;box-shadow:0 1px 3px rgba(0,0,0,0.03)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;flex-wrap:wrap;gap:8px">
          <div>
            <h4 style="font-size:0.9rem;font-weight:700;color:#0f172a;margin-bottom:2px;display:flex;align-items:center;gap:8px">
              <i class="fas fa-ruler-combined text-primary"></i> 2. Staff / Guard Size Breakdown &amp; Cutting Requirements
            </h4>
            <span style="font-size:0.8rem;color:#64748b">Different security guards or staff require different sizes (e.g. 38 @ 1.55m, 40 @ 1.70m, 42 @ 1.85m)</span>
          </div>
          <button type="button" class="btn btn-sm btn-outline-primary" onclick="addSizeRow()" style="font-weight:600">
            <i class="fas fa-plus-circle"></i> + Add Size Row
          </button>
        </div>

        <div class="table-wrapper" style="margin-bottom:12px">
          <table style="width:100%;font-size:0.88rem" id="sizeBreakdownTable">
            <thead>
              <tr style="background:#f1f5f9;color:#334155">
                <th style="width:22%">Size / Collar</th>
                <th style="width:20%">Sleeve / Cut</th>
                <th style="width:18%" class="text-center">Staff / Guards (Qty)</th>
                <th style="width:18%" class="text-center">Cloth / Piece (Meters)</th>
                <th style="width:17%" class="text-right">Subtotal Cloth</th>
                <th style="width:5%" class="text-center"></th>
              </tr>
            </thead>
            <tbody id="sizeRowsContainer">
              <!-- Dynamically populated size rows -->
            </tbody>
          </table>
        </div>

        <!-- Size Breakdown Totals Bar -->
        <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px 16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
          <div style="display:flex;align-items:center;gap:20px">
            <div>
              <span style="font-size:0.75rem;color:#64748b;text-transform:uppercase;font-weight:700">Total Units to Produce:</span>
              <div style="font-size:1.35rem;font-weight:800;color:#1e40af" id="totalSizeUnitsDisplay">50 pcs</div>
              <input type="hidden" name="units_produced" id="formUnitsProduced" value="50">
            </div>
            <div style="border-left:1px solid #cbd5e1;padding-left:20px">
              <span style="font-size:0.75rem;color:#64748b;text-transform:uppercase;font-weight:700">Total Fabric Needed:</span>
              <div style="font-size:1.35rem;font-weight:800;color:#059669" id="totalSizeClothDisplay">85.50 m</div>
            </div>
          </div>
          <div>
            <button type="button" class="btn btn-sm btn-primary" onclick="autoCalculateTrimsFromBreakdown()" style="font-weight:700;display:flex;align-items:center;gap:8px;box-shadow:0 2px 4px rgba(37,99,235,0.2)">
              <i class="fas fa-bolt"></i> ⚡ Auto-Calculate Required Trims &amp; Raw Materials
            </button>
          </div>
        </div>
      </div>

      <div class="form-group" style="margin-bottom:20px">
        <label class="form-label" style="font-weight:600">Cutting &amp; Batch Production Notes</label>
        <input type="text" name="notes" class="form-control" placeholder="e.g. Batch order for 50 security guards — Navy blue twill with pre-cut collar canvas">
      </div>

      <!-- Optional Link to Unnatti Boutique Product -->
      <div style="background:#f1f5f9;border-radius:8px;padding:12px 16px;margin-bottom:20px;border:1px solid #e2e8f0">
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin-bottom:0;font-weight:600;font-size:0.88rem;color:#1e293b">
          <input type="checkbox" name="add_to_finished_stock" id="chkAddToFinished" value="1" onchange="toggleFinishedProductSelect(this.checked)">
          <span><i class="fas fa-store text-primary"></i> Also automatically add finished garments to Unnatti Boutique inventory?</span>
        </label>
        <div id="finishedProductBox" style="display:none;margin-top:10px">
          <label class="form-label text-xs">Select Unnatti Ready Product / Size to increase stock:</label>
          <select name="finished_variant_id" class="form-select" style="background:#ffffff">
            <option value="">Select Boutique Finished Product...</option>
            <?php foreach ($boutiqueVariants as $bv): ?>
            <option value="<?= $bv['id'] ?>">
              <?= e($bv['product_name']) ?> — Size: <?= e($bv['size']) ?> (<?= e($bv['sku']) ?>) — Current Stock: <?= $bv['current_stock'] ?>
            </option>
            <?php endforeach; ?>
          </select>
          <small class="text-muted"><i class="fas fa-info-circle"></i> Once saved, this adds the produced pieces directly to the shop ready inventory for customer billing.</small>
        </div>
      </div>

      <!-- 3. Materials Consumed Table -->
      <div style="margin-bottom:20px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px">
          <div>
            <label class="form-label mb-0" style="font-weight:700;font-size:0.95rem;color:#0f172a">
              <i class="fas fa-layer-group text-primary"></i> 3. Materials Consumed &amp; Auto-Deducted from Stock
            </label>
            <div style="font-size:0.8rem;color:#64748b">Cloth, buttons, collar canvas, waist canvas, zips and hooks deducted from live inventory</div>
          </div>
          <button type="button" class="btn btn-sm btn-outline-primary" onclick="addMaterialRow()">
            <i class="fas fa-plus"></i> Add Another Material / Trim
          </button>
        </div>
            <i class="fas fa-plus"></i> Add Another Material / Trim
          </button>
        </div>

        <div class="table-wrapper">
          <table id="consumedItemsTable">
            <thead>
              <tr>
                <th style="width:25%">Material Type</th>
                <th style="width:35%">Select Raw Material from Stock</th>
                <th style="width:20%">Quantity / Meters Used</th>
                <th style="width:15%">Unit</th>
                <th style="width:5%"></th>
              </tr>
            </thead>
            <tbody id="materialRowsContainer">
              <!-- Material rows injected here -->
            </tbody>
          </table>
        </div>
      </div>

      <div class="d-flex justify-between align-center mt-3 pt-3" style="border-top:1px solid #e2e8f0">
        <div class="text-muted" style="font-size:0.83rem">
          <i class="fas fa-shield-alt text-success"></i> Stock will be deducted automatically from Fashion Kingdom inventory with full audit ledger tracking.
        </div>
        <button type="submit" class="btn btn-primary btn-lg">
          <i class="fas fa-check-circle"></i> Record Material Used &amp; Deduct Stock
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ======================================================================= -->
<!-- SECTION 3: RECENT MATERIAL CONSUMPTION LOGS                             -->
<!-- ======================================================================= -->
<div class="card">
  <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
    <span class="card-title"><i class="fas fa-history text-primary"></i> Past Production &amp; Material Used History</span>
    <span class="text-muted" style="font-size:0.8rem"><?= count($recentConsumptions) ?> recent batches</span>
  </div>
  <div class="card-body p-0">
    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Batch / Order #</th>
            <th>Client Firm &amp; Date</th>
            <th>Uniform Category</th>
            <th class="text-center">Units Produced</th>
            <th class="text-center">Fabric Used</th>
            <th>Size Breakdown</th>
            <th>Tailor / Notes</th>
            <th class="text-center">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recentConsumptions)): ?>
          <tr>
            <td colspan="8">
              <div class="empty-state" style="padding:30px">
                <i class="fas fa-cut"></i>
                <h4>No production batches logged yet</h4>
                <p>Use the form above to record firm orders, guard size breakdowns, and deduct cloth, buttons &amp; trims from stock.</p>
              </div>
            </td>
          </tr>
          <?php else: foreach ($recentConsumptions as $c): 
            $szArr = !empty($c['size_breakdown']) ? json_decode($c['size_breakdown'], true) : [];
          ?>
          <tr>
            <td>
              <strong style="color:#2563eb;display:block"><?= e($c['consumption_no']) ?></strong>
              <?php if (!empty($c['batch_order_no'])): ?>
                <span class="badge" style="background:#f1f5f9;color:#475569;font-size:0.72rem"><?= e($c['batch_order_no']) ?></span>
              <?php endif; ?>
            </td>
            <td>
              <div style="font-weight:700;color:#0f172a"><?= e($c['client_firm'] ?: 'Direct Mill Production') ?></div>
              <div style="font-size:0.75rem;color:#64748b"><?= formatDate($c['production_date']) ?></div>
            </td>
            <td>
              <span class="badge" style="background:#eff6ff;color:#1e40af;font-weight:600">
                <?= e($c['uniform_category'] ?: $c['garment_type']) ?>
              </span>
            </td>
            <td class="text-center" style="font-weight:800;color:#0f172a">
              <?= number_format($c['units_produced']) ?> pcs
            </td>
            <td class="text-center" style="font-weight:700;color:#059669">
              <?= number_format($c['total_fabric_meters'], 2) ?> m
            </td>
            <td style="font-size:0.78rem">
              <?php if (!empty($szArr) && is_array($szArr)): ?>
                <div style="display:flex;gap:4px;flex-wrap:wrap;max-width:180px">
                  <?php foreach ($szArr as $s): ?>
                    <span class="badge badge-light" style="font-size:0.72rem;padding:2px 6px">
                      <?= e($s['size']) ?>: <strong><?= $s['qty'] ?></strong>
                    </span>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <td style="font-size:0.82rem;color:#475569">
              <?= e($c['tailor_name'] ?: '—') ?>
              <?php if (!empty($c['notes'])): ?>
                <div style="font-size:0.73rem;color:#64748b"><?= e($c['notes']) ?></div>
              <?php endif; ?>
            </td>
            <td class="text-center">
              <button type="button" class="btn btn-xs btn-light" onclick="viewConsumptionDetails(<?= $c['id'] ?>)" title="View Used Materials & Size Breakdown">
                <i class="fas fa-eye"></i> Details
              </button>
            </td>
          </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Details Modal -->
<div class="modal-overlay" id="consumptionDetailsModal">
  <div class="modal" style="max-width:750px">
    <div class="modal-header">
      <span class="modal-title"><i class="fas fa-layer-group text-primary"></i> Production Batch &amp; Material Consumption Details</span>
      <button type="button" class="modal-close" onclick="closeModal('consumptionDetailsModal')">&times;</button>
    </div>
    <div class="modal-body" id="consumptionDetailsContent" style="max-height:calc(85vh - 130px);overflow-y:auto">
      <div style="text-align:center;padding:24px"><i class="fas fa-spinner fa-spin"></i> Loading...</div>
    </div>
    <div class="modal-footer" style="padding:12px 20px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end;background:#f8fafc">
      <button type="button" class="btn btn-secondary" onclick="closeModal('consumptionDetailsModal')">Close</button>
    </div>
  </div>
</div>

<script>
// Raw materials data for JS
const ALL_RAW_MATERIALS = <?= json_encode($rawMaterials) ?>;
const ESTIMATOR_MATERIALS_LIST = <?= json_encode($estimatorAvailableMaterials) ?>;
let rowIdx = 0;
let sizeRowIdx = 0;

function toggleCustomGarmentInput(val) {
  const inp = document.getElementById('customGarmentInput');
  if (inp) inp.style.display = (val === 'Custom') ? 'block' : 'none';
}

function toggleFinishedProductSelect(checked) {
  const box = document.getElementById('finishedProductBox');
  if (box) box.style.display = checked ? 'block' : 'none';
}

// ── Uniform Category Change Handler ──────────────────────────────────────────
function onUniformCategoryChange(cat) {
  const gTypeInp = document.getElementById('formGarmentType');
  if (gTypeInp) {
    if (cat.includes('Set')) gTypeInp.value = 'Shirt + Trouser Set';
    else if (cat.includes('Half Sleeve')) gTypeInp.value = 'Half Sleeve Shirt';
    else if (cat.includes('Full Sleeve')) gTypeInp.value = 'Full Sleeve Shirt';
    else if (cat.includes('Trouser') || cat.includes('Pant')) gTypeInp.value = 'Trouser / Pant';
    else if (cat.includes('Apron')) gTypeInp.value = 'Doctor Apron';
    else if (cat.includes('Scrub')) gTypeInp.value = 'Medical Scrub Suit';
    else gTypeInp.value = cat;
  }

  // Adjust default meters per piece in size rows
  const container = document.getElementById('sizeRowsContainer');
  let defaultMeters = 1.70;
  if (cat.includes('Half Sleeve')) defaultMeters = 1.40;
  else if (cat.includes('Trouser') || cat.includes('Pant')) defaultMeters = 1.25;
  else if (cat.includes('Set')) defaultMeters = 2.95; // 1.7m shirt + 1.25m pant
  else if (cat.includes('Apron')) defaultMeters = 2.20;

  container.querySelectorAll('tr').forEach(tr => {
    const meterInp = tr.querySelector('.size-meter-input');
    if (meterInp) {
      meterInp.value = defaultMeters.toFixed(2);
    }
  });
  recalcSizeTotals();
}

// ── Dynamic Staff / Guard Size Rows ──────────────────────────────────────────
function addSizeRow(size = '', qty = 10, meters = 1.70, style = 'Full Sleeve') {
  sizeRowIdx++;
  const container = document.getElementById('sizeRowsContainer');

  const standardSizes = [
    { label: '36 (XS)', m: 1.50 },
    { label: '38 (S)', m: 1.55 },
    { label: '40 (M)', m: 1.70 },
    { label: '42 (L)', m: 1.85 },
    { label: '44 (XL)', m: 2.00 },
    { label: '46 (XXL)', m: 2.15 },
    { label: 'Free Size', m: 1.70 },
    { label: 'Custom', m: 1.70 }
  ];

  let sizeOptions = '';
  standardSizes.forEach(s => {
    const isSel = (size && size === s.label) ? ' selected' : (!size && s.label === '40 (M)' ? ' selected' : '');
    sizeOptions += `<option value="${s.label}" data-default-meter="${s.m}" ${isSel}>${s.label}</option>`;
  });

  const tr = document.createElement('tr');
  tr.id = 'size-row-' + sizeRowIdx;
  tr.innerHTML = `
    <td>
      <select name="sizes[${sizeRowIdx}][size]" class="form-select form-select-sm" onchange="onSizeSelectChange(${sizeRowIdx}, this)" style="font-weight:600">
        ${sizeOptions}
      </select>
    </td>
    <td>
      <select name="sizes[${sizeRowIdx}][style]" class="form-select form-select-sm" onchange="onSizeStyleChange(${sizeRowIdx}, this)">
        <option value="Full Sleeve" ${style === 'Full Sleeve' ? 'selected' : ''}>Full Sleeve</option>
        <option value="Half Sleeve" ${style === 'Half Sleeve' ? 'selected' : ''}>Half Sleeve</option>
        <option value="Standard" ${style === 'Standard' ? 'selected' : ''}>Standard</option>
      </select>
    </td>
    <td>
      <input type="number" name="sizes[${sizeRowIdx}][qty]" class="form-control form-control-sm text-center size-qty-input"
             min="1" step="1" value="${qty}" oninput="recalcSizeTotals()" style="font-weight:700">
    </td>
    <td>
      <input type="number" name="sizes[${sizeRowIdx}][meters]" class="form-control form-control-sm text-center size-meter-input"
             min="0.1" step="0.05" value="${meters.toFixed(2)}" oninput="recalcSizeTotals()">
    </td>
    <td class="text-right" style="font-weight:700;color:#059669">
      <span class="size-subtotal-cloth">${(qty * meters).toFixed(2)}</span> m
    </td>
    <td class="text-center">
      <button type="button" class="btn btn-xs btn-outline-danger" onclick="removeSizeRow(${sizeRowIdx})" title="Remove size">
        <i class="fas fa-times"></i>
      </button>
    </td>
  `;

  container.appendChild(tr);
  recalcSizeTotals();
}

function onSizeSelectChange(idx, el) {
  const tr = document.getElementById('size-row-' + idx);
  if (!tr) return;
  const opt = el.options[el.selectedIndex];
  const cat = document.getElementById('formUniformCategory')?.value || '';
  let m = parseFloat(opt.getAttribute('data-default-meter')) || 1.70;

  if (cat.includes('Half Sleeve')) m = Math.max(1.1, m - 0.30);
  else if (cat.includes('Trouser') || cat.includes('Pant')) m = 1.25;
  else if (cat.includes('Set')) m = m + 1.25;

  const mInp = tr.querySelector('.size-meter-input');
  if (mInp) mInp.value = m.toFixed(2);
  recalcSizeTotals();
}

function onSizeStyleChange(idx, el) {
  const tr = document.getElementById('size-row-' + idx);
  if (!tr) return;
  const style = el.value;
  const mInp = tr.querySelector('.size-meter-input');
  if (mInp) {
    let cur = parseFloat(mInp.value) || 1.70;
    if (style === 'Half Sleeve' && cur >= 1.6) cur = 1.40;
    else if (style === 'Full Sleeve' && cur < 1.6) cur = 1.70;
    mInp.value = cur.toFixed(2);
  }
  recalcSizeTotals();
}

function removeSizeRow(idx) {
  const el = document.getElementById('size-row-' + idx);
  if (el) el.remove();
  recalcSizeTotals();
}

function recalcSizeTotals() {
  const container = document.getElementById('sizeRowsContainer');
  let totalUnits = 0;
  let totalCloth = 0.0;

  container.querySelectorAll('tr').forEach(tr => {
    const qty = parseInt(tr.querySelector('.size-qty-input')?.value, 10) || 0;
    const meter = parseFloat(tr.querySelector('.size-meter-input')?.value) || 0;
    const sub = Math.round(qty * meter * 100) / 100;
    const subEl = tr.querySelector('.size-subtotal-cloth');
    if (subEl) subEl.textContent = sub.toFixed(2);

    totalUnits += qty;
    totalCloth += sub;
  });

  const unitsDisp = document.getElementById('totalSizeUnitsDisplay');
  const clothDisp = document.getElementById('totalSizeClothDisplay');
  const formUnits = document.getElementById('formUnitsProduced');

  if (unitsDisp) unitsDisp.textContent = totalUnits + ' pcs';
  if (clothDisp) clothDisp.textContent = totalCloth.toFixed(2) + ' m';
  if (formUnits) formUnits.value = totalUnits > 0 ? totalUnits : 1;
}

// ── Auto-Calculate Raw Materials from Size Breakdown ─────────────────────────
function autoCalculateTrimsFromBreakdown() {
  recalcSizeTotals();
  const totalUnits = parseInt(document.getElementById('formUnitsProduced')?.value, 10) || 0;
  const totalCloth = parseFloat(document.getElementById('totalSizeClothDisplay')?.textContent) || 0;
  const uCat = document.getElementById('formUniformCategory')?.value || 'Security Guard Uniform';

  if (totalUnits <= 0) {
    showToast('Please add at least one size row with quantity.', 'warning');
    return;
  }

  // Clear existing items table
  const container = document.getElementById('materialRowsContainer');
  container.innerHTML = '';
  rowIdx = 0;

  // 1. Select primary fabric
  const fabricOpt = document.getElementById('estimatorFabric')?.options[0];
  const fabricId = fabricOpt ? parseInt(fabricOpt.value, 10) : '';
  addMaterialRow('fabric', fabricId, totalCloth.toFixed(2), 'meters', `Cloth covers all body, collar, cuffs & pockets for ${totalUnits} uniforms`);

  // 2. Buttons
  let buttonsPerGarment = 11;
  if (uCat.includes('Half Sleeve')) buttonsPerGarment = 8;
  else if (uCat.includes('Trouser') || uCat.includes('Pant')) buttonsPerGarment = 1;
  else if (uCat.includes('Apron')) buttonsPerGarment = 5;
  else if (uCat.includes('Set')) buttonsPerGarment = 12; // 11 shirt + 1 pant

  const totalButtons = totalUnits * buttonsPerGarment;
  const btnItem = ALL_RAW_MATERIALS.find(m => m.material_name.toLowerCase().includes('shirt button') || m.material_name.toLowerCase().includes('button'));
  if (btnItem) {
    addMaterialRow('button', btnItem.id, totalButtons, 'pieces', `${buttonsPerGarment} buttons per garment (${totalUnits} pieces)`);
  }

  // 3. Shirt Collar Canvas (pre-cut canvas fusing ~15 pcs/packet)
  if (!uCat.includes('Trouser') && !uCat.includes('Pant')) {
    const collarCanvasItem = ALL_RAW_MATERIALS.find(m => m.material_name.toLowerCase().includes('collar canvas') || m.sku.includes('COLLAR') || m.material_name.toLowerCase().includes('fusing'));
    if (collarCanvasItem) {
      addMaterialRow('other', collarCanvasItem.id, totalUnits, 'pieces', `1 pre-cut collar canvas piece per shirt (${totalUnits} pcs)`);
    }
  }

  // 4. Pant Waist Canvas (Roll / Packet) & Zips & Hooks
  if (uCat.includes('Trouser') || uCat.includes('Pant') || uCat.includes('Set')) {
    // Waist Canvas Roll
    const waistCanvasItem = ALL_RAW_MATERIALS.find(m => m.material_name.toLowerCase().includes('waist canvas') || m.sku.includes('WST'));
    if (waistCanvasItem) {
      addMaterialRow('other', waistCanvasItem.id, totalUnits, 'meters', `1 meter waistband canvas roll per trouser (${totalUnits} meters)`);
    }

    // Zipper
    const zipItem = ALL_RAW_MATERIALS.find(m => m.material_name.toLowerCase().includes('zip') || m.sku.includes('ZIP'));
    if (zipItem) {
      addMaterialRow('zipper', zipItem.id, totalUnits, 'pieces', `1 pant metal zip per trouser (${totalUnits} pcs)`);
    }

    // Waist Hook
    const hookItem = ALL_RAW_MATERIALS.find(m => m.material_name.toLowerCase().includes('hook') || m.sku.includes('HOOK'));
    if (hookItem) {
      addMaterialRow('hook', hookItem.id, totalUnits, 'sets', `1 waist hook set per trouser (${totalUnits} sets)`);
    }
  }

  // Scroll to materials section
  const table = document.getElementById('consumedItemsTable');
  if (table) {
    table.scrollIntoView({ behavior: 'smooth' });
  }

  if (typeof showToast === 'function') {
    showToast(`Calculated all raw materials & trims for ${totalUnits} uniforms!`, 'success');
  }
}

// ── Estimator Logic (Moved from Materials to Production Page) ────────────────
const SIZE_CLOTH_RATIOS = {
  full_shirt: {
    '36': 1.45,
    '38': 1.55,
    '40': 1.70,
    '42': 1.85,
    '44': 2.00,
    '46': 2.15,
    'free': 1.70,
    'custom': 1.70
  },
  half_shirt: {
    '36': 1.25,
    '38': 1.30,
    '40': 1.40,
    '42': 1.50,
    '44': 1.60,
    '46': 1.70,
    'free': 1.40,
    'custom': 1.40
  },
  tshirt: {
    '36': 1.05,
    '38': 1.10,
    '40': 1.20,
    '42': 1.30,
    '44': 1.40,
    '46': 1.50,
    'free': 1.20,
    'custom': 1.20
  },
  trouser: {
    '36': 1.15,
    '38': 1.20,
    '40': 1.25,
    '42': 1.30,
    '44': 1.35,
    '46': 1.40,
    'free': 1.25,
    'custom': 1.25
  },
  apron: {
    '36': 1.70,
    '38': 1.80,
    '40': 2.00,
    '42': 2.10,
    '44': 2.20,
    '46': 2.30,
    'free': 2.00,
    'custom': 2.00
  },
  kurta: {
    '36': 1.90,
    '38': 2.00,
    '40': 2.20,
    '42': 2.30,
    '44': 2.40,
    '46': 2.50,
    'free': 2.20,
    'custom': 2.20
  },
  custom: {
    '36': 1.50,
    '38': 1.50,
    '40': 1.50,
    '42': 1.50,
    '44': 1.50,
    '46': 1.50,
    'free': 1.50,
    'custom': 1.50
  }
};

let selectedAdditionalMaterials = [];
let activeAppliedGarment = null;

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function getEstimatorFabricMeters() {
  const fabricMaterials = selectedAdditionalMaterials.filter(x => {
    const n = (x.name || '').toLowerCase();
    const u = (x.unit || '').toLowerCase();
    const isTrim = n.includes('waist') || n.includes('canvas') || n.includes('button') || n.includes('zip') || n.includes('hook');
    return x.id === 'manual_fabric' || (!isTrim && (n.includes('cloth') || n.includes('fabric') || u.includes('meter')));
  });
  if (fabricMaterials.length === 0) return 0;
  return fabricMaterials.reduce((acc, x) => {
    const q = (x.allocatedQty !== undefined && x.allocatedQty !== null && x.allocatedQty !== '')
      ? parseFloat(x.allocatedQty)
      : parseFloat(x.stock || 0);
    return acc + (isNaN(q) ? 0 : q);
  }, 0);
}

function addMaterialToEstimator() {
  const sel = document.getElementById('estimatorAddMaterialSelect');
  const val = sel.value;
  if (!val) {
    if (typeof showToast === 'function') showToast('Please select a material first.', 'warning');
    return;
  }

  if (val === 'manual_fabric') {
    quickAddTrim('manual_fabric');
    sel.value = '';
    return;
  }

  const opt = sel.options[sel.selectedIndex];
  const id = val;
  const name = opt.getAttribute('data-name');
  const sku = opt.getAttribute('data-sku') || '';
  const stock = parseFloat(opt.getAttribute('data-stock')) || 0;
  const unit = opt.getAttribute('data-unit') || 'Pcs';

  if (selectedAdditionalMaterials.some(m => String(m.id) === String(id))) {
    if (typeof showToast === 'function') showToast('Material is already added.', 'info');
    return;
  }

  selectedAdditionalMaterials.push({ id, name, sku, stock, unit, allocatedQty: stock });
  renderSelectedMaterialsCards();
  sel.value = '';
  recalcEstimatorYield();
  if (activeAppliedGarment) {
    applyCustomMeter();
  }
  if (typeof showToast === 'function') showToast('Added ' + name + ' to estimator.', 'success');
}

function quickAddTrim(type) {
  let item = null;
  const list = (typeof ESTIMATOR_MATERIALS_LIST !== 'undefined' && ESTIMATOR_MATERIALS_LIST.length > 0) 
    ? ESTIMATOR_MATERIALS_LIST 
    : ALL_RAW_MATERIALS;

  if (type === 'fabric') {
    item = list.find(m => {
      const n = (m.product_name || m.material_name || '').toLowerCase();
      const isTrim = n.includes('waist') || n.includes('canvas') || n.includes('button') || n.includes('zip') || n.includes('hook');
      return !isTrim && (n.includes('cloth') || n.includes('fabric') || (m.size || m.unit || '').toLowerCase().includes('meter'));
    });
    if (!item) {
      quickAddTrim('manual_fabric');
      return;
    }
  } else if (type === 'manual_fabric') {
    if (selectedAdditionalMaterials.some(m => m.id === 'manual_fabric')) {
      if (typeof showToast === 'function') showToast('Manual cloth roll is already added.', 'info');
      return;
    }
    selectedAdditionalMaterials.push({
      id: 'manual_fabric',
      name: 'Manual Cloth / Fabric',
      sku: 'MANUAL-CLOTH',
      stock: 99999,
      unit: 'meters',
      allocatedQty: 100
    });
    renderSelectedMaterialsCards();
    recalcEstimatorYield();
    if (activeAppliedGarment) applyCustomMeter();
    if (typeof showToast === 'function') showToast('Added Manual Cloth (100 meters) to calculation.', 'success');
    return;
  } else if (type === 'button') {
    item = list.find(m => (m.product_name || m.material_name || '').toLowerCase().includes('shirt button') || (m.sku && m.sku.includes('SHIRT-BTN')));
    if (!item) item = list.find(m => (m.product_name || m.material_name || '').toLowerCase().includes('button'));
  } else if (type === 'collar') {
    item = list.find(m => (m.product_name || m.material_name || '').toLowerCase().includes('collar canvas') || (m.sku && m.sku.includes('COLLAR')));
  } else if (type === 'pant_btn') {
    item = list.find(m => (m.product_name || m.material_name || '').toLowerCase().includes('pant button') || (m.sku && m.sku.includes('PANT-BTN')));
  } else if (type === 'waist') {
    item = list.find(m => (m.product_name || m.material_name || '').toLowerCase().includes('waist canvas') || (m.sku && m.sku.includes('WST')));
  } else if (type === 'zip') {
    item = list.find(m => (m.product_name || m.material_name || '').toLowerCase().includes('zip') || (m.sku && m.sku.includes('ZIP')));
  } else if (type === 'hook') {
    item = list.find(m => (m.product_name || m.material_name || '').toLowerCase().includes('hook') || (m.sku && m.sku.includes('HOOK')));
  }

  if (item) {
    const id = item.id;
    const name = item.product_name || item.material_name;
    const sku = item.sku || '';
    const stock = parseFloat(item.current_stock) || 0;
    const unit = item.size || item.unit || 'Pcs';

    if (selectedAdditionalMaterials.some(m => String(m.id) === String(id))) {
      if (typeof showToast === 'function') showToast(name + ' is already added.', 'info');
      return;
    }

    selectedAdditionalMaterials.push({ id, name, sku, stock, unit, allocatedQty: stock });
    renderSelectedMaterialsCards();
    recalcEstimatorYield();
    if (activeAppliedGarment) {
      applyCustomMeter();
    }
    if (typeof showToast === 'function') showToast('Added ' + name + ' to estimator.', 'success');
  } else {
    if (typeof showToast === 'function') showToast('Material not found in inventory.', 'warning');
  }
}

function removeMaterialFromEstimator(idx) {
  selectedAdditionalMaterials.splice(idx, 1);
  renderSelectedMaterialsCards();
  recalcEstimatorYield();
  if (activeAppliedGarment) {
    applyCustomMeter();
  }
}

function updateAllocatedMaterialQty(idx, val, inputEl) {
  if (!selectedAdditionalMaterials[idx]) return;
  const m = selectedAdditionalMaterials[idx];
  const isManual = (m.id === 'manual_fabric');
  const maxStock = isManual ? Infinity : (parseFloat(m.stock) || 0);
  let numVal = parseFloat(val);
  const msgEl = document.getElementById('stockExceededMsg_' + idx);

  if (!isManual && !isNaN(numVal) && numVal > maxStock) {
    if (inputEl) inputEl.value = maxStock;
    m.allocatedQty = maxStock;
    if (msgEl) {
      msgEl.style.display = 'inline-flex';
      msgEl.innerHTML = `<i class="fas fa-ban"></i> Out of stock / Exceeds stock! Maximum available is <strong>${maxStock} ${escapeHtml(m.unit)}</strong>. Input reset to available stock.`;
      setTimeout(() => { if (msgEl) msgEl.style.display = 'none'; }, 4500);
    }
    if (typeof showToast === 'function') {
      showToast(`Out of stock! Maximum available stock for ${m.name} is ${maxStock} ${m.unit}.`, 'danger');
    }
  } else if (!isNaN(numVal) && numVal < 0) {
    if (inputEl) inputEl.value = 0;
    m.allocatedQty = 0;
    if (msgEl) msgEl.style.display = 'none';
  } else {
    m.allocatedQty = isNaN(numVal) ? 0 : numVal;
    if (msgEl) msgEl.style.display = 'none';
  }

  recalcEstimatorYield();
  if (activeAppliedGarment) {
    applyCustomMeter();
  }
}

function validateAllocatedMaterialQty(idx, inputEl) {
  if (!selectedAdditionalMaterials[idx]) return;
  const m = selectedAdditionalMaterials[idx];
  const isManual = (m.id === 'manual_fabric');
  const maxStock = isManual ? Infinity : (parseFloat(m.stock) || 0);
  let val = parseFloat(inputEl.value);
  if (isNaN(val) || val < 0) {
    inputEl.value = 0;
    m.allocatedQty = 0;
  } else if (!isManual && val > maxStock) {
    inputEl.value = maxStock;
    m.allocatedQty = maxStock;
  }
  recalcEstimatorYield();
  if (activeAppliedGarment) {
    applyCustomMeter();
  }
}

function renderSelectedMaterialsCards() {
  const container = document.getElementById('estimatorSelectedMaterialsList');
  if (!container) return;

  if (selectedAdditionalMaterials.length === 0) {
    container.innerHTML = `
      <div style="background:#fff;border:1px dashed #cbd5e1;padding:14px 16px;border-radius:8px;font-size:0.84rem;color:#64748b;display:flex;align-items:center;gap:10px">
        <i class="fas fa-layer-group text-primary" style="font-size:1.1rem"></i>
        <span>No materials added yet. Choose fabric, buttons, collar canvas, zippers or hooks above to calculate production yield.</span>
      </div>
    `;
    return;
  }

  let html = '';
  selectedAdditionalMaterials.forEach((m, idx) => {
    const isManual = (m.id === 'manual_fabric');
    const isFabric = isManual || (m.name.toLowerCase().includes('cloth') || m.name.toLowerCase().includes('fabric') || ((m.unit && m.unit.toLowerCase().includes('meter')) && !m.name.toLowerCase().includes('waist') && !m.name.toLowerCase().includes('canvas')));
    const isBtn = m.name.toLowerCase().includes('button') || m.sku.includes('BTN');
    const isZip = m.name.toLowerCase().includes('zip');
    const isHook = m.name.toLowerCase().includes('hook');
    const isCollar = m.name.toLowerCase().includes('collar') || m.sku.includes('COLLAR');
    const isWaist = m.name.toLowerCase().includes('waist') || m.sku.includes('WST');

    let icon = isFabric ? '🧵' : (isBtn ? '🔘' : (isZip ? '🤐' : (isHook ? '🪝' : (isCollar ? '📐' : (isWaist ? '📏' : '📦')))));
    let roleBadge = isFabric ? 'Cloth / Fabric Roll' : (isBtn ? 'Buttons & Fasteners' : (isZip ? 'Zippers' : (isHook ? 'Hooks & Eyes' : (isCollar ? 'Collar Canvas Fusing' : (isWaist ? 'Waistband Canvas' : 'Trim / Accessory')))));
    let cardBg = isFabric ? '#eff6ff' : (isBtn ? '#f0fdf4' : (isCollar ? '#fefce8' : (isZip || isHook ? '#faf5ff' : '#f8fafc')));
    let cardBorder = isFabric ? '#bfdbfe' : (isBtn ? '#bbf7d0' : (isCollar ? '#fef08a' : (isZip || isHook ? '#e9d5ff' : '#cbd5e1')));
    let badgeColor = isFabric ? '#1e40af' : (isBtn ? '#166534' : (isCollar ? '#854d0e' : (isZip || isHook ? '#6b21a8' : '#334155')));

    const currentQty = (m.allocatedQty !== undefined) ? m.allocatedQty : m.stock;
    const stockDisplay = isManual
      ? `Custom / Manual Entry`
      : `<strong style="font-size:1.15rem">${m.stock.toLocaleString()}</strong> ${escapeHtml(m.unit)}`;

    html += `
      <div style="background:${cardBg};padding:14px 18px;border-radius:10px;border:1px solid ${cardBorder};margin-bottom:12px;box-shadow:0 1px 2px rgba(0,0,0,0.03);position:relative">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
          <div style="flex:1;min-width:220px">
            <div style="font-size:0.75rem;color:${badgeColor};text-transform:uppercase;font-weight:700;letter-spacing:0.5px">
              ${icon} Selected Raw Material: <span style="font-weight:600;opacity:0.85">${roleBadge}</span>
            </div>
            <div style="font-size:1.15rem;font-weight:800;color:#0f172a;margin-top:2px">
              ${escapeHtml(m.name)} <span style="font-size:0.8rem;color:#64748b;font-weight:600">(${escapeHtml(m.sku)})</span>
            </div>
            <div style="font-size:0.88rem;color:${badgeColor};margin-top:2px">
              Available in Stock: ${stockDisplay}
            </div>
          </div>
          <div>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeMaterialFromEstimator(${idx})" style="font-size:0.78rem;font-weight:600;padding:4px 12px;border-radius:6px;display:flex;align-items:center;gap:4px">
              <i class="fas fa-trash-alt"></i> Remove
            </button>
          </div>
        </div>

        <!-- Material Units Input inside the Card -->
        <div style="margin-top:12px;padding-top:10px;border-top:1px dashed ${cardBorder};display:flex;align-items:center;gap:12px;flex-wrap:wrap">
          <label for="matQtyInput_${idx}" style="font-weight:600;font-size:0.86rem;color:${badgeColor};margin-bottom:0">
            Enter ${escapeHtml(m.name)} Units for Calculation:
          </label>
          <div style="display:flex;align-items:center;gap:6px">
            <input type="number" id="matQtyInput_${idx}" class="form-control form-control-sm" step="${isFabric ? '0.1' : '1'}" min="0" ${isManual ? '' : `max="${m.stock}"`} value="${currentQty}" oninput="updateAllocatedMaterialQty(${idx}, this.value, this)" onblur="validateAllocatedMaterialQty(${idx}, this)" style="font-size:1.05rem;font-weight:700;width:150px;background:#fff">
            <span style="font-weight:700;color:${badgeColor};font-size:0.88rem">${escapeHtml(m.unit)}</span>
          </div>
          <div id="stockExceededMsg_${idx}" style="display:none;width:100%;color:#b91c1c;font-weight:700;font-size:0.78rem;background:#fee2e2;padding:4px 10px;border-radius:4px;border:1px solid #fca5a5;align-items:center;gap:6px;margin-top:4px"></div>
        </div>
      </div>
    `;
  });

  container.innerHTML = html;
}

function onGarmentOrSizeChange() {
  const gSel = document.getElementById('estimatorGarmentSelect');
  const sSel = document.getElementById('estimatorGarmentSize');
  const meterInput = document.getElementById('estimatorManualMeter');
  const btnInput = document.getElementById('estimatorManualButtons');
  if (!gSel || !sSel || !meterInput) return;

  const gVal = gSel.value;
  const sVal = sSel.value;

  // Set default buttons for selected garment if user switches garment
  if (btnInput) {
    if (gVal === 'full_shirt') btnInput.value = 11;
    else if (gVal === 'half_shirt') btnInput.value = 8;
    else if (gVal === 'tshirt') btnInput.value = 2;
    else if (gVal === 'trouser') btnInput.value = 1;
    else if (gVal === 'apron') btnInput.value = 5;
    else if (gVal === 'kurta') btnInput.value = 4;
    else if (gVal === 'custom') btnInput.value = 0;
  }

  if (sVal === 'custom') {
    meterInput.focus();
  } else if (SIZE_CLOTH_RATIOS[gVal] && SIZE_CLOTH_RATIOS[gVal][sVal] !== undefined) {
    meterInput.value = SIZE_CLOTH_RATIOS[gVal][sVal].toFixed(2);
  }

  // Real-time update to the four tabs in the grid
  recalcEstimatorYield();

  if (activeAppliedGarment) {
    applyCustomMeter();
  }
}

function onManualMeterInput() {
  // Real-time reflect entered meters to the 4 tabs grid
  recalcEstimatorYield();
  if (activeAppliedGarment) {
    applyCustomMeter();
  }
}

function onManualButtonsInput() {
  // Real-time reflect entered buttons to the 4 tabs grid
  recalcEstimatorYield();
  if (activeAppliedGarment) {
    applyCustomMeter();
  }
}

function applyCustomMeter() {
  const fabricMeters = getEstimatorFabricMeters();
  const meterPerPc = parseFloat(document.getElementById('estimatorManualMeter').value) || 0;
  const gSel = document.getElementById('estimatorGarmentSelect');
  const sSel = document.getElementById('estimatorGarmentSize');
  const gOpt = gSel.options[gSel.selectedIndex];
  const sOpt = sSel.options[sSel.selectedIndex];
  const garmentKey = gSel.value;
  const sizeKey = sSel.value;
  const garmentLabel = gOpt && gOpt.value ? (gOpt.getAttribute('data-label') || gOpt.text) : 'Custom Garment';
  const sizeLabel = sOpt ? sOpt.text : sizeKey;

  if (meterPerPc <= 0) {
    if (typeof showToast === 'function') showToast('Please enter a valid meter per piece (e.g. 1.7, 1.4).', 'warning');
    document.getElementById('estimatorManualMeter').focus();
    return;
  }

  if (fabricMeters <= 0) {
    if (typeof showToast === 'function') showToast('Please add a fabric/cloth material above first to calculate yield.', 'warning');
    return;
  }

  activeAppliedGarment = { key: garmentKey, label: garmentLabel, size: sizeKey, meter: meterPerPc };

  // Max yield based on fabric roll
  const pcsFromCloth = fabricMeters > 0 ? Math.floor(fabricMeters / meterPerPc) : 0;

  // Evaluate trimming requirements for each added material
  let materialRequirements = [];
  let bottleneckMaxPcs = pcsFromCloth;
  let bottleneckName = 'Fabric Roll';

  selectedAdditionalMaterials.forEach(m => {
    const nameLow = m.name.toLowerCase();
    const sku = m.sku || '';
    const allocQty = (m.allocatedQty !== undefined) ? m.allocatedQty : m.stock;

    let perPcRatio = 0;
    let ratioDesc = '';
    let isApplicable = false;
    let maxSupportedPcs = Infinity;

    if (nameLow.includes('button') || sku.includes('BTN')) {
      isApplicable = true;
      const customBtnsInput = parseFloat(document.getElementById('estimatorManualButtons')?.value);
      let btnRate = (!isNaN(customBtnsInput) && customBtnsInput >= 0) ? customBtnsInput : 11;
      perPcRatio = btnRate;
      if (btnRate > 0) {
        ratioDesc = btnRate + ' buttons/pc';
      } else {
        ratioDesc = '0 buttons/pc';
        isApplicable = false;
      }
    } else if (nameLow.includes('collar') || sku.includes('COLLAR')) {
      if (garmentKey === 'full_shirt' || garmentKey === 'half_shirt') {
        isApplicable = true;
        const isPacket15 = m.unit.toLowerCase().includes('15');
        perPcRatio = isPacket15 ? (1 / 15) : 1;
        ratioDesc = isPacket15 ? '1 collar pc / shirt (~1/15 packet)' : '1 collar pc / shirt';
      }
    } else if (nameLow.includes('zip') || sku.includes('ZIP')) {
      if (garmentKey === 'trouser') {
        isApplicable = true;
        perPcRatio = 1;
        ratioDesc = '1 zipper / trouser';
      }
    } else if (nameLow.includes('hook') || sku.includes('HOOK')) {
      if (garmentKey === 'trouser') {
        isApplicable = true;
        perPcRatio = 1;
        ratioDesc = '1 hook set / trouser';
      }
    } else if (nameLow.includes('waist') || sku.includes('WST')) {
      if (garmentKey === 'trouser') {
        isApplicable = true;
        perPcRatio = 1.0;
        ratioDesc = '1m waist canvas / trouser';
      }
    }

    if (isApplicable && perPcRatio > 0) {
      const neededForClothRun = Math.ceil(pcsFromCloth * perPcRatio);
      maxSupportedPcs = Math.floor(allocQty / perPcRatio);

      if (maxSupportedPcs < bottleneckMaxPcs) {
        bottleneckMaxPcs = maxSupportedPcs;
        bottleneckName = m.name;
      }

      materialRequirements.push({
        id: m.id,
        name: m.name,
        sku: m.sku,
        unit: m.unit,
        allocated: allocQty,
        stock: m.stock,
        needed: neededForClothRun,
        maxPcs: maxSupportedPcs,
        perPcRatio,
        ratioDesc,
        isSufficient: (allocQty >= neededForClothRun),
        shortage: Math.max(0, neededForClothRun - allocQty)
      });
    }
  });

  const finalFeasiblePcs = Math.min(pcsFromCloth, bottleneckMaxPcs);
  const usedCloth = (finalFeasiblePcs * meterPerPc).toFixed(1);
  const leftoverCloth = Math.max(0, fabricMeters - (finalFeasiblePcs * meterPerPc)).toFixed(1);
  const hasShortage = materialRequirements.some(r => !r.isSufficient);

  // Build Results UI
  const resultBox = document.getElementById('appliedCustomResult');
  resultBox.style.display = 'block';

  let rowsHtml = '';
  // Fabric row
  const primaryFabric = selectedAdditionalMaterials.find(x => x.id === 'manual_fabric' || (x.name.toLowerCase().includes('cloth') || x.name.toLowerCase().includes('fabric') || (x.unit && x.unit.toLowerCase().includes('meter'))));
  const fabricDisplayName = primaryFabric ? primaryFabric.name : 'Main Fabric Roll';
  rowsHtml += `
    <tr style="border-bottom:1px solid #e2e8f0;background:#eff6ff">
      <td style="padding:10px 12px;font-weight:700;color:#1e40af">
        <i class="fas fa-scroll text-primary" style="margin-right:6px"></i> ${escapeHtml(fabricDisplayName)}
      </td>
      <td style="padding:10px 12px;font-size:0.83rem;color:#475569">
        ${meterPerPc}m per piece
      </td>
      <td style="padding:10px 12px;font-weight:700;color:#0f172a" class="text-center">
        ${fabricMeters} meters
      </td>
      <td style="padding:10px 12px;font-weight:700;color:#2563eb" class="text-center">
        ${usedCloth} meters
      </td>
      <td style="padding:10px 12px;font-weight:600;color:#64748b" class="text-center">
        ${leftoverCloth}m remaining
      </td>
      <td style="padding:10px 12px" class="text-center">
        <span class="badge badge-success" style="font-size:0.75rem">Sufficient (${pcsFromCloth} pcs)</span>
      </td>
    </tr>
  `;

  // Material rows
  materialRequirements.forEach(req => {
    const statusBadge = req.isSufficient
      ? `<span class="badge badge-success" style="font-size:0.75rem"><i class="fas fa-check"></i> Sufficient</span>`
      : `<span class="badge badge-danger" style="font-size:0.75rem"><i class="fas fa-exclamation-triangle"></i> Short by ${req.shortage} ${escapeHtml(req.unit)}</span>`;

    const balanceText = req.isSufficient
      ? `<span style="color:#15803d;font-weight:600">+${req.allocated - req.needed} ${escapeHtml(req.unit)}</span>`
      : `<span style="color:#b91c1c;font-weight:700;background:#fee2e2;padding:2px 6px;border-radius:4px">-${req.shortage} ${escapeHtml(req.unit)}</span>`;

    rowsHtml += `
      <tr style="border-bottom:1px solid #e2e8f0;background:#fff">
        <td style="padding:10px 12px;font-weight:600;color:#1e293b">
          ${escapeHtml(req.name)} <span style="font-size:0.75rem;color:#64748b">(${escapeHtml(req.sku)})</span>
        </td>
        <td style="padding:10px 12px;font-size:0.82rem;color:#64748b">
          ${req.ratioDesc}
        </td>
        <td style="padding:10px 12px;font-weight:700;color:#0f172a" class="text-center">
          ${req.allocated} ${escapeHtml(req.unit)}
        </td>
        <td style="padding:10px 12px;font-weight:700;color:#2563eb" class="text-center">
          ${req.needed} ${escapeHtml(req.unit)}
        </td>
        <td style="padding:10px 12px" class="text-center">
          ${balanceText}
        </td>
        <td style="padding:10px 12px" class="text-center">
          ${statusBadge}
        </td>
      </tr>
    `;
  });

  let verdictBox = '';
  if (!hasShortage) {
    verdictBox = `
      <div style="background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;padding:12px 16px;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
        <div>
          <span style="color:#065f46;font-weight:800;font-size:1.15rem">
            <i class="fas fa-check-circle" style="color:#10b981;margin-right:6px"></i> Feasible Production Run: ~${finalFeasiblePcs.toLocaleString()} Complete Garments
          </span>
          <div style="color:#047857;font-size:0.83rem;margin-top:2px">
            All raw materials, cloth roll and trims are 100% sufficient to complete this batch.
          </div>
        </div>
        <button type="button" class="btn btn-sm btn-success" onclick="applyEstimatorToBatchForm()" style="font-weight:700;padding:6px 16px;font-size:0.84rem">
          <i class="fas fa-arrow-down"></i> Apply to Batch Order Below &darr;
        </button>
      </div>
    `;
  } else {
    verdictBox = `
      <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 16px;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
        <div>
          <span style="color:#92400e;font-weight:800;font-size:1.15rem">
            <i class="fas fa-exclamation-triangle" style="color:#f59e0b;margin-right:6px"></i> Stock Bottleneck Detected: ~${finalFeasiblePcs.toLocaleString()} pcs Feasible
          </span>
          <div style="color:#b45309;font-size:0.83rem;margin-top:2px">
            Cloth roll allows <strong>~${pcsFromCloth} pcs</strong>, but <strong>${escapeHtml(bottleneckName)}</strong> stock limits finished production to <strong>~${finalFeasiblePcs} pcs</strong>.
          </div>
        </div>
        <button type="button" class="btn btn-sm btn-primary" onclick="applyEstimatorToBatchForm()" style="font-weight:700;padding:6px 16px;font-size:0.84rem">
          <i class="fas fa-arrow-down"></i> Apply ~${finalFeasiblePcs} pcs to Batch Form &darr;
        </button>
      </div>
    `;
  }

  resultBox.innerHTML = `
    <div style="background:#ffffff;border:1px solid #0284c7;border-radius:10px;padding:16px;box-shadow:0 4px 6px -1px rgba(0,0,0,0.1)">
      ${verdictBox}

      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px">
        <div>
          <span style="font-size:0.75rem;font-weight:700;color:#0284c7;text-transform:uppercase;letter-spacing:0.5px">Rough Yield Calculation Breakdown</span>
          <div style="font-size:1.1rem;font-weight:800;color:#0f172a">
            ${escapeHtml(garmentLabel)} &bull; <span style="font-size:0.95rem;color:#475569;font-weight:600">${escapeHtml(sizeLabel)}</span>
          </div>
        </div>
        <div style="display:flex;gap:16px;font-size:0.85rem">
          <div>Fabric Rate: <strong>${meterPerPc}m / pc</strong></div>
          <div>Cloth Used: <strong style="color:#2563eb">${usedCloth}m</strong></div>
          <div>Cloth Remaining: <strong style="color:#059669">${leftoverCloth}m</strong></div>
        </div>
      </div>

      <div class="table-responsive" style="margin-top:8px">
        <table class="table table-sm" style="margin-bottom:0;font-size:0.85rem;border:1px solid #e2e8f0;border-radius:6px;overflow:hidden">
          <thead>
            <tr style="background:#f1f5f9;color:#334155;font-weight:700">
              <th style="padding:8px 12px">Material / Roll</th>
              <th style="padding:8px 12px">Usage Spec</th>
              <th style="padding:8px 12px" class="text-center">Units Entered for Run</th>
              <th style="padding:8px 12px" class="text-center">Required for Batch</th>
              <th style="padding:8px 12px" class="text-center">Balance / Shortage</th>
              <th style="padding:8px 12px" class="text-center">Sufficiency Status</th>
            </tr>
          </thead>
          <tbody>
            ${rowsHtml}
          </tbody>
        </table>
      </div>
    </div>
  `;

  highlightMatchingCard(garmentKey, finalFeasiblePcs, meterPerPc);
}

function clearCustomMeter() {
  activeAppliedGarment = null;
  document.getElementById('appliedCustomResult').style.display = 'none';

  const gSel = document.getElementById('estimatorGarmentSelect');
  if (gSel) gSel.value = 'full_shirt';
  const sSel = document.getElementById('estimatorGarmentSize');
  if (sSel) sSel.value = '40';
  const mInp = document.getElementById('estimatorManualMeter');
  if (mInp) mInp.value = '1.70';
  const bInp = document.getElementById('estimatorManualButtons');
  if (bInp) bInp.value = '11';

  ['cardFullShirt', 'cardHalfShirt', 'cardTrouser', 'cardKurta'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      el.style.border = '1px solid #e2e8f0';
      el.style.boxShadow = 'none';
      el.style.background = '#f8fafc';
    }
  });
  ['badgeFullShirt', 'badgeHalfShirt', 'badgeTrouser', 'badgeKurta'].forEach(id => {
    const b = document.getElementById(id);
    if (b) b.style.display = 'none';
  });

  recalcEstimatorYield();
}

function highlightMatchingCard(key, customPcs, customMeter) {
  ['cardFullShirt', 'cardHalfShirt', 'cardTrouser', 'cardKurta'].forEach(id => {
    const el = document.getElementById(id);
    if (el) {
      el.style.border = '1px solid #e2e8f0';
      el.style.boxShadow = 'none';
      el.style.background = '#f8fafc';
    }
  });
  ['badgeFullShirt', 'badgeHalfShirt', 'badgeTrouser', 'badgeKurta'].forEach(id => {
    const b = document.getElementById(id);
    if (b) b.style.display = 'none';
  });

  let cardId = null;
  let badgeId = null;
  if (key === 'full_shirt') { cardId = 'cardFullShirt'; badgeId = 'badgeFullShirt'; }
  else if (key === 'half_shirt') { cardId = 'cardHalfShirt'; badgeId = 'badgeHalfShirt'; }
  else if (key === 'trouser') { cardId = 'cardTrouser'; badgeId = 'badgeTrouser'; }
  else if (key === 'kurta') { cardId = 'cardKurta'; badgeId = 'badgeKurta'; }

  if (cardId) {
    const c = document.getElementById(cardId);
    if (c) {
      c.style.border = '2px solid #2563eb';
      c.style.boxShadow = '0 0 0 3px rgba(37,99,235,0.15)';
      c.style.background = '#eff6ff';
    }
    const b = document.getElementById(badgeId);
    if (b) b.style.display = 'inline-block';
  }
}

function selectEstimatorCard(garmentKey) {
  const gSel = document.getElementById('estimatorGarmentSelect');
  if (gSel) {
    gSel.value = garmentKey;
    onGarmentOrSizeChange();
    applyCustomMeter();
  }
}

function recalcEstimatorYield() {
  const m = getEstimatorFabricMeters();
  const gSel = document.getElementById('estimatorGarmentSelect');
  const activeGarment = gSel ? gSel.value : 'full_shirt';
  const customMeter = parseFloat(document.getElementById('estimatorManualMeter')?.value);
  const customBtns = parseFloat(document.getElementById('estimatorManualButtons')?.value);
  
  // Helper to get entered quantity or default stock
  const getQty = (x) => {
    if (x.allocatedQty !== undefined && x.allocatedQty !== null && x.allocatedQty !== '') {
      const q = parseFloat(x.allocatedQty);
      return isNaN(q) ? 0 : q;
    }
    return parseFloat(x.stock || 0);
  };

  // Filter materials by role / name
  const shirtBtns = selectedAdditionalMaterials.filter(x => (x.name.toLowerCase().includes('shirt button') || (x.sku && x.sku.includes('SHIRT-BTN'))));
  const pantBtns = selectedAdditionalMaterials.filter(x => (x.name.toLowerCase().includes('pant button') || (x.sku && x.sku.includes('PANT-BTN'))));
  const allBtns = selectedAdditionalMaterials.filter(x => (x.name.toLowerCase().includes('button') || (x.sku && x.sku.includes('BTN'))));
  
  const collarMats = selectedAdditionalMaterials.filter(x => (x.name.toLowerCase().includes('collar') || (x.sku && x.sku.includes('COLLAR'))));
  const zipMats = selectedAdditionalMaterials.filter(x => (x.name.toLowerCase().includes('zip') || (x.sku && x.sku.includes('ZIP'))));
  const hookMats = selectedAdditionalMaterials.filter(x => (x.name.toLowerCase().includes('hook') || (x.sku && x.sku.includes('HOOK'))));
  const waistCanvasMats = selectedAdditionalMaterials.filter(x => (x.name.toLowerCase().includes('waist') || (x.sku && x.sku.includes('WST'))));

  const totalShirtBtnStock = (shirtBtns.length > 0 ? shirtBtns : allBtns).reduce((acc, x) => acc + getQty(x), 0);
  const totalPantBtnStock = (pantBtns.length > 0 ? pantBtns : allBtns).reduce((acc, x) => acc + getQty(x), 0);
  const totalAllBtnStock = allBtns.reduce((acc, x) => acc + getQty(x), 0);
  
  const totalCollarPcs = collarMats.reduce((acc, x) => {
    const isPkt15 = (x.unit && x.unit.toLowerCase().includes('15'));
    const q = getQty(x);
    return acc + (isPkt15 ? (q * 15) : q);
  }, 0);

  const totalZipStock = zipMats.reduce((acc, x) => acc + getQty(x), 0);
  const totalHookStock = hookMats.reduce((acc, x) => acc + getQty(x), 0);
  const totalWaistCanvasStock = waistCanvasMats.reduce((acc, x) => acc + getQty(x), 0);

  // ─────────────────────────────────────────────────────────────
  // 1. Full Sleeve Shirts: dynamic cloth meter + dynamic buttons
  // ─────────────────────────────────────────────────────────────
  const rateFull = (activeGarment === 'full_shirt' && !isNaN(customMeter) && customMeter > 0) ? customMeter : 1.70;
  const btnsFull = (activeGarment === 'full_shirt' && !isNaN(customBtns) && customBtns >= 0) ? customBtns : 11;
  const full = m > 0 ? Math.floor(m / rateFull) : 0;
  const fullBtnNeed = full * btnsFull;
  const fullCollarNeed = full;

  const specFullEl = document.getElementById('specFullShirt');
  if (specFullEl) specFullEl.textContent = `${rateFull} meters / shirt + ${btnsFull} buttons`;
  document.getElementById('yieldFullShirt').textContent = '~' + full.toLocaleString() + ' pcs';
  document.getElementById('yieldFullShirtButtons').innerHTML = m > 0
    ? ('<i class="fas fa-circle"></i> ~' + fullBtnNeed.toLocaleString() + ' buttons needed')
    : '<span style="color:#64748b;font-weight:500"><i class="fas fa-info-circle text-primary"></i> Add fabric/cloth above to calculate</span>';

  let scFullHtml = [];
  let maxFullTrims = full;

  if (m > 0 && allBtns.length > 0) {
    const maxWithBtn = btnsFull > 0 ? Math.floor(totalShirtBtnStock / btnsFull) : full;
    maxFullTrims = Math.min(maxFullTrims, maxWithBtn);
    if (totalShirtBtnStock >= fullBtnNeed) {
      scFullHtml.push(`<div style="color:#15803d;font-weight:600"><i class="fas fa-check-circle"></i> Button stock sufficient (${totalShirtBtnStock.toLocaleString()} entered)</div>`);
    } else {
      scFullHtml.push(`<div style="color:#b91c1c;font-weight:600"><i class="fas fa-exclamation-triangle"></i> Short by ${(fullBtnNeed - totalShirtBtnStock).toLocaleString()} btn (Entered: ${totalShirtBtnStock.toLocaleString()}, max ~${maxWithBtn} shirts)</div>`);
    }
  }

  if (m > 0 && collarMats.length > 0) {
    const maxWithCollar = Math.floor(totalCollarPcs);
    maxFullTrims = Math.min(maxFullTrims, maxWithCollar);
    if (totalCollarPcs >= fullCollarNeed) {
      scFullHtml.push(`<div style="color:#15803d;font-weight:600"><i class="fas fa-check-circle"></i> Collar canvas sufficient (${totalCollarPcs.toLocaleString()} pcs entered)</div>`);
    } else {
      scFullHtml.push(`<div style="color:#b91c1c;font-weight:600"><i class="fas fa-exclamation-triangle"></i> Short by ${(fullCollarNeed - totalCollarPcs).toLocaleString()} collar canvas (Entered: ${totalCollarPcs} pcs, max ~${maxWithCollar} shirts)</div>`);
    }
  }

  if (full > 0 && maxFullTrims < full && (allBtns.length > 0 || collarMats.length > 0)) {
    scFullHtml.push(`<div style="margin-top:3px;padding:3px 6px;background:#fee2e2;border-radius:4px;color:#991b1b;font-weight:700;font-size:0.75rem"><i class="fas fa-lock"></i> Limited to ~${maxFullTrims} pcs with entered trims</div>`);
  }

  const scFull = document.getElementById('stockCheckFullShirt');
  if (scFull) {
    if (scFullHtml.length > 0) {
      scFull.style.display = 'block';
      scFull.innerHTML = scFullHtml.join('');
    } else {
      scFull.style.display = 'none';
    }
  }

  // ─────────────────────────────────────────────────────────────
  // 2. Half Sleeve Shirts: dynamic cloth meter + dynamic buttons
  // ─────────────────────────────────────────────────────────────
  const rateHalf = (activeGarment === 'half_shirt' && !isNaN(customMeter) && customMeter > 0) ? customMeter : 1.40;
  const btnsHalf = (activeGarment === 'half_shirt' && !isNaN(customBtns) && customBtns >= 0) ? customBtns : 8;
  const half = m > 0 ? Math.floor(m / rateHalf) : 0;
  const halfBtnNeed = half * btnsHalf;
  const halfCollarNeed = half;

  const specHalfEl = document.getElementById('specHalfShirt');
  if (specHalfEl) specHalfEl.textContent = `${rateHalf} meters / shirt + ${btnsHalf} buttons`;
  document.getElementById('yieldHalfShirt').textContent = '~' + half.toLocaleString() + ' pcs';
  document.getElementById('yieldHalfShirtButtons').innerHTML = m > 0
    ? ('<i class="fas fa-circle"></i> ~' + halfBtnNeed.toLocaleString() + ' buttons needed')
    : '<span style="color:#64748b;font-weight:500"><i class="fas fa-info-circle text-primary"></i> Add fabric/cloth above to calculate</span>';

  let scHalfHtml = [];
  let maxHalfTrims = half;

  if (m > 0 && allBtns.length > 0) {
    const maxWithBtn = btnsHalf > 0 ? Math.floor(totalShirtBtnStock / btnsHalf) : half;
    maxHalfTrims = Math.min(maxHalfTrims, maxWithBtn);
    if (totalShirtBtnStock >= halfBtnNeed) {
      scHalfHtml.push(`<div style="color:#15803d;font-weight:600"><i class="fas fa-check-circle"></i> Button stock sufficient (${totalShirtBtnStock.toLocaleString()} entered)</div>`);
    } else {
      scHalfHtml.push(`<div style="color:#b91c1c;font-weight:600"><i class="fas fa-exclamation-triangle"></i> Short by ${(halfBtnNeed - totalShirtBtnStock).toLocaleString()} btn (Entered: ${totalShirtBtnStock.toLocaleString()}, max ~${maxWithBtn} shirts)</div>`);
    }
  }

  if (m > 0 && collarMats.length > 0) {
    const maxWithCollar = Math.floor(totalCollarPcs);
    maxHalfTrims = Math.min(maxHalfTrims, maxWithCollar);
    if (totalCollarPcs >= halfCollarNeed) {
      scHalfHtml.push(`<div style="color:#15803d;font-weight:600"><i class="fas fa-check-circle"></i> Collar canvas sufficient (${totalCollarPcs.toLocaleString()} pcs entered)</div>`);
    } else {
      scHalfHtml.push(`<div style="color:#b91c1c;font-weight:600"><i class="fas fa-exclamation-triangle"></i> Short by ${(halfCollarNeed - totalCollarPcs).toLocaleString()} collar canvas (Entered: ${totalCollarPcs} pcs, max ~${maxWithCollar} shirts)</div>`);
    }
  }

  if (half > 0 && maxHalfTrims < half && (allBtns.length > 0 || collarMats.length > 0)) {
    scHalfHtml.push(`<div style="margin-top:3px;padding:3px 6px;background:#fee2e2;border-radius:4px;color:#991b1b;font-weight:700;font-size:0.75rem"><i class="fas fa-lock"></i> Limited to ~${maxHalfTrims} pcs with entered trims</div>`);
  }

  const scHalf = document.getElementById('stockCheckHalfShirt');
  if (scHalf) {
    if (scHalfHtml.length > 0) {
      scHalf.style.display = 'block';
      scHalf.innerHTML = scHalfHtml.join('');
    } else {
      scHalf.style.display = 'none';
    }
  }

  // ─────────────────────────────────────────────────────────────
  // 3. Trousers / Pants: dynamic cloth meter + 1 zip + 1 hook + dynamic button
  // ─────────────────────────────────────────────────────────────
  const rateTrouser = (activeGarment === 'trouser' && !isNaN(customMeter) && customMeter > 0) ? customMeter : 1.25;
  const btnsTrouser = (activeGarment === 'trouser' && !isNaN(customBtns) && customBtns >= 0) ? customBtns : 1;
  const trs = m > 0 ? Math.floor(m / rateTrouser) : 0;
  const trsBtnNeed = trs * btnsTrouser;

  const specTrouserEl = document.getElementById('specTrouser');
  if (specTrouserEl) specTrouserEl.textContent = `${rateTrouser} meters / pant + 1 zip + 1 hook` + (btnsTrouser > 0 ? ` + ${btnsTrouser} button` : '');
  document.getElementById('yieldTrouser').textContent = '~' + trs.toLocaleString() + ' pcs';
  document.getElementById('yieldTrouserTrims').innerHTML = m > 0
    ? ('<i class="fas fa-check"></i> ' + trs.toLocaleString() + ' zips & hooks needed' + (btnsTrouser > 0 ? ` (~${trsBtnNeed} btn)` : ''))
    : '<span style="color:#64748b;font-weight:500"><i class="fas fa-info-circle text-primary"></i> Add cloth/fabric above to calculate</span>';

  let scTrsHtml = [];
  let maxTrsTrims = trs;

  if (m > 0 && zipMats.length > 0) {
    maxTrsTrims = Math.min(maxTrsTrims, totalZipStock);
    if (totalZipStock >= trs) {
      scTrsHtml.push(`<div style="color:#15803d;font-weight:600"><i class="fas fa-check-circle"></i> Zips sufficient (${totalZipStock.toLocaleString()} entered)</div>`);
    } else {
      scTrsHtml.push(`<div style="color:#b91c1c;font-weight:600"><i class="fas fa-exclamation-triangle"></i> Short by ${(trs - totalZipStock).toLocaleString()} zips (Entered: ${totalZipStock.toLocaleString()}, max ~${totalZipStock} pants)</div>`);
    }
  }

  if (m > 0 && hookMats.length > 0) {
    maxTrsTrims = Math.min(maxTrsTrims, totalHookStock);
    if (totalHookStock >= trs) {
      scTrsHtml.push(`<div style="color:#15803d;font-weight:600"><i class="fas fa-check-circle"></i> Hooks sufficient (${totalHookStock.toLocaleString()} entered)</div>`);
    } else {
      scTrsHtml.push(`<div style="color:#b91c1c;font-weight:600"><i class="fas fa-exclamation-triangle"></i> Short by ${(trs - totalHookStock).toLocaleString()} hooks (Entered: ${totalHookStock.toLocaleString()}, max ~${totalHookStock} pants)</div>`);
    }
  }

  if (m > 0 && waistCanvasMats.length > 0) {
    const maxWithWaist = Math.floor(totalWaistCanvasStock);
    maxTrsTrims = Math.min(maxTrsTrims, maxWithWaist);
    if (totalWaistCanvasStock >= trs) {
      scTrsHtml.push(`<div style="color:#15803d;font-weight:600"><i class="fas fa-check-circle"></i> Waist canvas sufficient (${totalWaistCanvasStock.toLocaleString()}m entered)</div>`);
    } else {
      scTrsHtml.push(`<div style="color:#b91c1c;font-weight:600"><i class="fas fa-exclamation-triangle"></i> Short by ${(trs - totalWaistCanvasStock).toFixed(1)}m canvas (Entered: ${totalWaistCanvasStock}m, max ~${maxWithWaist} pants)</div>`);
    }
  }

  if (m > 0 && pantBtns.length > 0 && btnsTrouser > 0) {
    const maxWithBtn = Math.floor(totalPantBtnStock / btnsTrouser);
    maxTrsTrims = Math.min(maxTrsTrims, maxWithBtn);
    if (totalPantBtnStock >= trsBtnNeed) {
      scTrsHtml.push(`<div style="color:#15803d;font-weight:600"><i class="fas fa-check-circle"></i> Pant buttons sufficient (${totalPantBtnStock.toLocaleString()} entered)</div>`);
    } else {
      scTrsHtml.push(`<div style="color:#b91c1c;font-weight:600"><i class="fas fa-exclamation-triangle"></i> Short by ${(trsBtnNeed - totalPantBtnStock).toLocaleString()} buttons (Entered: ${totalPantBtnStock.toLocaleString()}, max ~${maxWithBtn} pants)</div>`);
    }
  }

  if (trs > 0 && maxTrsTrims < trs && (zipMats.length > 0 || hookMats.length > 0 || waistCanvasMats.length > 0 || pantBtns.length > 0)) {
    scTrsHtml.push(`<div style="margin-top:3px;padding:3px 6px;background:#fee2e2;border-radius:4px;color:#991b1b;font-weight:700;font-size:0.75rem"><i class="fas fa-lock"></i> Limited to ~${maxTrsTrims} pcs with entered trims</div>`);
  }

  const scTrs = document.getElementById('stockCheckTrouser');
  if (scTrs) {
    if (scTrsHtml.length > 0) {
      scTrs.style.display = 'block';
      scTrs.innerHTML = scTrsHtml.join('');
    } else {
      scTrs.style.display = 'none';
    }
  }

  // ─────────────────────────────────────────────────────────────
  // 4. Kurtas: dynamic cloth meter + dynamic buttons
  // ─────────────────────────────────────────────────────────────
  const rateKurta = (activeGarment === 'kurta' && !isNaN(customMeter) && customMeter > 0) ? customMeter : 2.20;
  const btnsKurta = (activeGarment === 'kurta' && !isNaN(customBtns) && customBtns >= 0) ? customBtns : 4;
  const krt = m > 0 ? Math.floor(m / rateKurta) : 0;
  const krtBtnNeed = krt * btnsKurta;

  const specKurtaEl = document.getElementById('specKurta');
  if (specKurtaEl) specKurtaEl.textContent = `${rateKurta} meters / kurta + ${btnsKurta} buttons`;
  document.getElementById('yieldKurta').textContent = '~' + krt.toLocaleString() + ' pcs';
  document.getElementById('yieldKurtaButtons').innerHTML = m > 0
    ? ('<i class="fas fa-circle"></i> ~' + krtBtnNeed.toLocaleString() + ' buttons needed')
    : '<span style="color:#64748b;font-weight:500"><i class="fas fa-info-circle text-primary"></i> Add fabric/cloth above to calculate</span>';

  let scKrtHtml = [];
  let maxKrtTrims = krt;

  if (m > 0 && allBtns.length > 0) {
    const maxWithBtn = btnsKurta > 0 ? Math.floor(totalAllBtnStock / btnsKurta) : krt;
    maxKrtTrims = Math.min(maxKrtTrims, maxWithBtn);
    if (totalAllBtnStock >= krtBtnNeed) {
      scKrtHtml.push(`<div style="color:#15803d;font-weight:600"><i class="fas fa-check-circle"></i> Button stock sufficient (${totalAllBtnStock.toLocaleString()} entered)</div>`);
    } else {
      scKrtHtml.push(`<div style="color:#b91c1c;font-weight:600"><i class="fas fa-exclamation-triangle"></i> Short by ${(krtBtnNeed - totalAllBtnStock).toLocaleString()} buttons (Entered: ${totalAllBtnStock.toLocaleString()}, max ~${maxWithBtn} kurtas)</div>`);
    }
  }

  if (krt > 0 && maxKrtTrims < krt && allBtns.length > 0) {
    scKrtHtml.push(`<div style="margin-top:3px;padding:3px 6px;background:#fee2e2;border-radius:4px;color:#991b1b;font-weight:700;font-size:0.75rem"><i class="fas fa-lock"></i> Limited to ~${maxKrtTrims} pcs with entered trims</div>`);
  }

  const scKrt = document.getElementById('stockCheckKurta');
  if (scKrt) {
    if (scKrtHtml.length > 0) {
      scKrt.style.display = 'block';
      scKrt.innerHTML = scKrtHtml.join('');
    } else {
      scKrt.style.display = 'none';
    }
  }

  // If custom applied, re-apply it with new fabric meters
  if (activeAppliedGarment) {
    applyCustomMeter();
  }
}

function applyEstimatorToBatchForm() {
  const formCard = document.getElementById('consumptionFormCard');
  if (!formCard) return;

  // Garment selection
  const gSel = document.getElementById('estimatorGarmentSelect');
  const gVal = gSel ? gSel.value : '';

  if (gVal === 'full_shirt') {
    const uc = document.getElementById('formUniformCategory');
    if (uc) uc.value = 'Security Guard Uniform (Full Sleeve Shirt)';
  } else if (gVal === 'half_shirt') {
    const uc = document.getElementById('formUniformCategory');
    if (uc) uc.value = 'Security Guard Uniform (Half Sleeve Shirt)';
  } else if (gVal === 'trouser') {
    const uc = document.getElementById('formUniformCategory');
    if (uc) uc.value = 'Security Guard Uniform (Trousers / Pants)';
  }

  // Scroll smoothly to Section 2
  formCard.scrollIntoView({ behavior: 'smooth' });

  if (typeof showToast === 'function') {
    showToast('Estimator values mapped to Batch Order form! Check guard sizes & trims below.', 'success');
  }
}

// ── Dynamic Material Rows ───────────────────────────────────────────────────
function addMaterialRow(role = 'fabric', preselectId = '', defaultQty = '', defaultUnit = 'meters', notes = '') {
  rowIdx++;
  const container = document.getElementById('materialRowsContainer');

  let optionsHtml = '<option value="">Select Material from Stock...</option>';
  ALL_RAW_MATERIALS.forEach(m => {
    const isSel = (preselectId && String(m.id) === String(preselectId)) ? ' selected' : '';
    optionsHtml += `<option value="${m.id}" data-stock="${m.current_stock}" data-unit="${m.unit}" ${isSel}>
      ${m.material_name} (${m.sku}) — Stock: ${m.current_stock} ${m.unit}
    </option>`;
  });

  const tr = document.createElement('tr');
  tr.id = 'mat-row-' + rowIdx;
  tr.innerHTML = `
    <td>
      <select name="items[${rowIdx}][material_role]" class="form-select form-select-sm" style="font-weight:600">
        <option value="fabric" ${role === 'fabric' ? 'selected' : ''}>Cloth / Fabric (Meters)</option>
        <option value="button" ${role === 'button' ? 'selected' : ''}>Buttons (Pieces)</option>
        <option value="zipper" ${role === 'zipper' ? 'selected' : ''}>Zipper (Pieces)</option>
        <option value="hook" ${role === 'hook' ? 'selected' : ''}>Waist Hook (Sets)</option>
        <option value="other" ${role === 'other' ? 'selected' : ''}>Other / Canvas / Trims</option>
      </select>
    </td>
    <td>
      <select name="items[${rowIdx}][variant_id]" class="form-select form-select-sm" onchange="onMaterialSelected(${rowIdx}, this)" required>
        ${optionsHtml}
      </select>
      <div id="stock-hint-${rowIdx}" style="font-size:0.75rem;color:#059669;margin-top:3px;font-weight:600"></div>
    </td>
    <td>
      <input type="number" name="items[${rowIdx}][qty_used]" id="qty-input-${rowIdx}" class="form-control form-control-sm"
             step="0.01" min="0.01" required placeholder="0.00" value="${defaultQty}" style="font-weight:700">
    </td>
    <td>
      <input type="text" name="items[${rowIdx}][unit]" id="unit-input-${rowIdx}" class="form-control form-control-sm"
             value="${defaultUnit}" placeholder="meters/pcs">
      <input type="hidden" name="items[${rowIdx}][notes]" value="${notes}">
    </td>
    <td class="text-center">
      <button type="button" class="btn btn-xs btn-outline-danger" onclick="removeMaterialRow(${rowIdx})" title="Remove">
        <i class="fas fa-trash"></i>
      </button>
    </td>
  `;

  container.appendChild(tr);

  // Trigger stock hint if preselected
  const sel = tr.querySelector(`select[name="items[${rowIdx}][variant_id]"]`);
  if (sel && sel.value) {
    onMaterialSelected(rowIdx, sel);
  }
}

function onMaterialSelected(idx, selectEl) {
  const opt = selectEl.options[selectEl.selectedIndex];
  const hint = document.getElementById('stock-hint-' + idx);
  const unitInp = document.getElementById('unit-input-' + idx);
  if (!opt || !opt.value) {
    if (hint) hint.textContent = '';
    return;
  }
  const stock = parseFloat(opt.getAttribute('data-stock')) || 0;
  const unit = opt.getAttribute('data-unit') || 'meters';
  const qtyInp = document.getElementById('qty-input-' + idx);
  const qty = parseFloat(qtyInp?.value) || 0;

  if (stock < qty) {
    if (hint) {
      hint.innerHTML = `<span style="color:#dc2626"><i class="fas fa-exclamation-triangle"></i> Short stock! Available: ${stock} ${unit}</span>`;
    }
  } else {
    if (hint) hint.textContent = `Available Stock: ${stock} ${unit}`;
  }

  if (unitInp && (!unitInp.value || unitInp.value === 'meters')) {
    unitInp.value = unit;
  }
}

function removeMaterialRow(idx) {
  const el = document.getElementById('mat-row-' + idx);
  if (el) el.remove();
}

// ── View Batch & Consumption Details ─────────────────────────────────────────
function viewConsumptionDetails(id) {
  openModal('consumptionDetailsModal');
  const content = document.getElementById('consumptionDetailsContent');
  if (content) content.innerHTML = '<div style="text-align:center;padding:24px"><i class="fas fa-spinner fa-spin"></i> Loading details...</div>';

  apiCall(window.APP_URL + '/api/get_consumption_details.php', 'GET', { id: id }).then(res => {
    if (res.success && res.data) {
      const c = res.data.consumption;
      const items = res.data.items || [];
      let itemsRows = '';
      items.forEach(it => {
        itemsRows += `
          <tr>
            <td><strong>${it.material_name}</strong> <span style="font-size:0.75rem;color:#64748b">(${it.sku})</span></td>
            <td><span class="badge badge-light">${it.material_role}</span></td>
            <td class="text-right" style="font-weight:700;color:#059669">${it.qty_used} ${it.unit}</td>
            <td style="font-size:0.8rem;color:#64748b">${it.notes || '—'}</td>
          </tr>
        `;
      });

      // Parse size breakdown
      let sizeRows = '';
      if (c.size_breakdown) {
        try {
          const sArr = typeof c.size_breakdown === 'string' ? JSON.parse(c.size_breakdown) : c.size_breakdown;
          if (Array.isArray(sArr) && sArr.length > 0) {
            sArr.forEach(s => {
              sizeRows += `
                <tr>
                  <td><strong>${s.size}</strong></td>
                  <td>${s.style || 'Standard'}</td>
                  <td class="text-center" style="font-weight:700">${s.qty} guards</td>
                  <td class="text-center">${s.meters} m</td>
                  <td class="text-right" style="font-weight:700;color:#059669">${s.total_cloth} m</td>
                </tr>
              `;
            });
          }
        } catch (e) {}
      }

      content.innerHTML = `
        <div style="background:#f8fafc;padding:16px;border-radius:10px;margin-bottom:16px;border:1px solid #e2e8f0">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px">
            <div>
              <div style="font-size:1.15rem;font-weight:800;color:#0f172a">${c.client_firm || 'Direct Mill Production'}</div>
              <div style="font-size:0.8rem;color:#2563eb;font-weight:600">Batch Order #: ${c.batch_order_no || c.consumption_no} (${c.consumption_no})</div>
            </div>
            <span class="badge badge-primary" style="font-size:0.85rem">${c.uniform_category || c.garment_type}</span>
          </div>
          <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:10px;font-size:0.83rem;color:#475569;border-top:1px dashed #cbd5e1;padding-top:10px">
            <div>Production Date: <strong>${c.production_date}</strong></div>
            <div>Total Uniforms: <strong style="color:#0f172a;font-size:0.95rem">${c.units_produced} pcs</strong></div>
            <div>Total Cloth: <strong style="color:#059669;font-size:0.95rem">${c.total_fabric_meters} m</strong></div>
            <div>Tailor / Master: <strong>${c.tailor_name || '—'}</strong></div>
          </div>
          ${c.notes ? `<div style="font-size:0.8rem;color:#64748b;margin-top:8px;background:#ffffff;padding:6px 10px;border-radius:6px;border:1px solid #e2e8f0"><strong>Notes:</strong> ${c.notes}</div>` : ''}
        </div>

        ${sizeRows ? `
          <h5 style="margin-bottom:8px;font-size:0.88rem;color:#1e293b"><i class="fas fa-ruler-combined text-primary"></i> Staff / Guard Size Breakdown</h5>
          <div class="table-wrapper" style="margin-bottom:16px">
            <table>
              <thead>
                <tr style="background:#f1f5f9">
                  <th>Size</th>
                  <th>Style</th>
                  <th class="text-center">Staff Qty</th>
                  <th class="text-center">Meters / Pcs</th>
                  <th class="text-right">Total Cloth</th>
                </tr>
              </thead>
              <tbody>${sizeRows}</tbody>
            </table>
          </div>
        ` : ''}

        <h5 style="margin-bottom:8px;font-size:0.88rem;color:#1e293b"><i class="fas fa-boxes text-success"></i> Raw Materials &amp; Trims Deducted from Stock</h5>
        <div class="table-wrapper">
          <table>
            <thead>
              <tr style="background:#f1f5f9">
                <th>Material</th>
                <th>Role</th>
                <th class="text-right">Qty Used &amp; Deducted</th>
                <th>Remarks</th>
              </tr>
            </thead>
            <tbody>${itemsRows}</tbody>
          </table>
        </div>
      `;
    } else {
      content.innerHTML = '<div class="alert alert-danger">Could not load consumption details.</div>';
    }
  }).catch(() => {
    content.innerHTML = '<div class="alert alert-danger">Network error loading consumption details.</div>';
  });
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) {
    el.classList.remove('active');
    el.style.display = 'none';
  }
  document.body.style.overflow = '';
}

// Initial setup
document.addEventListener('DOMContentLoaded', function() {
  selectedAdditionalMaterials = [];
  renderSelectedMaterialsCards();
  recalcEstimatorYield();

  // Populate standard size breakdown for 50 security guard uniforms
  addSizeRow('38 (S)', 10, 1.55, 'Full Sleeve');
  addSizeRow('40 (M)', 20, 1.70, 'Full Sleeve');
  addSizeRow('42 (L)', 15, 1.85, 'Full Sleeve');
  addSizeRow('44 (XL)', 5, 2.00, 'Full Sleeve');

  // Auto-calculate trims for initial sizes
  autoCalculateTrimsFromBreakdown();
});
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
