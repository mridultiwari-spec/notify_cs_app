<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app_config.php';
require_once __DIR__ . '/../config/session_token_auth.php';
ini_set('max_execution_time', 0);
set_time_limit(0);
if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('UTC');
}
$debugMode = isset($_GET['debug']) && $_GET['debug'] == '1';
$debugSteps = array();
function debugStep($message)
{
    global $debugMode, $debugSteps;
    if ($debugMode) {
        $debugSteps[] = date('H:i:s') . ' | ' . $message;
    }
}
if ($debugMode) {
    @ini_set('display_errors', '1');
    @ini_set('display_startup_errors', '1');
    @error_reporting(E_ALL);
    register_shutdown_function(function () {
        global $debugSteps;
        $error = error_get_last();
        if ($error && isset($error['type']) && in_array($error['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR))) {
            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=utf-8');
            }
            echo "=== customer_segment.php DEBUG FATAL ===\n";
            foreach ($debugSteps as $line) {
                echo $line . "\n";
            }
            echo "FATAL: " . $error['message'] . "\n";
            echo "FILE: " . $error['file'] . "\n";
            echo "LINE: " . $error['line'] . "\n";
        }
    });
}
function writeDebugLog($message, $level = "INFO")
{
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] [{$level}] {$message}" . PHP_EOL;
    $logFile = dirname(__DIR__) . '/file/debug_log.txt';
    $logDir = dirname($logFile);
    if ((is_dir($logDir) && is_writable($logDir)) || (file_exists($logFile) && is_writable($logFile))) {
        @file_put_contents($logFile, $logMessage, FILE_APPEND);
    }
    error_log('[customer_segment] ' . $message);
    debugStep($level . ': ' . $message);
}

function getSessionTokenFromRequest()
{

    $headers = array();
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } elseif (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
    }

    foreach ($headers as $key => $value) {
        if (strtolower($key) === 'authorization') {
            if (preg_match('/Bearer\s+(.*)$/i', $value, $matches)) {
                return trim($matches[1]);
            }
        }
    }


    if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        if (preg_match('/Bearer\s+(.*)$/i', $_SERVER['HTTP_AUTHORIZATION'], $matches)) {
            return trim($matches[1]);
        }
    }


    if (isset($_SESSION['shopify_session_token'])) {
        return $_SESSION['shopify_session_token'];
    }

    return null;
}

function updateAccessTokenInDatabase($shop, $newToken)
{
    global $pdo, $prefix;
    $tables = $prefix . "shopify_sms_notification_app";

    try {
        $stmt = $pdo->prepare("UPDATE $tables SET session_access_token = :token, session_token_updated_at = NOW() WHERE shop = :shop");
        $stmt->execute(array(':token' => $newToken, ':shop' => $shop));
        writeDebugLog("Access token updated in database for shop: $shop");
        return true;
    } catch (Exception $e) {
        writeDebugLog("Failed to update token in DB: " . $e->getMessage(), "ERROR");
        return false;
    }
}


function markShopNeedsReauth($shop)
{
    global $pdo, $prefix;
    $tables = $prefix . "shopify_sms_notification_app";

    try {

        $stmt = $pdo->query("SHOW COLUMNS FROM $tables LIKE 'needs_reauth'");
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE $tables ADD COLUMN needs_reauth TINYINT DEFAULT 0");
        }

        $stmt = $pdo->prepare("UPDATE $tables SET needs_reauth = 1 WHERE shop = :shop");
        $stmt->execute(array(':shop' => $shop));
        writeDebugLog("Marked shop $shop as needing re-authentication", "WARN");
    } catch (Exception $e) {
        writeDebugLog("Failed to mark reauth needed: " . $e->getMessage(), "ERROR");
    }
}

function triggerBackgroundSync($url, $shop, $segmentId, $localSegmentId)
{
    $postFields = http_build_query(array(
        'sync_customers' => 1,
        'shop' => $shop,
        'segment_id' => $segmentId,
        'local_segment_id' => $localSegmentId
    ));
    error_log("[BackgroundSync] Triggering sync for segment $localSegmentId via: $url");
    $execAvailable = function_exists('exec');
    $disabledFunctions = explode(',', ini_get('disable_functions'));
    $disabledFunctions = array_map('trim', $disabledFunctions);

    if ($execAvailable && !in_array('exec', $disabledFunctions)) {
        $command = sprintf(
            'curl -s --max-time 1 --connect-timeout 1 -X POST -d %s %s > /dev/null 2>&1 &',
            escapeshellarg($postFields),
            escapeshellarg($url)
        );
        @exec($command);
        error_log("[BackgroundSync] exec() method used");
        return;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT_MS => 500,
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/x-www-form-urlencoded',
                'Connection: Close'
            ),
            CURLOPT_HEADER => false,
            CURLOPT_NOBODY => false,
        ));

        curl_exec($ch);
        curl_close($ch);
        error_log("[BackgroundSync] cURL method used");
        return;
    }
    $urlParts = parse_url($url);
    $host = $urlParts['host'];
    $port = isset($urlParts['port']) ? $urlParts['port'] : (isset($urlParts['scheme']) && $urlParts['scheme'] == 'https' ? 443 : 80);
    $path = isset($urlParts['path']) ? $urlParts['path'] : '/';
    $scheme = isset($urlParts['scheme']) ? $urlParts['scheme'] : 'http';

    $fp = @fsockopen(
        ($scheme == 'https' ? 'ssl://' : '') . $host,
        $port,
        $errno,
        $errstr,
        5
    );
    if ($fp) {
        stream_set_blocking($fp, 0);

        $header = "POST $path HTTP/1.1\r\n";
        $header .= "Host: $host\r\n";
        $header .= "Content-Type: application/x-www-form-urlencoded\r\n";
        $header .= "Content-Length: " . strlen($postFields) . "\r\n";
        $header .= "Connection: Close\r\n\r\n";
        $header .= $postFields;

        fwrite($fp, $header);
        fclose($fp);
        error_log("[BackgroundSync] fsockopen method used");
    } else {
        error_log("[BackgroundSync] All methods failed");
    }
}

function getExistingSegmentWebhook($pdo, $prefix, $shop, $segmentGid)
{
    $webhooksTable = $prefix . "webhooks";
    $topic = 'customer.joined_segment';

    try {
        $uniqueTopic = $topic . ':' . $segmentGid;
        $stmt = $pdo->prepare("SELECT webhook_id FROM {$webhooksTable} WHERE shop = :shop AND topic = :topic LIMIT 1");
        $stmt->execute(array(':shop' => $shop, ':topic' => $uniqueTopic));
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result && !empty($result['webhook_id'])) {
            return $result['webhook_id'];
        }
        return null;
    } catch (Exception $e) {
        writeDebugLog("Error getting existing webhook: " . $e->getMessage(), "ERROR");
        return null;
    }
}

function saveWebhookToDatabase($pdo, $prefix, $shop, $segmentGid, $segmentName, $webhookId, $channel = 'sms')
{
    $webhooksTable = $prefix . "webhooks";
    $topic = 'customer.joined_segment';

    try {
        $uniqueTopic = $topic . ':' . $segmentGid;
        $stmt = $pdo->prepare("SELECT id FROM {$webhooksTable} WHERE shop = :shop AND topic = :topic");
        $stmt->execute(array(':shop' => $shop, ':topic' => $uniqueTopic));

        if ($stmt->fetch()) {
            $stmt = $pdo->prepare("
                UPDATE {$webhooksTable} 
                SET webhook_id = :webhook_id, channel = :channel
                WHERE shop = :shop AND topic = :topic
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO {$webhooksTable} (shop, topic, webhook_id, channel) 
                VALUES (:shop, :topic, :webhook_id, :channel)
            ");
        }

        $stmt->execute(array(
            ':shop' => $shop,
            ':topic' => $uniqueTopic,
            ':webhook_id' => $webhookId,
            ':channel' => $channel
        ));
        writeDebugLog("Saved webhook to database for segment: {$segmentName} with webhook_id: {$webhookId}");
        return true;
    } catch (Exception $e) {
        writeDebugLog("Error saving webhook to database: " . $e->getMessage(), "ERROR");
        return false;
    }
}

function deleteWebhookFromDatabase($pdo, $prefix, $shop, $segmentGid)
{
    $webhooksTable = $prefix . "webhooks";
    $topic = 'customer.joined_segment';
    $uniqueTopic = $topic . ':' . $segmentGid;

    try {
        $stmt = $pdo->prepare("DELETE FROM {$webhooksTable} WHERE shop = :shop AND topic = :topic");
        $stmt->execute(array(':shop' => $shop, ':topic' => $uniqueTopic));
        writeDebugLog("Deleted webhook from database for segment: {$segmentGid}");
        return true;
    } catch (Exception $e) {
        writeDebugLog("Error deleting webhook from database: " . $e->getMessage(), "ERROR");
        return false;
    }
}

function registerCustomerJoinedSegmentWebhook($shop, $accessToken, $segmentGid, $segmentName)
{
    global $app_url, $pdo, $prefix;

    $webhookEndpoint = rtrim($app_url, '/') . '/webhooks/segmentJoin2.php';
    $apiVersion = "2026-01";
    $filter = 'segmentId:"' . $segmentGid . '"';

    $query = '
    mutation webhookSubscriptionCreate($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) {
        webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
            webhookSubscription {
                id
                topic
                filter
                uri
            }
            userErrors {
                field
                message
            }
        }
    }';

    $variables = array(
        'topic' => 'CUSTOMER_JOINED_SEGMENT',
        'webhookSubscription' => array(
            'callbackUrl' => $webhookEndpoint,
            'format' => 'JSON',
            'filter' => $filter
        )
    );
    writeDebugLog("Registering webhook for segment: {$segmentName}");
    writeDebugLog("Segment Numeric ID: {$segmentGid}");
    writeDebugLog("Filter: {$filter}");
    writeDebugLog("Webhook endpoint: {$webhookEndpoint}");

    $result = makeShopifyGraphQLRequest($shop, $accessToken, $query, $variables, $apiVersion, null);

    $GLOBALS['webhook_debug'] = array();

    if (isset($result['error'])) {
        $GLOBALS['webhook_debug'] = array(
            'error_type' => 'curl_error',
            'message' => $result['error']
        );
        writeDebugLog("GraphQL cURL error: " . $result['error'], "ERROR");
        return false;
    }

    if (isset($result['errors'])) {
        $GLOBALS['webhook_debug'] = array(
            'error_type' => 'graphql_errors',
            'message' => $result['errors']
        );
        writeDebugLog("GraphQL errors: " . json_encode($result['errors']), "ERROR");
        return false;
    }

    if (isset($result['data']['webhookSubscriptionCreate']['userErrors']) && !empty($result['data']['webhookSubscriptionCreate']['userErrors'])) {
        $userErrors = $result['data']['webhookSubscriptionCreate']['userErrors'];
        $GLOBALS['webhook_debug'] = array(
            'error_type' => 'user_errors',
            'message' => $userErrors
        );
        writeDebugLog("User errors: " . json_encode($userErrors), "ERROR");
        return false;
    }
    $webhookId = isset($result['data']['webhookSubscriptionCreate']['webhookSubscription']['id'])
        ? $result['data']['webhookSubscriptionCreate']['webhookSubscription']['id']
        : null;

    if ($webhookId) {
        if (preg_match('/\/(\d+)$/', $webhookId, $matches)) {
            $numericWebhookId = $matches[1];
        } else {
            $numericWebhookId = $webhookId;
        }

        writeDebugLog("Successfully registered webhook for segment '{$segmentName}'. Webhook ID: {$numericWebhookId}");

        saveWebhookToDatabase($pdo, $prefix, $shop, $segmentGid, $segmentName, $numericWebhookId, 'sms');

        $GLOBALS['webhook_debug'] = array(
            'success' => true,
            'webhook_id' => $numericWebhookId,
            'message' => 'Webhook registered successfully',
            'endpoint' => $webhookEndpoint
        );
        return true;
    }

    $GLOBALS['webhook_debug'] = array(
        'error_type' => 'no_webhook_id',
        'message' => 'No webhook ID in response',
        'full_response' => $result
    );
    writeDebugLog("Failed to register webhook for segment: {$segmentName}", "ERROR");
    return false;
}

function unregisterCustomerJoinedSegmentWebhook($shop, $accessToken, $segmentGid, $webhookId)
{
    global $pdo, $prefix;
    $apiVersion = "2026-01";

    $query = '
    mutation webhookSubscriptionDelete($id: ID!) {
        webhookSubscriptionDelete(id: $id) {
            deletedWebhookSubscriptionId
            userErrors {
                field
                message
            }
        }
    }';

    if (is_numeric($webhookId) && strpos($webhookId, 'gid://') !== 0) {
        $webhookGid = "gid://shopify/WebhookSubscription/{$webhookId}";
    } else {
        $webhookGid = $webhookId;
    }

    $variables = array('id' => $webhookGid);

    writeDebugLog("Unregistering webhook for segment: {$segmentGid} with webhook ID: {$webhookGid}");

    $result = makeShopifyGraphQLRequest($shop, $accessToken, $query, $variables, $apiVersion, null);

    if (isset($result['errors'])) {
        writeDebugLog("Error unregistering webhook: " . json_encode($result['errors']), "ERROR");
        return false;
    }

    $deletedId = isset($result['data']['webhookSubscriptionDelete']['deletedWebhookSubscriptionId'])
        ? $result['data']['webhookSubscriptionDelete']['deletedWebhookSubscriptionId']
        : null;

    if ($deletedId) {
        writeDebugLog("Successfully unregistered webhook for segment: {$segmentGid}");
        deleteWebhookFromDatabase($pdo, $prefix, $shop, $segmentGid);
        return true;
    }

    writeDebugLog("Failed to unregister webhook for segment: {$segmentGid}", "ERROR");
    return false;
}

