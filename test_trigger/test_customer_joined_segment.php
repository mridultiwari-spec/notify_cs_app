<?php
require_once __DIR__ . '/../config/db.php';
include __DIR__ . '/../accurate_country_code.php';
include __DIR__ . '/../send_sms_api.php';
require_once '../app_config.php';
require '../send_whatsapp_message_api.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);
$notification_type = "Customer Segment";

$segmentId = isset($data['segment_id']) ? $data['segment_id'] : '';
$countryCode = isset($data['country_code']) ? $data['country_code'] : '';
$phone = isset($data['phone']) ? $data['phone'] : '';
$shop = isset($data['shop']) ? $data['shop'] : '';

if (!$segmentId || !$countryCode || !$phone) {
    echo json_encode(array('success' => false, 'message' => 'Missing required parameters'));
    exit;
}

if (!$shop) {
    session_start();
    $shop = isset($_SESSION['shop']) ? $_SESSION['shop'] : '';
}

if (!$shop) {
    echo json_encode(array('success' => false, 'message' => 'Shop not found'));
    exit;
}
session_write_close();
try {
    $pdo = getDatabaseConnection();
    $table = "$prefix" . "customer_segment";
    //$stmt = $pdo->prepare("SELECT sms, sms_variables, template_id_sms FROM $table WHERE id = :segment_id AND shop = :shop");
    $stmt = $pdo->prepare("SELECT sms, sms_variables, template_id_sms, whatsapp_enabled FROM $table WHERE id = :segment_id AND shop = :shop");
    $stmt->execute(array(':segment_id' => $segmentId, ':shop' => $shop));
    $segmentData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$segmentData || empty($segmentData['template_id_sms'])) {
        echo json_encode(array('success' => false, 'message' => 'No SMS template found for this segment'));
        exit;
    }

    $cleanPhone = ltrim($phone, '0');
    $cleanCountryCode = str_replace('+', '', $countryCode);

    // if ($cleanCountryCode && $cleanPhone) {
    //     $final_arr = getCountryCode_and_phone_number($cleanCountryCode, $cleanPhone);
    //     $cleanCountryCode = $final_arr['country_code'];
    //     $cleanPhone = $final_arr['phone_number'];
    // }

    $cleanPhone = preg_replace('/[^0-9]/', '', $cleanPhone);
    if (substr($cleanPhone, 0, 1) === '0') {
        $cleanPhone = substr($cleanPhone, 1);
    }

    $template_name_sms = $segmentData['template_id_sms'];

    $actualValues = array(
        'order_name' => '#TEST-12345',
        'order_total_price' => '99.99',
        'customer_email_id' => 'john.doe@example.com',
        'country_code' => $cleanCountryCode,
        'customer_phone' => $cleanPhone,
        'customer_name' => 'John Doe',
        'customer_fname' => 'John',
        'customer_lname' => 'Doe',
        'segment_name' => 'Test Segment',
        'customer_address' => '123 Test Street',
        'customer_city' => 'New York',
        'customer_zip' => '10001'
    );

    $smsVariables = json_decode($segmentData['sms_variables'], true);

    $parameter_values = array();

    if (!empty($smsVariables) && is_array($smsVariables)) {
        foreach ($smsVariables as $key => $dbValue) {
            $variableName = trim($key, '{} ');
            if (isset($actualValues[$variableName])) {
                $parameter_values[$key] = $actualValues[$variableName];
            } else {
                $dbValueClean = trim($dbValue, '{} ');
                if (isset($actualValues[$dbValueClean])) {
                    $parameter_values[$key] = $actualValues[$dbValueClean];
                } else {
                    $parameter_values[$key] = $dbValue;
                }
            }
        }
    } else {
        $parameter_values = array(
            "order_name" => '#TEST-12345',
            "order_total_price" => '99.99',
            "customer_email_id" => 'john.doe@example.com',
            "country_code" => $cleanCountryCode,
            "customer_phone" => $cleanPhone,
            "customer_fname" => 'John',
            "customer_lname" => 'Doe'
        );
    }

    if (empty($parameter_values)) {
        $parameter_values = array(
            "order_name" => '#TEST-12345',
            "order_total_price" => '99.99',
            "customer_email_id" => 'john.doe@example.com',
            "country_code" => $cleanCountryCode,
            "customer_phone" => $cleanCountryCode . $cleanPhone
        );
    }

    if (!empty($cleanPhone) && !empty($template_name_sms) && !empty($parameter_values)) {
        $result = send_smstext_with_parameters(
            $cleanCountryCode,
            $cleanPhone,
            $template_name_sms,
            $shop,
            'john.doe@example.com',
            'John Doe',
            $parameter_values,
            'TEST-12345',
            'Test Order',
            $notification_type
        );

        echo json_encode(array(
            'success' => true,
            'message' => 'Test message sent successfully',
            'preview' => array(
                'template_name' => $template_name_sms,
                'phone' => $cleanCountryCode . $cleanPhone,
                'parameter_values' => $parameter_values,
                'variables_from_db' => $smsVariables
            )
        ));
    } else {
        echo json_encode(array(
            'success' => false,
            'message' => 'Missing required data to send SMS',
            'debug' => array(
                'phone' => $cleanPhone,
                'template' => $template_name_sms,
                'param_count' => count($parameter_values),
                'params' => $parameter_values
            )
        ));
    }

} catch (Exception $e) {
    echo json_encode(array('success' => false, 'message' => 'Error: ' . $e->getMessage()));
}
$whatsapp_enabled = isset($segmentData['whatsapp_enabled']) ? (int) $segmentData['whatsapp_enabled'] : 0;
$stmt_wa = $pdo->prepare("SELECT whatsapp FROM $table WHERE id = :segment_id AND shop = :shop");
$stmt_wa->execute(array(':segment_id' => $segmentId, ':shop' => $shop));
$waRow = $stmt_wa->fetch(PDO::FETCH_ASSOC);

