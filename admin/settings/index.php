<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireAdmin();

$success = '';
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();

    $settings = [
        'business_name'           => sanitize($_POST['business_name'] ?? ''),
        'business_tagline'        => sanitize($_POST['business_tagline'] ?? ''),
        'business_address'        => sanitize($_POST['business_address'] ?? ''),
        'business_phone'          => cleanMobile($_POST['business_phone'] ?? ''),
        'business_email'          => sanitize($_POST['business_email'] ?? ''),
        'business_gstin'          => sanitize($_POST['business_gstin'] ?? ''),
        'currency_symbol'         => sanitize($_POST['currency_symbol'] ?? '₹'),
        'date_format'             => sanitize($_POST['date_format'] ?? 'd/m/Y'),
        'invoice_prefix'          => strtoupper(sanitize($_POST['invoice_prefix'] ?? 'INV')),
        'purchase_gst_pct'        => (float)($_POST['purchase_gst_pct'] ?? 5),
        'sales_gst_pct'           => (float)($_POST['sales_gst_pct'] ?? 5),
        'default_profit_markup_pct'=> (float)($_POST['default_profit_markup_pct'] ?? 65),
        'default_min_stock_alert' => (int)($_POST['default_min_stock_alert'] ?? 5),
        'invoice_footer'          => sanitize($_POST['invoice_footer'] ?? ''),
        'terms_conditions'        => sanitize($_POST['terms_conditions'] ?? ''),
        'session_timeout'         => (int)($_POST['session_timeout'] ?? 480),
        'max_discount_pct'        => (float)($_POST['max_discount_pct'] ?? 20),
    ];

    // Handle logo upload
    if (!empty($_FILES['business_logo']['name'])) {
        $uploaded = uploadImage($_FILES['business_logo'], UPLOAD_PATH . 'logos/', 'logo_');
        if ($uploaded) {
            // Delete old logo
            $oldLogo = getSetting('business_logo', '');
            if ($oldLogo && file_exists(UPLOAD_PATH . 'logos/' . $oldLogo)) unlink(UPLOAD_PATH . 'logos/' . $oldLogo);
            $settings['business_logo'] = $uploaded;
        }
    }

    if (empty($settings['business_name'])) {
        $errors[] = 'Business name is required.';
    }
    if (!empty($settings['business_phone']) && !isValidMobile($settings['business_phone'])) {
        $errors[] = 'Business phone must be exactly 10 digits.';
    }

    if (empty($errors)) {
        foreach ($settings as $key => $value) {
            $existing = db()->fetchOne("SELECT id FROM settings WHERE setting_key=?", [$key]);
            if ($existing) {
                db()->execute("UPDATE settings SET setting_value=?, updated_at=NOW() WHERE setting_key=?", [(string)$value, $key]);
            } else {
                db()->insert("INSERT INTO settings (setting_key, setting_value) VALUES (?,?)", [$key, (string)$value]);
            }
        }
        // Refresh settings cache
        unset($_SESSION['settings_cache']);
        logActivity('Updated system settings', 'settings');
        $success = 'Settings saved successfully.';
    }
}

// Load current settings
$currentSettings = [];
$allSettings = db()->fetchAll("SELECT setting_key, setting_value FROM settings");
foreach ($allSettings as $s) { $currentSettings[$s['setting_key']] = $s['setting_value']; }

function getSett(string $key, string $default = ''): string {
    global $currentSettings;
    return $currentSettings[$key] ?? $default;
}

$pageTitle = 'Settings'; $activePage = 'settings';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<div class="page-header">
  <div class="page-header-left"><h1 class="page-title"><i class="fas fa-cog text-primary" style="margin-right:10px"></i>System Settings</h1></div>
</div>

<?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= e($success) ?></div><?php endif; ?>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>

