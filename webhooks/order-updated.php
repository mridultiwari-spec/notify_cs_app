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
require_once '../config/db.php';
require_once __DIR__ . '/../app_config.php';
include '../accurate_country_code.php';
include './../send_sms_api.php';
include '../send_whatsapp_message_api.php';
require_once '../dynmc_prod_img.php';

$logFile = dirname(__FILE__) . '/../file/order_edited_log.txt';
$data = file_get_contents("php://input");

$order_edited = json_decode($data);

if (!$order_edited) {
    set_http_status(200);
    exit;
}

$order_status_url_full = isset($order_edited->order_status_url) ? $order_edited->order_status_url : '';
$shop = parse_url($order_status_url_full, PHP_URL_HOST);

if (!$shop) {
    file_put_contents("$logFile", "ERROR: Could not determine shop\n\n", FILE_APPEND);
    set_http_status(200);
    exit;
}

$pdo = getDatabaseConnection();
$prefix = $app_prefix;
$tables = $prefix . "shopify_sms_notification_app";
$stmt = $pdo->prepare("SELECT shop, session_access_token AS oauth_token FROM $tables WHERE shop = :shop");
$stmt->execute(array(':shop' => $shop));
$shopRow = $stmt->fetch();

if (!$shopRow) {
    file_put_contents("$logFile", "ERROR: Shop not found in DB: $shop\n\n", FILE_APPEND);
    set_http_status(200);
    exit;
}

$oauth_token = $shopRow['oauth_token'];

$order_id = isset($order_edited->id) ? $order_edited->id : '';
$email = isset($order_edited->email) ? $order_edited->email : '';

file_put_contents("$logFile", "Order ID: $order_id | Email: $email\n\n", FILE_APPEND);

$total_discount = isset($order_edited->total_discounts) ? $order_edited->total_discounts : '';

$order_status_url = $order_status_url_full;
$parts = explode('orders/', $order_status_url);
$order_status_url = isset($parts[1]) ? $parts[1] : '';

$total_weight = isset($order_edited->total_weight) ? $order_edited->total_weight : 0;

$billing = isset($order_edited->billing_address) ? $order_edited->billing_address : null;
$shipping = isset($order_edited->shipping_address) ? $order_edited->shipping_address : null;

$billing_string = $billing ? implode(', ', array_filter(array(
    isset($billing->first_name) ? $billing->first_name : '',
    isset($billing->last_name) ? $billing->last_name : '',
    isset($billing->address1) ? $billing->address1 : '',
    isset($billing->address2) ? $billing->address2 : '',
    isset($billing->company) ? $billing->company : '',
    isset($billing->city) ? $billing->city : '',
    isset($billing->province) ? $billing->province : '',
    isset($billing->zip) ? $billing->zip : '',
    isset($billing->country) ? $billing->country : '',
    isset($billing->phone) ? $billing->phone : ''
))) : '';

$shipping_string = $shipping ? implode(', ', array_filter(array(
    isset($shipping->first_name) ? $shipping->first_name : '',
    isset($shipping->last_name) ? $shipping->last_name : '',
    isset($shipping->address1) ? $shipping->address1 : '',
    isset($shipping->address2) ? $shipping->address2 : '',
    isset($shipping->company) ? $shipping->company : '',
    isset($shipping->city) ? $shipping->city : '',
    isset($shipping->province) ? $shipping->province : '',
    isset($shipping->zip) ? $shipping->zip : '',
    isset($shipping->country) ? $shipping->country : '',
    isset($shipping->phone) ? $shipping->phone : ''
))) : '';

$order_name = isset($order_edited->name) ? $order_edited->name : '';

$customer = isset($order_edited->customer) ? $order_edited->customer : null;
$customer_fname = ($customer && isset($customer->first_name)) ? $customer->first_name : '';
$customer_lname = ($customer && isset($customer->last_name)) ? $customer->last_name : '';
$customer_full_name = trim($customer_fname . ' ' . $customer_lname);
$customer_email_id = isset($order_edited->contact_email) ? $order_edited->contact_email : '';

$customer_shipping_phone = ($shipping && isset($shipping->phone)) ? $shipping->phone : '';
$customer_billing_phone = ($billing && isset($billing->phone)) ? $billing->phone : '';
$customer_root_phone = isset($order_edited->phone) ? $order_edited->phone : '';
$shipping_country_code = ($shipping && isset($shipping->country_code)) ? $shipping->country_code : '';
$billing_country_code = ($billing && isset($billing->country_code)) ? $billing->country_code : '';

