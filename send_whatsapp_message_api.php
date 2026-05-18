<?php
function send_whatsapp_message($config, $pdo = null, $table2 = null)
{
    require_once __DIR__ . '/config/db.php';
    require_once __DIR__ . '/app_config.php';
    if ($pdo !== null) {
        $shop = isset($config['shop']) ? $config['shop'] : '';

        if (!empty($shop)) {
            global $prefix;
            $settings_table = $prefix . "shopify_sms_notification_app_API_Settings";
            try {
                $stmt = $pdo->prepare("SELECT * FROM $settings_table WHERE shop = :shop AND type = 'whatsapp' AND status = 'enabled'");
                $stmt->execute(array(':shop' => $shop));
                $whatsappSettings = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($whatsappSettings) {
                    $config['api_domain'] = isset($whatsappSettings['details']) ? $whatsappSettings['details'] : '';
                    $config['channel_id'] = isset($whatsappSettings['channel_id']) ? $whatsappSettings['channel_id'] : '';
                    $config['api_key'] = isset($whatsappSettings['apikey']) ? $whatsappSettings['apikey'] : '';
                }
            } catch (Exception $e) {
            }
        }
    }

    $api_domain = isset($config['api_domain']) ? rtrim($config['api_domain'], '/') : '';
    $channel_id = isset($config['channel_id']) ? $config['channel_id'] : '';
    $api_key = isset($config['api_key']) ? $config['api_key'] : '';
    $to = isset($config['to']) ? trim($config['to']) : '';
    $send_type = isset($config['send_type']) ? $config['send_type'] : 'template';

    $template_name = isset($config['template_name']) ? trim($config['template_name']) : '';
    $language_code = isset($config['language_code']) ? trim($config['language_code']) : 'en';

    $media_type = isset($config['media_type']) ? $config['media_type'] : 'text';
    $media_url = isset($config['media_url']) ? trim($config['media_url']) : '';
    $media_source = isset($config['media_source']) ? $config['media_source'] : 'url';

    $var_headers = isset($config['variable_headers']) && is_array($config['variable_headers']) ? $config['variable_headers'] : array();
    $var_body = isset($config['variable_body']) && is_array($config['variable_body']) ? $config['variable_body'] : array();

    $buttons = isset($config['buttons']) && is_array($config['buttons']) ? $config['buttons'] : array();

    $shop = isset($config['shop']) ? $config['shop'] : '';
    $order_id = isset($config['order_id']) ? $config['order_id'] : '';
    $order_name = isset($config['order_name']) ? $config['order_name'] : '';
    $customer_email = isset($config['customer_email']) ? $config['customer_email'] : '';
    $country_code = isset($config['country_code']) ? $config['country_code'] : '';
    $phone_num = isset($config['phone_num']) ? $config['phone_num'] : $to;
    $notification_type = isset($config['notification_type']) ? $config['notification_type'] : 'whatsapp';
    $log_file = isset($config['log_file']) ? $config['log_file'] : 'whatsapp_log.txt';

    if (empty($api_domain) || empty($channel_id) || empty($api_key)) {
        return _wa_send_response(false, 'Missing API configuration', null, null, null, $pdo, $table2, $shop, $order_id, $order_name, $customer_email, $country_code, $phone_num, $notification_type, $log_file);
    }

    if (empty($to)) {
        return _wa_send_response(false, 'Missing recipient phone number', null, null, null, $pdo, $table2, $shop, $order_id, $order_name, $customer_email, $country_code, $phone_num, $notification_type, $log_file);
    }

    $payload = null;
    $api_name = 'WhatsApp API';
    $action_name = 'Send WhatsApp';

    if ($send_type === 'media') {
        $payload = _wa_build_media_payload($to, $media_type, $media_url, $log_file);
        $api_name = 'WhatsApp Media API';
        $action_name = 'Send WhatsApp Media';
    } else {
        if (empty($template_name)) {
            return _wa_send_response(false, 'Missing template name', null, null, null, $pdo, $table2, $shop, $order_id, $order_name, $customer_email, $country_code, $phone_num, $notification_type, $log_file);
        }
        $payload = _wa_build_template_payload($to, $template_name, $language_code, $media_type, $media_url, $var_headers, $var_body, $buttons, $log_file);
    }

    if (!$payload) {
        return _wa_send_response(false, 'Failed to build payload', null, null, null, $pdo, $table2, $shop, $order_id, $order_name, $customer_email, $country_code, $phone_num, $notification_type, $log_file);
    }
    $api_url = $api_domain;
    $json_payload = json_encode($payload);

    _wa_log("FULL API URL: " . $api_url, $log_file);
    _wa_log("Request Payload: " . $json_payload, $log_file);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'X-API-KEY: ' . $api_key,
        'Content-Type: application/json'
    ));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    $success = false;
    $status_message = '';
    $api_response = '';

    if ($curl_error) {
        $status_message = 'CURL Error: ' . $curl_error;
        $api_response = $curl_error;
        $success = false;
    } else {
        $decoded_response = json_decode($response, true);
        $api_response = $response;

        if ($http_code >= 200 && $http_code < 300) {
            if (isset($decoded_response['errors']) && is_array($decoded_response['errors']) && count($decoded_response['errors']) > 0) {
               
                $error_details = '';
                foreach ($decoded_response['errors'] as $error) {
                    $error_code = isset($error['code']) ? $error['code'] : '';
                    $error_detail = isset($error['detail']) ? $error['detail'] : '';
                    $error_details .= ' [Code: ' . $error_code . '] ' . $error_detail;
                }
                $status_message = 'failed: ' . trim($error_details);
                $success = false;
            } elseif (isset($decoded_response['error'])) {
                
                $error_msg = isset($decoded_response['error']['message']) ? $decoded_response['error']['message'] : json_encode($decoded_response['error']);
                $status_message = 'failed: ' . $error_msg;
                $success = false;
            } else {
                
                $status_message = 'success';
                $success = true;
            }
        } else {
            
            $error_msg = isset($decoded_response['error']['message']) ? $decoded_response['error']['message'] : 'HTTP Error: ' . $http_code;
            $status_message = 'failed: ' . $error_msg;
            $success = false;
        }
    }

    _wa_log("API URL: $api_url", $log_file);
    _wa_log("Request: " . $json_payload, $log_file);
    _wa_log("Response: " . $response, $log_file);
    _wa_log("HTTP Code: $http_code", $log_file);
    _wa_log("Success: " . ($success ? 'YES' : 'NO'), $log_file);
    _wa_log("Status Message: " . $status_message, $log_file);
    _wa_log("-----------------------------------", $log_file);

    return _wa_send_response($success, $status_message, $json_payload, $api_response, $http_code, $pdo, $table2, $shop, $order_id, $order_name, $customer_email, $country_code, $phone_num, $notification_type, $log_file, $api_name, $action_name);
}
function _wa_build_media_payload($to, $media_type, $media_url, $log_file)
{
    if (empty($media_url)) {
        _wa_log("Media message requires media_url", $log_file);
        return null;
    }

    $wa_type_map = array(
        'image' => 'image',
        'video' => 'video',
        'pdf' => 'document',
        'document' => 'document',
    );

    $wa_media_type = isset($wa_type_map[$media_type]) ? $wa_type_map[$media_type] : 'image';

    return array(
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $to,
        'type' => $wa_media_type,
        $wa_media_type => array('link' => $media_url),
    );
}

