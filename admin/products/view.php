<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
$product = db()->fetchOne(
    "SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON p.category_id=c.id WHERE p.id=?",
    [$id]
);
if (!$product) { $_SESSION['flash_error']='Product not found.'; header('Location:'.APP_URL.'/admin/products/index.php'); exit; }

$variants = db()->fetchAll(
    "SELECT * FROM product_variants WHERE product_id=? ORDER BY id",
    [$id]
);
$totalStock = array_sum(array_column($variants, 'current_stock'));
$movements = db()->fetchAll(
    "SELECT sm.*, pv.sku, u.full_name AS employee, sm.created_at
     FROM stock_movements sm JOIN product_variants pv ON sm.variant_id=pv.id LEFT JOIN users u ON sm.created_by=u.id
     WHERE pv.product_id=? ORDER BY sm.id DESC LIMIT 10",
    [$id]
);

$pageTitle = e($product['name']); $activePage='products';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<script>window.APP_URL='<?= APP_URL ?>';</script>

<!-- Stock Adjustment Modal -->
<div class="modal-overlay" id="stockAdjustModal">
  <div class="modal modal-sm">
    <div class="modal-header">
      <span class="modal-title"><i class="fas fa-boxes text-primary"></i> Adjust Stock</span>
      <button class="modal-close" onclick="closeModal('stockAdjustModal')">&times;</button>
    </div>
    <div class="modal-body">
      <div style="background:#f1f5f9;padding:12px;border-radius:6px;margin-bottom:16px">
        <div style="font-size:0.82rem;color:#64748b">Product / SKU</div>
        <div style="font-weight:600;color:#1e293b" id="adjustSku"></div>
      </div>
      
      <?php if (count($variants) > 1): ?>
      <div class="form-group">
        <label class="form-label">Select Size / Variant <span class="required">*</span></label>
        <select id="adjustVariantSelect" class="form-select" onchange="onVariantSelectChange(this.value)">
          <?php foreach ($variants as $v): ?>
          <option value="<?= $v['id'] ?>" data-sku="<?= e($v['sku']) ?>" data-size="<?= e($v['size']) ?>" data-stock="<?= (int)$v['current_stock'] ?>">
            <?= e($v['size']) ?> (<?= e($v['sku']) ?>) — Current: <?= (int)$v['current_stock'] ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="form-group">
        <label class="form-label">Current Stock</label>
        <input type="number" id="currentStockDisplay" class="form-control" readonly disabled style="background:#f8fafc">
      </div>

      <div class="form-group">
        <label class="form-label">Adjustment Type <span class="required">*</span></label>
        <select id="adjustType" class="form-select" onchange="updateAdjustPreview()">
          <option value="add">Add Stock (+)</option>
          <option value="subtract">Remove Stock (-)</option>
        </select>
      </div>

      <div class="form-group">
        <label class="form-label">Quantity <span class="required">*</span></label>
        <input type="number" id="adjustQty" class="form-control" min="1" value="1" oninput="updateAdjustPreview()" required>
      </div>

      <div style="background:#eff6ff;padding:12px;border-radius:6px;border-left:3px solid #2563eb;margin-bottom:0">
        <div style="font-size:0.85rem;color:#1e40af;font-weight:500">New Stock: <span id="newStockPreview" style="font-size:1.1rem">0</span></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-light" onclick="closeModal('stockAdjustModal')">Cancel</button>
      <button class="btn btn-primary" id="confirmAdjustBtn" onclick="confirmStockAdjust()">
        <i class="fas fa-check"></i> Adjust Stock
      </button>
    </div>
  </div>
</div>

<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title"><i class="fas fa-box text-primary" style="margin-right:10px"></i><?= e($product['name']) ?></h1>
    <p class="page-subtitle"><?= e($product['category_name'] ?? 'Uncategorized') ?> &bull; <span class="badge badge-<?= $product['status']==='active'?'success':'danger' ?>"><?= ucfirst($product['status']) ?></span></p>
  </div>
  <div class="page-header-right">
    <a href="<?= APP_URL ?>/admin/products/edit.php?id=<?= $id ?>" class="btn btn-outline-primary btn-sm"><i class="fas fa-edit"></i> Edit</a>
    <button type="button" class="btn btn-primary btn-sm" onclick="openAddStock()"><i class="fas fa-sliders-h"></i> Adjust</button>
    <a href="<?= APP_URL ?>/admin/products/index.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
  </div>
