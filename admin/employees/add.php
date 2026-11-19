<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireAdmin();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $fullName      = sanitize($_POST['full_name'] ?? '');
    $username      = sanitize($_POST['username'] ?? '');
    $password      = $_POST['password'] ?? '';
    $mobile        = cleanMobile($_POST['mobile'] ?? '');
    $email         = sanitize($_POST['email'] ?? '');
    $canEnterStock = isset($_POST['can_enter_stock']) ? 1 : 0;
    $canDiscount   = isset($_POST['can_apply_discount']) ? 1 : 0;
    $maxDiscount   = (float)($_POST['max_discount_pct'] ?? 0);

    if (empty($fullName)) $errors[] = 'Full name is required.';
    if (empty($username)) $errors[] = 'Username is required.';
    if (strlen($password) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($mobile && !isValidMobile($mobile)) $errors[] = 'Mobile number must be exactly 10 digits.';

    if (empty($errors)) {
        $dup = db()->fetchOne("SELECT id FROM users WHERE username=?", [$username]);
        if ($dup) $errors[] = "Username '$username' is already taken.";
    }

    if (empty($errors)) {
        $id = db()->insert(
            "INSERT INTO users (full_name, username, password, role, mobile, email, can_enter_stock, can_apply_discount, max_discount_pct, status)
             VALUES (?,?,?,?,?,?,?,?,?,?)",
            [$fullName, $username, password_hash($password, PASSWORD_DEFAULT, ['cost'=>12]),
             'employee', $mobile?:null, $email?:null, $canEnterStock, $canDiscount, $maxDiscount, 'active']
        );
        logActivity("Added employee: $fullName", 'employees', $id, 'user');
        $_SESSION['flash_success'] = "Employee '$fullName' added. They can now log in.";
        header('Location: ' . APP_URL . '/admin/employees/view.php?id=' . $id); exit;
    }
}

$pageTitle='Add Employee'; $activePage='employees';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<div class="page-header">
  <div class="page-header-left"><h1 class="page-title"><i class="fas fa-user-plus text-primary" style="margin-right:10px"></i>Add Employee</h1></div>
  <div class="page-header-right"><a href="<?= APP_URL ?>/admin/employees/index.php" class="btn btn-light"><i class="fas fa-arrow-left"></i> Back</a></div>
</div>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?= e($err) ?></div><?php endforeach; ?>
<form method="POST" style="max-width:700px">
  <?= csrfField() ?>
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fas fa-user"></i> Employee Information</span></div>
    <div class="card-body">
      <div class="form-row cols-2">
        <div class="form-group"><label class="form-label">Full Name <span class="required">*</span></label><input type="text" name="full_name" class="form-control" required value="<?= e($_POST['full_name']??'') ?>"></div>
        <div class="form-group"><label class="form-label">Username <span class="required">*</span></label><input type="text" name="username" class="form-control" required value="<?= e($_POST['username']??'') ?>" autocomplete="username"></div>
      </div>
      <div class="form-row cols-2">
        <div class="form-group"><label class="form-label">Password <span class="required">*</span></label><input type="password" name="password" class="form-control" required minlength="6" autocomplete="new-password"><div class="form-hint">Minimum 6 characters</div></div>
        <div class="form-group"><label class="form-label">Mobile</label><input type="tel" name="mobile" class="form-control" placeholder="10-digit mobile number" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'').slice(0,10)" value="<?= e($_POST['mobile']??'') ?>"></div>
      </div>
      <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e($_POST['email']??'') ?>"></div>
    </div>
  </div>

  <div class="card" style="margin-top:16px">
    <div class="card-header"><span class="card-title"><i class="fas fa-shield-alt"></i> Permissions</span></div>
    <div class="card-body">
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:12px;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:10px">
          <input type="checkbox" name="can_enter_stock" value="1" <?= !empty($_POST['can_enter_stock'])?'checked':'' ?> style="width:18px;height:18px;accent-color:#2563eb">
          <div>
            <div style="font-weight:600">Can Enter Stock</div>
            <div style="font-size:0.78rem;color:#64748b">Allow employee to add/record purchase stock entries</div>
          </div>
        </label>
        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:12px;border:1px solid #e2e8f0;border-radius:8px">
          <input type="checkbox" name="can_apply_discount" value="1" id="discountCheck" <?= !empty($_POST['can_apply_discount'])?'checked':'' ?> style="width:18px;height:18px;accent-color:#2563eb" onchange="toggleDiscountField(this.checked)">
          <div>
            <div style="font-weight:600">Can Apply Discount</div>
            <div style="font-size:0.78rem;color:#64748b">Allow employee to give discounts on bills</div>
          </div>
        </label>
      </div>
      <div id="discountPctField" style="display:<?= !empty($_POST['can_apply_discount'])?'block':'none' ?>;margin-top:10px">
        <label class="form-label">Maximum Discount % Allowed</label>
        <input type="number" name="max_discount_pct" class="form-control" step="0.5" min="0" max="100" value="<?= e($_POST['max_discount_pct']??'5') ?>" style="max-width:180px">
      </div>
    </div>
    <div class="card-footer"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Create Employee</button></div>
  </div>
</form>
<script>function toggleDiscountField(show) { document.getElementById('discountPctField').style.display = show?'block':'none'; }</script>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
