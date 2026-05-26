<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/app_config.php';
include_once __DIR__ . '/send_sms_api.php';
include_once __DIR__ . '/send_whatsapp_message_api.php';
include_once __DIR__ . '/accurate_country_code.php';

ini_set('max_execution_time', 0);
set_time_limit(0);
ignore_user_abort(true);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../file/debug_log.txt');
function writeBulkLog($message, $type = 'INFO')
{
    $logFile = __DIR__ . '/../file/debug_log.txt';
    //$timestamp = date('Y-m-d H:i:s');
    //$logEntry = "[{$timestamp}] [BULK-SCHEDULE] [{$type}] {$message}" . PHP_EOL;
   //@file_put_contents($logFile, $logEntry, FILE_APPEND);
    error_log("[BULK-SCHEDULE] {$message}");
}

writeBulkLog("========== BULK SCHEDULE STARTED ==========");

try {
    $pdo = getDatabaseConnection();
    writeBulkLog("Database connection successful");
    $customerSegmentTable = $prefix . "customer_segment";
    $segmentCustomersInfoTable = $prefix . "segment_customers_info";
    $configTable = $prefix . "shopify_sms_notification_app";
    $logTable = $prefix . "shopify_sms_notification_App_Log_Details";
    
    $stmt = $pdo->prepare("
        SELECT 
            cs.id,
            cs.segment_id,
            cs.shop,
            cs.conditions,
            cs.sms_enabled,
            cs.sms,
            cs.sms_variables,
            cs.template_id_sms,
            cs.whatsapp,
            cs.whatsapp_enabled
        FROM $customerSegmentTable cs
        WHERE cs.conditions LIKE :conditions
    ");
    $stmt->execute(array(':conditions' => '%"type":"Bulk Schedule"%'));
    $segments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($segments)) {
        writeBulkLog("No segments found with Bulk Schedule condition");
        exit("No bulk schedule segments found");
    }
    
    writeBulkLog("Found " . count($segments) . " bulk schedule segment(s)");
    
    $totalSmsSent = 0;
    $totalWhatsappSent = 0;
    
    foreach ($segments as $segment) {
        $segmentDbId = $segment['id'];
        $segmentGid = $segment['segment_id'];
        $shop = $segment['shop'];
        $sms_enabled = isset($segment['sms_enabled']) ? (int) $segment['sms_enabled'] : 0;
        $whatsapp_enabled = isset($segment['whatsapp_enabled']) ? (int) $segment['whatsapp_enabled'] : 0;
        $template_name_sms = isset($segment['template_id_sms']) ? $segment['template_id_sms'] : '';
        $whatsappData = array();
        
        if (!empty($segment['whatsapp'])) {
            $whatsappData = json_decode($segment['whatsapp'], true);
            if (!is_array($whatsappData)) {
                $whatsappData = array();
            }
        }
        
        $smsVariables = array();
        if (!empty($segment['sms_variables'])) {
            $smsVariables = json_decode($segment['sms_variables'], true);
            if (!is_array($smsVariables)) {
                $smsVariables = array();
            }
        }
        
        writeBulkLog("Processing Segment ID: {$segmentDbId}, Shop: {$shop}");
        writeBulkLog("SMS enabled: {$sms_enabled}, WhatsApp enabled: {$whatsapp_enabled}");
        
        if ($sms_enabled != 1 && $whatsapp_enabled != 1) {
            writeBulkLog("Both SMS and WhatsApp disabled for this segment. Skipping.");
            continue;
        }
        
        $stmt = $pdo->prepare("SELECT session_access_token AS oauth_token FROM $configTable WHERE shop = :shop");
        $stmt->execute(array(':shop' => $shop));
        $shopData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$shopData) {
            writeBulkLog("Shop not found in config: {$shop}", "ERROR");
            continue;
        }
        
        $accessToken = $shopData['oauth_token'];
        
        $stmt = $pdo->prepare("
            SELECT * FROM $segmentCustomersInfoTable 
            WHERE segment_id = :segment_id
        ");
        $stmt->execute(array(':segment_id' => $segmentGid));
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($customers)) {
            writeBulkLog("No customers found in segment {$segmentDbId}");
            continue;
        }
        
        writeBulkLog("Found " . count($customers) . " customers in segment {$segmentDbId}");
        
        $segmentSmsSent = 0;
        $segmentWhatsappSent = 0;
        
        foreach ($customers as $customer) {
            $customerFirstName = isset($customer['first_name']) ? $customer['first_name'] : '';
            $customerLastName = isset($customer['last_name']) ? $customer['last_name'] : '';
            $customerFullName = trim($customerFirstName . ' ' . $customerLastName);
            $customerEmail = isset($customer['email']) ? $customer['email'] : '';
            $phoneNumber = isset($customer['phone']) ? $customer['phone'] : '';
            $countryCode = isset($customer['country_code']) ? $customer['country_code'] : '';
            $shopifyCustomerId = isset($customer['shopify_customer_id']) ? $customer['shopify_customer_id'] : '';
            
            if (empty($phoneNumber)) {
                writeBulkLog("Skipping customer {$customerFullName} - No phone number");
                continue;
            }
            
            if (!empty($countryCode) && strlen($countryCode) == 2) {
                $countryCodeUpper = strtoupper($countryCode);
                if (isset($ccodes[$countryCodeUpper])) {
                    $countryCode = $ccodes[$countryCodeUpper];
                }
            }
            
            if (substr($phoneNumber, 0, 1) === '0') {
                $phoneNumber = substr($phoneNumber, 1);
            }
            
            if ($sms_enabled == 1 && !empty($template_name_sms)) {
                writeBulkLog("Sending SMS to {$customerFullName} at {$phoneNumber}");
                
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
                    $segmentSmsSent++;
                    $totalSmsSent++;
                    writeBulkLog("SMS sent successfully to {$customerFullName}");
                } else {
                    writeBulkLog("ERROR: send_smstext_with_parameters function not found", "ERROR");
                }
                
                usleep(200000);
            }
            if ($whatsapp_enabled == 1 && !empty($whatsappData)) {
                $whatsappTemplateName = isset($whatsappData['template_name']) ? $whatsappData['template_name'] : '';
                
                if (!empty($whatsappTemplateName)) {
                    writeBulkLog("Sending WhatsApp to {$customerFullName} at {$phoneNumber}");
                    
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
                        'email_id' => $customerEmail
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
                    
                    // Process buttons
                    $buttons = array();
                    $whatsappCtaUrls = isset($whatsappData['cta_url']) ? $whatsappData['cta_url'] : array();
                    if (!is_array($whatsappCtaUrls)) {
                        $whatsappCtaUrls = array();
                    }
                    
                    // Button 1
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
                    
                    // Button 2
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
                    
                    // Button 3
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
                    
                    if (function_exists('send_whatsapp_message')) {
                        $whatsappResult = send_whatsapp_message($whatsappConfig, $pdo, $logTable);
                        if (isset($whatsappResult['success']) && $whatsappResult['success']) {
                            $segmentWhatsappSent++;
                            $totalWhatsappSent++;
                            writeBulkLog("WhatsApp sent successfully to {$customerFullName}");
                        } else {
                            $errorMsg = isset($whatsappResult['message']) ? $whatsappResult['message'] : 'Unknown error';
                            writeBulkLog("WhatsApp failed for {$customerFullName}: {$errorMsg}", "ERROR");
                        }
                    } else {
                        writeBulkLog("ERROR: send_whatsapp_message function not found", "ERROR");
                    }
                    usleep(200000);
                }
            }
        }
        
        writeBulkLog("Segment {$segmentDbId} completed - SMS: {$segmentSmsSent}, WhatsApp: {$segmentWhatsappSent}");
    }
    
    writeBulkLog("========== BULK SCHEDULE COMPLETED ==========");
    writeBulkLog("Total - SMS Sent: {$totalSmsSent}, WhatsApp Sent: {$totalWhatsappSent}");
    
    echo "Bulk schedule completed. SMS: {$totalSmsSent}, WhatsApp: {$totalWhatsappSent}";
    
} catch (Exception $e) {
    writeBulkLog("ERROR: Exception occurred - " . $e->getMessage(), "ERROR");
    writeBulkLog("Stack trace: " . $e->getTraceAsString(), "ERROR");
    echo "Error: " . $e->getMessage();
}
?>