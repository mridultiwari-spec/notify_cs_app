<?php

session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/config/session_token_auth.php';

function writeToggleLog($message, $type = 'INFO')
{
    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents('/var/www/html/notifycsapp/file/debug_log.txt', "[{$timestamp}] [{$type}] {$message}\n", FILE_APPEND);
}

function webhooksTableExists($pdo)
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'webhooks'");
        $exists = $stmt && $stmt->fetch(PDO::FETCH_NUM) ? true : false;
    } catch (Exception $e) {
        $exists = false;
    }
    return $exists;
}

function hasInvalidTokenError($response)
{
    if (!is_array($response) || !isset($response['errors'])) {
        return false;
    }
    $errorText = '';
    if (is_string($response['errors'])) {
        $errorText = $response['errors'];
    } else {
        $errorText = json_encode($response['errors']);
    }
    return (stripos($errorText, 'Invalid API key or access token') !== false);
}

$rawInput = file_get_contents("php://input");

$data = json_decode($rawInput, true);

if (!$data) {
    writeToggleLog('webhook_toggle invalid JSON payload', 'ERROR');
    exit(json_encode(array("error" => "Invalid JSON")));
}

$shop = isset($data['shop']) ? $data['shop'] : '';
$action = isset($data['action']) ? (int) $data['action'] : null;
$topic = isset($data['topic']) ? $data['topic'] : '';
$channel = isset($data['channel']) ? $data['channel'] : '';

if ($action === null || !$topic || !$channel) {
    exit(json_encode(array("error" => "Invalid input")));
}

