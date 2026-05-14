<?php
require_once __DIR__ . '/app_config.php';
require_once __DIR__ . '/config/db.php';
include_once __DIR__ . '/accurate_country_code.php';
include_once __DIR__ . '/send_sms_api.php';
include_once __DIR__ . '/send_whatsapp_message_api.php';

function processAbandonedCheckoutNotifications($shop, $oauth_token)
{
    global $pdo, $prefix, $logFile;
    $pdo = getDatabaseConnection();
    $checkout_table = $prefix . "abandoned_checkouts";
    $notification_table = $prefix . "shopify_sms_notification_App_Email_Notification";
    $log_table = $prefix . "shopify_sms_notification_App_Log_Details";
    
    createAbandonedCheckoutsTable($pdo, $checkout_table);
    
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - Starting abandoned checkout notification processing for shop: $shop\n", FILE_APPEND);
    
    $stmt = $pdo->prepare("SELECT * FROM $notification_table WHERE aid = :aid AND shop = :shop");
    $aid = 5;
    $stmt->execute(array(':aid' => $aid, ':shop' => $shop));
    $notification_config = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$notification_config) {
        file_put_contents($logFile, "WARNING: No notification template found for aid=$aid and shop=$shop\n", FILE_APPEND);
        return array('processed' => 0, 'sms_sent' => 0, 'whatsapp_sent' => 0);
    }
   
    $sql = "SELECT * FROM $checkout_table WHERE shop = :shop AND status = 0 ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array(':shop' => $shop));
    $checkouts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($checkouts)) {
        file_put_contents($logFile, "No pending abandoned checkouts for shop: $shop\n", FILE_APPEND);
        return array('processed' => 0, 'sms_sent' => 0, 'whatsapp_sent' => 0);
    }
    
    $processed_count = 0;
    $sms_sent_count = 0;
    $whatsapp_sent_count = 0;
    
    foreach ($checkouts as $checkout) {
        $result = sendCheckoutNotification(
            $checkout,
            $notification_config,
            $pdo,
            $checkout_table,
            $log_table,
            $shop
        );
        
        if ($result['success']) {
            $processed_count++;
            if ($result['sms_sent']) {
                $sms_sent_count++;
            }
            if ($result['whatsapp_sent']) {
                $whatsapp_sent_count++;
            }
            
            updateCheckoutStatus($pdo, $checkout_table, $checkout['id'], 1);
        }
    }
    
    file_put_contents(
        $logFile,
        date('Y-m-d H:i:s') . " - Completed processing | Shop: $shop | " .
        "Total: $processed_count | SMS Sent: $sms_sent_count | WhatsApp Sent: $whatsapp_sent_count\n",
        FILE_APPEND
    );
    
    return array(
        'processed' => $processed_count,
        'sms_sent' => $sms_sent_count,
        'whatsapp_sent' => $whatsapp_sent_count
    );
}

