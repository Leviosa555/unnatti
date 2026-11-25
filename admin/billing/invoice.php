<?php
/**
 * Invoice View / Print Page
 */
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireLogin();

$saleId = (int)($_GET['id'] ?? 0);
if (!$saleId) { header('Location: ' . APP_URL . '/admin/billing/index.php'); exit; }

$sale = db()->fetchOne(
    "SELECT s.*, u.full_name AS employee_name FROM sales s JOIN users u ON s.created_by=u.id WHERE s.id=?",
    [$saleId]
);
if (!$sale) { $_SESSION['flash_error'] = 'Invoice not found.'; header('Location: ' . APP_URL . '/admin/billing/index.php'); exit; }

// Employees can only see their own bills
if (!isAdmin() && $sale['created_by'] != currentUserId()) {
    header('Location: ' . APP_URL . '/employee/my_bills.php'); exit;
}

$items = db()->fetchAll("SELECT * FROM sale_items WHERE sale_id=? ORDER BY id", [$saleId]);
$payments = db()->fetchAll("SELECT * FROM payments WHERE sale_id=? ORDER BY id", [$saleId]);
$cancellation = db()->fetchOne("SELECT * FROM bill_cancellations WHERE sale_id=?", [$saleId]);

// Business info
$activeProf = getActiveProfile();
if ($activeProf === 'fashion_kingdom') {
    $bName    = 'Fashion Kingdom — Uniform Mills & Tailoring Hub';
    $bFooter  = 'Thank you for partnering with Fashion Kingdom. Institutional uniform batch supply.';
} else {
    $bName    = getSetting('business_name', 'Unnatti Enterprises');
    $bFooter  = getSetting('invoice_footer', 'Thank you for your business!');
}
$bAddress = getSetting('business_address', '');
$bPhone   = getSetting('business_phone', '');
$bEmail   = getSetting('business_email', '');
$bGstin   = getSetting('business_gstin', '');
$bLogo    = getSetting('business_logo', '');
$bTerms   = getSetting('terms_conditions', '');

$pageTitle  = 'Invoice ' . $sale['invoice_no'];
$activePage = 'billing';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<script>window.APP_URL='<?= APP_URL ?>';</script>

<div class="page-header no-print">
  <div class="page-header-left">
    <h1 class="page-title">Invoice <?= e($sale['invoice_no']) ?></h1>
    <p class="page-subtitle">
      <?= billStatusBadge($sale['status']) ?>
      <?= paymentBadge($sale['payment_status'], $sale['status']) ?>
    </p>
  </div>
  <div class="page-header-right">
    <?php if (isAdmin() && $sale['status'] === 'active'): ?>
    <a href="<?= APP_URL ?>/admin/billing/cancel.php?id=<?= $saleId ?>" class="btn btn-outline-danger btn-sm">
      <i class="fas fa-ban"></i> Cancel Bill
    </a>
    <a href="<?= APP_URL ?>/admin/billing/return.php?id=<?= $saleId ?>" class="btn btn-outline-primary btn-sm">
      <i class="fas fa-undo"></i> Return
    </a>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/api/generate_pdf.php?id=<?= $saleId ?>" class="btn btn-secondary btn-sm" target="_blank">
      <i class="fas fa-file-pdf"></i> Download PDF
    </a>
    <button class="btn btn-primary btn-sm" onclick="printInvoice()">
      <i class="fas fa-print"></i> Print
    </button>
    <a href="<?= APP_URL ?>/admin/billing/index.php" class="btn btn-light btn-sm">
      <i class="fas fa-arrow-left"></i> Back
    </a>
  </div>
</div>

<?php if ($sale['status'] === 'cancelled' && $cancellation): ?>
<div class="alert alert-danger no-print">
  <i class="fas fa-ban"></i>
  <div>
    <strong>Bill Cancelled</strong><br>
    Reason: <?= e($cancellation['cancellation_reason']) ?> &bull;
    Cancelled at: <?= formatDateTime($cancellation['cancelled_at']) ?>
  </div>
</div>
<?php endif; ?>

