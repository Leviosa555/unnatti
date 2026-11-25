<?php
/**
 * Employee Settings
 * Displays Pricing Defaults, Invoice Settings, and System Settings.
 */
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireLogin();

// Redirect admin to admin settings
if (isAdmin()) {
    header('Location: ' . APP_URL . '/admin/settings/index.php');
    exit;
}

$success = '';
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();

    $settings = [
        'business_name'             => sanitize($_POST['business_name'] ?? ''),
        'business_tagline'          => sanitize($_POST['business_tagline'] ?? ''),
        'business_address'          => sanitize($_POST['business_address'] ?? ''),
        'business_phone'            => cleanMobile($_POST['business_phone'] ?? ''),
        'business_email'            => sanitize($_POST['business_email'] ?? ''),
        'business_gstin'            => sanitize($_POST['business_gstin'] ?? ''),
        'currency_symbol'           => sanitize($_POST['currency_symbol'] ?? '₹'),
        'date_format'               => sanitize($_POST['date_format'] ?? 'd/m/Y'),
        'invoice_prefix'            => strtoupper(sanitize($_POST['invoice_prefix'] ?? 'INV')),
        'purchase_gst_pct'          => (float)($_POST['purchase_gst_pct'] ?? 5),
        'sales_gst_pct'             => (float)($_POST['sales_gst_pct'] ?? 5),
        'default_profit_markup_pct' => (float)($_POST['default_profit_markup_pct'] ?? 65),
        'default_min_stock_alert'   => (int)($_POST['default_min_stock_alert'] ?? 5),
        'invoice_footer'            => sanitize($_POST['invoice_footer'] ?? ''),
        'terms_conditions'          => sanitize($_POST['terms_conditions'] ?? ''),
        'session_timeout'           => (int)($_POST['session_timeout'] ?? 480),
        'max_discount_pct'          => (float)($_POST['max_discount_pct'] ?? 20),
    ];

    if (empty($settings['business_name'])) {
        $errors[] = 'Business name is required.';
    }
    if (!empty($settings['business_phone']) && !isValidMobile($settings['business_phone'])) {
        $errors[] = 'Business phone must be exactly 10 digits.';
    }

    if (empty($errors)) {
        foreach ($settings as $key => $val) {
            setSetting($key, (string)$val);
        }
        logActivity('Updated store settings', 'settings', null, 'settings');
        $success = 'Settings updated successfully.';
    }
}

// Helper to fetch setting with fallback
function getSett($key, $default = '') {
    return getSetting($key, $default);
}

$pageTitle  = 'Settings';
$activePage = 'settings';
$breadcrumb = [['label' => 'Settings']];
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<script>window.APP_URL = '<?= APP_URL ?>';</script>

<div class="page-header">
  <div class="page-header-left">
    <h1 class="page-title"><i class="fas fa-cog text-primary" style="margin-right:10px"></i>Settings</h1>
    <p class="page-subtitle">Configure store pricing defaults, invoice details, and system parameters</p>
  </div>
</div>

<?php if ($success): ?>
<div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= e($success) ?></div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
<div class="alert alert-danger"><i class="fas fa-exclamation-triangle"></i> <?= implode('<br>', array_map('e', $errors)) ?></div>
<?php endif; ?>

