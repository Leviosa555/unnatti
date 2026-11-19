<?php
/**
 * Redirect Finished Goods Stock to unified Shop Products or Fashion Kingdom Materials
 */
require_once dirname(dirname(__DIR__)) . '/config/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';

$activeProfile = getActiveProfile();
$queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
if ($activeProfile === 'fashion_kingdom') {
    header('Location: ' . APP_URL . '/admin/purchases/materials.php' . $queryString, true, 302);
} else {
    header('Location: ' . APP_URL . '/admin/products/index.php' . $queryString, true, 302);
}
exit;
