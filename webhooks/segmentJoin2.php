<?php
if (!function_exists('set_http_status')) {
    function set_http_status($code)
    {
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
            header('HTTP/1.1 ' . (int) $code);
        }
    }
}
include '../send_sms_api.php';
include '../send_whatsapp_message_api.php';
include  '../accurate_country_code.php';
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '../file/debug_log.txt');

if (function_exists('date_default_timezone_set')) {
    date_default_timezone_set('UTC');
}
if (!function_exists('hash_equals')) {
    function hash_equals($known_string, $user_string)
    {
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
if (!function_exists('wa_replace_placeholders')) {
    function wa_replace_placeholders($text, $replacements)
    {
        if (!is_string($text))
            return $text;

        foreach ($replacements as $key => $value) {
            $text = str_replace('{{ ' . $key . ' }}', $value, $text);
            $text = str_replace('{{' . $key . '}}', $value, $text);
        }
        return $text;
    }
}
function writeWebhookLog($message, $type = 'INFO')
{
    $logFile = __DIR__ . '../file/debug_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[{$timestamp}] [{$type}] {$message}" . PHP_EOL;
    @file_put_contents($logFile, $logEntry, FILE_APPEND);
    error_log($message);
}
writeWebhookLog("========== CUSTOMER JOINED SEGMENT WEBHOOK RECEIVED ==========");
$shop = isset($_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN']) ? $_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] : '';
$topic = isset($_SERVER['HTTP_X_SHOPIFY_TOPIC']) ? $_SERVER['HTTP_X_SHOPIFY_TOPIC'] : '';
$hmac = isset($_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256']) ? $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] : '';

writeWebhookLog("Shop: {$shop}");
writeWebhookLog("Topic: {$topic}");

$rawData = file_get_contents('php://input');
writeWebhookLog("Raw payload: " . $rawData);

$secret = $api_secret;
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
    $table = $prefix . "customer_segment";
    $table2 = $prefix . "segment_customers_info";
    $stmt = $pdo->prepare("SELECT session_access_token AS oauth_token FROM $configTable WHERE shop = :shop");
    $stmt->execute(array(':shop' => $shop));
    $shopData = $stmt->fetch();
    if (!$shopData) {
        writeWebhookLog("ERROR: Shop not found in database", "ERROR");
        set_http_status(200);
        exit("Shop not found");
    }
    $accessToken = $shopData['oauth_token'];
    writeWebhookLog("Access token retrieved successfully");

    $stmt = $pdo->prepare("SELECT id FROM $table WHERE segment_id = :segment_id AND shop = :shop");
    $stmt->execute(array(
        ':segment_id' => $segmentGid,
        ':shop' => $shop
    ));
    $segmentRecord = $stmt->fetch();
    if (!$segmentRecord) {
        writeWebhookLog("ERROR: Segment not found in $table table: {$segmentGid}", "ERROR");
        set_http_status(200);
        exit("Segment not found in database");
    }
    $segmentDbId = $segmentRecord['id'];
    writeWebhookLog("Found segment DB id: {$segmentDbId}");
    $segmentMemberId = fetchCustomerSegmentMemberId($shop, $accessToken, $customerGid, $segmentGid);
    if (!$segmentMemberId) {
        writeWebhookLog("ERROR: Failed to fetch customer segment member ID", "ERROR");
        set_http_status(200);
        exit("Failed to fetch customer segment member ID");
    }
    writeWebhookLog("Customer Segment Member ID: {$segmentMemberId}");

    $customerDetails = fetchCustomerDetails($shop, $accessToken, $customerGid);
    if (!$customerDetails) {
        writeWebhookLog("ERROR: Failed to fetch customer details from Shopify", "ERROR");
        set_http_status(200);
        exit("Failed to fetch customer details");
    }
    writeWebhookLog("Customer details retrieved: " . json_encode($customerDetails));
    $defaultAddress = isset($customerDetails['defaultAddress']) ? $customerDetails['defaultAddress'] : array();
    $addresses = isset($customerDetails['addresses']) ? $customerDetails['addresses'] : array();
    $primaryAddress = !empty($defaultAddress) ? $defaultAddress : (isset($addresses[0]) ? $addresses[0] : array());

    $insertData = array(
        'segment_id' => $segmentGid,
        'shopify_customer_id' => $segmentMemberId,
        'first_name' => isset($customerDetails['firstName']) ? $customerDetails['firstName'] : '',
        'last_name' => isset($customerDetails['lastName']) ? $customerDetails['lastName'] : '',
        'email' => isset($customerDetails['email']) ? $customerDetails['email'] : '',
        'phone' => isset($customerDetails['phone']) ? $customerDetails['phone'] : '',
        'address1' => isset($primaryAddress['address1']) ? $primaryAddress['address1'] : '',
        'address2' => isset($primaryAddress['address2']) ? $primaryAddress['address2'] : '',
        'city' => isset($primaryAddress['city']) ? $primaryAddress['city'] : '',
        'country' => isset($primaryAddress['countryCodeV2']) ? $primaryAddress['countryCodeV2'] : '',
        'country_code' => isset($primaryAddress['countryCodeV2']) ? $primaryAddress['countryCodeV2'] : '',
        'zip' => isset($primaryAddress['zip']) ? $primaryAddress['zip'] : '',
        'created_at' => date('Y-m-d H:i:s')
    );
    writeWebhookLog("Data to insert: " . json_encode($insertData));

    $stmt = $pdo->prepare("
        SELECT id FROM $table2 
        WHERE shopify_customer_id = :shopify_customer_id
    ");
    $stmt->execute(array(
        ':shopify_customer_id' => $segmentMemberId
    ));

    if ($stmt->fetch()) {
        writeWebhookLog("Customer already exists in segment, updating information...");
        $updateStmt = $pdo->prepare("
            UPDATE $table2 
            SET first_name = :first_name,
                last_name = :last_name,
                email = :email,
                phone = :phone,
                address1 = :address1,
                address2 = :address2,
                city = :city,
                country = :country,
                country_code = :country_code,
                zip = :zip
            WHERE shopify_customer_id = :shopify_customer_id
        ");
        $updateStmt->execute(array(
            ':first_name' => $insertData['first_name'],
            ':last_name' => $insertData['last_name'],
            ':email' => $insertData['email'],
            ':phone' => $insertData['phone'],
            ':address1' => $insertData['address1'],
            ':address2' => $insertData['address2'],
            ':city' => $insertData['city'],
            ':country' => $insertData['country'],
            ':country_code' => $insertData['country_code'],
            ':zip' => $insertData['zip'],
            ':shopify_customer_id' => $segmentMemberId
        ));
        writeWebhookLog("Customer information updated successfully");
    } else {
        $insertStmt = $pdo->prepare("
            INSERT INTO $table2 (
                segment_id,
                shopify_customer_id,
                first_name,
                last_name,
                email,
                phone,
                address1,
                address2,
                city,
                country,
                country_code,
                zip,
                created_at
            ) VALUES (
                :segment_id,
                :shopify_customer_id,
                :first_name,
                :last_name,
                :email,
                :phone,
                :address1,
                :address2,
                :city,
                :country,
                :country_code,
                :zip,
                :created_at
            )
        ");

        $insertStmt->execute($insertData);
        writeWebhookLog("Customer inserted successfully into $table2 table");
    }
    $customerFirstName = isset($customerDetails['firstName']) ? $customerDetails['firstName'] : '';
    $customerLastName = isset($customerDetails['lastName']) ? $customerDetails['lastName'] : '';
    $customerFullName = trim($customerFirstName . ' ' . $customerLastName);
    $customerEmail = isset($customerDetails['email']) ? $customerDetails['email'] : '';
    $phoneNumber = isset($customerDetails['phone']) ? $customerDetails['phone'] : '';

    //$countryCode = isset($customerDetails['country_code']) ? $customerDetails['country_code'] : '';
    $countryCode = isset($customerDetails['country_code']) ? $customerDetails['country_code'] : '';
    if (empty($countryCode) && isset($primaryAddress['countryCodeV2'])) {
        $countryCode = $primaryAddress['countryCodeV2'];
    }

    if (!empty($countryCode) && strlen($countryCode) == 2) {
        // $ccodes array is defined in accurate_country_code.php
        $countryCodeUpper = strtoupper($countryCode);
        if (isset($ccodes[$countryCodeUpper])) {
            $countryCode = $ccodes[$countryCodeUpper]; // Convert "IN" to 91
        }
    }

    if (substr($phoneNumber, 0, 1) === '0') {
        $phoneNumber = substr($phoneNumber, 1);
    }
    // if (empty($countryCode) && isset($primaryAddress['countryCodeV2'])) {
    //     $countryCode = $primaryAddress['countryCodeV2'];
    // }

    // if (substr($phoneNumber, 0, 1) === '0') {
    //     $phoneNumber = substr($phoneNumber, 1);
    // }

    $stmt = $pdo->prepare("
    SELECT conditions, sms_enabled, sms, sms_variables, template_id_sms, whatsapp, whatsapp_enabled 
    FROM $table 
    WHERE id = :segment_id AND shop = :shop
");
    $stmt->execute(array(
        ':segment_id' => $segmentDbId,
        ':shop' => $shop
    ));
    $segmentSettings = $stmt->fetch();

    if ($segmentSettings) {
        $conditions = $segmentSettings['conditions'];
        $sms_enabled = isset($segmentSettings['sms_enabled']) ? (int) $segmentSettings['sms_enabled'] : 0;
        $template_name_sms = isset($segmentSettings['template_id_sms']) ? $segmentSettings['template_id_sms'] : '';
        $whatsapp_enabled = isset($segmentSettings['whatsapp_enabled']) ? (int) $segmentSettings['whatsapp_enabled'] : 0;

        writeWebhookLog("Segment conditions: {$conditions}, SMS enabled: {$sms_enabled}, WhatsApp enabled: {$whatsapp_enabled}");

        if ($conditions === '{"type":"When Customer Joins Segment"}') {

            // ============ SEND SMS ============
            if ($sms_enabled == 1) {
                writeWebhookLog("SMS is enabled - sending");

                $smsVariables = array();
                if (!empty($segmentSettings['sms_variables'])) {
                    $smsVariables = json_decode($segmentSettings['sms_variables'], true);
                    if (!is_array($smsVariables)) {
                        $smsVariables = array();
                    }
                }

                $parameter_values = array();
                if (!empty($smsVariables) && is_array($smsVariables)) {
                    foreach ($smsVariables as $key => $value) {
                        $processed_value = $value;
                        $processed_value = str_replace('{{ customer_fname }}', $customerFirstName, $processed_value);
                        $processed_value = str_replace('{{ customer_full_name }}', $customerFullName, $processed_value);
                        $processed_value = str_replace('{{ customer_lname }}', $customerLastName, $processed_value);
                        $processed_value = str_replace('{{ customer_name }}', $customerFullName, $processed_value);
                        $processed_value = str_replace('{{ customer_email_id }}', $customerEmail, $processed_value);
                        $processed_value = str_replace('{{ customer_phone }}', $phoneNumber, $processed_value);
                        $parameter_values[$key] = $processed_value;
                    }
                }

                if (function_exists('send_smstext_with_parameters')) {
                    send_smstext_with_parameters(
                        $countryCode,
                        $phoneNumber,
                        $template_name_sms,
                        $shop,
                        $customerEmail,
                        $customerFullName,
                        $parameter_values,
                        $customerFullName,
                        '',
                        "Customer Segment"
                    );
                    writeWebhookLog("SMS sent successfully");
                } else {
                    writeWebhookLog("ERROR: send_smstext_with_parameters function not found", "ERROR");
                }
            } else {
                writeWebhookLog("SMS is NOT enabled - skipping");
            }
            if ($whatsapp_enabled == 1) {
                writeWebhookLog("WhatsApp is enabled - sending");

                $whatsappTemplateName = '';
                $whatsappData = array();

                if (!empty($segmentSettings['whatsapp'])) {
                    $whatsappData = json_decode($segmentSettings['whatsapp'], true);
                    if (is_array($whatsappData)) {
                        $whatsappTemplateName = isset($whatsappData['template_name']) ? $whatsappData['template_name'] : '';
                    }
                }

                if (!empty($whatsappTemplateName)) {
                    $whatsappApiConfig = array(
                        'log_file' => "$logFile"
                    );

                    $whatsappMediaType = isset($whatsappData['media_type']) ? $whatsappData['media_type'] : 'text';
                    $whatsappMediaUrl = isset($whatsappData['media_url']) ? $whatsappData['media_url'] : '';
                    $whatsappMediaSource = isset($whatsappData['media_source']) ? $whatsappData['media_source'] : '';

                    $headerVariables = isset($whatsappData['variable_headers']) ? $whatsappData['variable_headers'] : array();
                    $bodyVariables = isset($whatsappData['variable_body']) ? $whatsappData['variable_body'] : array();

                    $replacementMap = array(
                        'customer_fname' => $customerFirstName,
                        'customer_lname' => $customerLastName,
                        'customer_name' => $customerFullName,
                        'customer_full_name' => $customerFullName,
                        'customer_email_id' => $customerEmail,
                        'customer_phone' => $phoneNumber,
                        'phone_number' => $phoneNumber,
                        'country_code' => $countryCode,
                        'email_id' => $customerEmail,
                        'customer_id' => $customerId,
                        'segment_id' => $segmentId
                    );

                    $processedHeaders = array();
                    if (!empty($headerVariables)) {
                        foreach ($headerVariables as $headerVar) {
                            $processedHeaders[] = wa_replace_placeholders($headerVar, $replacementMap);
                        }
                    }

                    $processedBody = array();
                    if (!empty($bodyVariables)) {
                        foreach ($bodyVariables as $bodyVar) {
                            $processedBody[] = wa_replace_placeholders($bodyVar, $replacementMap);
                        }
                    }

                    if (!empty($whatsappMediaUrl)) {
                        $whatsappMediaUrl = wa_replace_placeholders($whatsappMediaUrl, $replacementMap);
                    }

                    $buttons = array();
                    $whatsappCtaUrls = isset($whatsappData['cta_url']) ? $whatsappData['cta_url'] : array();
                    if (!is_array($whatsappCtaUrls)) {
                        $whatsappCtaUrls = array();
                    }

                    if (isset($whatsappData['button_type']) && $whatsappData['button_type'] !== 'none' && !empty($whatsappData['button_text1_type'])) {
                        $btnText = wa_replace_placeholders(trim($whatsappData['button_text1_type']), $replacementMap);
                        $btnUrl = isset($whatsappCtaUrls['button1']) ? wa_replace_placeholders($whatsappCtaUrls['button1'], $replacementMap) : '';
                        $buttons[] = array(
                            'sub_type' => ($whatsappData['button_type'] === 'cta') ? 'url' : 'quick_reply',
                            'index' => '0',
                            'value' => ($whatsappData['button_type'] === 'cta') ? $btnUrl : $btnText,
                            'button_text' => $btnText
                        );
                    }
                    if (isset($whatsappData['button_type2']) && $whatsappData['button_type2'] !== 'none' && !empty($whatsappData['button_text2_type'])) {
                        $btnText = wa_replace_placeholders(trim($whatsappData['button_text2_type']), $replacementMap);
                        $btnUrl = isset($whatsappCtaUrls['button2']) ? wa_replace_placeholders($whatsappCtaUrls['button2'], $replacementMap) : '';
                        $buttons[] = array(
                            'sub_type' => ($whatsappData['button_type2'] === 'cta') ? 'url' : 'quick_reply',
                            'index' => '1',
                            'value' => ($whatsappData['button_type2'] === 'cta') ? $btnUrl : $btnText,
                            'button_text' => $btnText
                        );
                    }
                    if (isset($whatsappData['button_type3']) && $whatsappData['button_type3'] !== 'none' && !empty($whatsappData['button_text3_type'])) {
                        $btnText = wa_replace_placeholders(trim($whatsappData['button_text3_type']), $replacementMap);
                        $btnUrl = isset($whatsappCtaUrls['button3']) ? wa_replace_placeholders($whatsappCtaUrls['button3'], $replacementMap) : '';
                        $buttons[] = array(
                            'sub_type' => ($whatsappData['button_type3'] === 'cta') ? 'url' : 'quick_reply',
                            'index' => '2',
                            'value' => ($whatsappData['button_type3'] === 'cta') ? $btnUrl : $btnText,
                            'button_text' => $btnText
                        );
                    }

                    $whatsappConfig = array_merge($whatsappApiConfig, array(
                        'to' => $countryCode . $phoneNumber,
                        'template_name' => $whatsappTemplateName,
                        'language_code' => 'en',
                        'media_type' => $whatsappMediaType,
                        'media_url' => $whatsappMediaUrl,
                        'media_source' => $whatsappMediaSource,
                        'variable_headers' => $processedHeaders,
                        'variable_body' => $processedBody,
                        'buttons' => $buttons,
                        'shop' => $shop,
                        'order_id' => '',
                        'order_name' => $customerFullName,
                        'customer_email' => $customerEmail,
                        'country_code' => $countryCode,
                        'phone_num' => $phoneNumber,
                        'notification_type' => 'Customer Segment'
                    ));

                    if (empty($phoneNumber)) {
                        writeWebhookLog("ERROR: Customer phone is empty", "ERROR");
                    } else {
                        $logTable = $prefix . "shopify_sms_notification_App_Log_Details";
                        if (function_exists('send_whatsapp_message')) {
                            $whatsappResult = send_whatsapp_message($whatsappConfig, $pdo, $logTable);
                            if ($whatsappResult['success']) {
                                writeWebhookLog("WhatsApp sent successfully");
                            } else {
                                writeWebhookLog("WhatsApp failed: " . $whatsappResult['message'], "ERROR");
                            }
                        } else {
                            writeWebhookLog("ERROR: send_whatsapp_message function not found", "ERROR");
                        }
                    }
                } else {
                    writeWebhookLog("No WhatsApp template configured - skipping");
                }
            } else {
                writeWebhookLog("WhatsApp is NOT enabled - skipping");
            }
        } else {
            writeWebhookLog("Conditions NOT met. Skipping all notifications.");
        }
    } else {
        writeWebhookLog("Segment settings not found for segment_id: {$segmentDbId}");
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

function fetchCustomerSegmentMemberId($shop, $accessToken, $customerGid, $segmentGid)
{
    writeWebhookLog("Fetching customer segment member ID for customer: {$customerGid}, segment: {$segmentGid}");
    $query = '
    query GetSegmentMemberInfo($customerId: ID!, $segmentId: ID!) {
        customer(id: $customerId) {
            segmentMembers(first: 10, segmentId: $segmentId) {
                edges {
                    node {
                        id
                        segment {
                            id
                        }
                    }
                }
            }
        }
    }';

    $variables = array(
        'customerId' => $customerGid,
        'segmentId' => $segmentGid
    );

    $url = "https://{$shop}/admin/api/2026-01/graphql.json";
    $payload = json_encode(array(
        'query' => $query,
        'variables' => $variables
    ));

    writeWebhookLog("GraphQL query payload: " . $payload);

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
        writeWebhookLog("cURL error in fetchCustomerSegmentMemberId: " . curl_error($ch), "ERROR");
        curl_close($ch);
        return null;
    }
    curl_close($ch);

    writeWebhookLog("GraphQL response for segment member: " . $response);
    $result = json_decode($response, true);
    if (isset($result['data']['customer']['segmentMembers']['edges'][0]['node']['id'])) {
        $segmentMemberId = $result['data']['customer']['segmentMembers']['edges'][0]['node']['id'];
        writeWebhookLog("Successfully fetched segment member ID: {$segmentMemberId}");
        return $segmentMemberId;
    }
    if (isset($result['errors'])) {
        writeWebhookLog("GraphQL errors in fetchCustomerSegmentMemberId: " . json_encode($result['errors']), "ERROR");
    }
    preg_match('/\/(\d+)$/', $customerGid, $customerMatches);
    preg_match('/\/(\d+)$/', $segmentGid, $segmentMatches);

    if (!empty($customerMatches[1]) && !empty($segmentMatches[1])) {
        $constructedMemberId = "gid://shopify/SegmentMember/{$customerMatches[1]}_{$segmentMatches[1]}";
        writeWebhookLog("Constructed segment member ID: {$constructedMemberId}");
        return $constructedMemberId;
    }

    writeWebhookLog("No segment member found for customer in this segment", "WARNING");
    return null;
}

function fetchCustomerDetails($shop, $accessToken, $customerGid)
{
    writeWebhookLog("Fetching customer details for: {$customerGid}");
    $query = '
    query GetCustomerDetails($customerId: ID!) {
        customer(id: $customerId) {
            id
            firstName
            lastName
            defaultEmailAddress {
                emailAddress
            }
            defaultPhoneNumber {
                phoneNumber
            }
            createdAt
            addressesV2(first: 10) {
                edges {
                    node {
                        address1
                        address2
                        city
                        countryCodeV2
                        firstName
                        lastName
                        zip
                        phone
                    }
                }
            }
        }
    }';
    $variables = array('customerId' => $customerGid);
    $url = "https://{$shop}/admin/api/2026-01/graphql.json";
    $payload = json_encode(array(
        'query' => $query,
        'variables' => $variables
    ));

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
        writeWebhookLog("cURL error: " . curl_error($ch), "ERROR");
        curl_close($ch);
        return null;
    }
    curl_close($ch);

    writeWebhookLog("GraphQL response code: {$httpCode}");
    writeWebhookLog("GraphQL response: " . $response);
    $result = json_decode($response, true);
    if (isset($result['data']['customer'])) {
        $customer = $result['data']['customer'];
        $addresses = array();
        $defaultAddress = array();
        if (isset($customer['addressesV2']['edges'])) {
            foreach ($customer['addressesV2']['edges'] as $edge) {
                $addresses[] = $edge['node'];
            }
            if (!empty($addresses)) {
                $defaultAddress = $addresses[0];
            }
        }
        $formattedCustomer = array(
            'id' => $customer['id'],
            'firstName' => isset($customer['firstName']) ? $customer['firstName'] : '',
            'lastName' => isset($customer['lastName']) ? $customer['lastName'] : '',
            'email' => (isset($customer['defaultEmailAddress']) && isset($customer['defaultEmailAddress']['emailAddress'])) ? $customer['defaultEmailAddress']['emailAddress'] : '',
            'phone' => (isset($customer['defaultPhoneNumber']) && isset($customer['defaultPhoneNumber']['phoneNumber'])) ? $customer['defaultPhoneNumber']['phoneNumber'] : '',
            'createdAt' => isset($customer['createdAt']) ? $customer['createdAt'] : '',
            'defaultAddress' => $defaultAddress,
            'addresses' => $addresses,
            'country_code' => isset($defaultAddress['countryCodeV2']) ? $defaultAddress['countryCodeV2'] : ''
        );
        writeWebhookLog("Successfully fetched customer: {$formattedCustomer['firstName']} {$formattedCustomer['lastName']}");
        return $formattedCustomer;
    }
    if (isset($result['errors'])) {
        writeWebhookLog("GraphQL errors: " . json_encode($result['errors']), "ERROR");
    }
    return null;
}