$whatsappData = array();
$whatsappTemplateName = '';

if ($waRow && !empty($waRow['whatsapp'])) {
    $whatsappData = json_decode($waRow['whatsapp'], true);
    if (is_array($whatsappData)) {
        $whatsappTemplateName = isset($whatsappData['template_name']) ? $whatsappData['template_name'] : '';
        error_log("WhatsApp template name from DB: {$whatsappTemplateName}");
    }
}

if ($whatsapp_enabled == 1 && !empty($whatsappTemplateName)) {
    error_log("Processing WhatsApp for customer segment test - Template: $whatsappTemplateName");

    // $whatsappApiConfig = array(
    //     'api_domain' => 'https://your-api-domain.com', 
    //     'channel_id' => 'your_channel_id',
    //     'api_key' => 'your_api_key',
    //     'log_file' => 'whatsapp_log.txt'
    // );
    $whatsappApiConfig = array(
        'log_file' => 'whatsapp_log.txt'
    );

    $whatsappTable2 = $prefix . "shopify_sms_notification_App_Log_Details";

    $whatsappMediaType = isset($whatsappData['media_type']) ? $whatsappData['media_type'] : 'text';
    $whatsappMediaUrl = isset($whatsappData['media_url']) ? $whatsappData['media_url'] : '';
    $whatsappMediaSource = isset($whatsappData['media_source']) ? $whatsappData['media_source'] : '';

    $replacementMap = array(
        'order_name' => '#TEST-12345',
        'order_total_price' => '99.99',
        'customer_email_id' => 'john.doe@example.com',
        'country_code' => $cleanCountryCode,
        'customer_phone' => $cleanPhone,
        'customer_name' => 'John Doe',
        'customer_fname' => 'John',
        'customer_lname' => 'Doe',
        'customer_full_name' => 'John Doe',
        'segment_name' => 'Test Segment',
        'customer_address' => '123 Test Street',
        'customer_city' => 'New York',
        'customer_zip' => '10001',
        'phone_number' => $cleanPhone
    );
    $processedHeaders = array();
    $headerVariables = isset($whatsappData['variable_headers']) ? $whatsappData['variable_headers'] : array();
    if (!empty($headerVariables)) {
        foreach ($headerVariables as $headerVar) {
            $processedValue = wa_replace_placeholders($headerVar, $replacementMap);
            $processedHeaders[] = $processedValue;
            error_log("WhatsApp Header variable: '$headerVar' -> '$processedValue'");
        }
    }

    $processedBody = array();
    $bodyVariables = isset($whatsappData['variable_body']) ? $whatsappData['variable_body'] : array();

    if (!empty($bodyVariables)) {
        foreach ($bodyVariables as $bodyVar) {
            $processedValue = wa_replace_placeholders($bodyVar, $replacementMap);
            $processedBody[] = $processedValue;
            error_log("WhatsApp Body variable: '$bodyVar' -> '$processedValue'");
        }
    }

    if (!empty($whatsappMediaUrl)) {
        $whatsappMediaUrl = wa_replace_placeholders($whatsappMediaUrl, $replacementMap);
        error_log("WhatsApp Media URL after replacement: $whatsappMediaUrl");
    }

    $buttons = array();

    $whatsappCtaUrls = isset($whatsappData['cta_url']) ? $whatsappData['cta_url'] : array();
    if (!is_array($whatsappCtaUrls)) {
        $whatsappCtaUrls = array();
    }

    $btn1Type = isset($whatsappData['button_type']) ? $whatsappData['button_type'] : '';
    $btn1Text = isset($whatsappData['button_text1_type']) ? trim($whatsappData['button_text1_type']) : '';
    $btn1Url = isset($whatsappCtaUrls['button1']) ? $whatsappCtaUrls['button1'] : '';

    if ($btn1Type !== 'none' && !empty($btn1Text)) {
        $btn1Text = wa_replace_placeholders($btn1Text, $replacementMap);
        $btn1Url = wa_replace_placeholders($btn1Url, $replacementMap);

        $buttons[] = array(
            'sub_type' => ($btn1Type === 'cta') ? 'url' : 'quick_reply',
            'index' => '0',
            'value' => ($btn1Type === 'cta') ? $btn1Url : $btn1Text,
            'button_text' => $btn1Text
        );
        error_log("WhatsApp Button 1: type=$btn1Type, text=$btn1Text");
    }

    $btn2Type = isset($whatsappData['button_type2']) ? $whatsappData['button_type2'] : '';
    $btn2Text = isset($whatsappData['button_text2_type']) ? trim($whatsappData['button_text2_type']) : '';
    $btn2Url = isset($whatsappCtaUrls['button2']) ? $whatsappCtaUrls['button2'] : '';

    if ($btn2Type !== 'none' && !empty($btn2Text)) {
        $btn2Text = wa_replace_placeholders($btn2Text, $replacementMap);
        $btn2Url = wa_replace_placeholders($btn2Url, $replacementMap);

        $buttons[] = array(
            'sub_type' => ($btn2Type === 'cta') ? 'url' : 'quick_reply',
            'index' => '1',
            'value' => ($btn2Type === 'cta') ? $btn2Url : $btn2Text,
            'button_text' => $btn2Text
        );
        error_log("WhatsApp Button 2: type=$btn2Type, text=$btn2Text");
    }

    $btn3Type = isset($whatsappData['button_type3']) ? $whatsappData['button_type3'] : '';
    $btn3Text = isset($whatsappData['button_text3_type']) ? trim($whatsappData['button_text3_type']) : '';
    $btn3Url = isset($whatsappCtaUrls['button3']) ? $whatsappCtaUrls['button3'] : '';

    if ($btn3Type !== 'none' && !empty($btn3Text)) {
        $btn3Text = wa_replace_placeholders($btn3Text, $replacementMap);
        $btn3Url = wa_replace_placeholders($btn3Url, $replacementMap);

        $buttons[] = array(
            'sub_type' => ($btn3Type === 'cta') ? 'url' : 'quick_reply',
            'index' => '2',
            'value' => ($btn3Type === 'cta') ? $btn3Url : $btn3Text,
            'button_text' => $btn3Text
        );
        error_log("WhatsApp Button 3: type=$btn3Type, text=$btn3Text");
    }
    $customer_phone = "$cleanCountryCode" . "$cleanPhone";
    $whatsappConfig = array_merge($whatsappApiConfig, array(
        'to' => $customer_phone,
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
        'order_name' => '',
        'customer_email' => 'john.doe@example.com',
        'country_code' => $cleanCountryCode,
        'phone_num' => $cleanPhone,
        'notification_type' => $notification_type
    ));

    if (empty($cleanPhone)) {
        error_log("ERROR: Customer phone is empty, cannot send WhatsApp for segment test");
    } else {
        if (function_exists('send_whatsapp_message')) {
            $whatsappResult = send_whatsapp_message($whatsappConfig, $pdo, $whatsappTable2);

            if ($whatsappResult['success']) {
                error_log("WhatsApp sent successfully for customer segment test to {$cleanPhone}");
            } else {
                error_log("WhatsApp failed for customer segment test: " . $whatsappResult['message']);
            }
        } else {
            error_log("WhatsApp function send_whatsapp_message not found");
        }
    }
} else {
    error_log("No WhatsApp template configured for this segment, skipping WhatsApp send");
}
?>