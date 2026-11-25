<?php
/**
 * Authentication & Authorization — Unnatti Enterprises Mini ERP
 */

require_once __DIR__ . '/../config/config.php';

// =====================================================
// SESSION MANAGEMENT
// =====================================================

/** Check if user is logged in; redirect to login if not */
function requireLogin(string $redirectTo = ''): void {
    checkSessionTimeout();
    if (!isLoggedIn()) {
        $url = $redirectTo ?: APP_URL . '/login.php';
        header("Location: $url");
        exit;
    }
}

/** Check if logged in */
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

/** Check if current user is admin */
function isAdmin(): bool {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/** Require admin — redirect employees to their employee portal */
function requireAdmin(): void {
    requireLogin();
    if (!isAdmin()) {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($uri, '/admin/products/add.php') !== false) {
            header('Location: ' . APP_URL . '/employee/products/add.php');
            exit;
        }
        if (strpos($uri, '/admin/products') !== false) {
            header('Location: ' . APP_URL . '/employee/products/index.php');
            exit;
        }
        if (strpos($uri, '/admin/billing/create.php') !== false) {
            header('Location: ' . APP_URL . '/employee/billing/create.php');
            exit;
        }
        if (strpos($uri, '/admin/billing') !== false) {
            header('Location: ' . APP_URL . '/employee/billing/index.php');
            exit;
        }
        if (strpos($uri, '/admin/settings') !== false) {
            header('Location: ' . APP_URL . '/employee/settings/index.php');
            exit;
        }
        header('Location: ' . APP_URL . '/employee/dashboard.php');
        exit;
    }
}

/** Require employee or admin */
function requireEmployee(): void {
    requireLogin();
}

/** Get current logged-in user ID */
function currentUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

/** Get current user info from session */
function currentUser(): array {
    return [
        'id'       => $_SESSION['user_id'] ?? 0,
        'name'     => $_SESSION['full_name'] ?? '',
        'username' => $_SESSION['username'] ?? '',
        'role'     => $_SESSION['role'] ?? '',
        'can_enter_stock'    => $_SESSION['can_enter_stock'] ?? 0,
        'can_apply_discount' => $_SESSION['can_apply_discount'] ?? 0,
        'max_discount_pct'   => $_SESSION['max_discount_pct'] ?? 0,
    ];
}

/** Check session timeout */
function checkSessionTimeout(): void {
    if (!isLoggedIn()) return;
    $timeout = (int)(getSetting('session_timeout', '120')) * 60;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        logoutUser();
        header('Location: ' . APP_URL . '/login.php?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();
}

/** Perform login */
function loginUser(array $user): void {
    session_regenerate_id(true);
    $_SESSION['user_id']            = $user['id'];
    $_SESSION['full_name']          = $user['full_name'];
    $_SESSION['username']           = $user['username'];
    $_SESSION['role']               = $user['role'];
    $_SESSION['can_enter_stock']    = $user['can_enter_stock'];
    $_SESSION['can_apply_discount'] = $user['can_apply_discount'];
    $_SESSION['max_discount_pct']   = $user['max_discount_pct'];
    $_SESSION['last_activity']      = time();
    $_SESSION['login_time']         = time();

    // Update last login
    db()->execute("UPDATE users SET last_login = NOW() WHERE id = ?", [$user['id']]);
}

/** Destroy session and log out */
function logoutUser(): void {
    session_unset();
    session_destroy();
    session_start();
    session_regenerate_id(true);
}

// =====================================================
// CSRF PROTECTION
// =====================================================

/** Generate or retrieve CSRF token */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH));
    }
    return $_SESSION['csrf_token'];
}

/** Output hidden CSRF input field */
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

/** Validate CSRF token — die on failure */
function validateCSRF(): void {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals(csrfToken(), $token)) {
        http_response_code(403);
        die(json_encode(['success' => false, 'message' => 'Invalid security token. Please refresh and try again.']));
    }
}

// =====================================================
// PERMISSION CHECKS
// =====================================================

/** Can the current user enter stock? */
function canEnterStock(): bool {
    return isAdmin() || (bool)($_SESSION['can_enter_stock'] ?? false);
}

/** Can the current user apply discounts? */
function canApplyDiscount(): bool {
    return isAdmin() || (bool)($_SESSION['can_apply_discount'] ?? false);
}

/** Get max discount percentage for current user */
function maxDiscountPct(): float {
    if (isAdmin()) return 100.0;
    return (float)($_SESSION['max_discount_pct'] ?? 0);
}