</div>

<div class="grid-2">
  <div>
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fas fa-info-circle"></i> Product Details</span></div>
      <div class="card-body">
        <?php if ($product['image'] && file_exists(UPLOAD_PATH.'products/'.$product['image'])): ?>
        <div style="margin-bottom:16px"><img src="<?= APP_URL ?>/uploads/products/<?= e($product['image']) ?>" style="max-height:160px;border-radius:10px;border:1px solid #e2e8f0"></div>
        <?php endif; ?>
        <table style="width:100%;border-collapse:collapse">
          <?php
          $fields = [
            'Category' => $product['category_name'] ?? '—',
          ];
          if (!empty($product['brand'])) {
            $fields['Brand'] = $product['brand'];
          }
          if (!empty($product['description'])) {
            $fields['Description'] = $product['description'];
          }
          $fields['Total Sizes / Units'] = count($variants);
          $fields['Total Stock'] = number_format($totalStock) . ' units';
          $fields['Added'] = formatDate($product['created_at']);
          foreach ($fields as $label => $val):
          ?>
          <tr style="border-bottom:1px solid #f1f5f9"><td style="padding:9px 0;color:#64748b;font-size:0.82rem;font-weight:600;width:140px"><?= $label ?></td><td style="padding:9px 0;font-size:0.85rem"><?= e($val) ?></td></tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>

  <div>
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fas fa-layer-group"></i> Variants & Pricing</span></div>
      <div class="table-wrapper">
        <?php
          $hasCost = false;
          foreach ($variants as $v) {
            if ($v['purchase_price_with_gst'] > 0) {
              $hasCost = true;
              break;
            }
          }
        ?>
        <table>
          <thead>
            <tr>
              <th>SKU</th>
              <th>Unit / Size</th>
              <?php if ($hasCost): ?>
                <th class="text-right">Cost+GST</th>
                <th class="text-right">Sell Price</th>
                <th class="text-right">Margin%</th>
              <?php else: ?>
                <th class="text-right">Selling Price / MRP</th>
                <th class="text-center">Min Alert</th>
              <?php endif; ?>
              <th class="text-center">Stock</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($variants as $v):
              $margin = $v['purchase_price_with_gst'] > 0 ? round(($v['selling_price']-$v['purchase_price_with_gst'])/$v['selling_price']*100,1) : 0;
            ?>
            <tr>
              <td><span class="sku-badge"><?= e($v['sku']) ?></span></td>
              <td><strong><?= e($v['size']) ?></strong></td>
              <?php if ($hasCost): ?>
                <td class="text-right"><?= formatCurrency($v['purchase_price_with_gst']) ?></td>
                <td class="text-right text-bold" style="color:#2563eb"><?= formatCurrency($v['selling_price']) ?></td>
                <td class="text-right"><span class="badge badge-<?= $margin>=20?'success':($margin>=10?'warning':'danger') ?>"><?= $margin ?>%</span></td>
              <?php else: ?>
                <td class="text-right text-bold" style="color:#2563eb;font-size:0.95rem"><?= formatCurrency($v['selling_price']) ?></td>
                <td class="text-center text-muted"><?= (int)$v['min_stock_alert'] ?></td>
              <?php endif; ?>
              <td class="text-center">
                <?php if ($v['current_stock']==0): ?><span class="badge badge-danger">0</span>
                <?php elseif ($v['current_stock']<=$v['min_stock_alert']): ?><span class="badge badge-warning"><?= $v['current_stock'] ?></span>
                <?php else: ?><span class="badge badge-success"><?= $v['current_stock'] ?></span><?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Stock Movements -->