<!-- INVOICE -->
<div class="invoice-wrapper">

  <!-- Cancelled watermark -->
  <?php if ($sale['status'] === 'cancelled'): ?>
  <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%) rotate(-30deg);font-size:5rem;font-weight:900;color:rgba(220,38,38,0.08);pointer-events:none;white-space:nowrap;z-index:0">CANCELLED</div>
  <?php endif; ?>

  <!-- Header -->
  <div class="invoice-header">
    <div>
      <?php if ($bLogo && file_exists(UPLOAD_PATH . 'logos/' . $bLogo)): ?>
        <img src="<?= APP_URL ?>/uploads/logos/<?= e($bLogo) ?>" style="height:56px;margin-bottom:8px;display:block" alt="Logo">
      <?php endif; ?>
      <div class="invoice-logo">
        <?= e($bName) ?>
        <span><?= e($bAddress) ?></span>
      </div>
      <div style="margin-top:6px;font-size:0.8rem;color:#64748b">
        <?php if ($bPhone): ?><i class="fas fa-phone"></i> <?= e($bPhone) ?>&nbsp;&nbsp;<?php endif; ?>
        <?php if ($bEmail): ?><i class="fas fa-envelope"></i> <?= e($bEmail) ?><?php endif; ?>
        <?php if ($bGstin): ?><br><i class="fas fa-id-card"></i> GSTIN: <?= e($bGstin) ?><?php endif; ?>
      </div>
    </div>
    <div class="invoice-info">
      <div class="invoice-number">TAX INVOICE</div>
      <div class="invoice-meta">
        <strong>Invoice No:</strong> <?= e($sale['invoice_no']) ?><br>
        <strong>Date:</strong> <?= formatDate($sale['sale_date']) ?><br>
        <strong>Bill By:</strong> <?= e($sale['employee_name']) ?>
      </div>
    </div>
  </div>

  <!-- Parties -->
  <div class="invoice-parties">
    <div>
      <div class="invoice-party-label">Bill To</div>
      <div class="invoice-party-name"><?= e($sale['customer_name']) ?></div>
      <div class="invoice-party-detail">
        <?php if ($sale['customer_mobile']): ?>📞 <?= e($sale['customer_mobile']) ?><br><?php endif; ?>
        <?php if ($sale['customer_address']): ?><?= e($sale['customer_address']) ?><?php endif; ?>
      </div>
    </div>
    <div style="text-align:right">
      <div class="invoice-party-label">Payment</div>
      <div class="invoice-party-name"><?= ucfirst(str_replace('_',' ',$sale['payment_method'])) ?></div>
      <div class="invoice-party-detail"><?= paymentBadge($sale['payment_status'], $sale['status']) ?></div>
    </div>
  </div>

  <!-- Items Table -->
  <div class="invoice-items">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Product</th>
          <th class="text-center">Size</th>
          <th class="text-center">Qty</th>
          <th class="text-right">Rate</th>
          <th class="text-right">Discount</th>
          <th class="text-right">GST</th>
          <th class="text-right">Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $i => $item): ?>
        <tr>
          <td class="text-muted"><?= $i+1 ?></td>
          <td>
            <div style="font-weight:600"><?= e($item['product_name_snapshot']) ?></div>
            <div style="font-size:0.75rem;color:#64748b"><span class="sku-badge"><?= e($item['sku_snapshot']) ?></span></div>
          </td>
          <td class="text-center"><?= e($item['size_snapshot'] ?? '—') ?></td>
          <td class="text-center"><?= $item['qty'] ?></td>
          <td class="text-right"><?= formatCurrency($item['selling_price_snapshot']) ?></td>
          <td class="text-right"><?= $item['discount_amount'] > 0 ? formatCurrency($item['discount_amount']) : '—' ?></td>
          <td class="text-right text-muted"><?= $item['gst_pct_snapshot'] ?>%<br><small><?= formatCurrency($item['gst_amount']) ?></small></td>
          <td class="text-right price-cell"><?= formatCurrency($item['line_total']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="7" class="text-right text-muted">Subtotal</td><td class="text-right"><?= formatCurrency($sale['subtotal']) ?></td></tr>
        <?php if ($sale['discount_amount'] > 0): ?>
        <tr><td colspan="7" class="text-right text-danger">Discount</td><td class="text-right text-danger">–<?= formatCurrency($sale['discount_amount']) ?></td></tr>
        <?php endif; ?>
        <?php if ($sale['tax_amount'] > 0): ?>
        <tr><td colspan="7" class="text-right text-muted">GST</td><td class="text-right"><?= formatCurrency($sale['tax_amount']) ?></td></tr>
        <?php endif; ?>
        <?php if ($sale['additional_charges'] > 0): ?>
        <tr><td colspan="7" class="text-right">Additional Charges</td><td class="text-right"><?= formatCurrency($sale['additional_charges']) ?></td></tr>
        <?php endif; ?>
        <tr class="grand-total">
          <td colspan="7" class="text-right">Grand Total</td>
          <td class="text-right"><?= formatCurrency($sale['grand_total']) ?></td>
        </tr>
        <tr>
          <td colspan="7" class="text-right text-muted">Amount Received</td>
          <td class="text-right text-success"><?= formatCurrency($sale['amount_received']) ?></td>
        </tr>
        <?php if ($sale['balance_due'] > 0): ?>
        <tr>
          <td colspan="7" class="text-right text-danger font-bold">Balance Due</td>
          <td class="text-right text-danger font-bold"><?= formatCurrency($sale['balance_due']) ?></td>
        </tr>
        <?php endif; ?>
      </tfoot>
    </table>
  </div>

  <?php if ($sale['notes']): ?>
  <div style="background:#f8fafc;border-radius:8px;padding:12px;margin-bottom:20px;font-size:0.83rem;color:#64748b">
    <strong>Notes:</strong> <?= e($sale['notes']) ?>
  </div>
  <?php endif; ?>

  <!-- Footer -->
  <div class="invoice-footer">
    <div class="invoice-terms">
      <?php if ($bTerms): ?>
      <strong>Terms & Conditions:</strong><br><?= e($bTerms) ?>
      <?php endif; ?>
      <div style="margin-top:8px;color:#94a3b8"><?= e($bFooter) ?></div>
    </div>
    <div class="invoice-signature">
      <div class="signature-line"></div>
      <div style="font-size:0.8rem;font-weight:600"><?= e($bName) ?></div>
      <div style="font-size:0.72rem;color:#64748b">Authorized Signature</div>
    </div>
  </div>

  <div class="invoice-watermark">Generated by <?= e($bName) ?> ERP &bull; <?= formatDateTime(date('Y-m-d H:i:s')) ?></div>
</div>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
