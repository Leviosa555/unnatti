<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
$variantId = (int)($_GET['variant_id'] ?? 0);

if (!$id && $variantId) {
    $vRow = db()->fetchOne("SELECT product_id FROM product_variants WHERE id = ?", [$variantId]);
    if ($vRow) $id = (int)$vRow['product_id'];
}

$product = db()->fetchOne(
    "SELECT p.*, c.name AS category_name FROM products p LEFT JOIN categories c ON p.category_id=c.id WHERE p.id=?",
    [$id]
);
if (!$product) { $_SESSION['flash_error']='Product not found.'; header('Location:'.APP_URL.'/admin/products/index.php'); exit; }

$variants = db()->fetchAll("SELECT * FROM product_variants WHERE product_id=? ORDER BY id", [$id]);
$categories = db()->fetchAll("SELECT id, name FROM categories WHERE parent_id IS NULL AND status='active' ORDER BY name");
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $name        = sanitize($_POST['name'] ?? '');
    $categoryId  = (int)($_POST['category_id'] ?? 0);
    $status      = in_array($_POST['status']??'', ['active','inactive']) ? $_POST['status'] : 'active';

    if (empty($name)) $errors[] = 'Product name required.';

    if (empty($errors)) {
        // Handle image
        $imageName = $product['image'];
        if (!empty($_FILES['image']['name'])) {
            $uploaded = uploadImage($_FILES['image'], UPLOAD_PATH.'products/', 'prod_');
            if ($uploaded) {
                if ($imageName && file_exists(UPLOAD_PATH.'products/'.$imageName)) unlink(UPLOAD_PATH.'products/'.$imageName);
                $imageName = $uploaded;
            }
        }

        db()->execute(
            "UPDATE products SET name=?,category_id=?,image=?,status=?,updated_at=NOW() WHERE id=?",
            [$name, $categoryId?:null, $imageName, $status, $id]
        );

        // Update each variant pricing with complete purchase & pricing formula
        $updatedVariants = $_POST['variants'] ?? [];
        foreach ($updatedVariants as $vid => $v) {
            $pp      = (float)($v['purchase_price'] ?? 0);
            $pgst    = (float)($v['purchase_gst_pct'] ?? 5);
            $prof    = (float)($v['profit_markup_pct'] ?? 65);
            $sgst    = (float)($v['sales_gst_pct'] ?? 5);
            $size    = sanitize($v['size'] ?? 'Medium');
            $tag     = (float)($v['tag_price'] ?? 0);
            $min     = (int)($v['min_stock_alert'] ?? 5);
            $vstatus = sanitize($v['status'] ?? 'active');

            $pricing   = calculatePricing($pp, $pgst, $prof, $sgst);
            $sellPrice = ($tag > 0) ? $tag : $pricing['selling_price'];
            $tagPrice  = $sellPrice;

            if ($pp > 0 && abs($sellPrice - $pricing['selling_price']) > 0.01) {
                $sellingBeforeGst     = (float)round($sellPrice / (1 + ($sgst / 100)));
                $salesGstAmount       = (float)round($sellPrice - $sellingBeforeGst);
                $purchaseGstAmount    = $pricing['purchase_gst_amount'];
                $purchasePriceWithGst = $pricing['purchase_price_with_gst'];
                $profitAmount         = (float)round($sellingBeforeGst - $purchasePriceWithGst);
                $profitMarkupPct      = ($purchasePriceWithGst > 0)
                    ? round(($profitAmount / $purchasePriceWithGst) * 100, 2)
                    : 0;
            } else {
                $purchaseGstAmount    = $pricing['purchase_gst_amount'];
                $purchasePriceWithGst = $pricing['purchase_price_with_gst'];
                $profitAmount         = $pricing['profit_amount'];
                $sellingBeforeGst     = $pricing['selling_price_before_gst'];
                $salesGstAmount       = $pricing['sales_gst_amount'];
                $profitMarkupPct      = $prof;
            }

            db()->execute(
                "UPDATE product_variants SET size=?, purchase_price=?, purchase_gst_pct=?, purchase_gst_amount=?,
                 purchase_price_with_gst=?, profit_markup_pct=?, profit_amount=?, selling_price_before_gst=?,
                 sales_gst_pct=?, sales_gst_amount=?, selling_price=?, tag_price=?, min_stock_alert=?, status=?, updated_at=NOW()
                 WHERE id=? AND product_id=?",
                [$size, $pp, $pgst, $purchaseGstAmount, $purchasePriceWithGst, $profitMarkupPct, $profitAmount,
                 $sellingBeforeGst, $sgst, $salesGstAmount, $sellPrice, $tagPrice, $min, $vstatus, $vid, $id]
            );
        }

        logActivity("Updated product: $name", 'products', $id, 'product');
        $_SESSION['flash_success'] = "Product '$name' updated.";
        header('Location: '.APP_URL.'/admin/products/view.php?id='.$id); exit;
    }
}

