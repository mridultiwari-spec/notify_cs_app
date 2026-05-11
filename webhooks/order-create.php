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

$data = file_get_contents("php://input");
$order_details = json_decode($data);

if (!$order_details) {
    set_http_status(200);
    exit;
}

$order_status_url_full = isset($order_details->order_status_url) ? $order_details->order_status_url : '';
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
$order_id = isset($order_details->id) ? $order_details->id : '';
$email = isset($order_details->email) ? $order_details->email : '';
file_put_contents("$logFile", "Order ID: $order_id | Email: $email\n\n", FILE_APPEND);

$total_discount = isset($order_details->total_discounts) ? $order_details->total_discounts : '';
$order_status_url = $order_status_url_full;
$parts = explode('orders/', $order_status_url);
$order_status_url = isset($parts[1]) ? $parts[1] : '';
$total_weight = isset($order_details->total_weight) ? $order_details->total_weight : 0;

$billing = isset($order_details->billing_address) ? $order_details->billing_address : null;
$shipping = isset($order_details->shipping_address) ? $order_details->shipping_address : null;

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

$order_name = isset($order_details->name) ? $order_details->name : '';
$order_tags = isset($order_details->tags) ? $order_details->tags : '';
$customer = isset($order_details->customer) ? $order_details->customer : null;
$customer_fname = ($customer && isset($customer->first_name)) ? $customer->first_name : '';
$customer_lname = ($customer && isset($customer->last_name)) ? $customer->last_name : '';
$customer_full_name = trim($customer_fname . ' ' . $customer_lname);
$customer_email_id = isset($order_details->contact_email) ? $order_details->contact_email : '';
$customer_shipping_phone = ($shipping && isset($shipping->phone)) ? $shipping->phone : '';
$customer_billing_phone = ($billing && isset($billing->phone)) ? $billing->phone : '';
$customer_root_phone = isset($order_details->phone) ? $order_details->phone : '';
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
$customer_order_phone = isset($order_details->phone) ? $order_details->phone : '';

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

$order_total_price = isset($order_details->total_price) ? $order_details->total_price : '';

date_default_timezone_set("Asia/Kolkata");
$current_DateTime = date('Y-m-d H:i:s');
$finalDateTime = $current_DateTime;
$subject = ucwords("Order Confirmation");
$notification_type = "Order Confirmation";

$prefix = $app_prefix;
$table = $prefix . "shopify_sms_notification_App_Email_Notification";
$stmt = $pdo->prepare("SELECT * FROM $table WHERE aid = 1 AND shop = :shop");
$stmt->execute(array(':shop' => $shop));
$row = $stmt->fetch();

