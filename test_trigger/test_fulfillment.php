<?php
require_once '../config/db.php';
require_once __DIR__ . '/../app_config.php';
include '../accurate_country_code.php';
include '../send_sms_api.php';
include '../send_whatsapp_message_api.php';

header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);

$notification_type = "Fulfillment Request";
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
    $fulfillment = json_decode('{
        "id": 123456,
        "order_id": 820982911946154508,
        "status": "pending",
        "created_at": "2021-12-31T19:00:00-05:00",
        "service": null,
        "updated_at": "2021-12-31T19:00:00-05:00",
        "tracking_company": "UPS",
        "shipment_status": null,
        "location_id": null,
        "origin_address": null,
        "email": "jon@example.com",
        "destination": {
            "first_name": "Steve",
            "address1": "123 Shipping Street",
            "phone": "555-555-SHIP",
            "city": "Shippington",
            "zip": "40003",
            "province": "Kentucky",
            "country": "United States",
            "last_name": "Shipper",
            "address2": null,
            "company": "Shipping Company",
            "latitude": null,
            "longitude": null,
            "name": "Steve Shipper",
            "country_code": "US",
            "province_code": "KY"
        },
        "line_items": [
            {
                "id": 487817672276298554,
                "name": "Aviator sunglasses",
                "quantity": 1,
                "sku": "SKU2006-001",
                "price": "89.99",
                "vendor": null
            },
            {
                "id": 789012345678901234,
                "name": "Lens Protection Plan (2 Year)",
                "quantity": 1,
                "sku": "LENS-PROTECT-2YR",
                "price": "19.99",
                "vendor": null
            },
            {
                "id": 890123456789012345,
                "name": "Premium Leather Case",
                "quantity": 1,
                "sku": "CASE-LEATHER-PREM",
                "price": "24.99",
                "vendor": null
            },
            {
                "id": 976318377106520349,
                "name": "Mid-century lounger",
                "quantity": 1,
                "sku": "SKU2006-020",
                "price": "159.99",
                "vendor": null
            },
            {
                "id": 315789986012684393,
                "name": "Coffee table",
                "quantity": 1,
                "sku": "SKU2006-035",
                "price": "119.99",
                "vendor": null
            }
        ],
        "tracking_number": "1z827wk74630",
        "tracking_numbers": ["1z827wk74630"],
        "tracking_url": "https://www.ups.com/WebTracking?loc=en_US&requester=ST&trackNums=1z827wk74630",
        "tracking_urls": ["https://www.ups.com/WebTracking?loc=en_US&requester=ST&trackNums=1z827wk74630"],
        "receipt": {},
        "name": "#9999.1",
        "admin_graphql_api_id": "gid://shopify/Fulfillment/123456"
    }');
  
    $fulfillment_id = $fulfillment->id;
    $order_id = $fulfillment->order_id;
    $order_name = isset($fulfillment->name) ? $fulfillment->name : '';
    $email = isset($fulfillment->email) ? $fulfillment->email : '';
    
    $tracking_number = isset($fulfillment->tracking_number) ? $fulfillment->tracking_number : '';
    $tracking_url = isset($fulfillment->tracking_url) ? $fulfillment->tracking_url : '';
    $fulfillment_status = isset($fulfillment->status) ? $fulfillment->status : '';
    $tracking_company = isset($fulfillment->tracking_company) ? $fulfillment->tracking_company : '';
    $fulfillment_created_at = isset($fulfillment->created_at) ? $fulfillment->created_at : '';
    $fulfillment_updated_at = isset($fulfillment->updated_at) ? $fulfillment->updated_at : '';
            
    $total_discount = '';
    $total_weight = 0;
    $order_status_url = '';
    
    $destination = $fulfillment->destination;
    
    $shipping_string = implode(', ', array_filter(array(
        isset($destination->first_name) ? $destination->first_name : '',
        isset($destination->last_name) ? $destination->last_name : '',
        isset($destination->address1) ? $destination->address1 : '',
        isset($destination->address2) ? $destination->address2 : '',
        isset($destination->company) ? $destination->company : '',
        isset($destination->city) ? $destination->city : '',
        isset($destination->province) ? $destination->province : '',
        isset($destination->zip) ? $destination->zip : '',
        isset($destination->country) ? $destination->country : '',
        isset($destination->phone) ? $destination->phone : ''
    )));
    
    $billing_string = '';
    
    $shipping_city = isset($destination->city) ? $destination->city : '';
    $shipping_state = isset($destination->province) ? $destination->province : '';
    $shipping_zip = isset($destination->zip) ? $destination->zip : '';
    $shipping_country = isset($destination->country) ? $destination->country : '';
   
    $customer_phone = $phone;
    $country_code_input = str_replace('+', '', $countryCode);
    
    $destination_country_code = isset($destination->country_code) ? $destination->country_code : '';
    $customer_id = isset($customer->id) ? $customer->id : '';
    $customer_fname = isset($destination->first_name) ? $destination->first_name : '';
    $customer_lname = isset($destination->last_name) ? $destination->last_name : '';
    $customer_full_name = trim($customer_fname . ' ' . $customer_lname);
    $customer_email_id = isset($fulfillment->email) ? $fulfillment->email : '';
    
    $item_name_arr = array();
    $item_price_arr = array();
    $item_quantity_arr = array();
    $item_sku_arr = array();
    $item_vendor_arr = array();
    
    if (isset($fulfillment->line_items) && is_array($fulfillment->line_items)) {
        foreach ($fulfillment->line_items as $value) {
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
    
    if ($country_code_input && $customer_phone) {
        $final_arr = getCountryCode_and_phone_number($country_code_input, $customer_phone);
        $final_country_code = $final_arr['country_code'];
        $final_phone_number = $final_arr['phone_number'];
    } else {
        $final_country_code = $country_code_input;
        $final_phone_number = $customer_phone;
    }
    
    $actual_values = array(
        "{{ order_name }}" => $order_name,
        "{{ Ad_order_number }}" => $order_id,
        "{{ fulfillment_id }}" => $fulfillment_id,
        "{{ fulfillment_status }}" => $fulfillment_status,
        "{{ tracking_number }}" => $tracking_number,
        "{{ tracking_url }}" => $tracking_url,
        "{{ tracking_company }}" => $tracking_company,
        "{{ fulfillment_created_at }}" => $fulfillment_created_at,
        "{{ fulfillment_updated_at }}" => $fulfillment_updated_at,
        "{{ customer_fname }}" => $customer_fname,
        "{{ customer_lname }}" => $customer_lname,
        "{{ customer_full_name }}" => $customer_full_name,
        "{{ customer_email_id }}" => $customer_email_id,
        "{{ customer_phone }}" => $final_phone_number,
        "{{ country_code }}" => $final_country_code,
        "{{ shipping_address }}" => $shipping_string,
        "{{ shipping_city }}" => $shipping_city,
        "{{ shipping_state }}" => $shipping_state,
        "{{ shipping_zip }}" => $shipping_zip,
        "{{ shipping_country }}" => $shipping_country,
        "{{ Ad_shipping_address }}" => $shipping_string,
        "{{ Ad_billing_address }}" => $billing_string,
        "{{ item_name }}" => $item_name,
        "{{ Ad_item_price }}" => $item_price,
        "{{ Ad_item_quantity }}" => $item_quantity,
        "{{ Ad_item_sku }}" => $item_sku,
        "{{ Ad_item_vendor }}" => $item_vendor,
        "{{ Ad_total_discount }}" => $total_discount,
        "{{ Ad_total_weight }}" => $total_weight,
        "{{ Ad_order_status_url }}" => $order_status_url
    );
    
    
    $aid = 6;
    $table = $prefix . "shopify_sms_notification_App_Email_Notification";
    
    $stmt = $pdo->prepare("SELECT * FROM $table WHERE aid=:aid AND shop=:shop");
    $stmt->execute(array(':aid' => $aid, ':shop' => $shop));
    $row = $stmt->fetch();
    $template_name_sms = $row['template_name'];
    if (!$row) {
        echo json_encode(array('success' => false, 'message' => 'Template not found for Fulfillment Request (aid=6)'));
        exit;
    }
    
    $sms_text = $row['sms'];
 
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
    
        $sms_enabled = isset($row['sms_enabled']) ? (int)$row['sms_enabled'] : 0;
    $whatsapp_enabled = isset($row['whatsapp_enabled']) ? (int)$row['whatsapp_enabled'] : 0;
    $has_parameters = (count($parameter_values) > 0);
    
    if (empty($processed_sms_text) || empty($sms_text)) {
        $processed_sms_text = "Your order {$order_name} has been shipped! Tracking number: {$tracking_number}. Track your order: {$tracking_url}";
    }

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
    
} catch (Exception $e) {
    echo json_encode(array('success' => false, 'message' => $e->getMessage()));
}
$whatsapp_data_from_db = array();
if (isset($row['whatsapp']) && !empty($row['whatsapp'])) {
    $whatsapp_data_from_db = json_decode($row['whatsapp'], true);
    if (!is_array($whatsapp_data_from_db)) {
        $whatsapp_data_from_db = array();
    }
}

$whatsapp_template_name = isset($whatsapp_data_from_db['template_name']) ? $whatsapp_data_from_db['template_name'] : '';

if ($whatsapp_enabled == 1 && !empty($whatsapp_template_name)) {
    error_log("Processing WhatsApp for fulfillment request test - Template: $whatsapp_template_name");
    
    $whatsapp_api_config = array(
        // 'api_domain' => 'https://your-api-domain.com',
        // 'channel_id' => 'your_channel_id',
        // 'api_key' => 'your_api_key',
        'log_file' => __DIR__ . '/../file/debug_log.txt'
    );
    $whatsapp_table2 = $prefix . "shopify_sms_notification_App_Log_Details";
    
    $replacement_map = array(
        'order_name' => $order_name,
        'Ad_order_number' => $order_id,
        'fulfillment_id' => $fulfillment_id,
        'fulfillment_status' => $fulfillment_status,
        'tracking_number' => $tracking_number,
        'tracking_url' => $tracking_url,
        'tracking_company' => $tracking_company,
        'fulfillment_created_at' => $fulfillment_created_at,
        'fulfillment_updated_at' => $fulfillment_updated_at,
        'customer_fname' => $customer_fname,
        'customer_lname' => $customer_lname,
        'customer_full_name' => $customer_full_name,
        'customer_email_id' => $customer_email_id,
        'customer_phone' => $final_phone_number,
        'country_code' => $final_country_code,
        'shipping_address' => $shipping_string,
        'shipping_city' => $shipping_city,
        'shipping_state' => $shipping_state,
        'shipping_zip' => $shipping_zip,
        'shipping_country' => $shipping_country,
        'Ad_shipping_address' => $shipping_string,
        'Ad_billing_address' => $billing_string,
        'item_name' => $item_name,
        'Ad_item_price' => $item_price,
        'Ad_item_quantity' => $item_quantity,
        'Ad_item_sku' => $item_sku,
        'Ad_item_vendor' => $item_vendor,
        'Ad_total_discount' => $total_discount,
        'Ad_total_weight' => $total_weight,
        'Ad_order_status_url' => $order_status_url,
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
    $wa_phone = "$country_code_input"."$final_phone_number";
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
        'notification_type' => $notification_type
    ));
    
    if (empty($final_phone_number)) {
        error_log("ERROR: Customer phone is empty, cannot send WhatsApp for fulfillment request test");
    } else {
        if (function_exists('send_whatsapp_message')) {
            $whatsapp_result = send_whatsapp_message($whatsapp_config, $pdo, $whatsapp_table2);
            
            if ($whatsapp_result['success']) {
                error_log("WhatsApp sent successfully for fulfillment request test to {$final_phone_number}");
            } else {
                error_log("WhatsApp failed for fulfillment request test: " . $whatsapp_result['message']);
            }
        } else {
            error_log("WhatsApp function send_whatsapp_message not found");
        }
    }
} else {
    error_log("No WhatsApp template configured for aid=6, skipping WhatsApp send");
}
?>