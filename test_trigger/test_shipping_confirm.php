<?php
require_once '../config/db.php';
require_once __DIR__ . '/../app_config.php';
include '../accurate_country_code.php';
include '../send_sms_api.php';
include '../send_whatsapp_message_api.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

$notification_type = "Shipping Confirmation";
$countryCode = isset($data['country_code']) ? $data['country_code'] : '';
$phone = isset($data['phone']) ? $data['phone'] : '';
$shop = isset($data['shop']) ? $data['shop'] : '';

if (!$countryCode || !$phone) {
    echo json_encode(array('success' => false, 'message' => 'Missing country code or phone number'));
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
    $prefix = $app_prefix;
    $configTable = $prefix . 'shopify_sms_notification_app';
    $stmt = $pdo->prepare("SELECT session_access_token AS oauth_token FROM $configTable WHERE shop = :shop");
    $stmt->execute(array(':shop' => $shop));
    $shopRow = $stmt->fetch();
    
    if (!$shopRow) {
        echo json_encode(array('success' => false, 'message' => 'Shop not found in database'));
        exit;
    }
  
    $order_details = json_decode('{
        "id": 820982911946154508,
        "name": "#9999",
        "total_price": "404.95",
        "total_discounts": "20.00",
        "total_weight": 0,
        "order_status_url": "https://jsmith.myshopify.com/548380009/orders/123456abcd/authenticate?key=abcdefg",
        "billing_address": {
            "first_name": "Steve",
            "last_name": "Shipper",
            "address1": "123 Shipping Street",
            "city": "Shippington",
            "province": "Kentucky",
            "zip": "40003",
            "country": "United States",
            "country_code": "US",
            "phone": "555-555-SHIP"
        },
        "shipping_address": {
            "first_name": "Steve",
            "last_name": "Shipper",
            "address1": "123 Shipping Street",
            "city": "Shippington",
            "province": "Kentucky",
            "zip": "40003",
            "country": "United States",
            "country_code": "US",
            "phone": "555-555-SHIP"
        },
        "customer": {
            "first_name": "John",
            "last_name": "Smith",
            "phone": null,
            "email": "john@example.com"
        },
        "contact_email": "jon@example.com",
        "line_items": [
            {
                "name": "Aviator sunglasses",
                "price": "89.99",
                "quantity": 1,
                "sku": "SKU2006-001",
                "vendor": null
            },
            {
                "name": "Lens Protection Plan (2 Year)",
                "price": "19.99",
                "quantity": 1,
                "sku": "LENS-PROTECT-2YR",
                "vendor": null
            },
            {
                "name": "Premium Leather Case",
                "price": "24.99",
                "quantity": 1,
                "sku": "CASE-LEATHER-PREM",
                "vendor": null
            },
            {
                "name": "Mid-century lounger",
                "price": "159.99",
                "quantity": 1,
                "sku": "SKU2006-020",
                "vendor": null
            },
            {
                "name": "Coffee table",
                "price": "119.99",
                "quantity": 1,
                "sku": "SKU2006-035",
                "vendor": null
            }
        ]
    }');
    
    $order_id = $order_details->id;
    $order_name = $order_details->name;
    $order_total_price = $order_details->total_price;
    $total_discount = $order_details->total_discounts;
    $total_weight = $order_details->total_weight;
   
    $order_status_url = $order_details->order_status_url;
    $parts = explode('orders/', $order_status_url);
    
    $billing = $order_details->billing_address;
    $shipping = $order_details->shipping_address;
  
    $customer_phone_input = $phone;
    $country_code_input = str_replace('+', '', $countryCode);
    
    $shipping_country_code = isset($shipping->country_code) ? $shipping->country_code : '';
    $billing_country_code = isset($billing->country_code) ? $billing->country_code : '';
    
    $billing_string = implode(', ', array_filter(array(
        isset($billing->first_name) ? $billing->first_name : '',
        isset($billing->last_name) ? $billing->last_name : '',
        isset($billing->address1) ? $billing->address1 : '',
        isset($billing->city) ? $billing->city : '',
        isset($billing->province) ? $billing->province : '',
        isset($billing->zip) ? $billing->zip : '',
        isset($billing->country) ? $billing->country : '',
        isset($billing->phone) ? $billing->phone : ''
    )));
    
    $shipping_string = implode(', ', array_filter(array(
        isset($shipping->first_name) ? $shipping->first_name : '',
        isset($shipping->last_name) ? $shipping->last_name : '',
        isset($shipping->address1) ? $shipping->address1 : '',
        isset($shipping->city) ? $shipping->city : '',
        isset($shipping->province) ? $shipping->province : '',
        isset($shipping->zip) ? $shipping->zip : '',
        isset($shipping->country) ? $shipping->country : '',
        isset($shipping->phone) ? $shipping->phone : ''
    )));
    
    $shipping_city = isset($shipping->city) ? $shipping->city : '';
    $shipping_state = isset($shipping->province) ? $shipping->province : '';
    $shipping_zip = isset($shipping->zip) ? $shipping->zip : '';
    $shipping_country = isset($shipping->country) ? $shipping->country : '';
    
    $customer = $order_details->customer;
    $customer_fname = isset($customer->first_name) ? $customer->first_name : '';
    $customer_lname = isset($customer->last_name) ? $customer->last_name : '';
    $customer_full_name = trim($customer_fname . ' ' . $customer_lname);
    $customer_email_id = isset($order_details->contact_email) ? $order_details->contact_email : '';
    
    $item_name_arr = array();
    $item_price_arr = array();
    $item_quantity_arr = array();
    $item_sku_arr = array();
    $item_vendor_arr = array();
    
    if (isset($order_details->line_items) && is_array($order_details->line_items)) {
        foreach ($order_details->line_items as $value) {
            $item_name_arr[] = isset($value->name) ? $value->name : '';
            $item_price_arr[] = isset($value->price) ? $value->price : '';
            $item_quantity_arr[] = isset($value->quantity) ? $value->quantity : '';
            $item_sku_arr[] = isset($value->sku) ? $value->sku : '';
            $item_vendor_arr[] = isset($value->vendor) ? $value->vendor : '';
        }
    }
    
    $item_name = implode(', ', $item_name_arr);
    $item_price = isset($item_price_arr[0]) ? $item_price_arr[0] : '';
    $item_quantity = isset($item_quantity_arr[0]) ? $item_quantity_arr[0] : '';
    $item_sku = isset($item_sku_arr[0]) ? $item_sku_arr[0] : '';
    $item_vendor = isset($item_vendor_arr[0]) ? $item_vendor_arr[0] : '';
    
    $tracking_number = "1z827wk74630";
    $tracking_url = "https://www.ups.com/WebTracking?loc=en_US&requester=ST&trackNums=1z827wk74630";
    $tracking_company = "UPS";
    $estimated_delivery_date = "";
   
    if ($country_code_input && $customer_phone_input) {
        $final_arr = getCountryCode_and_phone_number($country_code_input, $customer_phone_input);
        $final_country_code = $final_arr['country_code'];
        $final_phone_number = $final_arr['phone_number'];
    } else {
        $final_country_code = $country_code_input;
        $final_phone_number = $customer_phone_input;
    }
    
    $actual_values = array(
        "{{ order_name }}" => $order_name,
        "{{ Ad_order_number }}" => $order_id,
        "{{ order_total_price }}" => $order_total_price,
        "{{ customer_fname }}" => $customer_fname,
        "{{ customer_lname }}" => $customer_lname,
        "{{ customer_full_name }}" => $customer_full_name,
        "{{ customer_email_id }}" => $customer_email_id,
        "{{ customer_phone }}" => $final_phone_number,
        "{{ country_code }}" => $final_country_code,
        "{{ item_name }}" => $item_name,
        "{{ Ad_item_price }}" => $item_price,
        "{{ Ad_item_quantity }}" => $item_quantity,
        "{{ Ad_item_sku }}" => $item_sku,
        "{{ Ad_item_vendor }}" => $item_vendor,
        "{{ Ad_total_discount }}" => $total_discount,
        "{{ Ad_total_weight }}" => $total_weight,
        "{{ Ad_order_status_url }}" => $order_status_url,
        "{{ Ad_shipping_address }}" => $shipping_string,
        "{{ Ad_billing_address }}" => $billing_string,
        "{{ shipping_address }}" => $shipping_string,
        "{{ shipping_city }}" => $shipping_city,
        "{{ shipping_state }}" => $shipping_state,
        "{{ shipping_zip }}" => $shipping_zip,
        "{{ shipping_country }}" => $shipping_country,
        "{{ tracking_number }}" => $tracking_number,
        "{{ tracking_url }}" => $tracking_url,
        "{{ tracking_company }}" => $tracking_company,
        "{{ estimated_delivery_date }}" => $estimated_delivery_date
    );
    
    $aid = 7;
    $table = $prefix . "shopify_sms_notification_App_Email_Notification";
    
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE aid=:aid AND shop=:shop");
    $stmt->execute(array(':aid' => $aid, ':shop' => $shop));
    $row = $stmt->fetch();
    
    if (!$row) {
        echo json_encode(array('success' => false, 'message' => 'Template not found for Shipping Confirmation (aid=7)'));
        exit;
    }
    
    $template_name_sms = $row['template_name'];
    $sms_text = $row['sms'];
    $sms_enabled = isset($row['sms_enabled']) ? (int)$row['sms_enabled'] : 0;
    $whatsapp_enabled = isset($row['whatsapp_enabled']) ? (int)$row['whatsapp_enabled'] : 0;
    
    $processed_sms_text = str_replace(
        array_keys($actual_values),
        array_values($actual_values),
        $sms_text
    );
    
    $parameter_values = array();
    
    if (!empty($row['sms_variables'])) {
        $sms_variables = json_decode($row['sms_variables'], true);
        
        if (is_array($sms_variables)) {
            foreach ($sms_variables as $key => $val) {
                $parameter_values[$key] = str_replace(
                    array_keys($actual_values),
                    array_values($actual_values),
                    $val
                );
            }
        }
    }
    
    $has_parameters = (count($parameter_values) > 0);
    
    if (empty($processed_sms_text) || empty($sms_text)) {
        $processed_sms_text = "Your order {$order_name} has been shipped via {$tracking_company}! Tracking number: {$tracking_number}. Track your order: {$tracking_url} Estimated delivery: {$estimated_delivery_date}";
    }
    
    // ========== SMS HANDLING ==========
    if ($sms_enabled == 1) {
        if ($has_parameters) {
            send_smstext_with_parameters(
                $final_country_code,
                $final_phone_number,
                $template_name_sms,
                $shop,
                $customer_email_id,
                $customer_full_name,
                $parameter_values,
                $order_id,
                $order_name,
                $notification_type
            );
            
            $response = array(
                'success' => true,
                'message' => 'Template SMS sent successfully',
                'template_name' => $template_name_sms,
                'parameters' => $parameter_values
            );
        } else {
            send_smstext(
                $final_country_code,
                $final_phone_number,
                $template_name_sms,
                $shop,
                $customer_email_id,
                $customer_full_name,
                $order_id,
                $order_name,
                $notification_type
            );
            
            $response = array(
                'success' => true,
                'message' => 'Plain text SMS sent successfully',
                'template_name' => $template_name_sms
            );
        }
    } else {
        $response = array(
            'success' => true,
            'message' => 'SMS is disabled for this template, skipping SMS',
            'sms_enabled' => false,
            'whatsapp_enabled' => $whatsapp_enabled
        );
    }
    
    echo json_encode($response);
    
    // ========== WHATSAPP HANDLING (MOVED OUTSIDE THE SMS ENABLED CHECK) ==========
    $whatsapp_data_from_db = array();
    if (isset($row['whatsapp']) && !empty($row['whatsapp'])) {
        $whatsapp_data_from_db = json_decode($row['whatsapp'], true);
        if (!is_array($whatsapp_data_from_db)) {
            $whatsapp_data_from_db = array();
        }
    }
    
    $whatsapp_template_name = isset($whatsapp_data_from_db['template_name']) ? $whatsapp_data_from_db['template_name'] : '';
    
    if ($whatsapp_enabled == 1 && !empty($whatsapp_template_name)) {
        error_log("Processing WhatsApp for shipping confirmation test - Template: $whatsapp_template_name");
        
        $whatsapp_api_config = array(
            'log_file' => __DIR__ . '/../file/debug_log.txt'
        );
        
        $whatsapp_table2 = $prefix . "shopify_sms_notification_App_Log_Details";
        
        $replacement_map = array(
            'order_name' => $order_name,
            'Ad_order_number' => $order_id,
            'order_total_price' => $order_total_price,
            'customer_fname' => $customer_fname,
            'customer_lname' => $customer_lname,
            'customer_full_name' => $customer_full_name,
            'customer_email_id' => $customer_email_id,
            'customer_phone' => $final_phone_number,
            'country_code' => $final_country_code,
            'item_name' => $item_name,
            'Ad_item_price' => $item_price,
            'Ad_item_quantity' => $item_quantity,
            'Ad_item_sku' => $item_sku,
            'Ad_item_vendor' => $item_vendor,
            'Ad_total_discount' => $total_discount,
            'Ad_total_weight' => $total_weight,
            'Ad_order_status_url' => $order_status_url,
            'Ad_shipping_address' => $shipping_string,
            'Ad_billing_address' => $billing_string,
            'shipping_address' => $shipping_string,
            'shipping_city' => $shipping_city,
            'shipping_state' => $shipping_state,
            'shipping_zip' => $shipping_zip,
            'shipping_country' => $shipping_country,
            'tracking_number' => $tracking_number,
            'tracking_url' => $tracking_url,
            'tracking_company' => $tracking_company,
            'estimated_delivery_date' => $estimated_delivery_date,
            'order_id' => $order_id
        );
        
        $processed_headers = array();
        $header_variables = isset($whatsapp_data_from_db['variable_headers']) ? $whatsapp_data_from_db['variable_headers'] : array();
        
        if (!empty($header_variables)) {
            foreach ($header_variables as $header_var) {
                $processed_value = wa_replace_placeholders($header_var, $replacement_map);
                $processed_headers[] = $processed_value;
                error_log("WhatsApp Header variable: '$header_var' -> '$processed_value'");
            }
        }
        
        $processed_body = array();
        $body_variables = isset($whatsapp_data_from_db['variable_body']) ? $whatsapp_data_from_db['variable_body'] : array();
        
        if (!empty($body_variables)) {
            foreach ($body_variables as $body_var) {
                $processed_value = wa_replace_placeholders($body_var, $replacement_map);
                $processed_body[] = $processed_value;
                error_log("WhatsApp Body variable: '$body_var' -> '$processed_value'");
            }
        }
        
        $whatsapp_media_url = isset($row['media_url']) ? $row['media_url'] : '';
        $whatsapp_media_source = isset($row['media_source']) ? $row['media_source'] : '';
        $whatsapp_media_type = isset($row['media_type']) ? $row['media_type'] : 'text';
        
        if (!empty($whatsapp_media_url)) {
            $whatsapp_media_url = wa_replace_placeholders($whatsapp_media_url, $replacement_map);
            error_log("WhatsApp Media URL after replacement: $whatsapp_media_url");
        }
        
        $buttons = array();
        
        $cta_urls = array();
        if (isset($row['cta_url']) && !empty($row['cta_url'])) {
            $cta_urls = json_decode($row['cta_url'], true);
            if (!is_array($cta_urls)) {
                $cta_urls = array();
            }
        }
        
        $btn1_type = isset($row['button_type']) ? $row['button_type'] : '';
        $btn1_text = isset($row['button_text1_type']) ? trim($row['button_text1_type']) : '';
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
            error_log("WhatsApp Button 1: type=$btn1_type, text=$btn1_text");
        }
        
        $btn2_type = isset($row['button_type2']) ? $row['button_type2'] : '';
        $btn2_text = isset($row['button_text2_type']) ? trim($row['button_text2_type']) : '';
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
            error_log("WhatsApp Button 2: type=$btn2_type, text=$btn2_text");
        }
        
        $btn3_type = isset($row['button_type3']) ? $row['button_type3'] : '';
        $btn3_text = isset($row['button_text3_type']) ? trim($row['button_text3_type']) : '';
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
            error_log("WhatsApp Button 3: type=$btn3_type, text=$btn3_text");
        }
        
        $wa_phone = "$country_code_input" . "$final_phone_number";
        $whatsapp_config = array_merge($whatsapp_api_config, array(
            'to' => $wa_phone,
            'template_name' => $whatsapp_template_name,
            'language_code' => 'en',
            'media_type' => $whatsapp_media_type,
            'media_url' => $whatsapp_media_url,
            'media_source' => $whatsapp_media_source,
            'variable_headers' => $processed_headers,
            'variable_body' => $processed_body,
            'buttons' => $buttons,
            'shop' => $shop,
            'order_id' => $order_id,
            'order_name' => $order_name,
            'customer_email' => $customer_email_id,
            'country_code' => $final_country_code,
            'phone_num' => $final_phone_number,
            'notification_type' => 'Shipping Confirmation'
        ));
        
        if (empty($final_phone_number)) {
            error_log("ERROR: Customer phone is empty, cannot send WhatsApp for shipping confirmation test");
        } else {
            if (function_exists('send_whatsapp_message')) {
                $whatsapp_result = send_whatsapp_message($whatsapp_config, $pdo, $whatsapp_table2);
                
                if ($whatsapp_result['success']) {
                    error_log("WhatsApp sent successfully for shipping confirmation test to {$final_phone_number}");
                } else {
                    error_log("WhatsApp failed for shipping confirmation test: " . $whatsapp_result['message']);
                }
            } else {
                error_log("WhatsApp function send_whatsapp_message not found");
            }
        }
    } else {
        error_log("No WhatsApp template configured for aid=7, skipping WhatsApp send");
    }
    // ========== END WHATSAPP HANDLING ==========
    
} catch (Exception $e) {
    echo json_encode(array('success' => false, 'message' => $e->getMessage()));
}
?>