if ($row) {
    // Check if SMS is enabled
    $sms_enabled = isset($row['sms_enabled']) ? (int) $row['sms_enabled'] : 0;

    // Only process SMS if enabled
    if ($sms_enabled == 1) {
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
            "{{ item_name }}" => $item_name,
            "{{ customer_full_name }}" => $customer_full_name,
            "{{ Ad_total_discount }}" => $total_discount,
            "{{ Ad_order_status_url }}" => $order_status_url,
            "{{ Ad_item_price }}" => $item_price,
            "{{ Ad_item_quantity }}" => $item_quantity,
            "{{ Ad_item_sku }}" => $item_sku,
            "{{ Ad_item_vendor }}" => $item_vendor,
            "{{ Ad_total_weight }}" => $total_weight,
            "{{ Ad_shipping_address }}" => $shipping_string,
            "{{ Ad_billing_address }}" => $billing_string
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

            // Use the ORIGINAL sms_text as template ID
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

            if ($media_source_prod == 'dynmc_prod_img' && $media_type == 'image') {
                $product_id = null;
                if (isset($order_details->line_items) && !empty($order_details->line_items)) {
                    if (isset($order_details->line_items[0]->product_id)) {
                        $product_id = $order_details->line_items[0]->product_id;
                    }
                }
                if ($product_id) {
                    $aid = 1;
                    if (function_exists('fetchProductImageAndUpdateDb')) {
                        $result = fetchProductImageAndUpdateDb($pdo, $shop, $oauth_token, $product_id, $aid, $media_source_prod);
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
                    $template_name_sms,
                    $shop,
                    $customer_email_id,
                    $customer_full_name,
                    $parameter_values,
                    $order_id,
                    $order_name,
                    $notification_type
                );
                file_put_contents("$logFile", "Template SMS sent successfully for order confirmation {$order_id} to {$customer_phone}\n", FILE_APPEND);
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
                "{{ item_name }}",
                "{{ customer_full_name }}",
                "{{ Ad_total_discount }}",
                "{{ Ad_order_status_url }}",
                "{{ Ad_item_price }}",
                "{{ Ad_item_quantity }}",
                "{{ Ad_item_sku }}",
                "{{ Ad_item_vendor }}",
                "{{ Ad_total_weight }}",
                "{{ Ad_shipping_address }}",
                "{{ Ad_billing_address }}"
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
                $item_name,
                $customer_full_name,
                $total_discount,
                $order_status_url,
                $item_price,
                $item_quantity,
                $item_sku,
                $item_vendor,
                $total_weight,
                $shipping_string,
                $billing_string
            );

            $final_text = str_replace($searchVal, $replaceVal, $sms_text);
            $final_text2 = str_replace($searchVal, $replaceVal, $whatsapp_text);

            if (empty($final_text) || empty($sms_text)) {
                $final_text = "Your order {$order_name} has been confirmed. Total: {$order_total_price}. Track: {$order_status_url}";
                file_put_contents("$logFile", "WARNING: Using fallback SMS text for order confirmation\n", FILE_APPEND);
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
                '{{ item_name }}' => isset($item_name) ? $item_name : null,
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

            if ($media_source_prod == 'dynmc_prod_img' && $media_type == 'image') {
                $product_id = null;
                if (isset($order_details->line_items) && !empty($order_details->line_items)) {
                    if (isset($order_details->line_items[0]->product_id)) {
                        $product_id = $order_details->line_items[0]->product_id;
                    }
                }
                if ($product_id) {
                    $aid = 1;
                    if (function_exists('fetchProductImageAndUpdateDb')) {
                        $result = fetchProductImageAndUpdateDb($pdo, $shop, $oauth_token, $product_id, $aid, $media_source_prod);
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
                send_smstext(
                    $country_code,
                    $customer_phone,
                    $template_name_sms,
                    $shop,
                    $customer_email_id,
                    $customer_full_name,
                    $order_id,
                    $order_name,
                    $notification_type
                );
                file_put_contents("$logFile", "Plain text SMS sent successfully for order confirmation {$order_id} to {$customer_phone}\n", FILE_APPEND);
            }
        }
    } else {
        file_put_contents("$logFile", "SMS is disabled for aid=1, shop: {$shop}. Skipping SMS.\n", FILE_APPEND);
    }
}
file_put_contents(
    "$logFile",
    "Discount: $total_discount\nBilling: $billing_string\nShipping: $shipping_string\nOrder_Status_Url: $order_status_url\n\n",
    FILE_APPEND
);

$whatsapp_api_config = array(
    // 'api_domain' => 'https://your-api-domain.com',  // Replace with actual API domain
    // 'channel_id' => 'your_channel_id',              // Replace with actual channel ID
    // 'api_key' => 'your_api_key',                    // Replace with actual API key
    'log_file' => "$logFile"
);

$table2 = $prefix . "shopify_sms_notification_App_Log_Details";

$whatsapp_data_from_db = array();
if (isset($row['whatsapp']) && !empty($row['whatsapp'])) {
    $whatsapp_data_from_db = json_decode($row['whatsapp'], true);
    if (!is_array($whatsapp_data_from_db)) {
        $whatsapp_data_from_db = array();
    }
}

$whatsapp_template_name = isset($whatsapp_data_from_db['template_name']) ? $whatsapp_data_from_db['template_name'] : '';

$whatsapp_enabled_main = isset($row['whatsapp_enabled']) ? (int) $row['whatsapp_enabled'] : 0;
if ($whatsapp_enabled_main == 1 && !empty($whatsapp_template_name)) {
    file_put_contents("$logFile", "Processing WhatsApp for order confirmation - Template: $whatsapp_template_name\n", FILE_APPEND);
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

    $processed_headers = array();
    $header_variables = isset($whatsapp_data_from_db['variable_headers']) ? $whatsapp_data_from_db['variable_headers'] : array();

    if (!empty($header_variables)) {
        foreach ($header_variables as $header_var) {
            $processed_value = wa_replace_placeholders($header_var, $replacement_map);
            $processed_headers[] = $processed_value;
            file_put_contents("$logFile", "Header variable: '$header_var' -> '$processed_value'\n", FILE_APPEND);
        }
    }

    $processed_body = array();
    $body_variables = isset($whatsapp_data_from_db['variable_body']) ? $whatsapp_data_from_db['variable_body'] : array();

    if (!empty($body_variables)) {
        foreach ($body_variables as $body_var) {
            $processed_value = wa_replace_placeholders($body_var, $replacement_map);
            $processed_body[] = $processed_value;
            file_put_contents("$logFile", "Body variable: '$body_var' -> '$processed_value'\n", FILE_APPEND);
        }
    }

    // ========== 4. PROCESS MEDIA URL ==========
    $media_url = isset($row['media_url']) ? $row['media_url'] : '';
    $media_source = isset($row['media_source']) ? $row['media_source'] : '';
    $media_type = isset($row['media_type']) ? $row['media_type'] : 'text';

    // Replace placeholders in media URL
    if (!empty($media_url)) {
        $media_url = wa_replace_placeholders($media_url, $replacement_map);
        file_put_contents("$logFile", "Media URL after replacement: $media_url\n", FILE_APPEND);
    }

    // Handle dynamic product image
    if ($media_source == 'dynmc_prod_img' && $media_type == 'image') {
        $product_id = null;
        if (isset($order_details->line_items) && !empty($order_details->line_items)) {
            if (isset($order_details->line_items[0]->product_id)) {
                $product_id = $order_details->line_items[0]->product_id;
            }
        }
        if ($product_id) {
            $aid = 1;
            if (function_exists('fetchProductImageAndUpdateDb')) {
                $result = fetchProductImageAndUpdateDb($pdo, $shop, $oauth_token, $product_id, $aid, $media_source);
                if ($result['success']) {
                    $media_url = $result['media_url'];
                    file_put_contents("$logFile", "Dynamic product image fetched: $media_url\n", FILE_APPEND);
                } else {
                    file_put_contents("$logFile", "Image fetch error: " . $result['message'] . "\n", FILE_APPEND);
                }
            }
        }
    }

    $buttons = array();

    $cta_urls = array();
    if (isset($row['cta_url']) && !empty($row['cta_url'])) {
        $cta_urls = json_decode($row['cta_url'], true);
        if (!is_array($cta_urls)) {
            $cta_urls = array();
        }
    }

    $button_type1 = isset($row['button_type']) ? $row['button_type'] : '';
    $button_text1 = isset($row['button_text1_type']) ? trim($row['button_text1_type']) : '';
    $button_url1 = isset($cta_urls['button1']) ? $cta_urls['button1'] : '';

    if ($button_type1 !== 'none' && !empty($button_text1)) {
        $button_text1 = wa_replace_placeholders($button_text1, $replacement_map);
        $button_url1 = wa_replace_placeholders($button_url1, $replacement_map);

        $buttons[] = array(
            'sub_type' => ($button_type1 === 'cta') ? 'url' : 'quick_reply',
            'index' => '0',
            'value' => ($button_type1 === 'cta') ? $button_url1 : $button_text1,
            'button_text' => $button_text1
        );
        file_put_contents("$logFile", "Button 1 added: type=$button_type1, text=$button_text1\n", FILE_APPEND);
    }
    $button_type2 = isset($row['button_type2']) ? $row['button_type2'] : '';
    $button_text2 = isset($row['button_text2_type']) ? trim($row['button_text2_type']) : '';
    $button_url2 = isset($cta_urls['button2']) ? $cta_urls['button2'] : '';

    if ($button_type2 !== 'none' && !empty($button_text2)) {
        $button_text2 = wa_replace_placeholders($button_text2, $replacement_map);
        $button_url2 = wa_replace_placeholders($button_url2, $replacement_map);

        $buttons[] = array(
            'sub_type' => ($button_type2 === 'cta') ? 'url' : 'quick_reply',
            'index' => '1',
            'value' => ($button_type2 === 'cta') ? $button_url2 : $button_text2,
            'button_text' => $button_text2
        );
        file_put_contents("$logFile", "Button 2 added: type=$button_type2, text=$button_text2\n", FILE_APPEND);
    }
    $button_type3 = isset($row['button_type3']) ? $row['button_type3'] : '';
    $button_text3 = isset($row['button_text3_type']) ? trim($row['button_text3_type']) : '';
    $button_url3 = isset($cta_urls['button3']) ? $cta_urls['button3'] : '';

    if ($button_type3 !== 'none' && !empty($button_text3)) {
        $button_text3 = wa_replace_placeholders($button_text3, $replacement_map);
        $button_url3 = wa_replace_placeholders($button_url3, $replacement_map);

        $buttons[] = array(
            'sub_type' => ($button_type3 === 'cta') ? 'url' : 'quick_reply',
            'index' => '2',
            'value' => ($button_type3 === 'cta') ? $button_url3 : $button_text3,
            'button_text' => $button_text3
        );
        file_put_contents("$logFile", "Button 3 added: type=$button_type3, text=$button_text3\n", FILE_APPEND);
    }
    //$final_contact = "$country_code" . "$customer_phone";
    $whatsapp_config = array_merge($whatsapp_api_config, array(
        'to' =>  $country_code . $customer_phone,
        'template_name' => $whatsapp_template_name,
        'language_code' => 'en',
        'media_type' => $media_type,
        'media_url' => $media_url,
        'media_source' => $media_source,
        'variable_headers' => $processed_headers,
        'variable_body' => $processed_body,
        'buttons' => $buttons,
        'shop' => $shop,
        'order_id' => $order_id,
        'order_name' => $order_name,
        'customer_email' => $customer_email_id,
        'country_code' => $country_code,
        'phone_num' => $customer_phone,
        'notification_type' => 'Order Confirmation'
    ));

    if (empty($customer_phone)) {
        file_put_contents("$logFile", "ERROR: Customer phone is empty, cannot send WhatsApp for order {$order_id}\n", FILE_APPEND);
    } else {
        $whatsapp_result = send_whatsapp_message($whatsapp_config, $pdo, $table2);

        if ($whatsapp_result['success']) {
            file_put_contents("$logFile", "WhatsApp sent successfully for order confirmation {$order_id} to {$customer_phone}\n", FILE_APPEND);
        } else {
            file_put_contents("$logFile", "WhatsApp failed for order {$order_id}: " . $whatsapp_result['message'] . "\n", FILE_APPEND);
        }
    }
} else {
    file_put_contents("$logFile", "No WhatsApp template configured for aid=1, skipping WhatsApp send\n", FILE_APPEND);
}
$sql = "SELECT * FROM " . $table . " WHERE shop = '{$shop}' AND order_name !='' AND order_name != 'Abandoned Checkout'";
$result1 = $pdo->query($sql);

if ($result1->rowCount() > 0) {
    while ($row7 = $result1->fetch(PDO::FETCH_ASSOC)) {
        $promo_sms_enabled = isset($row7['sms_enabled']) ? (int) $row7['sms_enabled'] : 0;
        $db_tags = isset($row7['tags_data']) ? $row7['tags_data'] : '';
        $db_price_arr = isset($row7['price']) ? $row7['price'] : '';
        $db_price_res = explode("-", $db_price_arr);
        $db_price = $db_price_res[0];
        $db_price2 = isset($db_price_res[1]) ? $db_price_res[1] : '';
        $order_condition = isset($row7['order_condition']) ? $row7['order_condition'] : '';
        $offer_name = isset($row7['order_name']) ? $row7['order_name'] : '';
        $timeinterval = isset($row7['timeinterval']) ? $row7['timeinterval'] : '';
        $template_name_sms = isset($row7['template_name']) ? $row7['template_name'] : '';
        $sms_text = isset($row7['sms']) ? $row7['sms'] : '';
        $whatsapp_text = isset($row7['whatsapp']) ? $row7['whatsapp'] : '';
        $media_type = isset($row7['media_type']) ? $row7['media_type'] : '';
        $button_type = isset($row7['button_type']) ? $row7['button_type'] : '';
        $button_type2 = isset($row7['button_type2']) ? $row7['button_type2'] : '';
        $button_type3 = isset($row7['button_type3']) ? $row7['button_type3'] : '';
        $media_url = isset($row7['media_url']) ? $row7['media_url'] : '';
        $button_text1 = isset($row7['button_text1_type']) ? trim($row7['button_text1_type']) : '';
        $button_text2 = isset($row7['button_text2_type']) ? trim($row7['button_text2_type']) : '';
        $button_text3 = isset($row7['button_text3_type']) ? trim($row7['button_text3_type']) : '';
        $cta_url = isset($row7['cta_url']) ? json_decode($row7['cta_url'], true) : array();

        $sms_variables = array();
        $has_parameters = false;
        if (isset($row7['sms_variables']) && !empty($row7['sms_variables'])) {
            $sms_variables = json_decode($row7['sms_variables'], true);
            if (!is_array($sms_variables)) {
                $sms_variables = array();
            }
            if (count($sms_variables) > 0) {
                $has_parameters = true;
            }
        }

        if (empty($template_name_sms)) {
            continue;
        }

        $searchVal_promo = array(
            "{{ order_name }}",
            "{{ order_total_price }}",
            "{{ customer_email_id }}",
            "{{ country_code }}",
            "{{ customer_phone }}",
            "{{ order_id }}"
        );

        $replaceVal_promo = array(
            $order_name,
            $order_total_price,
            $customer_email_id,
            $country_code,
            $customer_phone,
            $order_id
        );

        $tags_matched = false;
        $price_matched = false;

        // Check tags condition
        // if (!empty($db_tags) && $db_tags == $order_tags && $order_tags != '') {
        //     $tags_matched = true;
        //     file_put_contents("$logFile", "Promotional: Tags matched for shop: $shop, Tags: $db_tags, Offer: $offer_name\n", FILE_APPEND);
        // }
        // // Check price conditions
        // elseif (!empty($db_price)) {
        //     if ($order_condition == 'Greater than' && $order_total_price >= $db_price) {
        //         $price_matched = true;
        //         file_put_contents("$logFile", "Promotional: Price matched (Greater than) for shop: $shop, Price: $order_total_price >= $db_price, Offer: $offer_name\n", FILE_APPEND);
        //     } elseif ($order_condition == 'Equals' && $order_total_price == $db_price) {
        //         $price_matched = true;
        //         file_put_contents("$logFile", "Promotional: Price matched (Equals) for shop: $shop, Price: $order_total_price == $db_price, Offer: $offer_name\n", FILE_APPEND);
        //     } elseif ($order_condition == 'Less than' && $order_total_price <= $db_price) {
        //         $price_matched = true;
        //         file_put_contents("$logFile", "Promotional: Price matched (Less than) for shop: $shop, Price: $order_total_price <= $db_price, Offer: $offer_name\n", FILE_APPEND);
        //     } elseif ($order_condition == 'Between' && !empty($db_price2) && $order_total_price > $db_price && $order_total_price < $db_price2) {
        //         $price_matched = true;
        //         file_put_contents("$logFile", "Promotional: Price matched (Between) for shop: $shop, Price: $db_price < $order_total_price < $db_price2, Offer: $offer_name\n", FILE_APPEND);
        //     }
        // }
        $tags_matched = false;
        $price_matched = false;

        if (!empty($db_tags) && $db_tags == $order_tags && $order_tags != '') {
            $tags_matched = true;
            file_put_contents("$logFile", "Promotional: Tags matched for shop: $shop, Tags: $db_tags, Offer: $offer_name\n", FILE_APPEND);
        }
        if (!empty($db_price)) {
            if ($order_condition == 'Greater than' && $order_total_price >= $db_price) {
                $price_matched = true;
                file_put_contents("$logFile", "Promotional: Price matched (Greater than) for shop: $shop, Price: $order_total_price >= $db_price, Offer: $offer_name\n", FILE_APPEND);
            } elseif ($order_condition == 'Equals' && $order_total_price == $db_price) {
                $price_matched = true;
                file_put_contents("$logFile", "Promotional: Price matched (Equals) for shop: $shop, Price: $order_total_price == $db_price, Offer: $offer_name\n", FILE_APPEND);
            } elseif ($order_condition == 'Less than' && $order_total_price <= $db_price) {
                $price_matched = true;
                file_put_contents("$logFile", "Promotional: Price matched (Less than) for shop: $shop, Price: $order_total_price <= $db_price, Offer: $offer_name\n", FILE_APPEND);
            } elseif ($order_condition == 'Between' && !empty($db_price2) && $order_total_price > $db_price && $order_total_price < $db_price2) {
                $price_matched = true;
                file_put_contents("$logFile", "Promotional: Price matched (Between) for shop: $shop, Price: $db_price < $order_total_price < $db_price2, Offer: $offer_name\n", FILE_APPEND);
            }
        }
        if ($tags_matched || $price_matched) {
            $subject_promo = ($tags_matched ? "Product Tags Based" : "Order Value Based");
            if ($promo_sms_enabled == 1 && !empty($customer_phone)) {
                if ($has_parameters) {
                    $parameter_values = array();

                    foreach ($sms_variables as $key => $value) {
                        $processed_value = $value;

                        $processed_value = str_replace($searchVal_promo, $replaceVal_promo, $processed_value);

                        if (strpos($processed_value, '{{') !== false) {
                            $recursion_count = 0;
                            $max_recursion = 5;
                            while (strpos($processed_value, '{{') !== false && $recursion_count < $max_recursion) {
                                $processed_value = str_replace($searchVal_promo, $replaceVal_promo, $processed_value);
                                $recursion_count++;
                            }
                        }
                        $parameter_values[$key] = $processed_value;
                    }
                    file_put_contents("$logFile", "Promotional: Using template with parameters for offer: $offer_name\n", FILE_APPEND);
                    file_put_contents("$logFile", "Template Name: $template_name_sms\n", FILE_APPEND);
                    file_put_contents("$logFile", "Parameter Values: " . json_encode($parameter_values) . "\n", FILE_APPEND);

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
                        $subject_promo
                    );
                } else {
                    if (empty($template_name_sms)) {
                        file_put_contents("$logFile", "Promotional: No SMS text found for offer: $offer_name\n", FILE_APPEND);
                    } else {
                        file_put_contents("$logFile", "Promotional: Using plain text SMS for offer: $offer_name\n", FILE_APPEND);
                        send_smstext(
                            $country_code,
                            $customer_phone,
                            $template_name_sms,
                            $shop,
                            $customer_email_id,
                            $customer_full_name,
                            $order_id,
                            $order_name,
                            $subject_promo
                        );
                    }
                }
                file_put_contents("$logFile", "Promotional SMS sent successfully for offer: $offer_name to {$customer_phone}\n", FILE_APPEND);
            } else {
                file_put_contents("$logFile", "Promotional SMS NOT sent - Phone is empty for offer: $offer_name\n", FILE_APPEND);
            }
            $promo_whatsapp_enabled = isset($row7['whatsapp_enabled']) ? (int) $row7['whatsapp_enabled'] : 0;
            $promo_whatsapp_data = array();
            if (isset($row7['whatsapp']) && !empty($row7['whatsapp'])) {
                $promo_whatsapp_data = json_decode($row7['whatsapp'], true);
                if (!is_array($promo_whatsapp_data)) {
                    $promo_whatsapp_data = array();
                }
            }

            $promo_whatsapp_template_name = isset($promo_whatsapp_data['template_name']) ? $promo_whatsapp_data['template_name'] : '';

            if ($promo_whatsapp_enabled == 1 && !empty($promo_whatsapp_template_name) && !empty($customer_phone)) {
                file_put_contents("$logFile", "Promotional: Sending WhatsApp for offer: $offer_name, Template: $promo_whatsapp_template_name\n", FILE_APPEND);

                $promo_replacement_map = array(
                    'order_name' => $order_name,
                    'order_total_price' => $order_total_price,
                    'customer_email_id' => $customer_email_id,
                    'country_code' => $country_code,
                    'customer_phone' => $customer_phone,
                    'order_id' => $order_id,
                    'customer_fname' => $customer_fname,
                    'customer_lname' => $customer_lname,
                    'customer_full_name' => $customer_full_name,
                    'item_name' => $item_name
                );

                $promo_processed_headers = array();
                $promo_header_vars = isset($promo_whatsapp_data['variable_headers']) ? $promo_whatsapp_data['variable_headers'] : array();

                if (!empty($promo_header_vars)) {
                    foreach ($promo_header_vars as $header_var) {
                        $processed_value = wa_replace_placeholders($header_var, $promo_replacement_map);
                        $promo_processed_headers[] = $processed_value;
                        file_put_contents("$logFile", "Promo Header: '$header_var' -> '$processed_value'\n", FILE_APPEND);
                    }
                }

                $promo_processed_body = array();
                $promo_body_vars = isset($promo_whatsapp_data['variable_body']) ? $promo_whatsapp_data['variable_body'] : array();

                if (!empty($promo_body_vars)) {
                    foreach ($promo_body_vars as $body_var) {
                        $processed_value = wa_replace_placeholders($body_var, $promo_replacement_map);
                        $promo_processed_body[] = $processed_value;
                        file_put_contents("$logFile", "Promo Body: '$body_var' -> '$processed_value'\n", FILE_APPEND);
                    }
                }
                
                $promo_media_url = isset($row7['media_url']) ? $row7['media_url'] : '';
                $promo_media_source = isset($row7['media_source']) ? $row7['media_source'] : '';
                $promo_media_type = isset($row7['media_type']) ? $row7['media_type'] : 'text';

                if (!empty($promo_media_url)) {
                    $promo_media_url = wa_replace_placeholders($promo_media_url, $promo_replacement_map);
                    file_put_contents("$logFile", "Promo Media URL: $promo_media_url\n", FILE_APPEND);
                }

                if ($promo_media_source == 'dynmc_prod_img' && $promo_media_type == 'image') {
                    $product_id = null;
                    if (isset($order_details->line_items) && !empty($order_details->line_items) && isset($order_details->line_items[0]->product_id)) {
                        $product_id = $order_details->line_items[0]->product_id;
                    }
                    if ($product_id && function_exists('fetchProductImageAndUpdateDb')) {
                        $result = fetchProductImageAndUpdateDb($pdo, $shop, $oauth_token, $product_id, $aid, $promo_media_source);
                        if ($result['success']) {
                            $promo_media_url = $result['media_url'];
                            file_put_contents("$logFile", "Promo Dynamic product image fetched: $promo_media_url\n", FILE_APPEND);
                        }
                    }
                }

                $promo_buttons = array();
                $promo_cta_urls = array();
                if (isset($row7['cta_url']) && !empty($row7['cta_url'])) {
                    $promo_cta_urls = json_decode($row7['cta_url'], true);
                    if (!is_array($promo_cta_urls)) {
                        $promo_cta_urls = array();
                    }
                }

                $promo_btn1_type = isset($row7['button_type']) ? $row7['button_type'] : '';
                $promo_btn1_text = isset($row7['button_text1_type']) ? trim($row7['button_text1_type']) : '';
                $promo_btn1_url = isset($promo_cta_urls['button1']) ? $promo_cta_urls['button1'] : '';

                if ($promo_btn1_type !== 'none' && !empty($promo_btn1_text)) {
                    $promo_btn1_text = wa_replace_placeholders($promo_btn1_text, $promo_replacement_map);
                    $promo_btn1_url = wa_replace_placeholders($promo_btn1_url, $promo_replacement_map);

                    $promo_buttons[] = array(
                        'sub_type' => ($promo_btn1_type === 'cta') ? 'url' : 'quick_reply',
                        'index' => '0',
                        'value' => ($promo_btn1_type === 'cta') ? $promo_btn1_url : $promo_btn1_text,
                        'button_text' => $promo_btn1_text
                    );
                    file_put_contents("$logFile", "Promo Button 1: type=$promo_btn1_type, text=$promo_btn1_text\n", FILE_APPEND);
                }

                $promo_btn2_type = isset($row7['button_type2']) ? $row7['button_type2'] : '';
                $promo_btn2_text = isset($row7['button_text2_type']) ? trim($row7['button_text2_type']) : '';
                $promo_btn2_url = isset($promo_cta_urls['button2']) ? $promo_cta_urls['button2'] : '';

                if ($promo_btn2_type !== 'none' && !empty($promo_btn2_text)) {
                    $promo_btn2_text = wa_replace_placeholders($promo_btn2_text, $promo_replacement_map);
                    $promo_btn2_url = wa_replace_placeholders($promo_btn2_url, $promo_replacement_map);

                    $promo_buttons[] = array(
                        'sub_type' => ($promo_btn2_type === 'cta') ? 'url' : 'quick_reply',
                        'index' => '1',
                        'value' => ($promo_btn2_type === 'cta') ? $promo_btn2_url : $promo_btn2_text,
                        'button_text' => $promo_btn2_text
                    );
                    file_put_contents("$logFile", "Promo Button 2: type=$promo_btn2_type, text=$promo_btn2_text\n", FILE_APPEND);
                }

                $promo_btn3_type = isset($row7['button_type3']) ? $row7['button_type3'] : '';
                $promo_btn3_text = isset($row7['button_text3_type']) ? trim($row7['button_text3_type']) : '';
                $promo_btn3_url = isset($promo_cta_urls['button3']) ? $promo_cta_urls['button3'] : '';

                if ($promo_btn3_type !== 'none' && !empty($promo_btn3_text)) {
                    $promo_btn3_text = wa_replace_placeholders($promo_btn3_text, $promo_replacement_map);
                    $promo_btn3_url = wa_replace_placeholders($promo_btn3_url, $promo_replacement_map);

                    $promo_buttons[] = array(
                        'sub_type' => ($promo_btn3_type === 'cta') ? 'url' : 'quick_reply',
                        'index' => '2',
                        'value' => ($promo_btn3_type === 'cta') ? $promo_btn3_url : $promo_btn3_text,
                        'button_text' => $promo_btn3_text
                    );
                    file_put_contents("$logFile", "Promo Button 3: type=$promo_btn3_type, text=$promo_btn3_text\n", FILE_APPEND);
                }

                $promo_whatsapp_config = array_merge($whatsapp_api_config, array(
                    'to' => $country_code . $customer_phone,
                    'template_name' => $promo_whatsapp_template_name,
                    'language_code' => 'en',
                    'media_type' => $promo_media_type,
                    'media_url' => $promo_media_url,
                    'media_source' => $promo_media_source,
                    'variable_headers' => $promo_processed_headers,
                    'variable_body' => $promo_processed_body,
                    'buttons' => $promo_buttons,
                    'shop' => $shop,
                    'order_id' => $order_id,
                    'order_name' => $order_name,
                    'customer_email' => $customer_email_id,
                    'country_code' => $country_code,
                    'phone_num' => $customer_phone,
                    'notification_type' => $subject_promo
                ));

                $promo_whatsapp_result = send_whatsapp_message($promo_whatsapp_config, $pdo, $table2);

                if ($promo_whatsapp_result['success']) {
                    file_put_contents("$logFile", "Promotional WhatsApp sent successfully for offer: $offer_name to {$customer_phone}\n", FILE_APPEND);
                } else {
                    file_put_contents("$logFile", "Promotional WhatsApp FAILED for offer: $offer_name - " . $promo_whatsapp_result['message'] . "\n", FILE_APPEND);
                }
            } elseif (!empty($customer_phone) && $promo_whatsapp_enabled == 1 && empty($promo_whatsapp_template_name)) {
                file_put_contents("$logFile", "Promotional WhatsApp: No template name for offer: $offer_name\n", FILE_APPEND);
            } elseif ($promo_whatsapp_enabled == 0) {
                file_put_contents("$logFile", "Promotional WhatsApp: Disabled for offer: $offer_name\n", FILE_APPEND);
            }
        }
    }
}