<?php
/**
 * HTML Head + Top Navigation Bar
 * Usage: include at top of every page
 * Variables expected: $pageTitle (string), $activePage (string)
 */
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

$businessName = getSetting('business_name', 'Unnatti Enterprises');
$activeProfile = getActiveProfile();
$displayBusinessName = ($activeProfile === 'fashion_kingdom') ? 'Fashion Kingdom' : $businessName;
$user         = currentUser();
$pageTitle    = $pageTitle ?? 'Dashboard';
$activePage   = $activePage ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= e($displayBusinessName) ?> — Mini ERP Management System">
    <title><?= e($pageTitle) ?> — <?= e($displayBusinessName) ?></title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

    <!-- App CSS -->
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">

    <?php if (isset($extraCss)) echo $extraCss; ?>
</head>
<body>

<!-- Sidebar Overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

<div class="wrapper" id="wrapper">

    <!-- ==================== SIDEBAR ==================== -->
    <?php
    $role = $user['role'] ?? 'employee';
    if ($role === 'admin') {
        include dirname(__DIR__) . '/includes/sidebar.php';
    } else {
        include dirname(__DIR__) . '/includes/employee_sidebar.php';
    }
    ?>

    <!-- ==================== MAIN CONTENT ==================== -->
    <div class="main-content" id="mainContent">

        <!-- TOP BAR -->
        <header class="topbar" id="topbar">
            <div class="topbar-left">
                <button class="sidebar-toggle-btn" id="sidebarToggle" onclick="toggleSidebar()" title="Toggle Sidebar">
                    <i class="fas fa-bars"></i>
                </button>
                <!-- Breadcrumb -->
                <nav class="breadcrumb-nav" aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item">
                            <a href="<?= APP_URL ?>/<?= $role === 'admin' ? ($activeProfile === 'fashion_kingdom' ? 'admin/purchases/materials.php' : 'admin/dashboard.php') : 'employee/dashboard.php' ?>">
                                <i class="fas fa-home"></i>
                            </a>
                        </li>
                        <?php if (!empty($breadcrumb)): foreach ($breadcrumb as $bc): ?>
                        <li class="breadcrumb-item <?= $bc === end($breadcrumb) ? 'active' : '' ?>">
                            <?= isset($bc['url']) ? '<a href="' . e($bc['url']) . '">' . e($bc['label']) . '</a>' : e($bc['label'] ?? $bc) ?>
                        </li>
                        <?php endforeach; endif; ?>
                    </ol>
                </nav>
            </div>

            <div class="topbar-right">
                <!-- Notifications -->
                <div class="topbar-item dropdown" id="notifDropdown">
                    <button class="topbar-btn" onclick="toggleDropdown('notifMenu')" title="Notifications">
                        <i class="fas fa-bell"></i>
                        <?php
                        $notifLowStockCount = (int)(db()->fetchOne("SELECT COUNT(*) as cnt FROM product_variants WHERE current_stock <= min_stock_alert AND current_stock > 0 AND status='active'")['cnt'] ?? 0);
                        $notifOutStockCount = (int)(db()->fetchOne("SELECT COUNT(*) as cnt FROM product_variants WHERE current_stock = 0 AND status='active'")['cnt'] ?? 0);
                        $notifCount = $notifLowStockCount + $notifOutStockCount;
                        if ($notifCount > 0): ?>
                        <span class="badge-dot"><?= $notifCount ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="dropdown-menu dropdown-menu-right" id="notifMenu">
                        <div class="dropdown-header">
                            <span>Notifications</span>
                            <?php if ($notifCount > 0): ?>
                            <span class="badge badge-danger"><?= $notifCount ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($notifLowStockCount > 0): ?>
                        <a href="<?= APP_URL ?>/<?= ($activeProfile === 'fashion_kingdom') ? 'admin/purchases/materials.php?filter=low_stock' : 'admin/products/index.php?filter=low_stock' ?>" class="dropdown-item">
                            <i class="fas fa-exclamation-triangle text-warning"></i>
                            <?= $notifLowStockCount ?> item(s) with low stock
                        </a>
                        <?php endif; ?>
                        <?php if ($notifOutStockCount > 0): ?>
                        <a href="<?= APP_URL ?>/<?= ($activeProfile === 'fashion_kingdom') ? 'admin/purchases/materials.php?filter=out_of_stock' : 'admin/products/index.php?filter=out_of_stock' ?>" class="dropdown-item">
                            <i class="fas fa-times-circle text-danger"></i>
                            <?= $notifOutStockCount ?> item(s) out of stock
                        </a>
                        <?php endif; ?>
                        <?php if ($notifCount === 0): ?>
                        <div class="dropdown-item text-muted text-center">No new notifications</div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- User Profile -->
                <div class="topbar-item dropdown" id="userDropdown">
                    <button class="topbar-user-btn" onclick="toggleDropdown('userMenu')">
                        <div class="user-avatar"><?= strtoupper(substr($user['name'], 0, 1)) ?></div>
                        <span class="user-name"><?= e(explode(' ', $user['name'])[0]) ?></span>
                        <i class="fas fa-chevron-down"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-right" id="userMenu">
                        <div class="dropdown-header">
                            <strong><?= e($user['name']) ?></strong>
                            <small class="text-muted text-capitalize"><?= e($user['role']) ?></small>
                        </div>
                        <div class="dropdown-divider"></div>
                        <a href="<?= APP_URL ?>/change_password.php" class="dropdown-item">
                            <i class="fas fa-key"></i> Change Password
                        </a>
                        <?php if ($role === 'admin'): ?>
                        <a href="<?= APP_URL ?>/admin/settings/index.php" class="dropdown-item">
                            <i class="fas fa-cog"></i> Settings
                        </a>
                        <?php endif; ?>
                        <div class="dropdown-divider"></div>
                        <a href="<?= APP_URL ?>/logout.php" class="dropdown-item text-danger">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Page Content Starts -->
        <div class="content-area" id="contentArea">

            <!-- Flash Messages -->
            <?php if (isset($_SESSION['flash_success'])): ?>
            <div class="alert alert-success alert-dismissible">
                <i class="fas fa-check-circle"></i>
                <?= e($_SESSION['flash_success']) ?>
                <button type="button" class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
            <?php unset($_SESSION['flash_success']); endif; ?>

            <?php if (isset($_SESSION['flash_error'])): ?>
            <div class="alert alert-danger alert-dismissible">
                <i class="fas fa-exclamation-circle"></i>
                <?= e($_SESSION['flash_error']) ?>
                <button type="button" class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
            <?php unset($_SESSION['flash_error']); endif; ?>