$pageTitle='Edit Product'; $activePage='products';
$breadcrumb = [['label'=>'Products','url'=>APP_URL.'/admin/products/index.php'],['label'=>e($product['name']),'url'=>APP_URL.'/admin/products/view.php?id='.$id],['label'=>'Edit']];
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
        <div style="font-size:0.85rem;color:#64748b">SKU</div>
        <div style="font-weight:600" id="adjustSku"></div>
      </div>
      
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
  <div class="page-header-left"><h1 class="page-title"><i class="fas fa-edit text-primary" style="margin-right:10px"></i>Edit: <?= e($product['name']) ?></h1></div>
  <div class="page-header-right"><a href="<?= APP_URL ?>/admin/products/view.php?id=<?= $id ?>" class="btn btn-light"><i class="fas fa-arrow-left"></i> Back</a></div>
</div>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>
<form method="POST" enctype="multipart/form-data">
  <?= csrfField() ?>
  <div class="grid-2" style="align-items:stretch">
    <div style="display:flex;flex-direction:column">
      <div class="card" style="height:100%;display:flex;flex-direction:column;margin-bottom:0">
        <div class="card-header" style="min-height:57px;display:flex;align-items:center"><span class="card-title"><i class="fas fa-info-circle"></i> Product Details</span></div>
        <div class="card-body" style="flex:1;display:flex;flex-direction:column">
          <div class="form-group"><label class="form-label">Product Name <span class="required">*</span></label><input type="text" name="name" class="form-control" required value="<?= e($product['name']) ?>"></div>
          <div class="form-group"><label class="form-label">Category</label>
            <select name="category_id" class="form-select">
              <option value="">Select Category</option>
              <?php foreach ($categories as $cat): ?><option value="<?= $cat['id'] ?>" <?= $product['category_id']==$cat['id']?'selected':'' ?>><?= e($cat['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="form-group mb-0" style="flex:1;display:flex;flex-direction:column">
            <label class="form-label">Product Image <span class="text-muted" style="font-weight:400">(Optional - JPG, PNG, WebP)</span></label>
            <?php if ($product['image'] && file_exists(UPLOAD_PATH.'products/'.$product['image'])): ?>
            <div style="margin-bottom:8px"><img src="<?= APP_URL ?>/uploads/products/<?= e($product['image']) ?>" style="height:80px;border-radius:8px;border:1px solid #e2e8f0"></div>
            <?php endif; ?>
            <input type="file" name="image" class="form-control" accept="image/*">
          </div>
        </div>
        <div class="card-footer" style="min-height:57px;display:flex;align-items:center">
          <small class="text-muted"><i class="fas fa-info-circle"></i> Product identity for boutique billing &amp; inventory.</small>
        </div>
      </div>
    </div>
    <div style="display:flex;flex-direction:column">
      <div class="card" style="height:100%;display:flex;flex-direction:column;margin-bottom:0">
        <div class="card-header" style="min-height:57px;display:flex;align-items:center"><span class="card-title"><i class="fas fa-layer-group"></i> Variants</span></div>
        <div class="card-body p-0" style="flex:1">
        <?php foreach ($variants as $v): 
          $margin = $v['purchase_price_with_gst'] > 0 ? round(($v['selling_price']-$v['purchase_price_with_gst'])/$v['selling_price']*100,1) : 0;
        ?>
        <div style="padding:16px 20px;border-bottom:1px solid #e2e8f0">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
            <strong style="font-size:0.95rem;color:#1e293b"><i class="fas fa-barcode text-muted" style="margin-right:6px"></i><?= e($v['sku']) ?></strong>
            <div class="d-flex align-center gap-2">
              <span class="badge badge-<?= $margin>=20?'success':($margin>=10?'warning':'danger') ?>" id="margin-badge-<?= $v['id'] ?>"><?= $margin ?>% margin</span>
              <span class="badge badge-<?= $v['current_stock'] <= $v['min_stock_alert'] ? ($v['current_stock'] == 0 ? 'danger' : 'warning') : 'success' ?>">
                Stock: <?= $v['current_stock'] ?>
              </span>
            </div>
          </div>
          <div class="form-row cols-2" style="margin-bottom:10px">
            <div class="form-group mb-0">
              <label class="form-label text-xs">Garment Size / Unit <span class="required">*</span></label>
              <input type="text" name="variants[<?= $v['id'] ?>][size]" class="form-control" value="<?= e($v['size']) ?>" placeholder="e.g. Medium, Large, Size 32" list="garmentSizesList" required>
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-xs">Status</label>
              <select name="variants[<?= $v['id'] ?>][status]" class="form-select">
                <option value="active" <?= $v['status']==='active'?'selected':'' ?>>Active</option>
                <option value="inactive" <?= $v['status']==='inactive'?'selected':'' ?>>Inactive</option>
              </select>
            </div>
          </div>
          <div class="form-row cols-3" style="margin-bottom:10px">
            <div class="form-group mb-0">
              <label class="form-label text-xs">Purchase Price (₹)</label>
              <input type="number" name="variants[<?= $v['id'] ?>][purchase_price]" class="form-control" step="1" min="0" placeholder="0" value="<?= round($v['purchase_price']) ?>" oninput="calcEdit(<?= $v['id'] ?>)">
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-xs">Purchase GST %</label>
              <input type="number" name="variants[<?= $v['id'] ?>][purchase_gst_pct]" class="form-control" step="0.01" min="0" value="<?= $v['purchase_gst_pct'] ?>" oninput="calcEdit(<?= $v['id'] ?>)">
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-xs">Profit Markup %</label>
              <input type="number" name="variants[<?= $v['id'] ?>][profit_markup_pct]" class="form-control" step="0.01" min="0" value="<?= $v['profit_markup_pct'] ?>" oninput="calcEdit(<?= $v['id'] ?>)">
            </div>
          </div>
          <div class="form-row cols-3" style="margin-bottom:10px">
            <div class="form-group mb-0">
              <label class="form-label text-xs">Sales GST %</label>
              <input type="number" name="variants[<?= $v['id'] ?>][sales_gst_pct]" class="form-control" step="0.01" min="0" value="<?= $v['sales_gst_pct'] ?>" oninput="calcEdit(<?= $v['id'] ?>)">
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-xs">Tag / MRP (₹) <span class="required">*</span></label>
              <input type="number" name="variants[<?= $v['id'] ?>][tag_price]" class="form-control" step="1" min="0" required value="<?= round($v['tag_price'] ?: $v['selling_price']) ?>" style="font-weight:700;color:#2563eb">
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-xs">Min Stock Alert</label>
              <input type="number" name="variants[<?= $v['id'] ?>][min_stock_alert]" class="form-control" min="0" value="<?= $v['min_stock_alert'] ?>">
            </div>
          </div>
          <div style="background:#f8fafc;border-radius:6px;padding:8px 12px;margin-top:10px;font-size:0.8rem;display:flex;justify-content:space-between;align-items:center">
            <div style="color:#64748b" id="edit-preview-info-<?= $v['id'] ?>">
              Cost+GST: <strong>₹<?= number_format(round($v['purchase_price_with_gst'])) ?></strong> &bull; Current Stock: <strong class="<?= $v['current_stock'] <= $v['min_stock_alert'] ? 'text-danger' : 'text-success' ?>"><?= $v['current_stock'] ?></strong> units
            </div>
            <button type="button" class="btn btn-sm btn-outline-primary" onclick="showStockAdjustModal(<?= $v['id'] ?>, '<?= addslashes(e($v['sku'])) ?>', <?= $v['current_stock'] ?>)" title="Adjust Stock">
              <i class="fas fa-boxes"></i> Adjust Stock
            </button>
          </div>
        </div>
        <?php endforeach; ?>
        </div>
        <div class="card-footer" style="min-height:57px;display:flex;align-items:center"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button></div>
      </div>
    </div>
  </div>
</form>

<datalist id="garmentSizesList">
  <option value="Small">
  <option value="Medium">
  <option value="Large">
  <option value="X-Large">
  <option value="XX-Large">
  <option value="Size 28">
  <option value="Size 30">
  <option value="Size 32">
  <option value="Size 34">
  <option value="Size 36">
  <option value="Size 38">
  <option value="Size 40">
  <option value="Size 42">
  <option value="Size 44">
  <option value="Size 6">
  <option value="Size 7">
  <option value="Size 8">
  <option value="Size 9">
  <option value="Size 10">
  <option value="Size 11">
  <option value="Size 12">
</datalist>
<script>
let adjustVariantId = null;
let adjustCurrentStock = 0;

function showStockAdjustModal(variantId, sku, currentStock) {
  adjustVariantId = variantId;
  adjustCurrentStock = currentStock;
  
  document.getElementById('adjustSku').textContent = sku;
  document.getElementById('currentStockDisplay').value = currentStock;
  document.getElementById('adjustQty').value = 1;
  document.getElementById('adjustType').value = 'add';
  
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

function calcEdit(id) {
  const row = document.querySelector(`[name="variants[${id}][purchase_price]"]`)?.closest('div[style*="border-bottom"]');
  if (!row) return;
  const pp   = parseFloat(row.querySelector(`[name="variants[${id}][purchase_price]"]`)?.value) || 0;
  const pgst = parseFloat(row.querySelector(`[name="variants[${id}][purchase_gst_pct]"]`)?.value) || 0;
  const prof = parseFloat(row.querySelector(`[name="variants[${id}][profit_markup_pct]"]`)?.value) || 0;
  const sgst = parseFloat(row.querySelector(`[name="variants[${id}][sales_gst_pct]"]`)?.value) || 0;

  if (pp <= 0) return;
  const pgstAmt   = Math.round(pp * (pgst / 100));
  const ppWithGst = Math.round(pp + pgstAmt);
  const profAmt   = Math.round(ppWithGst * (prof / 100));
  const spBeforeGst = Math.round(ppWithGst + profAmt);
  const sgstAmt   = Math.round(spBeforeGst * (sgst / 100));
  const sellingPrice = Math.round(spBeforeGst + sgstAmt);

  const tagInput = row.querySelector(`[name="variants[${id}][tag_price]"]`);
  if (tagInput) tagInput.value = sellingPrice;

  const previewInfo = document.getElementById('edit-preview-info-' + id);
  if (previewInfo) {
    previewInfo.innerHTML = 'Calculated Sell Price: <strong class="text-primary">₹' + sellingPrice + '</strong> &bull; Cost+GST: ₹' + ppWithGst + ' &bull; Margin: ₹' + profAmt;
  }
}
</script>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
