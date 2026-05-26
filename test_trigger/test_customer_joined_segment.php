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
    $stmt = $pdo->prepare("SELECT sms, sms_variables, template_id_sms, whatsapp, whatsapp_enabled, sms_enabled FROM $table WHERE id = :segment_id AND shop = :shop");
    $stmt->execute(array(':segment_id' => $segmentId, ':shop' => $shop));
    $segmentData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$segmentData) {
        echo json_encode(array('success' => false, 'message' => 'No segment found'));
        exit;
    }

    $cleanPhone = ltrim($phone, '0');
    $cleanCountryCode = str_replace('+', '', $countryCode);

    $cleanPhone = preg_replace('/[^0-9]/', '', $cleanPhone);
    if (substr($cleanPhone, 0, 1) === '0') {
        $cleanPhone = substr($cleanPhone, 1);
    }

    // Get enabled statuses
    $sms_enabled = isset($segmentData['sms_enabled']) ? (int) $segmentData['sms_enabled'] : 0;
    $whatsapp_enabled = isset($segmentData['whatsapp_enabled']) ? (int) $segmentData['whatsapp_enabled'] : 0;

    $response = array('success' => false, 'message' => 'No channel enabled');
    $sms_sent = false;
    $whatsapp_sent = false;
    $has_parameters = false;

    // ============ SEND SMS IF ENABLED ============
    if ($sms_enabled == 1) {
        if (empty($segmentData['template_id_sms'])) {
            error_log("SMS enabled but no template_id_sms found for segment: $segmentId");
        } else {
            $template_name_sms = $segmentData['template_id_sms'];
            $sms_text = isset($segmentData['sms']) ? $segmentData['sms'] : '';

            // Define test values for placeholders
            $testValues = array(
                'order_name' => '#TEST-12345',
                'order_total_price' => '99.99',
                'customer_email_id' => 'john.doe@example.com',
                'country_code' => $cleanCountryCode,
                'customer_phone' => $cleanPhone,
                'customer_full_name' => 'John Doe',
                'customer_fname' => 'John',
                'customer_lname' => 'Doe',
                'segment_name' => 'Test Segment',
                'customer_address' => '123 Test Street',
                'customer_city' => 'New York',
                'customer_zip' => '10001'
            );

            // Check if SMS has parameters (sms_variables is not empty)
            $smsVariables = array();
            $has_parameters = false;

            if (!empty($segmentData['sms_variables'])) {
                $smsVariables = json_decode($segmentData['sms_variables'], true);
                if (is_array($smsVariables) && count($smsVariables) > 0) {
                    $has_parameters = true;
                    error_log("SMS has parameters - will use template-based SMS");
                } else {
                    error_log("SMS has NO parameters - will use plain text SMS");
                }
            } else {
                error_log("SMS has NO parameters - will use plain text SMS");
            }

            // Build replacement map for variables
            $replacementMap = array(
                '{{ order_name }}' => $testValues['order_name'],
                '{{ order_total_price }}' => $testValues['order_total_price'],
                '{{ customer_email_id }}' => $testValues['customer_email_id'],
                '{{ country_code }}' => $testValues['country_code'],
                '{{ customer_phone }}' => $testValues['customer_phone'],
                '{{ customer_full_name }}' => $testValues['customer_full_name'],
                '{{ customer_fname }}' => $testValues['customer_fname'],
                '{{ customer_lname }}' => $testValues['customer_lname'],
                '{{ segment_name }}' => $testValues['segment_name'],
                '{{ customer_address }}' => $testValues['customer_address'],
                '{{ customer_city }}' => $testValues['customer_city'],
                '{{ customer_zip }}' => $testValues['customer_zip']
            );

            if ($has_parameters) {
                // PARAMETER-BASED SMS (Template SMS)
                error_log("Using TEMPLATE-BASED SMS with parameters");

                $parameter_values = array();
                foreach ($smsVariables as $key => $value) {
                    $processed_value = $value;
                    foreach ($replacementMap as $placeholder => $val) {
                        $processed_value = str_replace($placeholder, $val, $processed_value);
                    }
                    $parameter_values[$key] = $processed_value;
                }

                if (!empty($cleanPhone) && !empty($template_name_sms) && !empty($parameter_values)) {
                    if (function_exists('send_smstext_with_parameters')) {
                        send_smstext_with_parameters(
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
                        $sms_sent = true;
                        error_log("Template SMS sent successfully for segment test to {$cleanPhone}");
                    } else {
                        error_log("ERROR: send_smstext_with_parameters function not found");
                    }
                } else {
                    error_log("Missing required data to send template SMS");
                }
            } else {
                // PLAIN TEXT SMS (No parameters)
                error_log("Using PLAIN TEXT SMS (no parameters)");

                if (empty($template_name_sms)) {
                    error_log("ERROR: SMS template name is empty for plain text SMS");
                } else {
                    // Replace placeholders in SMS text
                    $processed_sms_text = $sms_text;
                    foreach ($replacementMap as $placeholder => $val) {
                        $processed_sms_text = str_replace($placeholder, $val, $processed_sms_text);
                    }

                    error_log("Processed SMS text: " . $processed_sms_text);

                    if (!empty($cleanPhone) && !empty($template_name_sms)) {
                        if (function_exists('send_smstext')) {
                            send_smstext(
                                $cleanCountryCode,
                                $cleanPhone,
                                $template_name_sms,
                                $shop,
                                'john.doe@example.com',
                                'John Doe',
                                'TEST-12345',
                                'Test Order',
                                $notification_type
                            );
                            $sms_sent = true;
                            error_log("Plain text SMS sent successfully for segment test to {$cleanPhone}");
                        } else {
                            error_log("ERROR: send_smstext function not found");
                        }
                    } else {
                        error_log("Missing required data to send plain text SMS");
                    }
                }
            }
        }
    } else {
        error_log("SMS is disabled for this segment (sms_enabled=$sms_enabled)");
    }

    // ============ SEND WHATSAPP IF ENABLED ============
    if ($whatsapp_enabled == 1) {
        $whatsappData = array();
        $whatsappTemplateName = '';

        if (!empty($segmentData['whatsapp'])) {
            $whatsappData = json_decode($segmentData['whatsapp'], true);
            if (is_array($whatsappData)) {
                $whatsappTemplateName = isset($whatsappData['template_name']) ? $whatsappData['template_name'] : '';
                error_log("WhatsApp template name from DB: {$whatsappTemplateName}");
            }
        }

        if (!empty($whatsappTemplateName)) {
            error_log("Processing WhatsApp for customer segment test - Template: $whatsappTemplateName");

            $whatsappApiConfig = array(
                'log_file' =>  __DIR__ . '/../file/debug_log.txt'
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

            $customer_phone = $cleanCountryCode . $cleanPhone;
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
                'order_name' => '#TEST-12345',
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

                    if (isset($whatsappResult['success']) && $whatsappResult['success']) {
                        $whatsapp_sent = true;
                        error_log("WhatsApp sent successfully for customer segment test to {$cleanPhone}");
                    } else {
                        $error_msg = isset($whatsappResult['message']) ? $whatsappResult['message'] : 'Unknown error';
                        error_log("WhatsApp failed for customer segment test: " . $error_msg);
                    }
                } else {
                    error_log("WhatsApp function send_whatsapp_message not found");
                }
            }
        } else {
            error_log("No WhatsApp template configured for this segment, skipping WhatsApp send");
        }
    } else {
        error_log("WhatsApp is disabled for this segment (whatsapp_enabled=$whatsapp_enabled)");
    }

    // Prepare response based on what was sent
    if ($sms_sent && $whatsapp_sent) {
        $response = array('success' => true, 'message' => 'SMS and WhatsApp sent successfully');
    } elseif ($sms_sent) {
        $response = array('success' => true, 'message' => 'SMS sent successfully');
    } elseif ($whatsapp_sent) {
        $response = array('success' => true, 'message' => 'WhatsApp sent successfully');
    } else {
        $response = array('success' => false, 'message' => 'No channel enabled. Please enable SMS or WhatsApp template first.');
    }

    echo json_encode($response);

} catch (Exception $e) {
    echo json_encode(array('success' => false, 'message' => 'Error: ' . $e->getMessage()));
}
?>