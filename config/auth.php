<?php
require_once dirname(__FILE__) . '/db.php';
require_once dirname(__FILE__) . '/session_token_auth.php';
require_once __DIR__ . '/../app_config.php';
if (session_id() === '') {
    session_start();
}

$shop = null;
$token = get_bearer_token_php53();

if (!$token && isset($_GET['id_token'])) {
    $token = $_GET['id_token'];
}

if ($token) {
    $validated = validate_shopify_session_token_php53($token, $api_secret, $api_key);
    if ($validated['success']) {
        $shop = $validated['shop'];
    }
}

if (isset($_GET['shop'])) {
    $shop = $_GET['shop'];
} elseif (isset($_SESSION['shop'])) {
    $shop = $_SESSION['shop'];
}

if (!$shop) {
    die("Shop not found");
}

$_SESSION['shop'] = $shop;

$pdo = getDatabaseConnection();
$table  = $prefix . 'shopify_sms_notification_app';

$stmt = $pdo->prepare("SELECT shop FROM $table WHERE shop = :shop");
$stmt->execute(array(':shop' => $shop));

if (!$stmt->fetch()) {
    die("Invalid shop");
}