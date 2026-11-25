<?php
/**
 * Admin Sidebar Navigation
 * Brand Header functions as a Profile Dropdown Switcher:
 * - Unnatti Enterprises: Boutique ERP & Retail (Dashboard, Customer Side, Product & Inventory, Administration)
 * - Fashion Kingdom: Supplier & Mills (Stock Inward, Material Stock, Movements, Supplier Bills, Suppliers Directory)
 */
$currentPath = $_SERVER['PHP_SELF'] ?? '';
$appUrl = APP_URL;
$businessName = getSetting('business_name', 'Unnatti Enterprises');
$businessLogo = getSetting('business_logo', '');

// Get active profile ('unnatti' or 'fashion_kingdom')
$activeProfile = getActiveProfile();

function isNavActive($paths, string $current): string {
    $paths = (array)$paths;
    foreach ($paths as $path) {
        if (strpos($current, $path) !== false) {
            return ' active';
        }
    }
    return '';
}
?>

<nav class="sidebar" id="sidebar">
    <!-- Brand Header / Profile Dropdown Button -->
    <div class="sidebar-brand brand-clickable" id="brandDropdownTrigger" onclick="toggleBrandDropdown(event)" title="Click to switch profile">
        <div class="brand-logo">
            <?php if ($activeProfile === 'fashion_kingdom'): ?>
                <div class="brand-icon brand-icon-fk"><i class="fas fa-crown"></i></div>
            <?php else: ?>
                <?php if ($businessLogo && file_exists(UPLOAD_PATH . 'logos/' . $businessLogo)): ?>
                    <img src="<?= $appUrl ?>/uploads/logos/<?= e($businessLogo) ?>" alt="Logo">
                <?php else: ?>
                    <div class="brand-icon"><i class="fas fa-store"></i></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="brand-info">
            <h4 class="brand-name"><?= $activeProfile === 'fashion_kingdom' ? 'Fashion Kingdom' : e($businessName) ?></h4>
            <span class="brand-tagline"><?= $activeProfile === 'fashion_kingdom' ? 'Supplier &amp; Mills' : 'Boutique ERP' ?></span>
        </div>
        <div class="brand-arrow">
            <i class="fas fa-chevron-down"></i>
        </div>
    </div>

    <!-- Dropdown Popup Menu -->
    <div class="brand-dropdown-popup" id="brandDropdownPopup">
        <div class="popup-heading">Select Profile</div>
        <a href="<?= $appUrl ?>/admin/dashboard.php?profile=unnatti" 
           class="popup-option <?= $activeProfile === 'unnatti' ? 'active' : '' ?>">
            <div class="popup-icon un-icon">
                <i class="fas fa-store"></i>
            </div>
            <div class="popup-info">
                <span class="popup-title">Unnatti Enterprises</span>
                <span class="popup-desc">Boutique ERP &amp; Retail</span>
            </div>
            <?php if ($activeProfile === 'unnatti'): ?>
                <i class="fas fa-check popup-check"></i>
            <?php endif; ?>
        </a>

        <a href="<?= $appUrl ?>/admin/purchases/materials.php?profile=fashion_kingdom" 
           class="popup-option <?= $activeProfile === 'fashion_kingdom' ? 'active' : '' ?>">
            <div class="popup-icon fk-icon">
                <i class="fas fa-crown"></i>
            </div>
            <div class="popup-info">
                <span class="popup-title">Fashion Kingdom</span>
                <span class="popup-desc">Supplier &amp; Mills Hub</span>
            </div>
            <?php if ($activeProfile === 'fashion_kingdom'): ?>
                <i class="fas fa-check popup-check"></i>
            <?php endif; ?>
        </a>
    </div>

    <!-- Navigation (Starts directly below brand header with zero extra stuff) -->
    <div class="sidebar-nav">
        <ul class="sidebar-menu">

            <?php if ($activeProfile === 'fashion_kingdom'): ?>
                <!-- ===================================================== -->
                <!-- FASHION KINGDOM PROFILE: ONLY SUPPLIER THING SHOWN     -->
                <!-- ===================================================== -->
                <li class="nav-section-label"><span>Supplier Operations</span></li>

                <li class="nav-item<?= isNavActive('/admin/purchases/materials.php', $currentPath) ?>">
                    <a href="<?= $appUrl ?>/admin/purchases/materials.php">
                        <span class="nav-icon"><i class="fas fa-scroll" style="color:#3b82f6"></i></span>
                        <span class="nav-text">Material Stock &amp; Inventory</span>
                    </a>
                </li>

                <li class="nav-item<?= isNavActive('/admin/purchases/consumption.php', $currentPath) ?>">
                    <a href="<?= $appUrl ?>/admin/purchases/consumption.php">
                        <span class="nav-icon"><i class="fas fa-cut" style="color:#ec4899"></i></span>
                        <span class="nav-text">Material Used / Production</span>
                    </a>
                </li>

                <li class="nav-item<?= isNavActive('/admin/purchases/add.php', $currentPath) ?>">
                    <a href="<?= $appUrl ?>/admin/purchases/add.php">
                        <span class="nav-icon"><i class="fas fa-plus-circle" style="color:#10b981"></i></span>
                        <span class="nav-text">Stock Inward Entry</span>
                    </a>
                </li>

                <li class="nav-item<?= isNavActive('/admin/purchases/material_movements.php', $currentPath) ?>">
                    <a href="<?= $appUrl ?>/admin/purchases/material_movements.php">
                        <span class="nav-icon"><i class="fas fa-exchange-alt" style="color:#f59e0b"></i></span>
                        <span class="nav-text">Material Stock Movements</span>
                    </a>
                </li>

                <li class="nav-item<?= isNavActive(['/admin/purchases/index.php', '/admin/purchases/view.php', '/admin/suppliers'], $currentPath) ?>">
                    <a href="<?= $appUrl ?>/admin/purchases/index.php">
                        <span class="nav-icon"><i class="fas fa-file-invoice-dollar" style="color:#06b6d4"></i></span>
                        <span class="nav-text">Supplier Bills &amp; Directory</span>
                    </a>
                </li>

                <!-- Uniform Batch Supply & Billing -->
                <li class="nav-section-label"><span>Uniform Supply &amp; Billing</span></li>

                <li class="nav-item<?= isNavActive('/admin/billing/create.php', $currentPath) ?>">
                    <a href="<?= $appUrl ?>/admin/billing/create.php">
                        <span class="nav-icon"><i class="fas fa-file-invoice" style="color:#8b5cf6"></i></span>
                        <span class="nav-text">Create Bill / Supply Order</span>
                    </a>
                </li>

                <li class="nav-item<?= isNavActive(['/admin/billing/index.php', '/admin/billing/invoice.php'], $currentPath) ?>">
                    <a href="<?= $appUrl ?>/admin/billing/index.php">
                        <span class="nav-icon"><i class="fas fa-receipt" style="color:#ec4899"></i></span>
                        <span class="nav-text">All Bills &amp; Invoices</span>
                    </a>
                </li>

                <!-- Production & Output Analytics -->
                <li class="nav-section-label"><span>Production Analytics</span></li>

                <li class="nav-item<?= isNavActive('/admin/purchases/analytics.php', $currentPath) ?>">
                    <a href="<?= $appUrl ?>/admin/purchases/analytics.php">
                        <span class="nav-icon"><i class="fas fa-chart-line" style="color:#10b981"></i></span>
                        <span class="nav-text">Monthly Production Analytics</span>
                    </a>
                </li>

            <?php else: ?>
                <!-- ===================================================== -->
                <!-- UNNATTI PROFILE: BOUTIQUE & RETAIL                    -->
                <!-- ===================================================== -->

                <!-- Dashboard -->
                <li class="nav-item<?= isNavActive('/admin/dashboard', $currentPath) ?>">
                    <a href="<?= $appUrl ?>/admin/dashboard.php">
                        <span class="nav-icon"><i class="fas fa-tachometer-alt"></i></span>
                        <span class="nav-text">Dashboard</span>
                    </a>
                </li>

                <!-- 1. PRODUCT & INVENTORY -->
                <li class="nav-section-label"><span>Product &amp; Inventory</span></li>

                <?php 
                $isProductActive = (strpos($currentPath, '/admin/products') !== false || strpos($currentPath, '/admin/stock') !== false || strpos($currentPath, '/admin/inventory') !== false);
                ?>
                <li class="nav-item<?= $isProductActive ? ' active' : '' ?> has-submenu">
                    <a href="#" class="submenu-toggle" onclick="toggleSubmenu(this)">
                        <span class="nav-icon"><i class="fas fa-boxes"></i></span>
                        <span class="nav-text">Product</span>
                        <span class="nav-arrow"><i class="fas fa-chevron-right"></i></span>
                    </a>
                    <ul class="submenu <?= $isProductActive ? 'open' : '' ?>">
                        <li><a href="<?= $appUrl ?>/admin/products/index.php"><i class="fas fa-tshirt"></i> Shop Products</a></li>
                        <li><a href="<?= $appUrl ?>/admin/products/add.php"><i class="fas fa-plus"></i> Add New Product</a></li>
                        <li><a href="<?= $appUrl ?>/admin/stock/movements.php"><i class="fas fa-exchange-alt"></i> Stock Movements</a></li>
                        <li><a href="<?= $appUrl ?>/admin/inventory/adjustments.php"><i class="fas fa-sliders-h"></i> Stock Adjustments</a></li>
                    </ul>
                </li>

                <!-- 2. SALES & BILLING -->
                <li class="nav-section-label"><span>Sales &amp; Billing</span></li>

                <?php 
                $isBillingActive = (strpos($currentPath, '/admin/billing') !== false || strpos($currentPath, '/admin/customers') !== false);
                ?>
                <li class="nav-item<?= $isBillingActive ? ' active' : '' ?> has-submenu">
                    <a href="#" class="submenu-toggle" onclick="toggleSubmenu(this)">
                        <span class="nav-icon"><i class="fas fa-cash-register"></i></span>
                        <span class="nav-text">Sales &amp; Billing</span>
                        <span class="nav-arrow"><i class="fas fa-chevron-right"></i></span>
                    </a>
                    <ul class="submenu <?= $isBillingActive ? 'open' : '' ?>">
                        <li><a href="<?= $appUrl ?>/admin/billing/create.php"><i class="fas fa-file-invoice"></i> Create Bill / Sale</a></li>
                        <li><a href="<?= $appUrl ?>/admin/billing/index.php"><i class="fas fa-receipt"></i> Bills &amp; Invoices</a></li>
                    </ul>
                </li>

                <!-- 3. ADMINISTRATION (Reports, Employees, Settings) -->
                <li class="nav-section-label"><span>Administration</span></li>

                <!-- Reports -->
                <li class="nav-item<?= isNavActive('/admin/reports', $currentPath) ?> has-submenu">
                    <a href="#" class="submenu-toggle" onclick="toggleSubmenu(this)">
                        <span class="nav-icon"><i class="fas fa-chart-bar"></i></span>
                        <span class="nav-text">Reports</span>
                        <span class="nav-arrow"><i class="fas fa-chevron-right"></i></span>
                    </a>
                    <ul class="submenu <?= strpos($currentPath, '/admin/reports') !== false ? 'open' : '' ?>">
                        <li><a href="<?= $appUrl ?>/admin/reports/index.php?report=sales_summary"><i class="fas fa-chart-line"></i> Sales Summary</a></li>
                        <li><a href="<?= $appUrl ?>/admin/reports/index.php?report=stock_valuation"><i class="fas fa-boxes"></i> Stock Valuation</a></li>
                        <li><a href="<?= $appUrl ?>/admin/reports/index.php?report=product_performance"><i class="fas fa-chart-pie"></i> Material Performance</a></li>
                        <li><a href="<?= $appUrl ?>/admin/reports/index.php?report=customer_ledger"><i class="fas fa-file-invoice-dollar"></i> Customer Dues</a></li>
                    </ul>
                </li>

                <!-- Employees -->
                <li class="nav-item<?= isNavActive('/admin/employees', $currentPath) ?>">
                    <a href="<?= $appUrl ?>/admin/employees/index.php">
                        <span class="nav-icon"><i class="fas fa-user-tie"></i></span>
                        <span class="nav-text">Employees</span>
                    </a>
                </li>

                <!-- Settings -->
                <li class="nav-item<?= isNavActive('/admin/settings', $currentPath) ?>">
                    <a href="<?= $appUrl ?>/admin/settings/index.php">
                        <span class="nav-icon"><i class="fas fa-cog"></i></span>
                        <span class="nav-text">Settings</span>
                    </a>
                </li>

            <?php endif; ?>

        </ul>
    </div>

    <!-- Sidebar Footer -->
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar-sm"><?= strtoupper(substr($user['name'] ?? 'A', 0, 1)) ?></div>
            <div class="user-info">
                <span class="user-name-sm"><?= e($user['name'] ?? 'Admin') ?></span>
                <span class="user-role-sm">Administrator</span>
            </div>
        </div>
    </div>
</nav>

<script>
function toggleBrandDropdown(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    const popup = document.getElementById('brandDropdownPopup');
    const trigger = document.getElementById('brandDropdownTrigger');
    if (!popup) return;
    const willOpen = !popup.classList.contains('open');
    popup.classList.toggle('open', willOpen);
    if (trigger) trigger.classList.toggle('open', willOpen);
}

document.addEventListener('click', function(e) {
    const popup = document.getElementById('brandDropdownPopup');
    const trigger = document.getElementById('brandDropdownTrigger');
    if (popup && popup.classList.contains('open')) {
        if (!popup.contains(e.target) && (!trigger || !trigger.contains(e.target))) {
            popup.classList.remove('open');
            if (trigger) trigger.classList.remove('open');
        }
    }
});
</script>