function _wa_build_template_payload($to, $template_name, $language_code, $media_type, $media_url, $var_headers, $var_body, $buttons, $log_file)
{
    $has_headers = is_array($var_headers) && count($var_headers) > 0;
    $has_body = is_array($var_body) && count($var_body) > 0;
    $has_media = ($media_type !== 'text' && !empty($media_url));
    $has_buttons = is_array($buttons) && count($buttons) > 0;

    if (!$has_headers && !$has_body && !$has_media && !$has_buttons) {
        _wa_log("Building SIMPLE template (no parameters)", $log_file);
        return array(
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => array(
                'name' => $template_name,
                'language' => array('code' => $language_code)
            )
        );
    }

    $wa_type_map = array(
        'image' => 'image',
        'video' => 'video',
        'pdf' => 'document',
        'document' => 'document',
    );

    $components = array();

    if ($has_media) {
        $wa_media_type = isset($wa_type_map[$media_type]) ? $wa_type_map[$media_type] : 'image';
        $components[] = array(
            'type' => 'header',
            'parameters' => array(
                array(
                    'type' => $wa_media_type,
                    $wa_media_type => array('link' => $media_url)
                )
            )
        );
        _wa_log("Added MEDIA header: $media_type", $log_file);
    } elseif ($has_headers) {
        $header_params = _wa_build_text_params($var_headers);
        if (!empty($header_params)) {
            $components[] = array(
                'type' => 'header',
                'parameters' => $header_params
            );
            _wa_log("Added TEXT header with " . count($header_params) . " parameter(s)", $log_file);
        }
    }

    if ($has_body) {
        $body_params = _wa_build_text_params($var_body);
        if (!empty($body_params)) {
            $components[] = array(
                'type' => 'body',
                'parameters' => $body_params
            );
            _wa_log("Added BODY with " . count($body_params) . " parameter(s)", $log_file);
        }
    }
    if ($has_buttons) {
        foreach ($buttons as $btn) {
            $sub_type = isset($btn['sub_type']) ? $btn['sub_type'] : '';
            $index = isset($btn['index']) ? (string) $btn['index'] : '0';
            $value = isset($btn['value']) ? $btn['value'] : '';
            $button_text = isset($btn['button_text']) ? $btn['button_text'] : '';
            if ($sub_type === 'url') {
                $components[] = array(
                    'type' => 'button',
                    'sub_type' => 'url',
                    'index' => $index,
                    'parameters' => array(
                        array('type' => 'text', 'text' => $value)
                    )
                );
                _wa_log("Added URL button at index $index: $value", $log_file);
            } elseif ($sub_type === 'quick_reply') {
                $components[] = array(
                    'type' => 'button',
                    'sub_type' => 'quick_reply',
                    'index' => $index,
                    'parameters' => array(
                        array('type' => 'payload', 'payload' => $value)
                    )
                );
                _wa_log("Added Quick Reply button at index $index: $value", $log_file);
            } elseif ($sub_type === 'phone_number') {
                $components[] = array(
                    'type' => 'button',
                    'sub_type' => 'phone_number',
                    'index' => $index,
                    'parameters' => array(
                        array('type' => 'phone_number', 'phone_number' => $value)
                    )
                );
                _wa_log("Added Phone Number button at index $index: $value", $log_file);
            }
        }
    }

    $template = array(
        'name' => $template_name,
        'language' => array('code' => $language_code)
    );

    if (!empty($components)) {
        $template['components'] = $components;
    }

    return array(
        'messaging_product' => 'whatsapp',
        'recipient_type' => 'individual',
        'to' => $to,
        'type' => 'template',
        'template' => $template
    );
}
function _wa_build_text_params($values)
{
    $params = array();
    foreach ($values as $v) {
        $v = trim((string) $v);
        if ($v !== '') {
            $params[] = array('type' => 'text', 'text' => $v);
        }
    }
    return $params;
}

