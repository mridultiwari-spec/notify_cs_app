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
include '../send_sms_api.php';
include '../send_whatsapp_message_api.php';
require_once '../dynmc_prod_img.php';

$data = file_get_contents("php://input");
//file_put_contents("$logFile", date('Y-m-d H:i:s') . "\n" . $data . "\n\n", FILE_APPEND);

$fulfillment = json_decode($data);

if (!$fulfillment) {
    set_http_status(200);
    exit;
}

$shop = isset($_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN']) ? $_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] : '';

if (!$shop) {
    file_put_contents("$logFile", "ERROR: Could not determine shop from header\n\n", FILE_APPEND);
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

$fulfillment_id = isset($fulfillment->id) ? $fulfillment->id : '';
$order_id = isset($fulfillment->order_id) ? $fulfillment->order_id : '';
$email = isset($fulfillment->email) ? $fulfillment->email : '';

file_put_contents("$logFile", "Fulfillment ID: $fulfillment_id | Order ID: $order_id | Email: $email\n\n", FILE_APPEND);

$order_name = isset($fulfillment->name) ? $fulfillment->name : '';

$tracking_number = isset($fulfillment->tracking_number) ? $fulfillment->tracking_number : '';
$tracking_url = isset($fulfillment->tracking_url) ? $fulfillment->tracking_url : '';

$order_status_url = '';
//$total_discount = '';
$total_weight = 0;
$total_discount = isset($fulfillment->line_items[0]->total_discount) ? $fulfillment->line_items[0]->total_discount : '0.00';
$destination = isset($fulfillment->destination) ? $fulfillment->destination : null;

$shipping_string = $destination ? implode(', ', array_filter(array(
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
))) : '';

$billing_string = '';

$shipping_city = ($destination && isset($destination->city)) ? $destination->city : '';

$customer_fname = ($destination && isset($destination->first_name)) ? $destination->first_name : '';
$customer_lname = ($destination && isset($destination->last_name)) ? $destination->last_name : '';
$customer_full_name = trim($customer_fname . ' ' . $customer_lname);
$customer_email_id = isset($fulfillment->email) ? $fulfillment->email : '';

$destination_phone = ($destination && isset($destination->phone)) ? $destination->phone : '';
$destination_country_code = ($destination && isset($destination->country_code)) ? $destination->country_code : '';

$country_code = $destination_country_code ? strtoupper($destination_country_code) : '';
$customer_phone = $destination_phone ? $destination_phone : '';

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

date_default_timezone_set("Asia/Kolkata");
$current_DateTime = date('Y-m-d H:i:s');
$finalDateTime = $current_DateTime;
$subject = ucwords("Shipping Update");

$prefix = $app_prefix;
$table = $prefix . "shopify_sms_notification_App_Email_Notification";
$stmt = $pdo->prepare("SELECT * FROM $table WHERE aid = 8 AND shop = :shop");
$stmt->execute(array(':shop' => $shop));
$row = $stmt->fetch();

if ($row) {
    $sms_enabled = isset($row['sms_enabled']) ? (int) $row['sms_enabled'] : 0;
    if ($sms_enabled == 1) {

        $sms_text = $row['sms'];
        $whatsapp_text = $row['whatsapp'];
        $media_type = $row['media_type'];
        $button_type = $row['button_type'];
        $media_url = $row['media_url'];
        $media_source_prod = $row['media_source'];
        $button_text1 = trim($row['button_text1']);
        $template_name_sms = $row['template_name'];
        // Fetch and decode sms_variables
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
            "{{ tracking_number }}" => $tracking_number,
            "{{ tracking_url }}" => $tracking_url,
            "{{ customer_fname }}" => $customer_fname,
            "{{ customer_lname }}" => $customer_lname,
            "{{ customer_email_id }}" => $customer_email_id,
            "{{ country_code }}" => $country_code,
            "{{ customer_phone }}" => $customer_phone,
            "{{ customer_full_name }}" => $customer_full_name,
            "{{ shipping_address }}" => $shipping_string,
            "{{ shipping_city }}" => $shipping_city,
            "{{ Ad_total_discount }}" => $total_discount,
            "{{ order_status_url }}" => $order_status_url,
            "{{ Ad_item_price }}" => $item_price,
            "{{ Ad_item_quantity }}" => $item_quantity,
            "{{ Ad_item_sku }}" => $item_sku,
            "{{ Ad_item_vendor }}" => $item_vendor,
            "{{ Ad_total_weight }}" => $total_weight,
            "{{ Ad_shipping_address }}" => $shipping_string,
            "{{ Ad_billing_address }}" => $billing_string,
            "{{ fulfillment_id }}" => $fulfillment_id,
            "{{ fulfillment_status }}" => isset($fulfillment->status) ? $fulfillment->status : ''
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

            // Process whatsapp_text similarly
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

            file_put_contents("$logFile", "========== FULFILLMENT SMS ==========\n", FILE_APPEND);
            file_put_contents("$logFile", "Original SMS Template: $sms_text\n", FILE_APPEND);
            file_put_contents("$logFile", "Processed SMS Text: $processed_sms_text\n", FILE_APPEND);
            file_put_contents("$logFile", "Phone: $customer_phone, Country: $country_code\n", FILE_APPEND);
            file_put_contents("$logFile", "Tracking: $tracking_number\n", FILE_APPEND);
            file_put_contents("$logFile", "====================================\n\n", FILE_APPEND);

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

            // Use the ORIGINAL sms_text as template ID
            $template_id = $template_name_sms;

            file_put_contents("$logFile", "Parameter Values for template: " . json_encode($parameter_values) . "\n", FILE_APPEND);
            file_put_contents("$logFile", "Template ID: " . $template_id . "\n", FILE_APPEND);

            // Process button text and media URL with replacements
            foreach ($replacementMap as $placeholder => $value) {
                if ($value !== null && $value !== '') {
                    if (strpos($button_text1, $placeholder) !== false) {
                        $button_text1 = str_replace($placeholder, $value, $button_text1);
                        file_put_contents("$logFile", "Replaced $placeholder in button text: $button_text1\n", FILE_APPEND);
                    }
                    if (strpos($media_url, $placeholder) !== false) {
                        $media_url = str_replace($placeholder, $value, $media_url);
                        file_put_contents("$logFile", "Replaced $placeholder in media URL: $media_url\n", FILE_APPEND);
                    }
                }
            }

            if ($media_source_prod == 'dynamic' && $media_type == 'image') {
                $product_id = null;
                if (isset($fulfillment->line_items) && !empty($fulfillment->line_items)) {
                    if (isset($fulfillment->line_items[0]->product_id)) {
                        $product_id = $fulfillment->line_items[0]->product_id;
                    }
                }
                if ($product_id) {
                    $aid = 8;
                    $result = fetchProductImageAndUpdateDb($pdo, $shop, $oauth_token, $product_id, $aid, $whatsapp_media_source, $prefix);
                    if ($result['success']) {
                        $media_url = $result['media_url'];
                        file_put_contents("$logFile", "Dynamic product image fetched: $media_url\n", FILE_APPEND);
                    } else {
                        error_log("Image fetch error: " . $result['message']);
                    }
                } else {
                    error_log("No product ID found for shop: {$shop}");
                }
            }

            // Send SMS using template function with processed SMS text
            if (empty($customer_phone)) {
                file_put_contents("$logFile", "ERROR: Customer phone is empty, cannot send SMS for fulfillment {$fulfillment_id}\n", FILE_APPEND);
            } elseif (empty($template_name_sms)) {
                file_put_contents("$logFile", "ERROR: Template Name is empty, cannot send template SMS for fulfillment {$fulfillment_id}\n", FILE_APPEND);
            } else {
                // Check if send_smstext_with_parameters function exists
                if (function_exists('send_smstext_with_parameters')) {
                    send_smstext_with_parameters(
                        $country_code,
                        $customer_phone,
                        $template_name_sms,
                        $shop,
                        $customer_email_id,
                        $customer_full_name,
                        $parameter_values,
                        $order_id,
                        $order_name,
                        $subject
                    );
                    file_put_contents("$logFile", "Template SMS sent successfully for fulfillment {$fulfillment_id} to {$customer_phone}\n", FILE_APPEND);
                    file_put_contents("$logFile", "SMS Text with replacements: {$processed_sms_text}\n", FILE_APPEND);
                } else {
                    // Fallback to regular send_smstext
                    send_smstext($country_code, $customer_phone, $template_name_sms, $shop, $customer_email_id, $customer_full_name, $order_id, $order_name);
                    file_put_contents("$logFile", "Template SMS (fallback) sent successfully for fulfillment {$fulfillment_id} to {$customer_phone}\n", FILE_APPEND);
                }
            }

            // Send WhatsApp if applicable
            if (!empty($customer_phone) && !empty($processed_whatsapp_text)) {
                if (($media_type == 'image' || $media_type == 'video' || $media_type == 'pdf') && !empty($button_type)) {
                    if (function_exists('send_whatsapp_text_Plus_button')) {
                        send_whatsapp_text_Plus_button($country_code, $customer_phone, $processed_whatsapp_text, $shop, $customer_email_id, $customer_full_name, $order_id, $order_name, $subject, $media_type, $button_type, $media_url, $button_text1);
                        file_put_contents("$logFile", "WhatsApp with media sent successfully\n", FILE_APPEND);
                    }
                } elseif ($button_type == 'cta' || $button_type == 'static_cta') {
                    if (function_exists('send_whatsapp_text_Plus_button')) {
                        send_whatsapp_text_Plus_button($country_code, $customer_phone, $processed_whatsapp_text, $shop, $customer_email_id, $customer_full_name, $order_id, $order_name, $subject, $media_type, $button_type, $media_url, $button_text1);
                        file_put_contents("$logFile", "WhatsApp with CTA button sent successfully\n", FILE_APPEND);
                    }
                } else {
                    if (function_exists('send_whatsapp_text')) {
                        send_whatsapp_text($country_code, $customer_phone, $processed_whatsapp_text, $shop, $customer_email_id, $customer_full_name, $order_id, $order_name, $subject);
                        file_put_contents("$logFile", "WhatsApp sent successfully\n", FILE_APPEND);
                    }
                }
            }

            $final_text = $processed_sms_text;

        } else {
            // No parameters - use plain text SMS
            file_put_contents("$logFile", "Using PLAIN TEXT SMS (no parameters)\n", FILE_APPEND);

            $searchVal = array(
                "{{ order_name }}",
                "{{ Ad_order_number }}",
                "{{ tracking_number }}",
                "{{ tracking_url }}",
                "{{ customer_fname }}",
                "{{ customer_lname }}",
                "{{ customer_email_id }}",
                "{{ country_code }}",
                "{{ customer_phone }}",
                "{{ customer_full_name }}",
                "{{ shipping_address }}",
                "{{ shipping_city }}",
                "{{ Ad_total_discount }}",
                "{{ order_status_url }}",
                "{{ Ad_item_price }}",
                "{{ Ad_item_quantity }}",
                "{{ Ad_item_sku }}",
                "{{ Ad_item_vendor }}",
                "{{ Ad_total_weight }}",
                "{{ Ad_shipping_address }}",
                "{{ Ad_billing_address }}",
                "{{ fulfillment_id }}",
                "{{ fulfillment_status }}"
            );

            $replaceVal = array(
                $order_name,
                $order_id,
                $tracking_number,
                $tracking_url,
                $customer_fname,
                $customer_lname,
                $customer_email_id,
                $country_code,
                $customer_phone,
                $customer_full_name,
                $shipping_string,
                $shipping_city,
                $total_discount,
                $order_status_url,
                $item_price,
                $item_quantity,
                $item_sku,
                $item_vendor,
                $total_weight,
                $shipping_string,
                $billing_string,
                $fulfillment_id,
                isset($fulfillment->status) ? $fulfillment->status : ''
            );

            $final_text = str_replace($searchVal, $replaceVal, $sms_text);
            $final_text2 = str_replace($searchVal, $replaceVal, $whatsapp_text);

            file_put_contents("$logFile", "========== FULFILLMENT SMS ==========\n", FILE_APPEND);
            file_put_contents("$logFile", "Original SMS Template: $sms_text\n", FILE_APPEND);
            file_put_contents("$logFile", "Replaced SMS Text: $final_text\n", FILE_APPEND);
            file_put_contents("$logFile", "Phone: $customer_phone, Country: $country_code\n", FILE_APPEND);
            file_put_contents("$logFile", "Tracking: $tracking_number\n", FILE_APPEND);
            file_put_contents("$logFile", "====================================\n\n", FILE_APPEND);

            $allPlaceholders = array(
                '{{ order_name }}' => $order_name,
                '{{ Ad_order_number }}' => $order_id,
                '{{ tracking_number }}' => $tracking_number,
                '{{ tracking_url }}' => $tracking_url,
                '{{ customer_fname }}' => $customer_fname,
                '{{ customer_lname }}' => $customer_lname,
                '{{ customer_email_id }}' => $customer_email_id,
                '{{ country_code }}' => $country_code,
                '{{ customer_phone }}' => $customer_phone,
                '{{ customer_full_name }}' => $customer_full_name,
                '{{ shipping_address }}' => $shipping_string,
                '{{ shipping_city }}' => $shipping_city,
                '{{ Ad_total_discount }}' => $total_discount,
                '{{ order_status_url }}' => $order_status_url,
                '{{ Ad_item_price }}' => $item_price,
                '{{ Ad_item_quantity }}' => $item_quantity,
                '{{ Ad_item_sku }}' => $item_sku,
                '{{ Ad_item_vendor }}' => $item_vendor,
                '{{ Ad_total_weight }}' => $total_weight,
                '{{ Ad_shipping_address }}' => $shipping_string,
                '{{ Ad_billing_address }}' => $billing_string,
                '{{ fulfillment_id }}' => $fulfillment_id,
                '{{ fulfillment_status }}' => isset($fulfillment->status) ? $fulfillment->status : ''
            );

            foreach ($allPlaceholders as $placeholder => $value) {
                if ($value !== null && $value !== '') {
                    if (strpos($button_text1, $placeholder) !== false) {
                        $button_text1 = str_replace($placeholder, $value, $button_text1);
                        file_put_contents("$logFile", "Replaced $placeholder in button text: $button_text1\n", FILE_APPEND);
                    }
                    if (strpos($media_url, $placeholder) !== false) {
                        $media_url = str_replace($placeholder, $value, $media_url);
                        file_put_contents("$logFile", "Replaced $placeholder in media URL: $media_url\n", FILE_APPEND);
                    }
                }
            }

            if ($media_source_prod == 'dynamic' && $media_type == 'image') {
                $product_id = null;
                if (isset($fulfillment->line_items) && !empty($fulfillment->line_items)) {
                    if (isset($fulfillment->line_items[0]->product_id)) {
                        $product_id = $fulfillment->line_items[0]->product_id;
                    }
                }
                if ($product_id) {
                    $aid = 8;
                    $result = fetchProductImageAndUpdateDb($pdo, $shop, $oauth_token, $product_id, $aid, $whatsapp_media_source, $prefix);
                    if ($result['success']) {
                        $media_url = $result['media_url'];
                        file_put_contents("$logFile", "Dynamic product image fetched: $media_url\n", FILE_APPEND);
                    } else {
                        error_log("Image fetch error: " . $result['message']);
                    }
                } else {
                    error_log("No product ID found for shop: {$shop}");
                }
            }

            if (empty($customer_phone)) {
                file_put_contents("$logFile", "ERROR: Customer phone is empty, cannot send SMS for fulfillment {$fulfillment_id}\n", FILE_APPEND);
            } elseif (empty($final_text)) {
                file_put_contents("$logFile", "ERROR: SMS text is empty, cannot send SMS for fulfillment {$fulfillment_id}\n", FILE_APPEND);
            } else {
                send_smstext($country_code, $customer_phone, $template_name_sms, $shop, $customer_email_id, $customer_full_name, $order_id, $order_name);
                file_put_contents("$logFile", "SMS sent successfully for fulfillment {$fulfillment_id} to {$customer_phone}\n", FILE_APPEND);
            }

            if (!empty($customer_phone) && !empty($final_text2)) {
                if (($media_type == 'image' || $media_type == 'video' || $media_type == 'pdf') && !empty($button_type)) {
                    if (function_exists('send_whatsapp_text_Plus_button')) {
                        send_whatsapp_text_Plus_button($country_code, $customer_phone, $final_text2, $shop, $customer_email_id, $customer_full_name, $order_id, $order_name, $subject, $media_type, $button_type, $media_url, $button_text1);
                        file_put_contents("$logFile", "WhatsApp with media sent successfully\n", FILE_APPEND);
                    }
                } elseif ($button_type == 'cta' || $button_type == 'static_cta') {
                    if (function_exists('send_whatsapp_text_Plus_button')) {
                        send_whatsapp_text_Plus_button($country_code, $customer_phone, $final_text2, $shop, $customer_email_id, $customer_full_name, $order_id, $order_name, $subject, $media_type, $button_type, $media_url, $button_text1);
                        file_put_contents("$logFile", "WhatsApp with CTA button sent successfully\n", FILE_APPEND);
                    }
                } else {
                    if (function_exists('send_whatsapp_text')) {
                        send_whatsapp_text($country_code, $customer_phone, $final_text2, $shop, $customer_email_id, $customer_full_name, $order_id, $order_name, $subject);
                        file_put_contents("$logFile", "WhatsApp sent successfully\n", FILE_APPEND);
                    }
                }
            }
        }

    }
} else {
    file_put_contents("$logFile", "WARNING: No SMS template found for aid=8 and shop={$shop}\n", FILE_APPEND);
}

file_put_contents(
    "$logFile",
    "Fulfillment Processed - ID: $fulfillment_id\nOrder ID: $order_id\nTracking: $tracking_number\nTracking URL: $tracking_url\nCustomer Phone: $customer_phone\nCountry Code: $country_code\nSMS Text: " . (isset($final_text) ? $final_text : '') . "\n\n",
    FILE_APPEND
);

set_http_status(200);

$whatsapp_data_from_db = array();
if (isset($row['whatsapp']) && !empty($row['whatsapp'])) {
    $whatsapp_data_from_db = json_decode($row['whatsapp'], true);
    if (!is_array($whatsapp_data_from_db)) {
        $whatsapp_data_from_db = array();
    }
}

$whatsapp_template_name = isset($whatsapp_data_from_db['template_name']) ? $whatsapp_data_from_db['template_name'] : '';
$whatsapp_enabled = isset($row['whatsapp_enabled']) ? (int) $row['whatsapp_enabled'] : 0;
if ($whatsapp_enabled == 1 && !empty($whatsapp_template_name)) {
    file_put_contents("$logFile", "Processing WhatsApp for fulfillment updated - Template: $whatsapp_template_name\n", FILE_APPEND);

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
        'Ad_order_number' => $order_id,
        'tracking_number' => $tracking_number,
        'tracking_url' => $tracking_url,
        'customer_fname' => $customer_fname,
        'customer_lname' => $customer_lname,
        'customer_email_id' => $customer_email_id,
        'country_code' => $country_code,
        'customer_phone' => $customer_phone,
        'customer_full_name' => $customer_full_name,
        'shipping_address' => $shipping_string,
        'shipping_city' => $shipping_city,
        'Ad_total_discount' => $total_discount,
        'order_status_url' => $order_status_url,
        'Ad_item_price' => $item_price,
        'Ad_item_quantity' => $item_quantity,
        'Ad_item_sku' => $item_sku,
        'Ad_item_vendor' => $item_vendor,
        'Ad_total_weight' => $total_weight,
        'Ad_shipping_address' => $shipping_string,
        'Ad_billing_address' => $billing_string,
        'fulfillment_id' => $fulfillment_id,
        'fulfillment_status' => isset($fulfillment->status) ? $fulfillment->status : '',
        'item_name' => $item_name,
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
        if (isset($fulfillment->line_items) && !empty($fulfillment->line_items)) {
            if (isset($fulfillment->line_items[0]->product_id)) {
                $product_id = $fulfillment->line_items[0]->product_id;
            }
        }
        if ($product_id) {
            $aid = 8;
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
    $full_phone_with_code = $country_code . $customer_phone;
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
        'order_id' => $order_id,
        'order_name' => $order_name,
        'customer_email' => $customer_email_id,
        'country_code' => $country_code,
        'phone_num' => $customer_phone,
        'notification_type' => 'Shipping Update'
    ));

    // ========== SEND WHATSAPP MESSAGE ==========
    if (empty($customer_phone)) {
        file_put_contents("$logFile", "ERROR: Customer phone is empty, cannot send WhatsApp for fulfillment {$fulfillment_id}\n", FILE_APPEND);
    } else {
        $whatsapp_result = send_whatsapp_message($whatsapp_config, $pdo, $table2);

        if ($whatsapp_result['success']) {
            file_put_contents("$logFile", "WhatsApp sent successfully for fulfillment updated {$fulfillment_id} to {$customer_phone}\n", FILE_APPEND);
        } else {
            file_put_contents("$logFile", "WhatsApp failed for fulfillment {$fulfillment_id}: " . $whatsapp_result['message'] . "\n", FILE_APPEND);
        }
    }
} else {
    file_put_contents("$logFile", "No WhatsApp template configured for aid=8, skipping WhatsApp send\n", FILE_APPEND);
}
?>