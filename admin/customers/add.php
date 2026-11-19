<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireAdmin();

$errors = []; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $name    = sanitize($_POST['name'] ?? '');
    $mobile  = cleanMobile($_POST['mobile'] ?? '');
    $email   = sanitize($_POST['email'] ?? '');
    $address = sanitize($_POST['address'] ?? '');
    $type    = in_array($_POST['type']??'', ['retail','wholesale','walk-in']) ? $_POST['type'] : 'retail';
    $gstin   = sanitize($_POST['gstin'] ?? '');
    $notes   = sanitize($_POST['notes'] ?? '');

    if (empty($name)) $errors[] = 'Customer name is required.';
    if ($mobile && !isValidMobile($mobile)) $errors[] = 'Mobile number must be exactly 10 digits.';
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';

    if (empty($errors)) {
        // Check duplicate mobile
        if ($mobile) {
            $dup = db()->fetchOne("SELECT id FROM customers WHERE mobile=?", [$mobile]);
            if ($dup) $errors[] = 'A customer with this mobile already exists.';
        }
    }

    if (empty($errors)) {
        $id = db()->insert(
            "INSERT INTO customers (name, mobile, email, address, customer_type, gstin, notes, status, created_by)
             VALUES (?,?,?,?,?,?,?,?,?)",
            [$name, $mobile?:null, $email?:null, $address?:null, $type, $gstin?:null, $notes?:null, 'active', currentUserId()]
        );
        logActivity("Added customer: $name", 'customers', $id, 'customer');
        $_SESSION['flash_success'] = "Customer '$name' added successfully.";
        header('Location: ' . APP_URL . '/admin/customers/view.php?id=' . $id); exit;
    }
}

$pageTitle='Add Customer'; $activePage='customers';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<div class="page-header">
  <div class="page-header-left"><h1 class="page-title"><i class="fas fa-user-plus text-primary" style="margin-right:10px"></i>Add Customer</h1></div>
  <div class="page-header-right"><a href="<?= APP_URL ?>/admin/customers/index.php" class="btn btn-light"><i class="fas fa-arrow-left"></i> Back</a></div>
</div>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= e($err) ?></div><?php endforeach; ?>
<form method="POST" style="max-width:680px">
  <?= csrfField() ?>
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fas fa-user"></i> Customer Information</span></div>
    <div class="card-body">
      <div class="form-row cols-2">
        <div class="form-group"><label class="form-label">Full Name <span class="required">*</span></label><input type="text" name="name" class="form-control" required value="<?= e($_POST['name']??'') ?>"></div>
        <div class="form-group"><label class="form-label">Mobile</label><input type="tel" name="mobile" class="form-control" placeholder="10-digit mobile number" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'').slice(0,10)" value="<?= e($_POST['mobile']??'') ?>"></div>
      </div>
      <div class="form-row cols-2">
        <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e($_POST['email']??'') ?>"></div>
        <div class="form-group"><label class="form-label">Customer Type</label>
          <select name="type" class="form-select">
            <option value="retail" <?= ($_POST['type']??'retail')==='retail'?'selected':'' ?>>Retail</option>
            <option value="wholesale" <?= ($_POST['type']??'')==='wholesale'?'selected':'' ?>>Wholesale</option>
            <option value="walk-in" <?= ($_POST['type']??'')==='walk-in'?'selected':'' ?>>Walk-in</option>
          </select>
        </div>
      </div>
      <div class="form-group"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"><?= e($_POST['address']??'') ?></textarea></div>
      <div class="form-row cols-2">
        <div class="form-group"><label class="form-label">GSTIN</label><input type="text" name="gstin" class="form-control" placeholder="Optional" value="<?= e($_POST['gstin']??'') ?>"></div>
        <div class="form-group"><label class="form-label">Notes</label><input type="text" name="notes" class="form-control" placeholder="Internal notes" value="<?= e($_POST['notes']??'') ?>"></div>
      </div>
    </div>
    <div class="card-footer"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Customer</button></div>
  </div>
</form>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
