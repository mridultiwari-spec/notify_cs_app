<?php
$logFile = dirname(__DIR__) . 'file/debug_log.txt';
if (!function_exists('set_http_status')) {
    function set_http_status($code) {
        if ($code == 200) {
            header('HTTP/1.1 200 OK');
        } elseif ($code == 401) {
            header('HTTP/1.1 401 Unauthorized');
        } elseif ($code == 400) {
            header('HTTP/1.1 400 Bad Request');
        } elseif ($code == 404) {
            header('HTTP/1.1 404 Not Found');
        } elseif ($code == 500) {
            header('HTTP/1.1 500 Internal Server Error');
        } else {
            header('HTTP/1.1 ' . (int)$code);
        }
    }
}

$shop = isset($_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN']) ? $_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] : '';

if (!$shop) {
    file_put_contents("$logFile", "ERROR: Could not determine shop\n\n", FILE_APPEND);
    set_http_status(200);
    exit;
}

file_put_contents("$logFile", "App uninstalled for shop: $shop\n\n", FILE_APPEND);

set_http_status(200);
?>