<form method="POST" enctype="multipart/form-data">
  <?= csrfField() ?>

  <div class="grid-2">
    <!-- Business Info -->
    <div class="card">
      <div class="card-header"><span class="card-title"><i class="fas fa-store"></i> Business Information</span></div>
      <div class="card-body">
        <div class="form-group"><label class="form-label">Business Name <span class="required">*</span></label><input type="text" name="business_name" class="form-control" required value="<?= e(getSett('business_name','Unnatti Enterprises')) ?>"></div>
        <div class="form-group"><label class="form-label">Tagline</label><input type="text" name="business_tagline" class="form-control" value="<?= e(getSett('business_tagline')) ?>"></div>
        <div class="form-group"><label class="form-label">Address</label><textarea name="business_address" class="form-control" rows="2"><?= e(getSett('business_address')) ?></textarea></div>
        <div class="form-row cols-2">
          <div class="form-group"><label class="form-label">Phone</label><input type="tel" name="business_phone" class="form-control" placeholder="10-digit phone number" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'').slice(0,10)" value="<?= e(getSett('business_phone')) ?>"></div>
          <div class="form-group"><label class="form-label">Email</label><input type="email" name="business_email" class="form-control" value="<?= e(getSett('business_email')) ?>"></div>
        </div>
        <div class="form-group"><label class="form-label">GSTIN</label><input type="text" name="business_gstin" class="form-control" value="<?= e(getSett('business_gstin')) ?>"></div>
        <div class="form-group mb-0">
          <label class="form-label">Business Logo</label>
          <?php $logo = getSett('business_logo'); if ($logo && file_exists(UPLOAD_PATH.'logos/'.$logo)): ?>
            <div style="margin-bottom:10px"><img src="<?= APP_URL ?>/uploads/logos/<?= e($logo) ?>" style="height:50px;border-radius:6px;border:1px solid #e2e8f0"></div>
          <?php endif; ?>
          <input type="file" name="business_logo" class="form-control" accept="image/*">
        </div>
      </div>
    </div>

    <!-- Pricing Defaults -->
    <div>
      <div class="card">
        <div class="card-header"><span class="card-title"><i class="fas fa-calculator"></i> Pricing Defaults</span></div>
        <div class="card-body">
          <div class="form-row cols-3">
            <div class="form-group"><label class="form-label">Purchase GST %</label><input type="number" name="purchase_gst_pct" class="form-control" step="0.01" value="<?= e(getSett('purchase_gst_pct','5')) ?>"></div>
            <div class="form-group"><label class="form-label">Sales GST %</label><input type="number" name="sales_gst_pct" class="form-control" step="0.01" value="<?= e(getSett('sales_gst_pct','5')) ?>"></div>
            <div class="form-group"><label class="form-label">Profit Markup %</label><input type="number" name="default_profit_markup_pct" class="form-control" step="0.01" value="<?= e(getSett('default_profit_markup_pct','65')) ?>"></div>
          </div>
          <div class="form-row cols-2">
            <div class="form-group"><label class="form-label">Min Stock Alert (default)</label><input type="number" name="default_min_stock_alert" class="form-control" min="0" value="<?= e(getSett('default_min_stock_alert','5')) ?>"></div>
            <div class="form-group"><label class="form-label">Max Discount % (global)</label><input type="number" name="max_discount_pct" class="form-control" step="0.5" min="0" value="<?= e(getSett('max_discount_pct','20')) ?>"></div>
          </div>
        </div>
      </div>

      <div class="card" style="margin-top:16px">
        <div class="card-header"><span class="card-title"><i class="fas fa-file-invoice"></i> Invoice Settings</span></div>
        <div class="card-body">
          <div class="form-row cols-2">
            <div class="form-group"><label class="form-label">Invoice Prefix</label><input type="text" name="invoice_prefix" class="form-control" value="<?= e(getSett('invoice_prefix','INV')) ?>"><div class="form-hint">E.g. INV → INV/2025/01/0001</div></div>
            <div class="form-group"><label class="form-label">Currency Symbol</label><input type="text" name="currency_symbol" class="form-control" value="<?= e(getSett('currency_symbol','₹')) ?>"></div>
          </div>
          <div class="form-group"><label class="form-label">Invoice Footer Message</label><input type="text" name="invoice_footer" class="form-control" placeholder="Thank you for your business!" value="<?= e(getSett('invoice_footer')) ?>"></div>
          <div class="form-group mb-0"><label class="form-label">Terms & Conditions</label><textarea name="terms_conditions" class="form-control" rows="3" placeholder="Goods once sold will not be taken back..."><?= e(getSett('terms_conditions')) ?></textarea></div>
        </div>
      </div>

      <div class="card" style="margin-top:16px">
        <div class="card-header"><span class="card-title"><i class="fas fa-cog"></i> System Settings</span></div>
        <div class="card-body">
          <div class="form-row cols-2">
            <div class="form-group"><label class="form-label">Date Format</label>
              <select name="date_format" class="form-select">
                <option value="d/m/Y" <?= getSett('date_format')==='d/m/Y'?'selected':'' ?>>DD/MM/YYYY</option>
                <option value="d-m-Y" <?= getSett('date_format')==='d-m-Y'?'selected':'' ?>>DD-MM-YYYY</option>
                <option value="Y-m-d" <?= getSett('date_format')==='Y-m-d'?'selected':'' ?>>YYYY-MM-DD</option>
                <option value="d M Y" <?= getSett('date_format')==='d M Y'?'selected':'' ?>>DD Mon YYYY</option>
              </select>
            </div>
            <div class="form-group"><label class="form-label">Session Timeout (minutes)</label><input type="number" name="session_timeout" class="form-control" min="5" value="<?= e(getSett('session_timeout','480')) ?>"></div>
          </div>
        </div>
        <div class="card-footer"><button type="submit" class="btn btn-primary btn-lg"><i class="fas fa-save"></i> Save All Settings</button></div>
      </div>
    </div>
  </div>
</form>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