function manageSegmentWebhook($pdo, $prefix, $shop, $accessToken, $segmentDbId, $segmentGid, $segmentName)
{
    $customerSegmentTable = $prefix . "customer_segment";

    try {
        $stmt = $pdo->prepare("SELECT sms_enabled, whatsapp_enabled FROM {$customerSegmentTable} WHERE id = :id AND shop = :shop");
        $stmt->execute(array(':id' => $segmentDbId, ':shop' => $shop));
        $status = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$status) {
            writeDebugLog("Segment not found for ID: {$segmentDbId}", "ERROR");
            return false;
        }

        $smsEnabled = (int) $status['sms_enabled'];
        $whatsappEnabled = (int) $status['whatsapp_enabled'];
        $shouldHaveWebhook = ($smsEnabled == 1 || $whatsappEnabled == 1);

        $existingWebhookId = getExistingSegmentWebhook($pdo, $prefix, $shop, $segmentGid);

        if ($shouldHaveWebhook && !$existingWebhookId) {
            writeDebugLog("Segment '{$segmentName}' needs webhook registration (SMS: {$smsEnabled}, WhatsApp: {$whatsappEnabled})");
            return registerCustomerJoinedSegmentWebhook($shop, $accessToken, $segmentGid, $segmentName);

        } elseif (!$shouldHaveWebhook && $existingWebhookId) {
            writeDebugLog("Segment '{$segmentName}' no longer needs webhook");
            return unregisterCustomerJoinedSegmentWebhook($shop, $accessToken, $segmentGid, $existingWebhookId);

        } else {
            writeDebugLog("No webhook action needed for segment '{$segmentName}'");
            return true;
        }

    } catch (Exception $e) {
        writeDebugLog("Error managing segment webhook: " . $e->getMessage(), "ERROR");
        return false;
    }
}
// debugStep('Script start');
// $shop = isset($_SESSION['shop']) ? $_SESSION['shop'] : (isset($_POST['shop']) ? $_POST['shop'] : (isset($_GET['shop']) ? $_GET['shop'] : ''));
// if (!$shop) {
//     die("Shop not found");
// }

// session_write_close();

debugStep('Script start');
$shop = isset($_SESSION['shop']) ? $_SESSION['shop'] : (isset($_POST['shop']) ? $_POST['shop'] : (isset($_GET['shop']) ? $_GET['shop'] : ''));
if (!$shop) {
    die("Shop not found");
}

$sessionToken = getSessionTokenFromRequest();
if ($sessionToken) {
    $_SESSION['shopify_session_token'] = $sessionToken;
    writeDebugLog("Session token stored in session");
}

session_write_close();

function makeShopifyGraphQLRequest($shop, $accessToken, $query, $variables = array(), $apiVersion = "2026-01", $sessionToken = null)
{
    global $prefix, $api_key, $api_secret, $pdo;

    $url = "https://{$shop}/admin/api/{$apiVersion}/graphql.json";
    $payload = json_encode(array(
        'query' => $query,
        'variables' => $variables
    ));

    writeDebugLog("Making GraphQL request to: {$url}");
    writeDebugLog("GraphQL Payload: " . substr($payload, 0, 500));

    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => array(
            "Content-Type: application/json",
            "X-Shopify-Access-Token: {$accessToken}"
        ),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        writeDebugLog("GraphQL cURL error: " . $error, "ERROR");
        curl_close($ch);
        return array('error' => $error);
    }
    curl_close($ch);

    writeDebugLog("GraphQL Response Code: {$httpCode}");

    $result = json_decode($response, true);

    if ($httpCode === 401 || $httpCode === 403) {
        writeDebugLog("Token expired (HTTP {$httpCode}). Attempting refresh...", "WARN");

        if (!$sessionToken) {
            $sessionToken = getSessionTokenFromRequest();
        }

        $newToken = refreshShopAccessToken($shop, $sessionToken);

        if ($newToken) {
            writeDebugLog("Token refreshed successfully. Retrying request...");

            updateAccessTokenInDatabase($shop, $newToken);

            $ch = curl_init($url);
            curl_setopt_array($ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => array(
                    "Content-Type: application/json",
                    "X-Shopify-Access-Token: {$newToken}"
                ),
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => false
            ));
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            writeDebugLog("Retry Response Code: {$httpCode}");

            global $accessToken;
            $accessToken = $newToken;

            $retryResult = json_decode($response, true);

            if ($httpCode === 401 || $httpCode === 403) {
                writeDebugLog("Still getting 401 after token refresh", "ERROR");
                markShopNeedsReauth($shop);
                return array('error' => 'Authentication failed after refresh', 'needs_reauth' => true);
            }

            return $retryResult;
        } else {
            writeDebugLog("Token refresh failed! User needs to re-authenticate.", "ERROR");
            return array('error' => 'Token refresh failed', 'needs_reauth' => true);
        }
    }

    return $result;
}

function refreshShopAccessToken($shop, $sessionToken = null)
{
    global $prefix, $api_key, $api_secret, $pdo;

    try {

        if (!$sessionToken) {
            $sessionToken = getSessionTokenFromRequest();
        }

        if (!$sessionToken) {
            writeDebugLog("No session token available for refresh", "ERROR");
            markShopNeedsReauth($shop);
            return false;
        }

        $tables = $prefix . "shopify_sms_notification_app";


        $result = get_valid_shop_access_token_php53(
            $pdo,
            $tables,
            $shop,
            $api_key,
            $api_secret,
            $sessionToken,
            true
        );

        if ($result['success']) {
            writeDebugLog("Token refreshed successfully via: " . $result['source']);
            return $result['access_token'];
        } else {
            writeDebugLog("Token refresh failed: " . $result['error'], "ERROR");
            markShopNeedsReauth($shop);
            return false;
        }

    } catch (Exception $e) {
        writeDebugLog("Token refresh exception: " . $e->getMessage(), "ERROR");
        markShopNeedsReauth($shop);
        return false;
    }
}

$isAjaxRequest = ($_SERVER['REQUEST_METHOD'] === 'POST') &&
    (isset($_POST['update_status']) ||
        isset($_POST['get_template_data']) ||
        isset($_POST['check_sync_status']));

if ($isAjaxRequest) {
    $pdo = getDatabaseConnection();
    $customerSegmentTable = $prefix . "customer_segment";

    // if (isset($_POST['update_status'])) {
    //     $id = isset($_POST['id']) ? $_POST['id'] : null;
    //     $status = isset($_POST['status']) ? $_POST['status'] : 0;

    //     if ($id !== null) {
    //         try {
    //             $stmt = $pdo->prepare("UPDATE $customerSegmentTable SET statuses = ? WHERE id = ?");
    //             $stmt->execute(array($status, $id));
    //             $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM $customerSegmentTable WHERE statuses = 1");
    //             $stmt->execute();
    //             $result = $stmt->fetch(PDO::FETCH_ASSOC);

    //             echo json_encode(array(
    //                 'success' => true,
    //                 'webhooks_registered' => false,
    //                 'message' => 'Status updated'
    //             ));
    //         } catch (Exception $e) {
    //             echo json_encode(array('success' => false, 'message' => $e->getMessage()));
    //         }
    //     } else {
    //         echo json_encode(array('success' => false, 'message' => 'Missing ID'));
    //     }
    //     exit;
    // }
    if (isset($_POST['update_status'])) {
        $id = isset($_POST['id']) ? $_POST['id'] : null;
        $status = isset($_POST['status']) ? $_POST['status'] : 0;
        $channel = isset($_POST['channel']) ? $_POST['channel'] : 'sms';

        if ($id !== null) {
            try {
                $stmt = $pdo->prepare("SELECT segment_id, template_name FROM $customerSegmentTable WHERE id = :id AND shop = :shop");
                $stmt->execute(array(':id' => $id, ':shop' => $shop));
                $segmentInfo = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$segmentInfo) {
                    echo json_encode(array('success' => false, 'message' => 'Segment not found'));
                    exit;
                }

                $segmentGid = $segmentInfo['segment_id'];
                $segmentName = $segmentInfo['template_name'];

                if ($channel === 'sms') {
                    $stmt = $pdo->prepare("UPDATE $customerSegmentTable SET sms_enabled = ? WHERE id = ?");
                } else {
                    $stmt = $pdo->prepare("UPDATE $customerSegmentTable SET whatsapp_enabled = ? WHERE id = ?");
                }
                $stmt->execute(array($status, $id));

                $webhookResult = manageSegmentWebhook($pdo, $prefix, $shop, $accessToken, $id, $segmentGid, $segmentName);

                $response = array(
                    'success' => true,
                    'message' => ucfirst($channel) . ' status updated',
                    'webhook_managed' => $webhookResult
                );
                if (isset($GLOBALS['webhook_debug'])) {
                    $response['webhook_debug'] = $GLOBALS['webhook_debug'];
                }

                echo json_encode($response);
            } catch (Exception $e) {
                echo json_encode(array('success' => false, 'message' => $e->getMessage()));
            }
        } else {
            echo json_encode(array('success' => false, 'message' => 'Missing ID'));
        }
        exit;
    }
    if (isset($_POST['get_template_data'])) {
        $id = isset($_POST['id']) ? $_POST['id'] : null;
        if (!$id) {
            echo json_encode(array('success' => false, 'message' => 'Missing ID'));
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT id, name, segment_id, template_name, template_id_sms, comment, sms, whatsapp, conditions, sms_variables, whatsapp_variables FROM $customerSegmentTable WHERE id = :id AND shop = :shop");
            $stmt->execute(array(':id' => $id, ':shop' => $shop));
            $templateData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($templateData) {
                $conditions = json_decode($templateData['conditions'], true);
                $conditionType = isset($conditions['type']) ? $conditions['type'] : 'When Customer Joins Segment';
                $scheduleDatetime = isset($conditions['schedule_datetime']) ? $conditions['schedule_datetime'] : '';
                $smsVariables = array();
                if (!empty($templateData['sms_variables'])) {
                    $smsVariables = json_decode($templateData['sms_variables'], true);
                    if (!is_array($smsVariables)) {
                        $smsVariables = array();
                    }
                }
                $whatsappVariables = array();
                if (!empty($templateData['whatsapp_variables'])) {
                    $whatsappVariables = json_decode($templateData['whatsapp_variables'], true);
                    if (!is_array($whatsappVariables)) {
                        $whatsappVariables = array();
                    }
                }
                $whatsappData = array();
                if (!empty($templateData['whatsapp'])) {
                    $whatsappData = json_decode($templateData['whatsapp'], true);
                    if (!is_array($whatsappData)) {
                        $whatsappData = array();
                    }
                }
                $buttonData = array();
                if (isset($whatsappData['button_type'])) {
                    $buttonData[] = array(
                        'type' => $whatsappData['button_type'],
                        'text' => isset($whatsappData['button_text1_type']) ? $whatsappData['button_text1_type'] : '',
                        'url' => (isset($whatsappData['cta_url']['button1']) && $whatsappData['button_type'] == 'cta') ? $whatsappData['cta_url']['button1'] : ''
                    );
                }
                if (isset($whatsappData['button_type2'])) {
                    $buttonData[] = array(
                        'type' => $whatsappData['button_type2'],
                        'text' => isset($whatsappData['button_text2_type']) ? $whatsappData['button_text2_type'] : '',
                        'url' => (isset($whatsappData['cta_url']['button2']) && $whatsappData['button_type2'] == 'cta') ? $whatsappData['cta_url']['button2'] : ''
                    );
                }
                if (isset($whatsappData['button_type3'])) {
                    $buttonData[] = array(
                        'type' => $whatsappData['button_type3'],
                        'text' => isset($whatsappData['button_text3_type']) ? $whatsappData['button_text3_type'] : '',
                        'url' => (isset($whatsappData['cta_url']['button3']) && $whatsappData['button_type3'] == 'cta') ? $whatsappData['cta_url']['button3'] : ''
                    );
                }
                $response = array(
                    'success' => true,
                    'data' => array(
                        'id' => $templateData['id'],
                        'name' => $templateData['name'],
                        'segment_id' => $templateData['segment_id'],
                        'template_name' => $templateData['template_name'],
                        'template_id_sms' => $templateData['template_id_sms'],
                        'comment' => $templateData['comment'],
                        'sms' => $templateData['sms'],
                        'whatsapp' => json_encode($whatsappData),
                        'condition_type' => $conditionType,
                        'schedule_datetime' => $scheduleDatetime,
                        'sms_variables' => $smsVariables,
                        'whatsapp_variables' => $whatsappVariables,
                        'whatsapp_data' => $whatsappData,
                        'buttons' => $buttonData,
                        'media_type' => isset($whatsappData['media_type']) ? $whatsappData['media_type'] : 'text',
                        'media_url' => isset($whatsappData['media_url']) ? $whatsappData['media_url'] : '',
                        'media_source' => isset($whatsappData['media_source']) ? $whatsappData['media_source'] : 'input'
                    )
                );
                echo json_encode($response);
            } else {
                echo json_encode(array('success' => false, 'message' => 'Template not found'));
            }
        } catch (Exception $e) {
            echo json_encode(array('success' => false, 'message' => $e->getMessage()));
        }
        exit;
    }


    if (isset($_POST['check_sync_status'])) {
        $id = isset($_POST['id']) ? $_POST['id'] : null;
        if (!$id) {
            echo json_encode(array('success' => false, 'message' => 'Missing ID'));
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT sync_status, sync_customer_count FROM $customerSegmentTable WHERE id = :id AND shop = :shop");
            $stmt->execute(array(':id' => $id, ':shop' => $shop));
            $syncData = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($syncData) {
                echo json_encode(array(
                    'success' => true,
                    'status' => isset($syncData['sync_status']) ? $syncData['sync_status'] : '',
                    'count' => isset($syncData['sync_customer_count']) ? (int) $syncData['sync_customer_count'] : 0
                ));
            } else {
                echo json_encode(array('success' => false, 'message' => 'Segment not found'));
            }
        } catch (Exception $e) {
            echo json_encode(array('success' => false, 'message' => $e->getMessage()));
        }
        exit;
    }
}