function sendCheckoutNotification($checkout, $config, $pdo, $checkout_table, $log_table, $shop)
{
    global $logFile;
    
    $result = array(
        'success' => false,
        'sms_sent' => false,
        'whatsapp_sent' => false
    );
    
    $checkout_id = $checkout['checkout_id'];
    $customer_email = $checkout['customer_email'];
    $customer_fname = $checkout['customer_first_name'];
    $customer_lname = $checkout['customer_last_name'];
    $customer_phone = $checkout['customer_phone'];
    $customer_full_name = trim($customer_fname . ' ' . $customer_lname);
    $total_price = $checkout['total_price'];
    $checkout_url = $checkout['checkout_url'];
    $checkout_created_at = $checkout['created_at'];
    $checkout_name = $checkout['checkout_name'];
    $currency = $checkout['currency'];
    
    $line_items = array();
    if (!empty($checkout['line_items'])) {
        $line_items = json_decode($checkout['line_items'], true);
        if (!is_array($line_items)) {
            $line_items = array();
        }
    }
    
    $product_name = '';
    $item_sku = '';
    $item_quantity = '';
    $item_price = '';
    
    if (count($line_items) > 0) {
        $first_item = $line_items[0];
        $product_name = isset($first_item['title']) ? $first_item['title'] : '';
        $item_sku = isset($first_item['sku']) ? $first_item['sku'] : '';
        $item_quantity = isset($first_item['quantity']) ? $first_item['quantity'] : '';
        $item_price = isset($first_item['variant_price']) ? $first_item['variant_price'] : '';
    }
    
    $country_code = '';
    $shipping_address = array();
    if (!empty($checkout['shipping_address'])) {
        $shipping_address = json_decode($checkout['shipping_address'], true);
        if (is_array($shipping_address)) {
            $country_code = isset($shipping_address['countryCodeV2']) 
                ? strtoupper($shipping_address['countryCodeV2']) 
                : '';
        }
    }
    
    $phone_number = $customer_phone;
    if (!empty($country_code) && !empty($phone_number) && function_exists('getCountryCode_and_phone_number')) {
        $final_arr = getCountryCode_and_phone_number($country_code, $phone_number);
        $country_code = $final_arr['country_code'];
        $phone_number = $final_arr['phone_number'];
    }
    
    $subject = "Abandoned Checkout";
    
    $replacementMap = array(
        "{{ product_name }}" => $product_name,
        "{{ total_price }}" => $total_price,
        "{{ checkout_created_at }}" => $checkout_created_at,
        "{{ customer_fname }}" => $customer_fname,
        "{{ customer_lname }}" => $customer_lname,
        "{{ customer_email }}" => $customer_email,
        "{{ customer_phone }}" => $customer_phone,
        "{{ country_code }}" => $country_code,
        "{{ checkout_url }}" => $checkout_url,
        "{{ checkout_name }}" => $checkout_name,
        "{{ customer_full_name }}" => $customer_full_name,
        "{{ phone_number }}" => $phone_number,
        "{{ currency }}" => $currency,
        "{{ email_id }}" => $customer_email,
        "{{ Ad_item_sku }}" => $item_sku,
        "{{ Ad_item_quantity }}" => $item_quantity,
        "{{ Ad_item_price }}" => $item_price,
        "{{ Ad_order_number }}" => '',
        "{{ Ad_total_discount }}" => '',
        "{{ Ad_order_status_url }}" => $checkout_url,
        "{{ Ad_item_vendor }}" => '',
        "{{ Ad_total_weight }}" => ''
    );
    
    $replacement_map = array(
        'email_id' => $customer_email,
        'country_code' => $country_code,
        'customer_full_name' => $customer_full_name,
        'phone_number' => $phone_number,
        'customer_fname' => $customer_fname,
        'customer_lname' => $customer_lname,
        'product_name' => $product_name,
        'total_price' => $total_price,
        'checkout_url' => $checkout_url,
        'checkout_created_at' => $checkout_created_at,
        'checkout_name' => $checkout_name,
        'currency' => $currency,
        'item_sku' => $item_sku,
        'item_quantity' => $item_quantity,
        'item_price' => $item_price
    );
    
    file_put_contents($logFile, date('Y-m-d H:i:s') . " - Processing checkout $checkout_id for customer: $customer_full_name\n", FILE_APPEND);
    
    $sms_enabled = isset($config['sms_enabled']) ? (int) $config['sms_enabled'] : 0;
    
    if ($sms_enabled == 1) {
        $sms_text = isset($config['sms']) ? $config['sms'] : '';
        $whatsapp_text = isset($config['whatsapp']) ? $config['whatsapp'] : '';
        $media_type = isset($config['media_type']) ? $config['media_type'] : '';
        $button_type = isset($config['button_type']) ? $config['button_type'] : '';
        $media_url = isset($config['media_url']) ? $config['media_url'] : '';
        $media_source_prod = isset($config['media_source']) ? $config['media_source'] : '';
        $button_text1 = isset($config['button_text1']) ? trim($config['button_text1']) : '';
        $template_name_sms = isset($config['template_name']) ? $config['template_name'] : '';
        
        $sms_variables = array();
        $has_parameters = false;
        
        if (isset($config['sms_variables']) && !empty($config['sms_variables'])) {
            $sms_variables = json_decode($config['sms_variables'], true);
            if (!is_array($sms_variables)) {
                $sms_variables = array();
            }
            
            if (count($sms_variables) > 0) {
                $has_parameters = true;
                file_put_contents($logFile, "SMS Variables loaded (has parameters): " . print_r($sms_variables, true) . "\n", FILE_APPEND);
            } else {
                file_put_contents($logFile, "SMS Variables is empty array\n", FILE_APPEND);
            }
        } else {
            file_put_contents($logFile, "SMS Variables is NULL or empty\n", FILE_APPEND);
        }
        
        if ($has_parameters) {
            file_put_contents($logFile, "Using TEMPLATE-BASED SMS with parameters\n", FILE_APPEND);
            
            foreach ($sms_variables as $key => $value) {
                $placeholder = "{{ " . trim($key) . " }}";
                
                $processed_value = $value;
                foreach ($replacementMap as $ph => $val) {
                    if (strpos($processed_value, $ph) !== false && $val !== null) {
                        $processed_value = str_replace($ph, $val, $processed_value);
                    }
                }
                
                $recursion_count = 0;
                $max_recursion = 10;
                while (strpos($processed_value, '{{') !== false && $recursion_count < $max_recursion) {
                    foreach ($replacementMap as $ph => $val) {
                        if (strpos($processed_value, $ph) !== false && $val !== null) {
                            $processed_value = str_replace($ph, $val, $processed_value);
                        }
                    }
                    $recursion_count++;
                }
                
                $replacementMap[$placeholder] = $processed_value;
                file_put_contents($logFile, "Added custom variable: $placeholder => $processed_value\n", FILE_APPEND);
            }
            $processed_sms_text = $sms_text;
            foreach ($replacementMap as $placeholder => $value) {
                if ($value !== null && strpos($processed_sms_text, $placeholder) !== false) {
                    $processed_sms_text = str_replace($placeholder, $value, $processed_sms_text);
                }
            }
            foreach ($sms_variables as $key => $value) {
                $placeholder = "{{ " . trim($key) . " }}";
                if (isset($replacementMap[$placeholder]) && strpos($processed_sms_text, $placeholder) !== false) {
                    $processed_sms_text = str_replace($placeholder, $replacementMap[$placeholder], $processed_sms_text);
                }
            }
            $processed_whatsapp_text = $whatsapp_text;
            foreach ($replacementMap as $placeholder => $value) {
                if ($value !== null && strpos($processed_whatsapp_text, $placeholder) !== false) {
                    $processed_whatsapp_text = str_replace($placeholder, $value, $processed_whatsapp_text);
                }
            }
            foreach ($sms_variables as $key => $value) {
                $placeholder = "{{ " . trim($key) . " }}";
                if (isset($replacementMap[$placeholder]) && strpos($processed_whatsapp_text, $placeholder) !== false) {
                    $processed_whatsapp_text = str_replace($placeholder, $replacementMap[$placeholder], $processed_whatsapp_text);
                }
            }
            
            $parameter_values = array();
            foreach ($sms_variables as $key => $value) {
                $placeholder = "{{ " . trim($key) . " }}";
                if (isset($replacementMap[$placeholder])) {
                    $parameter_values[$key] = $replacementMap[$placeholder];
                } else {
                    $parameter_values[$key] = $value;
                }
            }
            file_put_contents($logFile, "Parameter Values for template: " . json_encode($parameter_values) . "\n", FILE_APPEND);
            file_put_contents($logFile, "Template ID: " . $template_name_sms . "\n", FILE_APPEND);

            foreach ($replacementMap as $placeholder => $value) {
                if ($value !== null) {
                    if (strpos($button_text1, $placeholder) !== false) {
                        $button_text1 = str_replace($placeholder, $value, $button_text1);
                    }
                    if (strpos($media_url, $placeholder) !== false) {
                        $media_url = str_replace($placeholder, $value, $media_url);
                    }
                }
            }
            
            if ($media_source_prod == 'dynmc_prod_img' && $media_type == 'image') {
                file_put_contents($logFile, "INFO: Dynamic product image not applicable for abandoned checkout\n", FILE_APPEND);
            }
            
            if (empty($phone_number)) {
                file_put_contents($logFile, "ERROR: Customer phone is empty, cannot send SMS for checkout: $checkout_id\n", FILE_APPEND);
            } elseif (empty($template_name_sms)) {
                file_put_contents($logFile, "ERROR: Template ID is empty, cannot send template SMS for checkout: $checkout_id\n", FILE_APPEND);
            } else {
                send_smstext_with_parameters($country_code, $phone_number, $template_name_sms, $shop, $customer_email, $customer_full_name, $parameter_values, $customer_full_name, '', 'Abandoned Checkout');
                $result['sms_sent'] = true;
                file_put_contents($logFile, "Template SMS sent successfully to $phone_number\n", FILE_APPEND);
            }
            
            $whatsapp_enabled = isset($config['whatsapp_enabled']) ? (int) $config['whatsapp_enabled'] : 0;
            
            if ($whatsapp_enabled == 1 && !empty($phone_number) && !empty($processed_whatsapp_text)) {
                if (($media_type == 'image' || $media_type == 'video' || $media_type == 'pdf') && !empty($button_type)) {
                    if (function_exists('send_whatsapp_text_Plus_button')) {
                        send_whatsapp_text_Plus_button($country_code, $phone_number, $processed_whatsapp_text, $shop, $customer_email, $customer_full_name, '', '', $subject, $media_type, $button_type, $media_url, $button_text1);
                        $result['whatsapp_sent'] = true;
                        file_put_contents($logFile, "WhatsApp with media sent successfully\n", FILE_APPEND);
                    }
                } elseif ($button_type == 'cta' || $button_type == 'static_cta') {
                    if (function_exists('send_whatsapp_text_Plus_button')) {
                        send_whatsapp_text_Plus_button($country_code, $phone_number, $processed_whatsapp_text, $shop, $customer_email, $customer_full_name, '', '', $subject, $media_type, $button_type, $media_url, $button_text1);
                        $result['whatsapp_sent'] = true;
                        file_put_contents($logFile, "WhatsApp with CTA button sent successfully\n", FILE_APPEND);
                    }
                } else {
                    if (function_exists('send_whatsapp_text')) {
                        send_whatsapp_text($country_code, $phone_number, $processed_whatsapp_text, $shop, $customer_email, $customer_full_name, '', '', $subject);
                        $result['whatsapp_sent'] = true;
                        file_put_contents($logFile, "WhatsApp sent successfully\n", FILE_APPEND);
                    }
                }
            }
            
        } else {
            file_put_contents($logFile, "Using PLAIN TEXT SMS (no parameters)\n", FILE_APPEND);
            
            $searchVal = array(
                "{{ email_id }}",
                "{{ country_code }}",
                "{{ customer_full_name }}",
                "{{ phone_number }}",
                "{{ customer_fname }}",
                "{{ customer_lname }}",
                "{{ product_name }}",
                "{{ total_price }}",
                "{{ checkout_created_at }}",
                "{{ checkout_url }}",
                "{{ checkout_name }}",
                "{{ currency }}"
            );
            
            $replaceVal = array(
                $customer_email,
                $country_code,
                $customer_full_name,
                $phone_number,
                $customer_fname,
                $customer_lname,
                $product_name,
                $total_price,
                $checkout_created_at,
                $checkout_url,
                $checkout_name,
                $currency
            );
            
            $final_sms = str_replace($searchVal, $replaceVal, $sms_text);
            $final_whatsapp = str_replace($searchVal, $replaceVal, $whatsapp_text);
            
            file_put_contents($logFile, "========== ABANDONED CHECKOUT SMS ==========\n", FILE_APPEND);
            file_put_contents($logFile, "Original SMS Template: $sms_text\n", FILE_APPEND);
            file_put_contents($logFile, "Replaced SMS Text: $final_sms\n", FILE_APPEND);
            file_put_contents($logFile, "Phone: $phone_number, Country: $country_code\n", FILE_APPEND);
            file_put_contents($logFile, "Customer: $customer_full_name ($customer_email)\n", FILE_APPEND);
            file_put_contents($logFile, "============================================\n\n", FILE_APPEND);
            
            $allPlaceholders = array(
                '{{ email_id }}' => $customer_email,
                '{{ country_code }}' => $country_code,
                '{{ customer_full_name }}' => $customer_full_name,
                '{{ phone_number }}' => $phone_number,
                '{{ customer_fname }}' => $customer_fname,
                '{{ customer_lname }}' => $customer_lname,
                '{{ product_name }}' => $product_name,
                '{{ total_price }}' => $total_price,
                '{{ checkout_url }}' => $checkout_url
            );
            
            foreach ($allPlaceholders as $placeholder => $value) {
                if (!empty($value)) {
                    if (!empty($button_text1) && strpos($button_text1, $placeholder) !== false) {
                        $button_text1 = str_replace($placeholder, $value, $button_text1);
                        file_put_contents($logFile, "Replaced $placeholder in button text: $button_text1\n", FILE_APPEND);
                    }
                    if (!empty($media_url) && strpos($media_url, $placeholder) !== false) {
                        $media_url = str_replace($placeholder, $value, $media_url);
                        file_put_contents($logFile, "Replaced $placeholder in media URL: $media_url\n", FILE_APPEND);
                    }
                }
            }
            
            if ($media_source_prod == 'dynmc_prod_img' && $media_type == 'image') {
                file_put_contents($logFile, "INFO: Dynamic product image not applicable for abandoned checkout\n", FILE_APPEND);
            }
            
            if (empty($phone_number)) {
                file_put_contents($logFile, "ERROR: Customer phone is empty, cannot send SMS for checkout: $checkout_id\n", FILE_APPEND);
            } elseif (empty($template_name_sms)) {
                file_put_contents($logFile, "ERROR: Template name is empty, cannot send SMS for checkout: $checkout_id\n", FILE_APPEND);
            } else {
                send_smstext($country_code, $phone_number, $template_name_sms, $shop, $customer_email, $customer_full_name, $customer_full_name, '', "Abandoned Checkout");
                $result['sms_sent'] = true;
                file_put_contents($logFile, "SMS sent successfully to $phone_number\n", FILE_APPEND);
            }
            $whatsapp_enabled = isset($config['whatsapp_enabled']) ? (int) $config['whatsapp_enabled'] : 0;
            
            if ($whatsapp_enabled == 1 && !empty($phone_number) && !empty($final_whatsapp)) {
                if (($media_type == 'image' || $media_type == 'video' || $media_type == 'pdf') && !empty($button_type)) {
                    if (function_exists('send_whatsapp_text_Plus_button')) {
                        send_whatsapp_text_Plus_button($country_code, $phone_number, $final_whatsapp, $shop, $customer_email, $customer_full_name, '', '', $subject, $media_type, $button_type, $media_url, $button_text1);
                        $result['whatsapp_sent'] = true;
                        file_put_contents($logFile, "WhatsApp with media sent successfully\n", FILE_APPEND);
                    }
                } elseif ($button_type == 'cta' || $button_type == 'static_cta') {
                    if (function_exists('send_whatsapp_text_Plus_button')) {
                        send_whatsapp_text_Plus_button($country_code, $phone_number, $final_whatsapp, $shop, $customer_email, $customer_full_name, '', '', $subject, $media_type, $button_type, $media_url, $button_text1);
                        $result['whatsapp_sent'] = true;
                        file_put_contents($logFile, "WhatsApp with CTA button sent successfully\n", FILE_APPEND);
                    }
                } else {
                    if (function_exists('send_whatsapp_text')) {
                        send_whatsapp_text($country_code, $phone_number, $final_whatsapp, $shop, $customer_email, $customer_full_name, '', '', $subject);
                        $result['whatsapp_sent'] = true;
                        file_put_contents($logFile, "WhatsApp sent successfully\n", FILE_APPEND);
                    }
                }
            }
        }
    }
    $whatsapp_data_from_db = array();
    if (isset($config['whatsapp']) && !empty($config['whatsapp'])) {
        $whatsapp_data_from_db = json_decode($config['whatsapp'], true);
        if (!is_array($whatsapp_data_from_db)) {
            $whatsapp_data_from_db = array();
        }
    }
    
    $whatsapp_template_name = isset($whatsapp_data_from_db['template_name']) ? $whatsapp_data_from_db['template_name'] : '';
    $whatsapp_enabled = isset($config['whatsapp_enabled']) ? (int) $config['whatsapp_enabled'] : 0;
    
    if ($whatsapp_enabled == 1 && !empty($whatsapp_template_name)) {
        file_put_contents($logFile, "Processing WhatsApp Template: $whatsapp_template_name\n", FILE_APPEND);
        
        $whatsapp_api_config = array(
            'log_file' => "$logFile"
        );
        
        $table2 = $prefix . "shopify_sms_notification_App_Log_Details";
        
        $processed_headers = array();
        $header_variables = isset($whatsapp_data_from_db['variable_headers']) ? $whatsapp_data_from_db['variable_headers'] : array();
        
        if (!empty($header_variables)) {
            foreach ($header_variables as $header_var) {
                $processed_value = wa_replace_placeholders($header_var, $replacement_map);
                $processed_headers[] = $processed_value;
                file_put_contents($logFile, "Header variable: '$header_var' -> '$processed_value'\n", FILE_APPEND);
            }
        }
        
        $processed_body = array();
        $body_variables = isset($whatsapp_data_from_db['variable_body']) ? $whatsapp_data_from_db['variable_body'] : array();
        
        if (!empty($body_variables)) {
            foreach ($body_variables as $body_var) {
                $processed_value = wa_replace_placeholders($body_var, $replacement_map);
                $processed_body[] = $processed_value;
                file_put_contents($logFile, "Body variable: '$body_var' -> '$processed_value'\n", FILE_APPEND);
            }
        }
        
        $whatsapp_media_url = isset($config['media_url']) ? $config['media_url'] : '';
        $whatsapp_media_source = isset($config['media_source']) ? $config['media_source'] : '';
        $whatsapp_media_type = isset($config['media_type']) ? $config['media_type'] : 'text';
        
        if (!empty($whatsapp_media_url)) {
            $whatsapp_media_url = wa_replace_placeholders($whatsapp_media_url, $replacement_map);
            file_put_contents($logFile, "Media URL after replacement: $whatsapp_media_url\n", FILE_APPEND);
        }
        
        if ($whatsapp_media_source == 'dynmc_prod_img' && $whatsapp_media_type == 'image') {
            file_put_contents($logFile, "INFO: Dynamic product image not applicable for abandoned checkout WhatsApp\n", FILE_APPEND);
        }
        
        $buttons = array();
        $cta_urls = array();
        if (isset($config['cta_url']) && !empty($config['cta_url'])) {
            $cta_urls = json_decode($config['cta_url'], true);
            if (!is_array($cta_urls)) {
                $cta_urls = array();
            }
        }
        
        $btn1_type = isset($config['button_type']) ? $config['button_type'] : '';
        $btn1_text = isset($config['button_text1_type']) ? trim($config['button_text1_type']) : '';
        $btn1_url = isset($cta_urls['button1']) ? $cta_urls['button1'] : '';
        
        if ($btn1_type !== 'none' && !empty($btn1_text)) {
            $btn1_text = wa_replace_placeholders($btn1_text, $replacement_map);
            $btn1_url = wa_replace_placeholders($btn1_url, $replacement_map);
            
            $buttons[] = array(
                'sub_type' => ($btn1_type === 'cta') ? 'url' : 'quick_reply',
                'index' => '0',
                'value' => ($btn1_type === 'cta') ? $btn1_url : $btn1_text,
                'button_text' => $btn1_text
            );
            file_put_contents($logFile, "WhatsApp Button 1: type=$btn1_type, text=$btn1_text\n", FILE_APPEND);
        }    
        $btn2_type = isset($config['button_type2']) ? $config['button_type2'] : '';
        $btn2_text = isset($config['button_text2_type']) ? trim($config['button_text2_type']) : '';
        $btn2_url = isset($cta_urls['button2']) ? $cta_urls['button2'] : '';
        if ($btn2_type !== 'none' && !empty($btn2_text)) {
            $btn2_text = wa_replace_placeholders($btn2_text, $replacement_map);
            $btn2_url = wa_replace_placeholders($btn2_url, $replacement_map);
            
            $buttons[] = array(
                'sub_type' => ($btn2_type === 'cta') ? 'url' : 'quick_reply',
                'index' => '1',
                'value' => ($btn2_type === 'cta') ? $btn2_url : $btn2_text,
                'button_text' => $btn2_text
            );
            file_put_contents($logFile, "WhatsApp Button 2: type=$btn2_type, text=$btn2_text\n", FILE_APPEND);
        }
        $btn3_type = isset($config['button_type3']) ? $config['button_type3'] : '';
        $btn3_text = isset($config['button_text3_type']) ? trim($config['button_text3_type']) : '';
        $btn3_url = isset($cta_urls['button3']) ? $cta_urls['button3'] : '';
        
        if ($btn3_type !== 'none' && !empty($btn3_text)) {
            $btn3_text = wa_replace_placeholders($btn3_text, $replacement_map);
            $btn3_url = wa_replace_placeholders($btn3_url, $replacement_map);
            
            $buttons[] = array(
                'sub_type' => ($btn3_type === 'cta') ? 'url' : 'quick_reply',
                'index' => '2',
                'value' => ($btn3_type === 'cta') ? $btn3_url : $btn3_text,
                'button_text' => $btn3_text
            );
            file_put_contents($logFile, "WhatsApp Button 3: type=$btn3_type, text=$btn3_text\n", FILE_APPEND);
        }
        
        $full_phone_with_code = $country_code . $phone_number;
        $whatsapp_config = array_merge($whatsapp_api_config, array(
            'to' => $full_phone_with_code,
            'template_name' => $whatsapp_template_name,
            'language_code' => 'en',
            'media_type' => $whatsapp_media_type,
            'media_url' => $whatsapp_media_url,
            'media_source' => $whatsapp_media_source,
            'variable_headers' => $processed_headers,
            'variable_body' => $processed_body,
            'buttons' => $buttons,
            'shop' => $shop,
            'order_id' => '',
            'order_name' => $customer_full_name,
            'customer_email' => $customer_email,
            'country_code' => $country_code,
            'phone_num' => $phone_number,
            'notification_type' => 'Abandoned Checkout'
        ));
        
        if (empty($phone_number)) {
            file_put_contents($logFile, "ERROR: Customer phone is empty, cannot send WhatsApp for checkout: $checkout_id\n", FILE_APPEND);
        } else {
            $whatsapp_result = send_whatsapp_message($whatsapp_config, $pdo, $table2);
            
            if (isset($whatsapp_result['success']) && $whatsapp_result['success']) {
                $result['whatsapp_sent'] = true;
                file_put_contents($logFile, "WhatsApp template sent successfully to $phone_number\n", FILE_APPEND);
            } else {
                $error_msg = isset($whatsapp_result['message']) ? $whatsapp_result['message'] : 'Unknown error';
                file_put_contents($logFile, "WhatsApp template failed: $error_msg\n", FILE_APPEND);
            }
        }
    }
    
    $result['success'] = ($result['sms_sent'] || $result['whatsapp_sent']);
    
    file_put_contents(
        $logFile,
        date('Y-m-d H:i:s') . " - Checkout $checkout_id processed | " .
        "SMS: " . ($result['sms_sent'] ? 'Sent' : 'Not Sent') . " | " .
        "WhatsApp: " . ($result['whatsapp_sent'] ? 'Sent' : 'Not Sent') . "\n",
        FILE_APPEND
    );
    
    return $result;
}
function updateCheckoutStatus($pdo, $table, $id, $status)
{
    try {
        $sql = "UPDATE `$table` SET status = :status, processed_at = NOW() WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array(':status' => $status, ':id' => $id));
        return true;
    } catch (PDOException $e) {
        global $logFile;
        file_put_contents($logFile, date('Y-m-d H:i:s') . " ERROR updating status: " . $e->getMessage() . "\n", FILE_APPEND);
        return false;
    }
}

if (isset($_GET['shop']) && isset($_GET['token'])) {
    $shop = $_GET['shop'];
    $oauth_token = $_GET['token'];
    
    $result = processAbandonedCheckoutNotifications($shop, $oauth_token);

    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
?>