<form method="POST">
  <?= csrfField() ?>

  <div class="grid-2">
    <!-- Left Column: Pricing Defaults & Invoice Settings -->
    <div style="display:flex;flex-direction:column;gap:20px;">
      <!-- 1. PRICING DEFAULTS -->
      <div class="card">
        <div class="card-header"><span class="card-title"><i class="fas fa-calculator text-primary"></i> Pricing Defaults</span></div>
        <div class="card-body">
          <p class="text-xs text-muted mb-3">Default GST rates and profit markups applied across boutique products.</p>
          <div class="form-row cols-3">
            <div class="form-group mb-0">
              <label class="form-label text-xs">Purchase GST %</label>
              <input type="number" name="purchase_gst_pct" class="form-control" step="0.01" value="<?= e(getSett('purchase_gst_pct','5')) ?>">
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-xs">Sales GST %</label>
              <input type="number" name="sales_gst_pct" class="form-control" step="0.01" value="<?= e(getSett('sales_gst_pct','5')) ?>">
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-xs">Profit Markup %</label>
              <input type="number" name="default_profit_markup_pct" class="form-control" step="0.01" value="<?= e(getSett('default_profit_markup_pct','65')) ?>">
            </div>
          </div>
          <div class="form-row cols-2 mt-3">
            <div class="form-group mb-0">
              <label class="form-label text-xs">Min Stock Alert (Default)</label>
              <input type="number" name="default_min_stock_alert" class="form-control" min="0" value="<?= e(getSett('default_min_stock_alert','5')) ?>">
            </div>
            <div class="form-group mb-0">
              <label class="form-label text-xs">Max Discount % Allowed</label>
              <input type="number" name="max_discount_pct" class="form-control" step="0.5" min="0" value="<?= e(getSett('max_discount_pct','20')) ?>">
            </div>
          </div>
        </div>
      </div>

      <!-- 2. INVOICE SETTINGS -->
      <div class="card">
        <div class="card-header"><span class="card-title"><i class="fas fa-file-invoice text-primary"></i> Invoice Settings</span></div>
        <div class="card-body">
          <div class="form-row cols-2">
            <div class="form-group">
              <label class="form-label text-xs">Invoice Prefix</label>
              <input type="text" name="invoice_prefix" class="form-control" value="<?= e(getSett('invoice_prefix','INV')) ?>">
              <small class="text-muted">E.g. INV &rarr; INV/2026/11/0001</small>
            </div>
            <div class="form-group">
              <label class="form-label text-xs">Currency Symbol</label>
              <input type="text" name="currency_symbol" class="form-control" value="<?= e(getSett('currency_symbol','₹')) ?>">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label text-xs">Invoice Footer Message</label>
            <input type="text" name="invoice_footer" class="form-control" placeholder="Thank you for shopping with us!" value="<?= e(getSett('invoice_footer')) ?>">
          </div>
          <div class="form-group mb-0">
            <label class="form-label text-xs">Terms &amp; Conditions</label>
            <textarea name="terms_conditions" class="form-control" rows="3" placeholder="Exchange valid within 7 days with bill..."><?= e(getSett('terms_conditions')) ?></textarea>
          </div>
        </div>
      </div>
    </div>

    <!-- Right Column: System Settings & Store Profile -->
    <div style="display:flex;flex-direction:column;gap:20px;">
      <!-- 3. SYSTEM SETTINGS & STORE PROFILE -->
      <div class="card">
        <div class="card-header"><span class="card-title"><i class="fas fa-store text-primary"></i> Store Profile &amp; System Settings</span></div>
        <div class="card-body">
          <div class="form-group">
            <label class="form-label text-xs">Business / Store Name <span class="required">*</span></label>
            <input type="text" name="business_name" class="form-control" value="<?= e(getSett('business_name','Unnatti Enterprises')) ?>" required>
          </div>
          <div class="form-group">
            <label class="form-label text-xs">Tagline / Subtitle</label>
            <input type="text" name="business_tagline" class="form-control" value="<?= e(getSett('business_tagline','Boutique ERP')) ?>">
          </div>
          <div class="form-group">
            <label class="form-label text-xs">Store Address</label>
            <textarea name="business_address" class="form-control" rows="2"><?= e(getSett('business_address')) ?></textarea>
          </div>
          <div class="form-row cols-2">
            <div class="form-group">
              <label class="form-label text-xs">Phone</label>
              <input type="text" name="business_phone" class="form-control" value="<?= e(getSett('business_phone')) ?>">
            </div>
            <div class="form-group">
              <label class="form-label text-xs">Email</label>
              <input type="email" name="business_email" class="form-control" value="<?= e(getSett('business_email')) ?>">
            </div>
          </div>
          <div class="form-row cols-2">
            <div class="form-group">
              <label class="form-label text-xs">GSTIN</label>
              <input type="text" name="business_gstin" class="form-control" value="<?= e(getSett('business_gstin')) ?>">
            </div>
            <div class="form-group">
              <label class="form-label text-xs">Date Format</label>
              <select name="date_format" class="form-select">
                <option value="d/m/Y" <?= getSett('date_format')==='d/m/Y'?'selected':'' ?>>DD/MM/YYYY</option>
                <option value="d-m-Y" <?= getSett('date_format')==='d-m-Y'?'selected':'' ?>>DD-MM-YYYY</option>
                <option value="Y-m-d" <?= getSett('date_format')==='Y-m-d'?'selected':'' ?>>YYYY-MM-DD</option>
              </select>
            </div>
          </div>
        </div>
        <div class="card-footer" style="display:flex;justify-content:flex-end;">
          <button type="submit" class="btn btn-primary btn-lg">
            <i class="fas fa-save"></i> Save Settings
          </button>
        </div>
      </div>
    </div>
  </div>
</form>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