debugStep('Shop resolved: ' . $shop);
$pdo = getDatabaseConnection();
debugStep('Database connection ready');

try {
    $tables = $prefix . "shopify_sms_notification_app";
    $stmt = $pdo->query("SHOW COLUMNS FROM $tables LIKE 'needs_reauth'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE $tables ADD COLUMN needs_reauth TINYINT DEFAULT 0");
        writeDebugLog("Added needs_reauth column to $tables");
    }
} catch (Exception $e) {
    writeDebugLog("Error checking/adding needs_reauth column: " . $e->getMessage(), "ERROR");
}

$tables = $prefix . "shopify_sms_notification_app";
$customerSegmentTable = $prefix . "customer_segment";
$segmentCustomersInfoTable = $prefix . "segment_customers_info";
$webhooksTable = $prefix . "webhooks";
$stmt = $pdo->prepare("SELECT shop, session_access_token AS oauth_token FROM $tables WHERE shop = :shop");
$stmt->execute(array(':shop' => $shop));
$data = $stmt->fetch();
if (!$data) {
    die("Shop not installed");
}
debugStep('OAuth token loaded from ' . $tables);
$accessToken = $data['oauth_token'];

$segments = array();
$cursor = null;
$hasNextPage = true;
$segmentPageGuard = 0;
$apiVersion = "2026-01";
while ($hasNextPage) {
    $segmentPageGuard++;
    if ($segmentPageGuard > 50) {
        writeDebugLog("Segment pagination guard hit (50 pages). Stopping fetch to avoid timeout.", "ERROR");
        break;
    }
    $query = <<<GRAPHQL
query getSegments(\$cursor: String) {
    segments(first: 250, after: \$cursor) {
        edges {
            node {
                id
                name
            }
        }
        pageInfo {
            hasNextPage
            endCursor
        }
    }
}
GRAPHQL;

    $result = makeShopifyGraphQLRequest($shop, $accessToken, $query, array(
        "cursor" => $cursor
    ), $apiVersion, $sessionToken);


    if (isset($result['needs_reauth']) && $result['needs_reauth'] === true) {
        writeDebugLog("Re-authentication required. Please re-install the app.", "ERROR");

        die("Session expired. Please <a href='{$app_url}/install.php?shop={$shop}'>re-authenticate</a>.");
    }

    if (isset($result['error'])) {
        writeDebugLog("Segment fetch error: " . $result['error'], "ERROR");
        break;
    }

    if (!empty($result['data']['segments']['edges'])) {
        foreach ($result['data']['segments']['edges'] as $edge) {
            $segments[] = $edge['node'];
        }
    }
    $pageInfo = isset($result['data']['segments']['pageInfo']) ? $result['data']['segments']['pageInfo'] : null;
    if ($pageInfo && $pageInfo['hasNextPage']) {
        $cursor = $pageInfo['endCursor'];
        $hasNextPage = true;
    } else {
        $hasNextPage = false;
    }
}
debugStep('Segments fetched count: ' . count($segments));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_type']) && $_POST['form_type'] === 'add_segment') {
    $segment_id = isset($_POST['segment_id']) ? $_POST['segment_id'] : '';
    $segment_name_selected = '';
    foreach ($segments as $seg) {
        if ($seg['id'] === $segment_id) {
            $segment_name_selected = $seg['name'];
            break;
        }
    }
    $name = isset($_POST['segment_name']) ? $_POST['segment_name'] : '';
    $comment = isset($_POST['segment_comment']) ? $_POST['segment_comment'] : '';
    $edit_id = isset($_POST['edit_id']) ? $_POST['edit_id'] : '';

    try {
        if (!empty($edit_id)) {
            $stmt = $pdo->prepare("
                UPDATE $customerSegmentTable 
                SET segment_id = :segment_id, name = :name, comment = :comment, template_name = :template_name
                WHERE id = :id AND shop = :shop
            ");
            $stmt->execute(array(
                ':segment_id' => $segment_id,
                ':name' => $name,
                ':comment' => $comment,
                ':template_name' => $segment_name_selected,
                ':id' => $edit_id,
                ':shop' => $shop
            ));
            $localSegmentId = $edit_id;
        } else {
            $conditions = json_encode(array('type' => 'When Customer Joins Segment'));
            $sms_variables = null;
            $whatsapp_variables = null;

            $stmt = $pdo->prepare("
                INSERT INTO $customerSegmentTable 
                (shop, segment_id, name, temp_id, comment, conditions, sms, whatsapp, template_name, sms_variables, whatsapp_variables, DateAndTime, sync_status)
                VALUES 
                (:shop, :segment_id, :name, :temp_id, :comment, :conditions, :sms, :whatsapp, :template_name, :sms_variables, :whatsapp_variables, :dateandtime, 'pending')
            ");
            $stmt->execute(array(
                ':shop' => $shop,
                ':segment_id' => $segment_id,
                ':name' => $name,
                ':temp_id' => isset($_POST['temp_id']) ? $_POST['temp_id'] : null,
                ':comment' => $comment,
                ':conditions' => $conditions,
                ':sms' => '',
                ':whatsapp' => '',
                ':template_name' => $segment_name_selected,
                ':sms_variables' => $sms_variables,
                ':whatsapp_variables' => $whatsapp_variables,
                ':dateandtime' => date('Y-m-d H:i:s')
            ));
            $localSegmentId = $pdo->lastInsertId();
        }

        $syncStarted = false;
        if (empty($edit_id)) {
            $syncUrl = rtrim($app_url, '/') . '/templates/sync_customers_ajax.php';
            triggerBackgroundSync($syncUrl, $shop, $segment_id, $localSegmentId);
            $syncStarted = true;
        }

        // echo json_encode([
        //     'success' => true,
        //     'segment_id' => $localSegmentId,
        //     'shopify_segment_id' => $segment_id,
        //     'sync_started' => $syncStarted,
        //     'message' => 'Segment saved successfully. Customer sync started in background.'
        // ]);
        echo json_encode(array(
            'success' => true,
            'segment_id' => $localSegmentId,
            'shopify_segment_id' => $segment_id,
            'sync_started' => $syncStarted,
            'message' => 'Segment saved successfully. Customer sync started in background.'
        ));

        $pdo = null;

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        flush();

        exit;
    } catch (Exception $e) {
        //echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        echo json_encode(array('success' => false, 'message' => $e->getMessage()));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ((isset($_POST['form_type']) ? $_POST['form_type'] : '') === 'sms')) {
    $condition_type = isset($_POST['condition_type']) ? $_POST['condition_type'] : 'When Customer Joins Segment';
    $schedule_dt = isset($_POST['schedule_dt']) ? $_POST['schedule_dt'] : null;
    $conditions = ($condition_type === 'Bulk Schedule' && !empty($schedule_dt))
        ? json_encode(array('type' => 'Bulk Schedule', 'schedule_datetime' => $schedule_dt))
        : json_encode(array('type' => 'When Customer Joins Segment'));
    $id = isset($_POST['aid']) ? $_POST['aid'] : '';
    if (empty($id)) {
        die("ID missing");
    }
    $sms = isset($_POST['sms']) ? $_POST['sms'] : '';
    $template_name_sms = isset($_POST['template_name']) ? $_POST['template_name'] : '';
    $variables = array();
    for ($i = 1; $i <= 6; $i++) {
        $key = trim(isset($_POST["key_$i"]) ? $_POST["key_$i"] : '');
        $val = trim(isset($_POST["var$i"]) ? $_POST["var$i"] : '');

        if ($key !== '') {
            $variables[$key] = $val;
        }
    }
    $sms_variables = !empty($variables) ? json_encode($variables) : null;
    try {
        $update = $pdo->prepare("
            UPDATE $customerSegmentTable 
            SET sms = ?, sms_variables = ?, conditions = ?, template_id_sms = ?
            WHERE id = ?
        ");
        $update->execute(array(
            $sms,
            $sms_variables,
            $conditions,
            $template_name_sms,
            $id
        ));
        echo "<script>
            window.location.href = window.location.pathname + '?shop=" . urlencode($shop) . "';
        </script>";
        exit;
    } catch (Exception $e) {
        die("Error: " . $e->getMessage());
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ((isset($_POST['form_type']) ? $_POST['form_type'] : '') === 'whatsapp')) {
    $condition_type = isset($_POST['condition_type']) ? $_POST['condition_type'] : 'When Customer Joins Segment';
    $schedule_dt = isset($_POST['schedule_dt']) ? $_POST['schedule_dt'] : null;
    $conditions = ($condition_type === 'Bulk Schedule' && !empty($schedule_dt))
        ? json_encode(array('type' => 'Bulk Schedule', 'schedule_datetime' => $schedule_dt))
        : json_encode(array('type' => 'When Customer Joins Segment'));
    $id = isset($_POST['aid']) ? $_POST['aid'] : '';
    if (empty($id)) {
        die("ID missing");
    }
    $media_type = isset($_POST['media_type']) ? $_POST['media_type'] : 'text';
    $media_source_type = isset($_POST['media_source_type']) ? $_POST['media_source_type'] : 'url';
    $media_url = null;
    $media_source = null;

    if ($media_type !== 'text') {
        if ($media_source_type === 'url' && !empty($_POST['media_url'])) {
            $media_source = 'input';
            $media_url = trim($_POST['media_url']);
        } elseif ($media_source_type === 'file') {
            if (isset($_FILES['media_file']) && $_FILES['media_file']['error'] === 0) {
                $uploadDir = "uploads/";
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0777, true);
                }
                $fileName = time() . "_" . preg_replace(
                    "/[^a-zA-Z0-9._-]/",
                    "",
                    $_FILES["media_file"]["name"]
                );
                $targetFile = $uploadDir . $fileName;
                if (move_uploaded_file($_FILES["media_file"]["tmp_name"], $targetFile)) {
                    $media_source = 'file';
                    $media_url = $fileName;
                }
            }
        } elseif ($media_source_type === 'dynamic') {
            $media_source = 'dynamic';
            $media_url = null;
        }
    }
    $button_type1 = isset($_POST['button_type1']) ? $_POST['button_type1'] : '';
    $button_text1 = isset($_POST['button_text1']) ? $_POST['button_text1'] : '';
    $button_url1 = isset($_POST['button_url1']) ? $_POST['button_url1'] : '';

    $button_type2 = isset($_POST['button_type2']) ? $_POST['button_type2'] : '';
    $button_text2 = isset($_POST['button_text2']) ? $_POST['button_text2'] : '';
    $button_url2 = isset($_POST['button_url2']) ? $_POST['button_url2'] : '';

    $button_type3 = isset($_POST['button_type3']) ? $_POST['button_type3'] : '';
    $button_text3 = isset($_POST['button_text3']) ? $_POST['button_text3'] : '';
    $button_url3 = isset($_POST['button_url3']) ? $_POST['button_url3'] : '';

    $cta_urls = array();
    if ($button_type1 === 'cta' && !empty($button_url1)) {
        $cta_urls['button1'] = $button_url1;
    }
    if ($button_type2 === 'cta' && !empty($button_url2)) {
        $cta_urls['button2'] = $button_url2;
    }
    if ($button_type3 === 'cta' && !empty($button_url3)) {
        $cta_urls['button3'] = $button_url3;
    }
    $cta_url_json = !empty($cta_urls) ? json_encode($cta_urls) : null;

    $template_name = isset($_POST['whatsapp_template_name']) ? $_POST['whatsapp_template_name'] : '';
    $variable_headers = isset($_POST['variable_headers']) ? $_POST['variable_headers'] : array();
    $variable_body = isset($_POST['variable_body']) ? $_POST['variable_body'] : array();
    // $variable_headers = array_values(array_filter($variable_headers, function ($v) {
    //     return $v !== '';
    // }));
    // $variable_body = array_values(array_filter($variable_body, function ($v) {
    //     return $v !== '';
    // }));
    $variable_headers = array_values(array_filter($variable_headers));
    $variable_body = array_values(array_filter($variable_body));

    $whatsapp_data = array(
        "media_type" => $media_type,
        "media_url" => $media_url,
        "media_source" => $media_source,
        "template_name" => $template_name,
        "variable_headers" => $variable_headers,
        "variable_body" => $variable_body,
        "button_type" => $button_type1,
        "button_text1_type" => $button_text1,
        "button_type2" => $button_type2,
        "button_text2_type" => $button_text2,
        "button_type3" => $button_type3,
        "button_text3_type" => $button_text3,
        "cta_url" => $cta_urls
    );

    $variables = array();
    for ($i = 1; $i <= 6; $i++) {
        $key = trim(isset($_POST["key_$i"]) ? $_POST["key_$i"] : '');
        $val = trim(isset($_POST["var$i"]) ? $_POST["var$i"] : '');
        if ($key !== '') {
            $variables[$key] = $val;
        }
    }
    $whatsapp_variables = !empty($variables) ? json_encode($variables) : null;

    try {
        $whatsapp_json = json_encode($whatsapp_data);

        $update = $pdo->prepare("
            UPDATE $customerSegmentTable 
            SET whatsapp = ?, whatsapp_variables = ?, conditions = ?
            WHERE id = ?
        ");
        $update->execute(array(
            $whatsapp_json,
            $whatsapp_variables,
            $conditions,
            $id
        ));
        echo "<script>
            window.location.href = window.location.pathname + '?shop=" . urlencode($shop) . "&status=success';
        </script>";
        exit;
    } catch (Exception $e) {
        die("Error: " . $e->getMessage());
    }
}
// $stmt = $pdo->prepare("
//     SELECT id, name, comment, template_name, sms, whatsapp, conditions, DateAndTime, statuses AS status, segment_id
//     FROM $customerSegmentTable
//     WHERE shop = :shop
//     ORDER BY DateAndTime DESC
// ");
$stmt = $pdo->prepare("
    SELECT id, name, comment, template_name, sms, whatsapp, conditions, DateAndTime, 
           sms_enabled, whatsapp_enabled, segment_id
    FROM $customerSegmentTable
    WHERE shop = :shop
    ORDER BY DateAndTime DESC
");
$stmt->execute(array(':shop' => $shop));
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="shopify-api-key" content="<?php echo htmlspecialchars($api_key, ENT_QUOTES, 'UTF-8'); ?>">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <title>Customer Segment</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined" />
    <link rel="stylesheet"
        href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>/templates/styles/style_customer_segment.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/css/select2.min.css" rel="stylesheet" />
    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 0;
            border-radius: 12px;
            width: 90%;
            max-width: 450px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        }

        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 18px;
            font-weight: 600;
            color: #111827;
        }

        .close {
            color: #6b7280;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: #111827;
        }

        .modal-body {
            padding: 24px;
        }

        .modal-footer {
            padding: 16px 24px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .modal .form-group {
            margin-bottom: 20px;
        }

        .modal .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #374151;
        }

        .modal .form-group select,
        .modal .form-group input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }

        .modal .form-group select:focus,
        .modal .form-group input:focus {
            outline: none;
            border-color: #3b82f6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.1);
        }

        .modal .submit-btn {
            background-color: #3b82f6;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
        }

        .modal .submit-btn:hover {
            background-color: #2563eb;
        }

        .modal .cancel-btn {
            background-color: #6b7280;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
        }

        .modal .cancel-btn:hover {
            background-color: #4b5563;
        }

        .btn-group {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }

        td:has(.test-btn) {
            text-align: center;
        }

        .test-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background-color: #3b82f6;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .test-btn .material-symbols-outlined {
            font-size: 14px;
        }

        .test-btn:hover {
            background-color: #2563eb;
            transform: translateY(-1px);
        }

        .test-btn:active {
            transform: translateY(0);
        }

        .toast-message {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background-color: black;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            font-size: 14px;
            z-index: 10000;
            opacity: 0;
            transition: opacity 0.3s ease;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .toast-message.error {
            background-color: #ef4444;
        }

        .toast-message.show {
            opacity: 1;
        }

        .loading-spinner {
            display: inline-block;
            width: 14px;
            height: 14px;
            border: 2px solid #ffffff;
            border-top: 2px solid transparent;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            margin-right: 6px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }

        .button-group {
            margin-bottom: 15px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 12px;
        }

        .button-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .button-title {
            font-weight: 600;
            font-size: 14px;
        }

        .remove-button-btn {
            background: none;
            border: none;
            cursor: pointer;
            color: #ef4444;
            padding: 2px;
        }

        .button-row {
            display: flex;
            gap: 10px;
            margin-bottom: 8px;
        }

        .button-row select,
        .button-row input {
            flex: 1;
            padding: 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
        }

        .url-field {
            display: none;
        }

        .url-field input {
            width: 100%;
            padding: 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
        }

        .add-button-btn {
            background-color: rgb(66, 66, 66);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
        }

        .add-button-btn:hover {
            background-color: rgb(14, 14, 14);
        }

        .variables-section {
            margin-top: 15px;
        }

        .sub-section {
            margin-bottom: 12px;
        }

        .sub-section-title {
            margin-bottom: 8px;
        }

        .add-btn {
            background-color: rgb(66, 66, 66);
            border: 1px solid #d1d5db;
            color: white;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
        }

        .add-btn :hover {
            background-color: rgb(14, 14, 14);
        }

        .variable-row {
            display: flex;
            gap: 8px;
            margin-bottom: 6px;
            align-items: center;
        }

        .variable-row input {
            flex: 1;
            padding: 8px;
            border: 1px solid #d1d5db;
            border-radius: 4px;
        }

        .remove-btn {
            background: none;
            border: none;
            cursor: pointer;
            color: #ef4444;
            padding: 2px;
        }
    </style>
</head>

<body>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0/dist/js/select2.min.js"></script>
    <div class="container-box">
        <div class="title-row">
            <a class="material-symbols-outlined"
                href="<?php echo htmlspecialchars($app_url, ENT_QUOTES, 'UTF-8'); ?>?tab=customer_segment&shop=<?php echo $shop; ?>">arrow_back</a>
            <div class="page-title">Customer Segment</div>
        </div>
        <div class="header-row">
            <button class="submit-btn" onclick="openModal()">Add</button>
        </div>
        <table class="custom-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Selected Segment</th>
                    <th>Type</th>
                    <th class="action-header">Test</th>
                    <th class="action-header">Actions</th>
                    <th class="action-header">SMS</th>
                    <th class="action-header">WhatsApp</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($rows)): ?>
                    <?php foreach ($rows as $row): ?>
                        <tr id="row-<?= $row['id'] ?>">
                            <td><?= htmlspecialchars($row['name']) ?></td>
                            <td><?= htmlspecialchars($row['template_name']) ?></td>
                            <td>
                                <?php
                                $conditions = json_decode($row['conditions'], true);
                                if ($conditions && isset($conditions['type'])) {
                                    echo htmlspecialchars(ucfirst($conditions['type']));
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td>
                                <button class="test-btn" onclick="openTestModal(<?= $row['id'] ?>)"><span
                                        class="material-symbols-outlined">play_arrow</span> Trigger Test</button>
                            </td>
                            <td class="action-cell">
                                <div class="action-buttons">
                                    <span class="action-link primary" onclick="openTemplateModal(<?= $row['id'] ?>)">
                                        Configure Template
                                    </span>
                                    <span class="action-link danger" onclick="deleteRow(<?= $row['id'] ?>)">
                                        Delete
                                    </span>
                                </div>
                            </td>
                            <!-- <td>
                                <label class="switch">
                                    <input type="checkbox" class="webhook-toggle" data-id="<?= $row['id'] ?>"
                                        data-segment-id="<?= htmlspecialchars($row['segment_id']) ?>" <?= (isset($row['status']) ? $row['status'] : 0) ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                            </td> -->
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="segment-toggle sms-toggle" data-id="<?= $row['id'] ?>"
                                        data-segment-id="<?= htmlspecialchars($row['segment_id']) ?>" data-channel="sms"
                                        <?= (isset($row['sms_enabled']) && $row['sms_enabled'] == 1) ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                            <td>
                                <label class="switch">
                                    <input type="checkbox" class="segment-toggle whatsapp-toggle" data-id="<?= $row['id'] ?>"
                                        data-segment-id="<?= htmlspecialchars($row['segment_id']) ?>" data-channel="whatsapp"
                                        <?= (isset($row['whatsapp_enabled']) && $row['whatsapp_enabled'] == 1) ? 'checked' : '' ?>>
                                    <span class="slider"></span>
                                </label>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" style="text-align:center;">No data found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <!-- <div id="testModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Send Test Message</h3>
                <span class="close" onclick="closeTestModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Country Code</label>
                    <select id="test_country_code">
                        <option value="+91">India (+91)</option>
                        <option value="+1">USA (+1)</option>
                        <option value="+44">UK (+44)</option>
                        <option value="+61">Australia (+61)</option>
                        <option value="+86">China (+86)</option>
                        <option value="+81">Japan (+81)</option>
                        <option value="+49">Germany (+49)</option>
                        <option value="+33">France (+33)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" id="test_phone" placeholder="Enter phone number (e.g., 9876543210)">
                    <small style="color: #6b7280; font-size: 12px;">Enter number without country code</small>
                </div>
            </div>
            <div class="modal-footer">
                <button class="cancel-btn" onclick="closeTestModal()">Cancel</button>
                <button class="submit-btn" onclick="sendTestFromModal()">Send Test</button>
            </div>
        </div>
    </div> -->
    <div id="testModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Send Test Message</h3>
                <span class="close" onclick="closeTestModal()">&times;</span>
            </div>
            <div class="modal-body">
                <div class="form-group" style="position: relative;">
                    <label>Select Country Code :</label>
                    <div class="searchable-select-wrapper" style="position: relative;">
                        <input type="text" id="country_search" placeholder="Search country..."
                            style="width: 100%; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; margin-bottom: 5px;"
                            onkeyup="filterCountries()" autocomplete="off">
                        <select id="test_country_code" size="6"
                            style="width: 100%; padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; overflow-y: auto;"
                            onchange="updateSearchInput()">
                            <option value="+93">Afghanistan (+93)</option>
                            <option value="+358">Aland Islands (+358)</option>
                            <option value="+355">Albania (+355)</option>
                            <option value="+213">Algeria (+213)</option>
                            <option value="+1684">American Samoa (+1684)</option>
                            <option value="+376">Andorra (+376)</option>
                            <option value="+244">Angola (+244)</option>
                            <option value="+1264">Anguilla (+1264)</option>
                            <option value="+672">Antarctica (+672)</option>
                            <option value="+1268">Antigua and Barbuda (+1268)</option>
                            <option value="+54">Argentina (+54)</option>
                            <option value="+374">Armenia (+374)</option>
                            <option value="+297">Aruba (+297)</option>
                            <option value="+61">Australia (+61)</option>
                            <option value="+43">Austria (+43)</option>
                            <option value="+994">Azerbaijan (+994)</option>
                            <option value="+1242">Bahamas (+1242)</option>
                            <option value="+973">Bahrain (+973)</option>
                            <option value="+880">Bangladesh (+880)</option>
                            <option value="+1246">Barbados (+1246)</option>
                            <option value="+375">Belarus (+375)</option>
                            <option value="+32">Belgium (+32)</option>
                            <option value="+501">Belize (+501)</option>
                            <option value="+229">Benin (+229)</option>
                            <option value="+1441">Bermuda (+1441)</option>
                            <option value="+975">Bhutan (+975)</option>
                            <option value="+591">Bolivia (+591)</option>
                            <option value="+599">Bonaire (+599)</option>
                            <option value="+387">Bosnia and Herzegovina (+387)</option>
                            <option value="+267">Botswana (+267)</option>
                            <option value="+55">Brazil (+55)</option>
                            <option value="+246">British Indian Ocean Territory (+246)</option>
                            <option value="+673">Brunei (+673)</option>
                            <option value="+359">Bulgaria (+359)</option>
                            <option value="+226">Burkina Faso (+226)</option>
                            <option value="+257">Burundi (+257)</option>
                            <option value="+855">Cambodia (+855)</option>
                            <option value="+237">Cameroon (+237)</option>
                            <option value="+1">Canada (+1)</option>
                            <option value="+238">Cape Verde (+238)</option>
                            <option value="+1345">Cayman Islands (+1345)</option>
                            <option value="+236">Central African Republic (+236)</option>
                            <option value="+235">Chad (+235)</option>
                            <option value="+56">Chile (+56)</option>
                            <option value="+86">China (+86)</option>
                            <option value="+61">Christmas Island (+61)</option>
                            <option value="+672">Cocos Islands (+672)</option>
                            <option value="+57">Colombia (+57)</option>
                            <option value="+269">Comoros (+269)</option>
                            <option value="+242">Congo (+242)</option>
                            <option value="+242">Congo DR (+242)</option>
                            <option value="+682">Cook Islands (+682)</option>
                            <option value="+506">Costa Rica (+506)</option>
                            <option value="+225">Cote d'Ivoire (+225)</option>
                            <option value="+385">Croatia (+385)</option>
                            <option value="+53">Cuba (+53)</option>
                            <option value="+599">Curacao (+599)</option>
                            <option value="+357">Cyprus (+357)</option>
                            <option value="+420">Czech Republic (+420)</option>
                            <option value="+45">Denmark (+45)</option>
                            <option value="+253">Djibouti (+253)</option>
                            <option value="+1767">Dominica (+1767)</option>
                            <option value="+1809">Dominican Republic (+1809)</option>
                            <option value="+593">Ecuador (+593)</option>
                            <option value="+20">Egypt (+20)</option>
                            <option value="+503">El Salvador (+503)</option>
                            <option value="+240">Equatorial Guinea (+240)</option>
                            <option value="+291">Eritrea (+291)</option>
                            <option value="+372">Estonia (+372)</option>
                            <option value="+251">Ethiopia (+251)</option>
                            <option value="+500">Falkland Islands (+500)</option>
                            <option value="+298">Faroe Islands (+298)</option>
                            <option value="+679">Fiji (+679)</option>
                            <option value="+358">Finland (+358)</option>
                            <option value="+33">France (+33)</option>
                            <option value="+594">French Guiana (+594)</option>
                            <option value="+689">French Polynesia (+689)</option>
                            <option value="+262">French Southern Territories (+262)</option>
                            <option value="+241">Gabon (+241)</option>
                            <option value="+220">Gambia (+220)</option>
                            <option value="+995">Georgia (+995)</option>
                            <option value="+49">Germany (+49)</option>
                            <option value="+233">Ghana (+233)</option>
                            <option value="+350">Gibraltar (+350)</option>
                            <option value="+30">Greece (+30)</option>
                            <option value="+299">Greenland (+299)</option>
                            <option value="+1473">Grenada (+1473)</option>
                            <option value="+590">Guadeloupe (+590)</option>
                            <option value="+1671">Guam (+1671)</option>
                            <option value="+502">Guatemala (+502)</option>
                            <option value="+44">Guernsey (+44)</option>
                            <option value="+224">Guinea (+224)</option>
                            <option value="+245">Guinea-Bissau (+245)</option>
                            <option value="+592">Guyana (+592)</option>
                            <option value="+509">Haiti (+509)</option>
                            <option value="+39">Holy See (+39)</option>
                            <option value="+504">Honduras (+504)</option>
                            <option value="+852">Hong Kong (+852)</option>
                            <option value="+36">Hungary (+36)</option>
                            <option value="+354">Iceland (+354)</option>
                            <option value="+91" selected>India (+91)</option>
                            <option value="+62">Indonesia (+62)</option>
                            <option value="+98">Iran (+98)</option>
                            <option value="+964">Iraq (+964)</option>
                            <option value="+353">Ireland (+353)</option>
                            <option value="+44">Isle of Man (+44)</option>
                            <option value="+972">Israel (+972)</option>
                            <option value="+39">Italy (+39)</option>
                            <option value="+1876">Jamaica (+1876)</option>
                            <option value="+81">Japan (+81)</option>
                            <option value="+44">Jersey (+44)</option>
                            <option value="+962">Jordan (+962)</option>
                            <option value="+7">Kazakhstan (+7)</option>
                            <option value="+254">Kenya (+254)</option>
                            <option value="+686">Kiribati (+686)</option>
                            <option value="+850">North Korea (+850)</option>
                            <option value="+82">South Korea (+82)</option>
                            <option value="+383">Kosovo (+383)</option>
                            <option value="+965">Kuwait (+965)</option>
                            <option value="+996">Kyrgyzstan (+996)</option>
                            <option value="+856">Laos (+856)</option>
                            <option value="+371">Latvia (+371)</option>
                            <option value="+961">Lebanon (+961)</option>
                            <option value="+266">Lesotho (+266)</option>
                            <option value="+231">Liberia (+231)</option>
                            <option value="+218">Libya (+218)</option>
                            <option value="+423">Liechtenstein (+423)</option>
                            <option value="+370">Lithuania (+370)</option>
                            <option value="+352">Luxembourg (+352)</option>
                            <option value="+853">Macau (+853)</option>
                            <option value="+389">Macedonia (+389)</option>
                            <option value="+261">Madagascar (+261)</option>
                            <option value="+265">Malawi (+265)</option>
                            <option value="+60">Malaysia (+60)</option>
                            <option value="+960">Maldives (+960)</option>
                            <option value="+223">Mali (+223)</option>
                            <option value="+356">Malta (+356)</option>
                            <option value="+692">Marshall Islands (+692)</option>
                            <option value="+596">Martinique (+596)</option>
                            <option value="+222">Mauritania (+222)</option>
                            <option value="+230">Mauritius (+230)</option>
                            <option value="+262">Mayotte (+262)</option>
                            <option value="+52">Mexico (+52)</option>
                            <option value="+691">Micronesia (+691)</option>
                            <option value="+373">Moldova (+373)</option>
                            <option value="+377">Monaco (+377)</option>
                            <option value="+976">Mongolia (+976)</option>
                            <option value="+382">Montenegro (+382)</option>
                            <option value="+1664">Montserrat (+1664)</option>
                            <option value="+212">Morocco (+212)</option>
                            <option value="+258">Mozambique (+258)</option>
                            <option value="+95">Myanmar (+95)</option>
                            <option value="+264">Namibia (+264)</option>
                            <option value="+674">Nauru (+674)</option>
                            <option value="+977">Nepal (+977)</option>
                            <option value="+31">Netherlands (+31)</option>
                            <option value="+687">New Caledonia (+687)</option>
                            <option value="+64">New Zealand (+64)</option>
                            <option value="+505">Nicaragua (+505)</option>
                            <option value="+227">Niger (+227)</option>
                            <option value="+234">Nigeria (+234)</option>
                            <option value="+683">Niue (+683)</option>
                            <option value="+672">Norfolk Island (+672)</option>
                            <option value="+1670">Northern Mariana Islands (+1670)</option>
                            <option value="+47">Norway (+47)</option>
                            <option value="+968">Oman (+968)</option>
                            <option value="+92">Pakistan (+92)</option>
                            <option value="+680">Palau (+680)</option>
                            <option value="+970">Palestine (+970)</option>
                            <option value="+507">Panama (+507)</option>
                            <option value="+675">Papua New Guinea (+675)</option>
                            <option value="+595">Paraguay (+595)</option>
                            <option value="+51">Peru (+51)</option>
                            <option value="+63">Philippines (+63)</option>
                            <option value="+64">Pitcairn (+64)</option>
                            <option value="+48">Poland (+48)</option>
                            <option value="+351">Portugal (+351)</option>
                            <option value="+1787">Puerto Rico (+1787)</option>
                            <option value="+974">Qatar (+974)</option>
                            <option value="+262">Reunion (+262)</option>
                            <option value="+40">Romania (+40)</option>
                            <option value="+7">Russia (+7)</option>
                            <option value="+250">Rwanda (+250)</option>
                            <option value="+590">Saint Barthelemy (+590)</option>
                            <option value="+290">Saint Helena (+290)</option>
                            <option value="+1869">Saint Kitts and Nevis (+1869)</option>
                            <option value="+1758">Saint Lucia (+1758)</option>
                            <option value="+590">Saint Martin (+590)</option>
                            <option value="+508">Saint Pierre and Miquelon (+508)</option>
                            <option value="+1784">Saint Vincent (+1784)</option>
                            <option value="+684">Samoa (+684)</option>
                            <option value="+378">San Marino (+378)</option>
                            <option value="+239">Sao Tome and Principe (+239)</option>
                            <option value="+966">Saudi Arabia (+966)</option>
                            <option value="+221">Senegal (+221)</option>
                            <option value="+381">Serbia (+381)</option>
                            <option value="+248">Seychelles (+248)</option>
                            <option value="+232">Sierra Leone (+232)</option>
                            <option value="+65">Singapore (+65)</option>
                            <option value="+721">Sint Maarten (+721)</option>
                            <option value="+421">Slovakia (+421)</option>
                            <option value="+386">Slovenia (+386)</option>
                            <option value="+677">Solomon Islands (+677)</option>
                            <option value="+252">Somalia (+252)</option>
                            <option value="+27">South Africa (+27)</option>
                            <option value="+500">South Georgia (+500)</option>
                            <option value="+211">South Sudan (+211)</option>
                            <option value="+34">Spain (+34)</option>
                            <option value="+94">Sri Lanka (+94)</option>
                            <option value="+249">Sudan (+249)</option>
                            <option value="+597">Suriname (+597)</option>
                            <option value="+47">Svalbard and Jan Mayen (+47)</option>
                            <option value="+268">Swaziland (+268)</option>
                            <option value="+46">Sweden (+46)</option>
                            <option value="+41">Switzerland (+41)</option>
                            <option value="+963">Syria (+963)</option>
                            <option value="+886">Taiwan (+886)</option>
                            <option value="+992">Tajikistan (+992)</option>
                            <option value="+255">Tanzania (+255)</option>
                            <option value="+66">Thailand (+66)</option>
                            <option value="+670">Timor-Leste (+670)</option>
                            <option value="+228">Togo (+228)</option>
                            <option value="+690">Tokelau (+690)</option>
                            <option value="+676">Tonga (+676)</option>
                            <option value="+1868">Trinidad and Tobago (+1868)</option>
                            <option value="+216">Tunisia (+216)</option>
                            <option value="+90">Turkey (+90)</option>
                            <option value="+7370">Turkmenistan (+7370)</option>
                            <option value="+1649">Turks and Caicos Islands (+1649)</option>
                            <option value="+688">Tuvalu (+688)</option>
                            <option value="+256">Uganda (+256)</option>
                            <option value="+380">Ukraine (+380)</option>
                            <option value="+971">United Arab Emirates (+971)</option>
                            <option value="+44">United Kingdom (+44)</option>
                            <option value="+1">United States (+1)</option>
                            <option value="+598">Uruguay (+598)</option>
                            <option value="+998">Uzbekistan (+998)</option>
                            <option value="+678">Vanuatu (+678)</option>
                            <option value="+58">Venezuela (+58)</option>
                            <option value="+84">Vietnam (+84)</option>
                            <option value="+1284">British Virgin Islands (+1284)</option>
                            <option value="+1340">US Virgin Islands (+1340)</option>
                            <option value="+681">Wallis and Futuna (+681)</option>
                            <option value="+212">Western Sahara (+212)</option>
                            <option value="+967">Yemen (+967)</option>
                            <option value="+260">Zambia (+260)</option>
                            <option value="+263">Zimbabwe (+263)</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" id="test_phone" placeholder="Enter phone number (e.g., 9876543210)">
                    <small style="color: #6b7280; font-size: 12px;">Enter number without country code</small>
                </div>
            </div>
            <div class="modal-footer">
                <button class="cancel-btn" onclick="closeTestModal()">Cancel</button>
                <button class="submit-btn" onclick="sendTestFromModal()">Send Test</button>
            </div>
        </div>
    </div>
    <div id="modalOverlay" class="modal-overlay">
        <div class="modal-box">
            <div class="left-panel">
                <div class="modal-header">
                    <span class="close-btn" onclick="closeModal()">✕</span>
                    <h3 id="modalTitle">Add Customer Segment</h3>
                </div>
                <form method="POST" action="" enctype="multipart/form-data" id="segmentForm">
                    <input type="hidden" name="form_type" value="add_segment">
                    <input type="hidden" name="edit_id" id="edit_id" value="">
                    <input type="hidden" name="temp_id" value="16">
                    <input type="hidden" name="shop"
                        value="<?= htmlspecialchars(isset($_GET['shop']) ? $_GET['shop'] : '') ?>">
                    <div class="condition-box">
                        <span>Name :</span>
                        <input type="text" name="segment_name" id="segment_name" required>
                    </div>
                    <div class="custom-dropdown">
                        <select name="segment_id" id="segmentDropdown" required>
                            <option value="">Select Segment</option>
                            <?php foreach ($segments as $segment): ?>
                                <option value="<?= $segment['id'] ?>">
                                    <?= htmlspecialchars($segment['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="condition-box">
                        <span>Comment :</span>
                        <input type="text" name="segment_comment" id="segment_comment">
                    </div>
                    <div class="btn-group">
                        <button class="submit-btn">Save Segment</button>
                        <button type="button" class="cancel-btn" onclick="closeModal()">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="deleteModal" class="modal-overlay">
        <div class="modal-box delete-modal-box">
            <h3 class="delete-modal-title">
                Are you sure you want to delete this segment?
            </h3>
            <div class="delete-btn-group">
                <button class="delete-cancel-btn" onclick="closeDeleteModal()">Cancel</button>
                <button class="delete-confirm-btn" onclick="confirmDelete()">Delete</button>
            </div>
        </div>
    </div>
    <div id="templateModal" class="modal-overlay">
        <div class="modal-box template-modal">
            <div class="content-wrapper">
                <div class="left-panel">
                    <div class="condition-box">
                        <span>Condition Type :</span>
                        <label>
                            <input type="radio" name="condition_type" value="When Customer Joins Segment" checked> When
                            Customer Joins Segment
                        </label>
                        <label>
                            <input type="radio" name="condition_type" value="Bulk Schedule"> Bulk Schedule
                        </label>
                    </div>
                    <div class="condition-box" id="templateScheduleBox" style="display:none;">
                        <span>Schedule :</span>
                        <input type="date" name="schedule_date" id="template_schedule_date" style="flex:1;">
                        <select name="schedule_hour" id="template_schedule_hour" style="min-width:100px;">
                            <option value="00">12:00 AM (00:00)</option>
                            <option value="01">01:00 AM (01:00)</option>
                            <option value="02">02:00 AM (02:00)</option>
                            <option value="03">03:00 AM (03:00)</option>
                            <option value="04">04:00 AM (04:00)</option>
                            <option value="05">05:00 AM (05:00)</option>
                            <option value="06">06:00 AM (06:00)</option>
                            <option value="07">07:00 AM (07:00)</option>
                            <option value="08">08:00 AM (08:00)</option>
                            <option value="09">09:00 AM (09:00)</option>
                            <option value="10">10:00 AM (10:00)</option>
                            <option value="11">11:00 AM (11:00)</option>
                            <option value="12">12:00 PM (12:00)</option>
                            <option value="13">13:00 PM (01:00)</option>
                            <option value="14">14:00 PM (02:00)</option>
                            <option value="15">15:00 PM (03:00)</option>
                            <option value="16">16:00 PM (04:00)</option>
                            <option value="17">17:00 PM (05:00)</option>
                            <option value="18">18:00 PM (06:00)</option>
                            <option value="19">19:00 PM (07:00)</option>
                            <option value="20">20:00 PM (08:00)</option>
                            <option value="21">21:00 PM (09:00)</option>
                            <option value="22">22:00 PM (10:00)</option>
                            <option value="23">23:00 PM (11:00)</option>
                        </select>
                        <input type="hidden" name="schedule_dt" id="template_schedule_hidden_value">
                    </div>
                    <div class="tabs">
                        <div class="tab-btn active" data-tab="smsTab">SMS</div>
                        <div class="tab-btn" data-tab="whatsappTab">WhatsApp</div>
                    </div>
                    <form method="POST" id="smsForm" style="display: block;">
                        <input type="hidden" name="condition_type" id="sms_condition_type">
                        <input type="hidden" name="schedule_dt" id="sms_schedule_hidden">
                        <input type="hidden" name="form_type" value="sms">
                        <input type="hidden" name="aid" id="template_aid_sms">
                        <!-- <label>SMS Text : </label> -->
                        <p></p>
                        <textarea name="sms" id="sms_text" placeholder="Enter SMS template..."
                            style="display:none;"></textarea>
                        <p></p>
                        <label>Template Name : </label>
                        <p></p>
                        <input type="text" name="template_name" placeholder="Enter template name"
                            id="sms_template_name_input">
                        <p></p>
                        <label>Variables :</label>
                        <p></p>
                        <?php for ($i = 1; $i <= 6; $i++): ?>
                            <div class="condition-box">
                                <div style="display: flex; gap: 10px; flex: 1;">
                                    <input type="text" name="key_<?= $i ?>" id="sms_key_<?= $i ?>" placeholder="Key"
                                        style="flex: 1;">
                                    <span style="align-self: center;">:</span>
                                    <input type="text" name="var<?= $i ?>" id="sms_var_<?= $i ?>" placeholder="Value"
                                        style="flex: 1;">
                                </div>
                            </div>
                        <?php endfor; ?>
                        <div class="btn-group">
                            <button class="submit-btn">Save Template</button>
                            <button type="button" class="cancel-btn" onclick="closeTemplateModal()">Cancel</button>
                        </div>
                    </form>
                    <form method="POST" enctype="multipart/form-data" id="whatsappForm" style="display: none;">
                        <input type="hidden" name="condition_type" id="wa_condition_type">
                        <input type="hidden" name="schedule_dt" id="wa_schedule_hidden">
                        <input type="hidden" name="form_type" value="whatsapp">
                        <input type="hidden" name="aid" id="template_aid_wa">
                        <div class="condition-box">
                            <span>Select Media Type :</span>
                            <select name="media_type" id="wa_media_type" class="condition-select">
                                <option value="image">Image</option>
                                <option value="video">Video</option>
                                <option value="text" selected>Text</option>
                                <option value="pdf">Pdf</option>
                            </select>
                        </div>
                        <div class="condition-box" id="waMediaSourceBox">
                            <span>Media Source :</span>
                            <label><input type="radio" name="media_source_type" value="url" checked> URL</label>
                            <label><input type="radio" name="media_source_type" value="file"> Upload</label>
                            <label id="dynamicImageOption" style="display:none;">
                                <input type="radio" name="media_source_type" value="dynamic"> Dynamic Image URL
                            </label>
                        </div>
                        <div class="condition-box" id="waMediaUrlBox">
                            <input type="text" name="media_url" class="condition-input" placeholder="Enter media url"
                                style="flex:1;" id="wa_media_url">
                        </div>
                        <div class="condition-box" id="waMediaFileBox" style="display:none;">
                            <input type="file" name="media_file" class="condition-input">
                        </div>
                        <div id="buttons-container"></div>
                        <div style="margin-bottom: 15px;">
                            <button type="button" class="add-button-btn" onclick="addButton()">
                                <span style="font-size: 14px;">+</span> Add Button
                            </button>
                        </div>
                        <div class="condition-box">
                            <span>Template Name :</span>
                            <input type="text" name="whatsapp_template_name" class="condition-input"
                                placeholder="Enter template name" style="flex:1;" id="wa_template_name">
                        </div>
                        <div class="variables-section">
                            <div class="sub-section">
                                <div class="sub-section-title">
                                    <button type="button" class="add-btn" onclick="addVariableField('headers')">
                                        <span style="font-size: 14px;">Headers +</span>
                                    </button>
                                </div>
                                <div id="headers-container"></div>
                            </div>
                            <div class="sub-section">
                                <div class="sub-section-title">
                                    <button type="button" class="add-btn" onclick="addVariableField('body')">
                                        <span style="font-size: 14px;">Body +</span>
                                    </button>
                                </div>
                                <div id="body-container"></div>
                            </div>
                        </div>
                        <div style="display:none;">
                            <?php for ($i = 1; $i <= 6; $i++): ?>
                                <input type="text" name="key_<?= $i ?>" id="wa_key_<?= $i ?>">
                                <input type="text" name="var<?= $i ?>" id="wa_var_<?= $i ?>">
                            <?php endfor; ?>
                        </div>
                        <div class="btn-group">
                            <button class="submit-btn">Save Template</button>
                            <button type="button" class="cancel-btn" onclick="closeTemplateModal()">Cancel</button>
                        </div>
                    </form>
                </div>
                <div class="right-panel">
                    <span class="close-btn" onclick="closeTemplateModal()">✕</span>
                    <div class="variable-box">
                        <h4>Liquid Variables</h4>
                        <p>Please replace the Template Variable {#var#} with liquid variables mentioned below.</p>
                        <div class="variable-divider"></div>
                        <div class="variable-list">
                            <!-- <div class="variable-item">{{ order_name }}</div>-->
                            <div class="variable-item">{{ customer_full_name }}</div>
                            <div class="variable-item">{{ customer_fname }}</div>
                            <div class="variable-item">{{ customer_lname }}</div>
                            <div class="variable-item">{{ customer_email_id }}</div>
                            <div class="variable-item">{{ country_code }}</div>
                            <div class="variable-item">{{ customer_phone }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="loader" style="
    display:none;
    position:fixed;
    top:0; left:0;
    width:100%; height:100%;
    background:rgba(0,0,0,0.4);
    z-index:9999;
    justify-content:center;
    align-items:center;
">
        <div style="
        width:50px;
        height:50px;
        border:5px solid #fff;
        border-top:5px solid #3b82f6;
        border-radius:50%;
        animation:spin 1s linear infinite;
    "></div>
    </div>

    <style>
        @keyframes spin {
            100% {
                transform: rotate(360deg);
            }
        }
    </style>
    <script>
        var currentEditId = null;
        var currentSegmentId = null;
        var buttonCount = 0;

        function openModal() {
            document.getElementById('modalTitle').innerText = 'Add Customer Segment';
            resetSegmentForm();
            document.getElementById('modalOverlay').style.display = 'flex';
        }

        function openTemplateModal(id) {
            document.getElementById('templateModal').style.display = 'flex';
            document.getElementById('template_aid_sms').value = id;
            document.getElementById('template_aid_wa').value = id;

            buttonCount = 0;
            document.getElementById('buttons-container').innerHTML = '';
            document.getElementById('headers-container').innerHTML = '';
            document.getElementById('body-container').innerHTML = '';

            addButton();
            addVariableField('headers');
            addVariableField('body');

            fetch('', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'get_template_data=1&id=' + id
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.success) {
                        if (data.data.sms) {
                            document.getElementById('sms_text').value = data.data.sms;
                        } else {
                            document.getElementById('sms_text').value = '';
                        }

                        var smsTemplateNameInput = document.getElementById('sms_template_name_input');
                        if (smsTemplateNameInput) {
                            smsTemplateNameInput.value = data.data.template_id_sms || '';
                        }

                        var smsVars = data.data.sms_variables || {};
                        var varKeys = Object.keys(smsVars);
                        for (var i = 1; i <= 6; i++) {
                            var keyInput = document.getElementById('sms_key_' + i);
                            var valInput = document.getElementById('sms_var_' + i);
                            if (keyInput && valInput) {
                                if (varKeys[i - 1]) {
                                    keyInput.value = varKeys[i - 1];
                                    valInput.value = smsVars[varKeys[i - 1]];
                                } else {
                                    keyInput.value = '';
                                    valInput.value = '';
                                }
                            }
                        }

                        var waData = data.data.whatsapp_data || {};
                        if (waData.media_type) {
                            document.getElementById('wa_media_type').value = waData.media_type;
                        } else {
                            document.getElementById('wa_media_type').value = 'text';
                        }
                        if (waData.media_url) {
                            document.getElementById('wa_media_url').value = waData.media_url;
                        }
                        if (waData.template_name) {
                            document.getElementById('wa_template_name').value = waData.template_name;
                        }

                        var fileRadio = document.querySelector('#whatsappForm input[name="media_source_type"][value="file"]');
                        var urlRadio = document.querySelector('#whatsappForm input[name="media_source_type"][value="url"]');
                        var dynamicRadio = document.querySelector('#whatsappForm input[name="media_source_type"][value="dynamic"]');
                        if (waData.media_source === 'file') {
                            if (fileRadio) fileRadio.checked = true;
                        } else if (waData.media_source === 'dynamic') {
                            if (dynamicRadio) dynamicRadio.checked = true;
                        } else {
                            if (urlRadio) urlRadio.checked = true;
                        }
                        toggleWhatsAppMediaFields();
                        document.getElementById('buttons-container').innerHTML = '';
                        buttonCount = 0;
                        var buttons = data.data.buttons || [];
                        if (buttons.length === 0) {
                            addButton();
                        } else {
                            for (var b = 0; b < buttons.length; b++) {
                                addButton(buttons[b].type, buttons[b].text, buttons[b].url);
                            }
                        }

                        document.getElementById('headers-container').innerHTML = '';
                        var headers = (waData.variable_headers && waData.variable_headers.length > 0) ? waData.variable_headers : [''];
                        for (var h = 0; h < headers.length; h++) {
                            addVariableField('headers', headers[h]);
                        }

                        document.getElementById('body-container').innerHTML = '';
                        var bodyVars = (waData.variable_body && waData.variable_body.length > 0) ? waData.variable_body : [''];
                        for (var bd = 0; bd < bodyVars.length; bd++) {
                            addVariableField('body', bodyVars[bd]);
                        }

                        var waVars = data.data.whatsapp_variables || {};
                        var waVarKeys = Object.keys(waVars);
                        for (var j = 1; j <= 6; j++) {
                            var waKeyInput = document.getElementById('wa_key_' + j);
                            var waValInput = document.getElementById('wa_var_' + j);
                            if (waKeyInput && waValInput) {
                                if (waVarKeys[j - 1]) {
                                    waKeyInput.value = waVarKeys[j - 1];
                                    waValInput.value = waVars[waVarKeys[j - 1]];
                                } else {
                                    waKeyInput.value = '';
                                    waValInput.value = '';
                                }
                            }
                        }

                        var conditionType = data.data.condition_type || 'When Customer Joins Segment';
                        var conditionRadios = document.querySelectorAll('#templateModal input[name="condition_type"]');
                        for (var k = 0; k < conditionRadios.length; k++) {
                            if (conditionRadios[k].value === conditionType) {
                                conditionRadios[k].checked = true;
                            }
                        }

                        var scheduleBox = document.getElementById('templateScheduleBox');
                        if (conditionType === 'Bulk Schedule' && data.data.schedule_datetime) {
                            scheduleBox.style.display = 'flex';
                            var dateTime = data.data.schedule_datetime;
                            var datePart = dateTime.split('T')[0];
                            var timePart = dateTime.split('T')[1];
                            var hourPart = timePart ? timePart.split(':')[0] : '00';
                            document.getElementById('template_schedule_date').value = datePart;
                            document.getElementById('template_schedule_hour').value = hourPart;
                            updateScheduleDateTime();
                        } else {
                            scheduleBox.style.display = 'none';
                        }

                        document.getElementById('sms_condition_type').value = conditionType;
                        document.getElementById('wa_condition_type').value = conditionType;
                    } else {
                        shopify.toast.show('Error loading template data: ' + data.message, { isError: true, duration: 3000 });
                    }
                })
                .catch(function (error) {
                    shopify.toast.show('Error loading template data', { isError: true, duration: 3000 });
                });
        }
        function addButton(type, text, url) {
            type = type || 'none';
            text = text || '';
            url = url || '';

            if (buttonCount >= 3) {
                shopify.toast.show('Maximum 3 buttons allowed', { isError: true, duration: 3000 });
                return;
            }

            buttonCount++;
            var container = document.getElementById('buttons-container');
            if (!container) return;

            var newButtonGroup = document.createElement('div');
            newButtonGroup.className = 'button-group';
            newButtonGroup.id = 'button-group-' + buttonCount;
            newButtonGroup.innerHTML = '<div class="button-header">' +
                '<span class="button-title">Button ' + buttonCount + '</span>' +
                (buttonCount > 1 ? '<button type="button" class="remove-button-btn" onclick="removeButton(' + buttonCount + ')">' +
                    '<span><svg xmlns="http://www.w3.org/2000/svg" x="0px" y="0px" width="20" height="20" viewBox="0 0 30 30">' +
                    '<path d="M 14.984375 2.4863281 A 1.0001 1.0001 0 0 0 14 3.5 L 14 4 L 8.5 4 A 1.0001 1.0001 0 0 0 7.4863281 5 L 6 5 A 1.0001 1.0001 0 1 0 6 7 L 24 7 A 1.0001 1.0001 0 1 0 24 5 L 22.513672 5 A 1.0001 1.0001 0 0 0 21.5 4 L 16 4 L 16 3.5 A 1.0001 1.0001 0 0 0 14.984375 2.4863281 z M 6 9 L 7.7929688 24.234375 C 7.9109687 25.241375 8.7633438 26 9.7773438 26 L 20.222656 26 C 21.236656 26 22.088031 25.241375 22.207031 24.234375 L 24 9 L 6 9 z"></path>' +
                    '</svg></span></button>' : '') +
                '</div>' +
                '<div class="button-row">' +
                '<select name="button_type' + buttonCount + '" class="button-type" data-button="' + buttonCount + '" onchange="toggleUrlField(' + buttonCount + ')">' +
                '<option value="none"' + (type === 'none' ? ' selected' : '') + '>None</option>' +
                '<option value="cta"' + (type === 'cta' ? ' selected' : '') + '>CTA</option>' +
                '<option value="quick"' + (type === 'quick' ? ' selected' : '') + '>Quick Reply</option>' +
                '</select>' +
                '<input type="text" name="button_text' + buttonCount + '" placeholder="Button Text" value="' + text.replace(/"/g, '&quot;') + '">' +
                '</div>' +
                '<div class="url-field" id="url-field-' + buttonCount + '" ' + (type === 'cta' ? 'style="display:flex;"' : '') + '>' +
                '<input type="text" name="button_url' + buttonCount + '" placeholder="Enter URL (e.g., https://example.com)" value="' + url.replace(/"/g, '&quot;') + '">' +
                '</div>';
            container.appendChild(newButtonGroup);
        }

        function removeButton(buttonNumber) {
            var buttonGroup = document.getElementById('button-group-' + buttonNumber);
            if (buttonGroup) {
                buttonGroup.remove();
                buttonCount--;
            }
        }

        function toggleUrlField(buttonNumber) {
            var select = document.querySelector('select[name="button_type' + buttonNumber + '"]');
            var urlField = document.getElementById('url-field-' + buttonNumber);
            if (select && urlField) {
                if (select.value === 'cta') {
                    urlField.style.display = 'flex';
                } else {
                    urlField.style.display = 'none';
                }
            }
        }

        function addVariableField(type, value) {
            value = value || '';
            var container = type === 'headers' ? document.getElementById('headers-container') : document.getElementById('body-container');
            if (!container) return;

            var fieldName = type === 'headers' ? 'variable_headers[]' : 'variable_body[]';
            var placeholder = type === 'headers' ? 'Enter header variable (e.g., {{ customer_fname }})' : 'Enter body variable (e.g., {{ product_name }})';

            var newRow = document.createElement('div');
            newRow.className = 'variable-row';
            newRow.innerHTML = '<input type="text" name="' + fieldName + '" placeholder="' + placeholder + '" value="' + value.replace(/"/g, '&quot;') + '">' +
                '<button type="button" class="remove-btn" onclick="removeVariableField(this, \'' + type + '\')">' +
                '<span><svg xmlns="http://www.w3.org/2000/svg" x="0px" y="0px" width="20" height="20" viewBox="0 0 30 30">' +
                '<path d="M 14.984375 2.4863281 A 1.0001 1.0001 0 0 0 14 3.5 L 14 4 L 8.5 4 A 1.0001 1.0001 0 0 0 7.4863281 5 L 6 5 A 1.0001 1.0001 0 1 0 6 7 L 24 7 A 1.0001 1.0001 0 1 0 24 5 L 22.513672 5 A 1.0001 1.0001 0 0 0 21.5 4 L 16 4 L 16 3.5 A 1.0001 1.0001 0 0 0 14.984375 2.4863281 z M 6 9 L 7.7929688 24.234375 C 7.9109687 25.241375 8.7633438 26 9.7773438 26 L 20.222656 26 C 21.236656 26 22.088031 25.241375 22.207031 24.234375 L 24 9 L 6 9 z"></path>' +
                '</svg></span></button>';
            container.appendChild(newRow);
        }

        function removeVariableField(button, type) {
            var row = button.parentNode;
            var container = type === 'headers' ? document.getElementById('headers-container') : document.getElementById('body-container');
            if (container && container.children.length > 1) {
                row.remove();
            } else {
                var input = row.querySelector('input');
                if (input) {
                    input.value = '';
                }
            }
        }

        function toggleWhatsAppMediaFields() {
            var mediaType = document.getElementById('wa_media_type');
            var mediaSourceBox = document.getElementById('waMediaSourceBox');
            var urlBox = document.getElementById('waMediaUrlBox');
            var fileBox = document.getElementById('waMediaFileBox');
            var dynamicOption = document.getElementById('dynamicImageOption');
            var radioButtons = document.querySelectorAll('#whatsappForm input[name="media_source_type"]');

            var selected = null;
            for (var i = 0; i < radioButtons.length; i++) {
                if (radioButtons[i].checked) {
                    selected = radioButtons[i];
                    break;
                }
            }

            if (!selected && mediaType.value !== 'text') {
                for (var i = 0; i < radioButtons.length; i++) {
                    if (radioButtons[i].value === 'url') {
                        radioButtons[i].checked = true;
                        selected = radioButtons[i];
                        break;
                    }
                }
            }

            if (!selected) {
                if (mediaType.value === 'text') {
                    if (mediaSourceBox) mediaSourceBox.style.display = 'none';
                    if (urlBox) urlBox.style.display = 'none';
                    if (fileBox) fileBox.style.display = 'none';
                    if (dynamicOption) dynamicOption.style.display = 'none';
                }
                return;
            }

            if (mediaType.value === 'text') {
                if (mediaSourceBox) mediaSourceBox.style.display = 'none';
                if (urlBox) urlBox.style.display = 'none';
                if (fileBox) fileBox.style.display = 'none';
                if (dynamicOption) dynamicOption.style.display = 'none';
                return;
            }

            if (mediaSourceBox) mediaSourceBox.style.display = 'block';

            if (mediaType.value === 'image') {
                if (dynamicOption) dynamicOption.style.display = 'inline-flex';
            } else {
                if (dynamicOption) dynamicOption.style.display = 'none';
                if (selected.value === 'dynamic') {
                    for (var i = 0; i < radioButtons.length; i++) {
                        if (radioButtons[i].value === 'url') {
                            radioButtons[i].checked = true;
                            selected = radioButtons[i];
                            break;
                        }
                    }
                }
            }

            if (selected.value === 'url') {
                if (urlBox) urlBox.style.display = 'flex';
                if (fileBox) fileBox.style.display = 'none';
            }
            else if (selected.value === 'file') {
                if (urlBox) urlBox.style.display = 'none';
                if (fileBox) fileBox.style.display = 'flex';
            }
            else if (selected.value === 'dynamic') {
                if (urlBox) urlBox.style.display = 'none';
                if (fileBox) fileBox.style.display = 'none';
            }
        }
        // function openTestModal(segmentId) {
        //     currentSegmentId = segmentId;
        //     document.getElementById('testModal').style.display = 'block';
        // }

        // function closeTestModal() {
        //     document.getElementById('testModal').style.display = 'none';
        //     document.getElementById('test_phone').value = '';
        //     currentSegmentId = null;
        // }
        function openTestModal(segmentId) {
            currentSegmentId = segmentId;
            document.getElementById('testModal').style.display = 'block';

            var select = document.getElementById('test_country_code');
            var searchInput = document.getElementById('country_search');
            if (select && searchInput) {
                var selectedOption = select.options[select.selectedIndex];
                if (selectedOption) {
                    searchInput.value = selectedOption.text;
                }
                setTimeout(function () {
                    searchInput.focus();
                    searchInput.select();
                }, 100);
            }
        }

        function closeTestModal() {
            document.getElementById('testModal').style.display = 'none';
            document.getElementById('test_phone').value = '';
            currentSegmentId = null;

            var searchInput = document.getElementById('country_search');
            if (searchInput) {
                searchInput.value = '';
            }
            var select = document.getElementById('test_country_code');
            if (select) {
                var options = select.options;
                for (var i = 0; i < options.length; i++) {
                    options[i].style.display = '';
                }
            }
        }

        function filterCountries() {
            var searchInput = document.getElementById('country_search');
            var select = document.getElementById('test_country_code');
            if (!searchInput || !select) return;

            var filter = searchInput.value.toLowerCase();
            var options = select.options;

            for (var i = 0; i < options.length; i++) {
                var optionText = options[i].text.toLowerCase();
                var optionValue = options[i].value.toLowerCase();

                if (optionText.indexOf(filter) > -1 || optionValue.indexOf(filter) > -1) {
                    options[i].style.display = '';
                } else {
                    options[i].style.display = 'none';
                }
            }

            for (var j = 0; j < options.length; j++) {
                if (options[j].style.display !== 'none') {
                    if (filter.length > 0) {
                        options[j].scrollIntoView(false);
                    }
                    break;
                }
            }
        }

        function updateSearchInput() {
            var select = document.getElementById('test_country_code');
            var searchInput = document.getElementById('country_search');
            if (!select || !searchInput) return;

            var selectedOption = select.options[select.selectedIndex];
            if (selectedOption) {
                searchInput.value = selectedOption.text;
            }
        }

        function sendTestFromModal() {
            var countryCode = document.getElementById('test_country_code').value;
            var phone = document.getElementById('test_phone').value;

            if (!phone) {
                shopify.toast.show("Please enter phone number", { isError: true, duration: 3000 });
                return;
            }

            var phoneRegex = /^\d{5,15}$/;
            if (!phoneRegex.test(phone)) {
                shopify.toast.show("Please enter a valid phone number (5-15 digits)", { isError: true, duration: 3000 });
                return;
            }

            var sendButton = document.querySelector('#testModal .submit-btn');
            var originalText = sendButton.innerHTML;
            sendButton.disabled = true;
            sendButton.innerHTML = '<span class="loading-spinner"></span> Sending...';

            function resetSendButton() {
                sendButton.disabled = false;
                sendButton.innerHTML = originalText;
            }

            fetch('/notifycsapp/test_trigger/test_customer_joined_segment.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    country_code: countryCode,
                    phone: phone,
                    shop: "<?php echo $shop; ?>",
                    segment_id: currentSegmentId
                })
            })
                .then(function (res) {
                    return res.text().then(function (text) {
                        var data = null;
                        try {
                            data = JSON.parse(text);
                        } catch (e) {
                            data = { success: false, message: 'Invalid server response.' };
                        }
                        return data;
                    });
                })
                .then(function (data) {
                    if (data.success) {
                        closeTestModal();
                        shopify.toast.show('Test triggered successfully! Check the status in App Logs.', { duration: 3000 });
                    } else {
                        shopify.toast.show(data.message || "Error sending test SMS", { isError: true, duration: 3000 });
                        closeTestModal();
                    }
                })
                .catch(function (err) {
                    console.error(err);
                    shopify.toast.show("Network error. Please try again.", { isError: true, duration: 3000 });
                    closeTestModal();
                })
                .then(function () {
                    resetSendButton();
                });
        }

        function closeModal() {
            document.getElementById('modalOverlay').style.display = 'none';
        }

        function resetSegmentForm() {
            document.getElementById('segmentForm').reset();
            document.getElementById('edit_id').value = '';
            if (typeof $ !== 'undefined') {
                $('#segmentDropdown').val('').trigger('change');
            }
        }

        function closeTemplateModal() {
            document.getElementById('templateModal').style.display = 'none';
        }

        document.querySelectorAll('#whatsappForm input[name="media_source_type"]').forEach(function (radio) {
            radio.addEventListener('change', toggleWhatsAppMediaFields);
        });
        var waMediaType = document.getElementById('wa_media_type');
        if (waMediaType) {
            waMediaType.addEventListener('change', toggleWhatsAppMediaFields);
        }

        var deleteId = null;
        function deleteRow(id) {
            deleteId = id;
            document.getElementById('deleteModal').style.display = 'flex';
        }

        function closeDeleteModal() {
            deleteId = null;
            document.getElementById('deleteModal').style.display = 'none';
        }

        function confirmDelete() {
            if (!deleteId) return;
            fetch('delete_segment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'id=' + deleteId + '&shop=<?= urlencode($shop) ?>'
            })
                .then(function (response) { return response.json(); })
                .then(function (data) {
                    if (data.success) {
                        var row = document.getElementById('row-' + deleteId);
                        if (row) row.remove();
                        shopify.toast.show("Segment deleted successfully", { duration: 3000 });
                    } else {
                        shopify.toast.show("Error deleting record", { isError: true, duration: 3000 });
                    }
                    closeDeleteModal();
                })
                .catch(function () {
                    shopify.toast.show("Error deleting record", { isError: true, duration: 3000 });
                    closeDeleteModal();
                });
        }

        document.getElementById('deleteModal').addEventListener('click', function (e) {
            if (e.target === this) closeDeleteModal();
        });

        document.querySelectorAll('.variable-item').forEach(function (item) {
            item.addEventListener('click', function () {
                var text = this.innerText.trim();
                navigator.clipboard.writeText(text)
                    .then(function () {
                        shopify.toast.show('Variable copied to clipboard', { duration: 3000 });
                    })
                    .catch(function () {
                        shopify.toast.show('Failed to copy', { isError: true, duration: 3000 });
                    });
            });
        });

        document.querySelectorAll('#templateModal .tab-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tabId = this.getAttribute('data-tab');
                document.querySelectorAll('#templateModal .tab-btn').forEach(function (t) { t.classList.remove('active'); });
                this.classList.add('active');
                var smsForm = document.getElementById('smsForm');
                var waForm = document.getElementById('whatsappForm');
                if (tabId === 'smsTab') {
                    if (smsForm) smsForm.style.display = 'block';
                    if (waForm) waForm.style.display = 'none';
                } else {
                    if (smsForm) smsForm.style.display = 'none';
                    if (waForm) waForm.style.display = 'block';
                }
            });
        });

        document.addEventListener('DOMContentLoaded', function () {
            var smsForm = document.getElementById('smsForm');
            var waForm = document.getElementById('whatsappForm');
            if (smsForm) smsForm.style.display = 'block';
            if (waForm) waForm.style.display = 'none';
            toggleWhatsAppMediaFields();
        });

        // document.querySelectorAll('.webhook-toggle').forEach(function (toggle) {
        //     toggle.addEventListener('change', function () {
        //         var toggleEl = this;
        //         var id = this.dataset.id;
        //         var segmentId = this.dataset.segmentId;
        //         var status = this.checked ? 1 : 0;
        //         fetch('', {
        //             method: 'POST',
        //             headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        //             body: 'update_status=1&id=' + id + '&status=' + status + '&segment_id=' + encodeURIComponent(segmentId)
        //         })
        //             .then(function (res) { return res.json(); })
        //             .then(function (data) {
        //                 if (data && data.success) {
        //                     var statusText = status === 1 ? 'enabled' : 'disabled';
        //                     shopify.toast.show('Segment status ' + statusText + ' successfully.', { duration: 3000 });
        //                 } else {
        //                     toggleEl.checked = !toggleEl.checked;
        //                     shopify.toast.show((data && data.message) ? data.message : 'Error updating status', { isError: true, duration: 3000 });
        //                 }
        //             })
        //             .catch(function () {
        //                 toggleEl.checked = !toggleEl.checked;
        //                 shopify.toast.show('Error updating status', { isError: true, duration: 3000 });
        //             });
        //     });
        // });
        document.querySelectorAll('.segment-toggle').forEach(function (toggle) {
            toggle.addEventListener('change', function () {
                var toggleEl = this;
                var id = this.dataset.id;
                var segmentId = this.dataset.segmentId;
                var channel = this.dataset.channel;
                var status = this.checked ? 1 : 0;

                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'update_status=1&id=' + id + '&status=' + status + '&channel=' + channel + '&segment_id=' + encodeURIComponent(segmentId)
                })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        console.log('Webhook Response:', data);

                        if (data && data.success) {
                            var channelText = channel === 'sms' ? 'SMS' : 'WhatsApp';
                            var statusText = status === 1 ? 'enabled' : 'disabled';

                           
                            if (data.webhook_managed === true) {
                                shopify.toast.show(channelText + ' ' + statusText + ' successfully.', { duration: 3000 });
                            } else if (data.webhook_managed === false && data.webhook_debug) {
                               
                                var debug = data.webhook_debug;
                                var errorMsg = '';

                                if (debug.error_type === 'user_errors') {
                                    errorMsg = 'Webhook Error: ' + (debug.message[0]?.message || JSON.stringify(debug.message));
                                } else if (debug.error_type === 'graphql_errors') {
                                    errorMsg = 'GraphQL Error: ' + JSON.stringify(debug.message);
                                } else if (debug.error_type === 'curl_error') {
                                    errorMsg = 'Connection Error: ' + debug.message;
                                } else {
                                    errorMsg = 'Webhook registration failed. Check console for details.';
                                }

                                shopify.toast.show(errorMsg, { isError: true, duration: 8000 });
                                console.error('Webhook Debug Details:', debug);
                            } else {
                                shopify.toast.show(channelText + ' ' + statusText + ' successfully.', { duration: 3000 });
                            }
                        } else {
                            toggleEl.checked = !toggleEl.checked;
                            shopify.toast.show((data && data.message) ? data.message : 'Error updating status', { isError: true, duration: 3000 });
                        }
                    })
                    .catch(function (error) {
                        console.error('Fetch Error:', error);
                        toggleEl.checked = !toggleEl.checked;
                        shopify.toast.show('Network error. Check console.', { isError: true, duration: 3000 });
                    });
            });
        });

        function updateScheduleDateTime() {
            var dateInput = document.getElementById('template_schedule_date');
            var hourSelect = document.getElementById('template_schedule_hour');
            var hiddenInput = document.getElementById('template_schedule_hidden_value');

            if (dateInput && dateInput.value && hourSelect && hourSelect.value && hiddenInput) {
                var dateTimeValue = dateInput.value + 'T' + hourSelect.value + ':00';
                hiddenInput.value = dateTimeValue;
                var smsHidden = document.getElementById('sms_schedule_hidden');
                var waHidden = document.getElementById('wa_schedule_hidden');
                if (smsHidden) smsHidden.value = dateTimeValue;
                if (waHidden) waHidden.value = dateTimeValue;
            }
        }

        function setMinDate() {
            var dateInput = document.getElementById('template_schedule_date');
            if (dateInput) {
                var today = new Date();
                var year = today.getFullYear();
                var month = String(today.getMonth() + 1).padStart(2, '0');
                var day = String(today.getDate()).padStart(2, '0');
                dateInput.min = year + '-' + month + '-' + day;
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            setMinDate();
            var dateInput = document.getElementById('template_schedule_date');
            var hourSelect = document.getElementById('template_schedule_hour');
            if (dateInput) dateInput.addEventListener('change', updateScheduleDateTime);
            if (hourSelect) hourSelect.addEventListener('change', updateScheduleDateTime);
        });

        document.querySelectorAll('#templateModal input[name="condition_type"]').forEach(function (radio) {
            radio.addEventListener('change', function () {
                var value = this.value;
                var smsCondition = document.getElementById('sms_condition_type');
                var waCondition = document.getElementById('wa_condition_type');
                if (smsCondition) smsCondition.value = value;
                if (waCondition) waCondition.value = value;

                var scheduleBox = document.getElementById('templateScheduleBox');
                if (value === 'Bulk Schedule') {
                    if (scheduleBox) scheduleBox.style.display = 'flex';
                    updateScheduleDateTime();
                } else {
                    if (scheduleBox) scheduleBox.style.display = 'none';
                    var hiddenInput = document.getElementById('template_schedule_hidden_value');
                    if (hiddenInput) hiddenInput.value = '';
                    var smsHidden = document.getElementById('sms_schedule_hidden');
                    var waHidden = document.getElementById('wa_schedule_hidden');
                    if (smsHidden) smsHidden.value = '';
                    if (waHidden) waHidden.value = '';
                }
            });
        });
        if (typeof $ !== 'undefined') {
            $(document).ready(function () {
                $('#segmentDropdown').select2({
                    dropdownParent: $('#modalOverlay'),
                    width: '100%'
                });
            });
        }

        window.onclick = function (event) {
            var modal = document.getElementById('testModal');
            if (event.target == modal) {
                closeTestModal();
            }
        }


        document.getElementById('segmentForm').addEventListener('submit', function (e) {
            e.preventDefault();

            var formData = new FormData(this);
            var loader = document.getElementById('loader');
            if (loader) loader.style.display = 'flex';

            var segmentDropdown = document.getElementById('segmentDropdown');
            var selectedText = '';
            if (segmentDropdown && segmentDropdown.selectedIndex >= 0) {
                selectedText = segmentDropdown.options[segmentDropdown.selectedIndex].text;
            }
            var segmentName = document.getElementById('segment_name').value;

            fetch('', {
                method: 'POST',
                body: formData
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (loader) loader.style.display = 'none';

                    if (data.success) {
                        document.getElementById('modalOverlay').style.display = 'none';
                        resetSegmentForm();

                        if (data.shopify_segment_id && data.segment_id) {
                            addSegmentToTable(data.segment_id, segmentName, selectedText, data.shopify_segment_id);
                            shopify.toast.show('Segment added. Syncing in background...', { duration: 3000 });
                            if (data.sync_started) {
                                pollSegmentSyncStatus(data.segment_id, 0);
                            }
                        } else {
                            window.location.href = window.location.pathname + '?shop=<?php echo urlencode($shop); ?>';
                        }
                    } else {
                        shopify.toast.show('Error: ' + (data.message || 'Unknown error'), { isError: true, duration: 3000 });
                    }
                })
                .catch(function (error) {
                    if (loader) loader.style.display = 'none';
                    shopify.toast.show('Network error. Please try again.', { isError: true, duration: 3000 });
                });
        });

        function pollSegmentSyncStatus(localId, attempt) {
            if (attempt > 720) {
                shopify.toast.show('Customer sync is still running. Please check again later.', { duration: 3000 });
                return;
            }

            setTimeout(function () {
                fetch('', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'check_sync_status=1&id=' + encodeURIComponent(localId)
                })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (!data || !data.success) {
                            pollSegmentSyncStatus(localId, attempt + 1);
                            return;
                        }
                        if (data.status === 'completed') {
                            shopify.toast.show('Customer sync completed. ' + (data.count || 0) + ' customers synced.', { duration: 5000 });
                        } else if (data.status === 'failed') {
                            shopify.toast.show('Customer sync failed. Please try again.', { isError: true, duration: 5000 });
                        } else {
                            pollSegmentSyncStatus(localId, attempt + 1);
                        }
                    })
                    .catch(function () {
                        pollSegmentSyncStatus(localId, attempt + 1);
                    });
            }, 5000);
        }
        function addSegmentToTable(localId, name, templateName, shopifySegmentId) {
            var tbody = document.querySelector('.custom-table tbody');
            var noDataRow = tbody.querySelector('tr td[colspan="6"]');
            if (noDataRow) {
                noDataRow.parentNode.remove();
            }
            var newRow = document.createElement('tr');
            newRow.id = 'row-' + localId;

            newRow.innerHTML =
                '<td>' + escapeHtml(name) + '</td>' +
                '<td>' + escapeHtml(templateName) + '</td>' +
                '<td>When Customer Joins Segment</td>' +
                '<td>' +
                '<button class="test-btn" onclick="openTestModal(' + localId + ')">' +
                '<span class="material-symbols-outlined">play_arrow</span> Trigger Test' +
                '</button>' +
                '</td>' +
                '<td class="action-cell">' +
                '<div class="action-buttons">' +
                '<span class="action-link primary" onclick="openTemplateModal(' + localId + ')">' +
                'Configure Template' +
                '</span>' +
                '<span class="action-link danger" onclick="deleteRow(' + localId + ')">' +
                'Delete' +
                '</span>' +
                '</div>' +
                '</td>' +
                // '<td>' +
                // '<label class="switch">' +
                // '<input type="checkbox" class="webhook-toggle" data-id="' + localId + '" ' +
                // 'data-segment-id="' + escapeHtml(shopifySegmentId) + '">' +
                // '<span class="slider"></span>' +
                // '</label>' +
                // '</td>';
                '<td>' +
                '<label class="switch">' +
                '<input type="checkbox" class="segment-toggle sms-toggle" data-id="' + localId + '" ' +
                'data-segment-id="' + escapeHtml(shopifySegmentId) + '" data-channel="sms">' +
                '<span class="slider"></span>' +
                '</label>' +
                '</td>' +
                '<td>' +
                '<label class="switch">' +
                '<input type="checkbox" class="segment-toggle whatsapp-toggle" data-id="' + localId + '" ' +
                'data-segment-id="' + escapeHtml(shopifySegmentId) + '" data-channel="whatsapp">' +
                '<span class="slider"></span>' +
                '</label>' +
                '</td>';
            if (tbody.firstChild) {
                tbody.insertBefore(newRow, tbody.firstChild);
            } else {
                tbody.appendChild(newRow);
            }
            var toggle = newRow.querySelector('.webhook-toggle');
            if (toggle) {
                toggle.addEventListener('change', function () {
                    var toggleEl = this;
                    var id = this.dataset.id;
                    var segId = this.dataset.segmentId;
                    var status = this.checked ? 1 : 0;
                    fetch('', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'update_status=1&id=' + id + '&status=' + status + '&segment_id=' + encodeURIComponent(segId)
                    })
                        .then(function (res) { return res.json(); })
                        .then(function (result) {
                            if (result && result.success) {
                                var statusText = status === 1 ? 'enabled' : 'disabled';
                                shopify.toast.show('Segment status ' + statusText + ' successfully.', { duration: 3000 });
                            } else {
                                toggleEl.checked = !toggleEl.checked;
                                shopify.toast.show((result && result.message) ? result.message : 'Error updating status', { isError: true, duration: 3000 });
                            }
                        })
                        .catch(function () {
                            toggleEl.checked = !toggleEl.checked;
                            shopify.toast.show('Error updating status', { isError: true, duration: 3000 });
                        });
                });
            }
        }
        function updateSegmentSyncStatus(localId, status, count) {
            var row = document.getElementById('row-' + localId);
            if (!row) return;

            if (status === 'completed') {
                console.log('Segment ' + localId + ' synced: ' + count + ' customers');
            } else if (status === 'failed') {
                console.error('Segment ' + localId + ' sync failed');
            }
        }
        function escapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>