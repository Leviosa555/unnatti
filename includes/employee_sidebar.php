<?php
/**
 * Employee Sidebar Navigation (Unnatti Enterprises)
 */
$currentPath = $_SERVER['PHP_SELF'] ?? '';
$appUrl = APP_URL;
$businessName = getSetting('business_name', 'Unnatti Enterprises');
$user = currentUser();

if (!function_exists('isEmpNavActive')) {
    function isEmpNavActive(string $path, string $current): string {
        return (strpos($current, $path) !== false) ? ' active' : '';
    }
}

$isProductActive = (strpos($currentPath, '/employee/products') !== false || strpos($currentPath, '/admin/products') !== false);
$isBillingActive = (strpos($currentPath, '/employee/billing') !== false || strpos($currentPath, '/employee/create_bill') !== false || strpos($currentPath, '/employee/my_bills') !== false);
?>
<nav class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon"><i class="fas fa-store"></i></div>
        <div class="brand-info">
            <h4 class="brand-name"><?= e($businessName) ?></h4>
            <span class="brand-tagline">Boutique ERP &bull; Employee</span>
        </div>
    </div>
    <div class="sidebar-nav">
        <ul class="sidebar-menu">
            <!-- 1. DASHBOARD -->
            <li class="nav-item<?= (strpos($currentPath, '/employee/dashboard') !== false) ? ' active' : '' ?>">
                <a href="<?= $appUrl ?>/employee/dashboard.php">
                    <span class="nav-icon"><i class="fas fa-th-large"></i></span>
                    <span class="nav-text">Dashboard</span>
                </a>
            </li>

            <!-- 2. PRODUCT & INVENTORY -->
            <li class="nav-section-label"><span>Product &amp; Inventory</span></li>
            <li class="nav-item<?= $isProductActive ? ' active' : '' ?> has-submenu">
                <a href="#" class="submenu-toggle" onclick="toggleSubmenu(this)">
                    <span class="nav-icon"><i class="fas fa-layer-group"></i></span>
                    <span class="nav-text">Product</span>
                    <span class="nav-arrow"><i class="fas fa-chevron-right"></i></span>
                </a>
                <ul class="submenu <?= $isProductActive ? 'open' : '' ?>">
                    <li><a href="<?= $appUrl ?>/employee/products/index.php"><i class="fas fa-tshirt"></i> Shop Products</a></li>
                    <li><a href="<?= $appUrl ?>/employee/products/add.php"><i class="fas fa-plus"></i> Add New Product</a></li>
                </ul>
            </li>

            <!-- 3. SALES & BILLING -->
            <li class="nav-section-label"><span>Sales &amp; Billing</span></li>
            <li class="nav-item<?= $isBillingActive ? ' active' : '' ?> has-submenu">
                <a href="#" class="submenu-toggle" onclick="toggleSubmenu(this)">
                    <span class="nav-icon"><i class="fas fa-cash-register"></i></span>
                    <span class="nav-text">Sales &amp; Billing</span>
                    <span class="nav-arrow"><i class="fas fa-chevron-right"></i></span>
                </a>
                <ul class="submenu <?= $isBillingActive ? 'open' : '' ?>">
                    <li><a href="<?= $appUrl ?>/employee/billing/create.php"><i class="fas fa-file-invoice"></i> Create Bill / Sale</a></li>
                    <li><a href="<?= $appUrl ?>/employee/billing/index.php"><i class="fas fa-receipt"></i> Bills &amp; Invoices</a></li>
                </ul>
            </li>

            <!-- 4. ADMINISTRATION -->
            <li class="nav-section-label"><span>Administration</span></li>
            <li class="nav-item<?= isEmpNavActive('/employee/settings', $currentPath) ?>">
                <a href="<?= $appUrl ?>/employee/settings/index.php">
                    <span class="nav-icon"><i class="fas fa-cog"></i></span>
                    <span class="nav-text">Settings</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= $appUrl ?>/change_password.php">
                    <span class="nav-icon"><i class="fas fa-key"></i></span>
                    <span class="nav-text">Change Password</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="<?= $appUrl ?>/logout.php">
                    <span class="nav-icon"><i class="fas fa-sign-out-alt"></i></span>
                    <span class="nav-text">Logout</span>
                </a>
            </li>
        </ul>
    </div>
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar-sm"><?= strtoupper(substr($user['name'] ?? 'E', 0, 1)) ?></div>
            <div class="user-info">
                <span class="user-name-sm"><?= e($user['name'] ?? 'Employee') ?></span>
                <span class="user-role-sm">Employee</span>
            </div>
        </div>
    </div>
</nav>
