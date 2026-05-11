<?php
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
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../file/debug_log.txt');

if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('UTC');
}
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
function writeWebhookLog($message, $type = 'INFO') {
    $logFile = __DIR__ . '../file/debug_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$type}] {$message}" . PHP_EOL;
    @file_put_contents($logFile, $logEntry, FILE_APPEND);
    error_log($message);
}

writeWebhookLog("========== CUSTOMER LEFT SEGMENT WEBHOOK RECEIVED ==========");
$shop = isset($_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN']) ? $_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] : '';
$topic = isset($_SERVER['HTTP_X_SHOPIFY_TOPIC']) ? $_SERVER['HTTP_X_SHOPIFY_TOPIC'] : '';
$hmac = isset($_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256']) ? $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] : '';

writeWebhookLog("Shop: {$shop}");
writeWebhookLog("Topic: {$topic}");
$rawData = file_get_contents('php://input');
writeWebhookLog("Raw payload: " . $rawData);
$secret = "";
if (!empty($secret)) {
    $calculatedHmac = base64_encode(hash_hmac('sha256', $rawData, $secret, true));
    if (!hash_equals($hmac, $calculatedHmac)) {
        writeWebhookLog("HMAC validation failed!", "ERROR");
        set_http_status(401);
        exit("Invalid webhook signature");
    }
    writeWebhookLog("HMAC validation successful");
}
$payload = json_decode($rawData, true);
writeWebhookLog("Decoded payload: " . json_encode($payload));
$customerGid = isset($payload['customer_id']) ? $payload['customer_id'] : '';
$segmentGid = isset($payload['segment_id']) ? $payload['segment_id'] : '';

writeWebhookLog("Customer GID: {$customerGid}");
writeWebhookLog("Segment GID: {$segmentGid}");

if (empty($customerGid) || empty($segmentGid)) {
    writeWebhookLog("ERROR: Missing customer_id or segment_id in payload", "ERROR");
    set_http_status(200);
    exit("Missing required data");
}
preg_match('/\/(\d+)$/', $customerGid, $customerMatches);
$customerId = isset($customerMatches[1]) ? $customerMatches[1] : $customerGid;

preg_match('/\/(\d+)$/', $segmentGid, $segmentMatches);
$segmentId = isset($segmentMatches[1]) ? $segmentMatches[1] : $segmentGid;

writeWebhookLog("Extracted Customer ID: {$customerId}");
writeWebhookLog("Extracted Segment ID: {$segmentId}");
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../app_config.php';
try {
    $pdo = getDatabaseConnection();
    writeWebhookLog("Database connection successful");
    $configTable = $prefix . 'shopify_sms_notification_app';
    $table = $prefix."customer_segment";
    $table2 = $prefix."segment_customers_info";
    $stmt = $pdo->prepare("SELECT session_access_token AS oauth_token FROM $configTable WHERE shop = :shop");
    $stmt->execute([':shop' => $shop]);
    $shopData = $stmt->fetch();
    if (!$shopData) {
        writeWebhookLog("ERROR: Shop not found in database", "ERROR");
        set_http_status(200);
        exit("Shop not found");
    }
    
    $accessToken = $shopData['oauth_token'];
    writeWebhookLog("Access token retrieved successfully");
    $stmt = $pdo->prepare("SELECT id FROM $table WHERE segment_id = :segment_id AND shop = :shop");
    $stmt->execute([
        ':segment_id' => $segmentGid,
        ':shop' => $shop
    ]);
    $segmentRecord = $stmt->fetch();
    if (!$segmentRecord) {
        writeWebhookLog("ERROR: Segment not found in $table table: {$segmentGid}", "ERROR");
        set_http_status(200);
        exit("Segment not found in database");
    }
    
    $segmentRefId = $segmentRecord['id'];
    writeWebhookLog("Found segment_ref_id: {$segmentRefId}");
    $stmt = $pdo->prepare("
        SELECT id, email, first_name, last_name, shopify_customer_id 
        FROM $table2 
        WHERE segment_ref_id = :segment_ref_id AND shopify_customer_id = :shopify_customer_id
    ");
    $stmt->execute([
        ':segment_ref_id' => $segmentRefId,
        ':shopify_customer_id' => $customerGid
    ]);
    $existingRecord = $stmt->fetch();
    
    if ($existingRecord) {
        writeWebhookLog("Found customer record in database with ID: {$existingRecord['id']}");
        writeWebhookLog("Stored email: {$existingRecord['email']}");
        $customerDetails = fetchCustomerDetails($shop, $accessToken, $customerGid);      
        if ($customerDetails) {
            $shopifyEmail = isset($customerDetails['email']) ? $customerDetails['email'] : '';
            $shopifyFirstName = isset($customerDetails['firstName']) ? $customerDetails['firstName'] : '';
            $shopifyLastName = isset($customerDetails['lastName']) ? $customerDetails['lastName'] : '';
            
            writeWebhookLog("Shopify customer email: {$shopifyEmail}");
            writeWebhookLog("Shopify first name: {$shopifyFirstName}");
            writeWebhookLog("Shopify last name: {$shopifyLastName}");
            if (!empty($shopifyEmail) && $shopifyEmail === $existingRecord['email']) {
                writeWebhookLog("Email MATCHED! Deleting customer record...");
                $deleteStmt = $pdo->prepare("
                    DELETE FROM $table2 
                    WHERE id = :id
                ");
                $deleteStmt->execute([':id' => $existingRecord['id']]);                
                writeWebhookLog("Customer record deleted successfully for email: {$shopifyEmail}");
                
            } else {
                writeWebhookLog("Email MISMATCH! Shopify email: {$shopifyEmail}, DB email: {$existingRecord['email']}");
                writeWebhookLog("Customer record NOT deleted - emails do not match");
            }
        } else {
            writeWebhookLog("WARNING: Could not fetch customer details from Shopify", "WARNING");
            writeWebhookLog("Deleting record based on customer_id only as fallback");
            $deleteStmt = $pdo->prepare("
                DELETE FROM $table2 
                WHERE id = :id
            ");
            $deleteStmt->execute([':id' => $existingRecord['id']]);
            writeWebhookLog("Customer record deleted as fallback");
        }
        
    } else {
        writeWebhookLog("Customer not found by shopify_customer_id, fetching details from Shopify...");   
        $customerDetails = fetchCustomerDetails($shop, $accessToken, $customerGid); 
        if ($customerDetails) {
            $customerEmail = isset($customerDetails['email']) ? $customerDetails['email'] : '';
            $customerFirstName = isset($customerDetails['firstName']) ? $customerDetails['firstName'] : '';
            $customerLastName = isset($customerDetails['lastName']) ? $customerDetails['lastName'] : '';
            writeWebhookLog("Customer email from Shopify: {$customerEmail}");
            writeWebhookLog("Customer first name: {$customerFirstName}");
            writeWebhookLog("Customer last name: {$customerLastName}");
            if (!empty($customerEmail)) {
                $stmt = $pdo->prepare("
                    SELECT id, email FROM $table2 
                    WHERE segment_ref_id = :segment_ref_id AND email = :email
                ");
                $stmt->execute([
                    ':segment_ref_id' => $segmentRefId,
                    ':email' => $customerEmail
                ]);
                $recordByEmail = $stmt->fetch();
                
                if ($recordByEmail) {
                    writeWebhookLog("Found customer record by email: {$recordByEmail['id']}");
                    $deleteStmt = $pdo->prepare("
                        DELETE FROM $table2 
                        WHERE id = :id
                    ");
                    $deleteStmt->execute([':id' => $recordByEmail['id']]);
                    
                    writeWebhookLog("Customer record deleted successfully by email match");
                } else {
                    writeWebhookLog("No customer record found with email: {$customerEmail}");
                }
            } else {
                writeWebhookLog("No email found in Shopify customer details");
            }
        } else {
            writeWebhookLog("ERROR: Could not fetch customer details from Shopify", "ERROR");
        }
    }
    
    writeWebhookLog("Webhook processed successfully");
    set_http_status(200);
    echo "OK";
    
} catch (Exception $e) {
    writeWebhookLog("ERROR: Exception occurred - " . $e->getMessage(), "ERROR");
    writeWebhookLog("Stack trace: " . $e->getTraceAsString(), "ERROR");
    set_http_status(200);
    echo "Error: " . $e->getMessage();
}
function fetchCustomerDetails($shop, $accessToken, $customerGid) {
    writeWebhookLog("Fetching customer details from Shopify for: {$customerGid}");
    
    $query = '
    query GetCustomerDetails($customerId: ID!) {
        customer(id: $customerId) {
            id
            firstName
            lastName
            email
            phone
        }
    }';
    
    $variables = ['customerId' => $customerGid];
    
    $url = "https://{$shop}/admin/api/2026-01/graphql.json";
    $payload = json_encode([
        'query' => $query,
        'variables' => $variables
    ]);
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "X-Shopify-Access-Token: {$accessToken}"
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        writeWebhookLog("cURL error: " . curl_error($ch), "ERROR");
        curl_close($ch);
        return null;
    }
    
    curl_close($ch);
    
    writeWebhookLog("GraphQL response code: {$httpCode}");
    writeWebhookLog("GraphQL response: " . $response);
    
    $result = json_decode($response, true);
    
    if (isset($result['data']['customer'])) {
        writeWebhookLog("Customer details fetched successfully");
        return $result['data']['customer'];
    }
    
    if (isset($result['errors'])) {
        writeWebhookLog("GraphQL errors: " . json_encode($result['errors']), "ERROR");
    }
    return null;
}
?>