if ($shipping_country_code) {
    $country_code = strtoupper($shipping_country_code);
} elseif ($billing_country_code) {
    $country_code = strtoupper($billing_country_code);
} else {
    $country_code = 'IN';
}

$customer_details_phone = ($customer && isset($customer->phone)) ? $customer->phone : '';
$customer_order_phone = isset($order_edited->phone) ? $order_edited->phone : '';

if ($customer_shipping_phone) {
    $customer_phone = $customer_shipping_phone;
} elseif ($customer_billing_phone) {
    $customer_phone = $customer_billing_phone;
} elseif ($customer_details_phone) {
    $customer_phone = $customer_details_phone;
} elseif ($customer_order_phone) {
    $customer_phone = $customer_order_phone;
} elseif ($customer_root_phone) {
    $customer_phone = $customer_root_phone;
} else {
    $customer_phone = '';
}

if ($country_code && $customer_phone) {
    $final_arr = getCountryCode_and_phone_number($country_code, $customer_phone);
    $country_code = $final_arr['country_code'];
    $customer_phone = $final_arr['phone_number'];
}

$item_name_arr = array();
$item_price_arr = array();
$item_quantity_arr = array();
$item_sku_arr = array();
$item_vendor_arr = array();

if (isset($order_edited->line_items) && is_array($order_edited->line_items)) {
    foreach ($order_edited->line_items as $value) {
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

$order_total_price = isset($order_edited->total_price) ? $order_edited->total_price : '';

date_default_timezone_set("Asia/Kolkata");
$current_DateTime = date('Y-m-d H:i:s');
$finalDateTime = $current_DateTime;

$subject = ucwords("Order Updated");
$notification_type = "Order Edited";

$prefix = $app_prefix;
$table = $prefix . "shopify_sms_notification_App_Email_Notification";
$stmt = $pdo->prepare("SELECT * FROM $table WHERE aid = 2 AND shop = :shop");
$stmt->execute(array(':shop' => $shop));
$row = $stmt->fetch();

if ($row) {
    $sms_enabled = isset($row['sms_enabled']) ? (int) $row['sms_enabled'] : 0;
    if ($sms_enabled) {

        $sms_text = $row['sms'];
        $whatsapp_text = $row['whatsapp'];
        $media_type = $row['media_type'];
        $button_type = $row['button_type'];
        $media_url = $row['media_url'];
        $media_source_prod = $row['media_source'];
        $button_text1 = trim($row['button_text1']);
        $template_name_sms = $row['template_name'];
        $sms_variables = array();
        $has_parameters = false;

        if (isset($row['sms_variables']) && !empty($row['sms_variables'])) {
            $sms_variables = json_decode($row['sms_variables'], true);
            if (!is_array($sms_variables)) {
                $sms_variables = array();
            }

            // Check if sms_variables has any key-value pairs
            if (count($sms_variables) > 0) {
                $has_parameters = true;
                file_put_contents("$logFile", "SMS Variables loaded (has parameters): " . print_r($sms_variables, true) . "\n", FILE_APPEND);
            } else {
                file_put_contents("$logFile", "SMS Variables is empty array\n", FILE_APPEND);
            }
        } else {
            file_put_contents("$logFile", "SMS Variables is NULL or empty\n", FILE_APPEND);
        }

        // Build base replacement map for standard variables
        $replacementMap = array(
            "{{ order_name }}" => $order_name,
            "{{ Ad_order_number }}" => $order_id,
            "{{ order_total_price }}" => $order_total_price,
            "{{ customer_fname }}" => $customer_fname,
            "{{ customer_lname }}" => $customer_lname,
            "{{ customer_email_id }}" => $customer_email_id,
            "{{ country_code }}" => $country_code,
            "{{ customer_phone }}" => $customer_phone,
            "{{ customer_full_name }}" => $customer_full_name,
            "{{ Ad_total_discount }}" => $total_discount,
            "{{ Ad_order_status_url }}" => $order_status_url,
            "{{ Ad_item_price }}" => $item_price,
            "{{ Ad_item_quantity }}" => $item_quantity,
            "{{ Ad_item_sku }}" => $item_sku,
            "{{ Ad_item_vendor }}" => $item_vendor,
            "{{ Ad_total_weight }}" => $total_weight,
            "{{ Ad_shipping_address }}" => $shipping_string,
            "{{ Ad_billing_address }}" => $billing_string,
            "{{ item_name }}" => $item_name
        );

        // If we have parameters (sms_variables is not empty), use template-based SMS
        if ($has_parameters) {
            file_put_contents("$logFile", "Using TEMPLATE-BASED SMS with parameters\n", FILE_APPEND);

            // Process custom variables and add to replacement map
            foreach ($sms_variables as $key => $value) {
                $placeholder = "{{ " . trim($key) . " }}";

                // Process the value to replace any placeholders it might contain
                $processed_value = $value;

                // First replace standard placeholders
                foreach ($replacementMap as $ph => $val) {
                    if (strpos($processed_value, $ph) !== false && $val !== null) {
                        $processed_value = str_replace($ph, $val, $processed_value);
                    }
                }

                // Process any nested custom variables
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
                file_put_contents("$logFile", "Added custom variable: $placeholder => $processed_value\n", FILE_APPEND);
            }

            // Process sms_text with variable replacements
            $processed_sms_text = $sms_text;

            // Replace standard placeholders in sms_text
            foreach ($replacementMap as $placeholder => $value) {
                if ($value !== null && strpos($processed_sms_text, $placeholder) !== false) {
                    $processed_sms_text = str_replace($placeholder, $value, $processed_sms_text);
                }
            }

            // Also replace custom variable placeholders in sms_text
            foreach ($sms_variables as $key => $value) {
                $placeholder = "{{ " . trim($key) . " }}";
                if (isset($replacementMap[$placeholder]) && strpos($processed_sms_text, $placeholder) !== false) {
                    $processed_sms_text = str_replace($placeholder, $replacementMap[$placeholder], $processed_sms_text);
                }
            }

            file_put_contents("$logFile", "Original SMS Text: " . $sms_text . "\n", FILE_APPEND);
            file_put_contents("$logFile", "Processed SMS Text: " . $processed_sms_text . "\n", FILE_APPEND);

            // Build parameter values array for template SMS
            $parameter_values = array();
            foreach ($sms_variables as $key => $value) {
                $placeholder = "{{ " . trim($key) . " }}";
                if (isset($replacementMap[$placeholder])) {
                    $parameter_values[$key] = $replacementMap[$placeholder];
                } else {
                    $parameter_values[$key] = $value;
                }
            }

            // Use the ORIGINAL sms_text as template ID (not the processed one)
            $template_id = $template_name_sms;

            file_put_contents("$logFile", "Parameter Values for template: " . json_encode($parameter_values) . "\n", FILE_APPEND);
            file_put_contents("$logFile", "Template ID: " . $template_id . "\n", FILE_APPEND);

            // Process button text and media URL with replacements
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

            if ($media_source_prod == 'dynamic' && $media_type == 'image') {
                $product_id = null;
                if (isset($order_edited->line_items) && !empty($order_edited->line_items)) {
                    if (isset($order_edited->line_items[0]->product_id)) {
                        $product_id = $order_edited->line_items[0]->product_id;
                    }
                }
                if ($product_id) {
                    $aid = 2;
                    if (function_exists('fetchProductImageAndUpdateDb')) {
                        $result = fetchProductImageAndUpdateDb($pdo, $shop, $oauth_token, $product_id, $aid, $media_source_prod, $prefix);
                        if ($result['success']) {
                            $media_url = $result['media_url'];
                            file_put_contents("$logFile", "Dynamic product image fetched: $media_url\n", FILE_APPEND);
                        } else {
                            error_log("Image fetch error: " . $result['message']);
                        }
                    }
                } else {
                    error_log("No product ID found for shop: {$shop}");
                }
            }

            // Send SMS using template function with processed SMS text
            if (empty($customer_phone)) {
                file_put_contents("$logFile", "ERROR: Customer phone is empty, cannot send SMS for order {$order_id}\n", FILE_APPEND);
            } elseif (empty($template_id)) {
                file_put_contents("$logFile", "ERROR: Template ID is empty, cannot send template SMS for order {$order_id}\n", FILE_APPEND);
            } else {
                send_smstext_with_parameters(
                    $country_code,
                    $customer_phone,
                    $template_id,
                    $shop,
                    $customer_email_id,
                    $customer_full_name,
                    $parameter_values,
                    $order_id,
                    $order_name,
                    $notification_type
                );
                file_put_contents("$logFile", "Template SMS sent successfully for order update {$order_id} to {$customer_phone}\n", FILE_APPEND);
                file_put_contents("$logFile", "SMS Text with replacements: {$processed_sms_text}\n", FILE_APPEND);
            }

        } else {
            // No parameters - use plain text SMS
            file_put_contents("$logFile", "Using PLAIN TEXT SMS (no parameters)\n", FILE_APPEND);

            $searchVal = array(
                "{{ order_name }}",
                "{{ Ad_order_number }}",
                "{{ order_total_price }}",
                "{{ customer_fname }}",
                "{{ customer_lname }}",
                "{{ customer_email_id }}",
                "{{ country_code }}",
                "{{ customer_phone }}",
                "{{ customer_full_name }}",
                "{{ Ad_total_discount }}",
                "{{ Ad_order_status_url }}",
                "{{ Ad_item_price }}",
                "{{ Ad_item_quantity }}",
                "{{ Ad_item_sku }}",
                "{{ Ad_item_vendor }}",
                "{{ Ad_total_weight }}",
                "{{ Ad_shipping_address }}",
                "{{ Ad_billing_address }}",
                "{{ item_name }}"
            );

            $replaceVal = array(
                $order_name,
                $order_id,
                $order_total_price,
                $customer_fname,
                $customer_lname,
                $customer_email_id,
                $country_code,
                $customer_phone,
                $customer_full_name,
                $total_discount,
                $order_status_url,
                $item_price,
                $item_quantity,
                $item_sku,
                $item_vendor,
                $total_weight,
                $shipping_string,
                $billing_string,
                $item_name
            );

            $final_text = str_replace($searchVal, $replaceVal, $sms_text);
            $final_text2 = str_replace($searchVal, $replaceVal, $whatsapp_text);

            if (empty($final_text) || empty($sms_text)) {
                $final_text = "Your order {$order_name} has been updated. Track your order: {$order_status_url}";
                file_put_contents("$logFile", "WARNING: Using fallback SMS text for order update\n", FILE_APPEND);
            }

            $searchReplaceMap = array(
                '{{ order_name }}' => isset($order_name) ? $order_name : null,
                '{{ Ad_order_number }}' => isset($order_id) ? $order_id : null,
                '{{ order_total_price }}' => isset($order_total_price) ? $order_total_price : null,
                '{{ customer_fname }}' => isset($customer_fname) ? $customer_fname : null,
                '{{ customer_lname }}' => isset($customer_lname) ? $customer_lname : null,
                '{{ customer_email_id }}' => isset($customer_email_id) ? $customer_email_id : null,
                '{{ country_code }}' => isset($country_code) ? $country_code : null,
                '{{ customer_phone }}' => isset($customer_phone) ? $customer_phone : null,
                '{{ customer_full_name }}' => isset($customer_full_name) ? $customer_full_name : null,
                '{{ Ad_total_discount }}' => isset($total_discount) ? $total_discount : null,
                '{{ Ad_order_status_url }}' => isset($order_status_url) ? $order_status_url : null,
                '{{ Ad_item_price }}' => isset($item_price) ? $item_price : null,
                '{{ Ad_item_quantity }}' => isset($item_quantity) ? $item_quantity : null,
                '{{ Ad_item_sku }}' => isset($item_sku) ? $item_sku : null,
                '{{ Ad_item_vendor }}' => isset($item_vendor) ? $item_vendor : null,
                '{{ Ad_total_weight }}' => isset($total_weight) ? $total_weight : null,
                '{{ Ad_shipping_address }}' => isset($shipping_string) ? $shipping_string : null,
                '{{ Ad_billing_address }}' => isset($billing_string) ? $billing_string : null,
                '{{ item_name }}' => isset($item_name) ? $item_name : null,
            );

            foreach ($searchReplaceMap as $placeholder => $value) {
                if ($value !== null) {
                    if (strpos($button_text1, $placeholder) !== false) {
                        $button_text1 = str_replace($placeholder, $value, $button_text1);
                    }
                    if (strpos($media_url, $placeholder) !== false) {
                        $media_url = str_replace($placeholder, $value, $media_url);
                    }
                }
            }

            if ($media_source_prod == 'dynamic' && $media_type == 'image') {
                $product_id = null;
                if (isset($order_edited->line_items) && !empty($order_edited->line_items)) {
                    if (isset($order_edited->line_items[0]->product_id)) {
                        $product_id = $order_edited->line_items[0]->product_id;
                    }
                }
                if ($product_id) {
                    $aid = 2;
                    if (function_exists('fetchProductImageAndUpdateDb')) {
                        $result = fetchProductImageAndUpdateDb($pdo, $shop, $oauth_token, $product_id, $aid, $media_source_prod, $prefix);
                        if ($result['success']) {
                            $media_url = $result['media_url'];
                            file_put_contents("$logFile", "Dynamic product image fetched: $media_url\n", FILE_APPEND);
                        } else {
                            error_log("Image fetch error: " . $result['message']);
                        }
                    }
                } else {
                    error_log("No product ID found for shop: {$shop}");
                }
            }
            if (empty($customer_phone)) {
                file_put_contents("$logFile", "ERROR: Customer phone is empty, cannot send SMS for order {$order_id}\n", FILE_APPEND);
            } elseif (empty($final_text)) {
                file_put_contents("$logFile", "ERROR: SMS text is empty, cannot send SMS for order {$order_id}\n", FILE_APPEND);
            } else {
                send_smstext($country_code, $customer_phone, $template_name_sms, $shop, $customer_email_id, $customer_full_name, $order_id, $order_name, $notification_type);
                file_put_contents("$logFile", "Plain text SMS sent successfully for order update {$order_id} to {$customer_phone}\n", FILE_APPEND);
            }
        }

    }
} else {
    file_put_contents("$logFile", "WARNING: No enabled SMS template found for aid=2 and shop={$shop}\n", FILE_APPEND);
}

file_put_contents(
    "$logFile",
    "Discount: $total_discount\nBilling: $billing_string\nShipping: $shipping_string\nOrder_Status_Url: $order_status_url\n\n",
    FILE_APPEND
);

set_http_status(200);

// ========== WHATSAPP MESSAGING FOR ORDER EDITED ==========
// Check if WhatsApp template exists and is configured
$whatsapp_data_from_db = array();
if (isset($row['whatsapp']) && !empty($row['whatsapp'])) {
    $whatsapp_data_from_db = json_decode($row['whatsapp'], true);
    if (!is_array($whatsapp_data_from_db)) {
        $whatsapp_data_from_db = array();
    }
}

$whatsapp_template_name = isset($whatsapp_data_from_db['template_name']) ? $whatsapp_data_from_db['template_name'] : '';
$whatsapp_enabled = isset($row['whatsapp_enabled']) ? (int) $row['whatsapp_enabled'] : 0;
// Only proceed if a WhatsApp template is configured
if ($whatsapp_enabled == 1 && !empty($whatsapp_template_name)) {
    file_put_contents("$logFile", "Processing WhatsApp for order edited - Template: $whatsapp_template_name\n", FILE_APPEND);

    // API Configuration - Replace with your actual values
    $whatsapp_api_config = array(
        // 'api_domain' => 'https://your-api-domain.com',  // Replace with actual API domain
        // 'channel_id' => 'your_channel_id',              // Replace with actual channel ID
        // 'api_key' => 'your_api_key',                    // Replace with actual API key
        'log_file' => "$logFile"
    );

    // Table for logging
    $table2 = $prefix . "shopify_sms_notification_App_Log_Details";

    // ========== PREPARE VARIABLE REPLACEMENTS ==========
    $replacement_map = array(
        'order_name' => $order_name,
        'order_total_price' => $order_total_price,
        'customer_fname' => $customer_fname,
        'customer_lname' => $customer_lname,
        'customer_email_id' => $customer_email_id,
        'country_code' => $country_code,
        'customer_phone' => $customer_phone,
        'item_name' => $item_name,
        'customer_full_name' => $customer_full_name,
        'Ad_order_number' => $order_id,
        'Ad_total_discount' => $total_discount,
        'Ad_order_status_url' => $order_status_url,
        'Ad_item_price' => $item_price,
        'Ad_item_quantity' => $item_quantity,
        'Ad_item_sku' => $item_sku,
        'Ad_item_vendor' => $item_vendor,
        'Ad_total_weight' => $total_weight,
        'Ad_shipping_address' => $shipping_string,
        'Ad_billing_address' => $billing_string,
        'order_id' => $order_id
    );

    // ========== PROCESS HEADER VARIABLES ==========
    $processed_headers = array();
    $header_variables = isset($whatsapp_data_from_db['variable_headers']) ? $whatsapp_data_from_db['variable_headers'] : array();

    if (!empty($header_variables)) {
        foreach ($header_variables as $header_var) {
            $processed_value = wa_replace_placeholders($header_var, $replacement_map);
            $processed_headers[] = $processed_value;
            file_put_contents("$logFile", "Header variable: '$header_var' -> '$processed_value'\n", FILE_APPEND);
        }
    }

    // ========== PROCESS BODY VARIABLES ==========
    $processed_body = array();
    $body_variables = isset($whatsapp_data_from_db['variable_body']) ? $whatsapp_data_from_db['variable_body'] : array();

    if (!empty($body_variables)) {
        foreach ($body_variables as $body_var) {
            $processed_value = wa_replace_placeholders($body_var, $replacement_map);
            $processed_body[] = $processed_value;
            file_put_contents("$logFile", "Body variable: '$body_var' -> '$processed_value'\n", FILE_APPEND);
        }
    }

    // ========== PROCESS MEDIA URL ==========
    $whatsapp_media_url = isset($row['media_url']) ? $row['media_url'] : '';
    $whatsapp_media_source = isset($row['media_source']) ? $row['media_source'] : '';
    $whatsapp_media_type = isset($row['media_type']) ? $row['media_type'] : 'text';

    // Replace placeholders in media URL
    if (!empty($whatsapp_media_url)) {
        $whatsapp_media_url = wa_replace_placeholders($whatsapp_media_url, $replacement_map);
        file_put_contents("$logFile", "Media URL after replacement: $whatsapp_media_url\n", FILE_APPEND);
    }

    // Handle dynamic product image
    if ($whatsapp_media_source == 'dynamic' && $whatsapp_media_type == 'image') {
        $product_id = null;
        if (isset($order_edited->line_items) && !empty($order_edited->line_items)) {
            if (isset($order_edited->line_items[0]->product_id)) {
                $product_id = $order_edited->line_items[0]->product_id;
            }
        }
        if ($product_id) {
            $aid = 2;
            if (function_exists('fetchProductImageAndUpdateDb')) {
                $result = fetchProductImageAndUpdateDb($pdo, $shop, $oauth_token, $product_id, $aid, $whatsapp_media_source, $prefix);
                if ($result['success']) {
                    $whatsapp_media_url = $result['media_url'];
                    file_put_contents("$logFile", "Dynamic product image fetched for WhatsApp: $whatsapp_media_url\n", FILE_APPEND);
                } else {
                    file_put_contents("$logFile", "Image fetch error for WhatsApp: " . $result['message'] . "\n", FILE_APPEND);
                }
            }
        }
    }

    // ========== PROCESS BUTTONS ==========
    $buttons = array();

    // Decode CTA URLs
    $cta_urls = array();
    if (isset($row['cta_url']) && !empty($row['cta_url'])) {
        $cta_urls = json_decode($row['cta_url'], true);
        if (!is_array($cta_urls)) {
            $cta_urls = array();
        }
    }

    // Button 1
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
        file_put_contents("$logFile", "WhatsApp Button 1: type=$btn1_type, text=$btn1_text\n", FILE_APPEND);
    }

    // Button 2
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
        file_put_contents("$logFile", "WhatsApp Button 2: type=$btn2_type, text=$btn2_text\n", FILE_APPEND);
    }

    // Button 3
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
        file_put_contents("$logFile", "WhatsApp Button 3: type=$btn3_type, text=$btn3_text\n", FILE_APPEND);
    }

    // ========== BUILD WHATSAPP CONFIG ==========
    $whatsapp_config = array_merge($whatsapp_api_config, array(
        'to' =>  $country_code . $customer_phone,
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
        'country_code' => $country_code,
        'phone_num' => $customer_phone,
        'notification_type' => 'Order Edited'
    ));

    // ========== SEND WHATSAPP MESSAGE ==========
    if (empty($customer_phone)) {
        file_put_contents("$logFile", "ERROR: Customer phone is empty, cannot send WhatsApp for order {$order_id}\n", FILE_APPEND);
    } else {
        $whatsapp_result = send_whatsapp_message($whatsapp_config, $pdo, $table2);

        if ($whatsapp_result['success']) {
            file_put_contents("$logFile", "WhatsApp sent successfully for order edited {$order_id} to {$customer_phone}\n", FILE_APPEND);
        } else {
            file_put_contents("$logFile", "WhatsApp failed for order {$order_id}: " . $whatsapp_result['message'] . "\n", FILE_APPEND);
        }
    }
} else {
    file_put_contents("$logFile", "No WhatsApp template configured for aid=2, skipping WhatsApp send\n", FILE_APPEND);
}
?>