<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
$customer = db()->fetchOne("SELECT * FROM customers WHERE id=?", [$id]);
if (!$customer) { $_SESSION['flash_error']='Customer not found.'; header('Location:'.APP_URL.'/admin/customers/index.php'); exit; }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $name    = sanitize($_POST['name'] ?? '');
    $mobile  = cleanMobile($_POST['mobile'] ?? '');
    $email   = sanitize($_POST['email'] ?? '');
    $address = sanitize($_POST['address'] ?? '');
    $type    = sanitize($_POST['type'] ?? 'retail');
    $gstin   = sanitize($_POST['gstin'] ?? '');
    $notes   = sanitize($_POST['notes'] ?? '');
    $status  = sanitize($_POST['status'] ?? 'active');

    if (empty($name)) $errors[] = 'Name required.';
    if ($mobile && !isValidMobile($mobile)) $errors[] = 'Mobile number must be exactly 10 digits.';

    if (empty($errors)) {
        db()->execute(
            "UPDATE customers SET name=?,mobile=?,email=?,address=?,customer_type=?,gstin=?,notes=?,status=?,updated_at=NOW() WHERE id=?",
            [$name, $mobile?:null, $email?:null, $address?:null, $type, $gstin?:null, $notes?:null, $status, $id]
        );
        logActivity("Updated customer: $name", 'customers', $id, 'customer');
        $_SESSION['flash_success'] = 'Customer updated.';
        header('Location: ' . APP_URL . '/admin/customers/view.php?id=' . $id); exit;
    }
    // Re-fill for display
    $customer = array_merge($customer, $_POST);
}

$pageTitle='Edit Customer'; $activePage='customers';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<div class="page-header">
  <div class="page-header-left"><h1 class="page-title"><i class="fas fa-user-edit text-primary" style="margin-right:10px"></i>Edit Customer</h1></div>
  <div class="page-header-right"><a href="<?= APP_URL ?>/admin/customers/view.php?id=<?= $id ?>" class="btn btn-light"><i class="fas fa-arrow-left"></i> Back</a></div>
</div>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>
<form method="POST" style="max-width:680px">
  <?= csrfField() ?>
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fas fa-user"></i> Customer Information</span></div>
    <div class="card-body">
      <div class="form-row cols-2">
        <div class="form-group"><label class="form-label">Full Name <span class="required">*</span></label><input type="text" name="name" class="form-control" required value="<?= e($customer['name']) ?>"></div>
        <div class="form-group"><label class="form-label">Mobile</label><input type="tel" name="mobile" class="form-control" placeholder="10-digit mobile number" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'').slice(0,10)" value="<?= e($customer['mobile']??'') ?>"></div>
      </div>
      <div class="form-row cols-2">
        <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e($customer['email']??'') ?>"></div>
        <div class="form-group"><label class="form-label">Type</label>
          <select name="type" class="form-select">
            <?php foreach (['retail','wholesale','walk-in'] as $t): ?>
            <option value="<?= $t ?>" <?= $customer['customer_type']===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"><?= e($customer['address']??'') ?></textarea></div>
      <div class="form-row cols-2">
        <div class="form-group"><label class="form-label">GSTIN</label><input type="text" name="gstin" class="form-control" value="<?= e($customer['gstin']??'') ?>"></div>
        <div class="form-group"><label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="active" <?= $customer['status']==='active'?'selected':'' ?>>Active</option>
            <option value="inactive" <?= $customer['status']==='inactive'?'selected':'' ?>>Inactive</option>
          </select>
        </div>
      </div>
      <div class="form-group mb-0"><label class="form-label">Notes</label><input type="text" name="notes" class="form-control" value="<?= e($customer['notes']??'') ?>"></div>
    </div>
    <div class="card-footer"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Customer</button></div>
  </div>
</form>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