session_write_close();
try {
    $pdo = getDatabaseConnection();
    $configTable = $prefix . "shopify_sms_notification_app";
    $dataTable = $prefix . "shopify_sms_notification_App_Email_Notification";
    $webhookTable = "$prefix". "webhooks";

    $sessionToken = get_bearer_token_php53();
    if (!$sessionToken && isset($data['id_token'])) {
        $sessionToken = preg_replace('/\s+/', '', trim($data['id_token']));
    }
    if (!$sessionToken) {
        exit(json_encode(array("error" => "Missing session token")));
    }
    $tokenValidation = validate_shopify_session_token_php53($sessionToken, $api_secret, $api_key);
    if (!$tokenValidation['success']) {
        exit(json_encode(array("error" => $tokenValidation['error'])));
    }
    $shop = $tokenValidation['shop'];
    $_SESSION['shop'] = $shop;

    $tokenState = get_valid_shop_access_token_php53($pdo, $configTable, $shop, $api_key, $api_secret, $sessionToken);
    if (!$tokenState['success']) {
        exit(json_encode(array("error" => $tokenState['error'])));
    }
    $accessToken = $tokenState['access_token'];
    $apiVersion = "2026-01";

    $base_url = $app_url . '/webhooks/';

    $topicCallbackMap = array(
        'orders/create' => $base_url . 'order-create.php',
        'orders/updated' => $base_url . 'order-updated.php',
        'orders/cancelled' => $base_url . 'order-cancelled.php',
        'refunds/create' => $base_url . 'refund-create.php',
        'checkouts/update' => $base_url . 'checkout-update.php',
        'fulfillments/create' => $base_url . 'fulfillment-create.php',
        'orders/fulfilled' => $base_url . 'order-fulfilled.php',
        'fulfillments/update' => $base_url . 'fulfillment-update.php',
        'customers/update' => $base_url . 'customer-update.php',
        'customers/enable' => $base_url . 'customer-enable.php',
        // 'customer.joined_segment' => $base_url . 'customer-joined-segment.php',
    );
    $topicAidMap = array(
        'orders/create' => 1,
        'orders/updated' => 2,
        'orders/cancelled' => 3,
        'refunds/create' => 4,
        'checkouts/update' => 5,
        'fulfillments/create' => 6,
        'orders/fulfilled' => 7,
        'fulfillments/update' => 8,
        'customers/update' => 9,
        'customers/enable' => 10,
        // 'customer.joined_segment' => 16,
    );
    if (!isset($topicCallbackMap[$topic])) {
        exit(json_encode(array("error" => "Invalid topic")));
    }
    $callbackUrl = $topicCallbackMap[$topic];
    $aid = $topicAidMap[$topic];

    $templateCheckStmt = $pdo->prepare("
    SELECT id
    FROM $dataTable
    WHERE shop = :shop AND aid = :aid
    LIMIT 1
");
    $templateCheckStmt->execute(array(':shop' => $shop, ':aid' => $aid));
    $templateRow = $templateCheckStmt->fetch(PDO::FETCH_ASSOC);
    if (!$templateRow) {
        exit(json_encode(array(
            "error" => "Please configure the template first for this event."
        )));
    }

    if ($channel === 'sms') {
        $pdo->prepare("
        UPDATE $dataTable 
        SET sms_enabled = :val 
        WHERE shop = :shop AND aid = :aid
    ")->execute(array(':val' => $action, ':shop' => $shop, ':aid' => $aid));
    } else {
        $pdo->prepare("
        UPDATE $dataTable 
        SET whatsapp_enabled = :val 
        WHERE shop = :shop AND aid = :aid
    ")->execute(array(':val' => $action, ':shop' => $shop, ':aid' => $aid));
    }
    $stmt = $pdo->prepare("
    SELECT MAX(sms_enabled) sms, MAX(whatsapp_enabled) wa
    FROM $dataTable WHERE shop = :shop AND aid = :aid
");
    $stmt->execute(array(':shop' => $shop, ':aid' => $aid));
    $status = $stmt->fetch();

    $sms = (int) $status['sms'];
    $wa = (int) $status['wa'];
    function getWebhook($shop, $token, $apiVersion, $topic, $url)
    {
        $ch = curl_init("https://$shop/admin/api/$apiVersion/webhooks.json");
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => array("X-Shopify-Access-Token: $token")
        ));
        $res = json_decode(curl_exec($ch), true);
        $webhooks = isset($res['webhooks']) ? $res['webhooks'] : array();
        foreach ($webhooks as $wh) {
            if ($wh['topic'] === $topic && $wh['address'] === $url) {
                return $wh['id'];
            }
        }
        return null;
    }
    $existingWebhookId = getWebhook($shop, $accessToken, $apiVersion, $topic, $callbackUrl);
    if ($sms === 0 && $wa === 0 && $existingWebhookId) {
        $ch = curl_init("https://$shop/admin/api/$apiVersion/webhooks/$existingWebhookId.json");
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => "DELETE",
            CURLOPT_HTTPHEADER => array("X-Shopify-Access-Token: $accessToken")
        ));
        curl_exec($ch);
        if (webhooksTableExists($pdo)) {
            $pdo->prepare("DELETE FROM $webhookTable WHERE shop=:shop AND topic=:topic")->execute(array(':shop' => $shop, ':topic' => $topic));
        }
        exit(json_encode(array("status" => "deleted")));
    }
    if (($sms === 1 || $wa === 1) && !$existingWebhookId) {
        $payload = json_encode(array(
            "webhook" => array(
                "topic" => $topic,
                "address" => $callbackUrl,
                "format" => "json"
            )
        ));
        $ch = curl_init("https://$shop/admin/api/$apiVersion/webhooks.json");
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => array(
                "Content-Type: application/json",
                "X-Shopify-Access-Token: $accessToken"
            )
        ));
        $res = json_decode(curl_exec($ch), true);

        if (isset($res['webhook']['id'])) {
            if (webhooksTableExists($pdo)) {
                $pdo->prepare("
                INSERT INTO $webhookTable (shop, topic, webhook_id)
                VALUES (:shop, :topic, :id)
                ON DUPLICATE KEY UPDATE webhook_id=:id
            ")->execute(array(
                            ':shop' => $shop,
                            ':topic' => $topic,
                            ':id' => $res['webhook']['id']
                        ));
            }
            exit(json_encode(array("status" => "created")));
        }
        if (hasInvalidTokenError($res)) {
            writeToggleLog('webhook_toggle invalid token on create; attempting forced renewal for shop ' . $shop, 'ERROR');
            $renewed = get_valid_shop_access_token_php53($pdo, $configTable, $shop, $api_key, $api_secret, $sessionToken, true);
            if ($renewed['success'] && !empty($renewed['access_token'])) {
                $accessToken = $renewed['access_token'];
                $retryPayload = json_encode(array(
                    "webhook" => array(
                        "topic" => $topic,
                        "address" => $callbackUrl,
                        "format" => "json"
                    )
                ));
                $retryCh = curl_init("https://$shop/admin/api/$apiVersion/webhooks.json");
                curl_setopt_array($retryCh, array(
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $retryPayload,
                    CURLOPT_HTTPHEADER => array(
                        "Content-Type: application/json",
                        "X-Shopify-Access-Token: $accessToken"
                    )
                ));
                $retryRes = json_decode(curl_exec($retryCh), true);
                if (isset($retryRes['webhook']['id'])) {
                    if (webhooksTableExists($pdo)) {
                        $pdo->prepare("
                        INSERT INTO $webhookTable (shop, topic, webhook_id)
                        VALUES (:shop, :topic, :id)
                        ON DUPLICATE KEY UPDATE webhook_id=:id
                    ")->execute(array(
                                    ':shop' => $shop,
                                    ':topic' => $topic,
                                    ':id' => $retryRes['webhook']['id']
                                ));
                    }
                    exit(json_encode(array("status" => "created")));
                }
            }
            writeToggleLog('webhook_toggle forced renewal failed for shop ' . $shop, 'ERROR');
            exit(json_encode(array("error" => "Shop token expired or invalid.")));
        }
        writeToggleLog('webhook_toggle create_failed for topic ' . $topic . ' | response=' . json_encode($res), 'ERROR');
        exit(json_encode(array("error" => "create_failed", "res" => $res)));
    }
    echo json_encode(array(
        "status" => "no_change",
        "sms" => $sms,
        "wa" => $wa
    ));
} catch (Exception $e) {
    writeToggleLog('webhook_toggle exception: ' . $e->getMessage(), 'ERROR');
    echo json_encode(array("error" => "Unable to process webhook toggle."));
}