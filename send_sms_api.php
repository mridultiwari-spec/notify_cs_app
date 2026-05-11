<?php

function send_smstext($country_code, $phone_number, $template_name_sms, $shop, $customer_email_id, $customer_full_name, $order_id = '', $order_name = '', $notification_type)
{
    require_once __DIR__ . '/config/db.php';
    require_once __DIR__ . '/app_config.php';
    global $prefix;
    $pdo = getDatabaseConnection();
    $table = "$prefix" . "shopify_sms_notification_app_API_Settings";
    $table2 = "$prefix" . "shopify_sms_notification_App_Log_Details";
    $logFile = "sms_log.txt";
    file_put_contents($logFile, "========== SMS SEND ATTEMPT ==========\n", FILE_APPEND);
    
    if (strpos($phone_number, '.myshopify.com') !== false) {
        file_put_contents($logFile, "Detected swapped parameters - fixing...\n", FILE_APPEND);
        $temp = $phone_number;
        $phone_number = $final_sms;
        $final_sms = $temp;
        if (strpos($shop, '.myshopify.com') === false && strpos($phone_number, '.myshopify.com') !== false) {
            $temp_shop = $shop;
            $shop = $phone_number;
            $phone_number = $temp_shop;
        }
    }
    
    if (
        filter_var($customer_email_id, FILTER_VALIDATE_EMAIL) === false &&
        filter_var($customer_full_name, FILTER_VALIDATE_EMAIL) !== false
    ) {
        file_put_contents($logFile, "Detected swapped email and name - fixing...\n", FILE_APPEND);
        $temp = $customer_email_id;
        $customer_email_id = $customer_full_name;
        $customer_full_name = $temp;
    }
    
    file_put_contents($logFile, "Country Code: {$country_code}\n", FILE_APPEND);
    file_put_contents($logFile, "Phone Number: {$phone_number}\n", FILE_APPEND);
    
    file_put_contents($logFile, "Shop: {$shop}\n", FILE_APPEND);
    file_put_contents($logFile, "Customer Email: {$customer_email_id}\n", FILE_APPEND);
    file_put_contents($logFile, "Customer Name: {$customer_full_name}\n", FILE_APPEND);
    file_put_contents($logFile, "Order ID: {$order_id}\n", FILE_APPEND);
    file_put_contents($logFile, "Order Name: {$order_name}\n", FILE_APPEND);
    
    if (empty($phone_number) || empty($final_sms)) {
        file_put_contents($logFile, "ERROR: Phone number or SMS text is empty\n", FILE_APPEND);
        return false;
    }
    
    $clean_phone = preg_replace('/[^0-9]/', '', $phone_number);
    if (substr($clean_phone, 0, 1) === '0') {
        $clean_phone = substr($clean_phone, 1);
    }
    $clean_country_code = preg_replace('/[^0-9]/', '', $country_code);
    $to = '+' . $clean_country_code . $clean_phone;
    file_put_contents($logFile, "Formatted TO: {$to}\n", FILE_APPEND);
    
    $stmt = $pdo->prepare("
    SELECT api_token, details, channel_id
    FROM $table 
    WHERE shop = :shop AND type = 'sms'
    LIMIT 1
");

    $stmt->execute(array(':shop' => $shop));
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    $access_token = isset($data['api_token']) ? $data['api_token'] : '';
    $sender_id = isset($data['details']) ? $data['details'] : '';
    $channel_id = isset($data['channel_id']) ? $data['channel_id'] : '';

    $url = "https://messaginghub.solutions/chatbird/api/message/send";
    $payload = array(
        "channelId" => $channel_id,
        "from" => $sender_id,
        "to" => $to,
        "channel" => "SMS",
        "deptId" => "",
        "callbackUrl" => "",
        "message" => array(
            "template" => array(
                "template_name" => $template_name_sms
            )
            
        )
    );
    $jsonPayload = json_encode($payload);
    
    file_put_contents($logFile, "PAYLOAD: " . $jsonPayload . "\n", FILE_APPEND);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "authentication-token: $access_token",
        "Content-Type: application/json",
        'longTermToken: true'
    ));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $status = ($response && $httpCode == 200) ? "success" : "failed";
    $responseData = json_decode($response, true);
    
    if ($responseData && isset($responseData['status']) && $responseData['status'] === true) {
        file_put_contents($logFile, "Message accepted. ID: " . (isset($responseData['data']['id']) ? $responseData['data']['id'] : 'N/A') . "\n", FILE_APPEND);
        $status = "success";
    } else {
        $errorMsg = isset($responseData['message']) ? $responseData['message'] : 'Unknown error';
        file_put_contents($logFile, "Message failed: {$errorMsg}\n", FILE_APPEND);
        $status = "failed";
    }

    file_put_contents($logFile, "HTTP Code: {$httpCode}\n", FILE_APPEND);
    file_put_contents($logFile, "Response: {$response}\n", FILE_APPEND);
    file_put_contents($logFile, "CURL Error: {$error}\n", FILE_APPEND);
    
    try {
        $log_order_id = !empty($order_id) ? $order_id : ($order_name ? $order_name : '');
        $log_order_name = !empty($order_name) ? $order_name : ($order_id ? $order_id : '');

        $stmt = $pdo->prepare("
            INSERT INTO $table2 (
                shop,
                items_id,
                items_name,
                customer_email,
                country_code,
                phone_num,
                action_name,
                updated_time,
                api_name,
                response_result,
                api_response,
                api_request,
                notification_type
            ) VALUES (
                :shop,
                :items_id,
                :items_name,
                :customer_email,
                :country_code,
                :phone_num,
                :action_name,
                NOW(),
                :api_name,
                :response_result,
                :api_response,
                :api_request,
                :notification_type
            )
        ");
        $stmt->execute(array(
            ':shop' => isset($shop) ? $shop : '',
            ':items_id' => $log_order_id,
            ':items_name' => $log_order_name,
            ':customer_email' => isset($customer_email_id) ? $customer_email_id : '',
            ':country_code' => isset($clean_country_code) ? $clean_country_code : '',
            ':phone_num' => isset($clean_phone) ? $clean_phone : '',
            ':action_name' => 'Send SMS',
            ':api_name' => 'Send SMS API',
            ':response_result' => $status,
            ':api_response' => isset($response) ? $response : '',
            ':api_request' => isset($jsonPayload) ? $jsonPayload : '',
            ':notification_type' => isset($notification_type) ? $notification_type : ''
        ));
        file_put_contents($logFile, "Database log inserted\n", FILE_APPEND);
    } catch (Exception $e) {
        file_put_contents($logFile, "DB ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    }

    file_put_contents($logFile, "========================================\n\n", FILE_APPEND);

    return $status === "success";
}

function send_smstext_with_parameters($country_code, $phone_number, $template_name_sms, $shop, $customer_email_id, $customer_full_name, $parameter_values, $order_id = '', $order_name = '', $notification_type)
{
    require_once __DIR__ . '/config/db.php';
    require_once __DIR__ . '/app_config.php';
    global $prefix;
    $pdo = getDatabaseConnection();
    $table = "$prefix" . "shopify_sms_notification_app_API_Settings";
    $table2 = "$prefix" . "shopify_sms_notification_App_Log_Details";
    $logFile = "sms_log.txt";
    file_put_contents($logFile, "========== SMS SEND WITH PARAMETERS ATTEMPT ==========\n", FILE_APPEND);
    if (strpos($phone_number, '.myshopify.com') !== false) {
        file_put_contents($logFile, "Detected swapped parameters - fixing...\n", FILE_APPEND);
        $temp = $phone_number;
        $phone_number = $final_sms;
        $final_sms = $temp;
        if (strpos($shop, '.myshopify.com') === false && strpos($phone_number, '.myshopify.com') !== false) {
            $temp_shop = $shop;
            $shop = $phone_number;
            $phone_number = $temp_shop;
        }
    }
    
    if (
        filter_var($customer_email_id, FILTER_VALIDATE_EMAIL) === false &&
        filter_var($customer_full_name, FILTER_VALIDATE_EMAIL) !== false
    ) {
        file_put_contents($logFile, "Detected swapped email and name - fixing...\n", FILE_APPEND);
        $temp = $customer_email_id;
        $customer_email_id = $customer_full_name;
        $customer_full_name = $temp;
    }
    
    file_put_contents($logFile, "Country Code: {$country_code}\n", FILE_APPEND);
    file_put_contents($logFile, "Phone Number: {$phone_number}\n", FILE_APPEND);
    file_put_contents($logFile, "Template ID: {$template_name_sms}\n", FILE_APPEND);
    file_put_contents($logFile, "Parameter Values: " . json_encode($parameter_values) . "\n", FILE_APPEND);
    file_put_contents($logFile, "Shop: {$shop}\n", FILE_APPEND);
    file_put_contents($logFile, "Customer Email: {$customer_email_id}\n", FILE_APPEND);
    file_put_contents($logFile, "Customer Name: {$customer_full_name}\n", FILE_APPEND);
    file_put_contents($logFile, "Order ID: {$order_id}\n", FILE_APPEND);
    file_put_contents($logFile, "Order Name: {$order_name}\n", FILE_APPEND);
    
    if (empty($phone_number)) {
        file_put_contents($logFile, "ERROR: Phone number is empty\n", FILE_APPEND);
        return false;
    }
    
    if (empty($template_name_sms)) {
        file_put_contents($logFile, "ERROR: Template ID is empty\n", FILE_APPEND);
        return false;
    }
    
    if (empty($parameter_values) || !is_array($parameter_values)) {
        file_put_contents($logFile, "ERROR: Parameter values are empty or not an array\n", FILE_APPEND);
        return false;
    }
    
    $clean_phone = preg_replace('/[^0-9]/', '', $phone_number);
    if (substr($clean_phone, 0, 1) === '0') {
        $clean_phone = substr($clean_phone, 1);
    }
    $clean_country_code = preg_replace('/[^0-9]/', '', $country_code);
    $to = '+' . $clean_country_code . $clean_phone;
    file_put_contents($logFile, "Formatted TO: {$to}\n", FILE_APPEND);
    
    $stmt = $pdo->prepare("
    SELECT api_token, details, channel_id
    FROM $table 
    WHERE shop = :shop AND type = 'sms'
    LIMIT 1
");

    $stmt->execute(array(':shop' => $shop));
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    $access_token = isset($data['api_token']) ? $data['api_token'] : '';
    $sender_id = isset($data['details']) ? $data['details'] : '';
    $channel_id = isset($data['channel_id']) ? $data['channel_id'] : '';

    $url = "https://messaginghub.solutions/chatbird/api/message/send";
    
    $payload = array(
        "channelId" => $channel_id,
        "from" => $sender_id,
        "to" => $to,
        "channel" => "SMS",
        "deptId" => "",
        "callbackUrl" => "",
        "message" => array(
            //"sms_text" => $processed_sms_text,
            "template" => array(
                "templateId" => $template_name_sms,
                "parameterValues" => $parameter_values
            )
        )
    );
    
    $jsonPayload = json_encode($payload);
    
    file_put_contents($logFile, "PAYLOAD: " . $jsonPayload . "\n", FILE_APPEND);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "authentication-token: $access_token",
        "Content-Type: application/json",
        'longTermToken: true'
    ));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $status = ($response && $httpCode == 200) ? "success" : "failed";
    $responseData = json_decode($response, true);
    
    if ($responseData && isset($responseData['status']) && $responseData['status'] === true) {
        file_put_contents($logFile, "Message accepted. ID: " . (isset($responseData['data']['id']) ? $responseData['data']['id'] : 'N/A') . "\n", FILE_APPEND);
        $status = "success";
    } else {
        $errorMsg = isset($responseData['message']) ? $responseData['message'] : 'Unknown error';
        file_put_contents($logFile, "Message failed: {$errorMsg}\n", FILE_APPEND);
        $status = "failed";
    }

    file_put_contents($logFile, "HTTP Code: {$httpCode}\n", FILE_APPEND);
    file_put_contents($logFile, "Response: {$response}\n", FILE_APPEND);
    file_put_contents($logFile, "CURL Error: {$error}\n", FILE_APPEND);
    
    try {
        $log_order_id = !empty($order_id) ? $order_id : ($order_name ? $order_name : '');
        $log_order_name = !empty($order_name) ? $order_name : ($order_id ? $order_id : '');

        $stmt = $pdo->prepare("
            INSERT INTO $table2 (
                shop,
                items_id,
                items_name,
                customer_email,
                country_code,
                phone_num,
                action_name,
                updated_time,
                api_name,
                response_result,
                api_response,
                api_request,
                notification_type
            ) VALUES (
                :shop,
                :items_id,
                :items_name,
                :customer_email,
                :country_code,
                :phone_num,
                :action_name,
                NOW(),
                :api_name,
                :response_result,
                :api_response,
                :api_request,
                :notification_type
            )
        ");
        $stmt->execute(array(
            ':shop' => isset($shop) ? $shop : '',
            ':items_id' => $log_order_id,
            ':items_name' => $log_order_name,
            ':customer_email' => isset($customer_email_id) ? $customer_email_id : '',
            ':country_code' => isset($clean_country_code) ? $clean_country_code : '',
            ':phone_num' => isset($clean_phone) ? $clean_phone : '',
            ':action_name' => 'Send SMS',
            ':api_name' => 'Send SMS API (With Parameters)',
            ':response_result' => $status,
            ':api_response' => isset($response) ? $response : '',
            ':api_request' => isset($jsonPayload) ? $jsonPayload : '',
            ':notification_type' => isset($notification_type) ? $notification_type : ''
        ));
        file_put_contents($logFile, "Database log inserted\n", FILE_APPEND);
    } catch (Exception $e) {
        file_put_contents($logFile, "DB ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    }

    file_put_contents($logFile, "========================================\n\n", FILE_APPEND);

    return $status === "success";
}
?>