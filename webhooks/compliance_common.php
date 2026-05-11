<?php
require_once __DIR__ . '/../app_config.php';

if (!function_exists('hash_equals')) {
    function hash_equals($known_string, $user_string) {
        if (!is_string($known_string)) {
            $known_string = (string) $known_string;
        }
        if (!is_string($user_string)) {
            $user_string = (string) $user_string;
        }
        if (strlen($known_string) !== strlen($user_string)) {
            return false;
        }
        $res = 0;
        $len = strlen($known_string);
        for ($i = 0; $i < $len; $i++) {
            $res |= ord($known_string[$i]) ^ ord($user_string[$i]);
        }
        return $res === 0;
    }
}

function compliance_verify_webhook_hmac($payload, $hmacHeader, $secret) {
    if (!$hmacHeader || !$payload) {
        return false;
    }
    $calculated = base64_encode(hash_hmac('sha256', $payload, $secret, true));
    return hash_equals($calculated, $hmacHeader);
}

function compliance_log_event($topic, $shop, $payloadArray) {
    global $logFile;
    //$logFile = __DIR__ . '/compliance_webhooks.log';
    $entry = array(
        'received_at' => date('c'),
        'topic' => $topic,
        'shop' => $shop,
        'payload' => $payloadArray
    );
    @file_put_contents($logFile, json_encode($entry) . "\n", FILE_APPEND);
}

function compliance_handle_request() {
    global $api_secret;
    $payload = file_get_contents('php://input');
    $hmacHeader = isset($_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256']) ? $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] : '';
    $topic = isset($_SERVER['HTTP_X_SHOPIFY_TOPIC']) ? $_SERVER['HTTP_X_SHOPIFY_TOPIC'] : '';
    $shop = isset($_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN']) ? $_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] : '';

    if (!compliance_verify_webhook_hmac($payload, $hmacHeader, $api_secret)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(array('ok' => false, 'message' => 'Invalid webhook signature'));
        exit;
    }

    $payloadArray = json_decode($payload, true);
    if (!is_array($payloadArray)) {
        $payloadArray = array('raw' => $payload);
    }

    compliance_log_event($topic, $shop, $payloadArray);

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(array('ok' => true));
    exit;
}
