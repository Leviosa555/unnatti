<?php
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
requireAdmin();

$id = (int)($_GET['id'] ?? 0);
$emp = db()->fetchOne("SELECT * FROM users WHERE id=? AND role='employee'", [$id]);
if (!$emp) { $_SESSION['flash_error']='Employee not found.'; header('Location:'.APP_URL.'/admin/employees/index.php'); exit; }

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCSRF();
    $fullName      = sanitize($_POST['full_name'] ?? '');
    $username      = sanitize($_POST['username'] ?? '');
    $mobile        = cleanMobile($_POST['mobile'] ?? '');
    $email         = sanitize($_POST['email'] ?? '');
    $canEnterStock = isset($_POST['can_enter_stock']) ? 1 : 0;
    $canDiscount   = isset($_POST['can_apply_discount']) ? 1 : 0;
    $maxDiscount   = (float)($_POST['max_discount_pct'] ?? 0);
    $status        = sanitize($_POST['status'] ?? 'active');
    $newPassword   = $_POST['new_password'] ?? '';

    if (empty($fullName)) $errors[] = 'Full name required.';
    if (empty($username)) $errors[] = 'Username required.';
    if ($mobile && !isValidMobile($mobile)) $errors[] = 'Mobile number must be exactly 10 digits.';
    if ($username !== $emp['username']) {
        $dup = db()->fetchOne("SELECT id FROM users WHERE username=? AND id!=?", [$username, $id]);
        if ($dup) $errors[] = "Username taken.";
    }

    if (empty($errors)) {
        $params = [$fullName, $username, $mobile?:null, $email?:null, $canEnterStock, $canDiscount, $maxDiscount, $status, $id];
        $sql = "UPDATE users SET full_name=?,username=?,mobile=?,email=?,can_enter_stock=?,can_apply_discount=?,max_discount_pct=?,status=?,updated_at=NOW() WHERE id=?";
        if (!empty($newPassword)) {
            if (strlen($newPassword) < 6) { $errors[] = 'Password min 6 chars.'; }
            else {
                $sql = "UPDATE users SET full_name=?,username=?,mobile=?,email=?,can_enter_stock=?,can_apply_discount=?,max_discount_pct=?,status=?,password=?,updated_at=NOW() WHERE id=?";
                array_splice($params, 8, 0, [password_hash($newPassword, PASSWORD_DEFAULT, ['cost'=>12])]);
            }
        }
        if (empty($errors)) {
            db()->execute($sql, $params);
            logActivity("Updated employee: $fullName", 'employees', $id, 'user');
            $_SESSION['flash_success'] = 'Employee updated.';
            header('Location: ' . APP_URL . '/admin/employees/view.php?id=' . $id); exit;
        }
    }
    $emp = array_merge($emp, $_POST);
}

$pageTitle='Edit Employee'; $activePage='employees';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<div class="page-header">
  <div class="page-header-left"><h1 class="page-title"><i class="fas fa-user-edit text-primary" style="margin-right:10px"></i>Edit: <?= e($emp['full_name']) ?></h1></div>
  <div class="page-header-right"><a href="<?= APP_URL ?>/admin/employees/view.php?id=<?= $id ?>" class="btn btn-light"><i class="fas fa-arrow-left"></i> Back</a></div>
</div>
<?php foreach ($errors as $err): ?><div class="alert alert-danger"><?= e($err) ?></div><?php endforeach; ?>
<form method="POST" style="max-width:700px">
  <?= csrfField() ?>
  <div class="card">
    <div class="card-header"><span class="card-title"><i class="fas fa-user"></i> Employee Information</span></div>
    <div class="card-body">
      <div class="form-row cols-2">
        <div class="form-group"><label class="form-label">Full Name <span class="required">*</span></label><input type="text" name="full_name" class="form-control" required value="<?= e($emp['full_name']) ?>"></div>
        <div class="form-group"><label class="form-label">Username <span class="required">*</span></label><input type="text" name="username" class="form-control" required value="<?= e($emp['username']) ?>"></div>
      </div>
      <div class="form-row cols-2">
        <div class="form-group"><label class="form-label">Mobile</label><input type="tel" name="mobile" class="form-control" placeholder="10-digit mobile number" maxlength="10" pattern="[0-9]{10}" inputmode="numeric" oninput="this.value=this.value.replace(/\D/g,'').slice(0,10)" value="<?= e($emp['mobile']??'') ?>"></div>
        <div class="form-group"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e($emp['email']??'') ?>"></div>
      </div>
      <div class="form-row cols-2">
        <div class="form-group"><label class="form-label">New Password <span style="color:#94a3b8;font-size:0.78rem">(leave blank to keep)</span></label><input type="password" name="new_password" class="form-control" autocomplete="new-password"></div>
        <div class="form-group"><label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="active" <?= $emp['status']==='active'?'selected':'' ?>>Active</option>
            <option value="inactive" <?= $emp['status']==='inactive'?'selected':'' ?>>Inactive</option>
          </select>
        </div>
      </div>
    </div>
  </div>
  <div class="card" style="margin-top:16px">
    <div class="card-header"><span class="card-title"><i class="fas fa-shield-alt"></i> Permissions</span></div>
    <div class="card-body">
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:12px;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:10px">
        <input type="checkbox" name="can_enter_stock" value="1" <?= $emp['can_enter_stock']?'checked':'' ?> style="width:18px;height:18px;accent-color:#2563eb">
        <div><div style="font-weight:600">Can Enter Stock</div></div>
      </label>
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;padding:12px;border:1px solid #e2e8f0;border-radius:8px">
        <input type="checkbox" name="can_apply_discount" value="1" id="discountCheck" <?= $emp['can_apply_discount']?'checked':'' ?> style="width:18px;height:18px;accent-color:#2563eb" onchange="toggleDiscountField(this.checked)">
        <div><div style="font-weight:600">Can Apply Discount</div></div>
      </label>
      <div id="discountPctField" style="display:<?= $emp['can_apply_discount']?'block':'none' ?>;margin-top:10px">
        <label class="form-label">Maximum Discount %</label>
        <input type="number" name="max_discount_pct" class="form-control" step="0.5" min="0" max="100" value="<?= e($emp['max_discount_pct']) ?>" style="max-width:180px">
      </div>
    </div>
    <div class="card-footer"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Employee</button></div>
  </div>
</form>
<script>function toggleDiscountField(show) { document.getElementById('discountPctField').style.display = show?'block':'none'; }</script>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