<div class="card">
  <div class="card-header">
    <span class="card-title"><i class="fas fa-exchange-alt"></i> Recent Stock Movements</span>
    <a href="<?= APP_URL ?>/admin/stock/movements.php?variant_id=<?= $variants[0]['id']??0 ?>" class="btn btn-sm btn-light">View All</a>
  </div>
  <div class="table-wrapper">
    <table>
      <thead><tr><th>Date/Time</th><th>SKU</th><th class="text-center">Type</th><th class="text-center">Change</th><th class="text-center">After</th><th>Notes</th><th>By</th></tr></thead>
      <tbody>
        <?php foreach ($movements as $m): ?>
        <tr>
          <td style="font-size:0.8rem"><?= formatDateTime($m['created_at']) ?></td>
          <td><span class="sku-badge"><?= e($m['sku']) ?></span></td>
          <td class="text-center"><span class="badge badge-<?= $m['movement_type']==='purchase'?'success':($m['movement_type']==='sale'?'primary':'warning') ?>"><?= ucfirst($m['movement_type']) ?></span></td>
          <td class="text-center <?= $m['qty_change']>0?'text-success':'text-danger' ?>" style="font-weight:700"><?= $m['qty_change']>0?'+':''?><?= $m['qty_change'] ?></td>
          <td class="text-center text-bold"><?= $m['qty_after'] ?></td>
          <td style="font-size:0.8rem"><?= e($m['notes']??'') ?></td>
          <td style="font-size:0.8rem"><?= e($m['employee']??'System') ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($movements)): ?><tr><td colspan="7" class="text-center text-muted" style="padding:20px">No movements yet</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
const allVariants = <?= json_encode(array_map(function($v) {
    return [
        'id'    => (int)$v['id'],
        'sku'   => $v['sku'],
        'size'  => $v['size'],
        'stock' => (int)$v['current_stock']
    ];
}, $variants)) ?>;

let adjustVariantId = null;
let adjustCurrentStock = 0;

function openAddStock() {
  if (!allVariants || !allVariants.length) return;
  const v = allVariants[0];
  showStockAdjustModal(v.id, v.sku + (v.size ? ' (' + v.size + ')' : ''), v.stock);
}

function onVariantSelectChange(variantId) {
  const v = allVariants.find(item => item.id == variantId);
  if (v) {
    adjustVariantId = v.id;
    adjustCurrentStock = v.stock;
    document.getElementById('adjustSku').textContent = v.sku + (v.size ? ' (' + v.size + ')' : '');
    document.getElementById('currentStockDisplay').value = v.stock;
    updateAdjustPreview();
  }
}

function showStockAdjustModal(variantId, skuLabel, currentStock) {
  adjustVariantId = variantId;
  adjustCurrentStock = currentStock;

  document.getElementById('adjustSku').textContent = skuLabel;
  document.getElementById('currentStockDisplay').value = currentStock;
  document.getElementById('adjustQty').value = 1;
  document.getElementById('adjustType').value = 'add';

  const selectEl = document.getElementById('adjustVariantSelect');
  if (selectEl) {
    selectEl.value = variantId;
  }

  updateAdjustPreview();
  openModal('stockAdjustModal');
}

function updateAdjustPreview() {
  const type = document.getElementById('adjustType').value;
  const qty = parseInt(document.getElementById('adjustQty').value) || 0;
  const newStock = type === 'add' ? adjustCurrentStock + qty : adjustCurrentStock - qty;

  document.getElementById('newStockPreview').textContent = Math.max(0, newStock);
  document.getElementById('newStockPreview').style.color = newStock < 0 ? '#dc2626' : '#059669';
}

function confirmStockAdjust() {
  const type = document.getElementById('adjustType').value;
  const qty = parseInt(document.getElementById('adjustQty').value);

  if (!qty || qty < 1) {
    showToast('Please enter a valid quantity', 'error');
    return;
  }

  const btn = document.getElementById('confirmAdjustBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner spinner-sm"></span> Adjusting...';

  const formData = new FormData();
  formData.append('variant_id', adjustVariantId);
  formData.append('adjustment_type', type);
  formData.append('qty_change', qty);
  formData.append('reason', 'Manual adjustment');
  formData.append('csrf_token', '<?= csrfToken() ?>');

  fetch(APP_URL + '/api/adjust_stock.php', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      showToast(data.message, 'success');
      closeModal('stockAdjustModal');
      setTimeout(() => window.location.reload(), 1000);
    } else {
      showToast(data.message, 'error');
      btn.disabled = false;
      btn.innerHTML = '<i class="fas fa-check"></i> Adjust Stock';
    }
  })
  .catch(err => {
    showToast('Failed to adjust stock. Please try again.', 'error');
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-check"></i> Adjust Stock';
  });
}
</script>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