function _wa_send_response($success, $message, $api_request, $api_response, $http_code, $pdo, $table2, $shop, $order_id, $order_name, $customer_email, $country_code, $phone_num, $notification_type, $log_file, $api_name = 'WhatsApp API', $action_name = 'Send WhatsApp')
{
    $result = array(
        'success' => $success,
        'message' => $message,
        'api_request' => $api_request,
        'api_response' => $api_response,
        'http_code' => $http_code
    );

    if ($pdo !== null && $table2 !== null) {
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

            $response_result = $success ? 'success' : 'failed: ' . $message;

            $stmt->execute(array(
                ':shop' => $shop,
                ':items_id' => $log_order_id,
                ':items_name' => $log_order_name,
                ':customer_email' => $customer_email,
                ':country_code' => $country_code,
                ':phone_num' => $phone_num,
                ':action_name' => $action_name,
                ':api_name' => $api_name,
                ':response_result' => $response_result,
                ':api_response' => $api_response,
                ':api_request' => $api_request,
                ':notification_type' => $notification_type
            ));

            _wa_log("Database log inserted successfully", $log_file);
        } catch (Exception $e) {
            _wa_log("DB ERROR: " . $e->getMessage(), $log_file);
        }
    }

    _wa_log("========================================\n", $log_file);

    return $result;
}

function _wa_log($message, $log_file)
{
    //$log_entry = date('Y-m-d H:i:s') . " - " . $message . "\n";
    //file_put_contents($log_file, $log_entry, FILE_APPEND);
}

function wa_replace_placeholders($text, $replacements)
{
    if (empty($text) || empty($replacements)) {
        return $text;
    }

    $search = array();
    $replace = array();

    foreach ($replacements as $key => $value) {
        $search[] = '{{ ' . $key . ' }}';
        $search[] = '{{' . $key . '}}';
        $replace[] = $value;
        $replace[] = $value;
    }

    return str_replace($search, $replace, $text);
}